<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db_connection.php';


$newPass = 'Laptop99';
$hash    = password_hash($newPass, PASSWORD_DEFAULT);

// Resetea TODOS los usuarios
$stmt = $conn->prepare("UPDATE users SET password = ?");
$stmt->bind_param("s", $hash);
$stmt->execute();

echo "OK. All users reset to: {$newPass}<br>";
echo "Affected rows: " . $stmt->affected_rows . "<br>";
