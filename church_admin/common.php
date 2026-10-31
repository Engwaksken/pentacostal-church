<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('ce_h')) {
    function ce_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ce_csrf')) {
    function ce_csrf(): string
    {
        if (empty($_SESSION['ce_csrf']) || !is_string($_SESSION['ce_csrf'])) {
            $_SESSION['ce_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['ce_csrf'];
    }
}

if (!function_exists('ce_verify_csrf')) {
    function ce_verify_csrf(): void
    {
        $token = trim((string)($_POST['csrf_token'] ?? ''));

        if ($token === '' || !hash_equals(ce_csrf(), $token)) {
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }
    }
}

if (!function_exists('ce_user_id')) {
    function ce_user_id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('ce_require_admin')) {
    function ce_require_admin(): void
    {
        if (ce_user_id() < 1) {
            http_response_code(401);
            exit('Please log in first.');
        }

        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $role = str_replace('-', '_', $role);

        $allowed = [
            'administrator',
            'admin',
            'super admin',
            'super_admin',
            'pastor',
            'vicar',
            'secretary',
        ];

        if (!in_array($role, $allowed, true)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}

if (!function_exists('ce_flash')) {
    function ce_flash(string $type, string $message): void
    {
        $_SESSION['ce_flash'] = [
            'type' => trim($type) !== '' ? trim($type) : 'info',
            'message' => $message,
        ];
    }
}

if (!function_exists('ce_show_flash')) {
    function ce_show_flash(): void
    {
        $flash = $_SESSION['ce_flash'] ?? null;
        unset($_SESSION['ce_flash']);

        if (!is_array($flash)) {
            return;
        }

        $type = preg_replace('/[^a-z0-9_-]/i', '', (string)($flash['type'] ?? 'info'));
        $message = trim((string)($flash['message'] ?? ''));

        if ($message === '') {
            return;
        }

        echo '<div class="alert alert-' . ce_h($type ?: 'info') . '">'
            . ce_h($message)
            . '</div>';
    }
}

if (!function_exists('ce_redirect')) {
    function ce_redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('ce_query_all')) {
    function ce_query_all(mysqli $conn, string $sql): array
    {
        $result = $conn->query($sql);

        if (!$result) {
            throw new RuntimeException($conn->error);
        }

        return $result->fetch_all(MYSQLI_ASSOC) ?: [];
    }
}

if (!function_exists('ce_table_exists')) {
    function ce_table_exists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = (int)($result->fetch_assoc()['total'] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('ce_table_columns')) {
    function ce_table_columns(mysqli $conn, string $table): array
    {
        if (!ce_table_exists($conn, $table)) {
            return [];
        }

        $safeTable = str_replace('`', '``', $table);
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

        if (!$result) {
            throw new RuntimeException($conn->error);
        }

        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $column = trim((string)($row['Field'] ?? ''));

            if ($column !== '') {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}

if (!function_exists('ce_date')) {
    function ce_date(mixed $value, string $format = 'd M Y'): string
    {
        $value = trim((string)$value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '-' : date($format, $timestamp);
    }
}

if (!function_exists('ce_time')) {
    function ce_time(mixed $value, string $format = 'H:i'): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '-' : date($format, $timestamp);
    }
}

if (!function_exists('ce_media_url')) {
    function ce_media_url(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        if (str_starts_with($path, '../') || str_starts_with($path, '/')) {
            return $path;
        }

        if (str_starts_with($path, 'assets/') || str_starts_with($path, 'uploads/')) {
            return '../' . ltrim($path, '/');
        }

        return '../assets/uploads/' . ltrim($path, '/');
    }
}

if (!function_exists('ce_departments')) {
    function ce_departments(mysqli $conn): array
    {
        $columns = ce_table_columns($conn, 'departments');

        if ($columns === []) {
            return [];
        }

        $idColumn = in_array('id', $columns, true)
            ? 'id'
            : (in_array('department_id', $columns, true) ? 'department_id' : '');

        $nameColumn = in_array('department_name', $columns, true)
            ? 'department_name'
            : (in_array('name', $columns, true)
                ? 'name'
                : (in_array('title', $columns, true) ? 'title' : ''));

        if ($idColumn === '' || $nameColumn === '') {
            return [];
        }

        $sql = "SELECT `{$idColumn}` AS id, `{$nameColumn}` AS name
                FROM departments
                WHERE `{$nameColumn}` IS NOT NULL
                  AND TRIM(`{$nameColumn}`) <> ''";

        if (in_array('is_active', $columns, true)) {
            $sql .= ' AND is_active = 1';
        } elseif (in_array('status', $columns, true)) {
            $sql .= " AND LOWER(TRIM(status)) IN ('active', '1')";
        }

        $sql .= " ORDER BY `{$nameColumn}` ASC";

        return ce_query_all($conn, $sql);
    }
}

if (!function_exists('ce_attach_departments')) {
    function ce_attach_departments(
        mysqli $conn,
        array $rows,
        string $mapTable,
        string $foreignKey,
        array $departmentNames
    ): array {
        foreach ($rows as &$row) {
            $row['department_ids'] = '';
            $row['department_names'] = '';
        }
        unset($row);

        if ($rows === [] || !ce_table_exists($conn, $mapTable)) {
            return $rows;
        }

        $columns = ce_table_columns($conn, $mapTable);

        if (
            !in_array($foreignKey, $columns, true)
            || !in_array('department_id', $columns, true)
        ) {
            return $rows;
        }

        $safeTable = str_replace('`', '``', $mapTable);
        $safeForeignKey = str_replace('`', '``', $foreignKey);

        $links = ce_query_all(
            $conn,
            "SELECT `{$safeForeignKey}` AS record_id, department_id
             FROM `{$safeTable}`
             ORDER BY department_id ASC"
        );

        $mapping = [];

        foreach ($links as $link) {
            $recordId = (int)($link['record_id'] ?? 0);
            $departmentId = (int)($link['department_id'] ?? 0);

            if ($recordId > 0 && $departmentId > 0) {
                $mapping[$recordId][] = $departmentId;
            }
        }

        foreach ($rows as &$row) {
            $recordId = (int)($row['id'] ?? 0);
            $ids = array_values(array_unique($mapping[$recordId] ?? []));
            $names = [];

            foreach ($ids as $departmentId) {
                if (isset($departmentNames[$departmentId])) {
                    $names[] = (string)$departmentNames[$departmentId];
                }
            }

            $row['department_ids'] = implode(',', $ids);
            $row['department_names'] = implode(', ', $names);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('ce_form_header')) {
    function ce_form_header(string $action, string $returnTo): void
    {
        echo '<input type="hidden" name="csrf_token" value="' . ce_h(ce_csrf()) . '">';
        echo '<input type="hidden" name="action" value="' . ce_h($action) . '">';
        echo '<input type="hidden" name="return_to" value="' . ce_h($returnTo) . '">';
    }
}
