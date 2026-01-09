<?php
// create_work_order.php
// Step 1: recibe selected_defectives / selected_ids desde defectives_list.php o manage_process_area.php
// Step 2: muestra formulario para crear la WO
// Step 3: en POST (step = save_wo) crea work_orders + work_order_items.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// SESSION / ROLE
// ---------------------------------------------------------------------
$session_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$session_role    = $_SESSION['role'] ?? 'viewer';

// Solo estos roles pueden crear WO
$allowed_roles = ['admin', 'supervisor'];
if (!in_array($session_role, $allowed_roles, true)) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger mt-4">You do not have permission to create work orders.</div>';
    include '../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// STEP
// ---------------------------------------------------------------------
$step = $_POST['step'] ?? $_GET['step'] ?? 'select_devices';

// ---------------------------------------------------------------------
// GUARDAR WORK ORDER (step = save_wo)
//   IMPORTANTE: aquí NO incluimos header.php ni imprimimos nada
//   antes del header("Location...") para evitar el warning.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($step === 'save_wo')) {

    $title        = trim($_POST['title'] ?? '');
    $priority_raw = strtolower($_POST['priority'] ?? 'medium');

    // Mapear prioridad texto -> entero
    switch ($priority_raw) {
        case 'low':
            $priority = 0;
            break;
        case 'high':
            $priority = 2;
            break;
        case 'medium':
        default:
            $priority = 1;
            break;
    }

    $assigned_to = (int)($_POST['assigned_to'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $created_by  = $session_user_id > 0 ? $session_user_id : 1;

    $selected_defectives = $_POST['selected_defectives'] ?? [];

    // Validaciones básicas – si fallan, mostramos texto simple y salimos.
    if ($title === '' || $assigned_to <= 0 || empty($selected_defectives)) {
        include '../includes/header.php';
        echo '<div class="alert alert-danger mt-4">Missing required fields or no devices selected.</div>';
        include '../includes/footer.php';
        exit;
    }
/*
    if (count($selected_defectives) > 20) {
        include '../includes/header.php';
        echo '<div class="alert alert-warning mt-4">You can only assign up to 20 devices per work order.</div>';
        include '../includes/footer.php';
        exit;
    }*/

    // Validar que ningún equipo esté ya en una WO activa
    $placeholders = implode(',', array_fill(0, count($selected_defectives), '?'));
    $types        = str_repeat('i', count($selected_defectives));

    $sql_check = "
        SELECT wi.defective_id, wo.wo_number, wo.status
        FROM work_order_items wi
        JOIN work_orders wo ON wi.work_order_id = wo.id
        WHERE wi.defective_id IN ($placeholders)
          AND wo.status IN ('Pending', 'In Progress')
    ";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param($types, ...$selected_defectives);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    $already_in_wo = [];
    while ($row = $result_check->fetch_assoc()) {
        $already_in_wo[] = $row;
    }
    $stmt_check->close();

    if (!empty($already_in_wo)) {
        include '../includes/header.php';
        echo '<div class="alert alert-danger mt-4">';
        echo '<h5 class="mb-2">Some devices already belong to an active Work Order:</h5>';
        echo '<ul class="mb-2">';
        foreach ($already_in_wo as $item) {
            echo '<li>Defective ID: ' . h($item['defective_id']) .
                 ' — WO: ' . h($item['wo_number']) .
                 ' (Status: ' . h($item['status']) . ')</li>';
        }
        echo '</ul>';
        echo '<p class="mb-0">Please remove those devices from your selection.</p>';
        echo '</div>';
        include '../includes/footer.php';
        exit;
    }

    // -----------------------------------------------------------------
    // Crear WO + items + auto-flow -> REPAIR
    // -----------------------------------------------------------------
    $conn->begin_transaction();
    try {
        $wo_number = 'WO-' . date('Ymd-His');

        $sql = "
            INSERT INTO work_orders
                (wo_number, title, created_by, assigned_to, priority, status, notes, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, 'Pending', ?, NOW(), NOW())
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'ssiiis',
            $wo_number,
            $title,
            $created_by,
            $assigned_to,
            $priority,
            $notes
        );
        $stmt->execute();
        $work_order_id = $stmt->insert_id;
        $stmt->close();

        // Insert items
        $sqlItem = "
            INSERT INTO work_order_items
                (work_order_id, defective_id, defect_code_id, status, estimated_cost, final_cost, notes, created_at, updated_at)
            SELECT ?, di.id, di.defect_code_id, 'Pending', 0, 0, '', NOW(), NOW()
            FROM defectives_inventory di
            WHERE di.id = ?
        ";
        $stmt_item = $conn->prepare($sqlItem);

        // Auto-flow: cada equipo pasa a In Repair + REPAIR
        $sqlUpdate = "
            UPDATE defectives_inventory
            SET status = 'In Repair',
                process_area = 'REPAIR',
                last_updated = NOW()
            WHERE id = ?
            LIMIT 1
        ";
        $stmt_update = $conn->prepare($sqlUpdate);

        foreach ($selected_defectives as $def_id) {
            $def_id = (int)$def_id;

            $stmt_item->bind_param('ii', $work_order_id, $def_id);
            $stmt_item->execute();

            $stmt_update->bind_param('i', $def_id);
            $stmt_update->execute();
        }

        $stmt_item->close();
        $stmt_update->close();

        $conn->commit();

        // 🚨 Aquí aún no se ha enviado HTML, el redirect es seguro
        header("Location: work_order_view.php?id=" . $work_order_id);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        include '../includes/header.php>';
        echo '<div class="alert alert-danger mt-4">Error creating Work Order: ' . h($e->getMessage()) . '</div>';
        include '../includes/footer.php';
        exit;
    }
}

// ---------------------------------------------------------------------
// STEP = select_devices  (viene de defectives_list.php o manage_process_area.php)
//   Aquí sí incluimos header/footer porque solo mostramos HTML.
// ---------------------------------------------------------------------
if ($step === 'select_devices' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // ✅ Aceptar tanto selected_defectives[] como selected_ids[]
    $selected_defectives = $_POST['selected_defectives'] ?? $_POST['selected_ids'] ?? [];

    if (empty($selected_defectives)) {
        include '../includes/header.php';
        echo '<div class="alert alert-warning mt-4">No devices selected.</div>';
        include '../includes/footer.php';
        exit;
    }
    /*
    if (count($selected_defectives) > 20) {
        include '../includes/header.php';
        echo '<div class="alert alert-warning mt-4">You selected more than 20 devices. Please go back and select up to 20.</div>';
        include '../includes/footer.php';
        exit;
    }
    */
    // Técnicos (usuarios habilitados)
    $techs = [];
    $sql_techs = "
        SELECT id, username
        FROM users
        WHERE role IN ('technician','admin','supervisor')
        ORDER BY username
    ";
    $result_techs = $conn->query($sql_techs);
    while ($row = $result_techs->fetch_assoc()) {
        $techs[] = $row;
    }
    $result_techs->close();

    // Info de los defectives seleccionados (preview)
    $devices      = [];
    $placeholders = implode(',', array_fill(0, count($selected_defectives), '?'));
    $types        = str_repeat('i', count($selected_defectives));

    $sql_preview = "
        SELECT
            di.id,
            di.serial_number,
            di.category,
            di.grade,
            di.status,
            di.invoice_number,
            di.bin_location,
            p.product_id,
            d.defect_code
        FROM defectives_inventory di
        LEFT JOIN product_ids  p ON di.product_id = p.id
        LEFT JOIN defect_codes d ON di.defect_code_id = d.id
        WHERE di.id IN ($placeholders)
        ORDER BY di.id ASC
    ";
    $stmt_preview = $conn->prepare($sql_preview);
    $stmt_preview->bind_param($types, ...$selected_defectives);
    $stmt_preview->execute();
    $result_preview = $stmt_preview->get_result();
    while ($r = $result_preview->fetch_assoc()) {
        $devices[] = $r;
    }
    $stmt_preview->close();

    include '../includes/header.php';
    ?>

    <div class="mb-3">
        <h2 class="mb-1">
            <i class="bi bi-hammer"></i> Create Work Order
        </h2>
        <p class="text-muted mb-0">
            You are creating a Work Order for the selected devices below.
        </p>
    </div>

    <form method="post" action="create_work_order.php">
        <input type="hidden" name="step" value="save_wo">

        <?php foreach ($selected_defectives as $idf): ?>
            <input type="hidden" name="selected_defectives[]" value="<?php echo (int)$idf; ?>">
        <?php endforeach; ?>

        <!-- CARD: Work Order details -->
        <div class="card mb-4">
            <div class="card-header">
                Work Order Details
            </div>
            <div class="card-body row g-3">

                <div class="col-md-6">
                    <label class="form-label">Work Order Title</label>
                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        placeholder="e.g. Batch repair from BIN1000"
                        required
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label">Assign to Technician</label>
                    <select name="assigned_to" class="form-select" required>
                        <option value="">-- Select Technician --</option>
                        <?php foreach ($techs as $tech): ?>
                            <option value="<?php echo (int)$tech['id']; ?>">
                                <?php echo h($tech['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea
                        name="notes"
                        class="form-control"
                        rows="3"
                        placeholder="General notes about this Work Order..."
                    ></textarea>
                </div>
            </div>
        </div>

        <!-- CARD: Selected Devices -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Selected Devices</span>
                <span class="badge bg-secondary">
                    <?php echo count($devices); ?> device(s)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Serial</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Grade</th>
                                <th>Defect</th>
                                <th>Status</th>
                                <th>Invoice</th>
                                <th>BIN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-3">
                                        No devices loaded.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $dev): ?>
                                    <tr>
                                        <td><?php echo (int)$dev['id']; ?></td>
                                        <td><?php echo h($dev['serial_number']); ?></td>
                                        <td><?php echo h($dev['product_id']); ?></td>
                                        <td><?php echo h($dev['category']); ?></td>
                                        <td><?php echo h($dev['grade']); ?></td>
                                        <td><?php echo h($dev['defect_code']); ?></td>
                                        <td><?php echo h($dev['status']); ?></td>
                                        <td><?php echo h($dev['invoice_number']); ?></td>
                                        <td><?php echo h($dev['bin_location']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="mb-5 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Save Work Order
            </button>
        </div>
    </form>

    <?php
    include '../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Si llegó aquí sin POST / step válido
// ---------------------------------------------------------------------
include '../includes/header.php';
echo '<div class="alert alert-warning mt-4">Invalid access to create_work_order.php. Please start from the Defectives list.</div>';
include '../includes/footer.php';
exit;
