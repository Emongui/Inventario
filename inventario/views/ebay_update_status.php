<?php
// /inventario/views/ebay_update_status.php

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

$id = (int)($_POST['id'] ?? 0);          // defectives_inventory.id
$status = trim($_POST['status'] ?? '');  // target ebay_listings.status

$allowed = ['QUEUE','PREP','PHOTO_PENDING','READY_TO_LIST','LISTED','SOLD','CANCELLED','ERROR'];
if ($id <= 0 || $status === '' || !in_array($status, $allowed, true)) {
  header("Location: /inventario/views/ebay_queue.php");
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) $userId = 0;

// If listing doesn't exist yet, force draft creation first (simple behavior)
$check = $conn->prepare("SELECT id FROM ebay_listings WHERE inventory_id = ? LIMIT 1");
$check->bind_param("i", $id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if (!$existing) {
  // No listing row -> create minimal row (needs template_id)
  $tpl = $conn->prepare("SELECT id FROM ebay_templates WHERE is_active=1 ORDER BY id ASC LIMIT 1");
  $tpl->execute();
  $trow = $tpl->get_result()->fetch_assoc();
  $tpl->close();
  $templateId = (int)($trow['id'] ?? 0);

  if ($templateId <= 0) {
    $tpl2 = $conn->prepare("SELECT id FROM ebay_templates ORDER BY id ASC LIMIT 1");
    $tpl2->execute();
    $trow2 = $tpl2->get_result()->fetch_assoc();
    $tpl2->close();
    $templateId = (int)($trow2['id'] ?? 0);
  }

  if ($templateId <= 0) {
    http_response_code(500);
    exit("No eBay templates found. Create one first.");
  }

  // Get serial from inventory
  $st = $conn->prepare("SELECT serial_number FROM defectives_inventory WHERE id=? LIMIT 1");
  $st->bind_param("i", $id);
  $st->execute();
  $inv = $st->get_result()->fetch_assoc();
  $st->close();

  $serial = (string)($inv['serial_number'] ?? '');

  $ins = $conn->prepare("
    INSERT INTO ebay_listings (inventory_id, serial, template_id, status, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
  ");
  $ins->bind_param("isisi", $id, $serial, $templateId, $status, $userId);
  $ins->execute();
  $ins->close();
} else {
  // Update status
  $upd = $conn->prepare("
    UPDATE ebay_listings
    SET status = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
    WHERE inventory_id = ?
    LIMIT 1
  ");
  $upd->bind_param("sii", $status, $userId, $id);
  $upd->execute();
  $upd->close();
}

header("Location: /inventario/views/ebay_queue.php");
exit;
