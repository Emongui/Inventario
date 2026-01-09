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

// Filters
$wo = trim((string)($_GET['wo'] ?? ''));
$part = trim((string)($_GET['part'] ?? ''));

// Summary by part
$sql1 = "
  SELECT
    p.part_name,
    p.part_code,
    SUM(hl.qty) AS total_qty
  FROM harvest_event_lines hl
  JOIN harvest_events he ON he.id = hl.harvest_event_id
  JOIN parts_catalog p ON p.id = hl.part_id
";
$w = [];
$params = [];
$types = "";

if ($wo !== '') {
  $w[] = "he.wo_id = ?";
  $params[] = (int)$wo;
  $types .= "i";
}
if ($part !== '') {
  $w[] = "(p.part_name LIKE ? OR p.part_code LIKE ?)";
  $like = "%{$part}%";
  $params[] = $like;
  $params[] = $like;
  $types .= "ss";
}
if ($w) $sql1 .= " WHERE " . implode(" AND ", $w);
$sql1 .= " GROUP BY p.id ORDER BY total_qty DESC, p.part_name ASC";

$stmt = $conn->prepare($sql1);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$byPart = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary by WO
$sql2 = "
  SELECT
    he.wo_id,
    w.wo_number,
    COUNT(DISTINCT he.id) AS harvest_events,
    SUM(hl.qty) AS total_parts_qty
  FROM harvest_events he
  JOIN harvest_event_lines hl ON hl.harvest_event_id = he.id
  LEFT JOIN work_orders w ON w.id = he.wo_id
";
$w2 = [];
$params2 = [];
$types2 = "";

if ($wo !== '') {
  $w2[] = "he.wo_id = ?";
  $params2[] = (int)$wo;
  $types2 .= "i";
}
if ($w2) $sql2 .= " WHERE " . implode(" AND ", $w2);
$sql2 .= " GROUP BY he.wo_id ORDER BY he.wo_id DESC";

$stmt = $conn->prepare($sql2);
if ($types2 !== "") $stmt->bind_param($types2, ...$params2);
$stmt->execute();
$byWo = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-graph-up"></i> Harvest Report</h3>
    <a class="btn btn-outline-secondary" href="parts_inventory.php"><i class="bi bi-box-seam"></i> Parts Inventory</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
      <input class="form-control" name="wo" value="<?php echo h($wo); ?>" placeholder="Filter WO ID (e.g. 123)">
    </div>
    <div class="col-md-5">
      <input class="form-control" name="part" value="<?php echo h($part); ?>" placeholder="Filter part name or code...">
    </div>
    <div class="col-md-auto">
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Apply</button>
      <a class="btn btn-outline-secondary" href="harvest_report.php">Reset</a>
    </div>
  </form>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><strong>By Work Order</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>WO</th>
                  <th>Events</th>
                  <th>Total Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($byWo)): ?>
                  <tr><td colspan="3" class="p-3 text-muted">No harvest data.</td></tr>
                <?php else: ?>
                  <?php foreach ($byWo as $r): ?>
                    <tr>
                      <td>
                        <a href="work_order_view.php?id=<?php echo (int)$r['wo_id']; ?>">
                          <?php echo h($r['wo_number'] ?? ('WO#'.$r['wo_id'])); ?>
                        </a>
                      </td>
                      <td><?php echo (int)$r['harvest_events']; ?></td>
                      <td><strong><?php echo (int)$r['total_parts_qty']; ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><strong>By Part (Total Qty)</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>Part</th>
                  <th style="width:160px;">Code</th>
                  <th style="width:120px;">Total Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($byPart)): ?>
                  <tr><td colspan="3" class="p-3 text-muted">No harvest data.</td></tr>
                <?php else: ?>
                  <?php foreach ($byPart as $r): ?>
                    <tr>
                      <td><?php echo h($r['part_name']); ?></td>
                      <td><code><?php echo h($r['part_code']); ?></code></td>
                      <td><strong><?php echo (int)$r['total_qty']; ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="small text-muted mt-2">
    This report sums quantities recorded in harvest_event_lines.
  </div>
</div>

<?php include '../includes/footer.php'; ?>
