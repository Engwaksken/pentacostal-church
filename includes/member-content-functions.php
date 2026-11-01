<?php
declare(strict_types=1);

/**
 * Shared rules for member-facing church content: Health/Education department tips,
 * dashboard Bible verses, Service Programs and church songs.
 * Tables are created by database/member_content.sql.
 */

const MC_STATUSES = ['Draft', 'Published'];
const MC_COPYRIGHT_STATUSES = ['Not Entered', 'Public Domain', 'Licensed', 'Permission Granted'];

function mc_tip_departments(): array
{
    return [
        'health'    => ['label' => 'Health Department',    'short' => 'Health Tips',    'icon' => 'fa-heart-pulse',      'permission' => 'health_tips.manage'],
        'education' => ['label' => 'Education Department', 'short' => 'Education Tips', 'icon' => 'fa-graduation-cap', 'permission' => 'education_tips.manage'],
    ];
}

function mc_valid_department(string $department): bool
{
    return array_key_exists($department, mc_tip_departments());
}

function mc_can_manage_tips(string $department): bool
{
    $departments = mc_tip_departments();
    return isset($departments[$department]) && current_user_can($departments[$department]['permission']);
}

/** Department keys the current user may manage, in display order. */
function mc_manageable_departments(): array
{
    return array_values(array_filter(array_keys(mc_tip_departments()), 'mc_can_manage_tips'));
}

/** Worship resource pages, keyed by page, with the permission that manages each. */
function mc_worship_pages(): array
{
    return [
        'verses'           => ['label' => 'Bible Verses',     'icon' => 'fa-book-bible',  'href' => 'verses.php',           'permission' => 'bible_verses.manage'],
        'order_of_service' => ['label' => 'Service Program', 'icon' => 'fa-list-ol',     'href' => 'order-of-service.php', 'permission' => 'order_of_service.manage'],
        'hymns'            => ['label' => 'Church Songs',     'icon' => 'fa-music',       'href' => 'hymns.php',            'permission' => 'hymns.manage'],
    ];
}

/** Recognized languages used by communities in Uganda. */
function mc_ugandan_languages(): array
{
    return ['Acholi','Alur','Ateso','Dhopadhola (Japadhola)','English','Ik','Kakwa','Karamojong (Ngakarimojong)','Kebu','Kumam','Kupsabiny (Sebei)','Lango','Lugbara','Luganda','Lugisu (Lumasaba)','Lunyole (Nyole)','Lusoga',"Ma'di",'Nubi','Nyangia','Runyankole','Rukiga','Runyoro','Rutooro','Samia','Other'];
}

/** Tabs linking the worship resource pages the current user may manage. */
function mc_worship_tabs(string $active): void
{
    $pages = array_filter(mc_worship_pages(), fn($p) => current_user_can($p['permission']));
    if (count($pages) < 2) return;
    echo '<div class="mc-tabs">';
    foreach ($pages as $key => $p) {
        echo '<a class="' . ($key === $active ? 'active' : '') . '" href="' . htmlspecialchars($p['href'], ENT_QUOTES, 'UTF-8') . '"><i class="fas ' . htmlspecialchars($p['icon'], ENT_QUOTES, 'UTF-8') . '"></i> ' . htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</div>';
}

function mc_tables_ready(mysqli $conn, array $tables): bool
{
    foreach ($tables as $table) {
        if (!access_table_exists($conn, $table)) return false;
    }
    return true;
}

/** Trimmed POST text; throws when required and empty or longer than $max characters. */
function mc_post_text(string $key, string $label, int $max, bool $required = false): string
{
    $value = trim(str_replace("\r\n", "\n", (string)($_POST[$key] ?? '')));
    if ($required && $value === '') throw new RuntimeException($label . ' is required.');
    if (mb_strlen($value) > $max) throw new RuntimeException($label . ' must be ' . number_format($max) . ' characters or fewer.');
    return $value;
}

function mc_post_status(): string
{
    $status = (string)($_POST['status'] ?? 'Draft');
    return in_array($status, MC_STATUSES, true) ? $status : 'Draft';
}

function mc_post_date(string $key, string $label, bool $required = false): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        if ($required) throw new RuntimeException($label . ' is required.');
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Enter a valid ' . strtolower($label) . '.');
    return $value;
}

function mc_post_time(string $key, string $label): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) throw new RuntimeException('Enter a valid ' . strtolower($label) . '.');
    return strlen($value) === 5 ? $value . ':00' : $value;
}

/**
 * Publication date to store after a save: set when content becomes Published,
 * kept while it stays Published, and cleared when it is unpublished.
 */
function mc_published_at(string $newStatus, ?string $oldStatus, ?string $oldPublishedAt): ?string
{
    if ($newStatus !== 'Published') return null;
    if ($oldStatus === 'Published' && $oldPublishedAt) return $oldPublishedAt;
    return date('Y-m-d H:i:s');
}

function mc_format_date(?string $value, string $format = 'd M Y'): string
{
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) return '-';
    $timestamp = strtotime($value);
    return $timestamp === false ? '-' : date($format, $timestamp);
}

/**
 * Published Bible verses for the member dashboard: today's verse first, then the most
 * recently scheduled earlier verses, then undated ones. Future-dated verses stay hidden.
 */
function mc_dashboard_verses(mysqli $conn, int $limit = 3): array
{
    if (!access_table_exists($conn, 'bible_verses')) return [];
    $stmt = $conn->prepare(
        "SELECT reference, verse_text, translation, display_date
         FROM bible_verses
         WHERE status='Published' AND (display_date IS NULL OR display_date<=CURDATE())
         ORDER BY (display_date=CURDATE()) DESC, display_date IS NULL, display_date DESC, published_at DESC, id DESC
         LIMIT ?"
    );
    if (!$stmt) return [];
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
