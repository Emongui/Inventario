<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

function h($v): string {
  return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role'] ?? 'viewer';
$canView = in_array($role, ['admin','inventory_manager','supervisor','technician'], true);
if (!$canView) {
  echo "<div class='container mt-4'><div class='alert alert-danger'>Access denied.</div></div>";
  include '../includes/footer.php';
  exit;
}

$q = trim((string)($_GET['q'] ?? ''));

$sql = "
  SELECT
    p.id,
    p.part_name,
    p.part_code,
    COALESCE(ps.qty_on_hand, 0) AS qty_on_hand
  FROM parts_catalog p
  LEFT JOIN parts_stock ps ON ps.part_id = p.id
";

$params = [];
$types = "";
$where = "";

if ($q !== '') {
  $where = " WHERE (p.part_name LIKE ? OR p.part_code LIKE ?) ";
  $like = "%{$q}%";
  $params = [$like, $like];
  $types = "ss";
}

$sql .= $where . " ORDER BY p.part_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-box-seam"></i> Parts Inventory</h3>
    <a class="btn btn-outline-secondary" href="harvest_report.php"><i class="bi bi-graph-up"></i> Harvest Report</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-6">
      <input class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="Search part name or code...">
    </div>
    <div class="col-md-auto">
      <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
      <a class="btn btn-outline-secondary" href="parts_inventory.php">Reset</a>
    </div>
  </form>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:80px;">ID</th>
              <th>Part</th>
              <th style="width:160px;">Code</th>
              <th style="width:140px;">Qty On Hand</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="4" class="p-3 text-muted">No parts found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><?php echo h($r['part_name']); ?></td>
                  <td><code><?php echo h($r['part_code']); ?></code></td>
                  <td><strong><?php echo (int)$r['qty_on_hand']; ?></strong></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="small text-muted mt-2">
    Note: This view is read-only. Stock increases automatically through Harvest Finalize.
  </div>
</div>

<?php include '../includes/footer.php'; ?>
