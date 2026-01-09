<?php
// /inventario/views/ebay_mark_ready.php
// Validates: listing exists + template/title/description + >= 7 photos (counted by listing_id)

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
if ($inventoryId <= 0) {
  header("Location: /inventario/views/ebay_queue.php");
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) $userId = 0;

// Listing must exist
$st = $conn->prepare("
  SELECT id, title, description_html, template_id, price
  FROM ebay_listings
  WHERE inventory_id = ?
  LIMIT 1
");
$st->bind_param("i", $inventoryId);
$st->execute();
$el = $st->get_result()->fetch_assoc();
$st->close();

if (!$el) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("No listing found. Click Create Draft first."));
  exit;
}

$listingId = (int)$el['id'];

// Count photos by listing_id
$ps = $conn->prepare("SELECT COUNT(*) AS c FROM ebay_listing_photos WHERE listing_id=?");
$ps->bind_param("i", $listingId);
$ps->execute();
$crow = $ps->get_result()->fetch_assoc();
$ps->close();

$photos = (int)($crow['c'] ?? 0);

$errors = [];
if ((int)($el['template_id'] ?? 0) <= 0) $errors[] = "Missing template.";
if (trim((string)($el['title'] ?? '')) === '') $errors[] = "Missing title.";
if (trim((string)($el['description_html'] ?? '')) === '') $errors[] = "Missing description.";
if ($photos < 7) $errors[] = "Need at least 7 photos (current: $photos).";

// Optional: require price
// if ($el['price'] === null || (float)$el['price'] <= 0) $errors[] = "Missing price.";

if ($errors) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode(implode(' ', $errors)));
  exit;
}

// Mark READY_TO_LIST
$upd = $conn->prepare("
  UPDATE ebay_listings
  SET status='READY_TO_LIST', updated_by=?, updated_at=CURRENT_TIMESTAMP
  WHERE id=? LIMIT 1
");
$upd->bind_param("ii", $userId, $listingId);
$upd->execute();
$upd->close();

header("Location: /inventario/views/ebay_queue.php");
exit;
