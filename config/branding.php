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
            . ';--bg:' . $brand['background'] . ';--text:' . $brand['text'] . '}</style>' . site_font_css();
    }

    /** Font families admins can choose on the Appearance tab. 'google' fonts are loaded from Google Fonts. */
    function site_font_options(): array
    {
        return [
            'system'          => ['label' => 'System Default (Segoe UI)', 'stack' => "'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif", 'google' => false],
            'Arial'           => ['label' => 'Arial', 'stack' => 'Arial, Helvetica, sans-serif', 'google' => false],
            'Verdana'         => ['label' => 'Verdana', 'stack' => 'Verdana, Geneva, sans-serif', 'google' => false],
            'Tahoma'          => ['label' => 'Tahoma', 'stack' => 'Tahoma, Geneva, sans-serif', 'google' => false],
            'Trebuchet MS'    => ['label' => 'Trebuchet MS', 'stack' => "'Trebuchet MS', Helvetica, sans-serif", 'google' => false],
            'Georgia'         => ['label' => 'Georgia (serif)', 'stack' => 'Georgia, serif', 'google' => false],
            'Times New Roman' => ['label' => 'Times New Roman (serif)', 'stack' => "'Times New Roman', Times, serif", 'google' => false],
            'Roboto'          => ['label' => 'Roboto', 'stack' => "'Roboto', sans-serif", 'google' => true],
            'Open Sans'       => ['label' => 'Open Sans', 'stack' => "'Open Sans', sans-serif", 'google' => true],
            'Lato'            => ['label' => 'Lato', 'stack' => "'Lato', sans-serif", 'google' => true],
            'Poppins'         => ['label' => 'Poppins', 'stack' => "'Poppins', sans-serif", 'google' => true],
            'Montserrat'      => ['label' => 'Montserrat', 'stack' => "'Montserrat', sans-serif", 'google' => true],
            'Nunito'          => ['label' => 'Nunito', 'stack' => "'Nunito', sans-serif", 'google' => true],
            'Inter'           => ['label' => 'Inter', 'stack' => "'Inter', sans-serif", 'google' => true],
            'Merriweather'    => ['label' => 'Merriweather (serif)', 'stack' => "'Merriweather', serif", 'google' => true],
            'Playfair Display'=> ['label' => 'Playfair Display (serif)', 'stack' => "'Playfair Display', serif", 'google' => true],
        ];
    }

    /** Font sizes offered in the dropdown; any whole size from 10px to 24px can be entered as a custom size. */
    function site_font_sizes(): array
    {
        return [12 => 'Small (12px)', 13 => 'Compact (13px)', 14 => 'Default (14px)', 15 => 'Comfortable (15px)', 16 => 'Large (16px)', 18 => 'Extra Large (18px)'];
    }

    /** A custom font name is limited to letters, digits, spaces and hyphens so it is safe inside CSS and a URL. */
    function site_valid_font_name(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{0,59}$/', $name);
    }

    /** The font settings, validated: ['family' => key|'custom', 'custom' => name, 'style' => normal|italic, 'size' => int]. */
    function site_font(): array
    {
        $family = site_setting('font_family', 'system');
        $custom = site_setting('font_family_custom');
        if ($family === 'custom' ? !site_valid_font_name($custom) : !isset(site_font_options()[$family])) $family = 'system';
        $style = site_setting('font_style', 'normal') === 'italic' ? 'italic' : 'normal';
        $size = (int)site_setting('font_size', '14');
        if ($size < 10 || $size > 24) $size = 14;
        return ['family' => $family, 'custom' => $family === 'custom' ? $custom : '', 'style' => $style, 'size' => $size];
    }

    /** The font settings as CSS (and a Google Fonts link when needed). Printed by site_theme_css(). */
    function site_font_css(): string
    {
        $font = site_font();
        if ($font['family'] === 'system' && $font['style'] === 'normal' && $font['size'] === 14) return '';
        $html = '';
        if ($font['family'] === 'custom') {
            $name = $font['custom'];
            $stack = "'" . $name . "', 'Segoe UI', system-ui, sans-serif";
            $google = true;
        } else {
            $option = site_font_options()[$font['family']];
            $name = $font['family'];
            $stack = $option['stack'];
            $google = $option['google'];
        }
        if ($google) {
            // Listed fonts all have regular, bold and italic faces. A custom name asks for the default face only, because Google
            // rejects requests for faces a font lacks; a name Google does not have just fails to load and the fallback is used.
            $faces = $font['family'] === 'custom' ? '' : ':ital,wght@0,400;0,700;1,400;1,700';
            $url = 'https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $name) . $faces . '&display=swap';
            $html .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        }
        // Icons keep their own icon font; everything else follows the chosen font.
        return $html . '<style>body{font-family:' . $stack . ';font-style:' . $font['style'] . ';font-size:' . $font['size'] . 'px}'
            . 'button,input,select,textarea{font-family:inherit;font-style:inherit}</style>';
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
