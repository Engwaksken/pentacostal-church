<?php
define('APP_VERSION', '1.0.0');
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');

if (session_status() === PHP_SESSION_NONE) session_start();

// db.php also loads branding.php, which defines APP_NAME from the church name in Settings.
require_once __DIR__ . '/db.php';
// APP_URL in .env (loaded by db.php) overrides the live site address, e.g. for a local copy.
define('BASE_URL', rtrim(getenv('APP_URL') ?: 'https://pentacostal.kemmytech.com', '/') . '/');
define('APP_SHORT', APP_NAME);
require_once __DIR__ . '/../includes/access.php'; 

function requireLogin() {
    if (!isset($_SESSION['user_id'])) { header('Location: ' . BASE_URL . 'index.php'); exit; }
}
function isAdmin() { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }


function san($a, $b = null) {
    global $conn;
    if ($b === null) {
        
        return htmlspecialchars(mysqli_real_escape_string($conn, trim($a)), ENT_QUOTES, 'UTF-8');
    }
   
    return htmlspecialchars(mysqli_real_escape_string($conn, trim($b)), ENT_QUOTES, 'UTF-8');
}

function redirect($url) { header("Location: $url"); exit; }

function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash() {
    if (isset($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}
function showFlash() {
    $f = getFlash();
    if (!$f) return '';
    $cls = $f['type'] === 'success' ? 'alert-success' : ($f['type'] === 'danger' ? 'alert-danger' : 'alert-' . $f['type']);
    return "<div class='alert $cls alert-dismissible'>{$f['msg']}<button type='button' class='close' onclick='this.parentElement.remove()'>&times;</button></div>";
}


function genNo($prefix) {
    global $conn;
    $y = date('Y');
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM (
        SELECT id FROM members WHERE member_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM youth WHERE youth_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM baptisms WHERE baptism_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM contributions WHERE receipt_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM tithe WHERE receipt_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM thanksgiving WHERE receipt_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM offertory WHERE id LIKE '$prefix-$y-%'
        UNION SELECT id FROM pledges WHERE pledge_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM fundraising WHERE campaign_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM fundraising_collections WHERE receipt_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM projects WHERE project_no LIKE '$prefix-$y-%'
        UNION SELECT id FROM events WHERE id LIKE '$prefix-$y-%'
    ) x"));
    $seq = ($r['c'] ?? 0) + 1 + rand(0,3);
    return $prefix . '-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}


function numRows($sql) {
    global $conn;
    $r = mysqli_query($conn, $sql);
    return $r ? mysqli_num_rows($r) : 0;
}

function fetchOne($sql) {
    global $conn;
    $r = mysqli_query($conn, $sql);
    return $r ? mysqli_fetch_assoc($r) : null;
}

function sumField($sql) {
    global $conn;
    $r = mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_assoc($r);
    return $row ? array_values($row)[0] : 0;
}


function q($sql) {
    global $conn;
    return mysqli_query($conn, $sql);
}
