<?php
// update_work_order_items_bulk.php
// Guarda en bloque Status + Process Area + Notes para todos los items de una WO.
// Recalcula el status de la WO: Pending / In Progress / Completed.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function recomputeWorkOrderStatus(mysqli $conn, int $workOrderId): string
{
    $stmt = $conn->prepare("
        SELECT
            SUM(status='Pending') AS pending_count,
            SUM(status='In Progress') AS in_progress_count,
            COUNT(*) AS total_items
        FROM work_order_items
        WHERE work_order_id = ?
    ");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $pending = (int)($r['pending_count'] ?? 0);
    $inProg  = (int)($r['in_progress_count'] ?? 0);
    $total   = (int)($r['total_items'] ?? 0);

    if ($total <= 0) return 'Pending';
    if ($pending > 0) return 'Pending';
    if ($inProg > 0)  return 'In Progress';
    return 'Completed';
}

// Roles permitidos
$role = $_SESSION['role'] ?? 'viewer';
$allowedRoles = ['admin', 'inventory_manager', 'supervisor', 'technician'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    die("Not authorized.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method not allowed.");
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$workOrderId = (int)($_POST['work_order_id'] ?? 0);
$returnTo    = trim((string)($_POST['return_to'] ?? ''));

if ($workOrderId <= 0) {
    http_response_code(400);
    die("Invalid work_order_id.");
}

$items = $_POST['items'] ?? null;
if (!is_array($items) || empty($items)) {
    if ($returnTo !== '') {
        header("Location: " . $returnTo);
        exit;
    }
    header("Location: work_order_view.php?id=" . (int)$workOrderId);
    exit;
}

$validItemStatuses = ['Pending', 'In Progress', 'Repaired', 'Scrap', 'Returned', 'Harvest'];
$validAreas        = ['REPAIR', 'PRODUCTION', 'EBAY', 'PARTS', 'SCRAP'];

// ✅ Match DB: work_orders.status includes 'Cancelled'
$LOCK_STATUSES = ['Completed', 'Cancelled'];

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT id, assigned_to, status FROM work_orders WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $wo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$wo) {
        throw new Exception("Work order not found.");
    }

    $woStatus = (string)($wo['status'] ?? '');

    if (in_array($woStatus, $LOCK_STATUSES, true)) {
        throw new Exception("Work order is locked ({$woStatus}).");
    }

    if ($role === 'technician') {
        $assignedTo = (int)($wo['assigned_to'] ?? 0);
        if ($assignedTo !== $currentUserId) {
            throw new Exception("Not authorized to edit this work order.");
        }
    }

    $stmt = $conn->prepare("
        SELECT id, defective_id, status
        FROM work_order_items
        WHERE work_order_id = ?
    ");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $res = $stmt->get_result();

    $allowedItemIds = [];
    $itemMap = [];
    while ($r = $res->fetch_assoc()) {
        $iid = (int)$r['id'];
        $allowedItemIds[$iid] = true;
        $itemMap[$iid] = [
            'defective_id' => (int)$r['defective_id'],
            'old_status'   => (string)$r['status'],
        ];
    }
    $stmt->close();

    $stmtUpdateItem = $conn->prepare("
        UPDATE work_order_items
        SET status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND work_order_id = ?
        LIMIT 1
    ");

    $stmtUpdateInvArea = $conn->prepare("
        UPDATE defectives_inventory
        SET process_area = ?, last_updated = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
    ");

    $stmtInsertMove = $conn->prepare("
        INSERT INTO inventory_movements
            (defectives_id, serial_number, movement_type, from_location_id, to_location_id,
             from_status, to_status, reason, user_id, created_at)
        VALUES
            (?, NULL, 'STATUS_CHANGE', NULL, NULL,
             ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    foreach ($items as $itemIdStr => $data) {
        $itemId = (int)$itemIdStr;
        if ($itemId <= 0 || !isset($allowedItemIds[$itemId])) {
            continue;
        }

        $newStatus   = trim((string)($data['status'] ?? ''));
        $newNotes    = trim((string)($data['notes'] ?? ''));
        $processArea = trim((string)($data['process_area'] ?? ''));

        // Enforce Harvest rules
        if ($newStatus === 'Harvest') {
            $processArea = 'PARTS'; // Harvest siempre va a PARTS
        }

        if (!in_array($newStatus, $validItemStatuses, true)) {
            throw new Exception("Invalid item status for item_id={$itemId}");
        }
        if ($processArea !== '' && !in_array($processArea, $validAreas, true)) {
            throw new Exception("Invalid process area for item_id={$itemId}");
        }

        $defectiveId = (int)$itemMap[$itemId]['defective_id'];
        $oldStatus   = (string)$itemMap[$itemId]['old_status'];

        $stmtUpdateItem->bind_param("ssii", $newStatus, $newNotes, $itemId, $workOrderId);
        $stmtUpdateItem->execute();

        if ($processArea !== '') {
            $stmtUpdateInvArea->bind_param("si", $processArea, $defectiveId);
            $stmtUpdateInvArea->execute();
        }

        $reason = "WO bulk save (wo_id={$workOrderId}, item_id={$itemId})";
        $stmtInsertMove->bind_param("isssi", $defectiveId, $oldStatus, $newStatus, $reason, $currentUserId);
        $stmtInsertMove->execute();
    }

    $newWoStatus = recomputeWorkOrderStatus($conn, $workOrderId);

    $stmt = $conn->prepare("UPDATE work_orders SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
    $stmt->bind_param("si", $newWoStatus, $workOrderId);
    $stmt->execute();
    $stmt->close();

    $stmtUpdateItem->close();
    $stmtUpdateInvArea->close();
    $stmtInsertMove->close();

    $conn->commit();

    if ($returnTo !== '') {
        $sep = (str_contains($returnTo, '?')) ? '&' : '?';
        header("Location: " . $returnTo . $sep . "ok=1");
        exit;
    }

    header("Location: work_order_view.php?id=" . (int)$workOrderId . "&ok=1");
    exit;

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    http_response_code(500);
    die("Server error: " . h($e->getMessage()));
}
