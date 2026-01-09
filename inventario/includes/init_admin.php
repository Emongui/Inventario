<?php
require_once './includes/db_connection.php';

$username = 'admin';
$password = 'Admin1234'; // tu contraseña real
$role = 'admin';

$hash = password_hash($password, PASSWORD_DEFAULT);

// Elimina si ya existe
$stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->close();

// Inserta el usuario con rol
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $hash, $role);

if ($stmt->execute()) {
    echo "✅ Usuario 'admin' creado exitosamente con rol admin.";
} else {
    echo "❌ Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>