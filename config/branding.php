<?php
/**
 * Church branding from Settings (site_settings), shared by the whole system.
 *
 * Loaded by config/db.php, so any script with a database connection can use it.
 * APP_NAME is defined from the Settings church name so older code that prints APP_NAME follows Settings too.
 */

if (!function_exists('site_setting')) {
    /** One site_settings value, or $default when it is missing or empty. */
    function site_setting(string $key, string $default = ''): string
    {
        static $settings = null;
        if ($settings === null) {
            $settings = [];
            global $conn;
            if (isset($conn) && $conn instanceof mysqli) {
                try {
                    $res = $conn->query("SELECT setting_key, setting_value FROM site_settings");
                    while ($res && $row = $res->fetch_assoc()) $settings[$row['setting_key']] = trim((string)$row['setting_value']);
                    if ($res) $res->close();
                } catch (Throwable $e) {
                    // No settings table yet: every value falls back to its default.
                }
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

    /** The church name from Settings. */
    function site_name(): string
    {
        return site_brand()['name'];
    }
}

if (!defined('APP_NAME')) define('APP_NAME', site_name());
