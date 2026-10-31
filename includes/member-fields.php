<?php
declare(strict_types=1);

/*
 * Shared support for the member pledge number and service attended fields.
 * Used by the admin members module, public self-registration and the member portal.
 */

function member_fields_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

/** Submitted full name, falling back to first_name/last_name (e.g. older CSV files). */
function member_full_name(array $data): string
{
    $full = trim((string)($data['full_name'] ?? ''));
    if ($full === '') $full = trim((string)($data['first_name'] ?? '') . ' ' . (string)($data['last_name'] ?? ''));
    return (string)preg_replace('/\s+/', ' ', $full);
}

/**
 * Add members.pledge_no / members.service_attended when missing.
 * Call this before opening a transaction: ALTER TABLE implicitly commits.
 */
function member_fields_ensure(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $ok = true;
    $defs = [
        'pledge_no'        => "ALTER TABLE members ADD COLUMN pledge_no VARCHAR(50) NULL AFTER member_no",
        'service_attended' => "ALTER TABLE members ADD COLUMN service_attended VARCHAR(120) NULL"
            . (member_fields_column_exists($conn, 'members', 'baptism_date') ? ' AFTER baptism_date' : ''),
    ];
    foreach ($defs as $col => $sql) {
        if (member_fields_column_exists($conn, 'members', $col)) continue;
        try { if (!$conn->query($sql)) $ok = false; }
        catch (Throwable $e) { $ok = false; error_log("Could not add members.$col: " . $e->getMessage()); }
    }
    return $ready = $ok;
}

/**
 * Service choices: common defaults, service types recorded under Church Services,
 * and values already assigned to members.
 */
function member_fields_service_options(mysqli $conn): array
{
    $opts = ['First Service', 'Second Service', 'Third Service', 'Youth Service', 'Sunday School'];
    $queries = [];
    if (member_fields_column_exists($conn, 'church_services', 'service_type'))
        $queries[] = "SELECT DISTINCT TRIM(service_type) s FROM church_services WHERE TRIM(COALESCE(service_type,''))<>''";
    if (member_fields_ensure($conn))
        $queries[] = "SELECT DISTINCT TRIM(service_attended) s FROM members WHERE TRIM(COALESCE(service_attended,''))<>''";
    foreach ($queries as $sql) {
        $r = $conn->query($sql);
        if ($r) while ($x = $r->fetch_assoc()) $opts[] = (string)$x['s'];
    }
    $seen = []; $out = [];
    foreach ($opts as $o) {
        $k = strtolower($o);
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $out[] = $o;
    }
    return $out;
}

function member_fields_pledge_taken(mysqli $conn, string $pledgeNo, int $excludeMemberId = 0): bool
{
    if ($pledgeNo === '') return false;
    $stmt = $conn->prepare("SELECT id FROM members WHERE pledge_no=? AND id<>? LIMIT 1");
    $stmt->bind_param('si', $pledgeNo, $excludeMemberId); $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $found;
}
