<?php
// /inventario/views/ebay_create_draft.php
// Creates/updates an ebay_listings row for an inventory item using the active "Default" template.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function apply_placeholders(string $tpl, array $vars): string {
  foreach ($vars as $k => $v) {
    $tpl = str_replace('{'.$k.'}', (string)$v, $tpl);
  }
  return $tpl;
}

$role = $_SESSION['role'] ?? 'viewer';
if (!in_array($role, ['admin','inventory','inventory_manager','supervisor'], true)) {
  http_response_code(403);
  exit('Access denied');
}

$inventoryId = (int)($_POST['id'] ?? 0);
if ($inventoryId <= 0) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("Invalid inventory id."));
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) $userId = 0;

/* =========================
   1) Get active Default template
========================= */
$t = $conn->prepare("
  SELECT id, currency, title_pattern, description_html
  FROM ebay_templates
  WHERE is_active = 1
  ORDER BY id ASC
  LIMIT 1
");
$t->execute();
$template = $t->get_result()->fetch_assoc();
$t->close();

if (!$template) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("No eBay templates found. Please create at least one record in ebay_templates."));
  exit;
}

$templateId   = (int)$template['id'];
$templateCur  = (string)($template['currency'] ?? 'USD');
$titlePattern = (string)($template['title_pattern'] ?? '{SKU} | {CATEGORY} Grade {GRADE} | Serial {SERIAL}');
$descTpl      = (string)($template['description_html'] ?? '');

/* =========================
   2) Fetch inventory item info for placeholders
========================= */
$sql = "
  SELECT
    d.id,
    d.serial_number,
    d.category,
    d.grade,
    p.product_id AS sku,
    dc.defect_code
  FROM defectives_inventory d
  LEFT JOIN product_ids p ON p.id = d.product_id
  LEFT JOIN defect_codes dc ON dc.id = d.defect_code_id
  WHERE d.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventoryId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
  header("Location: /inventario/views/ebay_queue.php?err=" . urlencode("Inventory item not found."));
  exit;
}

$vars = [
  'SERIAL'   => (string)($item['serial_number'] ?? ''),
  'SKU'      => (string)($item['sku'] ?? ''),
  'CATEGORY' => (string)($item['category'] ?? ''),
  'GRADE'    => (string)($item['grade'] ?? ''),
  'DEFECT'   => (string)($item['defect_code'] ?? ''),
];

$title = trim(apply_placeholders($titlePattern, $vars));
if ($title === '') $title = 'CTI Listing';
if (strlen($title) > 80) $title = substr($title, 0, 80);

$descriptionHtml = apply_placeholders($descTpl, $vars);
if (trim($descriptionHtml) === '') {
  // Fallback
  $descriptionHtml = nl2br(
    "Item: {$vars['CATEGORY']}\n".
    "SKU: {$vars['SKU']}\n".
    "Serial: {$vars['SERIAL']}\n".
    "Grade: {$vars['GRADE']}\n".
    "Known issue(s): {$vars['DEFECT']}\n\n".
    "What you get:\n- Unit only (unless specified)\n\n".
    "Notes:\n- Listing created from CTI system. Photos pending.\n"
  );
}

/* =========================
   3) Upsert ebay_listings by inventory_id
   - template_id is REQUIRED (NOT NULL)
========================= */
$upsert = "
  INSERT INTO ebay_listings
    (inventory_id, serial, template_id, status, title, description_html, currency, quantity, created_by, created_at)
  VALUES
    (?, ?, ?, 'PREP', ?, ?, ?, 1, ?, CURRENT_TIMESTAMP)
  ON DUPLICATE KEY UPDATE
    serial = VALUES(serial),
    template_id = VALUES(template_id),
    currency = VALUES(currency),
    title = COALESCE(NULLIF(title,''), VALUES(title)),
    description_html = COALESCE(NULLIF(description_html,''), VALUES(description_html)),
    updated_by = VALUES(created_by),
    updated_at = CURRENT_TIMESTAMP
";

$serial = $vars['SERIAL'];
$stmt2 = $conn->prepare($upsert);
$stmt2->bind_param("isisssi", $inventoryId, $serial, $templateId, $title, $descriptionHtml, $templateCur, $userId);
$stmt2->execute();
$stmt2->close();

header("Location: /inventario/views/ebay_queue.php");
exit;
