<?php
// work_order_view.php

ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$currentUserId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentUserRole = $_SESSION['role'] ?? 'viewer';

$canEdit  = in_array($currentUserRole, ['admin', 'inventory_manager', 'supervisor', 'technician'], true);
$canClose = in_array($currentUserRole, ['admin', 'inventory_manager', 'supervisor', 'technician'], true);
$isAdmin  = ($currentUserRole === 'admin');

$workOrderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($workOrderId <= 0) {
    include '../includes/header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">Invalid Work Order ID.</div></div>';
    include '../includes/footer.php';
    exit;
}

$successMsg = '';
$errorMsg   = '';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $successMsg = 'Work Order items updated successfully.';
}
if (isset($_GET['reopened']) && $_GET['reopened'] === '1') {
    $successMsg = 'Work Order reopened successfully (Admin).';
}

$WO_STATUS_LABELS = [
    'Pending'     => 'Pending',
    'In Progress' => 'In Progress',
    'On Hold'     => 'On Hold',
    'Completed'   => 'Completed',
    'Cancelled'   => 'Cancelled',
];

$WO_STATUS_BADGE = [
    'Pending'     => 'bg-warning text-dark',
    'In Progress' => 'bg-info text-dark',
    'On Hold'     => 'bg-secondary',
    'Completed'   => 'bg-success',
    'Cancelled'   => 'bg-dark',
];

$ITEM_STATUSES = ['Pending', 'In Progress', 'Repaired', 'Scrap', 'Returned', 'Harvest'];
$PROCESS_AREAS = ['REPAIR', 'PRODUCTION', 'EBAY', 'PARTS', 'SCRAP'];

$BIN_NAMES = [
    'EBAY_QUEUE'     => 'EBAY_QUEUE',
    'PARTS_BIN'      => 'PARTS_BIN',
    'SCRAP'          => 'SCRAP',
    'PRODUCTION_BIN' => 'PRODUCTION_BIN',
];

// Admin reopen BEFORE output HTML
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_reopen_wo']) && $isAdmin) {
        $postedId = (int)($_POST['wo_id'] ?? 0);
        if ($postedId !== $workOrderId) {
            throw new Exception("Invalid request.");
        }

        $stmt = $conn->prepare("SELECT status FROM work_orders WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $workOrderId);
        $stmt->execute();
        $cur = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $curStatus = (string)($cur['status'] ?? '');
        if (!in_array($curStatus, ['Completed','Cancelled'], true)) {
            throw new Exception("This Work Order is not locked, so it doesn't need to be reopened.");
        }

        $stmt = $conn->prepare("UPDATE work_orders SET status = 'In Progress', updated_at = NOW() WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $workOrderId);
        $stmt->execute();
        $stmt->close();

        header("Location: work_order_view.php?id=".$workOrderId."&reopened=1");
        exit;
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

include '../includes/header.php';

// Load data
$wo        = null;
$items     = [];
$locations = [];
$locationNameToId = [];
$missingBins = [];

$partsCatalog = [];
try {
    $res = $conn->query("SELECT id, part_code, part_name FROM parts_catalog WHERE is_active=1 ORDER BY part_name ASC");
    while ($r = $res->fetch_assoc()) $partsCatalog[] = $r;
} catch (Throwable $e) {
    // if table not ready, don't crash view
    $partsCatalog = [];
}

try {
    $stmt = $conn->prepare("
        SELECT
            w.id,
            w.wo_number,
            w.title,
            w.status,
            w.priority,
            w.assigned_to,
            w.created_at,
            w.updated_at,
            u.username AS technician_name
        FROM work_orders w
        LEFT JOIN users u ON w.assigned_to = u.id
        WHERE w.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $wo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$wo) throw new Exception("Work order not found.");

    if ($currentUserRole === 'technician') {
        $assignedTo = (int)($wo['assigned_to'] ?? 0);
        if ($assignedTo !== $currentUserId) throw new Exception("Not authorized to view this work order.");
    }

    $stmt = $conn->prepare("
        SELECT
            woi.id AS work_order_item_id,
            woi.work_order_id,
            woi.defective_id,
            woi.status AS item_status,
            woi.notes AS item_notes,
            di.serial_number,
            p.product_id AS model,
            di.category,
            di.grade,
            d.defect_code,
            di.process_area,
            di.location_id
        FROM work_order_items woi
        JOIN defectives_inventory di ON di.id = woi.defective_id
        LEFT JOIN product_ids p ON p.id = di.product_id
        LEFT JOIN defect_codes d ON d.id = di.defect_code_id
        WHERE woi.work_order_id = ?
        ORDER BY woi.id ASC
    ");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $items[] = $r;
    $stmt->close();

    $res = $conn->query("SELECT id, location_name FROM locations ORDER BY location_name ASC");
    while ($r = $res->fetch_assoc()) {
        $locations[] = $r;
        $locationNameToId[(string)$r['location_name']] = (int)$r['id'];
    }

    foreach ($BIN_NAMES as $bn) {
        if (!isset($locationNameToId[$bn])) $missingBins[] = $bn;
    }

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

$woStatus      = $wo ? (string)$wo['status'] : '';
$woStatusLabel = $WO_STATUS_LABELS[$woStatus] ?? ($woStatus ?: 'Unknown');
$woBadge       = $WO_STATUS_BADGE[$woStatus] ?? 'bg-secondary';

$isLocked = in_array($woStatus, ['Completed','Cancelled'], true);

$hasOpenItems = false;
foreach ($items as $it) {
    $st = (string)($it['item_status'] ?? '');
    if (in_array($st, ['Pending', 'In Progress'], true)) { $hasOpenItems = true; break; }
}
$canFinalize = (!$errorMsg && $wo && !$isLocked && $canClose && !empty($items) && !$hasOpenItems);

// Routes
$routes = [];
if (!$errorMsg && $wo && ($wo['status'] ?? '') === 'Completed') {
    $stmt = $conn->prepare("
        SELECT di.process_area, COUNT(*) AS qty
        FROM work_order_items woi
        JOIN defectives_inventory di ON di.id = woi.defective_id
        WHERE woi.work_order_id = ?
          AND di.process_area IS NOT NULL
          AND di.process_area <> ''
          AND di.process_area <> 'REPAIR'
        GROUP BY di.process_area
        ORDER BY di.process_area
    ");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $routes[] = $r;
    $stmt->close();
}
?>

<div class="container mt-4">

    <h2 class="mb-3">
        <i class="bi bi-hammer"></i>
        Work Order <?php echo h($wo['wo_number'] ?? ('#' . $workOrderId)); ?>
    </h2>

    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo h($successMsg); ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?php echo h($errorMsg); ?></div>
    <?php endif; ?>

    <?php if (!$errorMsg && $wo): ?>

        <?php if (!empty($missingBins)): ?>
            <div class="alert alert-warning">
                <strong>Missing required BIN(s)</strong>: <?php echo h(implode(', ', $missingBins)); ?>.<br>
                Create them in <code>locations</code> so Finalize can auto-select the right destination.
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <strong>Status:</strong><br>
                        <span class="badge <?php echo h($woBadge); ?>"><?php echo h($woStatusLabel); ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Technician:</strong><br>
                        <?php echo h($wo['technician_name'] ?? $wo['assigned_to'] ?? ''); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Created:</strong><br>
                        <?php echo h($wo['created_at'] ?? ''); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Updated:</strong><br>
                        <?php echo h($wo['updated_at'] ?? ''); ?>
                    </div>
                </div>

                <?php if (!empty($wo['title'])): ?>
                    <div class="mt-3"><strong>Title:</strong> <?php echo h($wo['title']); ?></div>
                <?php endif; ?>

                <?php if ($isLocked): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        This Work Order is <strong><?php echo h($woStatus); ?></strong>. Editing is locked.
                        <?php if ($isAdmin): ?>
                            <div class="mt-2 small">
                                Admin can reopen it, but this does <strong>not</strong> rollback any inventory moves that may already have happened.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($isAdmin): ?>
                        <form method="post" class="mt-2">
                            <input type="hidden" name="wo_id" value="<?php echo (int)$workOrderId; ?>">
                            <button type="submit" name="admin_reopen_wo" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Reopen this Work Order? (Admin) This will unlock editing. It will NOT rollback inventory movements.');">
                                <i class="bi bi-unlock"></i> Reopen (Admin)
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Work Order Items</strong></div>
            <div class="card-body p-0">
                <?php if (empty($items)): ?>
                    <div class="p-3">No items in this work order.</div>
                <?php else: ?>

                    <form method="post" action="update_work_order_items_bulk.php" class="m-0">
                        <input type="hidden" name="work_order_id" value="<?php echo (int)$workOrderId; ?>">
                        <input type="hidden" name="return_to" value="<?php echo h($_SERVER['REQUEST_URI'] ?? 'work_orders_list.php'); ?>">

                        <div class="table-responsive">
                            <table class="table table-striped table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Serial</th>
                                        <th>Model</th>
                                        <th>Category</th>
                                        <th>Grade</th>
                                        <th>Defect</th>
                                        <th style="width: 180px;">Process Area</th>
                                        <th style="width: 180px;">Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it): ?>
                                        <?php
                                            $woiId             = (int)$it['work_order_item_id'];
                                            $currentArea       = (string)($it['process_area'] ?? '');
                                            $currentItemStatus = (string)($it['item_status'] ?? 'Pending');
                                            $currentNotes      = (string)($it['item_notes'] ?? '');
                                        ?>
                                        <tr>
                                            <td><?php echo h($it['serial_number'] ?? ''); ?></td>
                                            <td><?php echo h($it['model'] ?? ''); ?></td>
                                            <td><?php echo h($it['category'] ?? ''); ?></td>
                                            <td><?php echo h($it['grade'] ?? ''); ?></td>
                                            <td><?php echo h($it['defect_code'] ?? ''); ?></td>

                                            <td>
                                                <select class="form-select form-select-sm"
                                                    name="items[<?php echo $woiId; ?>][process_area]"
                                                    <?php echo (!$canEdit || $isLocked) ? 'disabled' : ''; ?>>
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($PROCESS_AREAS as $pa): ?>
                                                        <option value="<?php echo h($pa); ?>" <?php echo $currentArea === $pa ? 'selected' : ''; ?>>
                                                            <?php echo h($pa); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>

                                            <td>
                                                <select class="form-select form-select-sm"
                                                    name="items[<?php echo $woiId; ?>][status]"
                                                    <?php echo (!$canEdit || $isLocked) ? 'disabled' : ''; ?>>
                                                    <?php foreach ($ITEM_STATUSES as $st): ?>
                                                        <option value="<?php echo h($st); ?>" <?php echo $currentItemStatus === $st ? 'selected' : ''; ?>>
                                                            <?php echo h($st); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>

                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                    name="items[<?php echo $woiId; ?>][notes]"
                                                    value="<?php echo h($currentNotes); ?>"
                                                    placeholder="Notes..."
                                                    <?php echo (!$canEdit || $isLocked) ? 'disabled' : ''; ?>>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3 d-flex justify-content-between align-items-center">
                            <a href="work_orders_list.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Work Orders
                            </a>

                            <?php if ($canEdit && !$isLocked): ?>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save"></i> Save All
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>

                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3 small text-muted">
            Tip: Finish all items (no <code>Pending</code> / <code>In Progress</code>), then use <strong>Finalize</strong>.
        </div>

        <?php if (!$isLocked && !empty($items)): ?>
            <div class="card mt-3 border-primary">
                <div class="card-header bg-primary text-white">
                    <strong><i class="bi bi-check2-circle"></i> Finalize / Move Inventory</strong>
                </div>
                <div class="card-body">

                    <?php if ($hasOpenItems): ?>
                        <div class="alert alert-warning mb-3">
                            You still have items in <strong>Pending</strong> or <strong>In Progress</strong>. Finalize is disabled.
                        </div>
                    <?php endif; ?>

                    <form method="post" action="close_work_order.php" class="m-0">
                        <input type="hidden" name="wo_id" value="<?php echo (int)$workOrderId; ?>">

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Serial</th>
                                        <th>Model</th>
                                        <th>Status</th>
                                        <th>Process Area</th>
                                        <th style="width: 320px;">Destination BIN</th>
                                        <th style="width: 340px;">Harvest Parts (qty per part)</th>
                                        <th style="width: 240px;">Harvest Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it): ?>
                                        <?php
                                            $woiId   = (int)$it['work_order_item_id'];
                                            $serial  = (string)($it['serial_number'] ?? '');
                                            $model   = (string)($it['model'] ?? '');
                                            $st      = (string)($it['item_status'] ?? '');
                                            $area    = (string)($it['process_area'] ?? '');
                                            $curLoc  = (int)($it['location_id'] ?? 0);

                                            $rowClass = '';
                                            if ($st === 'Repaired') $rowClass = 'table-success';
                                            elseif ($st === 'Scrap') $rowClass = 'table-danger';
                                            elseif ($st === 'Returned') $rowClass = 'table-warning';
                                        ?>
                                        <tr class="<?php echo h($rowClass); ?>"
                                            data-item-status="<?php echo h($st); ?>"
                                            data-process-area="<?php echo h($area); ?>">
                                            <td><?php echo h($serial); ?></td>
                                            <td><?php echo h($model); ?></td>
                                            <td><strong><?php echo h($st); ?></strong></td>
                                            <td><?php echo h($area); ?></td>

                                            <td>
                                                <select class="form-select form-select-sm"
                                                    data-role="dest-bin"
                                                    name="to_location_id[<?php echo $woiId; ?>]"
                                                    <?php echo $canFinalize ? '' : 'disabled'; ?>
                                                    required>
                                                    <option value="">-- Select destination BIN --</option>
                                                    <?php foreach ($locations as $loc): ?>
                                                        <?php
                                                            $lid = (int)$loc['id'];
                                                            $lname = (string)$loc['location_name'];
                                                            $selected = ($lid === $curLoc) ? 'selected' : '';
                                                        ?>
                                                        <option value="<?php echo (int)$lid; ?>" <?php echo $selected; ?>>
                                                            <?php echo h($lname); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="small text-muted mt-1">
                                                    Current: <code><?php echo (int)$curLoc; ?></code>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if ($st === 'Harvest'): ?>
                                                    <?php if (empty($partsCatalog)): ?>
                                                        <div class="text-danger small">parts_catalog is empty or missing.</div>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-column gap-2">
                                                            <?php foreach ($partsCatalog as $p): ?>
                                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                                    <div class="small">
                                                                        <strong><?php echo h($p['part_name']); ?></strong>
                                                                        <span class="text-muted">(<?php echo h($p['part_code']); ?>)</span>
                                                                    </div>
                                                                    <input type="number"
                                                                        class="form-control form-control-sm"
                                                                        style="width: 90px;"
                                                                        name="harvest_qty[<?php echo (int)$woiId; ?>][<?php echo (int)$p['id']; ?>]"
                                                                        value="0" min="0" max="99" step="1">
                                                                </div>
                                                            <?php endforeach; ?>
                                                            <div class="small text-muted">0 = not harvested.</div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if ($st === 'Harvest'): ?>
                                                    <input type="text"
                                                        class="form-control form-control-sm"
                                                        name="harvest_notes[<?php echo (int)$woiId; ?>]"
                                                        placeholder="Notes for harvested parts...">
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button type="submit"
                                class="btn btn-primary"
                                <?php echo $canFinalize ? '' : 'disabled'; ?>
                                onclick="return confirm('Finalize this Work Order? This will move inventory + update stock + write logs.');">
                                <i class="bi bi-check2-circle"></i> Finalize Work Order
                            </button>
                        </div>

                        <div class="small text-muted mt-2">
                            Finalize will: move BIN, update <code>removal_status</code>, and log in <code>inventory_movements</code>.
                        </div>
                    </form>

                </div>
            </div>
        <?php endif; ?>

        <?php if (($wo['status'] ?? '') === 'Completed'): ?>
            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="mb-2"><i class="bi bi-printer"></i> Route Receipts</h5>

                    <?php if (empty($routes)): ?>
                        <div class="text-muted">No routed items found (Process Area is empty or REPAIR).</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($routes as $rt): ?>
                                <a class="btn btn-outline-dark" target="_blank"
                                   href="work_order_receipt.php?wo_id=<?php echo (int)$workOrderId; ?>&route=<?php echo urlencode($rt['process_area']); ?>&autoprint=1">
                                    <i class="bi bi-receipt"></i>
                                    Print <?php echo h($rt['process_area']); ?> (<?php echo (int)$rt['qty']; ?>)
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-muted mt-2">
                            Each button prints a receipt with only the items routed to that destination.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
(function() {
  const nameToId = {};
  <?php foreach ($locations as $loc): ?>
    nameToId[<?php echo json_encode((string)$loc['location_name']); ?>] = <?php echo (int)$loc['id']; ?>;
  <?php endforeach; ?>

  function desiredBinName(itemStatus, processArea) {
    const st = (itemStatus || '').trim().toUpperCase();
    const pa = (processArea || '').trim().toUpperCase();

    if (st === 'HARVEST') return 'PARTS_BIN';
    if (st === 'SCRAP') return 'SCRAP';

    // Returned should STAY in current BIN
    if (st === 'RETURNED') return null;

    if (st === 'REPAIRED') {
      if (pa === 'EBAY') return 'EBAY_QUEUE';
      if (pa === 'PRODUCTION') return 'PRODUCTION_BIN';
    }
    return null;
  }

  document.querySelectorAll('tr[data-item-status]').forEach(tr => {
    const st = tr.getAttribute('data-item-status');
    const pa = tr.getAttribute('data-process-area');
    const wantName = desiredBinName(st, pa);
    if (!wantName) return;

    const sel = tr.querySelector('select[data-role="dest-bin"]');
    if (!sel) return;

    const id = nameToId[wantName];
    if (!id) return;

    sel.value = String(id);
  });
})();
</script>

<?php include '../includes/footer.php'; ?>
<?php ob_end_flush(); ?>
