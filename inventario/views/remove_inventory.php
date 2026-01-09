
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

$msg = "";

if (isset($_POST['remove']) && !empty($_POST['serial_number']) && !empty($_POST['removal_destination'])) {
    $serial_number = trim($_POST['serial_number']);
    $destination = trim($_POST['removal_destination']);
    $removal_status = 'Removed';
    $removal_date = date('Y-m-d');

    // Verificar si el equipo existe
    $check = $conn->prepare("SELECT id FROM defectives_inventory WHERE serial_number = ?");
    $check->bind_param("s", $serial_number);
    $check->execute();
    $check_result = $check->get_result();

    if ($check_result->num_rows > 0) {
        // Actualizar el equipo
        $update = $conn->prepare("UPDATE defectives_inventory SET removal_status = ?, removal_destination = ?, removal_date = ? WHERE serial_number = ?");
        $update->bind_param("ssss", $removal_status, $destination, $removal_date, $serial_number);

        if ($update->execute()) {
            $msg = "<div class='alert alert-success'>Equipo marcado como removido a <strong>$destination</strong>.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error al actualizar: " . $update->error . "</div>";
        }
    } else {
        $msg = "<div class='alert alert-warning'>Serial no encontrado en el inventario.</div>";
    }
}
?>

<div class="container mt-5">
    <h2>Remover equipo defectuoso</h2>
    <?= $msg ?>
    <form method="POST">
        <div class="mb-3">
            <label for="serial_number" class="form-label">Serial Number</label>
            <input type="text" name="serial_number" class="form-control" placeholder="Ingresa el número de serie" required>
        </div>
        <div class="mb-3">
            <label for="removal_destination" class="form-label">Destino final del equipo</label>
            <select name="removal_destination" class="form-control" required>
                <option value="">Seleccione destino</option>
                <option value="Parts">Parts</option>
                <option value="eBay">eBay</option>
                <option value="Scrap">Scrap</option>
                <option value="Production">Production</option>
            </select>
        </div>
        <button type="submit" name="remove" class="btn btn-warning">Confirmar Remoción</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>
