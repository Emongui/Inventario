<?php
require_once 'includes/db_connection.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("SELECT id, product_id FROM product_ids WHERE product_id LIKE CONCAT('%', ?, '%') LIMIT 10");
$stmt->bind_param("s", $q);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = ['id' => $row['id'], 'product_id' => $row['product_id']];
}

echo json_encode($data);
