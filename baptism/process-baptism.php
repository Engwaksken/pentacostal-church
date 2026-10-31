<?php
declare(strict_types=1);

ob_start();

require_once '../config/config.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection not found.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');

function bap_out(bool $success, string $message = '', array $extra = []): void
{
    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function bap_clean($value): string
{
    return trim((string)($value ?? ''));
}

function bap_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function bap_col_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = (int)($row['c'] ?? 0) > 0;
    return $cache[$key];
}

function bap_count(mysqli $conn, string $sql, string $type = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return 0;
    }

    if ($type !== '' && $params) {
        $stmt->bind_param($type, ...$params);
    }

    $stmt->execute();

    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return (int)($row[0] ?? 0);
}

function bap_fetch_value(mysqli $conn, string $sql, string $type = '', array $params = []): string
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return '';
    }

    if ($type !== '' && $params) {
        $stmt->bind_param($type, ...$params);
    }

    $stmt->execute();

    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return (string)($row[0] ?? '');
}

function bap_valid_type(string $type): bool
{
    return in_array($type, [
        'Infant',
        'Adult',
        'Confirmation',
        'Re-affirmation'
    ], true);
}

function bap_valid_date(string $date): bool
{
    if ($date === '') {
        return false;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function bap_verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $posted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

    if ($stored !== '' && !hash_equals((string)$stored, (string)$posted)) {
        bap_out(false, 'Security token expired. Please refresh the page and try again.');
    }
}

function bap_generate_no(mysqli $conn): string
{
    if (function_exists('genNo')) {
        $generated = genNo('BAP');

        if (!empty($generated)) {
            return (string)$generated;
        }
    }

    $year = date('Y');
    $prefix = 'BAP-' . $year . '-';

    $lastNo = bap_fetch_value(
        $conn,
        "SELECT baptism_no
         FROM baptisms
         WHERE baptism_no LIKE CONCAT(?, '%')
         ORDER BY id DESC
         LIMIT 1",
        's',
        [$prefix]
    );

    if ($lastNo !== '' && preg_match('/(\d+)$/', $lastNo, $matches)) {
        return $prefix . str_pad((string)(((int)$matches[1]) + 1), 4, '0', STR_PAD_LEFT);
    }

    $count = bap_count($conn, "SELECT COUNT(*) FROM baptisms") + 1;

    return $prefix . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

function bap_member_exists(mysqli $conn, ?int $memberId): bool
{
    if ($memberId === null || $memberId <= 0) {
        return true;
    }

    if (!bap_table_exists($conn, 'members')) {
        return false;
    }

    return bap_count(
        $conn,
        "SELECT COUNT(*) FROM members WHERE id = ?",
        'i',
        [$memberId]
    ) > 0;
}

function bap_prepare_member_id(mysqli $conn, $raw): ?int
{
    $raw = bap_clean($raw);

    if ($raw === '') {
        return null;
    }

    $memberId = (int)$raw;

    if ($memberId <= 0) {
        return null;
    }

    if (!bap_member_exists($conn, $memberId)) {
        throw new Exception('Selected member was not found.');
    }

    return $memberId;
}

function bap_full_name(array $data): string
{
    return (string)preg_replace('/\s+/', ' ', bap_clean($data['full_name'] ?? ''));
}

function bap_insert_one(mysqli $conn, array $data): int
{
    $memberId = bap_prepare_member_id($conn, $data['member_id'] ?? '');

    $fullName = bap_full_name($data);

    $baptismNo   = bap_clean($data['baptism_no'] ?? '');
    $baptismType = bap_clean($data['baptism_type'] ?? '');
    $baptismDate = bap_clean($data['baptism_date'] ?? '');

    $officiant    = bap_clean($data['officiant'] ?? '');
    $venue        = bap_clean($data['venue'] ?? '');
    $godfather    = bap_clean($data['godfather'] ?? '');
    $godmother    = bap_clean($data['godmother'] ?? '');
    $parentFather = bap_clean($data['parent_father'] ?? '');
    $parentMother = bap_clean($data['parent_mother'] ?? '');
    $certificate  = bap_clean($data['certificate_no'] ?? '');
    $notes        = bap_clean($data['notes'] ?? '');

    if ($fullName === '') {
        throw new Exception('Candidate name is required.');
    }

    if (!bap_valid_type($baptismType)) {
        throw new Exception('Invalid baptism type selected.');
    }

    if (!bap_valid_date($baptismDate)) {
        throw new Exception('Valid baptism date is required.');
    }

    if ($baptismNo === '') {
        $baptismNo = bap_generate_no($conn);
    }

    $stmt = $conn->prepare("
        INSERT INTO baptisms (
            baptism_no,
            member_id,
            candidate_name,
            baptism_type,
            baptism_date,
            officiant,
            venue,
            godfather,
            godmother,
            parent_father,
            parent_mother,
            certificate_no,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'sisssssssssss',
        $baptismNo,
        $memberId,
        $fullName,
        $baptismType,
        $baptismDate,
        $officiant,
        $venue,
        $godfather,
        $godmother,
        $parentFather,
        $parentMother,
        $certificate,
        $notes
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Insert failed: ' . $error);
    }

    $newId = (int)$conn->insert_id;
    $stmt->close();

    return $newId;
}

function bap_ensure_signatories_table(mysqli $conn): void
{
    require_once __DIR__ . '/../includes/certificate-signatories.php';
    cert_signatories_ensure($conn);
}

function bap_upload_signature(string $field, string $prefix): ?string
{
    if (empty($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Signature upload failed for ' . $field . '.');
    }
    if ((int)$file['size'] > 2 * 1024 * 1024) {
        throw new Exception('Each signature image must not exceed 2 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new Exception('Only PNG, JPG and WEBP signature images are allowed.');
    }
    $dir = __DIR__ . '/../uploads/baptism-signatures';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new Exception('Could not create signature upload directory.');
    }
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $absolute = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $absolute)) {
        throw new Exception('Could not save uploaded signature.');
    }
    return 'uploads/baptism-signatures/' . $name;
}

function bap_delete_signature_file(?string $relative): void
{
    $relative = trim((string)$relative);
    if ($relative === '' || strpos($relative, 'uploads/baptism-signatures/') !== 0) return;
    $absolute = __DIR__ . '/../' . $relative;
    if (is_file($absolute)) @unlink($absolute);
}

function bap_get_counts(mysqli $conn): array
{
    $counts = [
        'Infant' => 0,
        'Adult' => 0,
        'Confirmation' => 0,
        'Re-affirmation' => 0,
        'Total' => 0,
        'ThisYear' => 0
    ];

    foreach (['Infant', 'Adult', 'Confirmation', 'Re-affirmation'] as $type) {
        $counts[$type] = bap_count(
            $conn,
            "SELECT COUNT(*) FROM baptisms WHERE baptism_type = ?",
            's',
            [$type]
        );
    }

    $counts['Total'] = bap_count($conn, "SELECT COUNT(*) FROM baptisms");
    $counts['ThisYear'] = bap_count(
        $conn,
        "SELECT COUNT(*) FROM baptisms WHERE YEAR(baptism_date) = YEAR(CURDATE())"
    );

    return $counts;
}

try {
    if (!bap_table_exists($conn, 'baptisms')) {
        bap_out(false, 'baptisms table does not exist.');
    }

    $action = bap_clean($_REQUEST['action'] ?? 'list');

    if ($action === '') {
        $action = 'list';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        bap_verify_csrf();
    }

    if ($action === 'get_signatories') {
        bap_ensure_signatories_table($conn);
        $res = $conn->query("SELECT * FROM baptism_certificate_signatories WHERE id = 1 LIMIT 1");
        $row = $res ? ($res->fetch_assoc() ?: []) : [];
        if ($res) $res->close();
        $row['vicar_title'] = trim((string)($row['vicar_title'] ?? '')) ?: CERT_SIGNATORY_DEFAULT_TITLES[0];
        $row['secretary_title'] = trim((string)($row['secretary_title'] ?? '')) ?: CERT_SIGNATORY_DEFAULT_TITLES[1];
        $row['vicar_signature_url'] = !empty($row['vicar_signature']) ? '../' . ltrim($row['vicar_signature'], '/') : '';
        $row['secretary_signature_url'] = !empty($row['secretary_signature']) ? '../' . ltrim($row['secretary_signature'], '/') : '';
        bap_out(true, '', ['item' => $row]);
    }

    if ($action === 'save_signatories') {
        bap_ensure_signatories_table($conn);
        $res = $conn->query("SELECT vicar_signature, secretary_signature FROM baptism_certificate_signatories WHERE id = 1 LIMIT 1");
        $old = $res ? ($res->fetch_assoc() ?: []) : [];
        if ($res) $res->close();

        $vicarName = bap_clean($_POST['vicar_name'] ?? '');
        $secretaryName = bap_clean($_POST['secretary_name'] ?? '');
        $vicarTitle = mb_substr(bap_clean($_POST['vicar_title'] ?? ''), 0, 100) ?: CERT_SIGNATORY_DEFAULT_TITLES[0];
        $secretaryTitle = mb_substr(bap_clean($_POST['secretary_title'] ?? ''), 0, 100) ?: CERT_SIGNATORY_DEFAULT_TITLES[1];
        $vicarPath = (string)($old['vicar_signature'] ?? '');
        $secretaryPath = (string)($old['secretary_signature'] ?? '');

        if (!empty($_POST['remove_vicar_signature'])) {
            bap_delete_signature_file($vicarPath);
            $vicarPath = '';
        }
        if (!empty($_POST['remove_secretary_signature'])) {
            bap_delete_signature_file($secretaryPath);
            $secretaryPath = '';
        }

        $newVicar = bap_upload_signature('vicar_signature', 'vicar');
        if ($newVicar !== null) {
            bap_delete_signature_file($vicarPath);
            $vicarPath = $newVicar;
        }
        $newSecretary = bap_upload_signature('secretary_signature', 'secretary');
        if ($newSecretary !== null) {
            bap_delete_signature_file($secretaryPath);
            $secretaryPath = $newSecretary;
        }

        $updatedBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE baptism_certificate_signatories SET vicar_title=?, vicar_name=?, vicar_signature=?, secretary_title=?, secretary_name=?, secretary_signature=?, updated_by=? WHERE id=1");
        if (!$stmt) throw new Exception('Could not prepare signatory update: ' . $conn->error);
        $stmt->bind_param('ssssssi', $vicarTitle, $vicarName, $vicarPath, $secretaryTitle, $secretaryName, $secretaryPath, $updatedBy);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Could not save signatories: ' . $error);
        }
        $stmt->close();
        bap_out(true, 'Certificate signatories and signatures saved successfully.');
    }

    if ($action === 'list') {
        $type = bap_clean($_GET['type'] ?? '');
        $search = bap_clean($_GET['search'] ?? '');
        $period = bap_clean($_GET['period'] ?? 'all');
        $startDate = bap_clean($_GET['start_date'] ?? '');
        $endDate = bap_clean($_GET['end_date'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 10);
        $allowedPerPage = [10, 25, 50, 100];

        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }

        if ($type !== '' && !bap_valid_type($type)) {
            bap_out(false, 'Invalid baptism type selected.');
        }

        if (!in_array($period, ['all', 'today', 'week', 'range'], true)) {
            $period = 'all';
        }

        if ($period === 'range') {
            if (!bap_valid_date($startDate) || !bap_valid_date($endDate)) {
                bap_out(false, 'Select a valid start date and end date.');
            }

            if ($startDate > $endDate) {
                bap_out(false, 'The start date cannot be later than the end date.');
            }
        }

        $hasMembers = bap_table_exists($conn, 'members');
        $conditions = [];
        $params = [];
        $types = '';

        if ($type !== '') {
            $conditions[] = 'b.baptism_type = ?';
            $params[] = $type;
            $types .= 's';
        }

        if ($search !== '') {
            $term = '%' . $search . '%';
            $searchConditions = [
                'b.baptism_no LIKE ?',
                'b.candidate_name LIKE ?',
                'b.officiant LIKE ?',
                'b.venue LIKE ?',
                'b.certificate_no LIKE ?',
                'b.godfather LIKE ?',
                'b.godmother LIKE ?'
            ];

            if ($hasMembers) {
                $searchConditions[] = "m.full_name LIKE ?";
                $searchConditions[] = 'm.member_no LIKE ?';
            }

            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';

            foreach ($searchConditions as $_) {
                $params[] = $term;
                $types .= 's';
            }
        }

        if ($period === 'today') {
            $conditions[] = 'b.baptism_date = CURDATE()';
        } elseif ($period === 'week') {
            $conditions[] = 'YEARWEEK(b.baptism_date, 1) = YEARWEEK(CURDATE(), 1)';
        } elseif ($period === 'range') {
            $conditions[] = 'b.baptism_date BETWEEN ? AND ?';
            $params[] = $startDate;
            $params[] = $endDate;
            $types .= 'ss';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $join = $hasMembers ? 'LEFT JOIN members m ON m.id = b.member_id' : '';

        $countSql = "SELECT COUNT(*) AS total FROM baptisms b {$join} {$where}";
        $countStmt = $conn->prepare($countSql);

        if (!$countStmt) {
            bap_out(false, 'Count query error: ' . $conn->error);
        }

        if ($params) {
            $countStmt->bind_param($types, ...$params);
        }

        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                b.*,
                " . ($hasMembers ? "
                m.full_name AS member_name,
                COALESCE(m.member_no, '') AS member_no
                " : "
                '' AS member_name,
                '' AS member_no
                ") . "
            FROM baptisms b
            {$join}
            {$where}
            ORDER BY b.baptism_date DESC, b.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            bap_out(false, 'Query error: ' . $conn->error);
        }

        $listParams = $params;
        $listTypes = $types . 'ii';
        $listParams[] = $perPage;
        $listParams[] = $offset;
        $stmt->bind_param($listTypes, ...$listParams);
        $stmt->execute();

        $result = $stmt->get_result();
        $items = [];

        while ($row = $result->fetch_assoc()) {
            $row['candidate_name'] = trim((string)($row['candidate_name'] ?? ''));
            $items[] = $row;
        }

        $stmt->close();

        $from = $total > 0 ? $offset + 1 : 0;
        $to = $total > 0 ? min($offset + $perPage, $total) : 0;

        bap_out(true, 'Loaded successfully.', [
            'items' => $items,
            'counts' => bap_get_counts($conn),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $from,
                'to' => $to
            ],
            'filters' => [
                'search' => $search,
                'type' => $type,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
    }

    if ($action === 'members') {
        if (!bap_table_exists($conn, 'members')) {
            bap_out(true, 'No members table found.', [
                'items' => []
            ]);
        }

        $items = [];

        $res = $conn->query("
            SELECT 
                id,
                member_no,
                full_name,
                gender,
                phone
            FROM members
            ORDER BY full_name
            LIMIT 2000
        ");

        while ($res && $row = $res->fetch_assoc()) {
            $items[] = $row;
        }

        bap_out(true, 'Loaded successfully.', [
            'items' => $items
        ]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0) {
            bap_out(false, 'Invalid record ID.');
        }

        $hasMembers = bap_table_exists($conn, 'members');

        $sql = "
            SELECT 
                b.*,
                " . ($hasMembers ? "
                m.full_name AS member_name,
                COALESCE(m.member_no, '') AS member_no
                " : "
                '' AS member_name,
                '' AS member_no
                ") . "
            FROM baptisms b
            " . ($hasMembers ? "LEFT JOIN members m ON m.id = b.member_id" : "") . "
            WHERE b.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            bap_out(false, 'Query error: ' . $conn->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            bap_out(false, 'Record not found.');
        }

        bap_out(true, 'Loaded successfully.', [
            'item' => $row
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bap_out(false, 'Invalid request method.');
    }

    if ($action === 'create') {
        $newId = bap_insert_one($conn, $_POST);

        $baptismNo = bap_fetch_value(
            $conn,
            "SELECT baptism_no FROM baptisms WHERE id = ? LIMIT 1",
            'i',
            [$newId]
        );

        bap_out(true, 'Baptism recorded successfully.', [
            'id' => $newId,
            'baptism_no' => $baptismNo
        ]);
    }

    if ($action === 'bulk_create') {
        $candidates = $_POST['candidates'] ?? [];

        if (!is_array($candidates) || empty($candidates)) {
            bap_out(false, 'No candidate data received.');
        }

        $sharedDate      = bap_clean($_POST['baptism_date'] ?? '');
        $sharedType      = bap_clean($_POST['baptism_type'] ?? '');
        $sharedOfficiant = bap_clean($_POST['officiant'] ?? '');
        $sharedVenue     = bap_clean($_POST['venue'] ?? '');

        if (!bap_valid_date($sharedDate)) {
            bap_out(false, 'Valid baptism date is required.');
        }

        if (!bap_valid_type($sharedType)) {
            bap_out(false, 'Invalid baptism type selected.');
        }

        $cleanCandidates = [];
        $errors = [];

        foreach ($candidates as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $rowNo = (int)$index + 1;

            if (bap_full_name($candidate) === '') {
                $errors[] = "Row {$rowNo}: Full name is required.";
                continue;
            }

            $cleanCandidates[] = array_merge($candidate, [
                'baptism_date' => $sharedDate,
                'baptism_type' => $sharedType,
                'officiant' => $sharedOfficiant,
                'venue' => $sharedVenue
            ]);
        }

        if ($errors) {
            bap_out(false, implode(' ', $errors));
        }

        if (!$cleanCandidates) {
            bap_out(false, 'No valid candidate rows received.');
        }

        $conn->begin_transaction();

        $inserted = 0;
        $baptismNos = [];

        try {
            foreach ($cleanCandidates as $candidate) {
                $newId = bap_insert_one($conn, $candidate);

                $baptismNo = bap_fetch_value(
                    $conn,
                    "SELECT baptism_no FROM baptisms WHERE id = ? LIMIT 1",
                    'i',
                    [$newId]
                );

                $baptismNos[] = $baptismNo !== '' ? $baptismNo : 'ID:' . $newId;
                $inserted++;
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $message = $inserted === 1
            ? 'Baptism recorded successfully. No: ' . ($baptismNos[0] ?? '')
            : $inserted . ' baptisms recorded successfully.';

        bap_out(true, $message, [
            'inserted' => $inserted,
            'baptism_nos' => $baptismNos
        ]);
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            bap_out(false, 'Invalid record ID.');
        }

        $exists = bap_count(
            $conn,
            "SELECT COUNT(*) FROM baptisms WHERE id = ?",
            'i',
            [$id]
        );

        if ($exists <= 0) {
            bap_out(false, 'Record not found.');
        }

        $memberId = bap_prepare_member_id($conn, $_POST['member_id'] ?? '');

        $fullName = bap_full_name($_POST);

        $baptismType = bap_clean($_POST['baptism_type'] ?? '');
        $baptismDate = bap_clean($_POST['baptism_date'] ?? '');

        $officiant    = bap_clean($_POST['officiant'] ?? '');
        $venue        = bap_clean($_POST['venue'] ?? '');
        $godfather    = bap_clean($_POST['godfather'] ?? '');
        $godmother    = bap_clean($_POST['godmother'] ?? '');
        $parentFather = bap_clean($_POST['parent_father'] ?? '');
        $parentMother = bap_clean($_POST['parent_mother'] ?? '');
        $certificate  = bap_clean($_POST['certificate_no'] ?? '');
        $notes        = bap_clean($_POST['notes'] ?? '');

        if ($fullName === '') {
            bap_out(false, 'Candidate name is required.');
        }

        if (!bap_valid_type($baptismType)) {
            bap_out(false, 'Invalid baptism type selected.');
        }

        if (!bap_valid_date($baptismDate)) {
            bap_out(false, 'Valid baptism date is required.');
        }

        $stmt = $conn->prepare("
            UPDATE baptisms SET
                member_id = ?,
                candidate_name = ?,
                baptism_type = ?,
                baptism_date = ?,
                officiant = ?,
                venue = ?,
                godfather = ?,
                godmother = ?,
                parent_father = ?,
                parent_mother = ?,
                certificate_no = ?,
                notes = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            bap_out(false, 'Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            'isssssssssssi',
            $memberId,
            $fullName,
            $baptismType,
            $baptismDate,
            $officiant,
            $venue,
            $godfather,
            $godmother,
            $parentFather,
            $parentMother,
            $certificate,
            $notes,
            $id
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            bap_out(false, 'Update failed: ' . $error);
        }

        $stmt->close();

        bap_out(true, 'Baptism record updated successfully.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            bap_out(false, 'Invalid record ID.');
        }

        $stmt = $conn->prepare("DELETE FROM baptisms WHERE id = ?");

        if (!$stmt) {
            bap_out(false, 'Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            bap_out(false, 'Delete failed: ' . $error);
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) {
            bap_out(false, 'Record not found or already deleted.');
        }

        bap_out(true, 'Baptism record deleted successfully.');
    }

    bap_out(false, 'Invalid request action.');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignore) {
        }
    }

    error_log('[process-baptism.php] ' . $e->getMessage());

    bap_out(false, 'Server error: ' . $e->getMessage());
}