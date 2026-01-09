<?php
// /views/inventory_value_manager.php
// View: Inventory + filters + Recovery Value (single + bulk update)
//
// Requires:
//  - ../includes/auth.php
//  - ../includes/db_connection.php
//  - ../includes/header.php
//  - ../includes/footer.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$currentRole = $_SESSION['role'] ?? 'viewer';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Who can edit values?
$canEdit = in_array($currentRole, ['admin','supervisor','inventory'], true);

$flash = '';
$flashType = 'success';

function clampInt($v, int $min, int $max, int $fallback): int {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    if ($n === false) return $fallback;
    return max($min, min($max, $n));
}

function parseMoney($v): ?float {
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;

    // allow "123", "123.45", "1,234.56"
    $v = str_replace([',', '$', ' '], '', $v);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) return null;
    return (float)$v;
}

function buildWhere(array $f, array &$params, string &$types): string {
    $where = [];

    if ($f['q'] !== '') {
        $where[] = "(di.serial_number LIKE CONCAT('%', ?, '%') OR p.product_id LIKE CONCAT('%', ?, '%'))";
        $params[] = $f['q'];
        $params[] = $f['q'];
        $types .= "ss";
    }

    if ($f['category'] !== '') {
        $where[] = "di.category = ?";
        $params[] = $f['category'];
        $types .= "s";
    }

    if ($f['model'] !== '') {
        $where[] = "p.product_id = ?";
        $params[] = $f['model'];
        $types .= "s";
    }

    if ($f['defect_main'] !== '') {
        $where[] = "dc.main_category = ?";
        $params[] = $f['defect_main'];
        $types .= "s";
    }

    if ($f['defect_code_id'] !== '') {
        $where[] = "di.defect_code_id = ?";
        $params[] = (int)$f['defect_code_id'];
        $types .= "i";
    }

    if ($f['status'] !== '') {
        $where[] = "di.status = ?";
        $params[] = $f['status'];
        $types .= "s";
    }

    if ($f['removal_status'] !== '') {
        $where[] = "di.removal_status = ?";
        $params[] = $f['removal_status'];
        $types .= "s";
    }

    return $where ? ("WHERE " . implode(" AND ", $where)) : "";
}

// -------- Filters (GET) --------
$filters = [
    'q'             => trim((string)($_GET['q'] ?? '')),
    'category'      => trim((string)($_GET['category'] ?? '')),
    'model'         => trim((string)($_GET['model'] ?? '')),
    'defect_main'   => trim((string)($_GET['defect_main'] ?? '')),
    'defect_code_id'=> trim((string)($_GET['defect_code_id'] ?? '')),
    'status'        => trim((string)($_GET['status'] ?? '')),
    'removal_status'=> trim((string)($_GET['removal_status'] ?? '')),
];

$page = clampInt($_GET['page'] ?? 1, 1, 999999, 1);
$perPage = clampInt($_GET['per_page'] ?? 50, 10, 200, 50);
$offset = ($page - 1) * $perPage;

// -------- Handle POST updates --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        $flash = "You don't have permission to edit values.";
        $flashType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save_single') {
                $id = (int)($_POST['id'] ?? 0);
                $value = parseMoney($_POST['recovery_value'] ?? '');

                $stmt = $conn->prepare("UPDATE defectives_inventory SET recovery_value = ?, last_updated = NOW() WHERE id = ? LIMIT 1");
                if ($value === null) {
                    // set NULL
                    $null = null;
                    $stmt->bind_param("di", $null, $id);
                } else {
                    $stmt->bind_param("di", $value, $id);
                }
                $stmt->execute();

                $flash = "Saved value for ID #{$id}.";
                $flashType = 'success';
            }

            elseif ($action === 'bulk_selected') {
                $ids = $_POST['ids'] ?? [];
                $bulkValue = parseMoney($_POST['bulk_value'] ?? '');

                if (!is_array($ids) || count($ids) === 0) {
                    $flash = "No items selected.";
                    $flashType = 'warning';
                } else {
                    // sanitize ids
                    $ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));
                    if (count($ids) === 0) {
                        $flash = "No valid items selected.";
                        $flashType = 'warning';
                    } else {
                        $conn->begin_transaction();

                        // chunk to avoid max placeholders issues
                        $chunks = array_chunk($ids, 300);

                        foreach ($chunks as $chunk) {
                            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                            if ($bulkValue === null) {
                                $sql = "UPDATE defectives_inventory SET recovery_value = NULL, last_updated = NOW() WHERE id IN ($placeholders)";
                                $types = str_repeat('i', count($chunk));
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param($types, ...$chunk);
                            } else {
                                $sql = "UPDATE defectives_inventory SET recovery_value = ?, last_updated = NOW() WHERE id IN ($placeholders)";
                                $types = 'd' . str_repeat('i', count($chunk));
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param($types, $bulkValue, ...$chunk);
                            }
                            $stmt->execute();
                        }

                        $conn->commit();
                        $flash = "Bulk update applied to " . count($ids) . " selected items.";
                        $flashType = 'success';
                    }
                }
            }

            elseif ($action === 'bulk_filtered') {
                // Apply to ALL items matching current filters (dangerous but useful)
                $bulkValue = parseMoney($_POST['bulk_value_filtered'] ?? '');

                // Rebuild WHERE from hidden inputs (keeps it consistent)
                $f = [
                    'q'             => trim((string)($_POST['f_q'] ?? '')),
                    'category'      => trim((string)($_POST['f_category'] ?? '')),
                    'model'         => trim((string)($_POST['f_model'] ?? '')),
                    'defect_main'   => trim((string)($_POST['f_defect_main'] ?? '')),
                    'defect_code_id'=> trim((string)($_POST['f_defect_code_id'] ?? '')),
                    'status'        => trim((string)($_POST['f_status'] ?? '')),
                    'removal_status'=> trim((string)($_POST['f_removal_status'] ?? '')),
                ];

                $params = [];
                $types = '';
                $where = buildWhere($f, $params, $types);

                // Update using JOIN so filters can use dc/p
                $sql = "
                    UPDATE defectives_inventory di
                    LEFT JOIN product_ids p ON p.id = di.product_id
                    LEFT JOIN defect_codes dc ON dc.id = di.defect_code_id
                    SET di.recovery_value = " . ($bulkValue === null ? "NULL" : "?") . ",
                        di.last_updated = NOW()
                    $where
                ";

                $stmt = $conn->prepare($sql);
                if ($bulkValue === null) {
                    if ($types !== '') $stmt->bind_param($types, ...$params);
                } else {
                    $types2 = 'd' . $types;
                    $params2 = array_merge([$bulkValue], $params);
                    $stmt->bind_param($types2, ...$params2);
                }

                $stmt->execute();
                $flash = "Bulk update applied to all filtered items.";
                $flashType = 'success';
            }

        } catch (Throwable $e) {
            if ($conn->errno) {
                // if transaction open, rollback
                try { $conn->rollback(); } catch (Throwable $t) {}
            }
            $flash = "Error: " . $e->getMessage();
            $flashType = 'danger';
        }
    }

    // keep user on the same filters/page after POST
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header("Location: inventory_value_manager.php" . ($qs ? ("?$qs") : "") . "&msg=" . urlencode($flash) . "&type=" . urlencode($flashType));
    exit();
}

// If redirected with msg
if (isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['type'] ?? 'success');
}

// -------- Dropdown data --------
$categories = [];
$res = $conn->query("SELECT DISTINCT category FROM defectives_inventory WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
while ($row = $res->fetch_assoc()) $categories[] = $row['category'];

$models = [];
$res = $conn->query("
    SELECT DISTINCT p.product_id AS model
    FROM defectives_inventory di
    JOIN product_ids p ON p.id = di.product_id
    WHERE p.product_id IS NOT NULL AND p.product_id <> ''
    ORDER BY p.product_id ASC
");
while ($row = $res->fetch_assoc()) $models[] = $row['model'];

$defectMains = [];
$res = $conn->query("SELECT DISTINCT main_category FROM defect_codes WHERE main_category IS NOT NULL AND main_category <> '' ORDER BY main_category ASC");
while ($row = $res->fetch_assoc()) $defectMains[] = $row['main_category'];

$defectCodes = [];
// Show codes filtered by defect_main if selected (nicer UX)
if ($filters['defect_main'] !== '') {
    $stmt = $conn->prepare("SELECT id, defect_code, sub_category FROM defect_codes WHERE main_category = ? ORDER BY defect_code ASC");
    $stmt->bind_param("s", $filters['defect_main']);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $defectCodes[] = $row;
} else {
    $r = $conn->query("SELECT id, defect_code, sub_category, main_category FROM defect_codes ORDER BY defect_code ASC");
    while ($row = $r->fetch_assoc()) $defectCodes[] = $row;
}

// -------- Query list + count --------
$params = [];
$types = '';
$where = buildWhere($filters, $params, $types);

// Count
$sqlCount = "
    SELECT COUNT(*) AS total
    FROM defectives_inventory di
    LEFT JOIN product_ids p ON p.id = di.product_id
    LEFT JOIN defect_codes dc ON dc.id = di.defect_code_id
    $where
";
$stmt = $conn->prepare($sqlCount);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

$totalPages = max(1, (int)ceil($total / $perPage));

// Data
$sqlData = "
    SELECT
        di.id,
        di.serial_number,
        di.category,
        di.grade,
        di.status,
        di.removal_status,
        di.removal_destination,
        di.recovery_value,
        di.date_added,
        di.last_updated,
        l.location_name AS bin_location,
        p.product_id AS model,
        dc.main_category,
        dc.sub_category,
        dc.defect_code
    FROM defectives_inventory di
    LEFT JOIN locations l ON l.id = di.location_id
    LEFT JOIN product_ids p ON p.id = di.product_id
    LEFT JOIN defect_codes dc ON dc.id = di.defect_code_id
    $where
    ORDER BY di.last_updated DESC, di.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sqlData);
if ($types !== '') {
    $types2 = $types . "ii";
    $params2 = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types2, ...$params2);
} else {
    $stmt->bind_param("ii", $perPage, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

require_once '../includes/header.php';
?>

<div class="container-fluid mt-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div>
            <h4 class="mb-0">Inventory Value Manager</h4>
            <div class="text-muted small">
                Total results: <strong><?= (int)$total ?></strong>
                <?php if (!$canEdit): ?>
                    <span class="badge bg-secondary ms-2">Read-only (role: <?= h($currentRole) ?>)</span>
                <?php else: ?>
                    <span class="badge bg-success ms-2">Editable (role: <?= h($currentRole) ?>)</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="inventory_value_manager.php">Reset</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Search (Serial / Model)</label>
                    <input type="text" name="q" class="form-control" value="<?= h($filters['q']) ?>" placeholder="e.g. C02... or IPAD...">
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= h($c) ?>" <?= $filters['category']===$c?'selected':'' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Model (Product ID)</label>
                    <select name="model" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($models as $m): ?>
                            <option value="<?= h($m) ?>" <?= $filters['model']===$m?'selected':'' ?>><?= h($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Defect Type</label>
                    <select name="defect_main" class="form-select" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($defectMains as $dm): ?>
                            <option value="<?= h($dm) ?>" <?= $filters['defect_main']===$dm?'selected':'' ?>><?= h($dm) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Selecting reloads defect codes list</div>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Defect Code</label>
                    <select name="defect_code_id" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($defectCodes as $dc): ?>
                            <?php
                                $label = $dc['defect_code'] ?? '';
                                if (!empty($dc['sub_category'])) $label .= " — " . $dc['sub_category'];
                                if (!$filters['defect_main'] && !empty($dc['main_category'])) $label = ($dc['main_category'] . " | " . $label);
                            ?>
                            <option value="<?= (int)$dc['id'] ?>" <?= $filters['defect_code_id']===(string)$dc['id']?'selected':'' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <?php foreach (['In Stock','In Repair','Hold','Scrap','Sold'] as $st): ?>
                            <option value="<?= h($st) ?>" <?= $filters['status']===$st?'selected':'' ?>><?= h($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Removal Status</label>
                    <select name="removal_status" class="form-select">
                        <option value="">All</option>
                        <?php foreach (['In Stock','Removed'] as $rs): ?>
                            <option value="<?= h($rs) ?>" <?= $filters['removal_status']===$rs?'selected':'' ?>><?= h($rs) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-1">
                    <label class="form-label mb-1">Per page</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ([25,50,100,200] as $pp): ?>
                            <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-1 d-grid">
                    <button class="btn btn-primary">Filter</button>
                </div>
            </div>

            <?php if ($canEdit): ?>
                <hr class="my-3">

                <div class="row g-2">
                    <div class="col-12 col-lg-6">
                        <div class="p-3 border rounded">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Bulk update (selected)</strong>
                                    <div class="text-muted small">Check rows → set value → apply.</div>
                                </div>
                            </div>

                            <form method="post" id="bulkSelectedForm" class="mt-2">
                                <input type="hidden" name="action" value="bulk_selected">
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" name="bulk_value" class="form-control" placeholder="e.g. 45.00 (empty = NULL)">
                                    <button class="btn btn-success" type="submit">Apply to selected</button>
                                </div>
                                <div class="form-text">Leave empty to clear (set NULL).</div>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="p-3 border rounded border-warning">
                            <strong>Bulk update (ALL filtered)</strong>
                            <div class="text-muted small">Applies to every row in the current filtered results.</div>

                            <form method="post" class="mt-2" onsubmit="return confirm('Apply value to ALL filtered items? This can update many rows. Continue?');">
                                <input type="hidden" name="action" value="bulk_filtered">
                                <!-- carry filters -->
                                <input type="hidden" name="f_q" value="<?= h($filters['q']) ?>">
                                <input type="hidden" name="f_category" value="<?= h($filters['category']) ?>">
                                <input type="hidden" name="f_model" value="<?= h($filters['model']) ?>">
                                <input type="hidden" name="f_defect_main" value="<?= h($filters['defect_main']) ?>">
                                <input type="hidden" name="f_defect_code_id" value="<?= h($filters['defect_code_id']) ?>">
                                <input type="hidden" name="f_status" value="<?= h($filters['status']) ?>">
                                <input type="hidden" name="f_removal_status" value="<?= h($filters['removal_status']) ?>">

                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" name="bulk_value_filtered" class="form-control" placeholder="e.g. 45.00 (empty = NULL)">
                                    <button class="btn btn-warning" type="submit">Apply to ALL filtered</button>
                                </div>
                                <div class="form-text">Leave empty to clear (set NULL).</div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </form>

    <!-- Table -->
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark" style="position: sticky; top: 0; z-index: 2;">
                    <tr>
                        <?php if ($canEdit): ?>
                            <th style="width:40px;">
                                <input type="checkbox" id="checkAll" class="form-check-input">
                            </th>
                        <?php endif; ?>
                        <th>ID</th>
                        <th>Serial</th>
                        <th>Category</th>
                        <th>Model</th>
                        <th>Grade</th>
                        <th>Defect</th>
                        <th>BIN</th>
                        <th>Status</th>
                        <th>Removal</th>
                        <th style="width:180px;">Recovery Value</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result->num_rows === 0): ?>
                    <tr><td colspan="<?= $canEdit ? '12' : '11' ?>" class="text-center text-muted py-4">No results.</td></tr>
                <?php endif; ?>

                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                        $defectLabel = trim(($row['main_category'] ?? '') . ' | ' . ($row['defect_code'] ?? ''));
                        $defectLabel = trim($defectLabel, " |");
                        $val = $row['recovery_value'];
                        $valShown = ($val === null) ? '' : number_format((float)$val, 2);
                    ?>
                    <tr>
                        <?php if ($canEdit): ?>
                            <td>
                                <input
                                    type="checkbox"
                                    class="form-check-input rowCheck"
                                    name="ids[]"
                                    form="bulkSelectedForm"
                                    value="<?= (int)$row['id'] ?>"
                                >
                            </td>
                        <?php endif; ?>

                        <td><?= (int)$row['id'] ?></td>
                        <td><span class="font-monospace"><?= h($row['serial_number']) ?></span></td>
                        <td><?= h($row['category']) ?></td>
                        <td><?= h($row['model']) ?></td>
                        <td><?= h($row['grade']) ?></td>
                        <td title="<?= h(($row['sub_category'] ?? '')) ?>"><?= h($defectLabel) ?></td>
                        <td><?= h($row['bin_location']) ?></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?= h($row['removal_status']) ?><?= $row['removal_destination'] ? " → " . h($row['removal_destination']) : "" ?></td>

                        <td>
                            <?php if ($canEdit): ?>
                                <form method="post" class="d-flex gap-2">
                                    <input type="hidden" name="action" value="save_single">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">$</span>
                                        <input
                                            type="text"
                                            name="recovery_value"
                                            class="form-control"
                                            value="<?= h($valShown) ?>"
                                            placeholder="(empty = NULL)"
                                        >
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted"><?= $valShown === '' ? '—' : ('$' . h($valShown)) ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="small text-muted"><?= h($row['last_updated']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="text-muted small">
                Page <strong><?= (int)$page ?></strong> / <strong><?= (int)$totalPages ?></strong>
            </div>

            <?php
                // keep querystring but change page
                $base = $_GET;
                unset($base['page']);
                $baseQS = http_build_query($base);
                $baseQS = $baseQS ? ($baseQS . '&') : '';
            ?>
            <div class="btn-group">
                <a class="btn btn-outline-secondary btn-sm <?= $page<=1?'disabled':'' ?>" href="?<?= $baseQS ?>page=1">First</a>
                <a class="btn btn-outline-secondary btn-sm <?= $page<=1?'disabled':'' ?>" href="?<?= $baseQS ?>page=<?= max(1,$page-1) ?>">Prev</a>
                <a class="btn btn-outline-secondary btn-sm <?= $page>=$totalPages?'disabled':'' ?>" href="?<?= $baseQS ?>page=<?= min($totalPages,$page+1) ?>">Next</a>
                <a class="btn btn-outline-secondary btn-sm <?= $page>=$totalPages?'disabled':'' ?>" href="?<?= $baseQS ?>page=<?= (int)$totalPages ?>">Last</a>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const checkAll = document.getElementById('checkAll');
    if (!checkAll) return;

    checkAll.addEventListener('change', function(){
        document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = checkAll.checked);
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
