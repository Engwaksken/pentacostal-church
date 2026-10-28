<?php
declare(strict_types=1);

ob_start();

require_once '../includes/header.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) || !($conn instanceof mysqli)) {
    youth_json(false, 'Database connection not found.');
}

$conn->set_charset('utf8mb4');

function youth_json(bool $success, string $message = '', array $extra = []): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function youth_clean($value): string
{
    return trim((string)($value ?? ''));
}

function youth_get(string $key, string $default = ''): string
{
    return youth_clean($_POST[$key] ?? $_GET[$key] ?? $default);
}

function youth_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function youth_col_exists(mysqli $conn, string $table, string $column): bool
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
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = (int)($row['c'] ?? 0) > 0;

    return $cache[$key];
}

function youth_bind_exec(mysqli_stmt $stmt, string $types, array $values): void
{
    $refs = [];

    foreach ($values as $key => $value) {
        $refs[$key] = &$values[$key];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
}

function youth_fetch_one(mysqli $conn, string $sql, string $types = '', array $values = []): ?array
{
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        youth_bind_exec($stmt, $types, $values);
    } else {
        $stmt->execute();
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function youth_insert_dynamic(mysqli $conn, string $table, array $data): int
{
    $cols = [];
    $values = [];

    foreach ($data as $key => $value) {
        if (youth_col_exists($conn, $table, $key)) {
            $cols[] = $key;
            $values[] = $value;
        }
    }

    if (!$cols) {
        throw new Exception('No matching table columns found.');
    }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));

    $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);

    youth_bind_exec($stmt, str_repeat('s', count($values)), $values);
    $stmt->close();

    // NOTE: insert_id lives on the connection, not the statement object �
    // $stmt->insert_id is not a valid mysqli_stmt property and would
    // silently return 0/null here.
    return (int)$conn->insert_id;
}

function youth_update_dynamic(mysqli $conn, string $table, array $data, int $id): void
{
    $set = [];
    $values = [];

    foreach ($data as $key => $value) {
        if (youth_col_exists($conn, $table, $key)) {
            $set[] = "`{$key}` = ?";
            $values[] = $value;
        }
    }

    if (!$set) {
        return;
    }

    $values[] = $id;

    $stmt = $conn->prepare("UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE id = ?");
    youth_bind_exec($stmt, str_repeat('s', count($values) - 1) . 'i', $values);
    $stmt->close();
}

function youth_gen_no(mysqli $conn): string
{
    if (function_exists('genNo')) {
        try {
            $generated = genNo('YTH', $conn, 'youth', 'youth_no');
            if (!empty($generated)) {
                return (string)$generated;
            }
        } catch (Throwable $e) {
        }
    }

    $prefix = 'YTH-' . date('Y') . '-';
    $last = youth_fetch_one(
        $conn,
        "SELECT youth_no FROM youth WHERE youth_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1",
        's',
        [$prefix]
    );

    $next = 1;

    if ($last && preg_match('/(\d+)$/', (string)$last['youth_no'], $match)) {
        $next = ((int)$match[1]) + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function youth_build_select(mysqli $conn): array
{
    $hasMembers = youth_table_exists($conn, 'members') && youth_col_exists($conn, 'youth', 'member_id');

    $select = [
        'y.id',
        youth_col_exists($conn, 'youth', 'member_id') ? 'y.member_id' : 'NULL AS member_id',
    ];

    foreach ([
        'youth_no',
        'education_level',
        'school_or_work',
        'skills',
        'next_of_kin',
        'next_of_kin_phone',
        'registered_date',
        'status',
        'created_at'
    ] as $column) {
        $select[] = youth_col_exists($conn, 'youth', $column)
            ? "y.`{$column}`"
            : "NULL AS `{$column}`";
    }

    if ($hasMembers) {
        $select[] = "m.full_name";
        $select[] = youth_col_exists($conn, 'members', 'gender') ? 'm.gender' : "NULL AS gender";
        $select[] = youth_col_exists($conn, 'members', 'phone') ? 'm.phone' : "NULL AS phone";
        $select[] = youth_col_exists($conn, 'members', 'email') ? 'm.email' : "NULL AS email";
        $select[] = youth_col_exists($conn, 'members', 'date_of_birth') ? 'm.date_of_birth' : "NULL AS date_of_birth";
        $select[] = youth_col_exists($conn, 'members', 'address') ? 'm.address' : "NULL AS address";
        $select[] = youth_col_exists($conn, 'members', 'occupation') ? 'm.occupation' : "NULL AS occupation";
    } else {
        $select[] = "'' AS full_name";
        $select[] = "NULL AS gender";
        $select[] = "NULL AS phone";
        $select[] = "NULL AS email";
        $select[] = "NULL AS date_of_birth";
        $select[] = "NULL AS address";
        $select[] = "NULL AS occupation";
    }

    $join = $hasMembers ? "LEFT JOIN members m ON m.id = y.member_id" : '';

    return [$select, $join];
}

function youth_fetch_rows(mysqli $conn, string $search = ''): array
{
    [$select, $join] = youth_build_select($conn);

    $where = '';
    $types = '';
    $params = [];

    if ($search !== '') {
        $where = "
            WHERE CONCAT_WS(' ',
                y.id,
                " . (youth_col_exists($conn, 'youth', 'youth_no') ? 'y.youth_no' : "''") . ",
                " . (youth_col_exists($conn, 'youth', 'education_level') ? 'y.education_level' : "''") . ",
                " . (youth_col_exists($conn, 'youth', 'school_or_work') ? 'y.school_or_work' : "''") . ",
                " . (youth_col_exists($conn, 'youth', 'status') ? 'y.status' : "''") . ",
                " . ($join ? "m.full_name, m.phone, m.email, m.gender" : "''") . "
            ) LIKE ?
        ";
        $types = 's';
        $params[] = '%' . $search . '%';
    }

    $sql = "
        SELECT " . implode(', ', $select) . "
        FROM youth y
        {$join}
        {$where}
        ORDER BY y.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        youth_bind_exec($stmt, $types, $params);
    } else {
        $stmt->execute();
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function youth_date_column(mysqli $conn): string
{
    if (youth_col_exists($conn, 'youth', 'registered_date')) {
        return 'y.registered_date';
    }

    if (youth_col_exists($conn, 'youth', 'created_at')) {
        return 'DATE(y.created_at)';
    }

    return '';
}

function youth_valid_date(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function youth_filter_parts(
    mysqli $conn,
    string $search = '',
    string $period = '',
    string $startDate = '',
    string $endDate = ''
): array {
    $conditions = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $conditions[] = "CONCAT_WS(' ',
            y.id,
            " . (youth_col_exists($conn, 'youth', 'youth_no') ? 'y.youth_no' : "''") . ",
            " . (youth_col_exists($conn, 'youth', 'education_level') ? 'y.education_level' : "''") . ",
            " . (youth_col_exists($conn, 'youth', 'school_or_work') ? 'y.school_or_work' : "''") . ",
            " . (youth_col_exists($conn, 'youth', 'status') ? 'y.status' : "''") . ",
            " . (youth_table_exists($conn, 'members') ? "m.full_name, m.phone, m.email, m.gender" : "''") . "
        ) LIKE ?";
        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    $dateColumn = youth_date_column($conn);
    $today = date('Y-m-d');

    if ($dateColumn !== '') {
        if ($period === 'daily') {
            $conditions[] = "{$dateColumn} = ?";
            $types .= 's';
            $params[] = $today;
        } elseif ($period === 'weekly') {
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekEnd = date('Y-m-d', strtotime('sunday this week'));
            $conditions[] = "{$dateColumn} BETWEEN ? AND ?";
            $types .= 'ss';
            $params[] = $weekStart;
            $params[] = $weekEnd;
        } elseif ($period === 'range') {
            $startDate = youth_valid_date($startDate);
            $endDate = youth_valid_date($endDate);

            if ($startDate !== '' && $endDate !== '') {
                if ($startDate > $endDate) {
                    [$startDate, $endDate] = [$endDate, $startDate];
                }

                $conditions[] = "{$dateColumn} BETWEEN ? AND ?";
                $types .= 'ss';
                $params[] = $startDate;
                $params[] = $endDate;
            } elseif ($startDate !== '') {
                $conditions[] = "{$dateColumn} >= ?";
                $types .= 's';
                $params[] = $startDate;
            } elseif ($endDate !== '') {
                $conditions[] = "{$dateColumn} <= ?";
                $types .= 's';
                $params[] = $endDate;
            }
        }
    }

    return [
        $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '',
        $types,
        $params,
    ];
}

function youth_fetch_paginated(
    mysqli $conn,
    string $search,
    string $period,
    string $startDate,
    string $endDate,
    int $page,
    int $perPage
): array {
    [$select, $join] = youth_build_select($conn);
    [$where, $types, $params] = youth_filter_parts(
        $conn,
        $search,
        $period,
        $startDate,
        $endDate
    );

    $page = max(1, $page);
    $allowedPerPage = [10, 25, 50, 100];
    $perPage = in_array($perPage, $allowedPerPage, true) ? $perPage : 10;

    $countSql = "SELECT COUNT(*) AS total FROM youth y {$join} {$where}";
    $countStmt = $conn->prepare($countSql);

    if ($types !== '') {
        youth_bind_exec($countStmt, $types, $params);
    } else {
        $countStmt->execute();
    }

    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT " . implode(', ', $select) . "
            FROM youth y
            {$join}
            {$where}
            ORDER BY y.id DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    youth_bind_exec($stmt, $listTypes, $listParams);

    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    $stmt->close();

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ],
    ];
}

function youth_report_summary(array $rows): array
{
    $summary = [
        'total' => count($rows),
        'active' => 0,
        'inactive' => 0,
        'male' => 0,
        'female' => 0
    ];

    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? 'Active'));
        $gender = strtolower((string)($row['gender'] ?? ''));

        if ($status === 'active' || $status === '') {
            $summary['active']++;
        } else {
            $summary['inactive']++;
        }

        if (in_array($gender, ['male', 'm'], true)) {
            $summary['male']++;
        }

        if (in_array($gender, ['female', 'f'], true)) {
            $summary['female']++;
        }
    }

    return $summary;
}

function youth_export_csv(array $rows): void
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="youth_registry_report_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $out = fopen('php://output', 'w');

    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        '#',
        'Youth No',
        'Full Name',
        'Gender',
        'Phone',
        'Email',
        'Education Level',
        'School / Workplace',
        'Next of Kin',
        'Next of Kin Phone',
        'Skills',
        'Registered Date',
        'Status'
    ]);

    foreach ($rows as $index => $row) {
        fputcsv($out, [
            $index + 1,
            $row['youth_no'] ?? '',
            $row['full_name'] ?? '',
            $row['gender'] ?? '',
            $row['phone'] ?? '',
            $row['email'] ?? '',
            $row['education_level'] ?? '',
            $row['school_or_work'] ?? '',
            $row['next_of_kin'] ?? '',
            $row['next_of_kin_phone'] ?? '',
            $row['skills'] ?? '',
            $row['registered_date'] ?? '',
            $row['status'] ?? 'Active'
        ]);
    }

    fclose($out);
    exit;
}

function youth_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function youth_find_autoload(): string
{
    $paths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function youth_export_pdf(array $rows): void
{
    $autoload = youth_find_autoload();

    if ($autoload === '') {
        youth_json(false, 'DOMPDF autoload file was not found. Please install DOMPDF using Composer or place vendor/autoload.php correctly.');
    }

    require_once $autoload;

    if (!class_exists('\\Dompdf\\Dompdf')) {
        youth_json(false, 'DOMPDF class was not found after loading autoload.php.');
    }

    $summary = youth_report_summary($rows);

    $generatedAt = date('d M Y, h:i A');

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 24px 22px; }
            body {
                font-family: DejaVu Sans, Arial, sans-serif;
                font-size: 11px;
                color: #222;
            }
            .header {
                border-bottom: 2px solid #4a1e8f;
                padding-bottom: 10px;
                margin-bottom: 14px;
            }
            .header h1 {
                margin: 0;
                color: #4a1e8f;
                font-size: 22px;
            }
            .header p {
                margin: 4px 0 0;
                color: #666;
            }
            .summary {
                width: 100%;
                border-collapse: collapse;
                margin: 0 0 14px;
            }
            .summary td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: center;
            }
            .summary strong {
                display: block;
                font-size: 15px;
                color: #4a1e8f;
            }
            table.report {
                width: 100%;
                border-collapse: collapse;
            }
            table.report th {
                background: #4a1e8f;
                color: #fff;
                padding: 7px 5px;
                border: 1px solid #4a1e8f;
                font-size: 9px;
                text-align: left;
            }
            table.report td {
                padding: 6px 5px;
                border: 1px solid #ddd;
                vertical-align: top;
                font-size: 9px;
            }
            table.report tr:nth-child(even) td {
                background: #fafafa;
            }
            .footer {
                margin-top: 16px;
                color: #777;
                font-size: 9px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Youth Registry Report</h1>
            <p>Generated on ' . youth_h($generatedAt) . '</p>
        </div>

        <table class="summary">
            <tr>
                <td><strong>' . (int)$summary['total'] . '</strong>Total Youth</td>
                <td><strong>' . (int)$summary['active'] . '</strong>Active</td>
                <td><strong>' . (int)$summary['inactive'] . '</strong>Inactive</td>
                <td><strong>' . (int)$summary['male'] . '</strong>Male</td>
                <td><strong>' . (int)$summary['female'] . '</strong>Female</td>
            </tr>
        </table>

        <table class="report">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Youth No</th>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Phone</th>
                    <th>Education</th>
                    <th>School/Work</th>
                    <th>Registered</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

    if (!$rows) {
        $html .= '<tr><td colspan="9" style="text-align:center">No youth records found.</td></tr>';
    } else {
        foreach ($rows as $index => $row) {
            $html .= '
                <tr>
                    <td>' . ($index + 1) . '</td>
                    <td>' . youth_h($row['youth_no'] ?? '') . '</td>
                    <td>' . youth_h($row['full_name'] ?? '') . '</td>
                    <td>' . youth_h($row['gender'] ?? '') . '</td>
                    <td>' . youth_h($row['phone'] ?? '') . '</td>
                    <td>' . youth_h($row['education_level'] ?? '') . '</td>
                    <td>' . youth_h($row['school_or_work'] ?? '') . '</td>
                    <td>' . youth_h($row['registered_date'] ?? '') . '</td>
                    <td>' . youth_h($row['status'] ?? 'Active') . '</td>
                </tr>';
        }
    }

    $html .= '
            </tbody>
        </table>

        <div class="footer">
            Youth Registry Report
        </div>
    </body>
    </html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $dompdf->stream('youth_registry_report_' . date('Ymd_His') . '.pdf', [
        'Attachment' => true
    ]);

    exit;
}

try {
    if (!youth_table_exists($conn, 'youth')) {
        youth_json(false, 'Youth table does not exist.');
    }

    $action = youth_clean($_POST['action'] ?? $_GET['action'] ?? 'list');

    if ($action === '') {
        $action = 'list';
    }

    if ($action === 'export_csv') {
        youth_export_csv(youth_fetch_rows($conn, youth_clean($_GET['q'] ?? '')));
    }

    if ($action === 'export_pdf') {
        youth_export_pdf(youth_fetch_rows($conn, youth_clean($_GET['q'] ?? '')));
    }

    if ($action === 'list') {
        $result = youth_fetch_paginated(
            $conn,
            youth_clean($_GET['q'] ?? ''),
            youth_clean($_GET['period'] ?? ''),
            youth_clean($_GET['start_date'] ?? ''),
            youth_clean($_GET['end_date'] ?? ''),
            max(1, (int)($_GET['page'] ?? 1)),
            (int)($_GET['per_page'] ?? 10)
        );

        youth_json(true, 'Loaded.', $result);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

        if ($id <= 0) {
            youth_json(false, 'Invalid youth ID.');
        }

        [$select, $join] = youth_build_select($conn);

        $item = youth_fetch_one(
            $conn,
            "SELECT " . implode(', ', $select) . " FROM youth y {$join} WHERE y.id = ? LIMIT 1",
            'i',
            [$id]
        );

        if (!$item) {
            youth_json(false, 'Youth record not found.');
        }

        youth_json(true, 'Loaded.', ['item' => $item]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        youth_json(false, 'Invalid request method.');
    }

    if ($action === 'create') {
        $memberIds = [];

        if (!empty($_POST['member_ids']) && is_array($_POST['member_ids'])) {
            foreach ($_POST['member_ids'] as $memberId) {
                $memberId = (int)$memberId;
                if ($memberId > 0) {
                    $memberIds[] = $memberId;
                }
            }
        } elseif (!empty($_POST['member_id'])) {
            $memberId = (int)$_POST['member_id'];
            if ($memberId > 0) {
                $memberIds[] = $memberId;
            }
        }

        $memberIds = array_values(array_unique($memberIds));

        if (!$memberIds) {
            youth_json(false, 'Please select at least one member.');
        }

        $sharedData = [
            'education_level' => youth_get('education_level'),
            'school_or_work' => youth_get('school_or_work'),
            'skills' => youth_get('skills'),
            'next_of_kin' => youth_get('next_of_kin'),
            'next_of_kin_phone' => youth_get('next_of_kin_phone'),
            'registered_date' => youth_get('registered_date', date('Y-m-d')) ?: date('Y-m-d'),
            'status' => youth_get('status', 'Active') ?: 'Active',
        ];

        $inserted = 0;
        $skipped = 0;
        $errors = [];

        $conn->begin_transaction();

        try {
            foreach ($memberIds as $memberId) {
                if (youth_col_exists($conn, 'youth', 'member_id')) {
                    $exists = youth_fetch_one($conn, "SELECT id FROM youth WHERE member_id = ? LIMIT 1", 'i', [$memberId]);

                    if ($exists) {
                        $skipped++;
                        continue;
                    }
                }

                $data = array_merge($sharedData, [
                    'member_id' => (string)$memberId
                ]);

                if (youth_col_exists($conn, 'youth', 'youth_no')) {
                    $data['youth_no'] = youth_gen_no($conn);
                }

                youth_insert_dynamic($conn, 'youth', $data);
                $inserted++;
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        }

        if ($inserted === 0 && $skipped > 0 && !$errors) {
            youth_json(false, "All selected members ({$skipped}) are already registered as youth.");
        }

        if ($inserted === 0 && $errors) {
            youth_json(false, 'No youth records were saved. ' . implode('; ', $errors));
        }

        $message = $inserted === 1
            ? 'Youth registered successfully.'
            : "{$inserted} youth members registered successfully.";

        if ($skipped > 0) {
            $message .= " ({$skipped} skipped because they are already registered.)";
        }

        if ($errors) {
            $message .= ' Errors: ' . implode('; ', $errors);
        }

        youth_json(true, $message, [
            'inserted' => $inserted,
            'skipped' => $skipped
        ]);
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            youth_json(false, 'Invalid youth ID.');
        }

        $data = [
            'education_level' => youth_get('education_level'),
            'school_or_work' => youth_get('school_or_work'),
            'skills' => youth_get('skills'),
            'next_of_kin' => youth_get('next_of_kin'),
            'next_of_kin_phone' => youth_get('next_of_kin_phone'),
            'registered_date' => youth_get('registered_date', date('Y-m-d')) ?: date('Y-m-d'),
            'status' => youth_get('status', 'Active') ?: 'Active',
        ];

        youth_update_dynamic($conn, 'youth', $data, $id);

        youth_json(true, 'Youth updated successfully.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            youth_json(false, 'Invalid youth ID.');
        }

        if (youth_col_exists($conn, 'youth', 'status')) {
            youth_update_dynamic($conn, 'youth', ['status' => 'Inactive'], $id);
            youth_json(true, 'Youth deactivated successfully.');
        }

        $stmt = $conn->prepare('DELETE FROM youth WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        youth_json(true, 'Youth deleted successfully.');
    }

    youth_json(false, 'Invalid action.');
} catch (Throwable $e) {
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
    } catch (Throwable $ignore) {
    }

    error_log('[process-youth.php] ' . $e->getMessage());

    youth_json(false, 'Server error: ' . $e->getMessage());
}