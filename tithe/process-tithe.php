<?php
/**
 * tithe/process-tithe.php
 * AJAX backend for tithe records with payment confirmation by email and SMS.
 * Returns JSON only.
 */
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';

if (file_exists('../config/mail-function.php')) {
    require_once '../config/mail-function.php';
}
if (file_exists('../config/sms-function.php')) {
    require_once '../config/sms-function.php';
}

if (function_exists('requireLogin')) {
    requireLogin();
}

while (ob_get_level() > 1) {
    ob_end_clean();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}
$conn->set_charset('utf8mb4');

function out(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function clean_value($value): string
{
    return trim((string)($value ?? ''));
}

function table_exists(string $table): bool
{
    global $conn;
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$table] = ((int)($row['c'] ?? 0) > 0);
}

function col_exists(string $table, string $column): bool
{
    global $conn;
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function make_receipt_no(): string
{
    return 'TITHE-' . date('Ymd-His') . '-' . random_int(100, 999);
}

function get_tithe_with_member(int $id): ?array
{
    global $conn;
    $sql = "
        SELECT
            t.*,
            m.full_name,
            m.member_no,
            m.email,
            m.phone
        FROM tithe t
        LEFT JOIN members m ON m.id = t.member_id
        WHERE t.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function save_receipt_row(int $titheId, string $receiptNo, int $memberId, float $amount, string $date, string $method, string $notes): void
{
    global $conn;
    if (!table_exists('receipts')) return;

    $cols = [];
    $vals = [];
    $types = '';
    $add = function (string $col, string $type, $val) use (&$cols, &$vals, &$types): void {
        if (col_exists('receipts', $col)) {
            $cols[] = $col;
            $vals[] = $val;
            $types .= $type;
        }
    };

    if (col_exists('receipts', 'receipt_no')) {
        $stmt = $conn->prepare("SELECT id FROM receipts WHERE receipt_no = ? LIMIT 1");
        $stmt->bind_param('s', $receiptNo);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) return;
    }

    $nullableMemberId = $memberId > 0 ? $memberId : null;
    $add('receipt_no', 's', $receiptNo);
    $add('member_id', 'i', $nullableMemberId);
    $add('tithe_id', 'i', $titheId);
    $add('source_type', 's', 'Tithe');
    $add('source_id', 'i', $titheId);
    $add('amount', 'd', $amount);
    $add('receipt_date', 's', $date);
    $add('payment_method', 's', $method);
    $add('description', 's', 'Tithe payment receipt');
    $add('notes', 's', $notes);
    $add('created_at', 's', date('Y-m-d H:i:s'));

    if (!$cols) return;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO receipts (`' . implode('`,`', $cols) . '`) VALUES (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
}

function send_tithe_email_confirmation(array $row): bool
{
    global $conn;

    $email = clean_value($row['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !function_exists('sendSystemEmail')) {
        return false;
    }

    $memberName = trim((string)($row['full_name'] ?? '')) ?: 'Member';
    $receiptNo = clean_value($row['receipt_no'] ?? ('TITHE-' . ($row['id'] ?? '')));
    $amount = number_format((float)($row['amount'] ?? 0));
    $date = clean_value($row['tithe_date'] ?? date('Y-m-d'));
    $method = clean_value($row['payment_method'] ?? '');
    $receiptUrl = 'download_receipt.php?tithe_id=' . (int)$row['id'];

    $subject = 'Tithe Payment Confirmation - ' . $receiptNo;
    $safeName = htmlspecialchars($memberName, ENT_QUOTES, 'UTF-8');
    $safeReceipt = htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    $safeMethod = htmlspecialchars($method ?: 'N/A', ENT_QUOTES, 'UTF-8');

    $html = "
        <div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;'>
            <div style='max-width:650px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;'>
                <h2 style='margin-top:0;color:#4a1e8f;'>Tithe Payment Confirmation</h2>
                <p>Dear <strong>{$safeName}</strong>,</p>
                <p>Thank you for your faithful tithe payment. Your payment has been received successfully.</p>
                <table style='width:100%;border-collapse:collapse;margin:18px 0;'>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Receipt No</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>{$safeReceipt}</td></tr>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Amount</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>UGX {$amount}</td></tr>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Date Paid</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>{$safeDate}</td></tr>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Payment Method</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>{$safeMethod}</td></tr>
                </table>
                <p>You can download your receipt from the system using receipt number <strong>{$safeReceipt}</strong>.</p>
                <p style='margin-bottom:0;'>Blessings,<br><strong>" . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . "</strong></p>
            </div>
        </div>
    ";

    $text = "Dear {$memberName},\n\nThank you for your tithe payment.\nReceipt No: {$receiptNo}\nAmount: UGX {$amount}\nDate Paid: {$date}\nPayment Method: " . ($method ?: 'N/A') . "\n\n" . APP_NAME;

    try {
        return sendSystemEmail($conn, $email, $memberName, $subject, $html, $text);
    } catch (Throwable $e) {
        error_log('Tithe email confirmation failed: ' . $e->getMessage());
        return false;
    }
}

function send_tithe_sms_confirmation(array $row): bool
{
    global $conn;

    $phone = clean_value($row['phone'] ?? '');
    if ($phone === '') return false;

    $receiptNo = clean_value($row['receipt_no'] ?? ('TITHE-' . ($row['id'] ?? '')));
    $amount = number_format((float)($row['amount'] ?? 0));
    $date = clean_value($row['tithe_date'] ?? date('Y-m-d'));
    $message = APP_NAME . ": Thank you for your tithe payment of UGX {$amount}. Receipt: {$receiptNo}. Date: {$date}.";

    try {
        if (function_exists('sendSms')) {
            return sendSms($conn, $phone, $message);
        }
        if (function_exists('sendSystemSms')) {
            return sendSystemSms($conn, $phone, $message);
        }
        if (function_exists('sendSMS')) {
            return sendSMS($conn, $phone, $message);
        }
    } catch (Throwable $e) {
        error_log('Tithe SMS confirmation failed: ' . $e->getMessage());
    }

    return false;
}

try {
    if (!table_exists('tithe')) {
        out(['success' => false, 'message' => 'tithe table does not exist.']);
    }

    $action = $_REQUEST['action'] ?? 'list';

    if ($action === 'list') {
        $member = (int)($_GET['member_id'] ?? 0);
        $month = (int)($_GET['month'] ?? 0);
        $year = (int)($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');

        $where = ['YEAR(t.tithe_date) = ?'];
        $types = 'i';
        $params = [$year];

        if ($month > 0) {
            $where[] = 'MONTH(t.tithe_date) = ?';
            $types .= 'i';
            $params[] = $month;
        }
        if ($member > 0) {
            $where[] = 't.member_id = ?';
            $types .= 'i';
            $params[] = $member;
        }

        $sql = "
            SELECT
                t.*,
                m.full_name,
                m.member_no,
                m.email,
                m.phone
            FROM tithe t
            LEFT JOIN members m ON m.id = t.member_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.tithe_date DESC, t.id DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        $stmt->close();

        out(['success' => true, 'items' => $items]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) out(['success' => false, 'message' => 'Invalid record.']);

        $row = get_tithe_with_member($id);
        out($row ? ['success' => true, 'item' => $row] : ['success' => false, 'message' => 'Record not found.']);
    }

    if ($action === 'resend_confirmation') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) out(['success' => false, 'message' => 'Invalid record.']);
        $row = get_tithe_with_member($id);
        if (!$row) out(['success' => false, 'message' => 'Record not found.']);
        $emailSent = send_tithe_email_confirmation($row);
        $smsSent = send_tithe_sms_confirmation($row);
        out(['success' => true, 'message' => 'Confirmation processed.', 'email_sent' => $emailSent, 'sms_sent' => $smsSent]);
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $member = (int)($_POST['member_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $periodMonth = (int)($_POST['period_month'] ?? 0);
        $periodYear = (int)($_POST['period_year'] ?? date('Y'));
        $date = clean_value($_POST['tithe_date'] ?? date('Y-m-d'));
        $method = clean_value($_POST['payment_method'] ?? 'Cash');
        $notes = clean_value($_POST['notes'] ?? '');

        if ($amount <= 0) out(['success' => false, 'message' => 'Amount must be greater than zero.']);
        if ($periodMonth < 0 || $periodMonth > 12) $periodMonth = 0;
        if ($periodYear < 2000 || $periodYear > 2100) $periodYear = (int)date('Y');
        if ($date === '') $date = date('Y-m-d');

        if ($action === 'update' && $id > 0) {
            $stmt = $conn->prepare("UPDATE tithe SET member_id = NULLIF(?,0), amount = ?, period_month = ?, period_year = ?, tithe_date = ?, payment_method = ?, notes = ? WHERE id = ?");
            $stmt->bind_param('idiisssi', $member, $amount, $periodMonth, $periodYear, $date, $method, $notes, $id);
            $stmt->execute();
            $stmt->close();

            $titheId = $id;
            $msg = 'Tithe updated successfully.';

            $stmt = $conn->prepare("SELECT receipt_no FROM tithe WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $receiptNo = (string)($stmt->get_result()->fetch_assoc()['receipt_no'] ?? '');
            $stmt->close();

            if ($receiptNo === '') {
                $receiptNo = make_receipt_no();
                if (col_exists('tithe', 'receipt_no')) {
                    $stmt = $conn->prepare("UPDATE tithe SET receipt_no = ? WHERE id = ?");
                    $stmt->bind_param('si', $receiptNo, $titheId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } else {
            $receiptNo = make_receipt_no();
            $stmt = $conn->prepare("INSERT INTO tithe (receipt_no, member_id, amount, period_month, period_year, tithe_date, payment_method, notes) VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sidiisss', $receiptNo, $member, $amount, $periodMonth, $periodYear, $date, $method, $notes);
            $stmt->execute();
            $titheId = (int)$conn->insert_id;
            $stmt->close();
            $msg = 'Tithe recorded successfully.';
        }

        save_receipt_row($titheId, $receiptNo, $member, $amount, $date, $method, $notes);

        $row = get_tithe_with_member($titheId);
        $emailSent = $row ? send_tithe_email_confirmation($row) : false;
        $smsSent = $row ? send_tithe_sms_confirmation($row) : false;

        $suffix = [];
        if ($emailSent) $suffix[] = 'email sent';
        if ($smsSent) $suffix[] = 'SMS sent';
        if ($suffix) $msg .= ' Confirmation ' . implode(' and ', $suffix) . '.';

        out([
            'success' => true,
            'message' => $msg,
            'id' => $titheId,
            'receipt_no' => $receiptNo,
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent
        ]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) out(['success' => false, 'message' => 'Invalid record.']);
        $stmt = $conn->prepare('DELETE FROM tithe WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['success' => true, 'message' => 'Tithe deleted successfully.']);
    }

    out(['success' => false, 'message' => 'Invalid action.']);
} catch (Throwable $e) {
    out(['success' => false, 'message' => $e->getMessage()]);
}
