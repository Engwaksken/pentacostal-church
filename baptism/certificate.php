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
@page{
    size:A4 portrait;
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
    width:190mm;
    min-height:270mm;
    margin:0 auto 15px auto;
    background:#fff;
    border:4px double #4b27a8;
    padding:12mm;
    box-sizing:border-box;
    page-break-inside:avoid;
    break-inside:avoid;
}

.cert-inner{
    min-height:245mm;
    border:2px solid #d4a017;
    padding:12mm;
    box-sizing:border-box;
    text-align:center;
    position:relative;
}

.certificate-watermark{
    position:absolute;
    z-index:0;
    top:50%;
    left:50%;
    width:65%;
    height:55%;
    object-fit:contain;
    opacity:.055;
    transform:translate(-50%,-50%);
}
.cert-inner > :not(.certificate-watermark){position:relative;z-index:1}

.cross{
    font-size:34px;
    color:#4b27a8;
    line-height:1;
    margin-bottom:4px;
}

.certificate-logo{
    display:block;
    max-width:150px;
    max-height:85px;
    width:auto;
    height:auto;
    object-fit:contain;
    margin:0 auto 10px;
}

.church-name{
    font-size:20px;
    color:#4b27a8;
    font-weight:bold;
    letter-spacing:1px;
}

.diocese{
    font-size:13px;
    color:#555;
    margin-bottom:12px;
}

h1{
    margin:8px 0 6px;
    color:#d4a017;
    font-size:30px;
    letter-spacing:1px;
}

.subtitle{
    color:#4b27a8;
    font-style:italic;
    font-size:18px;
    margin-bottom:16px;
}

.candidate{
    display:inline-block;
    font-size:34px;
    color:#4b27a8;
    font-weight:bold;
    border-bottom:2px solid #d4a017;
    padding:0 18px 6px;
    margin-bottom:14px;
}

.cert-body{
    font-size:23px;
    line-height:1.65;
    color:#111;
    margin-bottom:18px;
}

.details{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    margin-bottom:24px;
}

.details td{
    width:50%;
    padding:8px 6px;
    vertical-align:top;
    border-bottom:1px dotted #ccc;
}

.label{
    display:block;
    font-size:12px;
    color:#777;
    text-transform:uppercase;
    margin-bottom:3px;
}

.value{
    font-size:16px;
    font-weight:bold;
    color:#111;
}

.signatures{
    width:100%;
    margin-top:30px;
}

.signatures td{
    width:50%;
    padding:0 10px;
    text-align:center;
    vertical-align:top;
}

.signature-image{
    display:block;
    width:180px;
    height:70px;
    object-fit:contain;
    margin:0 auto 2px;
}
.signature-space{
    height:70px;
}
.signatory-name{
    font-size:14px;
    font-weight:bold;
    min-height:18px;
    margin-bottom:2px;
}
.sig-line{
    border-top:1px solid #4b27a8;
    padding-top:6px;
    font-size:14px;
}

.footer-note{
    position:absolute;
    left:0;
    right:0;
    bottom:8mm;
    font-size:11px;
    color:#777;
}
.cert-inner > .footer-note{position:absolute;left:0;right:0;bottom:8mm}

@media print{
    body{
        background:#fff;
    }

    .no-print{
        display:none !important;
    }

    /* One printed A4 page: a fixed-size frame (281mm is the page minus the 8mm margins) and the PDF's text sizes. */
    .cert{
        margin:0 auto;
        box-shadow:none;
        width:190mm;
        height:279mm;
        min-height:0;
        padding:6mm;
        overflow:hidden;
    }
    .cert-inner{height:100%;min-height:0;padding:5mm}
    .certificate-logo{max-width:150px;max-height:95px;margin:6mm auto 6px}
    .church-name{font-size:21px}
    h1{font-size:32px;margin:8px 0}
    .diocese{font-size:13px;margin-bottom:8px}
    .subtitle{font-size:16px;margin-bottom:10px}
    .candidate{font-size:30px;margin-bottom:12px;padding-bottom:6px}
    .cert-body{font-size:17px;line-height:1.6;margin-bottom:12px}
    .details{margin-top:10px;margin-bottom:12px}
    .details td{padding:8px 6px}
    .value{font-size:15px}
    .signatures{margin-top:18mm}
    .signature-image,.signature-space{height:60px}
    .signatory-name,.sig-line{font-size:13px}
}

body.certificate-pdf .cert{
    page-break-before:avoid;
    page-break-after:avoid;
    page-break-inside:auto;
    break-before:avoid;
    break-after:avoid;
    break-inside:auto;
    /* One A4 page: 297mm minus the 8mm @page margins leaves 281mm; the frame stays well inside it. */
    width:190mm;
    height:262mm;
    min-height:0;
    margin:0 auto;
    padding:6mm;
    overflow:hidden;
}
body.certificate-pdf .cert-inner{
    /* Explicit height (not 100%) so the gold frame closes inside the outer one even without box-sizing support. */
    height:240mm;
    min-height:0;
    padding:5mm;
}
body.certificate-pdf .certificate-logo{max-width:150px;max-height:95px;margin:6mm auto 6px}
body.certificate-pdf .church-name{font-size:21px;letter-spacing:.5px}
body.certificate-pdf h1{font-size:32px;margin:8px 0}
body.certificate-pdf .diocese{font-size:13px;margin-bottom:8px}
body.certificate-pdf .subtitle{font-size:16px;margin-bottom:10px}
body.certificate-pdf .candidate{font-size:30px;margin-bottom:12px;padding-bottom:6px}
body.certificate-pdf .cert-body{font-size:17px;line-height:1.6;margin-bottom:12px}
body.certificate-pdf .details{margin-top:10px;margin-bottom:12px}
body.certificate-pdf .details td{padding:8px 6px}
body.certificate-pdf .value{font-size:15px}
body.certificate-pdf .signatures{margin-top:22mm}
body.certificate-pdf .signature-image,body.certificate-pdf .signature-space{height:60px}
body.certificate-pdf .signatory-name,body.certificate-pdf .sig-line{font-size:13px}
body.certificate-pdf .footer-note{bottom:4mm}
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

<?php if($certificateLogoSrc !== ''): ?><img class="certificate-watermark" src="<?= htmlspecialchars($certificateLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true"><?php endif; ?>
<?php if($certificateLogoSrc !== ''): ?><img class="certificate-logo" src="<?= htmlspecialchars($certificateLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($certificateBrand['name'], ENT_QUOTES, 'UTF-8') ?> logo"><?php endif; ?>
<div class="church-name"><?= htmlspecialchars($certificateBrand['name'], ENT_QUOTES, 'UTF-8') ?></div>
<?php if($certificateBrand['subtitle'] !== ''): ?><div class="diocese"><?= htmlspecialchars($certificateBrand['subtitle'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<h1>BAPTISM CERTIFICATE</h1>

<div class="subtitle">This is to certify that</div>

<div class="candidate">
<?= htmlspecialchars($r['candidate_name']) ?>
</div>

<div class="cert-body">
<?php if($r['baptism_type'] === 'Infant'): ?>

was received into the Holy Sacrament of
<strong>Infant Baptism</strong>

<?php elseif($r['baptism_type'] === 'Confirmation'): ?>

was <strong>Confirmed</strong> in the Faith

<?php else: ?>

received the Holy Sacrament of
<strong><?= htmlspecialchars($r['baptism_type']) ?> Baptism</strong>

<?php endif; ?>

<br><br>

at <strong><?= htmlspecialchars($r['venue']) ?></strong>
</div>

<table class="details">
<tr>
<td>
<span class="label">Date of Baptism</span>
<span class="value"><?= date('d F Y', strtotime($r['baptism_date'])) ?></span>
</td>

<td>
<span class="label">Officiant</span>
<span class="value"><?= htmlspecialchars($r['officiant'] ?: '-') ?></span>
</td>
</tr>

<tr>
<td>
<span class="label">Godfather</span>
<span class="value"><?= htmlspecialchars($r['godfather'] ?: '-') ?></span>
</td>

<td>
<span class="label">Godmother</span>
<span class="value"><?= htmlspecialchars($r['godmother'] ?: '-') ?></span>
</td>
</tr>

<tr>
<td>
<span class="label">Father</span>
<span class="value"><?= htmlspecialchars($r['parent_father'] ?: '-') ?></span>
</td>

<td>
<span class="label">Mother</span>
<span class="value"><?= htmlspecialchars($r['parent_mother'] ?: '-') ?></span>
</td>
</tr>
</table>

<table class="signatures">
<tr>
<?php foreach ($certSignatories as $sig): ?>
<td>
<?php if ($sig['src'] !== ''): ?>
<img class="signature-image" src="<?= htmlspecialchars($sig['src'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($sig['title'], ENT_QUOTES, 'UTF-8') ?> signature">
<?php else: ?>
<div class="signature-space"></div>
<?php endif; ?>
<div class="signatory-name"><?= htmlspecialchars($sig['name'], ENT_QUOTES, 'UTF-8') ?></div>
<div class="sig-line"><?= htmlspecialchars($sig['title'], ENT_QUOTES, 'UTF-8') ?></div>
</td>
<?php endforeach; ?>
</tr>
</table>

<div class="footer-note">
Baptism No: <?= htmlspecialchars($r['baptism_no']) ?>

<?php if(!empty($r['certificate_no'])): ?>
 | Cert No: <?= htmlspecialchars($r['certificate_no']) ?>
<?php endif; ?>

 | Printed: <?= $generated ?>
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
        $dompdf->setPaper('A4', 'portrait');
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
