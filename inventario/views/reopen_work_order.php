<?php
// reopen_work_order.php
// Admin: re-abrir Work Orders bloqueadas (Completed/Closed/Canceled/Cancelled)

ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../includes/auth.php';
require_once '../includes/db_connection.php';

$currentUserRole = $_SESSION['role'] ?? 'viewer';
if ($currentUserRole !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$woId = isset($_POST['wo_id']) ? (int)$_POST['wo_id'] : 0;
if ($woId <= 0) {
    header("Location: work_orders_list.php");
    exit;
}

// Decide a qué status volver:
// - Yo lo devuelvo a "In Progress" para que quede claro que está activa otra vez.
$newStatus = 'In Progress';

try {
    $stmt = $conn->prepare("SELECT status FROM work_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $woId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception("Work order not found.");
    }

    $oldStatus = (string)$row['status'];

    // Solo reabrimos si estaba bloqueada (evita cambios raros)
    $locked = in_array($oldStatus, ['Completed','Closed','Canceled','Cancelled'], true);
    if (!$locked) {
        header("Location: work_order_view.php?id={$woId}");
        exit;
    }

    $stmt = $conn->prepare("UPDATE work_orders SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $woId);
    $stmt->execute();
    $stmt->close();

    header("Location: work_order_view.php?id={$woId}&reopened=1");
    exit;

} catch (Exception $e) {
    // En caso de error, vuelve a la vista con un mensaje simple
    header("Location: work_order_view.php?id={$woId}");
    exit;
}
