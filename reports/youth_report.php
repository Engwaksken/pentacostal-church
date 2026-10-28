<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

$conn->set_charset('utf8mb4');

/**
 * Escape HTML output.
 */
function e(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Format a database date safely.
 */
function fmtDate(mixed $date): string
{
    $date = trim((string)($date ?? ''));

    if ($date === '' || $date === '0000-00-00') {
        return '-';
    }

    $timestamp = strtotime($date);

    return $timestamp !== false
        ? date('d M Y', $timestamp)
        : '-';
}

/**
 * Display a value or a fallback dash.
 */
function displayValue(mixed $value): string
{
    $value = trim((string)($value ?? ''));

    return $value !== '' ? $value : '-';
}


$sql = "
    SELECT
        y.id AS youth_id,
        y.member_id,
        y.youth_no,
        m.date_of_birth,
        y.school_or_work,
        y.education_level,
        m.member_no,
        m.gender,
        m.phone,
        m.marital_status,
        m.joined_date,

        m.full_name

    FROM youth AS y

    INNER JOIN members AS m
        ON m.id = y.member_id

    ORDER BY
        m.full_name ASC,
        y.id ASC
";

$youthResult = $conn->query($sql);

if ($youthResult === false) {
    error_log('Youth report query failed: ' . $conn->error);

    http_response_code(500);
    exit('The youth report could not be generated.');
}

/*
|--------------------------------------------------------------------------
| Total youth count
|--------------------------------------------------------------------------
*/
$total = 0;

$totalResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM youth AS y
    INNER JOIN members AS m
        ON m.id = y.member_id
");

if ($totalResult !== false) {
    $totalRow = $totalResult->fetch_assoc();
    $total = (int)($totalRow['total'] ?? 0);
    $totalResult->free();
}

$generated = date('d M Y H:i');

ob_start();
include '../includes/header.php';
include '../includes/sidebar.php';
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Youth Report</title>

    <style>
        @page {
            margin: 18px 20px 25px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #222;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.35;
        }

        .header-table {
            width: 100%;
            margin-bottom: 12px;
            border-collapse: collapse;
            border-bottom: 3px solid #d4a017;
        }

        .header-table td {
            padding: 0 0 8px;
            vertical-align: top;
            border: 0;
        }

        .organisation-name {
            margin: 0 0 2px;
            color: #4a1e8f;
            font-size: 16px;
            font-weight: bold;
        }

        .report-title {
            margin: 0 0 3px;
            color: #4a1e8f;
            font-size: 12px;
            font-weight: bold;
        }

        .subtitle {
            color: #777;
            font-size: 9px;
        }

        .meta {
            width: 220px;
            color: #555;
            font-size: 9px;
            line-height: 1.6;
            text-align: right;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table thead {
            display: table-header-group;
        }

        .report-table tr {
            page-break-inside: avoid;
        }

        .report-table th {
            padding: 6px 4px;
            color: #fff;
            background: #4a1e8f;
            border: 1px solid #6a3bac;
            font-size: 8px;
            text-align: left;
            vertical-align: middle;
        }

        .report-table td {
            padding: 5px 4px;
            border: 1px solid #e5e5e5;
            font-size: 8px;
            vertical-align: middle;
            overflow-wrap: break-word;
        }

        .report-table tbody tr:nth-child(even) td {
            background: #f8f4ff;
        }

        .col-number {
            width: 4%;
        }

        .col-youth-number {
            width: 10%;
        }

        .col-name {
            width: 19%;
        }

        .col-gender {
            width: 8%;
        }

        .col-date {
            width: 10%;
        }

        .col-phone {
            width: 13%;
        }

        .col-occupation {
            width: 20%;
        }

        .text-center {
            text-align: center !important;
        }

        .status-active {
            color: #218838;
            font-weight: bold;
            text-align: center;
        }

        .status-inactive {
            color: #6c757d;
            text-align: center;
        }

        .empty-row {
            padding: 18px !important;
            color: #777;
            text-align: center;
        }

        .footer {
            margin-top: 12px;
            color: #888;
            font-size: 8px;
            text-align: center;
        }

        .no-print {
            margin-bottom: 15px;
            text-align: right;
        }

        .download-button {
            display: inline-block;
            padding: 8px 14px;
            color: #fff;
            background: #4a1e8f;
            border-radius: 4px;
            font-size: 12px;
            text-decoration: none;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                margin: 0;
            }
        }
    </style>
</head>

<body>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">
<?php if (!isset($_GET['pdf'])): ?>
    <div class="no-print">
        <a class="download-button" href="index.php" style="margin-right:8px">
            <i class="fas fa-arrow-left"></i> Back to Reports
        </a>
        <a class="download-button" href="?pdf=1">
            Download PDF
        </a>
    </div>
<?php endif; ?>

<table class="header-table">
    <tr>
        <td>
            <div class="organisation-name"><?= e(strtoupper(APP_NAME)) ?></div>
            <div class="report-title">Youth Register &amp; Project Participation</div>
            <div class="subtitle">Youth Empowerment Programme</div>
        </td>

        <td class="meta">
            <strong>Total Youth:</strong>
            <?= number_format($total) ?><br>

            <strong>Generated:</strong>
            <?= e($generated) ?>
        </td>
    </tr>
</table>

<table class="report-table">
    <thead>
        <tr>
            <th class="col-number text-center">#</th>
            <th class="col-youth-number">Youth No.</th>
            <th class="col-name">Full Name</th>
            <th class="col-gender">Gender</th>
            <th class="col-date">D.O.B</th>
            <th class="col-phone">Phone</th>
            <th class="col-occupation">School/Occupation</th>
        </tr>
    </thead>

    <tbody>
        <?php if ($youthResult->num_rows > 0): ?>
            <?php $number = 1; ?>

            <?php while ($youth = $youthResult->fetch_assoc()): ?>
                <?php
                $fullName = displayValue($youth['full_name'] ?? '');

                $youthNumber = displayValue(
                    $youth['youth_no']
                    ?? $youth['member_no']
                    ?? ''
                );
                ?>

                <tr>
                    <td class="text-center">
                        <?= $number++ ?>
                    </td>

                    <td>
                        <?= e($youthNumber) ?>
                    </td>

                    <td>
                        <?= e($fullName) ?>
                    </td>

                    <td>
                        <?= e(displayValue($youth['gender'] ?? '')) ?>
                    </td>

                    <td>
                        <?= e(fmtDate($youth['date_of_birth'] ?? null)) ?>
                    </td>

                    <td>
                        <?= e(displayValue($youth['phone'] ?? '')) ?>
                    </td>

                    <td>
                        <?= e(displayValue($youth['school_occupation'] ?? '')) ?>
                    </td>
                </tr>
            <?php endwhile; ?>

        <?php else: ?>
            <tr>
                <td colspan="7" class="empty-row">
                    No youth records found.
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="footer">
   Youth Report &mdash; <?= e($generated) ?>
</div>

</body>
</html>
<?php

require_once __DIR__ . '/report-branding.php';
$html = report_brand_html((string)ob_get_clean());

$youthResult->free();

/*
|--------------------------------------------------------------------------
| Generate PDF
|--------------------------------------------------------------------------
*/
if (isset($_GET['pdf'])) {
    $autoloadLocations = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];

    $autoload = null;

    foreach ($autoloadLocations as $location) {
        if (is_file($location)) {
            $autoload = $location;
            break;
        }
    }

    if ($autoload === null) {
        http_response_code(500);

        exit(
            '<p>Dompdf was not found. Run ' .
            '<code>composer require dompdf/dompdf</code>.</p>'
        );
    }

    require_once $autoload;

    if (!class_exists(\Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('Dompdf could not be loaded.');
    }

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', realpath(dirname(__DIR__)) ?: dirname(__DIR__));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'Youth_Report_' . date('Ymd_His') . '.pdf';

    $dompdf->stream($filename, [
        'Attachment' => true,
    ]);

    exit;
}

echo $html;
