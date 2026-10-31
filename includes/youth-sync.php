<?php
declare(strict_types=1);

/*
 * Youth auto-sync: keeps the youth registry in step with members' dates of birth.
 *
 * - Active members whose age is within the youth range (Site Settings > General, default 13-35) and who have no
 *   youth record are registered automatically.
 * - Active youth older than the range are marked 'Aged Out' (kept, never deleted), unless an admin set them
 *   back to Active, which marks them age_exempt (for example a youth leader).
 * - 'Aged Out' youth who are back in range (the range was widened) become Active again.
 * Members without a date of birth are left alone; manual registration still works for anyone.
 */

const YOUTH_DEFAULT_MIN_AGE = 13;
const YOUTH_DEFAULT_MAX_AGE = 35;

/** One site_settings value; uses site_setting() where the site has it, otherwise reads the table directly. */
function youth_setting(string $key, string $default): string
{
    if (function_exists('site_setting')) return site_setting($key, $default);
    $conn = $GLOBALS['conn'] ?? null;
    if (!($conn instanceof mysqli)) return $default;
    try {
        $stmt = $conn->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $value = trim((string)($stmt->get_result()->fetch_assoc()['setting_value'] ?? ''));
        $stmt->close();
        return $value !== '' ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/** The youth age range from Site Settings: [min, max]. */
function youth_age_range(): array
{
    $min = (int)youth_setting('youth_min_age', (string)YOUTH_DEFAULT_MIN_AGE);
    $max = (int)youth_setting('youth_max_age', (string)YOUTH_DEFAULT_MAX_AGE);
    if ($min < 1 || $min > 99 || $max < $min || $max > 99) {
        return [YOUTH_DEFAULT_MIN_AGE, YOUTH_DEFAULT_MAX_AGE];
    }
    return [$min, $max];
}

function youth_sync_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

/** Adds the columns and the 'Aged Out' status the sync needs, once. */
function youth_sync_prepare(mysqli $conn): bool
{
    if (!youth_sync_column_exists($conn, 'youth', 'member_id') || !youth_sync_column_exists($conn, 'youth', 'status')
        || !youth_sync_column_exists($conn, 'members', 'date_of_birth')) {
        return false;
    }

    $res = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'youth' AND COLUMN_NAME = 'status'");
    $type = strtolower((string)($res ? ($res->fetch_assoc()['COLUMN_TYPE'] ?? '') : ''));
    if (str_starts_with($type, 'enum(') && !str_contains($type, "'aged out'")) {
        $conn->query("ALTER TABLE youth MODIFY status ENUM('Active','Inactive','Aged Out') DEFAULT 'Active'");
    }
    if (!youth_sync_column_exists($conn, 'youth', 'auto_added')) {
        $conn->query('ALTER TABLE youth ADD COLUMN auto_added TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!youth_sync_column_exists($conn, 'youth', 'age_exempt')) {
        $conn->query('ALTER TABLE youth ADD COLUMN age_exempt TINYINT(1) NOT NULL DEFAULT 0');
    }
    return true;
}

/** Next youth numbers (YTH-<year>-0001 ...) continuing from the highest used this year. */
function youth_sync_next_numbers(mysqli $conn, int $count): array
{
    $prefix = 'YTH-' . date('Y') . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT youth_no FROM youth WHERE youth_no LIKE ?");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = 0;
    while ($row = $res->fetch_assoc()) {
        if (preg_match('/(\d+)$/', (string)$row['youth_no'], $m)) $last = max($last, (int)$m[1]);
    }
    $stmt->close();

    $numbers = [];
    for ($i = 1; $i <= $count; $i++) {
        $numbers[] = $prefix . str_pad((string)($last + $i), 4, '0', STR_PAD_LEFT);
    }
    return $numbers;
}

/**
 * Runs the sync. Returns ['ran' => bool, 'added' => int, 'aged_out' => int, 'restored' => int,
 * 'no_dob' => int (active members with no date of birth), 'min' => int, 'max' => int].
 */
function youth_auto_sync(mysqli $conn): array
{
    [$min, $max] = youth_age_range();
    $result = ['ran' => false, 'added' => 0, 'aged_out' => 0, 'restored' => 0, 'no_dob' => 0, 'min' => $min, 'max' => $max];

    try {
        if (!youth_sync_prepare($conn)) return $result;

        $age = 'TIMESTAMPDIFF(YEAR, m.date_of_birth, CURDATE())';
        $activeMember = "COALESCE(m.status, 'Active') = 'Active'";

        $conn->begin_transaction();

        // 1. Register members in range who have no youth record yet.
        $stmt = $conn->prepare(
            "SELECT m.id FROM members m
             LEFT JOIN youth y ON y.member_id = m.id
             WHERE y.id IS NULL AND $activeMember AND m.date_of_birth IS NOT NULL
               AND $age BETWEEN ? AND ?
             ORDER BY m.id"
        );
        $stmt->bind_param('ii', $min, $max);
        $stmt->execute();
        $memberIds = array_map(fn($r) => (int)$r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();

        if ($memberIds) {
            $hasYouthNo = youth_sync_column_exists($conn, 'youth', 'youth_no');
            $numbers = $hasYouthNo ? youth_sync_next_numbers($conn, count($memberIds)) : [];
            $today = date('Y-m-d');
            $insert = $hasYouthNo
                ? $conn->prepare("INSERT INTO youth (member_id, youth_no, registered_date, status, auto_added) VALUES (?, ?, ?, 'Active', 1)")
                : $conn->prepare("INSERT INTO youth (member_id, registered_date, status, auto_added) VALUES (?, ?, 'Active', 1)");
            foreach ($memberIds as $i => $memberId) {
                if ($hasYouthNo) $insert->bind_param('iss', $memberId, $numbers[$i], $today);
                else $insert->bind_param('is', $memberId, $today);
                $insert->execute();
                $result['added']++;
            }
            $insert->close();
        }

        // 2. Mark youth past the top age as Aged Out, unless an admin exempted them.
        $stmt = $conn->prepare(
            "UPDATE youth y JOIN members m ON m.id = y.member_id
             SET y.status = 'Aged Out'
             WHERE y.status = 'Active' AND y.age_exempt = 0 AND m.date_of_birth IS NOT NULL AND $age > ?"
        );
        $stmt->bind_param('i', $max);
        $stmt->execute();
        $result['aged_out'] = $stmt->affected_rows;
        $stmt->close();

        // 3. Bring back Aged Out youth who are in range again (the range was widened).
        $stmt = $conn->prepare(
            "UPDATE youth y JOIN members m ON m.id = y.member_id
             SET y.status = 'Active'
             WHERE y.status = 'Aged Out' AND m.date_of_birth IS NOT NULL AND $age BETWEEN ? AND ?"
        );
        $stmt->bind_param('ii', $min, $max);
        $stmt->execute();
        $result['restored'] = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();

        $res = $conn->query("SELECT COUNT(*) AS c FROM members m WHERE $activeMember AND m.date_of_birth IS NULL");
        $result['no_dob'] = (int)($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
        $result['ran'] = true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('[youth auto-sync] ' . $e->getMessage());
    }

    return $result;
}

/** Age in whole years from a Y-m-d date of birth, or null. */
function youth_age_from_dob(?string $dob): ?int
{
    if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}/', $dob)) return null;
    try {
        return (new DateTimeImmutable(substr($dob, 0, 10)))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable $e) {
        return null;
    }
}
