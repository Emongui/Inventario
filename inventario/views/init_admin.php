<?php
// /opt/lampp/htdocs/inventario/init_admin.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db_connection.php';

$username = 'admin';
$password_plain = 'Admin1234';
$role = 'admin';

$hash = password_hash($password_plain, PASSWORD_DEFAULT);

// delete if exists
$stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->close();

// insert fresh admin
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $hash, $role);
$stmt->execute();
$stmt->close();

echo "OK - admin reset. Username: admin Password: Admin1234";
