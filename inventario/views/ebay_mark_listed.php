<?php
// /inventario/views/ebay_mark_listed.php
// Marks a listing as LISTED and stores ebay_item_id + ebay_listing_url.

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
$ebayItemId  = trim($_POST['ebay_item_id'] ?? '');
$ebayUrl     = trim($_POST['ebay_listing_url'] ?? '');

if ($inventoryId <= 0) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("Invalid inventory id."));
  exit;
}

if ($ebayItemId === '' && $ebayUrl === '') {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("Please provide eBay Item ID or Listing URL."));
  exit;
}

// Basic safety (avoid super long junk)
if (strlen($ebayItemId) > 80)  $ebayItemId = substr($ebayItemId, 0, 80);
if (strlen($ebayUrl) > 255)    $ebayUrl = substr($ebayUrl, 0, 255);

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) $userId = 0;

// Listing must exist
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

// Update listing to LISTED
$upd = $conn->prepare("
  UPDATE ebay_listings
  SET
    status='LISTED',
    ebay_item_id = CASE WHEN ? <> '' THEN ? ELSE ebay_item_id END,
    ebay_listing_url = CASE WHEN ? <> '' THEN ? ELSE ebay_listing_url END,
    listed_at = COALESCE(listed_at, CURRENT_TIMESTAMP),
    updated_by = ?,
    updated_at = CURRENT_TIMESTAMP
  WHERE id = ?
  LIMIT 1
");
$upd->bind_param("ssssii", $ebayItemId, $ebayItemId, $ebayUrl, $ebayUrl, $userId, $listingId);
$upd->execute();
$upd->close();

header("Location: /inventario/views/ebay_queue.php");
exit;
