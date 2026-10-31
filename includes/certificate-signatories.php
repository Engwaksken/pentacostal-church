<?php
declare(strict_types=1);

/*
 * Certificate signatories: two people who sign church certificates, each with a title, a name and an optional
 * signature image. Set on the Baptism page (Certificate Signatories); read by the baptism certificate and the
 * event participation certificate. Stored in baptism_certificate_signatories (row id 1); the vicar_* and
 * secretary_* columns hold the first and second signatory.
 */

/** Titles shown until an admin sets their own. */
const CERT_SIGNATORY_DEFAULT_TITLES = ['Senior Pastor', 'Church Secretary'];

function cert_signatories_column_exists(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'baptism_certificate_signatories' AND COLUMN_NAME = ?");
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

/** Creates the settings table and the title columns when missing. */
function cert_signatories_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS baptism_certificate_signatories (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        vicar_name VARCHAR(150) NULL,
        vicar_signature VARCHAR(255) NULL,
        secretary_name VARCHAR(150) NULL,
        secretary_signature VARCHAR(255) NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!cert_signatories_column_exists($conn, 'vicar_title')) {
        $conn->query('ALTER TABLE baptism_certificate_signatories ADD COLUMN vicar_title VARCHAR(100) NULL AFTER id');
    }
    if (!cert_signatories_column_exists($conn, 'secretary_title')) {
        $conn->query('ALTER TABLE baptism_certificate_signatories ADD COLUMN secretary_title VARCHAR(100) NULL AFTER vicar_signature');
    }
    $conn->query('INSERT IGNORE INTO baptism_certificate_signatories (id) VALUES (1)');
    $done = true;
}

/**
 * The two signatories: [['title' => string, 'name' => string, 'signature' => string (stored path)], ...].
 * A blank title falls back to the default.
 */
function cert_signatories_load(mysqli $conn): array
{
    $row = [];
    try {
        cert_signatories_ensure($conn);
        $res = $conn->query('SELECT * FROM baptism_certificate_signatories WHERE id = 1 LIMIT 1');
        $row = $res ? ($res->fetch_assoc() ?: []) : [];
    } catch (Throwable $e) {
        error_log('[certificate signatories] ' . $e->getMessage());
    }
    $out = [];
    foreach (['vicar', 'secretary'] as $i => $prefix) {
        $title = trim((string)($row[$prefix . '_title'] ?? ''));
        $out[] = [
            'title' => $title !== '' ? $title : CERT_SIGNATORY_DEFAULT_TITLES[$i],
            'name' => trim((string)($row[$prefix . '_name'] ?? '')),
            'signature' => trim((string)($row[$prefix . '_signature'] ?? '')),
        ];
    }
    return $out;
}

/** A stored signature image as a data: URI for certificates and PDFs, or '' when there is none. */
function cert_signature_src(string $relative): string
{
    $relative = trim($relative);
    if ($relative === '' || strpos($relative, 'uploads/baptism-signatures/') !== 0) return '';
    $absolute = __DIR__ . '/../' . $relative;
    if (!is_file($absolute)) return '';
    $mime = function_exists('mime_content_type') ? (mime_content_type($absolute) ?: 'image/png') : 'image/png';
    $data = @file_get_contents($absolute);
    if ($data === false) return '';
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}
