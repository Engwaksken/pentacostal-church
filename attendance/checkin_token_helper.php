<?php

require_once __DIR__ . '/../config/env.php';

if (!defined('CHECKIN_LINK_SECRET')) {
    // Set CHECKIN_LINK_SECRET in .env; changing it invalidates check-in links already shared.
    define('CHECKIN_LINK_SECRET', env('CHECKIN_LINK_SECRET', 'church-attendance-checkin-fallback-secret-please-override'));
}

function checkin_b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function checkin_b64url_decode(string $data): string|false {
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function checkin_generate_token(string $date, string $type, int $expTs = 0): string {
    $payload = json_encode(['d' => $date, 't' => $type, 'e' => $expTs], JSON_UNESCAPED_SLASHES);
    $payloadEnc = checkin_b64url_encode($payload);
    $sig = substr(hash_hmac('sha256', $payloadEnc, CHECKIN_LINK_SECRET), 0, 40);
    return $payloadEnc . '.' . $sig;
}

function checkin_verify_token(?string $token): ?array {
    if (!$token || !str_contains($token, '.')) {
        return null;
    }

    [$payloadEnc, $sig] = explode('.', $token, 2);

    $expectedSig = substr(hash_hmac('sha256', $payloadEnc, CHECKIN_LINK_SECRET), 0, 40);
    if (!hash_equals($expectedSig, $sig)) {
        return null;
    }

    $json = checkin_b64url_decode($payloadEnc);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['d'], $data['t'], $data['e'])) {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['d'])) {
        return null;
    }

    $exp = (int)$data['e'];
    if ($exp > 0 && time() > $exp) {
        return null;
    }

    return ['date' => $data['d'], 'type' => (string)$data['t'], 'exp' => $exp];
}


function checkin_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $dir;
}