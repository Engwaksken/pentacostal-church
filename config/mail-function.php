<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/db.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!function_exists('mail_h')) {
    function mail_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getEmailSettings')) {
    function getEmailSettings(mysqli $conn): array
    {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'email_settings'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            throw new RuntimeException('Email settings table not found.');
        }

        $res = $conn->query("
            SELECT *
            FROM email_settings
            WHERE COALESCE(is_active, 1) = 1
            ORDER BY id DESC
            LIMIT 1
        ");

        if (!$res || $res->num_rows === 0) {
            throw new RuntimeException('Email settings not configured.');
        }

        return $res->fetch_assoc();
    }
}

if (!function_exists('createMailer')) {
    function createMailer(mysqli $conn): PHPMailer
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer not installed. Run composer require phpmailer/phpmailer');
        }

        $settings = getEmailSettings($conn);

        $mail = new PHPMailer(true);
        $mail->isSMTP();

        $mail->Host = trim((string)($settings['smtp_host'] ?? ''));
        $mail->Port = (int)($settings['smtp_port'] ?? 465);
        $mail->SMTPAuth = true;
        $mail->Username = (string)($settings['smtp_user'] ?? '');
        $mail->Password = (string)($settings['smtp_password'] ?? '');

        $secure = strtolower(trim((string)($settings['smtp_secure'] ?? 'tls')));

        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $fromEmail = trim((string)($settings['from_email'] ?? $settings['smtp_user'] ?? ''));
        $branding = system_mail_branding($conn);
        $fromName  = $branding['name'] !== '' ? $branding['name'] : trim((string)($settings['from_name'] ?? 'System Notification'));

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid sender email in email settings.');
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);

        return $mail;
    }
}

if (!function_exists('sendSystemEmail')) {
    function sendSystemEmail(
        mysqli $conn,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array $attachments = []
    ): bool {
        try {
            $toEmail = trim($toEmail);

            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            $mail = createMailer($conn);

            $mail->addAddress($toEmail, $toName ?: $toEmail);
            $mail->Subject = $subject;
            $mail->Body = brand_system_email($conn, $htmlBody);

            $mail->AltBody = $textBody !== ''
                ? $textBody
                : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

            foreach ($attachments as $file) {
                $path = is_array($file) ? ($file['path'] ?? '') : (string)$file;
                $name = is_array($file) ? ($file['name'] ?? basename($path)) : basename($path);

                if ($path !== '' && is_file($path)) {
                    $mail->addAttachment($path, $name);
                }
            }

            return $mail->send();
        } catch (Throwable $e) {
            error_log('Email sending failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('systemEmailTemplate')) {
    function systemEmailTemplate(string $title, string $bodyHtml, string $siteName = APP_NAME): string
    {
        $safeTitle = mail_h($title);
        $safeSite = mail_h($siteName);

        return "
        <div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;'>
            <div style='max-width:680px;margin:auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;'>
                <div style='background:#4a1e8f;color:#ffffff;padding:18px 24px;'>
                    <h2 style='margin:0;font-size:22px;'>{$safeTitle}</h2>
                </div>
                <div style='padding:24px;color:#111827;font-size:15px;line-height:1.6;'>
                    {$bodyHtml}
                    <p style='margin-top:24px;margin-bottom:0;'>Blessings,<br><strong>{$safeSite}</strong></p>
                </div>
            </div>
        </div>";
    }
}

if (!function_exists('sendMemberRegistrationConfirmation')) {
    function sendMemberRegistrationConfirmation(
        mysqli $conn,
        string $toEmail,
        string $memberName,
        string $memberNo = '',
        string $joinedDate = '',
        string $siteName = APP_NAME
    ): bool {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $safeName = mail_h($memberName ?: 'Member');
        $safeMemberNo = mail_h($memberNo ?: 'N/A');
        $safeJoinedDate = mail_h($joinedDate ?: date('Y-m-d'));
        $safeSiteName = mail_h($siteName);

        $subject = 'Registration Confirmation - ' . $siteName;

        $body = "
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Your membership registration has been completed successfully.</p>

            <table style='width:100%;border-collapse:collapse;margin:18px 0;'>
                <tr>
                    <td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Member No</strong></td>
                    <td style='padding:10px;border:1px solid #e5e7eb;'>{$safeMemberNo}</td>
                </tr>
                <tr>
                    <td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Joined Date</strong></td>
                    <td style='padding:10px;border:1px solid #e5e7eb;'>{$safeJoinedDate}</td>
                </tr>
            </table>

            <p>Thank you for registering with {$safeSiteName}.</p>
        ";

        $htmlBody = systemEmailTemplate('Welcome to ' . $siteName, $body, $siteName);

        $textBody = "Dear {$memberName},\n\n"
            . "Your membership registration has been completed successfully.\n\n"
            . "Member No: " . ($memberNo ?: 'N/A') . "\n"
            . "Joined Date: " . ($joinedDate ?: date('Y-m-d')) . "\n\n"
            . "Thank you for registering with {$siteName}.\n\n"
            . "{$siteName}";

        return sendSystemEmail(
            $conn,
            $toEmail,
            $memberName ?: 'Member',
            $subject,
            $htmlBody,
            $textBody
        );
    }
}

if (!function_exists('sendSimpleNotificationEmail')) {
    function sendSimpleNotificationEmail(
        mysqli $conn,
        string $toEmail,
        string $toName,
        string $subject,
        string $message,
        string $siteName = APP_NAME
    ): bool {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $safeName = mail_h($toName ?: 'Member');
        $safeMessage = nl2br(mail_h($message));

        $htmlBody = systemEmailTemplate(
            $subject,
            "<p>Dear <strong>{$safeName}</strong>,</p><p>{$safeMessage}</p>",
            $siteName
        );

        $textBody = "Dear {$toName},\n\n{$message}\n\n{$siteName}";

        return sendSystemEmail($conn, $toEmail, $toName, $subject, $htmlBody, $textBody);
    }
}

if (!function_exists('system_mail_branding')) {
    function system_mail_branding(mysqli $conn): array
    {
        $brand = ['name' => (defined('APP_NAME') ? APP_NAME : 'Church'), 'logo' => ''];
        try {
            $stmt = $conn->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name','site_logo')");
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    if ($row['setting_key'] === 'site_name' && trim((string)$row['setting_value']) !== '') $brand['name'] = trim((string)$row['setting_value']);
                    if ($row['setting_key'] === 'site_logo') $brand['logo'] = trim((string)$row['setting_value']);
                }
                $stmt->close();
            }
        } catch (Throwable $e) { /* Branding is optional; email delivery should continue. */ }
        if ($brand['logo'] !== '' && !preg_match('~^https?://~i', $brand['logo'])) {
            $base = defined('BASE_URL') ? BASE_URL : ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/');
            $brand['logo'] = rtrim($base, '/') . '/' . ltrim($brand['logo'], '/');
        }
        return $brand;
    }
}

if (!function_exists('brand_system_email')) {
    function brand_system_email(mysqli $conn, string $html): string
    {
        $brand = system_mail_branding($conn);
        $name = mail_h($brand['name']);
        $logo = $brand['logo'] !== '' ? '<img src="' . mail_h($brand['logo']) . '" alt="' . $name . '" style="display:block;max-width:160px;max-height:64px;margin:0 auto 12px">' : '';
        return '<div style="font-family:Arial,sans-serif;background:#f4f5f8;padding:24px"><div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden"><div style="padding:20px 24px;text-align:center;background:#4a1e8f;color:#fff">' . $logo . '<strong style="font-size:20px">' . $name . '</strong></div><div style="padding:24px;color:#172033;line-height:1.6">' . $html . '</div><div style="padding:14px 24px;background:#f8fafc;color:#64748b;text-align:center;font-size:12px">Sent by ' . $name . '</div></div></div>';
    }
}
