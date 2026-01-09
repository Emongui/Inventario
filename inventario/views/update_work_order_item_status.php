<?php
// update_work_order_item_status.php
// Actualiza status + notes del work_order_item, actualiza process_area en defectives_inventory,
// registra movimiento, y recalcula status de la WO (SIN rutas en status de work_orders).

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

function h($v): string {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_response(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/**
 * Recalcula el status de la Work Order basado en los items (sin rutas en status):
 * - Si hay Pending -> Pending
 * - Si no hay Pending y hay In Progress -> In Progress
 * - Si todo está terminal (Repaired/Scrap/Returned) -> Completed
 *
 * Nota: Closed/Canceled NO se asignan aquí automáticamente (solo manual desde UI/admin).
 */
function recomputeWorkOrderStatus(mysqli $conn, int $workOrderId): string
{
    // Si la WO ya está Closed o Canceled, no tocar
    $stmt = $conn->prepare("SELECT status FROM work_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $workOrderId);
    $stmt->execute();
    $wo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $current = (string)($wo['status'] ?? '');
    if ($current === 'Closed' || $current === 'Canceled') {
        return $current;
    }

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

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
);

// Roles permitidos
$role = $_SESSION['role'] ?? 'viewer';
$allowedRoles = ['admin', 'supervisor', 'technician'];

if (!in_array($role, $allowedRoles, true)) {
    if ($isAjax) json_response(403, ['ok' => false, 'error' => 'Not authorized']);
    die("Not authorized.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) json_response(405, ['ok' => false, 'error' => 'Method not allowed']);
    die("Method not allowed.");
}

$workOrderItemId = (int)($_POST['work_order_item_id'] ?? 0);
$newStatus       = trim((string)($_POST['new_status'] ?? ''));
$notes           = trim((string)($_POST['notes'] ?? ''));
$processArea     = trim((string)($_POST['process_area'] ?? ''));
$redirectWoId    = (int)($_POST['work_order_id'] ?? 0);
$returnTo        = trim((string)($_POST['return_to'] ?? ''));

// Estados de item
$validStatuses = ['Pending', 'In Progress', 'Repaired', 'Scrap', 'Returned'];

// Process Areas (ruta final del equipo)
$validAreas    = ['REPAIR', 'PRODUCTION', 'EBAY', 'PARTS', 'SCRAP'];

if ($workOrderItemId <= 0 || !in_array($newStatus, $validStatuses, true)) {
    if ($isAjax) json_response(400, ['ok' => false, 'error' => 'Invalid parameters']);
    die("Invalid parameters.");
}

if ($processArea !== '' && !in_array($processArea, $validAreas, true)) {
    if ($isAjax) json_response(400, ['ok' => false, 'error' => 'Invalid process area']);
    die("Invalid process area.");
}

$userId = (int)($_SESSION['user_id'] ?? 0);

try {
    $conn->begin_transaction();

    // 1) Leer item actual
    $stmt = $conn->prepare("
        SELECT id, work_order_id, defective_id, status, notes
        FROM work_order_items
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $workOrderItemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->rollback();
        if ($isAjax) json_response(404, ['ok' => false, 'error' => 'Work order item not found']);
        die("Work order item not found.");
    }

    $workOrderId = (int)$row['work_order_id'];
    $defectiveId = (int)$row['defective_id'];
    $oldStatus   = (string)$row['status'];

    if ($redirectWoId > 0 && $redirectWoId !== $workOrderId) {
        $conn->rollback();
        if ($isAjax) json_response(400, ['ok' => false, 'error' => 'Work order mismatch']);
        die("Work order mismatch.");
    }

    // 2) Update item (status + notes)
    $stmt = $conn->prepare("
        UPDATE work_order_items
        SET status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ssi", $newStatus, $notes, $workOrderItemId);
    $stmt->execute();
    $stmt->close();

    // 3) Update process_area en inventario (si viene)
    $oldArea = null;
    if ($processArea !== '') {
        $stmt = $conn->prepare("SELECT process_area FROM defectives_inventory WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $defectiveId);
        $stmt->execute();
        $oldArea = $stmt->get_result()->fetch_assoc()['process_area'] ?? null;
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE defectives_inventory
            SET process_area = ?, last_updated = CURRENT_TIMESTAMP
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("si", $processArea, $defectiveId);
        $stmt->execute();
        $stmt->close();
    }

    // 4) Log en inventory_movements (STATUS_CHANGE)
    $reasonParts = ["WO item status change (item_id={$workOrderItemId})"];
    if ($processArea !== '' && $oldArea !== $processArea) {
        $reasonParts[] = "process_area: {$oldArea} -> {$processArea}";
    }
    $reason = implode(' | ', $reasonParts);

    $stmt = $conn->prepare("
        INSERT INTO inventory_movements
            (defectives_id, serial_number, movement_type, from_location_id, to_location_id,
             from_status, to_status, reason, user_id, created_at)
        VALUES
            (?, NULL, 'STATUS_CHANGE', NULL, NULL,
             ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->bind_param("isssi", $defectiveId, $oldStatus, $newStatus, $reason, $userId);
    $stmt->execute();
    $stmt->close();

    // 5) Recalcular status de la WO (SIN rutas)
    $newWoStatus = recomputeWorkOrderStatus($conn, $workOrderId);

    $stmt = $conn->prepare("UPDATE work_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
    $stmt->bind_param("si", $newWoStatus, $workOrderId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    if ($isAjax) {
        json_response(200, [
            'ok' => true,
            'work_order_id' => $workOrderId,
            'work_order_status' => $newWoStatus,
            'item_id' => $workOrderItemId,
            'item_old_status' => $oldStatus,
            'item_new_status' => $newStatus
        ]);
    }

    if ($returnTo !== '') {
        header("Location: " . $returnTo);
        exit;
    }

    header("Location: work_order_view.php?id=" . (int)$workOrderId);
    exit;

} catch (Throwable $e) {
    if ($conn->errno) $conn->rollback();
    if ($isAjax) json_response(500, ['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
    die("Server error: " . h($e->getMessage()));
}
