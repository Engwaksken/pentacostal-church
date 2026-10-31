<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_permission('events.view');
require_once __DIR__ . '/certificate-functions.php';
if (!event_certificate_tables_ready($conn)) { http_response_code(404); exit('Certificate not found.'); }
$certificateId = (int)($_GET['certificate_id'] ?? 0);
$stmt = $conn->prepare('SELECT ec.*,e.title event_title,e.event_type,e.event_date,e.venue,e.organizer,m.id member_id,m.full_name,m.member_no FROM event_certificates ec JOIN events e ON e.id=ec.event_id JOIN members m ON m.id=ec.member_id JOIN event_attendance ea ON ea.event_id=ec.event_id AND ea.member_id=ec.member_id AND ea.attended=1 WHERE ec.id=? LIMIT 1');
if (!$stmt) { http_response_code(500); exit('Unable to load the certificate.'); }
$stmt->bind_param('i',$certificateId);$stmt->execute();$certificate=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$certificate) { http_response_code(404); exit('Certificate not found.'); }
$isAdmin = event_certificate_is_admin();
$memberId = function_exists('current_member_id') ? current_member_id() : 0;
if (!$isAdmin && ($memberId <= 0 || $memberId !== (int)$certificate['member_id'])) { http_response_code(403); exit('This certificate is not available to your account.'); }
$brand = event_certificate_brand($conn);
$logoSrc = event_certificate_logo_src((string)$brand['logo']);
$templateSrc = event_certificate_logo_src(event_certificate_setting($conn,'event_certificate_template',''));
$nameTop = (float)event_certificate_setting($conn,'event_certificate_name_top','52');
$detailsTop = (float)event_certificate_setting($conn,'event_certificate_details_top','70');
$certificateName = trim((string)$certificate['certificate_name']) ?: $brand['name'];
$participant = trim((string)$certificate['full_name']);
$eventDate = date('d F Y',strtotime((string)$certificate['event_date']));
$e = static fn($v): string => htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
// The church's signature line uses the first certificate signatory (title and name) set on the Baptism page.
require_once __DIR__ . '/../includes/certificate-signatories.php';
$churchSignatory = cert_signatories_load($conn)[0];
$isPdf = isset($_GET['pdf']) && $_GET['pdf'] === '1';
if ($isPdf) require_permission('events.view');
$pageTitle = 'Event Participation Certificate';
ob_start();
if (!$isPdf) {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    echo '<div class="main-content">';
    require_once __DIR__ . '/../includes/topbar.php';
    echo '<div class="content-area event-certificate-page">';
} else {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Event Participation Certificate</title></head><body class="event-certificate-pdf">';
}
?>
<style>
*{box-sizing:border-box}.event-certificate-page{padding:0 0 24px;color:#172033}.certificate-toolbar{display:flex;justify-content:flex-end;gap:10px;max-width:1100px;margin:0 auto 18px;padding:14px;background:#fff;border-radius:10px}.certificate-toolbar a,.certificate-toolbar button{padding:9px 14px;border:0;border-radius:6px;background:#4a1e8f;color:#fff;text-decoration:none;font:700 13px Arial,sans-serif;cursor:pointer}.certificate-toolbar a.secondary{background:#667085}.event-certificate{position:relative;isolation:isolate;display:flex;flex-direction:column;justify-content:center;width:min(1100px,100%);height:720px;margin:0 auto;padding:54px 72px;text-align:center;background:#fff;border:10px double #4a1e8f;outline:2px solid #d4a017;outline-offset:-24px;box-shadow:0 15px 42px #10182822;font-family:Georgia,'Times New Roman',serif}.event-certificate-watermark{position:absolute;z-index:0;inset:15% 20%;width:60%;height:70%;object-fit:contain;opacity:.06;pointer-events:none}.event-certificate>*:not(.event-certificate-watermark){position:relative;z-index:1}.event-certificate>.event-cert-number{position:absolute;z-index:2}.event-cert-logo{display:block;max-width:150px;max-height:85px;width:auto;height:auto;object-fit:contain;margin:0 auto 8px}.event-cert-name{font:700 22px Arial,sans-serif;color:#4a1e8f;letter-spacing:.5px}.event-cert-subtitle{margin-top:5px;font:14px Arial,sans-serif;color:#667085}.event-cert-eyebrow{margin:38px 0 10px;color:#a47a0a;font:700 13px Arial,sans-serif;letter-spacing:4px;text-transform:uppercase}.event-certificate h1{margin:0;color:#4a1e8f;font-size:42px;letter-spacing:2px;text-transform:uppercase}.event-cert-intro{margin:22px 0 8px;font-size:21px}.event-cert-person{display:inline-block;margin:4px auto 20px;padding:0 30px 8px;border-bottom:2px solid #d4a017;color:#4a1e8f;font-size:38px;font-weight:700}.event-cert-statement{max-width:800px;margin:0 auto;font-size:20px;line-height:1.65}.event-cert-event{font-weight:700}.event-cert-signatures{display:flex;justify-content:space-around;gap:60px;margin:65px auto 0;max-width:780px}.event-cert-signature{width:270px;padding-top:9px;border-top:1px solid #4a1e8f;font:14px Arial,sans-serif}.event-cert-number{right:58px;bottom:42px;color:#667085;font:11px Arial,sans-serif}.event-certificate.custom-template{display:block;padding:0;border:0;outline:0;box-shadow:none;background:transparent}.template-background{position:absolute;inset:0;width:100%;height:100%;object-fit:fill;z-index:0}.template-member-name{position:absolute;z-index:1;top:var(--member-name-top);left:8%;width:84%;transform:translateY(-50%);text-align:center;font:700 36px Georgia,'Times New Roman',serif;color:#172033;text-shadow:0 1px 2px #fff,0 0 5px #fff}.template-event-details{position:absolute;z-index:1;top:var(--event-details-top);left:10%;width:80%;transform:translateY(-50%);text-align:center;font:20px Georgia,'Times New Roman',serif;line-height:1.5;color:#172033;text-shadow:0 1px 2px #fff,0 0 5px #fff}.template-certificate-number{position:absolute;z-index:1;right:6%;bottom:5%;font:12px Arial,sans-serif;color:#172033;text-shadow:0 1px 2px #fff,0 0 5px #fff}.event-certificate.custom-template>.template-background{position:absolute}.event-certificate.custom-template>.template-member-name,.event-certificate.custom-template>.template-event-details,.event-certificate.custom-template>.template-certificate-number{position:absolute}.event-certificate.custom-template .template-background{z-index:0}.event-certificate.custom-template .template-member-name,.event-certificate.custom-template .template-event-details,.event-certificate.custom-template .template-certificate-number{z-index:1}@media print{@page{size:A4 landscape;margin:10mm}body *{visibility:hidden!important}.event-certificate,.event-certificate *{visibility:visible!important}.event-certificate{position:fixed;left:0;top:0;width:100vw;height:185mm;margin:0;padding:35px 55px;box-shadow:none;break-inside:avoid}}body.event-certificate-pdf{margin:0;padding:0;background:#fff}@page{size:A4 landscape;margin:8mm}.event-certificate-pdf .event-certificate{width:246mm;height:168mm;margin:0;padding:35px 55px;box-shadow:none}.event-certificate-pdf .event-cert-eyebrow{margin-top:22px}.event-certificate-pdf .event-cert-signatures{margin-top:42px;display:table;width:100%;max-width:none;border-spacing:70px 0}.event-certificate-pdf .event-cert-signature{display:table-cell;width:50%}.event-certificate-pdf .event-certificate.custom-template{padding:0}
</style>
<?php if (!$isPdf): ?><div class="certificate-toolbar"><button type="button" onclick="window.print()">Print</button><?php if (is_file(__DIR__.'/../vendor/autoload.php')): ?><a href="?certificate_id=<?=(int)$certificate['id']?>&pdf=1">Download PDF</a><?php endif; ?><a class="secondary" href="<?= $isAdmin ? 'attendance.php?id='.(int)$certificate['event_id'] : '../member/event-certificates.php' ?>">Back</a></div><?php endif; ?>
<?php if($templateSrc!==''):?><main class="event-certificate custom-template"><img class="template-background" src="<?=$e($templateSrc)?>" alt=""><div class="template-member-name" style="top:<?=htmlspecialchars(number_format($nameTop,1,'.',''),ENT_QUOTES,'UTF-8')?>%"> <?=$e($participant)?></div><div class="template-event-details" style="top:<?=htmlspecialchars(number_format($detailsTop,1,'.',''),ENT_QUOTES,'UTF-8')?>%">Participation in <?=$e($certificate['event_title'])?><br><?=$e($eventDate)?><?=!empty($certificate['venue'])?' | '.$e($certificate['venue']):''?></div><div class="template-certificate-number">Certificate No: <?=$e($certificate['certificate_no'])?></div></main><?php else: ?><main class="event-certificate">
<?php if($logoSrc!==''):?><img class="event-certificate-watermark" src="<?=$e($logoSrc)?>" alt="" aria-hidden="true"><?php endif;?>
<?php if($logoSrc!==''):?><img class="event-cert-logo" src="<?=$e($logoSrc)?>" alt="<?=$e($certificateName)?> logo"><?php endif;?>
<div class="event-cert-name"><?=$e($certificateName)?></div>
<?php if($brand['subtitle']!==''):?><div class="event-cert-subtitle"><?=$e($brand['subtitle'])?></div><?php endif;?>
<div class="event-cert-eyebrow">Certificate of Participation</div><h1>Event Certificate</h1>
<p class="event-cert-intro">This certificate is proudly presented to</p><div class="event-cert-person"><?=$e($participant)?></div>
<p class="event-cert-statement">In recognition of participation in <span class="event-cert-event"><?=$e($certificate['event_title'])?></span><?=!empty($certificate['event_type'])?' ('.$e($certificate['event_type']).')':''?>, held on <strong><?=$e($eventDate)?></strong><?=!empty($certificate['venue'])?' at <strong>'.$e($certificate['venue']).'</strong>':''?>.</p>
<div class="event-cert-signatures"><div class="event-cert-signature"><?=$e($certificate['organizer']?:'Event Organizer')?><br>Organizer</div><div class="event-cert-signature"><?=$churchSignatory['name']!==''?$e($churchSignatory['name']).'<br>':''?><?=$e($churchSignatory['title'])?></div></div>
<div class="event-cert-number">Certificate No: <?=$e($certificate['certificate_no'])?></div>
</main><?php endif; ?>
<?php
if ($isPdf) {
    echo '</body></html>';
    $html = ob_get_clean();
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) { http_response_code(503); exit('PDF download is not available. Please use Print and choose Save as PDF.'); }
    require_once $autoload;
    $options = new \Dompdf\Options(); $options->set('isRemoteEnabled',true); $options->set('isHtml5ParserEnabled',true); $options->set('defaultFont','Times-Roman');
    $pdf = new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();
    $pdf->stream('Event_Certificate_'.$certificate['certificate_no'].'.pdf',['Attachment'=>true]);exit;
}
echo '</div></div>';
require_once __DIR__ . '/../includes/footer.php';
?>
