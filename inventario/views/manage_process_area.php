<?php
// manage_process_area.php
// Clasifica defectivos en: REPAIR, EBAY, PARTS, SCRAP, PRODUCTION
// y permite crear WO a partir de los seleccionados (REPAIR).

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
// Roles permitidos
// ---------------------------------------------------------------------
$role = $_SESSION['role'] ?? 'viewer';
$allowed = ['admin','supervisor','inventory'];
if (!in_array($role, $allowed, true)) {
    echo "<div class='alert alert-danger mt-4'>You do not have permission to manage process areas.</div>";
    include '../includes/footer.php';
    exit;
}

$successMsg = '';
$errorMsg   = '';

// ---------------------------------------------------------------------
// ACCIÓN: actualizar process_area en defectives_inventory
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'update_area') {

    $selected_ids = $_POST['selected_ids'] ?? [];
    $new_area     = trim($_POST['new_process_area'] ?? '');

    if (empty($selected_ids) || $new_area === '') {
        $errorMsg = 'Please select at least one device and a destination area.';
    } else {
        // status según área
        switch ($new_area) {
            case 'REPAIR':
                $new_status = 'In Repair';
                break;
            case 'SCRAP':
                $new_status = 'Scrap';
                break;
            default:
                // EBAY / PARTS / PRODUCTION etc.
                $new_status = 'In Stock';
                break;
        }

        try {
            $conn->begin_transaction();

            $sql = "
                UPDATE defectives_inventory
                SET process_area = ?, status = ?, last_updated = NOW()
                WHERE id = ?
            ";
            $stmt = $conn->prepare($sql);

            foreach ($selected_ids as $id) {
                $id = (int)$id;
                $stmt->bind_param('ssi', $new_area, $new_status, $id);
                $stmt->execute();
            }

            $stmt->close();
            $conn->commit();
            $successMsg = 'Process area updated successfully for '.count($selected_ids).' device(s).';

        } catch (Exception $e) {
            $conn->rollback();
            $errorMsg = 'Error updating process area: '.$e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------
// FILTROS (GET)
// ---------------------------------------------------------------------
$status_filter       = trim($_GET['status']        ?? '');
$process_filter      = trim($_GET['process_area']  ?? '');
$category_filter     = trim($_GET['category']      ?? '');
$defect_filter       = trim($_GET['defect_code']   ?? '');
$bin_filter          = trim($_GET['bin']           ?? '');
$search              = trim($_GET['search']        ?? '');

// ---------------------------------------------------------------------
// Cargar listas para combos de filtro (distincts)
// ---------------------------------------------------------------------
$statuses      = [];
$process_areas = [];
$categories    = [];
$defect_codes  = [];
$bins          = [];

// status
$res = $conn->query("SELECT DISTINCT status FROM defectives_inventory ORDER BY status");
while ($r = $res->fetch_assoc()) {
    if ($r['status'] !== null && $r['status'] !== '') {
        $statuses[] = $r['status'];
    }
}
$res->close();

// process_area
$res = $conn->query("SELECT DISTINCT process_area FROM defectives_inventory ORDER BY process_area");
while ($r = $res->fetch_assoc()) {
    if ($r['process_area'] !== null && $r['process_area'] !== '') {
        $process_areas[] = $r['process_area'];
    }
}
$res->close();

// category
$res = $conn->query("SELECT DISTINCT category FROM defectives_inventory ORDER BY category");
while ($r = $res->fetch_assoc()) {
    if ($r['category'] !== null && $r['category'] !== '') {
        $categories[] = $r['category'];
    }
}
$res->close();

// defect_codes
$res = $conn->query("
    SELECT DISTINCT d.defect_code
    FROM defectives_inventory di
    JOIN defect_codes d ON di.defect_code_id = d.id
    ORDER BY d.defect_code
");
while ($r = $res->fetch_assoc()) {
    if ($r['defect_code'] !== null && $r['defect_code'] !== '') {
        $defect_codes[] = $r['defect_code'];
    }
}
$res->close();

// bins
$res = $conn->query("SELECT DISTINCT bin_location FROM defectives_inventory ORDER BY bin_location");
while ($r = $res->fetch_assoc()) {
    if ($r['bin_location'] !== null && $r['bin_location'] !== '') {
        $bins[] = $r['bin_location'];
    }
}
$res->close();

// ---------------------------------------------------------------------
// Construir query principal con filtros
// ---------------------------------------------------------------------
$conditions = [];
$params     = [];
$types      = '';

if ($status_filter !== '') {
    $conditions[] = 'di.status = ?';
    $params[]     = $status_filter;
    $types       .= 's';
}

if ($process_filter !== '') {
    $conditions[] = 'di.process_area = ?';
    $params[]     = $process_filter;
    $types       .= 's';
}

if ($category_filter !== '') {
    $conditions[] = 'di.category = ?';
    $params[]     = $category_filter;
    $types       .= 's';
}

if ($defect_filter !== '') {
    $conditions[] = 'd.defect_code = ?';
    $params[]     = $defect_filter;
    $types       .= 's';
}

if ($bin_filter !== '') {
    $conditions[] = 'di.bin_location = ?';
    $params[]     = $bin_filter;
    $types       .= 's';
}

if ($search !== '') {
    $conditions[] = '(di.serial_number LIKE ? 
                     OR di.invoice_number LIKE ? 
                     OR p.product_id LIKE ?)';

    $like = '%'.$search.'%';

    $params[] = $like; // serial
    $params[] = $like; // invoice
    $params[] = $like; // model/product
    $types   .= 'sss';
}


$sql = "
    SELECT
        di.id,
        di.serial_number,
        di.category,
        di.grade,
        di.status,
        di.process_area,
        di.invoice_number,
        di.bin_location,
        p.product_id,
        d.defect_code
    FROM defectives_inventory di
    LEFT JOIN product_ids  p ON di.product_id = p.id
    LEFT JOIN defect_codes d ON di.defect_code_id = d.id
";

if (!empty($conditions)) {
    $sql .= ' WHERE '.implode(' AND ', $conditions);
}

$sql .= ' ORDER BY di.id DESC LIMIT 200';

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$result->close();

// ---------------------------------------------------------------------
// Helper badge
// ---------------------------------------------------------------------
function badge($text, $map) {
    $color = $map[$text] ?? 'secondary';
    return "<span class='badge bg-{$color}'>".h($text)."</span>";
}

$statusColors = [
    'In Stock'  => 'secondary',
    'In Repair' => 'warning',
    'Scrap'     => 'danger',
    'Sold'      => 'success'
];

$processColors = [
    'REPAIR'      => 'warning',
    'EBAY'        => 'primary',
    'PARTS'       => 'dark',
    'SCRAP'       => 'danger',
    'PRODUCTION'  => 'success'
];

?>

<div class="container mt-3">

    <h3 class="mb-3">
        <i class="bi bi-diagram-3"></i>
        Manage Process Area
    </h3>

    <p class="text-muted">
        Use this screen to classify defective devices into <strong>REPAIR, EBAY, PARTS, SCRAP, PRODUCTION</strong>.
        From here you can also create a Work Order with the selected devices in <strong>REPAIR</strong>.
    </p>

    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo h($successMsg); ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?php echo h($errorMsg); ?></div>
    <?php endif; ?>

    <!-- FILTROS -->
    <form class="card card-body mb-3" method="get" action="manage_process_area.php">
        <div class="row g-2">

            <div class="col-md-2">
                <label class="form-label mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?php echo h($st); ?>" <?php if ($status_filter === $st) echo 'selected'; ?>>
                            <?php echo h($st); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">Process Area</label>
                <select name="process_area" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($process_areas as $pa): ?>
                        <option value="<?php echo h($pa); ?>" <?php if ($process_filter === $pa) echo 'selected'; ?>>
                            <?php echo h($pa); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo h($cat); ?>" <?php if ($category_filter === $cat) echo 'selected'; ?>>
                            <?php echo h($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">Defect</label>
                <select name="defect_code" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($defect_codes as $dc): ?>
                        <option value="<?php echo h($dc); ?>" <?php if ($defect_filter === $dc) echo 'selected'; ?>>
                            <?php echo h($dc); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">BIN</label>
                <select name="bin" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($bins as $b): ?>
                        <option value="<?php echo h($b); ?>" <?php if ($bin_filter === $b) echo 'selected'; ?>>
                            <?php echo h($b); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control form-control-sm"
                    placeholder="Serial / Invoice"
                    value="<?php echo h($search); ?>"
                >
            </div>

            <div class="col-12 mt-2 d-flex justify-content-end">
                <button class="btn btn-sm btn-primary me-2">
                    <i class="bi bi-funnel"></i> Apply Filters
                </button>
                <a href="manage_process_area.php" class="btn btn-sm btn-outline-secondary">
                    Clear
                </a>
            </div>
        </div>
    </form>

    <!-- FORM PRINCIPAL PARA ACTUALIZAR AREA -->
    <form id="bulkForm" method="post" action="manage_process_area.php">

        <input type="hidden" name="bulk_action" value="update_area">

        <div class="d-flex justify-content-between align-items-center mb-2">

            <div>
                <label class="me-2">Set Process Area for selected:</label>
                <select name="new_process_area" class="form-select form-select-sm d-inline-block w-auto">
                    <option value="">-- Select --</option>
                    <option value="REPAIR">REPAIR</option>
                    <option value="PRODUCTION">PRODUCTION</option>
                    <option value="EBAY">EBAY</option>
                    <option value="PARTS">PARTS</option>
                    <option value="SCRAP">SCRAP</option>
                </select>

                <button type="submit" class="btn btn-sm btn-success ms-2">
                    <i class="bi bi-save"></i> Update Area
                </button>
            </div>

            <div>
                <button type="button" class="btn btn-sm btn-primary" onclick="createWorkOrderFromSelection()">
                    <i class="bi bi-hammer"></i> Create Work Order (REPAIR)
                </button>
            </div>
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
                        <th>Category</th>
                        <th>Grade</th>
                        <th>Defect</th>
                        <th>Status</th>
                        <th>Process Area</th>
                        <th>Invoice</th>
                        <th>BIN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-3">No records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="selected_ids[]"
                                        value="<?php echo (int)$r['id']; ?>"
                                    >
                                </td>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td><?php echo h($r['serial_number']); ?></td>
                                <td><?php echo h($r['product_id']); ?></td>
                                <td><?php echo h($r['category']); ?></td>
                                <td><?php echo h($r['grade']); ?></td>
                                <td><?php echo h($r['defect_code']); ?></td>
                                <td><?php echo badge($r['status'], $statusColors); ?></td>
                                <td><?php echo $r['process_area'] ? badge($r['process_area'], $processColors) : '<span class="text-muted">Unassigned</span>'; ?></td>
                                <td><?php echo h($r['invoice_number']); ?></td>
                                <td><?php echo h($r['bin_location']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <!-- Form oculto para mandar a create_work_order.php -->
    <form id="woForm" method="post" action="create_work_order.php" style="display:none;">
        <input type="hidden" name="step" value="select_devices">
        <!-- aquí JS va a inyectar selected_ids[] -->
    </form>

</div>

<script>
// Seleccionar / deseleccionar todos
function toggleAll(master) {
    const checks = document.querySelectorAll('input[name="selected_ids[]"]');
    checks.forEach(ch => ch.checked = master.checked);
}

// Crear WO desde selección
function createWorkOrderFromSelection() {
    const selected = Array.from(document.querySelectorAll('input[name="selected_ids[]"]:checked'))
        .map(ch => ch.value);

    if (selected.length === 0) {
        alert('Please select at least one device.');
        return;
    }

    // Opcional: podrías filtrar aquí para que solo permita crear WO con equipos REPAIR
    // pero por ahora lo dejamos libre y la lógica de negocio la manejas tú.

    const form = document.getElementById('woForm');

    // Limpiar inputs previos
    while (form.elements.length > 1) { // deja el input "step"
        form.removeChild(form.lastChild);
    }

    selected.forEach(id => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'selected_ids[]';
        inp.value = id;
        form.appendChild(inp);
    });

    form.submit();
}
</script>

<?php include '../includes/footer.php'; ?>
