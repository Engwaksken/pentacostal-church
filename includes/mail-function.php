<?php
declare(strict_types=1);

/**
 * Central email helper for the Church Management System.
 *
 * SMTP configuration is loaded from the email_settings table:
 * id, smtp_host, smtp_port, smtp_user, smtp_password, smtp_secure,
 * from_email, from_name, is_active, created_at, updated_at.
 *
 * Expected usage:
 * sendEmail($to, $subject, $htmlBody, $altBody = null, $cc = [], $bcc = [], $attachments = [])
 */

if (!function_exists('mail_log_error')) {
    function mail_log_error(string $message): void
    {
        error_log('[mail-function.php] ' . $message);
    }
}

if (!function_exists('mail_load_phpmailer')) {
    function mail_load_phpmailer(): bool
    {
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return true;
        }

        $autoloadCandidates = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/\\') . '/vendor/autoload.php' : ''),
            (!empty($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/vendor/autoload.php' : ''),
        ];

        foreach ($autoloadCandidates as $autoload) {
            if ($autoload !== '' && is_file($autoload) && is_readable($autoload)) {
                require_once $autoload;
                if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                    return true;
                }
            }
        }

        // Fallback for projects where PHPMailer was copied manually.
        $manualCandidates = [
            dirname(__DIR__) . '/PHPMailer/src',
            dirname(__DIR__) . '/phpmailer/src',
            __DIR__ . '/PHPMailer/src',
            dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src',
            dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src',
        ];

        foreach ($manualCandidates as $dir) {
            $dir = rtrim($dir, '/\\');
            $exception = $dir . '/Exception.php';
            $phpmailer = $dir . '/PHPMailer.php';
            $smtp = $dir . '/SMTP.php';

            if (is_file($exception) && is_file($phpmailer) && is_file($smtp)) {
                require_once $exception;
                require_once $phpmailer;
                require_once $smtp;

                if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('mail_db_connection')) {
    function mail_db_connection(): ?mysqli
    {
        global $conn;

        if (isset($conn) && $conn instanceof mysqli) {
            return $conn;
        }

        return null;
    }
}

if (!function_exists('getEmailSettings')) {
    /**
     * Return the newest active SMTP configuration.
     */
    function getEmailSettings(?mysqli $connection = null): array
    {
        $db = $connection instanceof mysqli ? $connection : mail_db_connection();

        if (!$db instanceof mysqli) {
            mail_log_error('Database connection is unavailable while loading email settings.');
            return [];
        }

        $sql = "SELECT
                    id,
                    smtp_host,
                    smtp_port,
                    smtp_user,
                    smtp_password,
                    smtp_secure,
                    from_email,
                    from_name,
                    is_active,
                    created_at,
                    updated_at
                FROM email_settings
                WHERE is_active = 1
                ORDER BY id DESC
                LIMIT 1";

        try {
            $result = $db->query($sql);
        } catch (Throwable $e) {
            mail_log_error('Unable to read email_settings: ' . $e->getMessage());
            return [];
        }

        if (!$result instanceof mysqli_result || $result->num_rows === 0) {
            mail_log_error('No active record was found in email_settings.');
            return [];
        }

        $settings = $result->fetch_assoc() ?: [];
        $result->free();

        $settings['smtp_host'] = trim((string)($settings['smtp_host'] ?? ''));
        $settings['smtp_port'] = (int)($settings['smtp_port'] ?? 0);
        $settings['smtp_user'] = trim((string)($settings['smtp_user'] ?? ''));
        $settings['smtp_password'] = (string)($settings['smtp_password'] ?? '');
        $settings['smtp_secure'] = strtolower(trim((string)($settings['smtp_secure'] ?? '')));
        $settings['from_email'] = trim((string)($settings['from_email'] ?? ''));
        $settings['from_name'] = trim((string)($settings['from_name'] ?? ''));

        if ($settings['smtp_host'] === '') {
            mail_log_error('smtp_host is empty in email_settings.');
            return [];
        }

        if ($settings['smtp_port'] <= 0) {
            // Common defaults based on the selected encryption method.
            $settings['smtp_port'] = in_array($settings['smtp_secure'], ['ssl', 'smtps'], true) ? 465 : 587;
        }

        if (!filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
            mail_log_error('from_email is missing or invalid in email_settings.');
            return [];
        }

        if ($settings['from_name'] === '') {
            $settings['from_name'] = $settings['from_email'];
        }

        $settings = mail_normalize_smtp_settings($settings);

        if ($settings['smtp_host'] === '') {
            mail_log_error('smtp_host became empty after normalization.');
            return [];
        }

        return $settings;
    }
}

if (!function_exists('mail_brand_system_html')) {
    function mail_brand_system_html(string $html): string
    {
        $db = mail_db_connection();
        $name = defined('APP_NAME') ? APP_NAME : 'Church';
        $logo = '';
        $brand = function_exists('site_brand') ? site_brand() : [];
        $colors = [
            'primary' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($brand['primary'] ?? '')) ? $brand['primary'] : '#4a1e8f',
            'secondary' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($brand['secondary'] ?? '')) ? $brand['secondary'] : '#d4a017',
            'background' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($brand['background'] ?? '')) ? $brand['background'] : '#f3f2f8',
            'text' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($brand['text'] ?? '')) ? $brand['text'] : '#1e1e2d',
        ];
        if ($db instanceof mysqli) {
            try {
                $result = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name','site_logo')");
                if ($result) while ($row = $result->fetch_assoc()) {
                    if ($row['setting_key'] === 'site_name' && trim((string)$row['setting_value']) !== '') $name = trim((string)$row['setting_value']);
                    if ($row['setting_key'] === 'site_logo') $logo = trim((string)$row['setting_value']);
                }
            } catch (Throwable $e) { /* Continue with configured defaults. */ }
        }
        if ($logo !== '' && !preg_match('~^https?://~i', $logo)) {
            $logo = rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/' . ltrim($logo, '/');
        }
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $logoHtml = $logo !== '' ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeName . '" style="display:block;max-width:160px;max-height:64px;margin:0 auto 12px">' : '';
        return '<div style="font-family:Arial,sans-serif;background:' . $colors['background'] . ';padding:24px"><div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid ' . $colors['secondary'] . ';border-radius:14px;overflow:hidden"><div style="padding:20px 24px;text-align:center;background:' . $colors['primary'] . ';color:#fff">' . $logoHtml . '<strong style="font-size:20px">' . $safeName . '</strong></div><div style="padding:24px;color:' . $colors['text'] . ';line-height:1.6">' . $html . '</div><div style="padding:14px 24px;background:' . $colors['secondary'] . ';color:' . $colors['text'] . ';text-align:center;font-size:12px">Sent by ' . $safeName . '</div></div></div>';
    }
}

if (!function_exists('mail_normalize_smtp_settings')) {
    /**
     * Normalize host, encryption and port so values saved by the settings page
     * are safe to pass to PHPMailer.
     */
    function mail_normalize_smtp_settings(array $settings): array
    {
        $host = trim((string)($settings['smtp_host'] ?? ''));
        $port = (int)($settings['smtp_port'] ?? 0);
        $secure = strtolower(trim((string)($settings['smtp_secure'] ?? '')));

        // PHPMailer expects Host to be only the hostname. Encryption is set
        // separately through SMTPSecure.
        foreach (['ssl://', 'tls://', 'smtps://', 'smtp://'] as $prefix) {
            if (str_starts_with(strtolower($host), $prefix)) {
                if ($secure === '') {
                    $secure = match ($prefix) {
                        'ssl://', 'smtps://' => 'ssl',
                        'tls://' => 'tls',
                        default => '',
                    };
                }
                $host = substr($host, strlen($prefix));
                break;
            }
        }

        $host = trim($host, " \t\n\r\0\x0B/");

        // Accept common aliases from settings forms.
        if (in_array($secure, ['starttls', 'tls', 'yes', 'true'], true)) {
            $secure = 'tls';
        } elseif (in_array($secure, ['ssl', 'smtps'], true)) {
            $secure = 'ssl';
        } elseif (in_array($secure, ['none', 'off', 'no', 'false', '0'], true)) {
            $secure = '';
        }

        // If encryption was omitted, infer only the standard unambiguous
        // combinations. Explicit database values still take priority.
        if ($secure === '') {
            if ($port === 465) {
                $secure = 'ssl';
            } elseif ($port === 587) {
                $secure = 'tls';
            }
        }

        if ($port <= 0) {
            $port = $secure === 'ssl' ? 465 : 587;
        }

        $settings['smtp_host'] = $host;
        $settings['smtp_port'] = $port;
        $settings['smtp_secure'] = $secure;

        return $settings;
    }
}

if (!function_exists('mail_probe_smtp_endpoint')) {
    /**
     * Test whether PHP can open a TCP connection to the SMTP endpoint.
     * This does not authenticate and never sends credentials.
     *
     * @return array{success:bool,message:string,errno:int}
     */
    function mail_probe_smtp_endpoint(string $host, int $port, int $timeout = 8): array
    {
        $host = trim($host);
        if ($host === '' || $port <= 0) {
            return [
                'success' => false,
                'message' => 'SMTP host or port is empty.',
                'errno' => 0,
            ];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (is_resource($socket)) {
            fclose($socket);
            return [
                'success' => true,
                'message' => "TCP connection to {$host}:{$port} succeeded.",
                'errno' => 0,
            ];
        }

        $message = trim($errstr);
        if ($message === '') {
            $message = 'Unknown TCP connection failure.';
        }

        return [
            'success' => false,
            'message' => "TCP connection to {$host}:{$port} failed" .
                ($errno > 0 ? " (errno {$errno})" : '') . ": {$message}",
            'errno' => (int)$errno,
        ];
    }
}

if (!function_exists('mail_normalize_addresses')) {
    /**
     * @return array<int, array{email:string,name:string}>
     */
    function mail_normalize_addresses(string|array $addresses): array
    {
        if (is_string($addresses)) {
            $addresses = [$addresses];
        }

        $normalized = [];

        foreach ($addresses as $key => $value) {
            $email = '';
            $name = '';

            if (is_array($value)) {
                $email = trim((string)($value['email'] ?? $value[0] ?? ''));
                $name = trim((string)($value['name'] ?? $value[1] ?? ''));
            } elseif (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $email = trim($key);
                $name = trim((string)$value);
            } else {
                $email = trim((string)$value);
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $normalized[] = ['email' => $email, 'name' => $name];
            }
        }

        return $normalized;
    }
}

if (!function_exists('sendEmail')) {
    /**
     * Send an email through the active SMTP configuration.
     *
     * Returns true when successful, otherwise a user-safe error string.
     */
    function sendEmail(
        string|array $to,
        string $subject,
        string $body,
        ?string $altBody = null,
        string|array $cc = [],
        string|array $bcc = [],
        array $attachments = []
    ): bool|string {
        if (!mail_load_phpmailer()) {
            mail_log_error('PHPMailer could not be loaded.');
            return 'The email service is not available because PHPMailer is not installed.';
        }

        $settings = getEmailSettings();
        if ($settings === []) {
            return 'Email settings are not configured or no active SMTP account was found.';
        }

        $recipients = mail_normalize_addresses($to);
        if ($recipients === []) {
            return 'No valid recipient email address was provided.';
        }

        $subject = trim($subject);
        if ($subject === '') {
            return 'Email subject is required.';
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = (string)$settings['smtp_host'];
            $mail->Port = (int)$settings['smtp_port'];
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 30;
            $mail->Timelimit = 30;
            $mail->SMTPDebug = 0;

            $smtpUser = (string)$settings['smtp_user'];
            $smtpPassword = (string)$settings['smtp_password'];

            $mail->SMTPAuth = $smtpUser !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPassword;
            }

            $secure = strtolower((string)$settings['smtp_secure']);
            if ($secure === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = false;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAutoTLS = true;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            mail_log_error(
                'SMTP attempt: host=' . $mail->Host .
                ', port=' . $mail->Port .
                ', secure=' . ($secure !== '' ? $secure : 'none') .
                ', auth=' . ($mail->SMTPAuth ? 'yes' : 'no') .
                ', user=' . ($smtpUser !== '' ? $smtpUser : '(empty)') .
                ', from=' . (string)$settings['from_email']
            );

            $mail->setFrom(
                (string)$settings['from_email'],
                (string)$settings['from_name']
            );

            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient['email'], $recipient['name']);
            }

            foreach (mail_normalize_addresses($cc) as $recipient) {
                $mail->addCC($recipient['email'], $recipient['name']);
            }

            foreach (mail_normalize_addresses($bcc) as $recipient) {
                $mail->addBCC($recipient['email'], $recipient['name']);
            }

            foreach ($attachments as $attachment) {
                $path = '';
                $name = '';

                if (is_string($attachment)) {
                    $path = trim($attachment);
                } elseif (is_array($attachment)) {
                    $path = trim((string)($attachment['path'] ?? ''));
                    $name = trim((string)($attachment['name'] ?? ''));
                }

                if ($path !== '' && is_file($path) && is_readable($path)) {
                    $mail->addAttachment($path, $name !== '' ? $name : basename($path));
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = mail_brand_system_html($body);
            $mail->AltBody = $altBody !== null && trim($altBody) !== ''
                ? $altBody
                : trim(html_entity_decode(strip_tags(str_replace(
                    ['<br>', '<br/>', '<br />', '</p>'],
                    ["\n", "\n", "\n", "\n"],
                    $body
                )), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $mail->send();
            return true;
        } catch (Throwable $e) {
            $details = trim($e->getMessage());
            if (trim((string)$mail->ErrorInfo) !== '') {
                $details .= ($details !== '' ? ' | ' : '') . trim((string)$mail->ErrorInfo);
            }

            mail_log_error('SMTP send failed: ' . ($details !== '' ? $details : 'Unknown error'));

            $probe = mail_probe_smtp_endpoint(
                (string)$settings['smtp_host'],
                (int)$settings['smtp_port']
            );
            mail_log_error('SMTP connectivity test: ' . $probe['message']);

            if (!$probe['success']) {
                return 'Unable to connect to the configured SMTP server. Check the SMTP host, port, encryption, DNS and whether your hosting provider allows outbound SMTP connections.';
            }

            return 'The SMTP server is reachable, but email sending failed. Check the SMTP username/password, encryption method and sender address.';
        }
    }
}
