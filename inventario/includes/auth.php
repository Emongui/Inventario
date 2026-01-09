<?php
// includes/auth.php
// Session & access control for Defectives Inventory

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si no hay usuario logueado, mandar a login
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// Aseguramos que siempre haya role y user_id
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'viewer';
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = null;
}

?>
