<?php
// defectives_list.php
// Muestra SOLO equipos clasificados para REPAIR (process_area = 'REPAIR')
// y permite seleccionarlos para crear una Work Order.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// (Opcional) limitar por rol – el create_work_order.php ya valida otra vez
// ---------------------------------------------------------------------
$session_role = $_SESSION['role'] ?? 'viewer';
$allowed_roles = ['admin','supervisor'];
if (!in_array($session_role, $allowed_roles, true)) {
    echo '<div class="alert alert-danger mt-4">You do not have permission to create work orders.</div>';
    include '../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Cargar SOLO equipos en process_area = 'REPAIR'
// (ya clasificados desde manage_process_area.php)
// ---------------------------------------------------------------------
$sql = "
    SELECT
        di.id,
        di.serial_number,
        di.status,
        di.process_area,
        di.bin_location,
        di.invoice_number,
        p.product_id,
        d.defect_code
    FROM defectives_inventory di
    LEFT JOIN product_ids  p ON di.product_id = p.id
    LEFT JOIN defect_codes d ON di.defect_code_id = d.id
    WHERE di.process_area = 'REPAIR'
    ORDER BY di.id DESC
    LIMIT 500
";
$result = $conn->query($sql);

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$result->close();

// Badge helper
function badgeStatus($status) {
    $colors = [
        'In Stock'  => 'secondary',
        'In Repair' => 'warning',
        'Scrap'     => 'danger',
        'Sold'      => 'success'
    ];
    $c = $colors[$status] ?? 'secondary';
    return "<span class=\"badge bg-{$c}\">".h($status)."</span>";
}

function badgeArea($area) {
    $colors = [
        'REPAIR'      => 'warning',
        'EBAY'        => 'primary',
        'PARTS'       => 'dark',
        'SCRAP'       => 'danger',
        'PRODUCTION'  => 'success'
    ];
    $c = $colors[$area] ?? 'secondary';
    return "<span class=\"badge bg-{$c}\">".h($area)."</span>";
}
?>

<div class="container mt-3">

    <h3 class="mb-1">
        Defectives Inventory – Select Devices for Work Order
    </h3>
    <p class="text-muted mb-3">
        This list shows only devices classified as <strong>REPAIR</strong> in
        <code>manage_process_area.php</code>.  
        Select between <strong>1</strong> and <strong>20</strong> devices to create a Work Order.
    </p>

    <?php if (empty($rows)): ?>
        <div class="alert alert-info">
            There are no devices in <strong>REPAIR</strong> process area at the moment.
            Go to <em>Manage Process Area</em> to classify new units.
        </div>
    <?php else: ?>

    <form method="post" action="create_work_order.php" id="woSelectForm">
        <input type="hidden" name="step" value="select_devices">

        <div class="mb-2">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-hammer"></i> Create Work Order
            </button>
            <span class="text-muted ms-2">
                You can select between 1 and 20 devices.
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead class="table-light">
                <tr>
                    <th style="width:30px;">
                        <input type="checkbox" id="chk_all" onclick="toggleAll(this)">
                    </th>
                    <th>ID</th>
                    <th>Serial</th>
                    <th>Product</th>
                    <th>BIN</th>
                    <th>Defect Code</th>
                    <th>Status</th>
                    <th>Process Area</th>
                    <th>Invoice</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   name="selected_defectives[]"
                                   value="<?php echo (int)$r['id']; ?>">
                        </td>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><?php echo h($r['serial_number']); ?></td>
                        <td><?php echo h($r['product_id']); ?></td>
                        <td><?php echo h($r['bin_location']); ?></td>
                        <td><?php echo h($r['defect_code']); ?></td>
                        <td><?php echo badgeStatus($r['status']); ?></td>
                        <td><?php echo badgeArea($r['process_area']); ?></td>
                        <td><?php echo h($r['invoice_number']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php endif; ?>

</div>

<script>
function toggleAll(master) {
    const checks = document.querySelectorAll('input[name="selected_defectives[]"]');
    checks.forEach(ch => ch.checked = master.checked);
}
</script>

<?php include '../includes/footer.php'; ?>
