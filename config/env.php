<?php
/**
 * Loads settings from the .env file at the site root (see .env.example).
 * Real server environment variables take precedence over values in .env.
 *
 * Usage: env('DB_HOST'), env('APP_URL', 'https://example.com')
 */

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        static $values = null;

        if ($values === null) {
            $values = [];
            $envFile = dirname(__DIR__) . '/.env';
            if (is_readable($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    [$name, $value] = array_map('trim', explode('=', $line, 2));
                    if (strncmp($name, 'export ', 7) === 0) $name = trim(substr($name, 7));
                    if ($name === '') continue;
                    $quote = $value[0] ?? '';
                    if (strlen($value) > 1 && ($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                        $value = substr($value, 1, -1);
                    }
                    $values[$name] = $value;
                }
            }
        }

        $server = getenv($key);
        if ($server !== false && $server !== '') return $server;
        if (isset($values[$key]) && $values[$key] !== '') return $values[$key];
        return $default;
    }
}
