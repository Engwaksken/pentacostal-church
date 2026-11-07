<?php
// Database login comes from the .env file at the site root (see .env.example); never commit live credentials.
require_once __DIR__ . '/env.php';
if (env('DB_NAME') === null || env('DB_USER') === null) {
    error_log('Database settings missing: set DB_HOST, DB_NAME, DB_USER and DB_PASS in ' . dirname(__DIR__) . '/.env');
    http_response_code(500);
    die("<div style='padding:20px;color:red;font-family:sans-serif;'><strong>Configuration error:</strong> database settings are missing from the .env file.</div>");
}
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME'));
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("<div style='padding:20px;color:red;font-family:sans-serif;'><strong>DB Error:</strong> ".$conn->connect_error."</div>");
}
$conn->set_charset("utf8mb4");

// members.full_name holds the member's name; add and backfill it once from the legacy name columns.
$fullNameCol = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME='full_name'");
$hasMembers  = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members'");
if ($fullNameCol && !$fullNameCol->num_rows && $hasMembers && $hasMembers->num_rows) {
    try {
        $conn->query("ALTER TABLE members ADD COLUMN full_name VARCHAR(200) NOT NULL DEFAULT '' AFTER member_no");
        $middle = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME='middle_name'");
        $parts  = $middle && $middle->num_rows ? 'first_name,middle_name,last_name' : 'first_name,last_name';
        $conn->query("UPDATE members SET full_name=TRIM(REPLACE(CONCAT_WS(' ',$parts),'  ',' '))");
        unset($middle, $parts);
    } catch (Throwable $e) { error_log('Could not add members.full_name: ' . $e->getMessage()); }
}
// The legacy name columns are no longer written; let them be NULL until they are dropped.
$legacyNames = $conn->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME IN ('first_name','middle_name','last_name') AND IS_NULLABLE='NO'");
while ($legacyNames && $col = $legacyNames->fetch_assoc()) {
    try { $conn->query("ALTER TABLE members MODIFY `{$col['COLUMN_NAME']}` {$col['COLUMN_TYPE']} NULL DEFAULT NULL"); }
    catch (Throwable $e) { error_log("Could not make members.{$col['COLUMN_NAME']} nullable: " . $e->getMessage()); }
}
unset($fullNameCol, $hasMembers, $legacyNames, $col);

require_once __DIR__ . '/branding.php';
