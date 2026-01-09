<?php
require_once '../includes/db_connection.php';
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}
// Mostrar errores (modo debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuración base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "defectives_db";

// Conectar
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Agregar nuevo Product ID
if (isset($_POST['submit'])) {
    $product_id = $conn->real_escape_string($_POST['product_id']);
    $description = $conn->real_escape_string($_POST['description']);

    $sql = "INSERT INTO product_ids (product_id, description) VALUES ('$product_id', '$description')";
    
    if ($conn->query($sql) === TRUE) {
        $msg = "Product ID agregado correctamente.";
    } else {
        $msg = "Error: " . $conn->error;
    }
}

// Obtener lista actual
$result = $conn->query("SELECT * FROM product_ids ORDER BY product_id ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Product IDs</title>
</head>
<body>
    <h2>Agregar nuevo Product ID</h2>
    <?php if (isset($msg)) echo "<p>$msg</p>"; ?>

    <form method="post">
        Product ID: <input type="text" name="product_id" required><br><br>
        Descripción: <input type="text" name="description"><br><br>
        <input type="submit" name="submit" value="Agregar">
    </form>

    <h2>Lista de Product IDs actuales</h2>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Product ID</th>
            <th>Descripción</th>
        </tr>
        <?php while($row = $result->fetch_assoc()) { ?>
        <tr>
            <td><?= $row['id'] ?></td>
            <td><?= $row['product_id'] ?></td>
            <td><?= $row['description'] ?></td>
        </tr>
        <?php } ?>
    </table>

</body>
</html>
