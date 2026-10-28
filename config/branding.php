<?php
/**
 * Church branding from Settings (site_settings), shared by the whole system.
 *
 * Loaded by config/db.php, so any script with a database connection can use it.
 * APP_NAME is defined from the Settings church name so older code that prints APP_NAME follows Settings too.
 */

if (!function_exists('site_setting')) {
    /** One site_settings value, or $default when it is missing or empty. */
    function site_setting(string $key, string $default = '', ?mysqli $db = null): string
    {
        static $settings = null, $link = null;
        if ($db !== null) $link = $db;
        if ($settings === null) {
            $conn = $link ?? ($GLOBALS['conn'] ?? null);
            if (!($conn instanceof mysqli)) return $default;
            $settings = [];
            try {
                $res = $conn->query("SELECT setting_key, setting_value FROM site_settings");
                while ($res && $row = $res->fetch_assoc()) $settings[$row['setting_key']] = trim((string)$row['setting_value']);
                if ($res) $res->close();
            } catch (Throwable $e) {
                // No settings table yet: every value falls back to its default.
            }
        }
        $value = $settings[$key] ?? '';
        return $value !== '' ? $value : $default;
    }

    /** Name, subtitle, logo, favicon and theme colours as set in Settings. */
    function site_brand(): array
    {
        return [
            'name'       => site_setting('site_name', 'Church Management System'),
            'subtitle'   => site_setting('site_subtitle'),
            'logo'       => site_setting('site_logo', site_setting('logo', 'assets/img/logo.png')),
            'favicon'    => site_setting('site_favicon', site_setting('favicon')),
            'primary'    => site_setting('primary_color', '#4a1e8f'),
            'secondary'  => site_setting('secondary_color', '#d4a017'),
            'background' => site_setting('background_color', '#f3f2f8'),
            'text'       => site_setting('text_color', '#1e1e2d'),
        ];
    }

    /**
     * The Settings theme colours as CSS variables, for any page's <head> (after its stylesheets).
     * Logged-in pages get this through includes/header.php; standalone public pages print it themselves.
     */
    function site_theme_css(): string
    {
        $brand = site_brand();
        $defaults = ['primary' => '#4a1e8f', 'secondary' => '#d4a017', 'background' => '#f3f2f8', 'text' => '#1e1e2d'];
        foreach ($defaults as $key => $default) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand[$key])) $brand[$key] = $default;
        }
        $shade = static function (string $hex, int $percent): string {
            $rgb = array_map('hexdec', str_split(substr($hex, 1), 2));
            foreach ($rgb as &$channel) $channel = (int)round($channel + ($percent / 100) * ($percent < 0 ? $channel : 255 - $channel));
            unset($channel);
            return sprintf('#%02x%02x%02x', ...$rgb);
        };
        $p = $brand['primary'];
        $a = $brand['secondary'];
        return '<style>:root{--primary:' . $p . ';--primary-dk:' . $shade($p, -30) . ';--primary-lt:' . $shade($p, 20)
            . ';--primary-fade:color-mix(in srgb,' . $p . ' 8%,transparent);--accent:' . $a . ';--accent-lt:' . $shade($a, 20)
            . ';--bg:' . $brand['background'] . ';--text:' . $brand['text'] . '}</style>';
    }

    /** The church name from Settings. */
    function site_name(): string
    {
        return site_brand()['name'];
    }
}

// Remember db.php's connection, which is in scope here even when db.php is loaded inside a function.
if (isset($conn) && $conn instanceof mysqli) site_setting('site_name', '', $conn);
if (!defined('APP_NAME')) define('APP_NAME', site_name());
