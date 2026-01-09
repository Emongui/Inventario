<?php
// /inventario/views/ebay_template.php
// Shows final Title + HTML Description stored in ebay_listings for copy/paste.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
  return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role'] ?? 'viewer';
if (!in_array($role, ['admin','inventory','inventory_manager','supervisor'], true)) {
  http_response_code(403);
  exit('Access denied');
}

$inventoryId = (int)($_GET['id'] ?? 0);
if ($inventoryId <= 0) exit('Invalid id');

$sql = "
  SELECT
    el.title,
    el.description_html,
    el.status,
    el.price,
    d.serial_number
  FROM defectives_inventory d
  LEFT JOIN ebay_listings el ON el.inventory_id = d.id
  WHERE d.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventoryId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$title = $row['title'] ?? '';
$desc  = $row['description_html'] ?? '';
$status = $row['status'] ?? '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>eBay Template</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="m-0">eBay Template</h3>
    <div class="text-muted small">Status: <?= h($status) ?></div>
  </div>

  <?php if (trim($title) === '' && trim($desc) === ''): ?>
    <div class="alert alert-warning">
      No listing content yet. Go back and click <b>Create Draft</b> first.
    </div>
  <?php endif; ?>

  <div class="mb-2"><b>Title</b></div>
  <textarea class="form-control mb-3" rows="2"><?= h($title) ?></textarea>

  <div class="mb-2"><b>Description (HTML)</b></div>
  <textarea class="form-control mb-3" rows="14"><?= h($desc) ?></textarea>

  <div class="mb-2"><b>Preview</b></div>
  <div class="border rounded p-3 bg-light">
    <?= $desc ?>
  </div>

  <div class="text-muted mt-3 small">
    Tip: Click inside the box → Ctrl+A → Ctrl+C
  </div>

</body>
</html>
