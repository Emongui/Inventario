<?php
require_once './includes/db_connection.php';

header('Content-Type: application/json');

// Protección y limpieza de parámetros recibidos
function clean($conn, $param) {
    return trim($conn->real_escape_string($param));
}

// Obtener Sub-Categories
if (isset($_GET['main_category']) && !isset($_GET['sub_category'])) {
    $main_category = clean($conn, $_GET['main_category']);

    $sql = "SELECT DISTINCT sub_category FROM defect_codes WHERE main_category = '$main_category'";
    $result = $conn->query($sql);

    $sub_categories = [];
    while ($row = $result->fetch_assoc()) {
        $sub_categories[] = $row['sub_category'];
    }

    echo json_encode($sub_categories);
    exit;
}

// Obtener Defect Codes
if (isset($_GET['main_category']) && isset($_GET['sub_category'])) {
    $main_category = clean($conn, $_GET['main_category']);
    $sub_category = clean($conn, $_GET['sub_category']);

    $sql = "SELECT id, defect_code FROM defect_codes 
            WHERE main_category = '$main_category' AND sub_category = '$sub_category'";
    $result = $conn->query($sql);

    $defect_codes = [];
    while ($row = $result->fetch_assoc()) {
        $defect_codes[] = [
            "id" => $row['id'],
            "defect_code" => $row['defect_code']
        ];
    }

    echo json_encode($defect_codes);
    exit;
}

// Si no recibe ningún parámetro válido:
echo json_encode([]);
?>
