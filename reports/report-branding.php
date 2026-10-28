<?php
/** Apply configured church branding to a standalone printable report document. */
function report_brand_html(string $html): string
{
    global $conn;
    $name = defined('APP_NAME') ? APP_NAME : 'Church Management System';
    $logo = '';

    if (isset($conn) && $conn instanceof mysqli) {
        $result = @$conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name','site_logo')");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if ($row['setting_key'] === 'site_name' && trim((string)$row['setting_value']) !== '') $name = trim($row['setting_value']);
                if ($row['setting_key'] === 'site_logo') $logo = trim((string)$row['setting_value']);
            }
        }
    }

    $logoData = '';
    if ($logo !== '' && !preg_match('~^https?://~i', $logo)) {
        $path = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $logo), '/'));
        if ($path && is_file($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][$extension] ?? '';
            if ($mime !== '') $logoData = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
        }
    }
    // Dompdf can fetch remote logos when enabled; printable browser reports use the configured URL too.
    if ($logoData === '' && preg_match('~^https?://~i', $logo)) $logoData = $logo;

    $primary = function_exists('site_brand') ? site_brand()['primary'] : '#4a1e8f';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#4a1e8f';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $logoHtml = $logoData !== '' ? '<img class="report-brand-logo" src="' . htmlspecialchars($logoData, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeName . ' logo">' : '';
    $watermark = $logoData !== '' ? '<div class="report-brand-watermark"><img src="' . htmlspecialchars($logoData, ENT_QUOTES, 'UTF-8') . '" alt=""></div>' : '';
    $title = '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match)) $title = trim(strip_tags($match[1]));
    $header = '<div class="report-brand-header">' . $logoHtml . '<div><div class="report-brand-name">' . $safeName . '</div><div class="report-brand-subtitle">' . htmlspecialchars($title ?: 'Official Report', ENT_QUOTES, 'UTF-8') . '</div></div></div>';
    $styles = '<style>.report-brand-header{display:flex;align-items:center;gap:12px;padding:0 0 12px;margin:0 0 16px;border-bottom:2px solid ' . $primary . ';position:relative;z-index:1}.report-brand-logo{width:auto;height:auto;max-width:78px;max-height:66px;object-fit:contain}.report-brand-name{font-size:19px;font-weight:700;color:' . $primary . '}.report-brand-subtitle{font-size:11px;color:#666;margin-top:3px}.report-brand-watermark{position:fixed;top:30%;left:20%;width:60%;height:40%;text-align:center;z-index:-1;opacity:.055}.report-brand-watermark img{max-width:100%;max-height:100%;object-fit:contain}@media print{.report-brand-watermark{position:fixed}}@media screen{.report-brand-watermark{pointer-events:none}}</style>';

    if (stripos($html, '</head>') !== false) $html = preg_replace('~</head>~i', $styles . '</head>', $html, 1);
    if (preg_match('~<body([^>]*)>~i', $html, $body, PREG_OFFSET_CAPTURE)) {
        $position = $body[0][1] + strlen($body[0][0]);
        $html = substr_replace($html, $watermark . $header, $position, 0);
    }
    return $html;
}
