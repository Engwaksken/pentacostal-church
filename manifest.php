<?php
declare(strict_types=1);

// Installation metadata must be available without a login or a session cookie.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pwa.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode(pwa_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
