<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once './includes/db_connection.php';

$msg = "";

if (isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    // Asegúrate de que la tabla users tenga la columna 'role'
    $result = $conn->query("SELECT * FROM users WHERE username = '$username' LIMIT 1");
    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // Lo que ya tenías
            $_SESSION['user']     = $user['username'];

            // Lo nuevo para el sistema de órdenes y roles
            $_SESSION['user_id']  = (int)$user['id'];
            $_SESSION['role']     = $user['role'] ?? 'viewer';

            header("Location: views/dashboard.php");
            exit();
        } else {
            $msg = "<div class='alert alert-danger'>Contraseña incorrecta.</div>";
        }
    } else {
        $msg = "<div class='alert alert-danger'>Usuario no encontrado.</div>";
    }
}


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - Defectives Inventory</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header text-center">
                        <h4>Ingreso al Sistema</h4>
                    </div>
                    <div class="card-body">
                        <?= $msg ?>
                        <form method="post">
                            <div class="mb-3">
                                <label>Usuario:</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label>Contraseña:</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="login" class="btn btn-primary">Ingresar</button>
                            </div>
                        </form>
                    </div>
                </div>
                <p class="text-center mt-3">&copy; 2025 - CTI Lab</p>
            </div>
        </div>
    </div>
</body>
</html>
