<?php
declare(strict_types=1);

/*
 * One-time conversion of existing member numbers, run the first time the Members page opens after this update
 * (the same change as database/church_2025_member_numbers.sql, so nobody has to run SQL by hand):
 *   1. MEM-<year>-#### becomes MEM-#### (the number part is kept).
 *   2. Each member's login username becomes their member number.
 *   3. Pledge notes "Imported from BWEYAMO 2025.xlsx" become "2025 Pledge" (only where that note exists).
 * A site_settings flag makes it run once. If any two members would end up with the same number, nothing is
 * changed and the page says so.
 */

/**
 * Returns null when there was nothing to do (already done), otherwise
 * ['ok' => bool, 'renumbered' => int, 'usernames' => int, 'pledges' => int, 'message' => string].
 */
function member_numbers_migrate_once(mysqli $conn): ?array
{
    $key = 'member_numbers_without_year_done';
    $newNo = "IF(member_no LIKE 'MEM-____-%', CONCAT('MEM-', SUBSTRING(member_no, 10)), member_no)";

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS site_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $conn->prepare('SELECT 1 FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $done = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($done) return null;

        // Nothing would clash? Two members must never end up with the same number or username.
        $clashNos = (int)($conn->query("SELECT COUNT(*) AS c FROM (SELECT $newNo AS n FROM members WHERE member_no LIKE 'MEM-%' GROUP BY n HAVING COUNT(*) > 1) x")->fetch_assoc()['c'] ?? 0);
        $clashUsers = (int)($conn->query(
            "SELECT COUNT(*) AS c FROM members m JOIN users other ON other.username = IF(m.member_no LIKE 'MEM-____-%', CONCAT('MEM-', SUBSTRING(m.member_no, 10)), m.member_no) AND other.id <> m.user_id WHERE m.user_id IS NOT NULL"
        )->fetch_assoc()['c'] ?? 0);
        if ($clashNos > 0 || $clashUsers > 0) {
            return ['ok' => false, 'renumbered' => 0, 'usernames' => 0, 'pledges' => 0,
                'message' => "Member numbers were not converted: {$clashNos} number(s) and {$clashUsers} username(s) would clash. Nothing was changed."];
        }

        // Claim the flag first so two admins opening the page at once cannot both run it.
        $stamp = date('Y-m-d H:i:s');
        $stmt = $conn->prepare('INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)');
        $stmt->bind_param('ss', $key, $stamp);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) return null;

        $conn->begin_transaction();
        try {
            $conn->query("UPDATE members SET member_no = CONCAT('MEM-', SUBSTRING(member_no, 10)) WHERE member_no LIKE 'MEM-____-%'");
            $renumbered = $conn->affected_rows;

            $conn->query("UPDATE users u JOIN members m ON m.user_id = u.id SET u.username = m.member_no WHERE m.member_no <> '' AND u.username <> m.member_no");
            $usernames = $conn->affected_rows;

            $pledges = 0;
            $hasPledgeNotes = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME = 'notes'");
            if ($hasPledgeNotes && $hasPledgeNotes->num_rows > 0) {
                $conn->query("UPDATE pledges SET notes = '2025 Pledge' WHERE notes = 'Imported from BWEYAMO 2025.xlsx'");
                $pledges = $conn->affected_rows;
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $parts = [];
        if ($renumbered > 0) $parts[] = "{$renumbered} member number" . ($renumbered === 1 ? '' : 's') . ' changed to MEM-#### (without the year)';
        if ($usernames > 0) $parts[] = "{$usernames} login username" . ($usernames === 1 ? '' : 's') . ' set to the member number';
        if ($pledges > 0) $parts[] = "{$pledges} pledge note" . ($pledges === 1 ? '' : 's') . ' changed to "2025 Pledge"';
        return ['ok' => true, 'renumbered' => $renumbered, 'usernames' => $usernames, 'pledges' => $pledges,
            'message' => $parts ? ucfirst(implode('; ', $parts)) . '. Members now sign in with their member number or email.' : ''];
    } catch (Throwable $e) {
        error_log('[member number migration] ' . $e->getMessage());
        // Release the flag so it is tried again next time.
        try {
            $stmt = $conn->prepare('DELETE FROM site_settings WHERE setting_key = ?');
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ignore) {}
        return ['ok' => false, 'renumbered' => 0, 'usernames' => 0, 'pledges' => 0,
            'message' => 'Member numbers could not be converted: ' . $e->getMessage()];
    }
}
