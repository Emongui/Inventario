<?php
require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

$msg = "";
$record = null;

if (isset($_POST['search'])) {
    $serial_number = trim($_POST['serial_number']);
    
    $sql = "SELECT di.*, pi.product_id, dc.defect_code, dc.defect_description 
            FROM defectives_inventory di 
            LEFT JOIN product_ids pi ON di.product_id = pi.id
            LEFT JOIN defect_codes dc ON di.defect_code_id = dc.id
            WHERE di.serial_number = '$serial_number'";
    
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $record = $result->fetch_assoc();
    } else {
        $msg = "<div class='alert alert-warning'>No se encontró el serial ingresado.</div>";
    }
}
?>

<div class="card">
  <div class="card-header">Buscar Equipo por Serial Number</div>
  <div class="card-body">
    <?= $msg ?>
    <form method="post" class="mb-4">
      <div class="input-group">
        <input type="text" name="serial_number" class="form-control" placeholder="Ingrese Serial Number" required>
        <button type="submit" name="search" class="btn btn-primary">Buscar</button>
      </div>
    </form>

    <?php if ($record): ?>
    <table class="table table-bordered">
      <tr><th>ID</th><td><?= $record['id'] ?></td></tr>
      <tr><th>Serial Number</th><td><?= $record['serial_number'] ?></td></tr>
      <tr><th>Product ID</th><td><?= $record['product_id'] ?></td></tr>
      <tr><th>Categoría</th><td><?= $record['category'] ?></td></tr>
      <tr><th>Grade</th><td><?= $record['grade'] ?></td></tr>
      <tr><th>Defect Code</th><td><?= $record['defect_code'] ?></td></tr>
      <tr><th>Defect Description</th><td><?= $record['defect_description'] ?></td></tr>
      <tr><th>Bin Location</th><td><?= $record['bin_location'] ?></td></tr>
      <tr><th>Status</th><td><?= $record['status'] ?></td></tr>
      <tr><th>Removal Status</th><td><?= $record['removal_status'] ?></td></tr>
      <tr><th>Removal Destination</th><td><?= $record['removal_destination'] ?></td></tr>
      <tr><th>Removal Date</th><td><?= $record['removal_date'] ?></td></tr>
      <tr><th>Date Added</th><td><?= $record['date_added'] ?></td></tr>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
