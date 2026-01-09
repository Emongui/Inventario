<?php
// close_work_order.php
// Finaliza una Work Order: actualiza defectives_inventory (BIN + stock) y registra inventory_movements.
// Luego muestra/imprime recibos por ruta (PRODUCTION/EBAY/PARTS/SCRAP) usando work_order_receipt.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';
include '../includes/header.php';

function h($v): string {
  return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['role'] ?? 'viewer';

$canClose = in_array($currentUserRole, ['admin','inventory_manager','supervisor','technician'], true);
if (!$canClose) {
  http_response_code(403);
  echo "<div class='container mt-4'><div class='alert alert-danger'>Access denied.</div></div>";
  include '../includes/footer.php';
  exit;
}

$woId = (int)($_POST['wo_id'] ?? 0);
if ($woId <= 0) {
  echo "<div class='container mt-4'><div class='alert alert-danger'>Missing Work Order ID.</div></div>";
  include '../includes/footer.php';
  exit;
}

$toLocationByItem   = $_POST['to_location_id'] ?? [];   // array[woi_id] => location_id
$harvestQtyByItem   = $_POST['harvest_qty'] ?? [];      // harvest_qty[woi_id][part_id]=qty
$harvestNotesByItem = $_POST['harvest_notes'] ?? [];    // harvest_notes[woi_id]=text

function getLocation(mysqli $conn, int $locationId): ?array {
  $stmt = $conn->prepare("SELECT id, location_name FROM locations WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $locationId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function getLocationIdByName(mysqli $conn, string $name): int {
  $stmt = $conn->prepare("SELECT id FROM locations WHERE location_name = ? LIMIT 1");
  $stmt->bind_param("s", $name);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['id'] ?? 0);
}

function logMovement(mysqli $conn, array $m): void {
  $stmt = $conn->prepare("
    INSERT INTO inventory_movements
      (defectives_id, serial_number, movement_type, from_location_id, to_location_id, from_status, to_status, reason, user_id)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param(
    "issiiissi",
    $m['defectives_id'],
    $m['serial_number'],
    $m['movement_type'],
    $m['from_location_id'],
    $m['to_location_id'],
    $m['from_status'],
    $m['to_status'],
    $m['reason'],
    $m['user_id']
  );
  $stmt->execute();
  $stmt->close();
}

function ensurePartStockRow(mysqli $conn, int $partId): void {
  $stmt = $conn->prepare("INSERT IGNORE INTO parts_stock (part_id, qty_on_hand) VALUES (?, 0)");
  $stmt->bind_param("i", $partId);
  $stmt->execute();
  $stmt->close();
}

function incrementPartStock(mysqli $conn, int $partId, int $qty): void {
  ensurePartStockRow($conn, $partId);
  $stmt = $conn->prepare("UPDATE parts_stock SET qty_on_hand = qty_on_hand + ? WHERE part_id = ? LIMIT 1");
  $stmt->bind_param("ii", $qty, $partId);
  $stmt->execute();
  $stmt->close();
}

function fail(string $msg): void {
  throw new RuntimeException($msg);
}

function fetchRouteCounts(mysqli $conn, int $woId): array {
  $stmt = $conn->prepare("
    SELECT di.process_area, COUNT(*) AS qty
    FROM work_order_items woi
    JOIN defectives_inventory di ON di.id = woi.defective_id
    WHERE woi.work_order_id = ?
      AND di.process_area IN ('PRODUCTION','EBAY','PARTS','SCRAP')
    GROUP BY di.process_area
    ORDER BY di.process_area
  ");
  $stmt->bind_param("i", $woId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $out = ['PRODUCTION'=>0,'EBAY'=>0,'PARTS'=>0,'SCRAP'=>0];
  foreach ($rows as $r) {
    $pa = (string)$r['process_area'];
    $out[$pa] = (int)$r['qty'];
  }
  return $out;
}

try {
  $conn->begin_transaction();

  // WO
  $stmtWO = $conn->prepare("SELECT id, wo_number, status, assigned_to FROM work_orders WHERE id = ? LIMIT 1");
  $stmtWO->bind_param("i", $woId);
  $stmtWO->execute();
  $wo = $stmtWO->get_result()->fetch_assoc();
  $stmtWO->close();

  if (!$wo) fail("Work Order not found.");

  if ($currentUserRole === 'technician') {
    $assignedTo = (int)($wo['assigned_to'] ?? 0);
    if ($assignedTo !== $currentUserId) fail("Not authorized to close this work order.");
  }

  if (in_array((string)$wo['status'], ['Completed','Cancelled'], true)) {
    fail("This Work Order is {$wo['status']}. Closing is locked.");
  }

  // Items + inventory data
  $stmtItems = $conn->prepare("
    SELECT
      woi.id AS woi_id,
      woi.status AS woi_status,
      woi.defective_id,
      di.serial_number,
      di.status AS inv_status,
      di.process_area AS inv_process_area,
      di.location_id AS inv_location_id
    FROM work_order_items woi
    JOIN defectives_inventory di ON di.id = woi.defective_id
    WHERE woi.work_order_id = ?
    ORDER BY woi.id ASC
  ");
  $stmtItems->bind_param("i", $woId);
  $stmtItems->execute();
  $items = $stmtItems->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmtItems->close();

  if (!$items) fail("This Work Order has no items.");

  foreach ($items as $it) {
    if (in_array((string)$it['woi_status'], ['Pending','In Progress'], true)) {
      fail("Cannot finalize. Item #{$it['woi_id']} is still '{$it['woi_status']}'.");
    }
  }

  $report = [];

  // Needed for Harvest
  $partsBinId = getLocationIdByName($conn, 'PARTS_BIN');
  if ($partsBinId <= 0) {
    fail("Missing required BIN 'PARTS_BIN' in locations table.");
  }

  foreach ($items as $it) {
    $woiId       = (int)$it['woi_id'];
    $defectiveId = (int)$it['defective_id'];
    $serial      = (string)$it['serial_number'];
    $woiStatus   = (string)$it['woi_status'];

    $fromLocId   = (int)($it['inv_location_id'] ?? 0);
    $fromInvStat = (string)($it['inv_status'] ?? '');
    $invArea     = strtoupper(trim((string)($it['inv_process_area'] ?? '')));

    $toLocId = (int)($toLocationByItem[$woiId] ?? 0);

    // lock row
    $stmtLock = $conn->prepare("SELECT id FROM defectives_inventory WHERE id = ? FOR UPDATE");
    $stmtLock->bind_param("i", $defectiveId);
    $stmtLock->execute();
    $stmtLock->close();

    // -----------------------
    // REPAIRED => In Stock, route EBAY/PRODUCTION
    // -----------------------
    if ($woiStatus === 'Repaired') {
      if (!in_array($invArea, ['PRODUCTION','EBAY'], true)) {
        fail("Item #$woiId ($serial) is Repaired but process_area is invalid. Set it to PRODUCTION or EBAY first (Save All).");
      }

      if ($toLocId <= 0) $toLocId = $fromLocId;
      if ($toLocId <= 0) fail("Missing destination BIN for item #$woiId ($serial).");

      $toLoc = getLocation($conn, $toLocId);
      if (!$toLoc) fail("Invalid destination location_id=$toLocId for item #$woiId ($serial).");
      $toLocName = (string)$toLoc['location_name'];

      $stmtUpd = $conn->prepare("
        UPDATE defectives_inventory
        SET
          status = 'In Stock',
          process_area = ?,
          removal_status = 'In Stock',
          removal_destination = NULL,
          removal_date = NULL,
          location_id = ?,
          bin_location = ?,
          last_updated = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
      ");
      $stmtUpd->bind_param("sisi", $invArea, $toLocId, $toLocName, $defectiveId);
      $stmtUpd->execute();
      $stmtUpd->close();

      logMovement($conn, [
        'defectives_id'     => $defectiveId,
        'serial_number'     => $serial,
        'movement_type'     => 'MOVE',
        'from_location_id'  => $fromLocId,
        'to_location_id'    => $toLocId,
        'from_status'       => $fromInvStat,
        'to_status'         => 'In Stock',
        'reason'            => "WO Finalize: Repaired -> {$invArea}",
        'user_id'           => $currentUserId,
      ]);

      $report[] = "✅ $serial (Item #$woiId): Repaired -> $invArea, moved to $toLocName";
      continue;
    }

    // -----------------------
    // SCRAP => Removed, destination = Scrap (ENUM ok)
    // -----------------------
    if ($woiStatus === 'Scrap') {
      if ($toLocId <= 0) $toLocId = $fromLocId;
      if ($toLocId <= 0) fail("Missing destination BIN for item #$woiId ($serial).");

      $toLoc = getLocation($conn, $toLocId);
      if (!$toLoc) fail("Invalid destination location_id=$toLocId for item #$woiId ($serial).");
      $toLocName = (string)$toLoc['location_name'];

      $stmtUpd = $conn->prepare("
        UPDATE defectives_inventory
        SET
          status = 'Scrap',
          process_area = 'SCRAP',
          removal_status = 'Removed',
          removal_destination = 'Scrap',
          removal_date = NOW(),
          location_id = ?,
          bin_location = ?,
          last_updated = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
      ");
      $stmtUpd->bind_param("isi", $toLocId, $toLocName, $defectiveId);
      $stmtUpd->execute();
      $stmtUpd->close();

      logMovement($conn, [
        'defectives_id'     => $defectiveId,
        'serial_number'     => $serial,
        'movement_type'     => 'MOVE',
        'from_location_id'  => $fromLocId,
        'to_location_id'    => $toLocId,
        'from_status'       => $fromInvStat,
        'to_status'         => 'Scrap',
        'reason'            => "WO Finalize: Scrap",
        'user_id'           => $currentUserId,
      ]);

      logMovement($conn, [
        'defectives_id'     => $defectiveId,
        'serial_number'     => $serial,
        'movement_type'     => 'OUT',
        'from_location_id'  => $toLocId,
        'to_location_id'    => $toLocId,
        'from_status'       => 'Scrap',
        'to_status'         => 'Scrap',
        'reason'            => "WO Finalize: Removed from stock (Scrap)",
        'user_id'           => $currentUserId,
      ]);

      $report[] = "🗑️ $serial (Item #$woiId): Scrap, moved to $toLocName, removed from stock";
      continue;
    }

    // -----------------------
    // RETURNED => Removed, BUT removal_destination MUST stay NULL (Option A)
    // and stays in same BIN by default
    // -----------------------
    if ($woiStatus === 'Returned') {
      if ($toLocId <= 0) $toLocId = $fromLocId;
      if ($toLocId <= 0) fail("Invalid destination location_id=0 for item #$woiId ($serial).");

      $toLoc = getLocation($conn, $toLocId);
      if (!$toLoc) fail("Invalid destination location_id=$toLocId for item #$woiId ($serial).");
      $toLocName = (string)$toLoc['location_name'];

      $stmtUpd = $conn->prepare("
        UPDATE defectives_inventory
        SET
          status = 'Returned',
          removal_status = 'Removed',
          removal_destination = NULL,
          removal_date = NOW(),
          location_id = ?,
          bin_location = ?,
          last_updated = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
      ");
      $stmtUpd->bind_param("isi", $toLocId, $toLocName, $defectiveId);
      $stmtUpd->execute();
      $stmtUpd->close();

      logMovement($conn, [
        'defectives_id'     => $defectiveId,
        'serial_number'     => $serial,
        'movement_type'     => 'STATUS_CHANGE',
        'from_location_id'  => $fromLocId,
        'to_location_id'    => $toLocId,
        'from_status'       => $fromInvStat,
        'to_status'         => 'Returned',
        'reason'            => "WO Finalize: Returned (removed, keep BIN)",
        'user_id'           => $currentUserId,
      ]);

      $report[] = "↩️ $serial (Item #$woiId): Returned, BIN kept: $toLocName";
      continue;
    }

    // -----------------------
    // HARVEST => force PARTS_BIN + Removed + destination Parts (ENUM ok) + add parts stock
    // -----------------------
    if ($woiStatus === 'Harvest') {
      $toLocId = $partsBinId;

      $toLoc = getLocation($conn, $toLocId);
      if (!$toLoc) fail("Invalid PARTS_BIN location_id=$toLocId");
      $toLocName = (string)$toLoc['location_name'];

      $qtyMap = $harvestQtyByItem[$woiId] ?? [];
      if (!is_array($qtyMap)) $qtyMap = [];

      $harvestNote = trim((string)($harvestNotesByItem[$woiId] ?? ''));

      $stmtUpd = $conn->prepare("
        UPDATE defectives_inventory
        SET
          status = 'Returned',
          process_area = 'PARTS',
          removal_status = 'Removed',
          removal_destination = 'Parts',
          removal_date = NOW(),
          location_id = ?,
          bin_location = ?,
          last_updated = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
      ");
      $stmtUpd->bind_param("isi", $toLocId, $toLocName, $defectiveId);
      $stmtUpd->execute();
      $stmtUpd->close();

      // harvest_events header
      $stmtEv = $conn->prepare("
        INSERT INTO harvest_events (wo_id, work_order_item_id, defectives_id, serial_number, harvested_by, notes)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      $stmtEv->bind_param("iiisis", $woId, $woiId, $defectiveId, $serial, $currentUserId, $harvestNote);
      $stmtEv->execute();
      $harvestEventId = (int)$conn->insert_id;
      $stmtEv->close();

      // lines
      $stmtLine = $conn->prepare("
        INSERT INTO harvest_event_lines (harvest_event_id, part_id, qty)
        VALUES (?, ?, ?)
      ");

      $partsCount = 0;
      foreach ($qtyMap as $partIdStr => $qtyVal) {
        $partId = (int)$partIdStr;
        if ($partId <= 0) continue;

        $qty = (int)$qtyVal;
        if ($qty <= 0) continue;

        if ($qty > 999) $qty = 999;

        $stmtLine->bind_param("iii", $harvestEventId, $partId, $qty);
        $stmtLine->execute();

        incrementPartStock($conn, $partId, $qty);
        $partsCount += $qty;
      }
      $stmtLine->close();

      if ($partsCount <= 0) {
        fail("Harvest requires at least 1 part qty > 0 for item #$woiId ($serial).");
      }

      logMovement($conn, [
        'defectives_id'     => $defectiveId,
        'serial_number'     => $serial,
        'movement_type'     => 'MOVE',
        'from_location_id'  => $fromLocId,
        'to_location_id'    => $toLocId,
        'from_status'       => $fromInvStat,
        'to_status'         => 'Returned',
        'reason'            => "WO Finalize: Harvest -> PARTS_BIN (Removed) + parts added",
        'user_id'           => $currentUserId,
      ]);

      logMovement($conn, [
        'defectives_id'     => $defectiveId,
        'serial_number'     => $serial,
        'movement_type'     => 'OUT',
        'from_location_id'  => $toLocId,
        'to_location_id'    => $toLocId,
        'from_status'       => 'Returned',
        'to_status'         => 'Returned',
        'reason'            => "WO Finalize: Removed from stock (Harvest)",
        'user_id'           => $currentUserId,
      ]);

      $report[] = "🧠 $serial (Item #$woiId): Harvest -> PARTS_BIN, removed, parts added total qty: $partsCount";
      continue;
    }

    fail("Unsupported item status '{$woiStatus}' for item #$woiId ($serial).");
  }

  // WO -> Completed
  $stmtClose = $conn->prepare("UPDATE work_orders SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
  $stmtClose->bind_param("i", $woId);
  $stmtClose->execute();
  $stmtClose->close();

  $conn->commit();

  $routeCounts = fetchRouteCounts($conn, $woId);

  echo "<div class='container mt-4'>";
  echo "<div class='alert alert-success'><strong>Work Order finalized.</strong> WO: " . h($wo['wo_number']) . "</div>";

  echo "<div class='card shadow-sm mb-3'><div class='card-header'><strong>Finalize Report</strong></div><div class='card-body'>";
  echo "<ul class='mb-0'>";
  foreach ($report as $line) echo "<li>" . h($line) . "</li>";
  echo "</ul></div></div>";

  echo "<div class='card shadow-sm'>";
  echo "<div class='card-header d-flex justify-content-between align-items-center'>";
  echo "<strong><i class='bi bi-printer'></i> Route Receipts</strong>";
  echo "</div>";
  echo "<div class='card-body'>";

  $printLinks = [];
  foreach (['PRODUCTION','EBAY','PARTS','SCRAP'] as $route) {
    $qty = (int)($routeCounts[$route] ?? 0);
    if ($qty <= 0) continue;

    $url = "work_order_receipt.php?wo_id=".(int)$woId."&route=".urlencode($route)."&autoprint=1";
    $printLinks[] = $url;

    echo "<a class='btn btn-outline-dark me-2 mb-2' target='_blank' href='".h($url)."'>";
    echo "<i class='bi bi-receipt'></i> Print ".h($route)." (".$qty.")</a>";
  }

  if (empty($printLinks)) {
    echo "<div class='text-muted'>No routed items found to print.</div>";
  } else {
    echo "<div class='mt-2'>";
    echo "<button class='btn btn-primary' type='button' id='btnPrintAll'>";
    echo "<i class='bi bi-printer'></i> Print All Routes</button>";
    echo "<div class='small text-muted mt-2'>This will open one tab per route and trigger printing (browser popup settings may apply).</div>";
    echo "</div>";

    echo "<script>";
    echo "const __printLinks = ".json_encode($printLinks).";";
    echo "document.getElementById('btnPrintAll')?.addEventListener('click', () => {";
    echo "  __printLinks.forEach((u, i) => setTimeout(() => window.open(u, '_blank'), i*350));";
    echo "});";
    echo "</script>";
  }

  echo "</div></div>";

  echo "<div class='mt-3'>";
  echo "<a class='btn btn-secondary' href='work_order_view.php?id=".(int)$woId."'>Back to Work Order</a>";
  echo "</div>";

  echo "</div>";

} catch (Throwable $e) {
  if ($conn->ping()) $conn->rollback();

  echo "<div class='container mt-4'>";
  echo "<div class='alert alert-danger'><strong>Finalize failed:</strong> " . h($e->getMessage()) . "</div>";
  echo "<div><a class='btn btn-secondary' href='work_order_view.php?id=".(int)$woId."'>Back</a></div>";
  echo "</div>";
}

include '../includes/footer.php';
