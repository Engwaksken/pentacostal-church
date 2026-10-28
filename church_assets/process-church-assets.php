<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
requireLogin();
enforce_current_page_permission();

if (!isset($conn) || !($conn instanceof mysqli)) {
    ca_json(false, 'Database connection not found.');
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const CA_CATEGORIES = ['Furniture', 'Musical Instruments', 'Sound & Media', 'Electronics & IT', 'Vehicles', 'Land & Buildings', 'Tents & Canopies', 'Kitchen & Catering', 'Office Equipment', 'Tools & Machinery', 'Other'];
const CA_ACQUISITION = ['Purchased', 'Donated', 'Built', 'Transferred'];
const CA_CONDITIONS = ['New', 'Good', 'Fair', 'Poor', 'Damaged'];
const CA_STATUSES = ['In Use', 'In Store', 'Under Repair', 'Lent Out', 'Lost', 'Disposed'];
const CA_MAINTENANCE = ['Service', 'Repair', 'Inspection', 'Cleaning', 'Replacement of Parts', 'Other'];

function ca_json($success, $message = '', $extra = []) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit;
}
function ca_post($key, $default = '') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }
function ca_get($key, $default = '') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }
function ca_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function ca_valid_date($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
/** Empty string -> null, otherwise the date if valid; stops with $error if it is not. */
function ca_optional_date($d, $error) {
    if ($d === '') return null;
    if (!ca_valid_date($d)) ca_json(false, $error);
    return $d;
}
/** Next free asset number for the year, e.g. AST-2026-0003. */
function ca_asset_no(mysqli $conn) {
    $prefix = 'AST-' . date('Y') . '-';
    $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(asset_no, ?) AS UNSIGNED)), 0) n FROM church_assets WHERE asset_no LIKE CONCAT(?, '%')");
    $start = strlen($prefix) + 1;
    $stmt->bind_param('is', $start, $prefix); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $prefix . str_pad(((int)($row['n'] ?? 0)) + 1, 4, '0', STR_PAD_LEFT);
}
function ca_select_sql() {
    return "SELECT a.*, c.full_name AS custodian_name,
                (SELECT COALESCE(SUM(l.quantity), 0) FROM church_asset_loans l WHERE l.asset_id = a.id AND l.returned_date IS NULL) AS qty_lent,
                (SELECT COALESCE(SUM(m.cost), 0) FROM church_asset_maintenance m WHERE m.asset_id = a.id) AS maintenance_cost,
                (SELECT m.next_due_date FROM church_asset_maintenance m WHERE m.asset_id = a.id ORDER BY m.maintenance_date DESC, m.id DESC LIMIT 1) AS next_due_date
            FROM church_assets a
            LEFT JOIN members c ON c.id = a.custodian_member_id";
}
function ca_read_one(mysqli $conn, $id) {
    $stmt = $conn->prepare(ca_select_sql() . " WHERE a.id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}
function ca_rows(mysqli $conn, $sql, $id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id); $stmt->execute(); $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}
function ca_require_asset(mysqli $conn, $id) {
    if ($id <= 0) ca_json(false, 'Invalid asset ID.');
    $row = ca_read_one($conn, $id);
    if (!$row) ca_json(false, 'Asset not found.');
    return $row;
}
/** The asset a loan belongs to, or stop if the loan does not exist. */
function ca_loan_asset_id(mysqli $conn, $loanId) {
    if ($loanId <= 0) ca_json(false, 'Invalid loan.');
    $stmt = $conn->prepare("SELECT asset_id FROM church_asset_loans WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $loanId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) ca_json(false, 'Loan not found.');
    return (int)$row['asset_id'];
}
/** Mark an asset Lent Out when every unit is out, and back to In Use once something comes back. */
function ca_sync_lent_status(mysqli $conn, $assetId) {
    $a = ca_read_one($conn, $assetId);
    if (!$a) return;
    $available = (int)$a['quantity'] - (int)$a['qty_lent'];
    $status = $a['status'];
    if ($available <= 0 && in_array($status, ['In Use', 'In Store'], true)) $status = 'Lent Out';
    elseif ($available > 0 && $status === 'Lent Out') $status = 'In Use';
    if ($status === $a['status']) return;
    $stmt = $conn->prepare("UPDATE church_assets SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $assetId); $stmt->execute(); $stmt->close();
}

try {
    foreach (['church_assets', 'church_asset_maintenance', 'church_asset_loans'] as $t) {
        if (!ca_table_exists($conn, $t)) ca_json(false, 'The ' . $t . ' table was not found. Run database/church_assets.sql.');
    }
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ca_post('action') : ca_get('action', 'list');

    if ($action === 'list') {
        $res = $conn->query(ca_select_sql() . " ORDER BY a.name, a.id");
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        ca_json(true, '', ['items' => $items]);
    }

    if ($action === 'get') {
        $id = (int)ca_get('id', 0);
        if ($id <= 0) ca_json(false, 'Invalid asset ID.');
        $row = ca_read_one($conn, $id);
        if (!$row) ca_json(false, 'Asset not found.');
        $maintenance = ca_rows($conn, "SELECT * FROM church_asset_maintenance WHERE asset_id = ? ORDER BY maintenance_date DESC, id DESC", $id);
        $loans = ca_rows($conn, "SELECT l.*, COALESCE(NULLIF(l.borrower_name, ''), m.full_name) AS borrower, m.member_no,
                                        COALESCE(NULLIF(l.borrower_phone, ''), m.phone) AS phone
                                 FROM church_asset_loans l LEFT JOIN members m ON m.id = l.borrower_member_id
                                 WHERE l.asset_id = ? ORDER BY l.returned_date IS NOT NULL, l.loan_date DESC, l.id DESC", $id);
        ca_json(true, '', ['item' => $row, 'maintenance' => $maintenance, 'loans' => $loans]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ca_json(false, 'Invalid request method.');

    if ($action === 'create' || $action === 'update') {
        $id = (int)ca_post('id', 0);
        $name = mb_substr(ca_post('name'), 0, 200);
        $category = ca_post('category', 'Other');
        $description = ca_post('description');
        $serial = mb_substr(ca_post('serial_no'), 0, 120);
        $quantity = (int)ca_post('quantity', 1);
        $location = mb_substr(ca_post('location'), 0, 200);
        $custodian = (int)ca_post('custodian_member_id', 0) ?: null;
        $acquisition = ca_post('acquisition_type', 'Purchased');
        $source = mb_substr(ca_post('source_name'), 0, 200);
        $acquired = ca_optional_date(ca_post('acquired_date'), 'Invalid date acquired.');
        $cost = (float)ca_post('purchase_cost', 0);
        $value = (float)ca_post('current_value', 0);
        $warranty = ca_optional_date(ca_post('warranty_until'), 'Invalid warranty date.');
        $condition = ca_post('item_condition', 'Good');
        $status = ca_post('status', 'In Use');
        $notes = ca_post('notes');

        if ($name === '') ca_json(false, 'Please enter the asset name.');
        if ($quantity < 1) ca_json(false, 'The quantity must be at least 1.');
        if ($cost < 0 || $value < 0) ca_json(false, 'Cost and value cannot be negative.');
        if (!in_array($category, CA_CATEGORIES, true)) $category = 'Other';
        if (!in_array($acquisition, CA_ACQUISITION, true)) $acquisition = 'Purchased';
        if (!in_array($condition, CA_CONDITIONS, true)) $condition = 'Good';
        if (!in_array($status, CA_STATUSES, true)) $status = 'In Use';

        if ($action === 'create') {
            $no = ca_asset_no($conn);
            $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
            $stmt = $conn->prepare("INSERT INTO church_assets (asset_no, name, category, description, serial_no, quantity, location, custodian_member_id, acquisition_type, source_name, acquired_date, purchase_cost, current_value, warranty_until, item_condition, status, notes, created_by)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssssisisssddssssi', $no, $name, $category, $description, $serial, $quantity, $location, $custodian, $acquisition, $source, $acquired, $cost, $value, $warranty, $condition, $status, $notes, $by);
            $stmt->execute(); $newId = $stmt->insert_id; $stmt->close();
            ca_json(true, 'Asset ' . $no . ' registered.', ['id' => $newId]);
        }

        $current = ca_require_asset($conn, $id);
        if ($quantity < (int)$current['qty_lent']) ca_json(false, 'The quantity cannot be less than the ' . (int)$current['qty_lent'] . ' currently lent out.');
        $stmt = $conn->prepare("UPDATE church_assets SET name=?, category=?, description=?, serial_no=?, quantity=?, location=?, custodian_member_id=?, acquisition_type=?, source_name=?, acquired_date=?, purchase_cost=?, current_value=?, warranty_until=?, item_condition=?, status=?, notes=? WHERE id=?");
        $stmt->bind_param('ssssisisssddssssi', $name, $category, $description, $serial, $quantity, $location, $custodian, $acquisition, $source, $acquired, $cost, $value, $warranty, $condition, $status, $notes, $id);
        $stmt->execute(); $stmt->close();
        ca_json(true, 'Asset updated.');
    }

    if ($action === 'delete') {
        $id = (int)ca_post('id', 0);
        ca_require_asset($conn, $id);
        // Maintenance and loan rows go with it (ON DELETE CASCADE).
        $stmt = $conn->prepare("DELETE FROM church_assets WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        ca_json(true, 'Asset deleted.');
    }

    if ($action === 'maintenance_add') {
        $assetId = (int)ca_post('asset_id', 0);
        $date = ca_post('maintenance_date', date('Y-m-d')) ?: date('Y-m-d');
        $type = ca_post('maintenance_type', 'Service');
        $description = mb_substr(ca_post('description'), 0, 255);
        $cost = (float)ca_post('cost', 0);
        $doneBy = mb_substr(ca_post('done_by'), 0, 150);
        $nextDue = ca_optional_date(ca_post('next_due_date'), 'Invalid next due date.');
        ca_require_asset($conn, $assetId);
        if (!ca_valid_date($date)) ca_json(false, 'Invalid date.');
        if (!in_array($type, CA_MAINTENANCE, true)) ca_json(false, 'Please choose the type of work.');
        if ($cost < 0) ca_json(false, 'The cost cannot be negative.');
        if ($nextDue !== null && $nextDue < $date) ca_json(false, 'The next due date cannot be before the work date.');
        $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $stmt = $conn->prepare("INSERT INTO church_asset_maintenance (asset_id, maintenance_date, maintenance_type, description, cost, done_by, next_due_date, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssdssi', $assetId, $date, $type, $description, $cost, $doneBy, $nextDue, $by);
        $stmt->execute(); $stmt->close();
        ca_json(true, 'Maintenance recorded.');
    }

    if ($action === 'maintenance_delete') {
        $id = (int)ca_post('id', 0);
        if ($id <= 0) ca_json(false, 'Invalid entry.');
        $stmt = $conn->prepare("DELETE FROM church_asset_maintenance WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        ca_json(true, 'Maintenance entry deleted.');
    }

    if ($action === 'loan_add') {
        $assetId = (int)ca_post('asset_id', 0);
        $memberId = (int)ca_post('borrower_member_id', 0) ?: null;
        $borrower = mb_substr(ca_post('borrower_name'), 0, 200);
        $phone = mb_substr(ca_post('borrower_phone'), 0, 40);
        $quantity = (int)ca_post('quantity', 1);
        $loanDate = ca_post('loan_date', date('Y-m-d')) ?: date('Y-m-d');
        $dueDate = ca_optional_date(ca_post('due_date'), 'Invalid return-by date.');
        $purpose = mb_substr(ca_post('purpose'), 0, 255);
        $asset = ca_require_asset($conn, $assetId);
        if ($memberId === null && $borrower === '') ca_json(false, 'Choose a member or type the borrower\'s name.');
        if (!ca_valid_date($loanDate)) ca_json(false, 'Invalid loan date.');
        if ($dueDate !== null && $dueDate < $loanDate) ca_json(false, 'The return-by date cannot be before the loan date.');
        if (in_array($asset['status'], ['Lost', 'Disposed', 'Under Repair'], true)) ca_json(false, 'This asset is ' . $asset['status'] . ' and cannot be lent out.');
        $available = (int)$asset['quantity'] - (int)$asset['qty_lent'];
        if ($quantity < 1) ca_json(false, 'The quantity must be at least 1.');
        if ($quantity > $available) ca_json(false, $available > 0 ? 'Only ' . $available . ' available to lend.' : 'All units are already lent out.');
        $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $stmt = $conn->prepare("INSERT INTO church_asset_loans (asset_id, borrower_member_id, borrower_name, borrower_phone, quantity, loan_date, due_date, purpose, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iississsi', $assetId, $memberId, $borrower, $phone, $quantity, $loanDate, $dueDate, $purpose, $by);
        $stmt->execute(); $stmt->close();
        ca_sync_lent_status($conn, $assetId);
        ca_json(true, 'Loan recorded.');
    }

    if ($action === 'loan_return') {
        $id = (int)ca_post('id', 0);
        $date = ca_post('returned_date', date('Y-m-d')) ?: date('Y-m-d');
        $condition = ca_post('return_condition', 'Good');
        $assetId = ca_loan_asset_id($conn, $id);
        if (!ca_valid_date($date)) ca_json(false, 'Invalid return date.');
        if (!in_array($condition, CA_CONDITIONS, true)) $condition = 'Good';
        $stmt = $conn->prepare("UPDATE church_asset_loans SET returned_date = ?, return_condition = ? WHERE id = ? AND returned_date IS NULL");
        $stmt->bind_param('ssi', $date, $condition, $id); $stmt->execute(); $changed = $stmt->affected_rows; $stmt->close();
        if ($changed < 1) ca_json(false, 'This loan was already returned.');
        ca_sync_lent_status($conn, $assetId);
        ca_json(true, 'Marked as returned.');
    }

    if ($action === 'loan_delete') {
        $id = (int)ca_post('id', 0);
        $assetId = ca_loan_asset_id($conn, $id);
        $stmt = $conn->prepare("DELETE FROM church_asset_loans WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        ca_sync_lent_status($conn, $assetId);
        ca_json(true, 'Loan deleted.');
    }

    ca_json(false, 'Invalid request action.');
} catch (Throwable $e) {
    error_log('[process-church-assets] ' . $e->getMessage());
    ca_json(false, 'Something went wrong. Please try again.');
}
