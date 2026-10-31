<?php
declare(strict_types=1);

/*
 * Sunday school auto-sync from dates of birth. Runs when the Sunday School page opens.
 *
 * 1. Enrol child members: active members whose age is within the Sunday school range (the youngest class's
 *    lowest age to the oldest class's highest age) become pupils. A pupil entered by hand with the same name and
 *    date of birth is linked to the member instead of being duplicated.
 * 2. Place pupils in classes: each active pupil goes to the class whose age range ("3-5 years") fits their age,
 *    and moves up as they grow. Pupils older than every class are marked Graduated.
 *    Pupils whose age falls in a gap between classes stay where they are.
 * An admin who puts a pupil in a class other than the one for their age (or keeps an over-age pupil Active)
 * locks that pupil, and the sync leaves them alone; see sunday_school_lock_for_edit().
 */

/** [min, max] from a class age range such as "3-5 years", "6 to 9", "13+" or "4"; null when there is no age. */
function sunday_school_parse_range(?string $text): ?array
{
    $text = strtolower(trim((string)$text));
    if (preg_match('/(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})/u', $text, $m)) {
        $min = (int)$m[1]; $max = (int)$m[2];
        return $min <= $max ? [$min, $max] : [$max, $min];
    }
    if (preg_match('/(\d{1,2})\s*\+/', $text, $m)) return [(int)$m[1], 99];
    if (preg_match('/^\D*(\d{1,2})\D*$/', $text, $m)) return [(int)$m[1], (int)$m[1]];
    return null;
}

/** Classes that have a usable age range: [['id' => int, 'name' => string, 'min' => int, 'max' => int], ...]. */
function sunday_school_age_classes(mysqli $conn): array
{
    $classes = [];
    $res = $conn->query('SELECT id, class_name, age_range FROM sunday_school_classes');
    while ($res && $row = $res->fetch_assoc()) {
        $range = sunday_school_parse_range($row['age_range'] ?? '');
        if ($range) $classes[] = ['id' => (int)$row['id'], 'name' => (string)$row['class_name'], 'min' => $range[0], 'max' => $range[1]];
    }
    // Narrowest range first, so an age covered by two classes goes to the more specific one.
    usort($classes, fn($a, $b) => [$a['max'] - $a['min'], $a['min']] <=> [$b['max'] - $b['min'], $b['min']]);
    return $classes;
}

/** The class id for an age, or null when no class covers it. */
function sunday_school_class_for_age(array $classes, ?int $age): ?int
{
    if ($age === null) return null;
    foreach ($classes as $class) {
        if ($age >= $class['min'] && $age <= $class['max']) return $class['id'];
    }
    return null;
}

function sunday_school_age(?string $dob): ?int
{
    if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}/', $dob)) return null;
    try {
        return (new DateTimeImmutable(substr($dob, 0, 10)))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable $e) {
        return null;
    }
}

function sunday_school_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

/** Adds the columns the sync needs, once. Returns false when the Sunday school tables are missing. */
function sunday_school_sync_prepare(mysqli $conn): bool
{
    if (!sunday_school_column_exists($conn, 'sunday_school_pupils', 'date_of_birth')
        || !sunday_school_column_exists($conn, 'sunday_school_classes', 'age_range')) {
        return false;
    }
    if (!sunday_school_column_exists($conn, 'sunday_school_pupils', 'member_id')) {
        $conn->query('ALTER TABLE sunday_school_pupils ADD COLUMN member_id INT NULL, ADD INDEX idx_ss_pupils_member (member_id)');
    }
    if (!sunday_school_column_exists($conn, 'sunday_school_pupils', 'auto_added')) {
        $conn->query('ALTER TABLE sunday_school_pupils ADD COLUMN auto_added TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!sunday_school_column_exists($conn, 'sunday_school_pupils', 'class_locked')) {
        $conn->query('ALTER TABLE sunday_school_pupils ADD COLUMN class_locked TINYINT(1) NOT NULL DEFAULT 0');
    }
    return true;
}

/** Next pupil numbers (SSP-<year>-0001 ...) continuing from the highest used this year. */
function sunday_school_next_numbers(mysqli $conn, int $count): array
{
    $prefix = 'SSP-' . date('Y') . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare('SELECT pupil_no FROM sunday_school_pupils WHERE pupil_no LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = 0;
    while ($row = $res->fetch_assoc()) {
        if (preg_match('/(\d+)$/', (string)$row['pupil_no'], $m)) $last = max($last, (int)$m[1]);
    }
    $stmt->close();
    $numbers = [];
    for ($i = 1; $i <= $count; $i++) $numbers[] = $prefix . str_pad((string)($last + $i), 4, '0', STR_PAD_LEFT);
    return $numbers;
}

/**
 * Runs the sync. Returns ['ran', 'min', 'max', 'enrolled', 'linked', 'moved', 'graduated',
 * 'no_class' (active pupils whose age has no class), 'no_dob' (active pupils without a date of birth),
 * 'gaps' (ages between min and max that no class covers)].
 */
function sunday_school_auto_sync(mysqli $conn): array
{
    $result = ['ran' => false, 'min' => null, 'max' => null, 'enrolled' => 0, 'linked' => 0, 'moved' => 0,
        'graduated' => 0, 'no_class' => 0, 'no_dob' => 0, 'gaps' => []];

    try {
        if (!sunday_school_sync_prepare($conn)) return $result;
        $classes = sunday_school_age_classes($conn);
        if (!$classes) {
            $result['ran'] = true;
            return $result;
        }
        $min = min(array_column($classes, 'min'));
        $max = max(array_column($classes, 'max'));
        $result['min'] = $min;
        $result['max'] = $max;
        for ($age = $min; $age <= $max; $age++) {
            if (sunday_school_class_for_age($classes, $age) === null) $result['gaps'][] = $age;
        }

        $conn->begin_transaction();

        // 1. Enrol child members in the Sunday school age range.
        $hasMembers = sunday_school_column_exists($conn, 'members', 'date_of_birth');
        if ($hasMembers) {
            $hasGender = sunday_school_column_exists($conn, 'members', 'gender');
            $stmt = $conn->prepare(
                "SELECT m.id, m.full_name, " . ($hasGender ? 'm.gender' : 'NULL') . " AS gender, m.date_of_birth
                 FROM members m
                 LEFT JOIN sunday_school_pupils p ON p.member_id = m.id
                 WHERE p.id IS NULL AND COALESCE(m.status, 'Active') = 'Active' AND m.date_of_birth IS NOT NULL
                   AND TIMESTAMPDIFF(YEAR, m.date_of_birth, CURDATE()) BETWEEN ? AND ?
                 ORDER BY m.id"
            );
            $stmt->bind_param('ii', $min, $max);
            $stmt->execute();
            $children = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $findManual = $conn->prepare(
                'SELECT id FROM sunday_school_pupils
                 WHERE member_id IS NULL AND date_of_birth = ? AND LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1'
            );
            $link = $conn->prepare('UPDATE sunday_school_pupils SET member_id = ? WHERE id = ?');
            $toInsert = [];
            foreach ($children as $child) {
                $findManual->bind_param('ss', $child['date_of_birth'], $child['full_name']);
                $findManual->execute();
                $existing = $findManual->get_result()->fetch_assoc();
                if ($existing) {
                    $memberId = (int)$child['id'];
                    $pupilId = (int)$existing['id'];
                    $link->bind_param('ii', $memberId, $pupilId);
                    $link->execute();
                    $result['linked']++;
                } else {
                    $toInsert[] = $child;
                }
            }
            $findManual->close();
            $link->close();

            if ($toInsert) {
                $numbers = sunday_school_next_numbers($conn, count($toInsert));
                $today = date('Y-m-d');
                $insert = $conn->prepare(
                    "INSERT INTO sunday_school_pupils (pupil_no, full_name, gender, date_of_birth, member_id, status, date_enrolled, auto_added)
                     VALUES (?, ?, ?, ?, ?, 'Active', ?, 1)"
                );
                foreach ($toInsert as $i => $child) {
                    $g = strtolower(trim((string)$child['gender']));
                    $gender = in_array($g, ['male', 'm'], true) ? 'Male' : (in_array($g, ['female', 'f'], true) ? 'Female' : null);
                    $memberId = (int)$child['id'];
                    $insert->bind_param('ssssis', $numbers[$i], $child['full_name'], $gender, $child['date_of_birth'], $memberId, $today);
                    $insert->execute();
                    $result['enrolled']++;
                }
                $insert->close();
            }
        }

        // 2. Place active, unlocked pupils by age; graduate those older than every class.
        $res = $conn->query("SELECT id, class_id, date_of_birth FROM sunday_school_pupils WHERE COALESCE(status, 'Active') = 'Active' AND class_locked = 0");
        $move = $conn->prepare('UPDATE sunday_school_pupils SET class_id = ? WHERE id = ?');
        $graduate = $conn->prepare("UPDATE sunday_school_pupils SET status = 'Graduated' WHERE id = ?");
        while ($res && $pupil = $res->fetch_assoc()) {
            $age = sunday_school_age($pupil['date_of_birth']);
            if ($age === null) {
                $result['no_dob']++;
                continue;
            }
            $id = (int)$pupil['id'];
            if ($age > $max) {
                $graduate->bind_param('i', $id);
                $graduate->execute();
                $result['graduated']++;
                continue;
            }
            $classId = sunday_school_class_for_age($classes, $age);
            if ($classId === null) {
                $result['no_class']++;
                continue;
            }
            if ((int)$pupil['class_id'] !== $classId) {
                $move->bind_param('ii', $classId, $id);
                $move->execute();
                $result['moved']++;
            }
        }
        $move->close();
        $graduate->close();

        $conn->commit();
        $result['ran'] = true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('[sunday school auto-sync] ' . $e->getMessage());
    }

    return $result;
}

/**
 * Checks a class age range an admin entered. Returns an error message, or null when it is fine.
 * Ranges may not overlap another class, so every age has exactly one class.
 */
function sunday_school_check_class_range(mysqli $conn, int $min, int $max, int $excludeClassId = 0): ?string
{
    if ($min < 0 || $max > 99) return 'Ages must be between 0 and 99.';
    if ($min > $max) return 'The "From" age cannot be higher than the "To" age.';
    foreach (sunday_school_age_classes($conn) as $class) {
        if ($class['id'] === $excludeClassId) continue;
        if ($min <= $class['max'] && $max >= $class['min']) {
            return sprintf('Ages %d-%d overlap with the %s class (%d-%d years). Each age can belong to only one class.',
                $min, $max, $class['name'], $class['min'], $class['max']);
        }
    }
    return null;
}

/** The stored text for an age range, e.g. "3-5 years" or "4 years". */
function sunday_school_format_range(int $min, int $max): string
{
    return $min === $max ? $min . ' years' : $min . '-' . $max . ' years';
}

/** A short summary of what a sync changed, for a save message; '' when nothing changed. */
function sunday_school_sync_summary(array $sync): string
{
    if (empty($sync['ran'])) return '';
    $notes = [];
    if ($sync['enrolled'] > 0) $notes[] = $sync['enrolled'] . ' child member' . ($sync['enrolled'] === 1 ? '' : 's') . ' enrolled';
    if ($sync['moved'] > 0) $notes[] = $sync['moved'] . ' pupil' . ($sync['moved'] === 1 ? '' : 's') . ' moved to the class for their age';
    if ($sync['graduated'] > 0) $notes[] = $sync['graduated'] . ' graduated';
    $text = $notes ? ' ' . ucfirst(implode(', ', $notes)) . '.' : '';
    if ($sync['gaps']) {
        $text .= ' Note: no class covers age' . (count($sync['gaps']) === 1 ? ' ' : 's ') . implode(', ', $sync['gaps']) . '.';
    }
    return $text;
}

/** The standard Sunday school classes: pre-school, primary school and teenagers, covering ages 3-17 with no gap. */
const SUNDAY_SCHOOL_STANDARD_CLASSES = [
    ['name' => 'Nursery', 'min' => 3,  'max' => 5],
    ['name' => 'Junior',  'min' => 6,  'max' => 12],
    ['name' => 'Senior',  'min' => 13, 'max' => 17],
];

/**
 * Sets up the three standard classes. An existing class with the same name (or, failing that, the most similar
 * age range) is reused, so its teacher, capacity and notes are kept. Other classes lose their pupils, who are
 * re-placed by age; an extra class is deleted unless it has attendance history, in which case it is kept with its
 * age range cleared so that history still shows the class. Returns a summary for the save message.
 */
function sunday_school_apply_standard_classes(mysqli $conn): array
{
    sunday_school_sync_prepare($conn);
    $summary = ['updated' => [], 'created' => [], 'deleted' => [], 'kept' => []];

    $existing = [];
    $res = $conn->query('SELECT id, class_name, age_range FROM sunday_school_classes ORDER BY id');
    while ($res && $row = $res->fetch_assoc()) {
        $range = sunday_school_parse_range($row['age_range'] ?? '');
        $existing[(int)$row['id']] = ['id' => (int)$row['id'], 'name' => (string)$row['class_name'], 'range' => $range];
    }

    // Choose which existing class becomes each standard class: same name first, then the closest age range.
    $chosen = [];
    foreach (SUNDAY_SCHOOL_STANDARD_CLASSES as $i => $std) {
        foreach ($existing as $id => $class) {
            if (!in_array($id, $chosen, true) && strcasecmp(trim($class['name']), $std['name']) === 0) { $chosen[$i] = $id; break; }
        }
    }
    foreach (SUNDAY_SCHOOL_STANDARD_CLASSES as $i => $std) {
        if (isset($chosen[$i])) continue;
        $best = null; $bestOverlap = 0;
        foreach ($existing as $id => $class) {
            if (in_array($id, $chosen, true) || !$class['range']) continue;
            $overlap = min($std['max'], $class['range'][1]) - max($std['min'], $class['range'][0]) + 1;
            if ($overlap > $bestOverlap) { $best = $id; $bestOverlap = $overlap; }
        }
        if ($best !== null) $chosen[$i] = $best;
    }

    $conn->begin_transaction();
    try {
        $update = $conn->prepare('UPDATE sunday_school_classes SET class_name = ?, age_range = ? WHERE id = ?');
        $insert = $conn->prepare('INSERT INTO sunday_school_classes (class_name, age_range) VALUES (?, ?)');
        foreach (SUNDAY_SCHOOL_STANDARD_CLASSES as $i => $std) {
            $name = $std['name'];
            $range = sunday_school_format_range($std['min'], $std['max']);
            if (isset($chosen[$i])) {
                $update->bind_param('ssi', $name, $range, $chosen[$i]);
                $update->execute();
                $summary['updated'][] = $name;
            } else {
                $insert->bind_param('ss', $name, $range);
                $insert->execute();
                $summary['created'][] = $name;
            }
        }
        $update->close();
        $insert->close();

        $extraIds = array_diff(array_keys($existing), $chosen);
        $release = $conn->prepare('UPDATE sunday_school_pupils SET class_id = NULL, class_locked = 0 WHERE class_id = ?');
        $history = $conn->prepare('SELECT COUNT(*) AS c FROM sunday_school_attendance WHERE class_id = ?');
        $delete = $conn->prepare('DELETE FROM sunday_school_classes WHERE id = ?');
        $clear = $conn->prepare("UPDATE sunday_school_classes SET age_range = '' WHERE id = ?");
        foreach ($extraIds as $id) {
            $release->bind_param('i', $id);
            $release->execute();
            $history->bind_param('i', $id);
            $history->execute();
            $hasHistory = (int)($history->get_result()->fetch_assoc()['c'] ?? 0) > 0;
            if ($hasHistory) {
                $clear->bind_param('i', $id);
                $clear->execute();
                $summary['kept'][] = $existing[$id]['name'];
            } else {
                $delete->bind_param('i', $id);
                $delete->execute();
                $summary['deleted'][] = $existing[$id]['name'];
            }
        }
        $release->close();
        $history->close();
        $delete->close();
        $clear->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $summary['sync'] = sunday_school_auto_sync($conn);
    return $summary;
}

/**
 * When an admin saves a pupil: lock them (the sync then leaves them alone) if the chosen class is not the one
 * for their age, or if they are kept Active although older than every class; unlock them otherwise.
 * Returns 1 or 0 for class_locked.
 */
function sunday_school_lock_for_edit(mysqli $conn, ?string $dob, ?int $classId, string $status): int
{
    $age = sunday_school_age($dob);
    if ($age === null || $status !== 'Active') return 0;
    $classes = sunday_school_age_classes($conn);
    if (!$classes) return 0;
    if ($age > max(array_column($classes, 'max'))) return 1;
    $ageClass = sunday_school_class_for_age($classes, $age);
    // An age with no class is not locked, so the pupil still moves up once a class fits.
    return ($ageClass !== null && $ageClass !== (int)$classId) ? 1 : 0;
}
