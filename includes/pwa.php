<?php
declare(strict_types=1);

/** Public PWA branding helpers; the caller loads config/db.php first. */
function pwa_base_path(): string
{
    $url = defined('BASE_URL') ? BASE_URL : env('APP_URL', '');
    $path = $url !== '' ? (string)parse_url($url, PHP_URL_PATH) : dirname($_SERVER['SCRIPT_NAME'] ?? '/manifest.php');
    return '/' . (trim($path, '/\\.') !== '' ? trim($path, '/\\.') . '/' : '');
}

/** Only local files inside the site may be decoded; never fetch a remote URL on the server. */
function pwa_logo_file(string $logo): ?string
{
    if ($logo === '' || preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $logo)) return null;
    $root = realpath(__DIR__ . '/..');
    $file = realpath($root . '/' . ltrim($logo, '/'));
    if ($file === false || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    return $file;
}

function pwa_branding(): array
{
    $brand = site_brand();
    // Deliberately do not use site_brand()['logo'], which includes a stock-logo fallback.
    $logo = site_setting('site_logo', site_setting('logo'));
    $file = pwa_logo_file($logo);
    $image = $file !== null ? @getimagesize($file) : false;
    $version = substr(hash('sha256', $logo . ($file !== null ? (string)filemtime($file) : '')), 0, 16);
    $icons = [];
    $appleIcon = '';
    $base = pwa_base_path();
    if ($image && in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
        && $image[0] * $image[1] <= 16000000 && function_exists('imagecreatefromstring')) {
        foreach ([192, 512] as $size) {
            $icons[] = ['src' => $base . 'pwa-icon?size=' . $size . '&v=' . $version,
                'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'any'];
        }
        $appleIcon = $base . 'pwa-icon?size=180&v=' . $version;
    } elseif ($logo !== '' && ($image || preg_match('~^https?://~i', $logo))) {
        // Without GD (or for an external logo), let the browser load the admin's original.
        $url = preg_match('~^https?://~i', $logo) ? $logo : $base . ltrim($logo, '/');
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . $version;
        $icon = ['src' => $url, 'purpose' => 'any'];
        if ($image) {
            $icon['sizes'] = $image[0] . 'x' . $image[1];
            $icon['type'] = $image['mime'];
        }
        $icons[] = $icon;
        $appleIcon = $url;
    }
    return ['name' => $brand['name'], 'icons' => $icons, 'apple_icon' => $appleIcon,
        'theme_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $brand['primary']) ? $brand['primary'] : '#4a1e8f',
        'background_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $brand['background']) ? $brand['background'] : '#f3f2f8'];
}

function pwa_manifest(): array
{
    $brand = pwa_branding();
    $base = pwa_base_path();
    return [
        'id' => $base,
        'name' => $brand['name'],
        'short_name' => $brand['name'],
        'description' => 'Church services, programs, giving and member information.',
        'start_url' => $base . 'dashboard',
        'scope' => $base,
        'display' => 'standalone',
        'background_color' => $brand['background_color'],
        'theme_color' => $brand['theme_color'],
        'icons' => $brand['icons'],
        'shortcuts' => [['name' => 'Dashboard', 'url' => $base . 'dashboard', 'icons' => $brand['icons']]],
    ];
}

/** Return a square PNG, or null when the configured image cannot be rendered. */
function pwa_render_icon(int $size): ?string
{
    $file = pwa_logo_file(site_setting('site_logo', site_setting('logo')));
    $info = $file !== null ? @getimagesize($file) : false;
    if (!in_array($size, [180, 192, 512], true) || !$info
        || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
        || $info[0] * $info[1] > 16000000 || !function_exists('imagecreatefromstring')) return null;

    $source = @imagecreatefromstring((string)file_get_contents($file));
    if ($source === false) return null;

    // Keep rectangular logos intact, centered on an opaque square for home screens.
    $icon = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($icon, 255, 255, 255);
    imagefill($icon, 0, 0, $white);
    $scale = ($size * 0.8) / max($info[0], $info[1]);
    $width = max(1, (int)round($info[0] * $scale));
    $height = max(1, (int)round($info[1] * $scale));
    imagecopyresampled($icon, $source, (int)(($size - $width) / 2), (int)(($size - $height) / 2),
        0, 0, $width, $height, $info[0], $info[1]);
    ob_start();
    imagepng($icon);
    $png = ob_get_clean();
    imagedestroy($source);
    imagedestroy($icon);
    return $png;
}
