<?php
// Configure these values in the server environment or in a .env file at the site root (see .env.example);
// never commit live credentials. Real environment variables win over .env.
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || $envLine[0] === '#' || strpos($envLine, '=') === false) continue;
        [$envKey, $envValue] = array_map('trim', explode('=', $envLine, 2));
        if (strlen($envValue) > 1 && ($envValue[0] === '"' || $envValue[0] === "'") && substr($envValue, -1) === $envValue[0]) {
            $envValue = substr($envValue, 1, -1);
        }
        if ($envKey !== '' && getenv($envKey) === false) putenv("$envKey=$envValue");
    }
}
unset($envFile, $envLine, $envKey, $envValue);
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'cou_church');
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
