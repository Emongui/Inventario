<?php
// /inventario/jobs/ebay_publisher.php
// Run via cron or manually: php /opt/lampp/htdocs/inventario/jobs/ebay_publisher.php

ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../includes/db_connection.php';

// 1) get ready listings
$q = $conn->prepare("
  SELECT id, inventory_id, template_id, title, description_html, price, currency, attempts
  FROM ebay_listings
  WHERE status='READY_TO_LIST'
  ORDER BY id ASC
  LIMIT 10
");
$q->execute();
$list = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

foreach ($list as $el) {
  $listingId = (int)$el['id'];

  // mark as LISTING (in progress)
  $u = $conn->prepare("UPDATE ebay_listings SET status='LISTING', updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
  $u->bind_param("i", $listingId);
  $u->execute();
  $u->close();

  try {
    // TODO: Here goes the real eBay API call:
    // - build payload using template_id, title, description_html, price/currency
    // - upload photos from ebay_listing_photos for inventory_id
    // - create listing, get ebay_item_id + listing_url
    //
    // If success:
    //   UPDATE ebay_listings SET status='LISTED', ebay_item_id=?, ebay_listing_url=?, submitted_at=NOW(), listed_at=NOW()
    //
    // For now, we simulate fail to show structure:
    throw new Exception("eBay API not configured yet (OAuth token missing).");
  } catch (Throwable $e) {
    $err = substr($e->getMessage(), 0, 2000);

    $upd = $conn->prepare("
      UPDATE ebay_listings
      SET status='ERROR',
          attempts = attempts + 1,
          last_error = ?,
          last_error_at = NOW(),
          updated_at = CURRENT_TIMESTAMP
      WHERE id=? LIMIT 1
    ");
    $upd->bind_param("si", $err, $listingId);
    $upd->execute();
    $upd->close();
  }
}

echo "Done. Processed ".count($list)." listings.\n";
