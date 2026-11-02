<?php
require_once '../config/config.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);

$r = fetchOne("
    SELECT
        b.*,
        m.full_name AS member_name
    FROM baptisms b
    LEFT JOIN members m ON m.id = b.member_id
    WHERE b.id = $id
");

if (!$r) {
    setFlash('Record not found.', 'danger');
    redirect('index.php');
}

$isPdf = isset($_GET['pdf']);
if ($isPdf) require_permission('baptism.view');
$generated = date('d M Y');
$certificateBrand = [
    'name' => defined('APP_NAME') ? APP_NAME : 'Church',
    'subtitle' => '',
    'logo' => ''
];
try {
    $brandResult = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name','site_subtitle','site_logo')");
    if ($brandResult) {
        while ($brandRow = $brandResult->fetch_assoc()) {
            $brandValue = trim((string)$brandRow['setting_value']);
            if ($brandRow['setting_key'] === 'site_name' && $brandValue !== '') $certificateBrand['name'] = $brandValue;
            if ($brandRow['setting_key'] === 'site_subtitle') $certificateBrand['subtitle'] = $brandValue;
            if ($brandRow['setting_key'] === 'site_logo') $certificateBrand['logo'] = $brandValue;
        }
        $brandResult->close();
    }
} catch (Throwable $e) {
    error_log('[baptism certificate branding] ' . $e->getMessage());
}
$certificateLogoSrc = '';
if ($certificateBrand['logo'] !== '') {
    if (preg_match('~^https?://~i', $certificateBrand['logo'])) {
        $certificateLogoSrc = $certificateBrand['logo'];
    } else {
        $logoRelative = ltrim($certificateBrand['logo'], '/');
        $logoAbsolute = __DIR__ . '/../' . $logoRelative;
        if (is_file($logoAbsolute)) {
            $logoData = @file_get_contents($logoAbsolute);
            if ($logoData !== false) {
                $logoMime = function_exists('mime_content_type') ? (mime_content_type($logoAbsolute) ?: 'image/png') : 'image/png';
                $certificateLogoSrc = 'data:' . $logoMime . ';base64,' . base64_encode($logoData);
            }
        } else {
            $certificateLogoSrc = rtrim(BASE_URL, '/') . '/' . $logoRelative;
        }
    }
}

require_once __DIR__ . '/../includes/certificate-signatories.php';
// The two signatories (title, name, signature) set under Certificate Signatories on the Baptism page.
$certSignatories = cert_signatories_load($conn);
foreach ($certSignatories as $sigIndex => $sig) {
    $certSignatories[$sigIndex]['src'] = cert_signature_src($sig['signature']);
}
// The first signatory's name falls back to the officiant recorded for this baptism.
if ($certSignatories[0]['name'] === '') $certSignatories[0]['name'] = trim((string)($r['officiant'] ?? ''));

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php if (!$isPdf): ?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content baptism-page">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php else: ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Baptism Certificate</title></head><body class="certificate-pdf" style="background:#fff">
<?php endif; ?>


<style>
/* A4 landscape: 297mm x 210mm, minus the 8mm margins leaves 281mm x 194mm. */
@page{
    size:A4 landscape;
    margin:8mm;
}

html,body{
    margin:0;
    padding:0;
    background:#ece9e2;
    font-family:"Times New Roman", Georgia, serif;
}

/* top buttons */
.no-print{
    text-align:center;
    padding:15px 10px;
}

.btn{
    display:inline-block;
    padding:10px 24px;
    margin:4px;
    border-radius:4px;
    text-decoration:none;
    color:#fff;
    font-size:15px;
    border:none;
    cursor:pointer;
}

.btn-print{background:#4b27a8;}
.btn-pdf{background:#d93444;}
.btn-back{background:#66707c;}

/* certificate */
.cert{
    width:281mm;
    max-width:100%;
    min-height:194mm;
    margin:0 auto 15px auto;
    background:#fff;
    border:4px double #4b27a8;
    padding:6mm;
    box-sizing:border-box;
    page-break-inside:avoid;
    break-inside:avoid;
}

.cert-inner{
    min-height:180mm;
    border:2px solid #d4a017;
    padding:6mm 14mm 12mm;
    box-sizing:border-box;
    text-align:center;
    position:relative;
}

.certificate-watermark{
    position:absolute;
    z-index:0;
    top:50%;
    left:50%;
    width:45%;
    height:65%;
    object-fit:contain;
    opacity:.055;
    transform:translate(-50%,-50%);
}
.cert-inner > :not(.certificate-watermark){position:relative;z-index:1}

.certificate-logo{
    display:block;
    max-width:120px;
    max-height:48px;
    width:auto;
    height:auto;
    object-fit:contain;
    margin:0 auto 4px;
}

.church-name{
    font-size:20px;
    color:#4b27a8;
    font-weight:bold;
    letter-spacing:1px;
}

.diocese{
    font-size:12px;
    color:#555;
}

h1{
    margin:8px 0 4px;
    color:#d4a017;
    font-size:28px;
    letter-spacing:2px;
}

.subtitle{
    color:#4b27a8;
    font-style:italic;
    font-size:15px;
    margin-bottom:6px;
}

.candidate{
    display:inline-block;
    font-size:28px;
    color:#4b27a8;
    font-weight:bold;
    border-bottom:2px solid #d4a017;
    padding:0 24px 4px;
    margin-bottom:8px;
}

.cert-body{
    font-size:16px;
    line-height:1.45;
    color:#111;
    margin-bottom:8px;
}

.details{
    width:100%;
    border-collapse:collapse;
    margin:4px 0 6px;
}

.details td{
    width:33.33%;
    padding:5px 8px;
    vertical-align:top;
    border-bottom:1px dotted #ccc;
}

.label{
    display:block;
    font-size:11px;
    color:#777;
    text-transform:uppercase;
    margin-bottom:2px;
}

.value{
    font-size:14px;
    font-weight:bold;
    color:#111;
}

.signatures{
    width:80%;
    margin:14px auto 0;
}

.signatures td{
    width:50%;
    padding:0 24px;
    text-align:center;
    vertical-align:top;
}

.signature-image{
    display:block;
    width:150px;
    height:40px;
    object-fit:contain;
    margin:0 auto 2px;
}
.signature-space{
    height:40px;
}
.signatory-name{
    font-size:13px;
    font-weight:bold;
    min-height:16px;
    margin-bottom:2px;
}
.sig-line{
    border-top:1px solid #4b27a8;
    padding-top:4px;
    font-size:13px;
}

.footer-note{
    position:absolute;
    left:0;
    right:0;
    bottom:4mm;
    font-size:11px;
    color:#777;
}
.cert-inner > .footer-note{position:absolute;left:0;right:0;bottom:4mm}

@media print{
    body{
        background:#fff;
    }

    .no-print{
        display:none !important;
    }

    /* One printed landscape page: a fixed-size frame inside the 281mm x 194mm printable area. */
    .cert{
        margin:0 auto;
        box-shadow:none;
        width:279mm;
        max-width:none;
        height:192mm;
        min-height:0;
        overflow:hidden;
    }
    .cert-inner{height:100%;min-height:0}
}

body.certificate-pdf .cert{
    page-break-before:avoid;
    page-break-after:avoid;
    page-break-inside:auto;
    break-before:avoid;
    break-after:avoid;
    break-inside:auto;
    /* Dompdf adds the 6mm padding and 4px border on top: 265+15 = 280mm wide, 177+15 = 192mm tall. */
    width:265mm;
    max-width:none;
    height:177mm;
    min-height:0;
    margin:0 auto;
    overflow:hidden;
}
body.certificate-pdf .cert-inner{
    /* Explicit height (not 100%) so the gold frame closes inside the outer one: 156+6+12 padding+border = 175mm. */
    height:156mm;
    min-height:0;
    padding-top:8mm;
}

/* Larger type for the printed and PDF page, which has room to spare. */
@media print{
    .certificate-logo{max-height:60px;margin-bottom:6px}
    .church-name{font-size:23px}
    .diocese{font-size:13px}
    h1{font-size:36px;margin:12px 0 6px}
    .subtitle{font-size:18px;margin-bottom:10px}
    .candidate{font-size:34px;margin-bottom:12px}
    .cert-body{font-size:19px;line-height:1.5;margin-bottom:14px}
    .details td{padding:8px 10px}
    .label{font-size:12px}
    .value{font-size:16px}
    .signatures{margin-top:26px}
    .signature-image,.signature-space{height:55px}
    .signatory-name,.sig-line{font-size:14px}
}
body.certificate-pdf .certificate-logo{max-height:60px;margin-bottom:6px}
body.certificate-pdf .church-name{font-size:23px}
body.certificate-pdf .diocese{font-size:13px}
body.certificate-pdf h1{font-size:36px;margin:12px 0 6px}
body.certificate-pdf .subtitle{font-size:18px;margin-bottom:10px}
body.certificate-pdf .candidate{font-size:34px;margin-bottom:12px}
body.certificate-pdf .cert-body{font-size:19px;line-height:1.5;margin-bottom:14px}
body.certificate-pdf .details td{padding:8px 10px}
body.certificate-pdf .label{font-size:12px}
body.certificate-pdf .value{font-size:16px}
body.certificate-pdf .signatures{margin-top:26px}
body.certificate-pdf .signature-image,body.certificate-pdf .signature-space{height:55px}
body.certificate-pdf .signatory-name,body.certificate-pdf .sig-line{font-size:14px}
</style>


<?php if(!$isPdf): ?>
<div class="no-print">
    <button onclick="window.print()" class="btn btn-print">Print</button>

    <?php if(file_exists(__DIR__.'/../vendor/autoload.php')): ?>
        <a href="?id=<?= $id ?>&pdf=1" class="btn btn-pdf">Download PDF</a>
    <?php endif; ?>

    <a href="index.php" class="btn btn-back">Back</a>
</div>
<?php endif; ?>

<div class="cert">
<div class="cert-inner">

<?php if($certificateLogoSrc !== ''): ?><img class="certificate-watermark" src="<?= $h($certificateLogoSrc) ?>" alt="" aria-hidden="true"><?php endif; ?>
<?php if($certificateLogoSrc !== ''): ?><img class="certificate-logo" src="<?= $h($certificateLogoSrc) ?>" alt="<?= $h($certificateBrand['name']) ?> logo"><?php endif; ?>
<div class="church-name"><?= $h($certificateBrand['name']) ?></div>
<?php if($certificateBrand['subtitle'] !== ''): ?><div class="diocese"><?= $h($certificateBrand['subtitle']) ?></div><?php endif; ?>

<h1>BAPTISM CERTIFICATE</h1>

<div class="subtitle">This is to certify that</div>

<div class="candidate"><?= $h($r['candidate_name']) ?></div>

<div class="cert-body">
<?php if($r['baptism_type'] === 'Infant'): ?>
was received into the Holy Sacrament of <strong>Infant Baptism</strong>
<?php elseif($r['baptism_type'] === 'Confirmation'): ?>
was <strong>Confirmed</strong> in the Faith
<?php else: ?>
received the Holy Sacrament of <strong><?= $h($r['baptism_type']) ?> Baptism</strong>
<?php endif; ?>
<br>
at <strong><?= $h($r['venue']) ?></strong>
</div>

<table class="details">
<tr>
<td>
<span class="label">Date of Baptism</span>
<span class="value"><?= date('d F Y', strtotime($r['baptism_date'])) ?></span>
</td>
<td>
<span class="label">Father</span>
<span class="value"><?= $h($r['parent_father'] ?: '-') ?></span>
</td>
<td>
<span class="label">Godfather</span>
<span class="value"><?= $h($r['godfather'] ?: '-') ?></span>
</td>
</tr>
<tr>
<td>
<span class="label">Officiant</span>
<span class="value"><?= $h($r['officiant'] ?: '-') ?></span>
</td>
<td>
<span class="label">Mother</span>
<span class="value"><?= $h($r['parent_mother'] ?: '-') ?></span>
</td>
<td>
<span class="label">Godmother</span>
<span class="value"><?= $h($r['godmother'] ?: '-') ?></span>
</td>
</tr>
</table>

<table class="signatures">
<tr>
<?php foreach ($certSignatories as $sig): ?>
<td>
<?php if ($sig['src'] !== ''): ?>
<img class="signature-image" src="<?= $h($sig['src']) ?>" alt="<?= $h($sig['title']) ?> signature">
<?php else: ?>
<div class="signature-space"></div>
<?php endif; ?>
<div class="signatory-name"><?= $h($sig['name']) ?></div>
<div class="sig-line"><?= $h($sig['title']) ?></div>
</td>
<?php endforeach; ?>
</tr>
</table>

<div class="footer-note">
Baptism No: <?= $h($r['baptism_no']) ?>
<?php if(!empty($r['certificate_no'])): ?>
 | Cert No: <?= $h($r['certificate_no']) ?>
<?php endif; ?>
 | Printed: <?= $generated ?>
</div>

</div>
</div>

<?php if (!$isPdf): ?>
</div>
</div>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* PDF DOWNLOAD */
if($isPdf){

    $autoload = __DIR__.'/../vendor/autoload.php';

    if(file_exists($autoload)){

        require_once $autoload;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Times-Roman');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $dompdf->stream(
            'Baptism_Certificate_'.$id.'.pdf',
            ['Attachment' => true]
        );
        exit;
    }
}

echo $html;
?>
