<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
requireLogin();
enforce_current_page_permission();
require_once __DIR__ . '/../includes/service-options.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    gs_json(false, 'Database connection not found.');
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function gs_json($success, $message = '', $extra = []) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit;
}
function gs_post($key, $default = '') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }
function gs_get($key, $default = '') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }
function gs_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
/** Next free receipt number for the year, e.g. GS-2026-0007. */
function gs_receipt_no(mysqli $conn) {
    $prefix = 'GS-' . date('Y') . '-';
    $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_no, ?) AS UNSIGNED)), 0) n FROM good_samaritan WHERE receipt_no LIKE CONCAT(?, '%')");
    $start = strlen($prefix) + 1;
    $stmt->bind_param('is', $start, $prefix); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $prefix . str_pad(((int)($row['n'] ?? 0)) + 1, 4, '0', STR_PAD_LEFT);
}
function gs_save_service(mysqli $conn, int $id, ?string $type, ?string $session) {
    $stmt = $conn->prepare("UPDATE good_samaritan SET service_type=?, service_session=? WHERE id=?");
    $stmt->bind_param('ssi', $type, $session, $id); $stmt->execute(); $stmt->close();
}
function gs_select_sql() {
    return "SELECT g.*, m.full_name, m.member_no
            FROM good_samaritan g
            LEFT JOIN members m ON m.id = g.member_id";
}
function gs_read_one(mysqli $conn, $id) {
    $stmt = $conn->prepare(gs_select_sql() . " WHERE g.id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}

try {
    if (!gs_table_exists($conn, 'good_samaritan')) gs_json(false, 'The good_samaritan table was not found. Run database/good_samaritan.sql.');
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? gs_post('action') : gs_get('action', 'list');

    if ($action === 'list') {
        $res = $conn->query(gs_select_sql() . " ORDER BY g.given_on DESC, g.id DESC");
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        gs_json(true, '', ['items' => $items]);
    }

    if ($action === 'get') {
        $id = (int)gs_get('id', 0);
        if ($id <= 0) gs_json(false, 'Invalid record ID.');
        $row = gs_read_one($conn, $id);
        if (!$row) gs_json(false, 'Record not found.');
        gs_json(true, '', ['item' => $row]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') gs_json(false, 'Invalid request method.');

    if ($action === 'create' || $action === 'update') {
        $id = (int)gs_post('id', 0);
        $member_id = (int)gs_post('member_id', 0);
        $amount = (float)gs_post('amount', 0);
        $method = mb_substr(gs_post('payment_method', 'Cash') ?: 'Cash', 0, 40);
        $date = gs_post('given_on', date('Y-m-d')) ?: date('Y-m-d');
        $receipt = mb_substr(gs_post('receipt_no', ''), 0, 40);
        $notes = gs_post('notes', '');
        // Optional service; saved once database/service_session.sql has added the columns.
        $serviceType = gs_post('service_type', '');
        $serviceType = in_array($serviceType, service_type_options(), true) ? $serviceType : null;
        $session = gs_post('service_session', '');
        $session = in_array($session, service_session_options(), true) ? $session : null;
        $hasService = service_column_exists($conn, 'good_samaritan', 'service_session');

        // No member means a general collection, e.g. one basket for the whole congregation.
        $member_id = $member_id > 0 ? $member_id : null;
        if ($amount <= 0) gs_json(false, 'Please enter a valid amount.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) gs_json(false, 'Invalid date.');
        if ($receipt === '') $receipt = gs_receipt_no($conn);

        $dup = $conn->prepare("SELECT id FROM good_samaritan WHERE receipt_no = ? AND id <> ? LIMIT 1");
        $dup->bind_param('si', $receipt, $id); $dup->execute(); $taken = (bool)$dup->get_result()->fetch_row(); $dup->close();
        if ($taken) gs_json(false, 'Receipt number ' . $receipt . ' is already used.');

        if ($action === 'create') {
            $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
            $stmt = $conn->prepare("INSERT INTO good_samaritan (receipt_no, member_id, amount, payment_method, given_on, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sidsssi', $receipt, $member_id, $amount, $method, $date, $notes, $by);
            $stmt->execute(); $newId = $stmt->insert_id; $stmt->close();
            if ($hasService) gs_save_service($conn, $newId, $serviceType, $session);
            gs_json(true, 'Good Samaritan collection recorded. Receipt: ' . $receipt, ['id' => $newId]);
        }

        if ($id <= 0) gs_json(false, 'Invalid record ID.');
        $recordedBy = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $stmt = $conn->prepare("UPDATE good_samaritan SET receipt_no=?, member_id=?, amount=?, payment_method=?, given_on=?, notes=?, recorded_by=? WHERE id=?");
        $stmt->bind_param('sidsssii', $receipt, $member_id, $amount, $method, $date, $notes, $recordedBy, $id);
        $stmt->execute(); $stmt->close();
        if ($hasService) gs_save_service($conn, $id, $serviceType, $session);
        gs_json(true, 'Good Samaritan collection updated.');
    }

    if ($action === 'delete') {
        $id = (int)gs_post('id', 0);
        if ($id <= 0) gs_json(false, 'Invalid record ID.');
        $stmt = $conn->prepare("DELETE FROM good_samaritan WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        gs_json(true, 'Good Samaritan collection deleted.');
    }

    gs_json(false, 'Invalid request action.');
} catch (Throwable $e) {
    error_log('[process-good-samaritan] ' . $e->getMessage());
    gs_json(false, 'Something went wrong. Please try again.');
}
