<?php
/** Run with: php tools/test-pwa.php (no database required). */
declare(strict_types=1);

$settings = [];
function env(string $key, string $default = ''): string { return $GLOBALS['appUrl'] ?? $default; }
function site_setting(string $key, string $default = ''): string { return $GLOBALS['settings'][$key] ?? $default; }
function site_brand(): array {
    return ['name' => 'Test Church', 'primary' => '#123456', 'background' => '#abcdef'];
}
require_once __DIR__ . '/../includes/pwa.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$_SERVER['SCRIPT_NAME'] = '/manifest.php';
check(pwa_base_path() === '/', 'Root install path');
$appUrl = 'https://example.org/church';
check(pwa_base_path() === '/church/', 'Subdirectory install path');
check(pwa_branding()['icons'] === [], 'No stock icon when no admin logo exists');
check(pwa_render_icon(192) === null, 'No generated stock icon');
check(pwa_logo_file('../outside.png') === null, 'Reject paths outside site');
check(pwa_logo_file('https://example.org/logo.png') === null, 'Never fetch external images server-side');

$settings['site_logo'] = 'https://example.org/admin-logo.png';
$brand = pwa_branding();
check(strpos($brand['icons'][0]['src'], $settings['site_logo']) === 0, 'Use remote admin logo');
check($brand['apple_icon'] === $brand['icons'][0]['src'], 'Apple uses same branding');

$fixture = tempnam(__DIR__, 'pwa-test-');
try {
    if (function_exists('imagecreatetruecolor')) {
        $image = imagecreatetruecolor(80, 40);
        imagepng($image, $fixture);
        imagedestroy($image);
    } else {
        file_put_contents($fixture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='));
    }
    $settings = ['site_logo' => 'tools/' . basename($fixture), 'logo' => 'assets/img/logo.png'];
    $brand = pwa_branding();
    check($brand['name'] === 'Test Church', 'Use admin name');
    check($brand['theme_color'] === '#123456', 'Use admin theme');
    if (function_exists('imagecreatefromstring')) {
        check(count($brand['icons']) === 2, 'Generate both install sizes');
        check($brand['icons'][0]['sizes'] === '192x192', '192px icon');
        check($brand['icons'][1]['sizes'] === '512x512', '512px icon');
        check(strpos($brand['apple_icon'], 'size=180') !== false, '180px Apple icon');
        check(strpos($brand['icons'][0]['src'], '/church/pwa-icon?') === 0, 'Subdirectory icon URL');
        foreach ([180, 192, 512] as $size) {
            $png = pwa_render_icon($size);
            check($png !== null, 'Render configured logo');
            $dimensions = getimagesizefromstring($png);
            check($dimensions[0] === $size && $dimensions[1] === $size, 'Actual PNG dimensions');
            check($dimensions['mime'] === 'image/png', 'Actual PNG MIME');
            $rendered = imagecreatefromstring($png);
            check(imagecolorat($rendered, 0, 0) === 0xffffff, 'White padding around rectangular logo');
            check(imagecolorat($rendered, (int)($size / 2), (int)($size / 2)) === 0, 'Admin logo visible in center');
            imagedestroy($rendered);
        }
        check(pwa_render_icon(999) === null, 'Reject unsupported icon sizes');
    } else {
        check($brand['icons'][0]['sizes'] === '1x1', 'Original dimensions without GD');
    }
    $manifest = json_decode(json_encode(pwa_manifest()), true, 512, JSON_THROW_ON_ERROR);
    check($manifest['name'] === 'Test Church', 'Manifest site name');
    check($manifest['id'] === '/church/' && $manifest['scope'] === '/church/', 'Stable manifest identity and scope');
    check($manifest['start_url'] === '/church/dashboard', 'Manifest start URL');
    check($manifest['shortcuts'][0]['icons'] === $manifest['icons'], 'Shortcut uses admin logo');
    $previous = $brand['icons'][0]['src'];
    $settings['site_logo'] = 'https://example.org/replacement.png';
    check(pwa_branding()['icons'][0]['src'] !== $previous, 'Logo changes invalidate icon URL');
    unset($settings['site_logo']);
    check(pwa_branding()['icons'] !== [], 'Legacy logo setting remains supported');
} finally {
    unlink($fixture);
}
echo "PWA branding checks passed.\n";
