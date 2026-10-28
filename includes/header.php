<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

requireLogin();
enforce_current_page_permission();

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cms_table_exists')) {
    function cms_table_exists(mysqli $conn, string $table): bool {
        $safeTable = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

        if (!$res) {
            return false;
        }

        return $res->num_rows > 0;
    }
}

if (!function_exists('cms_setting')) {
    function cms_setting(mysqli $conn, string $key, string $default = ''): string {
        static $settingsTable = null;

        if ($settingsTable === null) {
            if (cms_table_exists($conn, 'site_settings')) {
                $settingsTable = 'site_settings';
            } elseif (cms_table_exists($conn, 'settings')) {
                $settingsTable = 'settings';
            } else {
                $settingsTable = '';
            }
        }

        if ($settingsTable === '') {
            return $default;
        }

        $stmt = $conn->prepare("
            SELECT setting_value
            FROM `$settingsTable`
            WHERE setting_key = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param("s", $key);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        $stmt->close();

        return !empty($row['setting_value'])
            ? (string)$row['setting_value']
            : $default;
    }
}

if (!function_exists('cms_asset_url')) {
    function cms_asset_url(string $path): string {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
}

$siteName = APP_NAME;
$faviconPath = '';
$siteLogo = '';

if (isset($conn) && $conn instanceof mysqli) {
    $siteName = cms_setting($conn, 'site_name', $siteName);

    $faviconPath = cms_setting($conn, 'site_favicon', '');
    if ($faviconPath === '') {
        $faviconPath = cms_setting($conn, 'favicon', '');
    }

    $siteLogo = cms_setting($conn, 'site_logo', '');
    if ($siteLogo === '') {
        $siteLogo = cms_setting($conn, 'logo', '');
    }
}

$faviconUrl = cms_asset_url($faviconPath);
$logoUrl = cms_asset_url($siteLogo);

$pageTitleText = isset($pageTitle) && trim((string)$pageTitle) !== ''
    ? trim((string)$pageTitle) . ' | ' . $siteName
    : $siteName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= h($pageTitleText) ?></title>

<?php if ($faviconUrl !== ''): ?>
<link rel="icon" href="<?= h($faviconUrl) ?>">
<link rel="shortcut icon" href="<?= h($faviconUrl) ?>">
<?php endif; ?>

<meta name="theme-color" content="#0d6efd">

<?php if ($logoUrl !== ''): ?>
<meta property="og:image" content="<?= h($logoUrl) ?>">
<?php endif; ?>

<link rel="stylesheet" href="<?= h(BASE_URL . 'assets/css/style.css?v=' . (int) @filemtime(__DIR__ . '/../assets/css/style.css')) ?>">
<?php
$themeColors = [
    'primary' => cms_setting($conn, 'primary_color', '#4a1e8f'),
    'accent' => cms_setting($conn, 'secondary_color', '#d4a017'),
    'bg' => cms_setting($conn, 'background_color', '#f3f2f8'),
    'text' => cms_setting($conn, 'text_color', '#1e1e2d'),
];
foreach ($themeColors as $key => $value) {
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        $themeColors[$key] = ['primary' => '#4a1e8f', 'accent' => '#d4a017', 'bg' => '#f3f2f8', 'text' => '#1e1e2d'][$key];
    }
}
$adjustThemeColor = static function (string $hex, int $percent): string {
    $rgb = array_map('hexdec', str_split(substr($hex, 1), 2));
    foreach ($rgb as &$channel) {
        $channel = (int) round($channel + ($percent / 100) * ($percent < 0 ? $channel : 255 - $channel));
    }
    unset($channel);
    return sprintf('#%02x%02x%02x', ...$rgb);
};
?>
<style>:root{--primary:<?= h($themeColors['primary']) ?>;--primary-dk:<?= h($adjustThemeColor($themeColors['primary'], -30)) ?>;--primary-lt:<?= h($adjustThemeColor($themeColors['primary'], 20)) ?>;--primary-fade:color-mix(in srgb, <?= h($themeColors['primary']) ?> 8%, transparent);--accent:<?= h($themeColors['accent']) ?>;--accent-lt:<?= h($adjustThemeColor($themeColors['accent'], 20)) ?>;--bg:<?= h($themeColors['bg']) ?>;--text:<?= h($themeColors['text']) ?>}</style>
<link rel="manifest" href="<?= h(BASE_URL . 'manifest.webmanifest') ?>">
<meta name="theme-color" content="<?= h($themeColors['primary'] ?? '#4a1e8f') ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="<?= h(BASE_URL . 'assets/img/pwa-192.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>

<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="cms-layout">
