<?php
// views/view_bin.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$locationId = (int)($_GET['location_id'] ?? 0);
if ($locationId <= 0) {
    echo '<div class="alert alert-danger">Invalid BIN.</div>';
    include '../includes/footer.php';
    exit;
}

// BIN info
$stmt = $conn->prepare("SELECT location_name FROM locations WHERE id = ?");
$stmt->bind_param('i', $locationId);
$stmt->execute();
$bin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bin) {
    echo '<div class="alert alert-danger">BIN not found.</div>';
    include '../includes/footer.php';
    exit;
}

// Items in BIN
$sql = "
SELECT
    di.id,
    di.serial_number,
    di.status,
    di.process_area,
    p.product_id,
    p.description
FROM defectives_inventory di
LEFT JOIN product_ids p ON di.product_id = p.id
WHERE di.location_id = ?
  AND di.removal_status = 'In Stock'
ORDER BY p.product_id, di.serial_number
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $locationId);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
?>

<h2 class="mb-3">BIN: <?php echo h($bin['location_name']); ?></h2>

<div class="mb-3">
  <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">← Back to Dashboard</a>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong>Items in this BIN</strong>
    <span class="badge bg-primary"><?php echo count($items); ?> units</span>
  </div>

  <div class="card-body">
    <?php if (!empty($items)): ?>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Serial</th>
              <th>Product ID</th>
              <th>Description</th>
              <th>Status</th>
              <th>Process Area</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $i): ?>
              <tr>
                <td><?php echo h($i['serial_number']); ?></td>
                <td><?php echo h($i['product_id']); ?></td>
                <td><?php echo h($i['description']); ?></td>
                <td><?php echo h($i['status']); ?></td>
                <td><?php echo h($i['process_area']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="alert alert-warning mb-0">This BIN is empty.</div>
    <?php endif; ?>
  </div>
        <div class="mb-3 d-flex gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">← Back to Dashboard</a>
          <a href="manage_bins.php?location_id=<?php echo (int)$locationId; ?>"
             class="btn btn-sm btn-primary">
                Manage this BIN
            </a>
        </div>
</div>

<?php include '../includes/footer.php'; ?>
