<?php
declare(strict_types=1);


while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@set_time_limit(25);

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (file_exists('../config/mail-function.php')) { require_once '../config/mail-function.php'; }
if (file_exists('../config/sms-function.php')) { require_once '../config/sms-function.php'; }

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}
$conn->set_charset('utf8mb4');

function bm_out(array $data): void { echo json_encode($data); exit; }
function bm_s($v): string { return trim((string)($v ?? '')); }
function bm_now(): string { return date('Y-m-d H:i:s'); }

function bm_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function bm_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function bm_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function bm_ensure_tables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS bulk_message_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel ENUM('email','sms') NOT NULL,
        recipient_group VARCHAR(80) NOT NULL,
        department_id INT NULL,
        subject VARCHAR(190) NULL,
        message MEDIUMTEXT NOT NULL,
        total_recipients INT NOT NULL DEFAULT 0,
        sent_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        skipped_count INT NOT NULL DEFAULT 0,
        status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
        created_by INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        completed_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!bm_col_exists($conn, 'bulk_message_campaigns', 'department_id')) {
        $conn->query("ALTER TABLE bulk_message_campaigns ADD COLUMN department_id INT NULL AFTER recipient_group");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS bulk_message_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        recipient_name VARCHAR(190) NULL,
        recipient_email VARCHAR(190) NULL,
        recipient_phone VARCHAR(50) NULL,
        group_name VARCHAR(100) NULL,
        status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_campaign_status (campaign_id, status),
        CONSTRAINT bulk_message_queue_campaign_fk FOREIGN KEY (campaign_id) REFERENCES bulk_message_campaigns(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function bm_normalize_phone(string $phone): string {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (preg_match('/^0[0-9]{9}$/', $phone)) return '+256' . substr($phone, 1);
    if (preg_match('/^256[0-9]{9}$/', $phone)) return '+' . $phone;
    return $phone;
}

function bm_add_unique(array &$items, array $row): void {
    $name  = trim((string)($row['name'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $phone = trim((string)($row['phone'] ?? ''));
    if ($name === '' && $email === '' && $phone === '') return;

    $key = strtolower($email !== '' ? 'e:' . $email : ($phone !== '' ? 'p:' . bm_normalize_phone($phone) : 'n:' . $name));
    foreach ($items as $x) {
        $xEmail = trim((string)($x['email'] ?? ''));
        $xPhone = trim((string)($x['phone'] ?? ''));
        $xName  = trim((string)($x['name'] ?? ''));
        $xKey = strtolower($xEmail !== '' ? 'e:' . $xEmail : ($xPhone !== '' ? 'p:' . bm_normalize_phone($xPhone) : 'n:' . $xName));
        if ($xKey === $key) return;
    }
    $items[] = [
        'name' => $name ?: 'Member',
        'email' => $email,
        'phone' => bm_normalize_phone($phone),
        'group_name' => (string)($row['group_name'] ?? 'Member')
    ];
}

function bm_members(mysqli $conn, string $groupName = 'Member', string $extraWhere = '', string $types = '', array $params = []): array {
    if (!bm_table_exists($conn, 'members')) return [];
    $where = "WHERE COALESCE(m.status,'Active')='Active'";
    if ($extraWhere !== '') $where .= ' AND ' . $extraWhere;

    $sql = "SELECT m.full_name AS name,
                   m.email, m.phone, ? AS group_name
            FROM members m
            {$where}
            ORDER BY m.full_name";
    return bm_fetch_all($conn, $sql, 's' . $types, array_merge([$groupName], $params));
}

function bm_recipients(mysqli $conn, string $group, int $departmentId = 0): array {
    $items = [];

    if ($group === 'all') return bm_members($conn, 'All Members');

    if ($group === 'department') {
        if ($departmentId <= 0 || !bm_table_exists($conn, 'department_members')) return [];
        return bm_members($conn, 'Department', 'm.id IN (SELECT member_id FROM department_members WHERE dept_id = ?)', 'i', [$departmentId]);
    }

    if ($group === 'youth') {
        if (!bm_table_exists($conn, 'youth') || !bm_table_exists($conn, 'members')) return [];
        return bm_fetch_all($conn, "SELECT m.full_name AS name,
                                            m.email, m.phone, 'Youth' AS group_name
                                     FROM youth y
                                     JOIN members m ON m.id = y.member_id
                                     WHERE COALESCE(y.status,'Active')='Active'
                                     ORDER BY m.full_name");
    }

    if ($group === 'sunday_school_teachers') {
        if (!bm_table_exists($conn, 'sunday_school_classes') || !bm_table_exists($conn, 'members') || !bm_col_exists($conn, 'sunday_school_classes', 'teacher_id')) return [];
        return bm_fetch_all($conn, "SELECT DISTINCT m.full_name AS name,
                                            m.email, m.phone, 'Sunday School Teacher' AS group_name
                                     FROM sunday_school_classes c
                                     JOIN members m ON m.id = c.teacher_id
                                     WHERE c.teacher_id IS NOT NULL
                                     ORDER BY m.full_name");
    }

    if ($group === 'sunday_school_parents') {
        if (!bm_table_exists($conn, 'sunday_school_pupils')) return [];
        if (bm_col_exists($conn, 'sunday_school_pupils', 'parent_member_id') && bm_table_exists($conn, 'members')) {
            $rows = bm_fetch_all($conn, "SELECT DISTINCT COALESCE(NULLIF(p.parent_guardian,''), m.full_name) AS name,
                                                 m.email,
                                                 COALESCE(NULLIF(p.parent_phone,''), m.phone) AS phone,
                                                 'Sunday School Parent' AS group_name
                                          FROM sunday_school_pupils p
                                          LEFT JOIN members m ON m.id = p.parent_member_id
                                          WHERE COALESCE(p.status,'Active')='Active'
                                            AND (m.id IS NOT NULL OR COALESCE(p.parent_phone,'') <> '')");
            foreach ($rows as $r) bm_add_unique($items, $r);
        }
        $rows = bm_fetch_all($conn, "SELECT parent_guardian AS name, '' AS email, parent_phone AS phone, 'Sunday School Parent' AS group_name
                                     FROM sunday_school_pupils
                                     WHERE COALESCE(status,'Active')='Active' AND COALESCE(parent_phone,'') <> ''");
        foreach ($rows as $r) bm_add_unique($items, $r);
        return $items;
    }

    return [];
}


function bm_decode_selected_recipients(string $json): array {
    if ($json === '') return [];
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    $items = [];
    foreach ($data as $r) {
        if (!is_array($r)) continue;
        bm_add_unique($items, [
            'name' => bm_s($r['name'] ?? 'Member') ?: 'Member',
            'email' => bm_s($r['email'] ?? ''),
            'phone' => bm_s($r['phone'] ?? ''),
            'group_name' => bm_s($r['group_name'] ?? 'Member') ?: 'Member'
        ]);
    }
    return $items;
}

function bm_send_sms(mysqli $conn, string $phone, string $message): bool {
    $phone = bm_normalize_phone($phone);
    if ($phone === '') return false;
    if (function_exists('sendSms')) return (bool)sendSms($conn, $phone, $message);
    if (function_exists('sendSystemSms')) return (bool)sendSystemSms($conn, $phone, $message);
    if (function_exists('sendSMS')) return (bool)sendSMS($conn, $phone, $message);
    throw new RuntimeException('SMS helper function not found.');
}

function bm_send_email(mysqli $conn, string $email, string $name, string $subject, string $message): bool {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (!function_exists('sendSystemEmail')) throw new RuntimeException('Email helper function sendSystemEmail() not found.');

    $safeName = htmlspecialchars($name ?: 'Member', ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $html = "<div style='font-family:Arial,sans-serif;line-height:1.6'>
                <p>Dear <strong>{$safeName}</strong>,</p>
                <div>{$safeMessage}</div>
                <p>Regards,<br><strong>" . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . "</strong></p>
             </div>";
    return sendSystemEmail($conn, $email, $name ?: 'Member', $subject, $html, $message);
}

function bm_campaign_summary(mysqli $conn, int $campaignId): array {
    $stmt = $conn->prepare("SELECT total_recipients, sent_count, failed_count, skipped_count, status FROM bulk_message_campaigns WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

try {
    bm_ensure_tables($conn);

    $action = bm_s($_POST['action'] ?? $_GET['action'] ?? 'preview');
    $group = bm_s($_POST['recipient_group'] ?? $_GET['recipient_group'] ?? 'all');
    $departmentId = (int)($_POST['department_id'] ?? $_GET['department_id'] ?? 0);

    if ($action === 'preview') {
        $items = bm_recipients($conn, $group, $departmentId);
        bm_out(['success' => true, 'items' => $items]);
    }

    if ($action === 'create_campaign') {
        $channel = bm_s($_POST['channel'] ?? 'email');
        $subject = bm_s($_POST['subject'] ?? 'Church Announcement');
        $message = bm_s($_POST['message'] ?? '');

        if (!in_array($channel, ['email', 'sms'], true)) bm_out(['success' => false, 'message' => 'Invalid channel.']);
        if ($message === '') bm_out(['success' => false, 'message' => 'Message is required.']);
        if ($channel === 'email' && $subject === '') bm_out(['success' => false, 'message' => 'Email subject is required.']);
        if (!in_array($group, ['all', 'department', 'youth', 'sunday_school_teachers', 'sunday_school_parents'], true)) bm_out(['success' => false, 'message' => 'Invalid recipient group.']);
        if ($group === 'department' && $departmentId <= 0) bm_out(['success' => false, 'message' => 'Please select a department.']);

        $selectedJson = bm_s($_POST['selected_recipients'] ?? '');
        $selectedRecipients = bm_decode_selected_recipients($selectedJson);
        $recipients = $selectedRecipients ?: bm_recipients($conn, $group, $departmentId);
        if (!$recipients) bm_out(['success' => false, 'message' => 'No recipients found or selected.']);

        $createdBy = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
        $now = bm_now();
        $status = 'queued';
        $total = 0;

        $stmt = $conn->prepare("INSERT INTO bulk_message_campaigns (channel, recipient_group, department_id, subject, message, status, created_by, created_at, updated_at) VALUES (?, ?, NULLIF(?,0), ?, ?, ?, NULLIF(?,0), ?, ?)");
        $stmt->bind_param('ssisssiss', $channel, $group, $departmentId, $subject, $message, $status, $createdBy, $now, $now);
        $stmt->execute();
        $campaignId = (int)$conn->insert_id;
        $stmt->close();

        $seen = [];
        $insert = $conn->prepare("INSERT INTO bulk_message_queue (campaign_id, recipient_name, recipient_email, recipient_phone, group_name, status, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($recipients as $r) {
            $name = bm_s($r['name'] ?? 'Member') ?: 'Member';
            $email = bm_s($r['email'] ?? '');
            $phone = bm_normalize_phone(bm_s($r['phone'] ?? ''));
            $groupName = bm_s($r['group_name'] ?? 'Member');

            $dedupe = strtolower($channel === 'email' ? $email : $phone);
            if ($dedupe === '' || isset($seen[$dedupe])) continue;
            $seen[$dedupe] = true;

            $rowStatus = 'queued';
            $err = '';
            if ($channel === 'email' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowStatus = 'skipped';
                $err = 'No valid email address.';
            }
            if ($channel === 'sms' && $phone === '') {
                $rowStatus = 'skipped';
                $err = 'No phone number.';
            }

            $insert->bind_param('isssssss', $campaignId, $name, $email, $phone, $groupName, $rowStatus, $err, $now);
            $insert->execute();
            $total++;
        }
        $insert->close();

        $stmt = $conn->prepare("UPDATE bulk_message_campaigns SET total_recipients = ?, skipped_count = (SELECT COUNT(*) FROM bulk_message_queue WHERE campaign_id = ? AND status = 'skipped'), updated_at = ? WHERE id = ?");
        $stmt->bind_param('iisi', $total, $campaignId, $now, $campaignId);
        $stmt->execute();
        $stmt->close();

        bm_out(['success' => true, 'message' => 'Message queue created. Sending will start now.', 'campaign_id' => $campaignId, 'summary' => bm_campaign_summary($conn, $campaignId)]);
    }

    if ($action === 'process_batch') {
        $campaignId = (int)($_POST['campaign_id'] ?? $_GET['campaign_id'] ?? 0);
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 8);
        if ($limit < 1) $limit = 5;
        if ($limit > 20) $limit = 20;
        if ($campaignId <= 0) bm_out(['success' => false, 'message' => 'Invalid campaign.']);

        $stmt = $conn->prepare("SELECT * FROM bulk_message_campaigns WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $campaign = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$campaign) bm_out(['success' => false, 'message' => 'Campaign not found.']);

        $processing = 'processing';
        $now = bm_now();
        $stmt = $conn->prepare("UPDATE bulk_message_campaigns SET status = ?, updated_at = ? WHERE id = ? AND status <> 'completed'");
        $stmt->bind_param('ssi', $processing, $now, $campaignId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT * FROM bulk_message_queue WHERE campaign_id = ? AND status = 'queued' ORDER BY id ASC LIMIT ?");
        $stmt->bind_param('ii', $campaignId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $batch = [];
        while ($res && $row = $res->fetch_assoc()) $batch[] = $row;
        $stmt->close();

        $sent = 0; $failed = 0;
        foreach ($batch as $q) {
            $ok = false;
            $error = '';
            try {
                if ($campaign['channel'] === 'email') {
                    $ok = bm_send_email($conn, (string)$q['recipient_email'], (string)$q['recipient_name'], (string)$campaign['subject'], (string)$campaign['message']);
                    if (!$ok) $error = 'Email failed or email settings not configured.';
                } else {
                    $ok = bm_send_sms($conn, (string)$q['recipient_phone'], (string)$campaign['message']);
                    if (!$ok) $error = 'SMS failed or SMS settings not configured.';
                }
            } catch (Throwable $e) {
                $ok = false;
                $error = $e->getMessage();
            }

            $rowStatus = $ok ? 'sent' : 'failed';
            $sentAt = $ok ? bm_now() : null;
            $qid = (int)$q['id'];
            $stmt = $conn->prepare("UPDATE bulk_message_queue SET status = ?, error_message = ?, sent_at = ? WHERE id = ?");
            $stmt->bind_param('sssi', $rowStatus, $error, $sentAt, $qid);
            $stmt->execute();
            $stmt->close();

            $ok ? $sent++ : $failed++;
        }

        $stmt = $conn->prepare("UPDATE bulk_message_campaigns SET
            sent_count = (SELECT COUNT(*) FROM bulk_message_queue WHERE campaign_id = ? AND status = 'sent'),
            failed_count = (SELECT COUNT(*) FROM bulk_message_queue WHERE campaign_id = ? AND status = 'failed'),
            skipped_count = (SELECT COUNT(*) FROM bulk_message_queue WHERE campaign_id = ? AND status = 'skipped'),
            updated_at = ?
            WHERE id = ?");
        $stmt->bind_param('iiisi', $campaignId, $campaignId, $campaignId, $now, $campaignId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) c FROM bulk_message_queue WHERE campaign_id = ? AND status = 'queued'");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $remaining = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        if ($remaining === 0) {
            $completed = 'completed';
            $now = bm_now();
            $stmt = $conn->prepare("UPDATE bulk_message_campaigns SET status = ?, updated_at = ?, completed_at = ? WHERE id = ?");
            $stmt->bind_param('sssi', $completed, $now, $now, $campaignId);
            $stmt->execute();
            $stmt->close();
        }

        bm_out([
            'success' => true,
            'message' => $remaining === 0 ? 'Sending completed.' : 'Batch processed.',
            'remaining' => $remaining,
            'processed_now' => count($batch),
            'batch_sent' => $sent,
            'batch_failed' => $failed,
            'summary' => bm_campaign_summary($conn, $campaignId)
        ]);
    }

    if ($action === 'campaign_status') {
        $campaignId = (int)($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) bm_out(['success' => false, 'message' => 'Invalid campaign.']);
        bm_out(['success' => true, 'summary' => bm_campaign_summary($conn, $campaignId)]);
    }

    // Backward-compatible alias: old frontends sending send_bulk now create a campaign.
    if ($action === 'send_bulk') {
        $_POST['action'] = 'create_campaign';
        bm_out(['success' => false, 'message' => 'This module now sends in batches. Please use the updated index.php included in this ZIP.']);
    }

    bm_out(['success' => false, 'message' => 'Invalid action.']);
} catch (Throwable $e) {
    error_log('Bulk messages error: ' . $e->getMessage());
    bm_out(['success' => false, 'message' => $e->getMessage()]);
}
