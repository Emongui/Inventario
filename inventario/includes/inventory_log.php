<?php
// includes/inventory_log.php
// Helper to register inventory movements in inventory_movements table.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Log an inventory movement.
 *
 * Expected keys in $data:
 * - defectives_id   (int|null)
 * - serial_number   (string|null)
 * - movement_type   (string)  'IN','OUT','MOVE','STATUS_CHANGE','ADJUST'
 * - from_location_id (int|null)
 * - to_location_id   (int|null)
 * - from_status      (string|null)
 * - to_status        (string|null)
 * - reason           (string|null)
 * - user_id          (int|null)  (if null, will try from $_SESSION['user_id'])
 */
function log_inventory_movement(mysqli $conn, array $data): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $defectives_id    = $data['defectives_id']    ?? null;
    $serial_number    = $data['serial_number']    ?? null;
    $movement_type    = $data['movement_type']    ?? null;
    $from_location_id = $data['from_location_id'] ?? null;
    $to_location_id   = $data['to_location_id']   ?? null;
    $from_status      = $data['from_status']      ?? null;
    $to_status        = $data['to_status']        ?? null;
    $reason           = $data['reason']           ?? null;
    $user_id          = $data['user_id']          ?? ($_SESSION['user_id'] ?? null);

    if (!$movement_type) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO inventory_movements
        (defectives_id, serial_number, movement_type, from_location_id, to_location_id,
         from_status, to_status, reason, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // i = int, s = string
    $stmt->bind_param(
        'ississssi',
        $defectives_id,
        $serial_number,
        $movement_type,
        $from_location_id,
        $to_location_id,
        $from_status,
        $to_status,
        $reason,
        $user_id
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
