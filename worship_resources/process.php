<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../church_admin/common.php';
require_once '../includes/member-content-functions.php';
requireLogin();
enforce_member_read_only();

const WR_TABLES = ['verses' => 'bible_verses', 'order_of_service' => 'service_orders', 'hymns' => 'church_hymns'];

$resource = (string)($_POST['resource'] ?? '');
$pages = mc_worship_pages();
if (!isset($pages[$resource])) {
    ce_flash('danger', 'Invalid request.');
    ce_redirect('verses.php');
}
// Each resource has its own manage permission; enforce it before touching any data.
require_permission($pages[$resource]['permission']);
$table = WR_TABLES[$resource];

function wr_load(mysqli $conn, string $table, int $id): array
{
    if ($id < 1) throw new RuntimeException('Invalid record.');
    $stmt = $conn->prepare("SELECT id, status, published_at FROM `$table` WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException('Record not found.');
    return $row;
}

function wr_save_verse(mysqli $conn, ?array $existing, string $status, ?string $publishedAt, int $uid): void
{
    $reference = mc_post_text('reference', 'Reference', 120, true);
    $text = mc_post_text('verse_text', 'Verse text', 2000, true);
    $translation = mc_post_text('translation', 'Translation', 80, true);
    $displayDate = mc_post_date('display_date', 'Display date');
    if ($existing) {
        $id = (int)$existing['id'];
        $stmt = $conn->prepare('UPDATE bible_verses SET reference=?, verse_text=?, translation=?, display_date=?, status=?, published_at=?, updated_by=? WHERE id=?');
        $stmt->bind_param('ssssssii', $reference, $text, $translation, $displayDate, $status, $publishedAt, $uid, $id);
    } else {
        $stmt = $conn->prepare('INSERT INTO bible_verses (reference, verse_text, translation, display_date, status, published_at, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssssssii', $reference, $text, $translation, $displayDate, $status, $publishedAt, $uid, $uid);
    }
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
}

function wr_save_hymn(mysqli $conn, ?array $existing, string $status, ?string $publishedAt, int $uid): void
{
    $v = [
        'hymn_number' => mc_post_text('hymn_number', 'Song number', 20),
        'title' => mc_post_text('title', 'Title', 200, true),
        'hymn_book' => mc_post_text('hymn_book', 'Song book', 150),
        'language' => mc_post_text('language', 'Language', 80),
        'author' => mc_post_text('author', 'Author', 180),
        'tune' => mc_post_text('tune', 'Tune', 180),
        'theme' => mc_post_text('theme', 'Theme', 120),
        'scripture_reference' => mc_post_text('scripture_reference', 'Scripture reference', 180),
        'lyrics' => mc_post_text('lyrics', 'Lyrics', 30000),
        'copyright_status' => (string)($_POST['copyright_status'] ?? 'Not Entered'),
        'copyright_notice' => mc_post_text('copyright_notice', 'Copyright notice', 255),
        'study_notes' => mc_post_text('study_notes', 'Study notes', 20000),
    ];
    if (!in_array($v['copyright_status'], MC_COPYRIGHT_STATUSES, true)) throw new RuntimeException('Select a valid copyright basis.');
    // Lyrics are only stored with a recorded right to reproduce them.
    if ($v['lyrics'] === '') {
        $v['copyright_status'] = 'Not Entered';
    } elseif ($v['copyright_status'] === 'Not Entered') {
        throw new RuntimeException('Select the copyright basis for the lyrics (Public Domain, Licensed or Permission Granted), or remove the lyrics.');
    } elseif ($v['copyright_status'] !== 'Public Domain' && $v['copyright_notice'] === '') {
        throw new RuntimeException('Enter the licence or permission notice for these lyrics.');
    }
    foreach ($v as $k => $value) if ($value === '' && $k !== 'title') $v[$k] = null;

    $params = array_values($v);
    $params[] = $status;
    $params[] = $publishedAt;
    $params[] = $uid;
    if ($existing) {
        $params[] = (int)$existing['id'];
        $stmt = $conn->prepare('UPDATE church_hymns SET hymn_number=?, title=?, hymn_book=?, language=?, author=?, tune=?, theme=?, scripture_reference=?, lyrics=?, copyright_status=?, copyright_notice=?, study_notes=?, status=?, published_at=?, updated_by=? WHERE id=?');
        $stmt->bind_param(str_repeat('s', 14) . 'ii', ...$params);
    } else {
        $params[] = $uid;
        $stmt = $conn->prepare('INSERT INTO church_hymns (hymn_number, title, hymn_book, language, author, tune, theme, scripture_reference, lyrics, copyright_status, copyright_notice, study_notes, status, published_at, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param(str_repeat('s', 14) . 'ii', ...$params);
    }
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
}

/** Service items posted as parallel arrays, in display order. Fully blank rows are ignored. */
function wr_posted_items(mysqli $conn): array
{
    $titles = (array)($_POST['item_title'] ?? []);
    $ledBy = (array)($_POST['item_led_by'] ?? []);
    $details = (array)($_POST['item_details'] ?? []);
    $hymnIds = (array)($_POST['item_hymn_id'] ?? []);
    if (count($titles) > 100) throw new RuntimeException('An Service Program can have at most 100 items.');

    $validHymns = [];
    foreach (ce_query_all($conn, 'SELECT id FROM church_hymns') as $h) $validHymns[(int)$h['id']] = true;

    $items = [];
    foreach (array_values($titles) as $i => $title) {
        $item = [
            'title' => trim((string)$title),
            'led_by' => trim((string)($ledBy[$i] ?? '')),
            'details' => trim(str_replace("\r\n", "\n", (string)($details[$i] ?? ''))),
            'hymn_id' => (int)($hymnIds[$i] ?? 0),
        ];
        if ($item['title'] === '' && $item['led_by'] === '' && $item['details'] === '' && $item['hymn_id'] === 0) continue;
        $n = $i + 1;
        if ($item['title'] === '') throw new RuntimeException("Service item $n needs a title.");
        if (mb_strlen($item['title']) > 200 || mb_strlen($item['led_by']) > 180 || mb_strlen($item['details']) > 2000) throw new RuntimeException("Service item $n is too long.");
        if ($item['hymn_id'] !== 0 && !isset($validHymns[$item['hymn_id']])) throw new RuntimeException("Service item $n links to a song that no longer exists.");
        $items[] = $item;
    }
    return $items;
}

function wr_save_service_order(mysqli $conn, ?array $existing, string $status, ?string $publishedAt, int $uid): void
{
    $title = mc_post_text('title', 'Title', 200, true);
    $type = mc_post_text('service_type', 'Service type', 120);
    $date = mc_post_date('service_date', 'Service date', true);
    $time = mc_post_time('service_time', 'Start time');
    $notes = mc_post_text('notes', 'Notes', 5000);
    $items = wr_posted_items($conn);

    $ownsTransaction = !$conn->in_transaction;
    if ($ownsTransaction) $conn->begin_transaction();
    try {
        if ($existing) {
            $id = (int)$existing['id'];
            $stmt = $conn->prepare('UPDATE service_orders SET title=?, service_type=?, service_date=?, service_time=?, notes=?, status=?, published_at=?, updated_by=? WHERE id=?');
            $stmt->bind_param('sssssssii', $title, $type, $date, $time, $notes, $status, $publishedAt, $uid, $id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM service_order_items WHERE service_order_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare('INSERT INTO service_orders (title, service_type, service_date, service_time, notes, status, published_at, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('sssssssii', $title, $type, $date, $time, $notes, $status, $publishedAt, $uid, $uid);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }
        if ($items) {
            $stmt = $conn->prepare('INSERT INTO service_order_items (service_order_id, sort_order, item_title, led_by, details, hymn_id) VALUES (?,?,?,?,?,NULLIF(?,0))');
            foreach ($items as $sort => $item) {
                $ledBy = $item['led_by'] === '' ? null : $item['led_by'];
                $details = $item['details'] === '' ? null : $item['details'];
                $stmt->bind_param('iisssi', $id, $sort, $item['title'], $ledBy, $details, $item['hymn_id']);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            }
            $stmt->close();
        }
        if ($ownsTransaction) $conn->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction) $conn->rollback();
        throw $e;
    }
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Invalid request.');
    ce_verify_csrf();
    if (!mc_tables_ready($conn, array_values(WR_TABLES))) throw new RuntimeException('Run database/member_content.sql first.');
    $action = (string)($_POST['action'] ?? '');
    $uid = ce_user_id();
    $label = ['verses' => 'Bible verse', 'order_of_service' => 'Service Program', 'hymns' => 'Song'][$resource];

    if ($action === 'save_bulk') {
        $entries = json_decode((string)($_POST['entries'] ?? ''), true);
        if (!is_array($entries) || !$entries || count($entries) > 50) throw new RuntimeException('Add between 1 and 50 entries at a time.');
        $conn->begin_transaction();
        try {
            foreach ($entries as $index => $entry) {
                if (!is_array($entry)) throw new RuntimeException('Entry ' . ($index + 1) . ' is invalid.');
                $_POST = array_merge($_POST, $entry, ['resource' => $resource, 'status' => $entry['status'] ?? 'Draft']);
                if ($resource === 'order_of_service') {
                    $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
                    $_POST['item_title'] = array_column($items, 'title');
                    $_POST['item_led_by'] = array_column($items, 'led_by');
                    $_POST['item_details'] = array_column($items, 'details');
                    $_POST['item_hymn_id'] = array_column($items, 'hymn_id');
                }
                $status = mc_post_status();
                $publishedAt = mc_published_at($status, null, null);
                match ($resource) {
                    'verses' => wr_save_verse($conn, null, $status, $publishedAt, $uid),
                    'hymns' => wr_save_hymn($conn, null, $status, $publishedAt, $uid),
                    'order_of_service' => wr_save_service_order($conn, null, $status, $publishedAt, $uid),
                };
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        ce_flash('success', count($entries) . ' ' . $label . ' entries saved.');

    } elseif ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? wr_load($conn, $table, $id) : null;
        $status = mc_post_status();
        $publishedAt = mc_published_at($status, $existing['status'] ?? null, $existing['published_at'] ?? null);
        match ($resource) {
            'verses' => wr_save_verse($conn, $existing, $status, $publishedAt, $uid),
            'hymns' => wr_save_hymn($conn, $existing, $status, $publishedAt, $uid),
            'order_of_service' => wr_save_service_order($conn, $existing, $status, $publishedAt, $uid),
        };
        ce_flash('success', $label . ($status === 'Published' ? ' saved and published.' : ' saved as a draft.'));

    } elseif ($action === 'set_status') {
        $row = wr_load($conn, $table, (int)($_POST['id'] ?? 0));
        $status = mc_post_status();
        $publishedAt = mc_published_at($status, $row['status'], $row['published_at']);
        $id = (int)$row['id'];
        $stmt = $conn->prepare("UPDATE `$table` SET status=?, published_at=?, updated_by=? WHERE id=?");
        $stmt->bind_param('ssii', $status, $publishedAt, $uid, $id);
        $stmt->execute();
        $stmt->close();
        ce_flash('success', $label . ($status === 'Published' ? ' published.' : ' unpublished.'));

    } elseif ($action === 'delete') {
        $id = (int)wr_load($conn, $table, (int)($_POST['id'] ?? 0))['id'];
        if ($resource === 'hymns') {
            $stmt = $conn->prepare('UPDATE service_order_items SET hymn_id=NULL WHERE hymn_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        // Service order items are removed by the ON DELETE CASCADE foreign key.
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ce_flash('success', $label . ' deleted.');

    } else {
        throw new RuntimeException('Invalid action.');
    }
} catch (Throwable $e) {
    ce_flash('danger', $e->getMessage());
}
ce_redirect($pages[$resource]['href']);
