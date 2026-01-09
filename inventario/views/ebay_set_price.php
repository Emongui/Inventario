<?php
// /inventario/views/ebay_set_price.php
// Saves a manual price into ebay_listings.price.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

$role = $_SESSION['role'] ?? 'viewer';
if (!in_array($role, ['admin','inventory','inventory_manager','supervisor'], true)) {
  http_response_code(403);
  exit('Access denied');
}

$inventoryId = (int)($_POST['id'] ?? 0);
$priceRaw    = trim($_POST['price'] ?? '');

if ($inventoryId <= 0) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("Invalid inventory id."));
  exit;
}

if ($priceRaw === '' || !is_numeric($priceRaw)) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("Invalid price."));
  exit;
}

$price = round((float)$priceRaw, 2);
if ($price < 0) $price = 0;

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) $userId = 0;

// Ensure listing exists
$st = $conn->prepare("SELECT id FROM ebay_listings WHERE inventory_id=? LIMIT 1");
$st->bind_param("i", $inventoryId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("No listing found. Click Create Draft first."));
  exit;
}

$listingId = (int)$row['id'];

$u = $conn->prepare("
  UPDATE ebay_listings
  SET price=?, updated_by=?, updated_at=CURRENT_TIMESTAMP
  WHERE id=? LIMIT 1
");
$u->bind_param("dii", $price, $userId, $listingId);
$u->execute();
$u->close();

header("Location: /inventario/views/ebay_queue.php");
exit;
