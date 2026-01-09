<?php
// manage_bins.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

/**
 * Safe escape helper
 */
function h($value): string {
    if ($value === null) return '';
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Parse serials from textarea/scanner input.
 * Accepts: newline, comma, tab, semicolon.
 * Returns: unique trimmed serials (preserving order).
 */
function parseSerials(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = str_replace([",", ";", "\t"], "\n", $raw);

    $parts = explode("\n", $raw);

    $seen = [];
    $serials = [];

    foreach ($parts as $p) {
        $s = trim($p);
        if ($s === '') continue;

        $s = trim($s, "\"'");
        $key = strtoupper($s);

        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $serials[] = $s;
        }
    }

    return $serials;
}

/**
 * Helper for dynamic bind_param (mysqli)
 */
function bindParams(mysqli_stmt $stmt, string $types, array $params): void {
    $bindNames = [];
    $bindNames[] = $types;

    for ($i = 0; $i < count($params); $i++) {
        $bindNames[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindNames);
}

// ---------------------------------------------------------------------
// ROLE CONTROL
// ---------------------------------------------------------------------
$currentUserId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$currentUserRole = $_SESSION['role'] ?? 'viewer';

$allowedRolesForMove = ['admin', 'inventory_manager','supervisor'];
$canMove = in_array($currentUserRole, $allowedRolesForMove, true);

// ---------------------------------------------------------------------
// AJAX PREVIEW ENDPOINT (NO PAGE RELOAD)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_scan_ajax') {

    header('Content-Type: application/json; charset=utf-8');

    if (!$canMove) {
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to move inventory.']);
        exit;
    }

    $scanInputSerialsRaw = (string)($_POST['scan_serials'] ?? '');
    $scanDestBin         = trim((string)($_POST['scan_new_location_name'] ?? ''));

    $serials = parseSerials($scanInputSerialsRaw);

    if (empty($serials)) {
        echo json_encode(['ok' => false, 'error' => 'No serial numbers provided.']);
        exit;
    }
    if ($scanDestBin === '') {
        echo json_encode(['ok' => false, 'error' => 'Destination BIN is required.']);
        exit;
    }

    try {
        // Validate destination exists
        $stmt = $conn->prepare("SELECT id, location_name FROM locations WHERE location_name = ? LIMIT 1");
        $stmt->bind_param('s', $scanDestBin);
        $stmt->execute();
        $dest = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$dest) {
            echo json_encode(['ok' => false, 'error' => 'Destination BIN not found in locations table.']);
            exit;
        }

        $destName = $dest['location_name'];

        // Fetch matching serials in one query
        $placeholders = implode(',', array_fill(0, count($serials), '?'));
        $types = str_repeat('s', count($serials));

        $sql = "
            SELECT
                di.serial_number,
                di.id,
                COALESCE(di.bin_location, l.location_name) AS current_bin
            FROM defectives_inventory di
            LEFT JOIN locations l ON di.location_id = l.id
            WHERE di.serial_number IN ($placeholders)
        ";

        $stmt = $conn->prepare($sql);
        $params = $serials;
        bindParams($stmt, $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();

        $foundMap = [];
        while ($r = $res->fetch_assoc()) {
            $foundMap[strtoupper($r['serial_number'])] = $r;
        }
        $stmt->close();

        $items = [];
        $okCount = 0;
        $notFoundCount = 0;
        $alreadyCount = 0;

        foreach ($serials as $sn) {
            $key = strtoupper($sn);

            if (!isset($foundMap[$key])) {
                $items[] = [
                    'serial' => $sn,
                    'status' => 'NOT_FOUND',
                    'current_bin' => '',
                ];
                $notFoundCount++;
                continue;
            }

            $currentBin = (string)($foundMap[$key]['current_bin'] ?? '');
            if ($currentBin === $destName) {
                $items[] = [
                    'serial' => $sn,
                    'status' => 'ALREADY_THERE',
                    'current_bin' => $currentBin,
                ];
                $alreadyCount++;
                continue;
            }

            $items[] = [
                'serial' => $sn,
                'status' => 'OK',
                'current_bin' => $currentBin,
            ];
            $okCount++;
        }

        echo json_encode([
            'ok' => true,
            'destination' => $destName,
            'input_count' => count($serials),
            'ok_count' => $okCount,
            'not_found_count' => $notFoundCount,
            'already_count' => $alreadyCount,
            'items' => $items
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Preview error: ' . $e->getMessage()]);
        exit;
    }
}

include '../includes/header.php';

$successMsg = '';
$errorMsg   = '';

// Persist values in UI (optional)
$scanInputSerialsRaw = '';
$scanDestBin = '';

// ---------------------------------------------------------------------
// HANDLE POST ACTIONS (confirm_scan, move_single, move_bulk)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!$canMove) {
        $errorMsg = "You do not have permission to move inventory.";
    } else {

        $action = $_POST['action'];

        // ======================================================
        // 1) CONFIRM SCAN MOVE (moves ONLY OK serials)
        // ======================================================
        if ($action === 'confirm_scan') {

            $scanInputSerialsRaw = isset($_POST['scan_serials']) ? (string)$_POST['scan_serials'] : '';
            $scanDestBin         = isset($_POST['scan_new_location_name']) ? trim($_POST['scan_new_location_name']) : '';

            $serials = parseSerials($scanInputSerialsRaw);

            if (empty($serials)) {
                $errorMsg = "No serial numbers provided.";
            } elseif ($scanDestBin === '') {
                $errorMsg = "Destination BIN is required.";
            } else {
                try {
                    $conn->begin_transaction();

                    // Resolve destination BIN
                    $stmt = $conn->prepare("SELECT id, location_name FROM locations WHERE location_name = ? LIMIT 1");
                    $stmt->bind_param('s', $scanDestBin);
                    $stmt->execute();
                    $dest = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$dest) {
                        throw new Exception("Destination BIN not found in locations table.");
                    }

                    $toLocationId   = (int)$dest['id'];
                    $toLocationName = $dest['location_name'];

                    $moved = 0;
                    $notFound = 0;
                    $alreadyThere = 0;

                    // Prepared statements
                    $sqlFind = "
                        SELECT di.id,
                               di.serial_number,
                               di.location_id,
                               COALESCE(di.bin_location, l.location_name) AS current_bin
                        FROM defectives_inventory di
                        LEFT JOIN locations l ON di.location_id = l.id
                        WHERE di.serial_number = ?
                        LIMIT 1
                    ";
                    $stmtFind = $conn->prepare($sqlFind);

                    $sqlUpdate = "
                        UPDATE defectives_inventory
                        SET location_id = ?,
                            bin_location = ?,
                            last_updated = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ";
                    $stmtUpdate = $conn->prepare($sqlUpdate);

                    $sqlLog = "
                        INSERT INTO bin_movements
                            (serial_number, from_location_id, to_location_id, user_id, action, created_at, notes)
                        VALUES
                            (?, ?, ?, ?, 'MOVE', NOW(), ?)
                    ";
                    $stmtLog = $conn->prepare($sqlLog);

                    foreach ($serials as $sn) {

                        $stmtFind->bind_param('s', $sn);
                        $stmtFind->execute();
                        $item = $stmtFind->get_result()->fetch_assoc();

                        if (!$item) {
                            $notFound++;
                            continue;
                        }

                        $itemId         = (int)$item['id'];
                        $serialNumber   = $item['serial_number'];
                        $fromLocationId = (int)($item['location_id'] ?? 0);
                        $currentBin     = (string)($item['current_bin'] ?? '');

                        if ($currentBin === $toLocationName) {
                            $alreadyThere++;
                            continue;
                        }

                        // Update
                        $stmtUpdate->bind_param('isi', $toLocationId, $toLocationName, $itemId);
                        $stmtUpdate->execute();

                        // Log
                        $notes = "Scan/Copy move from {$currentBin} to {$toLocationName}";
                        $userIdForLog = $currentUserId ?: null;
                        $stmtLog->bind_param('siiis', $serialNumber, $fromLocationId, $toLocationId, $userIdForLog, $notes);
                        $stmtLog->execute();

                        $moved++;
                    }

                    $stmtFind->close();
                    $stmtUpdate->close();
                    $stmtLog->close();

                    $conn->commit();

                    if ($moved > 0) {
                        $successMsg = "{$moved} item(s) moved to {$toLocationName}.";
                        if ($notFound > 0) $successMsg .= " ({$notFound} not found)";
                        if ($alreadyThere > 0) $successMsg .= " ({$alreadyThere} already there)";
                    } else {
                        $errorMsg = "No items were moved. (Not found / already in destination.)";
                    }

                } catch (Exception $e) {
                    $conn->rollback();
                    $errorMsg = "Confirm move error: " . $e->getMessage();
                }
            }
        }

        // ======================================================
        // 2) MOVE SINGLE ITEM
        // ======================================================
        if ($action === 'move_single') {

            $itemId          = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
            $newLocationName = isset($_POST['new_location_name']) ? trim($_POST['new_location_name']) : '';

            if ($itemId <= 0 || $newLocationName === '') {
                $errorMsg = "Missing item or destination BIN.";
            } else {
                try {
                    $conn->begin_transaction();

                    // Get current info
                    $sql = "
                        SELECT di.id,
                               di.serial_number,
                               di.location_id,
                               di.bin_location,
                               l.location_name AS location_name_ref
                        FROM defectives_inventory di
                        LEFT JOIN locations l ON di.location_id = l.id
                        WHERE di.id = ?
                        LIMIT 1
                    ";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('i', $itemId);
                    $stmt->execute();
                    $item = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$item) throw new Exception("Item not found.");

                    $serialNumber     = $item['serial_number'];
                    $fromLocationId   = (int)($item['location_id'] ?? 0);
                    $fromLocationName = $item['bin_location'] ?: $item['location_name_ref'];

                    // Resolve destination
                    $stmt = $conn->prepare("SELECT id, location_name FROM locations WHERE location_name = ? LIMIT 1");
                    $stmt->bind_param('s', $newLocationName);
                    $stmt->execute();
                    $newLocation = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$newLocation) throw new Exception("Destination BIN not found in locations table.");

                    $toLocationId   = (int)$newLocation['id'];
                    $toLocationName = $newLocation['location_name'];

                    // Update
                    $stmt = $conn->prepare("
                        UPDATE defectives_inventory
                        SET location_id = ?,
                            bin_location = ?,
                            last_updated = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('isi', $toLocationId, $toLocationName, $itemId);
                    $stmt->execute();
                    $stmt->close();

                    // Log
                    $stmt = $conn->prepare("
                        INSERT INTO bin_movements
                            (serial_number, from_location_id, to_location_id, user_id, action, created_at, notes)
                        VALUES
                            (?, ?, ?, ?, 'MOVE', NOW(), ?)
                    ");
                    $notes = "Move from {$fromLocationName} to {$toLocationName}";
                    $userIdForLog = $currentUserId ?: null;
                    $stmt->bind_param('siiis', $serialNumber, $fromLocationId, $toLocationId, $userIdForLog, $notes);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $successMsg = "Serial {$serialNumber} moved from {$fromLocationName} to {$toLocationName}.";

                } catch (Exception $e) {
                    $conn->rollback();
                    $errorMsg = "Error moving item: " . $e->getMessage();
                }
            }
        }

        // ======================================================
        // 3) MOVE BULK ITEMS (checkbox selection)
        // ======================================================
        if ($action === 'move_bulk') {

            $selectedIds     = isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])
                                ? array_map('intval', $_POST['selected_ids'])
                                : [];
            $newLocationName = isset($_POST['bulk_new_location_name']) ? trim($_POST['bulk_new_location_name']) : '';

            if (empty($selectedIds)) {
                $errorMsg = "No items selected for bulk move.";
            } elseif ($newLocationName === '') {
                $errorMsg = "Destination BIN is required for bulk move.";
            } else {
                try {
                    $conn->begin_transaction();

                    $stmt = $conn->prepare("SELECT id, location_name FROM locations WHERE location_name = ? LIMIT 1");
                    $stmt->bind_param('s', $newLocationName);
                    $stmt->execute();
                    $newLocation = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$newLocation) throw new Exception("Destination BIN not found in locations table.");

                    $toLocationId   = (int)$newLocation['id'];
                    $toLocationName = $newLocation['location_name'];

                    $movedCount = 0;

                    foreach ($selectedIds as $itemId) {

                        $stmt = $conn->prepare("
                            SELECT di.id,
                                   di.serial_number,
                                   di.location_id,
                                   di.bin_location,
                                   l.location_name AS location_name_ref
                            FROM defectives_inventory di
                            LEFT JOIN locations l ON di.location_id = l.id
                            WHERE di.id = ?
                            LIMIT 1
                        ");
                        $stmt->bind_param('i', $itemId);
                        $stmt->execute();
                        $item = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if (!$item) continue;

                        $serialNumber     = $item['serial_number'];
                        $fromLocationId   = (int)($item['location_id'] ?? 0);
                        $fromLocationName = $item['bin_location'] ?: $item['location_name_ref'];

                        $stmt = $conn->prepare("
                            UPDATE defectives_inventory
                            SET location_id = ?,
                                bin_location = ?,
                                last_updated = NOW()
                            WHERE id = ?
                            LIMIT 1
                        ");
                        $stmt->bind_param('isi', $toLocationId, $toLocationName, $itemId);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $conn->prepare("
                            INSERT INTO bin_movements
                                (serial_number, from_location_id, to_location_id, user_id, action, created_at, notes)
                            VALUES
                                (?, ?, ?, ?, 'MOVE', NOW(), ?)
                        ");
                        $notes = "Bulk move from {$fromLocationName} to {$toLocationName}";
                        $userIdForLog = $currentUserId ?: null;
                        $stmt->bind_param('siiis', $serialNumber, $fromLocationId, $toLocationId, $userIdForLog, $notes);
                        $stmt->execute();
                        $stmt->close();

                        $movedCount++;
                    }

                    $conn->commit();

                    if ($movedCount > 0) $successMsg = "{$movedCount} item(s) moved to {$toLocationName}.";
                    else $errorMsg = "No valid items were moved.";

                } catch (Exception $e) {
                    $conn->rollback();
                    $errorMsg = "Error in bulk move: " . $e->getMessage();
                }
            }
        }
    }
}

// ---------------------------------------------------------------------
// HANDLE SEARCH (GET)
// ---------------------------------------------------------------------
$locationId    = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$searchSerial  = isset($_GET['serial_number']) ? trim($_GET['serial_number']) : '';
$searchBin     = isset($_GET['bin_location']) ? trim($_GET['bin_location']) : '';
$searchStatus  = isset($_GET['status']) ? trim($_GET['status']) : '';
$searchProduct = isset($_GET['product_q']) ? trim($_GET['product_q']) : '';

if ($locationId > 0 && $searchBin === '') {
    $stmt = $conn->prepare("SELECT location_name FROM locations WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $locationId);
    $stmt->execute();
    $loc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($loc && !empty($loc['location_name'])) {
        $searchBin = $loc['location_name'];
    }
}

$rows = [];

try {
    $sql = "
        SELECT
            di.id,
            di.serial_number,
            di.category,
            di.grade,
            di.status,
            di.invoice_number,
            p.product_id AS product_name,
            d.defect_code,
            COALESCE(di.bin_location, l.location_name) AS bin_location,
            di.last_updated AS last_update
        FROM defectives_inventory di
        LEFT JOIN product_ids   p ON di.product_id = p.id
        LEFT JOIN defect_codes  d ON di.defect_code_id = d.id
        LEFT JOIN locations     l ON di.location_id = l.id
        WHERE 1 = 1
    ";

    $params = [];
    $types  = '';

    if ($searchSerial !== '') {
        $sql .= " AND di.serial_number LIKE ?";
        $params[] = "%" . $searchSerial . "%";
        $types    .= 's';
    }

    if ($searchProduct !== '') {
        $sql .= " AND (p.product_id LIKE ? OR p.description LIKE ?)";
        $like = "%" . $searchProduct . "%";
        $params[] = $like;
        $params[] = $like;
        $types    .= 'ss';
    }

    if ($searchBin !== '') {
        $sql .= " AND (di.bin_location = ? OR l.location_name = ?)";
        $params[] = $searchBin;
        $params[] = $searchBin;
        $types    .= 'ss';
    }

    if ($searchStatus !== '') {
        $sql .= " AND di.status = ?";
        $params[] = $searchStatus;
        $types    .= 's';
    }

    $sql .= " ORDER BY di.last_updated DESC, di.id DESC LIMIT 200";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) $rows[] = $row;

    $stmt->close();

} catch (Exception $e) {
    $errorMsg = "Error loading inventory: " . $e->getMessage();
}
?>

<div class="container mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h2 class="mb-0">Manage BIN / Move Inventory</h2>
        <?php if ($canMove): ?>
            <span class="badge bg-success">Move Enabled</span>
        <?php else: ?>
            <span class="badge bg-secondary">Read Only</span>
        <?php endif; ?>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success mt-3"><?php echo h($successMsg); ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger mt-3"><?php echo h($errorMsg); ?></div>
    <?php endif; ?>

    <?php if ($canMove): ?>
        <!-- QUICK TRANSFER (AJAX PREVIEW + CONFIRM POST) -->
        <div class="card mt-3" id="quickTransferCard">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <strong>Quick Transfer</strong>
                    <div class="text-muted small">
                        Preview is live (no reload). Green = will move, Red = not found, Yellow = already there.
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="badge bg-dark">Scanner mode</span>
                    <span class="badge bg-secondary" id="scanCountBadge">Scanned: 0</span>

                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="autoPreviewSwitch" checked>
                        <label class="form-check-label small" for="autoPreviewSwitch">
                            Auto preview on scan
                        </label>
                    </div>
                </div>
            </div>

            <div class="card-body">

                <!-- Preview form (JS will prevent default submit and run AJAX) -->
                <form method="post" class="row g-2" id="previewScanForm" autocomplete="off">
                    <input type="hidden" name="action" value="preview_scan_ajax">

                    <div class="col-12 col-lg-8">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <label for="scan_serials" class="form-label mb-0">Serial Numbers</label>

                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearScanBtn">
                                    Clear
                                </button>
                                <button type="submit" class="btn btn-sm btn-primary" id="previewBtn">
                                    Preview
                                </button>
                            </div>
                        </div>

                        <textarea
                            class="form-control mt-2"
                            id="scan_serials"
                            name="scan_serials"
                            rows="6"
                            placeholder="Scan or paste serials here (one per line). Commas/tabs also work."
                        ><?php echo h($scanInputSerialsRaw); ?></textarea>

                        <div class="form-text">
                            Keep scanning — focus will stay here. Preview updates automatically.
                        </div>
                    </div>

                    <div class="col-12 col-lg-4">
                        <label for="scan_new_location_name" class="form-label">Destination BIN</label>
                        <input
                            type="text"
                            class="form-control"
                            id="scan_new_location_name"
                            name="scan_new_location_name"
                            placeholder="e.g. BIN106"
                            value="<?php echo h($scanDestBin); ?>"
                        >

                        <div class="alert alert-light border mt-3 mb-0 small">
                            <div class="fw-semibold">Flow</div>
                            <div class="text-muted">
                                1) Scan serials<br>
                                2) Live Preview shows matches<br>
                                3) Confirm Move to execute
                            </div>
                        </div>
                    </div>
                </form>

                <div id="ajaxPreviewError" class="alert alert-danger mt-3" style="display:none;"></div>

                <div id="ajaxPreviewArea" class="mt-3" style="display:none;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div class="small text-muted" id="ajaxPreviewSummary"></div>

                        <!-- Confirm uses normal POST (safe) -->
                        <form method="post" class="m-0">
                            <input type="hidden" name="action" value="confirm_scan">
                            <input type="hidden" name="scan_serials" id="confirm_scan_serials" value="">
                            <input type="hidden" name="scan_new_location_name" id="confirm_scan_dest" value="">
                            <button type="submit" class="btn btn-danger" id="ajaxConfirmBtn" disabled>
                                Confirm Move (0)
                            </button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width:35%;">Serial</th>
                                    <th style="width:35%;">Current BIN</th>
                                    <th style="width:30%;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="ajaxPreviewTableBody"></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <!-- SEARCH FORM -->
    <form method="get" class="mb-3 mt-4">
        <div class="row g-2">
            <div class="col-md-3">
                <label for="product_q" class="form-label">Product / Model</label>
                <input type="text" class="form-control" id="product_q" name="product_q"
                       value="<?php echo h($searchProduct); ?>" placeholder="Search model / product id...">
            </div>

            <div class="col-md-3">
                <label for="serial_number" class="form-label">Serial Number</label>
                <input type="text" class="form-control" id="serial_number" name="serial_number"
                       value="<?php echo h($searchSerial); ?>" placeholder="Search by serial...">
            </div>

            <div class="col-md-3">
                <label for="bin_location" class="form-label">BIN Location</label>
                <input type="text" class="form-control" id="bin_location" name="bin_location"
                       value="<?php echo h($searchBin); ?>" placeholder="e.g. BIN106">
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <input type="text" class="form-control" id="status" name="status"
                       value="<?php echo h($searchStatus); ?>" placeholder="e.g. In Stock">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">Search</button>
            </div>
        </div>
    </form>

    <!-- RESULTS TABLE -->
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
            <thead>
                <tr>
                    <?php if ($canMove): ?>
                        <th>
                            <input type="checkbox" onclick="
                                const cbs = document.querySelectorAll('.cb-select-item');
                                cbs.forEach(cb => cb.checked = this.checked);
                            ">
                        </th>
                    <?php endif; ?>
                    <th>ID</th>
                    <th>Serial</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Grade</th>
                    <th>Defect</th>
                    <th>BIN</th>
                    <th>Status</th>
                    <th>Invoice</th>
                    <th>Last Update</th>
                    <?php if ($canMove): ?>
                        <th>Move</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?php echo $canMove ? 12 : 11; ?>" class="text-center">
                            No results found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php if ($canMove): ?>
                                <td>
                                    <input type="checkbox" class="cb-select-item" name="selected_ids[]"
                                           value="<?php echo (int)$row['id']; ?>" form="bulkMoveForm">
                                </td>
                            <?php endif; ?>

                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo h($row['serial_number']); ?></td>
                            <td><?php echo h($row['product_name']); ?></td>
                            <td><?php echo h($row['category']); ?></td>
                            <td><?php echo h($row['grade']); ?></td>
                            <td><?php echo h($row['defect_code']); ?></td>
                            <td><?php echo h($row['bin_location']); ?></td>
                            <td><?php echo h($row['status']); ?></td>
                            <td><?php echo h($row['invoice_number']); ?></td>
                            <td><?php echo h($row['last_update']); ?></td>

                            <?php if ($canMove): ?>
                                <td>
                                    <form method="post" class="d-flex gap-1">
                                        <input type="hidden" name="action" value="move_single">
                                        <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="text" name="new_location_name"
                                               class="form-control form-control-sm" placeholder="New BIN" required>
                                        <button type="submit" class="btn btn-sm btn-warning">Move</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canMove && !empty($rows)): ?>
        <!-- BULK MOVE -->
        <form id="bulkMoveForm" method="post" class="mt-3">
            <input type="hidden" name="action" value="move_bulk">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="bulk_new_location_name" class="form-label">Move selected to BIN</label>
                    <input type="text" class="form-control" id="bulk_new_location_name" name="bulk_new_location_name"
                           placeholder="e.g. BIN999" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-danger w-100">Move Selected</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

</div>

<script>
(function () {
    const ta = document.getElementById('scan_serials');
    const dest = document.getElementById('scan_new_location_name');
    const badge = document.getElementById('scanCountBadge');
    const autoSwitch = document.getElementById('autoPreviewSwitch');
    const clearBtn = document.getElementById('clearScanBtn');
    const form = document.getElementById('previewScanForm');

    const previewArea = document.getElementById('ajaxPreviewArea');
    const previewSummary = document.getElementById('ajaxPreviewSummary');
    const previewTbody = document.getElementById('ajaxPreviewTableBody');
    const previewErr = document.getElementById('ajaxPreviewError');

    const confirmSerials = document.getElementById('confirm_scan_serials');
    const confirmDest = document.getElementById('confirm_scan_dest');
    const confirmBtn = document.getElementById('ajaxConfirmBtn');

    if (!ta || !dest || !badge || !autoSwitch || !clearBtn || !form) return;

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, function (m) {
            return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' })[m];
        });
    }

    function parseSerialsJS(raw) {
        raw = (raw || '').trim();
        if (!raw) return [];

        raw = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        raw = raw.replace(/[,\t;]/g, '\n');

        const parts = raw.split('\n');
        const seen = new Set();
        const serials = [];

        for (const p of parts) {
            let s = (p || '').trim();
            if (!s) continue;

            s = s.replace(/^["']|["']$/g, '');
            const key = s.toUpperCase();

            if (!seen.has(key)) {
                seen.add(key);
                serials.push(s);
            }
        }
        return serials;
    }

    function updateCount() {
        const serials = parseSerialsJS(ta.value);
        badge.textContent = 'Scanned: ' + serials.length;
        return serials.length;
    }

    function setError(msg) {
        if (!previewErr) return;
        previewErr.style.display = msg ? 'block' : 'none';
        previewErr.textContent = msg || '';
    }

    function statusBadge(status) {
        if (status === 'OK') return '<span class="badge bg-success">Ready to move</span>';
        if (status === 'NOT_FOUND') return '<span class="badge bg-danger">Not Found</span>';
        return '<span class="badge bg-warning text-dark">Already in destination</span>';
    }

    function rowClass(status) {
        if (status === 'OK') return 'table-success';
        if (status === 'NOT_FOUND') return 'table-danger';
        return 'table-warning';
    }

    let previewTimer = null;
    let lastReqId = 0;

    async function runAjaxPreview() {
        const reqId = ++lastReqId;

        setError('');
        if (previewArea) previewArea.style.display = 'none';

        const serials = parseSerialsJS(ta.value);
        if (serials.length === 0) return;

        const destVal = (dest.value || '').trim();
        if (!destVal) return;

        // Keep focus in textarea always
        ta.focus();

        const body = new URLSearchParams();
        body.set('action', 'preview_scan_ajax');
        body.set('scan_serials', ta.value);
        body.set('scan_new_location_name', destVal);

        try {
            const resp = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });

            const data = await resp.json();
            if (reqId !== lastReqId) return;

            if (!data.ok) {
                setError(data.error || 'Preview error.');
                return;
            }

            if (previewSummary) {
                previewSummary.innerHTML =
                    `Destination: <strong>${escapeHtml(data.destination)}</strong> | ` +
                    `Input: <strong>${data.input_count}</strong> | ` +
                    `Ready: <strong>${data.ok_count}</strong> | ` +
                    `Not Found: <strong>${data.not_found_count}</strong> | ` +
                    `Already there: <strong>${data.already_count}</strong>`;
            }

            if (previewTbody) {
                previewTbody.innerHTML = '';
                for (const it of data.items) {
                    const tr = document.createElement('tr');
                    tr.className = rowClass(it.status);
                    tr.innerHTML =
                        `<td class="fw-semibold">${escapeHtml(it.serial)}</td>` +
                        `<td>${escapeHtml(it.current_bin || '')}</td>` +
                        `<td>${statusBadge(it.status)}</td>`;
                    previewTbody.appendChild(tr);
                }
            }

            if (confirmSerials) confirmSerials.value = ta.value;
            if (confirmDest) confirmDest.value = data.destination;

            if (confirmBtn) {
                confirmBtn.disabled = data.ok_count <= 0;
                confirmBtn.textContent = `Confirm Move (${data.ok_count})`;
                confirmBtn.onclick = function () {
                    return confirm(`Confirm move of ${data.ok_count} item(s) to ${data.destination}?`);
                };
            }

            if (previewArea) previewArea.style.display = 'block';

            // Force cursor at end (keep scanning)
            setTimeout(() => {
                ta.focus();
                ta.selectionStart = ta.selectionEnd = ta.value.length;
            }, 20);

        } catch (e) {
            setError('Preview error (network): ' + (e && e.message ? e.message : 'Unknown'));
        }
    }

    // Prevent default submit (no reload). Use AJAX instead.
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        runAjaxPreview();
    });

    // Initial focus
    updateCount();
    setTimeout(() => {
        ta.focus();
        ta.selectionStart = ta.selectionEnd = ta.value.length;
    }, 150);

    // Live count + auto preview (debounced)
    ta.addEventListener('input', () => {
        updateCount();
        if (!autoSwitch.checked) return;

        clearTimeout(previewTimer);
        previewTimer = setTimeout(runAjaxPreview, 120);
    });

    // If destination changes, refresh preview (if there are serials)
    dest.addEventListener('input', () => {
        if (!autoSwitch.checked) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(runAjaxPreview, 120);
    });

    // Clear
    clearBtn.addEventListener('click', () => {
        ta.value = '';
        updateCount();
        setError('');
        if (previewArea) previewArea.style.display = 'none';
        ta.focus();
    });

})();
</script>

<?php include '../includes/footer.php'; ?>
