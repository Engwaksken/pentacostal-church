<?php
declare(strict_types=1);

// Finds new announcements, events, programs, tips, pledges and payments, queues a notification
// for each member according to their settings, then sends queued push, email and SMS messages.
// Run every 10-15 minutes, for example:
// */10 * * * * /usr/bin/php /path/to/cron/member_notifications.php
//
// The first run only records what already exists, so members are not sent old items.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/member-notify.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (!mn_ready($conn)) exit("Run database/member_notifications.sql first.\n");
if (!(int)($conn->query("SELECT GET_LOCK('member_notifications', 0)")->fetch_row()[0] ?? 0)) exit("Already running.\n");

$init = mn_state($conn, 'initialized') === null;
$queued = 0;

/** Sends a church-wide item, or only records it on the first run. */
$item = function (string $category, string $refKey, string $title, string $body, ?array $departments = null) use ($conn, $init, &$queued): void {
    if ($init) {
        mn_mark_sent($conn, $refKey);
    } elseif (mn_broadcast($conn, $category, $refKey, $title, $body, $departments)) {
        $queued++;
    }
};

$short = fn(?string $text, int $max = 220) => mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8')))), 0, $max, '…');
$when = function (?string $date, ?string $time = null, ?string $venue = null): string {
    $text = $date ? date('D j M Y', strtotime($date)) : '';
    if ($time) $text .= ' at ' . date('g:i a', strtotime($time));
    if ($venue) $text .= ', ' . trim($venue);
    return $text;
};

/* ------------------------------------------------------------------ church-wide items */

if (mn_has_table($conn, 'church_announcements')) {
    $rows = mn_rows($conn, "SELECT id, title, message, priority FROM church_announcements
        WHERE status='Published' AND (publish_from IS NULL OR publish_from<=NOW()) AND (publish_until IS NULL OR publish_until>=NOW())");
    foreach ($rows as $a) {
        $title = ($a['priority'] === 'Urgent' ? 'Urgent: ' : '') . $a['title'];
        $item('announcements', 'announcement:' . $a['id'], $title, $short($a['message']), announcement_department_names($conn, (int)$a['id']));
    }
}

if (mn_has_table($conn, 'events')) {
    foreach (mn_rows($conn, 'SELECT id, title, event_date, event_time, venue FROM events WHERE event_date>=CURDATE()') as $e) {
        $item('events', 'event:' . $e['id'], 'New event: ' . $e['title'], $when($e['event_date'], $e['event_time'], $e['venue']));
    }
}

if (mn_has_table($conn, 'church_programs')) {
    $rows = mn_rows($conn, "SELECT id, title, program_date, start_time, venue FROM church_programs
        WHERE program_date>=CURDATE() AND COALESCE(status,'Scheduled') NOT IN ('Draft','Cancelled')");
    foreach ($rows as $p) {
        $item('programs', 'program:' . $p['id'], 'Upcoming: ' . $p['title'], $when($p['program_date'], $p['start_time'], $p['venue']));
    }
}

if (mn_has_table($conn, 'department_tips')) {
    foreach (mn_rows($conn, "SELECT id, department, title, content FROM department_tips WHERE status='Published'") as $t) {
        $label = $t['department'] === 'education' ? 'Education tip' : 'Health tip';
        $item('tips', 'tip:' . $t['id'], "$label: {$t['title']}", $short($t['content']));
    }
}

// Day-before reminders, sent from 8 am. Not recorded on the first run, so tomorrow's still go out.
if (!$init && (int)date('G') >= 8) {
    if (mn_has_table($conn, 'church_programs')) {
        $rows = mn_rows($conn, "SELECT id, title, program_date, start_time, venue FROM church_programs
            WHERE program_date=CURDATE() + INTERVAL 1 DAY AND COALESCE(status,'Scheduled') NOT IN ('Draft','Cancelled')");
        foreach ($rows as $p) {
            $item('reminders', 'program_reminder:' . $p['id'], 'Tomorrow: ' . $p['title'], $when($p['program_date'], $p['start_time'], $p['venue']));
        }
    }
    if (mn_has_table($conn, 'events')) {
        foreach (mn_rows($conn, 'SELECT id, title, event_date, event_time, venue FROM events WHERE event_date=CURDATE() + INTERVAL 1 DAY') as $e) {
            $item('reminders', 'event_reminder:' . $e['id'], 'Tomorrow: ' . $e['title'], $when($e['event_date'], $e['event_time'], $e['venue']));
        }
    }
}

/* ------------------------------------------------------------------ a member's own pledges and payments */

/** Rows added since the last run (by id). On the first run, only records where to start. */
$newRows = function (string $table, string $sql) use ($conn, $init): array {
    if (!mn_has_table($conn, $table)) return [];
    $max = (int)($conn->query("SELECT COALESCE(MAX(id),0) FROM `$table`")->fetch_row()[0] ?? 0);
    $last = (int)(mn_state($conn, "last_id:$table") ?? $max);
    mn_set_state($conn, "last_id:$table", (string)$max);
    return $init || $max <= $last ? [] : mn_rows($conn, $sql, 'ii', [$last, $max]);
};
$ugx = fn($amount) => 'UGX ' . number_format((float)$amount);

foreach ($newRows('pledges', 'SELECT id, member_id, pledge_no, amount_pledged, pledge_reason, due_date FROM pledges WHERE id>? AND id<=? AND member_id IS NOT NULL') as $p) {
    $body = "Your pledge {$p['pledge_no']} of {$ugx($p['amount_pledged'])}" . ($p['pledge_reason'] ? " for {$p['pledge_reason']}" : '') . ' has been recorded'
        . ($p['due_date'] ? ', due ' . date('j M Y', strtotime($p['due_date'])) : '') . '. Thank you.';
    mn_enqueue($conn, (int)$p['member_id'], 'pledges', 'pledge:' . $p['id'], 'Pledge recorded', $body);
    $queued++;
}

$pledgePayments = $newRows('pledge_payments', 'SELECT pp.id, pp.amount, pp.receipt_no, p.id AS pledge_id, p.member_id, p.pledge_no, p.status, p.amount_pledged, p.amount_paid
    FROM pledge_payments pp JOIN pledges p ON p.id=pp.pledge_id WHERE pp.id>? AND pp.id<=? AND p.member_id IS NOT NULL');
foreach ($pledgePayments as $pay) {
    $memberId = (int)$pay['member_id'];
    $balance = max(0, (float)$pay['amount_pledged'] - (float)$pay['amount_paid']);
    // The pledge module emails its own receipt, so this one goes by push and SMS only.
    mn_enqueue($conn, $memberId, 'payments', 'pledge_payment:' . $pay['id'], 'Pledge payment received',
        "We received {$ugx($pay['amount'])} for pledge {$pay['pledge_no']} (receipt {$pay['receipt_no']}). Balance: {$ugx($balance)}. God bless you.", ['push', 'sms']);
    if ($pay['status'] === 'Fulfilled') {
        mn_enqueue($conn, $memberId, 'pledges', 'pledge_fulfilled:' . $pay['pledge_id'], 'Pledge fully paid',
            "Your pledge {$pay['pledge_no']} of {$ugx($pay['amount_pledged'])} is now fully paid. Thank you for your faithful giving.");
    }
    $queued++;
}

$givingTypes = [
    'tithe' => ['Tithe', 'tithe_date'],
    'thanksgiving' => ['Thanksgiving', 'thanksgiving_date'],
    'contributions' => ['Contribution', 'contributed_on'],
];
foreach ($givingTypes as $table => [$label, $dateCol]) {
    foreach ($newRows($table, "SELECT id, member_id, amount, receipt_no, `$dateCol` AS paid_on FROM `$table` WHERE id>? AND id<=? AND member_id IS NOT NULL") as $g) {
        mn_enqueue($conn, (int)$g['member_id'], 'payments', "$table:{$g['id']}", "$label received",
            "We received your " . strtolower($label) . " of {$ugx($g['amount'])} on " . date('j M Y', strtotime($g['paid_on'])) . " (receipt {$g['receipt_no']}). God bless you.");
        $queued++;
    }
}

if ($init) {
    mn_set_state($conn, 'initialized', date('Y-m-d H:i:s'));
    echo "First run: recorded existing items; new items will be sent from now on.\n";
}

$sent = mn_deliver($conn);
echo date('Y-m-d H:i:s') . " queued $queued item(s); sent push {$sent['push']}, email {$sent['email']}, SMS {$sent['sms']}.\n";
$conn->query("SELECT RELEASE_LOCK('member_notifications')");

/** Department names an announcement is limited to, or null when it is for all members. */
function announcement_department_names(mysqli $conn, int $announcementId): ?array
{
    if (!mn_has_table($conn, 'church_announcement_departments') || !mn_has_table($conn, 'departments')) return null;
    $ids = array_map(fn($r) => (int)$r['department_id'], mn_rows($conn, 'SELECT department_id FROM church_announcement_departments WHERE announcement_id=?', 'i', [$announcementId]));
    if (!$ids) return null;
    $idCol = mn_has_column($conn, 'departments', 'id') ? 'id' : 'department_id';
    foreach (['department_name', 'name', 'title'] as $nameCol) {
        if (!mn_has_column($conn, 'departments', $nameCol)) continue;
        $rows = mn_rows($conn, "SELECT `$nameCol` AS name FROM departments WHERE `$idCol` IN (" . implode(',', $ids) . ')');
        return array_values(array_filter(array_map(fn($r) => trim((string)$r['name']), $rows)));
    }
    return [];
}
