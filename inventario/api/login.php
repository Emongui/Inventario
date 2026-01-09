<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

$username = isset($data['username']) ? trim((string) $data['username']) : '';
$password = isset($data['password']) ? (string) $data['password'] : '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Por favor ingresa usuario y contraseña.']);
    exit;
}

$stmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuario o contraseña incorrectos.']);
    exit;
}

$token = generate_jwt([
    'sub' => $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
]);

echo json_encode([
    'token' => $token,
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ],
]);
