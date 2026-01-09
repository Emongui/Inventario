<?php
declare(strict_types=1);

const API_JWT_SECRET = 'change-me';
const API_JWT_TTL_SECONDS = 3600;

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function get_jwt_secret(): string
{
    $env_secret = getenv('API_JWT_SECRET');
    return $env_secret !== false && $env_secret !== '' ? $env_secret : API_JWT_SECRET;
}

function generate_jwt(array $payload, ?string $secret = null): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $secret = $secret ?? get_jwt_secret();

    $payload['iat'] = time();
    $payload['exp'] = $payload['iat'] + API_JWT_TTL_SECONDS;

    $encoded_header = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encoded_payload = base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $encoded_header . '.' . $encoded_payload, $secret, true);

    return $encoded_header . '.' . $encoded_payload . '.' . base64url_encode($signature);
}

function verify_jwt(string $token, ?string $secret = null): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$encoded_header, $encoded_payload, $encoded_signature] = $parts;
    $secret = $secret ?? get_jwt_secret();

    $signature = base64url_decode($encoded_signature);
    $expected = hash_hmac('sha256', $encoded_header . '.' . $encoded_payload, $secret, true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload_json = base64url_decode($encoded_payload);
    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['exp']) && time() > (int) $payload['exp']) {
        return null;
    }

    return $payload;
}

function get_bearer_token(): ?string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    if (!$authorization) {
        return null;
    }

    if (preg_match('/Bearer\s+(\S+)/i', $authorization, $matches)) {
        return $matches[1];
    }

    return null;
}

function require_auth(): array
{
    $token = get_bearer_token();
    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing token']);
        exit;
    }

    $payload = verify_jwt($token);
    if (!$payload) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    return $payload;
}
