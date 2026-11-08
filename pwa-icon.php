<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pwa.php';

$size = (int)($_GET['size'] ?? 192);
$png = pwa_render_icon($size);
if ($png === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $png;
