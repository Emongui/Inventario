<?php
// work_order_receipt.php
// Imprime recibo por ruta (process_area) para una Work Order, incluyendo el modelo (Product).
// Supports autoprint=1 (auto triggers window.print()).

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------
// Auth / Role
// ---------------------------------------------------------
$role = $_SESSION['role'] ?? 'viewer';
$allowedRoles = ['admin', 'inventory_manager', 'supervisor', 'technician'];
if (!in_array($role, $allowedRoles, true)) {
    die("Not authorized.");
}

// ---------------------------------------------------------
// Params
// ---------------------------------------------------------
$workOrderId = isset($_GET['wo_id']) ? (int)$_GET['wo_id'] : 0;
$route       = isset($_GET['route']) ? trim((string)$_GET['route']) : '';
$autoprint   = (isset($_GET['autoprint']) && (string)$_GET['autoprint'] === '1');

if ($workOrderId <= 0 || $route === '') {
    die("Invalid parameters.");
}

// Only valid routes
$validRoutes = ['PRODUCTION', 'EBAY', 'PARTS', 'SCRAP'];
if (!in_array($route, $validRoutes, true)) {
    die("Invalid route.");
}

// Optional: who receives this pile (for handoff)
$ROUTE_HANDOFF = [
    'PRODUCTION' => 'Production Team',
    'EBAY'       => 'eBay Listing Team',
    'PARTS'      => 'Parts / Harvest Team',
    'SCRAP'      => 'Scrap / Disposal',
];

// ---------------------------------------------------------
// Load WO header + routed items
// ---------------------------------------------------------
$wo = null;
$items = [];
$errorMsg = '';

try {
    $stmt = $conn->prepare("
        SELECT
            w.id,
            w.wo_number,
            w.title,
            w.status,
            w.assigned_to,
            w.created_at,
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

    if (!$wo) {
        throw new Exception("Work order not found.");
    }

    // Technician can only print their WOs
    if ($role === 'technician') {
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $assignedTo = (int)($wo['assigned_to'] ?? 0);
        if ($assignedTo !== $currentUserId) {
            throw new Exception("Not authorized to print this work order.");
        }
    }

    // Routed items (include model)
    $stmt = $conn->prepare("
        SELECT
            di.serial_number,
            p.product_id AS model,
            di.category,
            di.grade,
            d.defect_code,
            woi.status AS item_status,
            di.process_area
        FROM work_order_items woi
        JOIN defectives_inventory di ON di.id = woi.defective_id
        LEFT JOIN product_ids p ON di.product_id = p.id
        LEFT JOIN defect_codes d ON d.id = di.defect_code_id
        WHERE woi.work_order_id = ?
          AND di.process_area = ?
        ORDER BY p.product_id ASC, di.serial_number ASC
    ");
    $stmt->bind_param("is", $workOrderId, $route);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

// Build model summary
$modelSummary = [];
foreach ($items as $it) {
    $m = (string)($it['model'] ?? '');
    if ($m === '') $m = '(Unknown Model)';
    if (!isset($modelSummary[$m])) $modelSummary[$m] = 0;
    $modelSummary[$m]++;
}
ksort($modelSummary);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>WO Receipt - <?php echo h($workOrderId); ?> - <?php echo h($route); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap (ok for printing) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        @media print {
            .no-print { display: none !important; }
            body { padding: 0 !important; }
            .card { border: none !important; }
        }
        body { padding: 18px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .smallcaps { letter-spacing: .06em; text-transform: uppercase; font-size: 12px; }
    </style>
</head>
<body>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
    <a class="btn btn-outline-secondary" href="javascript:window.close();">Close</a>
    <button class="btn btn-primary" onclick="window.print();">Print</button>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?php echo h($errorMsg); ?></div>
<?php else: ?>

    <div class="mb-3">
        <h3 class="mb-1">Route Receipt</h3>
        <div class="text-muted">Handoff list for destination route.</div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <div><strong>WO #:</strong> <span class="mono"><?php echo h($wo['wo_number'] ?? ('#'.$workOrderId)); ?></span></div>
                    <div><strong>Route:</strong> <span class="badge bg-dark"><?php echo h($route); ?></span></div>
                    <div class="mt-2">
                        <span class="smallcaps text-muted">Receiver / Handoff</span><br>
                        <strong><?php echo h($ROUTE_HANDOFF[$route] ?? ''); ?></strong>
                    </div>
                </div>
                <div class="col-md-4">
                    <div><strong>Technician:</strong> <?php echo h($wo['technician_name'] ?? $wo['assigned_to'] ?? ''); ?></div>
                    <div><strong>Status:</strong> <?php echo h($wo['status'] ?? ''); ?></div>
                    <?php if (!empty($wo['title'])): ?>
                        <div class="mt-2"><strong>Title:</strong> <?php echo h($wo['title']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <div><strong>Created:</strong> <?php echo h($wo['created_at'] ?? ''); ?></div>
                    <div><strong>Printed:</strong> <?php echo h(date('Y-m-d H:i:s')); ?></div>
                    <div class="mt-2">
                        <span class="smallcaps text-muted">Total Units</span><br>
                        <span class="badge bg-primary"><?php echo (int)count($items); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Model summary (super útil en piso) -->
    <?php if (!empty($modelSummary)): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Model Summary</strong>
                        <div class="text-muted small">Quick count to separate stacks faster.</div>
                    </div>
                </div>
                <div class="table-responsive mt-2">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Model</th>
                                <th style="width:120px;">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modelSummary as $m => $qty): ?>
                                <tr>
                                    <td><?php echo h($m); ?></td>
                                    <td class="fw-bold"><?php echo (int)$qty; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($items)): ?>
                <div class="p-3 text-muted">No items found for this route.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Serial</th>
                            <th>Model</th>
                            <th>Category</th>
                            <th>Grade</th>
                            <th>Defect</th>
                            <th>Item Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td class="mono"><?php echo h($it['serial_number']); ?></td>
                                <td><?php echo h($it['model'] ?? ''); ?></td>
                                <td><?php echo h($it['category'] ?? ''); ?></td>
                                <td><?php echo h($it['grade'] ?? ''); ?></td>
                                <td><?php echo h($it['defect_code'] ?? ''); ?></td>
                                <td><?php echo h($it['item_status'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 d-flex justify-content-between">
                    <div class="text-muted small">
                        Only items routed to <strong><?php echo h($route); ?></strong>.
                    </div>
                    <div class="fw-bold">
                        Total: <?php echo (int)count($items); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php if ($autoprint && !$errorMsg): ?>
<script>
  window.addEventListener('load', () => {
    window.print();
  });
</script>
<?php endif; ?>

</body>
</html>
