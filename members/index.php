<?php
$pageTitle = 'Members';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../includes/member-fields.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

// One-time: existing member numbers lose the year and usernames become member numbers (see the include).
require_once '../includes/member-number-migration.php';
$memberNumberMigration = member_numbers_migrate_once($conn);

if (!function_exists('h'))       { function h($v)    { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('fmtDate')) { function fmtDate($v) { return empty($v)||strtotime((string)$v)===false?'-':date('d M Y',strtotime((string)$v)); } }
if (!function_exists('bindParams')) {
    function bindParams(mysqli_stmt $stmt, string $types, array &$p): void {
        if ($types===''||empty($p)) return;
        $refs=[$types]; foreach($p as $k=>$v) $refs[]=&$p[$k];
        call_user_func_array([$stmt,'bind_param'],$refs);
    }
}
if (!function_exists('pageUrl')) {
    function pageUrl($page, array $extra=[]): string {
        $q=array_merge($_GET,$extra,['page'=>(int)$page]);
        foreach($q as $k=>$v) if($v===''||$v===null) unset($q[$k]);
        return 'index.php'.($q?'?'.http_build_query($q):'');
    }
}

function member_table_exists(mysqli $conn, string $table): bool {
    $stmt=$conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s',$table); $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c']??0)>0;
}
function member_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache=[];
    $key="$table.$col"; if(array_key_exists($key,$cache)) return (bool)$cache[$key];
    $stmt=$conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss',$table,$col); $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key]=((int)($row['c']??0)>0);
}
function member_zone_table(mysqli $conn): ?string {
    foreach(['church_zones','zones'] as $t) if(member_table_exists($conn,$t)) return $t;
    return null;
}
function member_zone_name_col(mysqli $conn, string $table): string {
    foreach(['zone_name','name','title'] as $c) if(member_col_exists($conn,$table,$c)) return $c;
    return 'id';
}
function member_ensure_zone_col(mysqli $conn): bool {
    if(!member_col_exists($conn,'members','church_zone_id')) {
        try { $conn->query("ALTER TABLE members ADD COLUMN church_zone_id INT NULL AFTER baptism_date"); }
        catch(Throwable $e){ error_log('Could not add church_zone_id: '.$e->getMessage()); }
    }
    return member_col_exists($conn,'members','church_zone_id');
}

$hasZoneCol    = member_ensure_zone_col($conn);
$hasExtraCols  = member_fields_ensure($conn);
$extraSelectSql= $hasExtraCols ? ", m.pledge_no, m.service_attended" : ", '' AS pledge_no, '' AS service_attended";
$serviceOptions= member_fields_service_options($conn);
$zoneTable     = member_zone_table($conn);
$zoneNameCol   = $zoneTable ? member_zone_name_col($conn,$zoneTable) : '';
$zoneSelectSql = ($hasZoneCol&&$zoneTable) ? ", m.church_zone_id, COALESCE(z.`$zoneNameCol`,'') AS zone_name" : ", NULL AS church_zone_id, '' AS zone_name";
$zoneJoinSql   = ($hasZoneCol&&$zoneTable) ? " LEFT JOIN `$zoneTable` z ON z.id=m.church_zone_id " : '';

/* -- filters / pagination -- */
$search  = trim((string)($_GET['search']??''));
$status  = trim((string)($_GET['status']??''));
$dateFrom = trim((string)($_GET['date_from']??''));
$dateTo   = trim((string)($_GET['date_to']??''));
$zoneId   = max(0,(int)($_GET['zone_id']??0));
$service  = trim((string)($_GET['service']??''));
$page    = max(1,(int)($_GET['page']??1));
$pp      = [10,25,50,100];
$perPage = (int)($_GET['per_page']??10);
if(!in_array($perPage,$pp,true)) $perPage=10;
if($status!==''&&!in_array($status,['Active','Inactive'],true)) $status='';
if($dateFrom!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)) $dateFrom='';
if($dateTo!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)) $dateTo='';
if($dateFrom!=='' && $dateTo!=='' && $dateFrom>$dateTo){[$dateFrom,$dateTo]=[$dateTo,$dateFrom];}

$baseWhere='WHERE 1=1'; $baseParams=[]; $baseTypes='';
if($search!==''){
    $baseWhere.=" AND (m.full_name LIKE ? OR m.member_no LIKE ? OR m.phone LIKE ? OR m.email LIKE ? OR m.occupation LIKE ? OR m.department LIKE ? OR d.name LIKE ?";
    $like='%'.$search.'%';
    for($i=0;$i<7;$i++){$baseParams[]=$like;$baseTypes.='s';}
    if($hasExtraCols){$baseWhere.=" OR m.pledge_no LIKE ? OR m.service_attended LIKE ?";$baseParams[]=$like;$baseParams[]=$like;$baseTypes.='ss';}
    if($hasZoneCol&&$zoneTable){$baseWhere.=" OR z.`$zoneNameCol` LIKE ?";$baseParams[]=$like;$baseTypes.='s';}
    $baseWhere.=")";
}
if($status!==''){$baseWhere.=' AND m.status=?';$baseParams[]=$status;$baseTypes.='s';}
if($dateFrom!==''){$baseWhere.=' AND DATE(m.joined_date)>=?';$baseParams[]=$dateFrom;$baseTypes.='s';}
if($dateTo!==''){$baseWhere.=' AND DATE(m.joined_date)<=?';$baseParams[]=$dateTo;$baseTypes.='s';}
if($service!=='' && $hasExtraCols){$baseWhere.=' AND m.service_attended=?';$baseParams[]=$service;$baseTypes.='s';}

/* The full filter includes the selected zone. The base filter is reused to
   calculate each zone's count without limiting every card to one zone. */
$where=$baseWhere; $params=$baseParams; $types=$baseTypes;
if($zoneId>0 && $hasZoneCol){$where.=' AND m.church_zone_id=?';$params[]=$zoneId;$types.='i';}

$countStmt=$conn->prepare("SELECT COUNT(*) AS total FROM members m LEFT JOIN departments d ON d.id=m.dept_id $zoneJoinSql $where");
bindParams($countStmt,$types,$params); $countStmt->execute();
$totalRows=(int)($countStmt->get_result()->fetch_assoc()['total']??0); $countStmt->close();
$totalPages=max(1,(int)ceil($totalRows/$perPage));
if($page>$totalPages) $page=$totalPages;
$offset=($page-1)*$perPage;

$listSql="
    SELECT m.id,m.member_no,m.full_name,m.gender,m.date_of_birth,
           m.phone,m.email,m.address,m.marital_status,m.occupation,m.department,
           m.dept_id,m.joined_date,m.baptism_date,m.status,m.photo,m.notes,m.created_at,
           COALESCE(d.name,m.department,'') AS dept_name
           $extraSelectSql
           $zoneSelectSql
    FROM members m
    LEFT JOIN departments d ON d.id=m.dept_id
    $zoneJoinSql
    $where
    ORDER BY m.id DESC LIMIT ? OFFSET ?";
$lp=$params; $lt=$types.'ii'; $lp[]=$perPage; $lp[]=$offset;
$stmt=$conn->prepare($listSql); bindParams($stmt,$lt,$lp); $stmt->execute();
$members=$stmt->get_result();

$summary=['total'=>0,'active'=>0,'today'=>0,'week'=>0];
$summarySql="SELECT COUNT(*) total,
                    COALESCE(SUM(m.status='Active'),0) active,
                    COALESCE(SUM(DATE(m.joined_date)=CURDATE()),0) today,
                    COALESCE(SUM(YEARWEEK(m.joined_date,1)=YEARWEEK(CURDATE(),1)),0) week
             FROM members m
             LEFT JOIN departments d ON d.id=m.dept_id
             $zoneJoinSql
             $where";
$summaryParams=$params;
$summaryStmt=$conn->prepare($summarySql);
bindParams($summaryStmt,$types,$summaryParams);
$summaryStmt->execute();
$sr=$summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();
foreach($summary as $k=>$v) $summary[$k]=(int)($sr[$k]??0);
$activeMembers=$summary['active'];

$zoneStats=[];
if($hasZoneCol&&$zoneTable){
    /* Counts per zone respect search, status and date range, but intentionally
       ignore the currently selected zone so users can switch zones directly. */
    $zStatSql="SELECT z.id, z.`$zoneNameCol` zone_name, COUNT(m.id) total
               FROM `$zoneTable` z
               LEFT JOIN members m ON m.church_zone_id=z.id
               LEFT JOIN departments d ON d.id=m.dept_id
               $baseWhere
               GROUP BY z.id,z.`$zoneNameCol`
               ORDER BY z.`$zoneNameCol`";
    $zoneParams=$baseParams;
    $zStmt=$conn->prepare($zStatSql);
    bindParams($zStmt,$baseTypes,$zoneParams);
    $zStmt->execute();
    $zr=$zStmt->get_result();
    while($row=$zr->fetch_assoc()) $zoneStats[]=$row;
    $zStmt->close();
}

$departments=[];
$dR=member_table_exists($conn,'departments')?$conn->query("SELECT id,name FROM departments WHERE COALESCE(status,'Active')='Active' ORDER BY name"):false;
if($dR) while($r=$dR->fetch_assoc()) $departments[]=$r;

$zones=[];
if($zoneTable){
    $sf=member_col_exists($conn,$zoneTable,'status')?" WHERE COALESCE(status,'Active')='Active'":'';
    $zR=$conn->query("SELECT id,`$zoneNameCol` AS zone_name FROM `$zoneTable` $sf ORDER BY `$zoneNameCol`");
    if($zR) while($z=$zR->fetch_assoc()) $zones[]=$z;
}

$startRow=$totalRows>0?$offset+1:0;
$endRow=min($offset+$perPage,$totalRows);

/* -- JSON blobs for JS -- */
$deptJson = json_encode(array_map(fn($d)=>['id'=>(int)$d['id'],'name'=>$d['name']],$departments));
$zoneJson = json_encode(array_map(fn($z)=>['id'=>(int)$z['id'],'name'=>$z['zone_name']],$zones));
$exportQuery = http_build_query(array_filter(['search'=>$search,'status'=>$status,'date_from'=>$dateFrom,'date_to'=>$dateTo,'zone_id'=>$zoneId>0?$zoneId:'','service'=>$service],fn($v)=>$v!==''));
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

    <!-- -- Page header ---------------------------------------- -->
    <div class="module-page-header">
        <div class="module-title">
            <h2><i class="fas fa-users"></i> Church Members</h2>
            <p>Manage registered church members<?= $zoneId>0 ? ' in the selected zone' : ' across all zones' ?>.</p>
        </div>
        <div class="module-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <!-- Export buttons -->
            <div class="btn-group" style="display:flex;gap:6px">
                <a href="process-member.php?action=export_csv<?= $exportQuery!==''?'&'.h($exportQuery):'' ?>"
                   class="btn btn-outline btn-sm" title="Download all members as CSV">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="process-member.php?action=export_pdf<?= $exportQuery!==''?'&'.h($exportQuery):'' ?>"
                   class="btn btn-outline btn-sm" title="Download members list as PDF"
                   target="_blank">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <button type="button" class="btn btn-outline btn-sm js-open-import-modal"
                        title="Import members from CSV file">
                    <i class="fas fa-file-upload"></i> Import CSV
                </button>
            </div>
            <button type="button" class="btn btn-outline js-open-registration-link" title="Generate a public self-registration link and QR code">
                <i class="fas fa-qrcode"></i> Registration Link / QR
            </button>
            <button type="button" class="btn btn-primary js-open-add-members">
                <i class="fas fa-user-plus"></i> Add Members
            </button>
        </div>
    </div>

    <?php if (function_exists('showFlash')) showFlash(); ?>
    <?php if (!empty($memberNumberMigration['message'])): ?>
    <div class="alert <?= $memberNumberMigration['ok'] ? 'alert-success' : 'alert-danger' ?>" style="margin-bottom:16px"><?= h($memberNumberMigration['message']) ?></div>
    <?php endif; ?>

    <div id="memberAlertArea"></div>

    <style>
    .member-stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
    .member-stat-card{background:#fff;border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:0 4px 18px rgba(15,23,42,.05)}
    .member-stat-icon{width:48px;height:48px;border-radius:13px;display:grid;place-items:center;background:rgba(46,49,146,.1);color:#2e3192;font-size:20px}
    .member-stat-card strong{display:block;font-size:25px;line-height:1.1;color:#172033}.member-stat-card span{font-size:13px;color:#6b7280}
    .zone-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:0 0 16px}
    .zone-stat{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#fff;border:1px solid var(--border,#e5e7eb);border-radius:12px;text-decoration:none;color:inherit}
    .zone-stat.active,.zone-stat:hover{border-color:#2e3192;background:rgba(46,49,146,.04)}
    .zone-stat b{font-size:18px;color:#2e3192}.zone-stat small{display:block;color:#6b7280;margin-top:2px}
    @media(max-width:1100px){.member-stats-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:620px){.member-stats-grid{grid-template-columns:1fr}#memberRegistrationLinkModal .form-grid{grid-template-columns:1fr!important}#memberRegistrationLinkModal .modal-body>div[style*="grid-template-columns:minmax"]{grid-template-columns:1fr!important}}
    </style>

    <div class="member-stats-grid">
        <div class="member-stat-card"><div class="member-stat-icon"><i class="fas fa-users"></i></div><div><strong><?= number_format($summary['total']) ?></strong><span>Total Members</span></div></div>
        <div class="member-stat-card"><div class="member-stat-icon"><i class="fas fa-user-check"></i></div><div><strong><?= number_format($summary['active']) ?></strong><span>Active Members</span></div></div>
        <div class="member-stat-card"><div class="member-stat-icon"><i class="fas fa-calendar-day"></i></div><div><strong><?= number_format($summary['today']) ?></strong><span>Joined Today</span></div></div>
        <div class="member-stat-card"><div class="member-stat-icon"><i class="fas fa-calendar-week"></i></div><div><strong><?= number_format($summary['week']) ?></strong><span>Joined This Week</span></div></div>
    </div>

    <?php if($zoneStats): ?>
    <div class="zone-stats">
        <a class="zone-stat <?= $zoneId===0?'active':'' ?>" href="<?= h(pageUrl(1,['zone_id'=>null])) ?>">
            <div><strong>All Zones</strong><small>Show every zone</small></div><b><?= number_format(array_sum(array_map(fn($z)=>(int)$z['total'],$zoneStats))) ?></b>
        </a>
        <?php foreach($zoneStats as $zs): ?>
        <a class="zone-stat <?= $zoneId===(int)$zs['id']?'active':'' ?>" href="<?= h(pageUrl(1,['zone_id'=>(int)$zs['id']])) ?>">
            <div><strong><?= h($zs['zone_name']) ?></strong><small>Church Zone</small></div><b><?= number_format((int)$zs['total']) ?></b>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- -- Filter toolbar ------------------------------------- -->
    <form method="GET" action="index.php" class="page-toolbar">
            <div class="page-toolbar-search">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= h($search) ?>"
                       placeholder="Search name, member/pledge no...">
            </div>
            <div class="page-toolbar-filters">
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <option value="Active"   <?= $status==='Active'  ?'selected':'' ?>>Active</option>
                <option value="Inactive" <?= $status==='Inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <?php if($zones): ?>
            <select name="zone_id" class="form-control">
                <option value="">All Zones</option>
                <?php foreach($zones as $z): ?><option value="<?= (int)$z['id'] ?>" <?= $zoneId===(int)$z['id']?'selected':'' ?>><?= h($z['zone_name']) ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if($hasExtraCols): ?>
            <select name="service" class="form-control">
                <option value="">All Services</option>
                <?php foreach($serviceOptions as $so): ?><option value="<?= h($so) ?>" <?= $service===$so?'selected':'' ?>><?= h($so) ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>" title="Joined from">
            <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>" title="Joined to">
            <select name="per_page" class="form-control">
                <?php foreach($pp as $s): ?>
                    <option value="<?= $s ?>" <?= $perPage===$s?'selected':'' ?>><?= $s ?> / page</option>
                <?php endforeach; ?>
            </select>
            </div>
            <div class="page-toolbar-actions">
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a class="btn btn-outline-primary page-toolbar-refresh" href="index.php">
                    <i class="fas fa-rotate-right"></i> Refresh
                </a>
            </div>
    </form>

    <!-- -- Members table -------------------------------------- -->
    <div class="card module-card">
        <div class="module-card-header">
            <strong><i class="fas fa-list"></i> Members List</strong>
            <div>
                <span class="badge badge-success"><?= number_format($activeMembers) ?> Active</span>
                <span class="badge badge-primary ml-2">
                    Showing <?= number_format($startRow) ?>-<?= number_format($endRow) ?>
                    of <?= number_format($totalRows) ?>
                </span>
            </div>
        </div>

        <div class="module-table-wrap">
            <table class="table module-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>Member No</th>
                        <th>Pledge No</th>
                        <th>Gender</th>
                        <th>Department</th>
                        <th>Zone</th>
                        <th>Service</th>
                        <th>Phone</th>
                        <th>Occupation</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($members&&$members->num_rows): $i=$offset+1; while($m=$members->fetch_assoc()): $id=(int)$m['id']; $full=trim((string)($m['full_name']??'')); ?>
                    <tr id="memberRow<?= $id ?>">
                        <td class="text-muted"><?= number_format($i++) ?></td>
                        <td>
                            <div class="record-info">
                                <div class="record-avatar"><?= h(strtoupper(substr($full!==''?$full:'M',0,1))) ?></div>
                                <div>
                                    <div class="record-name"><?= h($full?:'Unknown Member') ?></div>
                                    <div class="record-sub">
                                        <i class="fas fa-envelope"></i> <?= h($m['email']?:'No email') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge badge-primary"><i class="fas fa-id-card"></i> <?= h($m['member_no']??'-') ?></span></td>
                        <td><?= h(($m['pledge_no']??'')?:'-') ?></td>
                        <td><?= h($m['gender']??'-') ?></td>
                        <td><?= h($m['dept_name']??'-') ?></td>
                        <td><?= h($m['zone_name']??'-') ?></td>
                        <td><?= h(($m['service_attended']??'')?:'-') ?></td>
                        <td><?= h($m['phone']??'-') ?></td>
                        <td><?= h($m['occupation']??'-') ?></td>
                        <td>
                            <span class="badge <?= ($m['status']??'')==='Active'?'badge-success':'badge-secondary' ?>">
                                <?= h($m['status']??'-') ?>
                            </span>
                        </td>
                        <td><?= h(fmtDate($m['joined_date']??'')) ?></td>
                        <td class="text-right">
                            <div class="action-buttons">
                                <button type="button" class="btn btn-sm btn-info btn-icon js-member-view"
                                        data-id="<?= $id ?>" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-primary btn-icon js-member-edit"
                                        data-id="<?= $id ?>" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger btn-icon js-member-delete"
                                        data-id="<?= $id ?>"
                                        data-name="<?= h($full?:'this member') ?>" title="Delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="13">
                            <div class="empty-state">
                                <div class="icon"><i class="fas fa-users"></i></div>
                                <p>No members found.</p>
                                <button type="button" class="btn btn-primary js-open-add-members">
                                    <i class="fas fa-user-plus"></i> Add Members
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($totalRows>0): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-muted small">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></span>
            <div class="pagination-wrap" style="display:flex;gap:4px">
                <?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?>
                    <a class="btn btn-sm <?= $p===$page?'btn-primary':'btn-outline' ?>"
                       href="<?= h(pageUrl($p)) ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /.module-card -->


    <datalist id="memberServiceOptions">
        <?php foreach($serviceOptions as $so): ?><option value="<?= h($so) ?>"><?php endforeach; ?>
    </datalist>

    <!-- --------------------------------------------------------
         MODAL: Import CSV
    -------------------------------------------------------- -->
    <div class="member-modal-overlay" id="memberImportModal" aria-hidden="true">
        <div class="modal" role="dialog" style="max-width:560px">
            <div class="modal-header">
                <h4><i class="fas fa-file-upload"></i> Import Members from CSV</h4>
                <button type="button" class="close-btn js-close-member-modal">&times;</button>
            </div>
            <form id="memberImportForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="action" value="import_csv">
                <div class="modal-body">

                    <!-- Template download hint -->
                    <div class="info-box" style="margin:0 0 16px">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            Download the
                            <a href="process-member.php?action=csv_template" style="font-weight:600">
                                <i class="fas fa-download"></i> CSV template
                            </a>
                            to see the required column format before uploading.
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-file-csv"></i> Select CSV File <span class="req">*</span></label>
                        <input type="file" name="csv_file" id="csvFileInput"
                               accept=".csv,text/csv" class="form-control" required>
                        <small class="text-muted">
                            Required columns: <code>full_name</code>, <code>gender</code>.
                            Optional: <code>phone</code>, <code>email</code>, <code>occupation</code>,
                            <code>marital_status</code>, <code>date_of_birth</code>,
                            <code>joined_date</code>, <code>baptism_date</code>,
                            <code>address</code>, <code>notes</code>, <code>status</code>,
                            <code>pledge_no</code>, <code>service_attended</code>.
                        </small>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="skip_header" value="1" checked>
                            First row is a header (skip it)
                        </label>
                    </div>

                    <div id="importFormAlert" class="ajax-alert"></div>

                    <!-- Preview area -->
                    <div id="csvPreviewWrap" style="display:none;margin-top:12px">
                        <div class="form-section-title" style="margin:0 0 8px">
                            <i class="fas fa-table"></i> Preview (first 5 rows)
                        </div>
                        <div style="overflow-x:auto">
                            <table class="table module-table" id="csvPreviewTable" style="font-size:12px"></table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline js-close-member-modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="importSubmitBtn">
                        <i class="fas fa-file-import"></i>
                        <span id="importSubmitLabel">Import Members</span>
                    </button>
                </div>
            </form>
        </div>
    </div><!-- /#memberImportModal -->


    <!-- --------------------------------------------------------
         MODAL: Add Members (multi-row)
    -------------------------------------------------------- -->
    <div class="member-modal-overlay" id="memberAddModal" aria-hidden="true">
        <div class="modal modal-xl" role="dialog" style="max-width:min(1100px,98%)">
            <div class="modal-header">
                <h4 id="addModalTitle">
                    <i class="fas fa-user-plus"></i> Add Members
                    <span class="badge badge-primary ml-2" id="addRowCountBadge"
                          style="font-size:12px;vertical-align:middle">1 member</span>
                </h4>
                <button type="button" class="close-btn js-close-member-modal">&times;</button>
            </div>

            <form id="memberBulkForm" autocomplete="off">
                <input type="hidden" name="action" value="bulk_create">

                <div class="modal-body" style="padding:0">

                    <!-- Shared date / department fields applied to all rows -->
                    <div style="padding:16px 20px 12px;border-bottom:1px solid var(--border);background:#faf9fd">
                        <div class="form-section-title" style="margin:0 0 12px">
                            <i class="fas fa-sliders-h"></i> Defaults applied to all members below
                            <small class="text-muted" style="font-weight:400;margin-left:6px">(override per-row if needed)</small>
                        </div>
                        <div class="form-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));gap:12px">
                            <div class="form-group" style="margin:0">
                                <label>Joined Date</label>
                                <input type="date" id="bulkJoinedDate" class="form-control"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group" style="margin:0">
                                <label>Department</label>
                                <select id="bulkDeptId" class="form-control">
                                    <option value="">- None -</option>
                                    <?php foreach($departments as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0">
                                <label>Church Zone</label>
                                <select id="bulkZoneId" class="form-control">
                                    <option value="">- None -</option>
                                    <?php foreach($zones as $z): ?>
                                        <option value="<?= (int)$z['id'] ?>"><?= h($z['zone_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0">
                                <label>Service Attended</label>
                                <input type="text" id="bulkService" class="form-control" list="memberServiceOptions" placeholder="e.g. First Service">
                            </div>
                            <div class="form-group" style="margin:0">
                                <label>Status</label>
                                <select id="bulkStatus" class="form-control">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div style="margin-top:10px">
                            <button type="button" class="btn btn-sm btn-outline" id="btnApplyDefaults">
                                <i class="fas fa-magic"></i> Apply defaults to all rows
                            </button>
                        </div>
                    </div>

                    <!-- Column headers -->
                    <div id="memberRowsHeader" style="
                        display:grid;
                        grid-template-columns:24px 1.6fr 90px 100px 110px 110px 110px 120px 90px 36px;
                        gap:6px;align-items:center;
                        padding:8px 20px;
                        background:#f5f1ff;
                        border-bottom:2px solid var(--border);
                        font-size:10.5px;font-weight:700;color:var(--primary);
                        text-transform:uppercase;letter-spacing:.5px">
                        <div></div>
                        <div><i class="fas fa-user"></i> Full Name *</div>
                        <div><i class="fas fa-venus-mars"></i> Gender *</div>
                        <div><i class="fas fa-hand-holding-heart"></i> Pledge No</div>
                        <div><i class="fas fa-phone"></i> Phone</div>
                        <div><i class="fas fa-envelope"></i> Email</div>
                        <div><i class="fas fa-briefcase"></i> Occupation</div>
                        <div><i class="fas fa-church"></i> Service</div>
                        <div><i class="fas fa-toggle-on"></i> Status</div>
                        <div></div>
                    </div>

                    <!-- Scrollable rows container -->
                    <div id="memberRowsContainer" style="
                        max-height:420px;overflow-y:auto;padding:10px 20px">
                        <!-- rows injected by JS -->
                    </div>

                    <!-- Add row button -->
                    <div style="padding:10px 20px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center">
                        <button type="button" class="btn btn-outline btn-sm" id="btnAddMemberRow">
                            <i class="fas fa-plus"></i> Add Another Member
                        </button>
                        <span class="text-muted small" id="addRowsHint"></span>
                    </div>

                    <!-- Bulk alert -->
                    <div style="padding:0 20px">
                        <div id="bulkFormAlert" class="ajax-alert"></div>
                    </div>

                </div><!-- /.modal-body -->

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline js-close-member-modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="bulkSubmitBtn">
                        <i class="fas fa-save"></i>
                        <span id="bulkSubmitLabel">Save 1 Member</span>
                    </button>
                </div>
            </form>
        </div>
    </div><!-- /#memberAddModal -->


    <!-- --------------------------------------------------------
         MODAL: Edit Single Member
    -------------------------------------------------------- -->
    <div class="member-modal-overlay" id="memberFormModal" aria-hidden="true">
        <div class="modal modal-lg" role="dialog">
            <div class="modal-header">
                <h4 id="memberFormModalTitle">
                    <i class="fas fa-edit"></i> Edit Member
                </h4>
                <button type="button" class="close-btn js-close-member-modal">&times;</button>
            </div>
            <form id="memberForm" data-form-tabs>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="0">
                <div class="modal-body">
                    <div class="member-form-grid">
                        <div class="form-group" data-tab-start="Personal"><label>Member No</label><input class="form-control" name="member_no" placeholder="Auto-generated if blank"></div>
                        <div class="form-group"><label>Pledge No</label><input class="form-control" name="pledge_no" maxlength="50" placeholder="e.g. P-001"></div>
                        <div class="form-group full-span"><label>Full Name <span class="req">*</span></label><input class="form-control" name="full_name" required></div>
                        <div class="form-group"><label>Gender <span class="req">*</span></label><select class="form-control" name="gender" required><option value="">Select</option><option>Male</option><option>Female</option></select></div>
                        <div class="form-group"><label>Date of Birth</label><input type="date" class="form-control" name="date_of_birth"></div>
                        <div class="form-group"><label>Marital Status</label><select class="form-control" name="marital_status"><option>Single</option><option>Married</option><option>Widowed</option><option>Divorced</option></select></div>
                        <div class="form-group"><label>Occupation</label><input class="form-control" name="occupation"></div>
                        <div class="form-group" data-tab-start="Contact"><label>Phone</label><input class="form-control" name="phone"></div>
                        <div class="form-group"><label>Email</label><input type="email" class="form-control" name="email"></div>
                        <div class="form-group" data-tab-start="Church Details"><label>Department</label><select class="form-control" name="dept_id"><option value="">None</option><?php foreach($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Role in Department</label><input class="form-control" name="dept_role" value="Member"></div>
                        <div class="form-group"><label>Joined Date</label><input type="date" class="form-control" name="joined_date" value="<?= h(date('Y-m-d')) ?>"></div>
                        <div class="form-group"><label>Baptism Date</label><input type="date" class="form-control" name="baptism_date"></div>
                        <div class="form-group"><label>Church Zone</label><select class="form-control" name="church_zone_id"><option value="">None</option><?php foreach($zones as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['zone_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Service Attended</label><input class="form-control" name="service_attended" list="memberServiceOptions" maxlength="120" placeholder="e.g. First Service"></div>
                        <div class="form-group"><label>Status</label><select class="form-control" name="status"><option>Active</option><option>Inactive</option></select></div>
                    </div>
                    <div class="form-group" data-tab="Contact"><label>Address</label><textarea class="form-control" name="address" rows="3"></textarea></div>
                    <div class="form-group" data-tab="Church Details"><label>Notes</label><textarea class="form-control" name="notes" rows="3"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline js-close-member-modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="btn btn-primary" id="memberSubmitBtn"><i class="fas fa-save"></i> Update Member</button>
                </div>
            </form>
        </div>
    </div><!-- /#memberFormModal -->


    <!-- --------------------------------------------------------
         MODAL: View Member
    -------------------------------------------------------- -->
    <div class="member-modal-overlay" id="memberViewModal" aria-hidden="true">
        <div class="modal modal-lg" role="dialog">
            <div class="modal-header">
                <h4><i class="fas fa-user-circle"></i> Member Details</h4>
                <button type="button" class="close-btn js-close-member-modal">&times;</button>
            </div>
            <div class="modal-body" id="memberViewBody">
                <div class="text-center text-muted" style="padding:28px">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline js-close-member-modal">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div><!-- /#memberViewModal -->



    <!-- --------------------------------------------------------
         MODAL: Public Registration Link + QR
    -------------------------------------------------------- -->
    <div class="member-modal-overlay" id="memberRegistrationLinkModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="registrationLinkTitle" style="max-width:680px">
            <div class="modal-header">
                <h4 id="registrationLinkTitle"><i class="fas fa-qrcode"></i> Member Self-Registration Link</h4>
                <button type="button" class="close-btn js-close-member-modal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="info-box" style="margin:0 0 16px">
                    <i class="fas fa-circle-info"></i>
                    <div>Generate one external link that you can share by WhatsApp, email, SMS, or any other channel. A new member can open it or scan the QR code and register themselves.</div>
                </div>

                <div class="form-grid" style="grid-template-columns:2fr 1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Link Label</label>
                        <input type="text" class="form-control" id="registrationLinkLabel" value="Church member self-registration" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label>Valid For</label>
                        <select class="form-control" id="registrationLinkDays">
                            <option value="7">7 days</option>
                            <option value="30" selected>30 days</option>
                            <option value="90">90 days</option>
                            <option value="365">1 year</option>
                            <option value="0">No expiry</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Maximum Uses</label>
                        <input type="number" min="0" max="100000" value="0" class="form-control" id="registrationLinkMaxUses">
                        <small class="text-muted">0 = unlimited</small>
                    </div>
                </div>

                <div id="registrationLinkAlert" class="ajax-alert"></div>

                <div style="display:flex;justify-content:flex-end;margin:8px 0 18px">
                    <button type="button" class="btn btn-primary" id="generateRegistrationLinkBtn">
                        <i class="fas fa-wand-magic-sparkles"></i> Generate Link &amp; QR
                    </button>
                </div>

                <div id="registrationLinkResult" style="display:none;border-top:1px solid var(--border,#e5e7eb);padding-top:18px">
                    <div class="form-group">
                        <label>External Registration Link</label>
                        <div style="display:flex;gap:8px;align-items:stretch">
                            <input type="text" class="form-control" id="registrationPublicUrl" readonly style="flex:1">
                            <button type="button" class="btn btn-outline" id="copyRegistrationLinkBtn" title="Copy link"><i class="fas fa-copy"></i></button>
                        </div>
                        <small class="text-muted" id="registrationLinkMeta"></small>
                    </div>

                    <div style="display:grid;grid-template-columns:minmax(220px,280px) 1fr;gap:20px;align-items:center;margin-top:18px">
                        <div style="background:#fff;border:1px solid var(--border,#e5e7eb);border-radius:16px;padding:16px;text-align:center">
                            <div id="memberRegistrationQr" style="display:flex;justify-content:center;align-items:center;min-height:220px"></div>
                            <small class="text-muted">Scan to register</small>
                        </div>
                        <div>
                            <h5 style="margin:0 0 8px">Share this registration invitation</h5>
                            <p class="text-muted" style="margin:0 0 14px">Anyone with the active link can complete the public member registration form until the link expires or reaches its use limit.</p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="button" class="btn btn-primary" id="shareRegistrationLinkBtn"><i class="fas fa-share-nodes"></i> Share</button>
                                <button type="button" class="btn btn-outline" id="downloadRegistrationQrBtn"><i class="fas fa-download"></i> Download QR</button>
                                <a class="btn btn-outline" id="openRegistrationLinkBtn" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open Form</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline js-close-member-modal">Close</button>
            </div>
        </div>
    </div><!-- /#memberRegistrationLinkModal -->

    <!-- --------------------------------------------------------
         MODAL: Delete Member
    -------------------------------------------------------- -->
    <div class="member-modal-overlay" id="memberDeleteModal" aria-hidden="true">
        <div class="modal" role="dialog">
            <div class="modal-header">
                <h4><i class="fas fa-trash-alt"></i> Delete Member</h4>
                <button type="button" class="close-btn js-close-member-modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="deleteMemberId">
                <div class="info-box danger" style="margin:0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        Mark <strong id="deleteMemberName">this member</strong> as Inactive?
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline js-close-member-modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteMemberBtn">
                    <i class="fas fa-trash-alt"></i> Deactivate
                </button>
            </div>
        </div>
    </div><!-- /#memberDeleteModal -->

</div><!-- /.content-area -->
</div><!-- /.main-content -->


<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
window.MEMBER_MODULE = {
    processUrl : 'process-member.php',
    today      : '<?= date('Y-m-d') ?>',
    depts      : <?= $deptJson ?>,
    zones      : <?= $zoneJson ?>
};

(function () {
    'use strict';

    const cfg        = window.MEMBER_MODULE;
    const processUrl = cfg.processUrl;
    const alertArea  = document.getElementById('memberAlertArea');

    /* -- DOM refs -- */
    const addModal    = document.getElementById('memberAddModal');
    const editModal   = document.getElementById('memberFormModal');
    const viewModal   = document.getElementById('memberViewModal');
    const deleteModal = document.getElementById('memberDeleteModal');
    const importModal = document.getElementById('memberImportModal');
    const registrationLinkModal = document.getElementById('memberRegistrationLinkModal');
    const editForm    = document.getElementById('memberForm');
    const bulkForm    = document.getElementById('memberBulkForm');
    const importForm  = document.getElementById('memberImportForm');

    /* -- helpers -- */
    const esc = v => String(v==null?'':v).replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

    function alertMsg(msg, type='success') {
        alertArea.innerHTML = `<div class="alert alert-${type==='success'?'success':'danger'}">${esc(msg)}</div>`;
        alertArea.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
    function showBulkAlert(msg, type='success') {
        const el = document.getElementById('bulkFormAlert');
        if (!el) return;
        el.className   = 'ajax-alert ' + (type==='error'?'error':'success');
        el.textContent = msg;
    }
    function clearBulkAlert() {
        const el = document.getElementById('bulkFormAlert');
        if (el) { el.className='ajax-alert'; el.textContent=''; }
    }
    function showImportAlert(msg, type='success') {
        const el = document.getElementById('importFormAlert');
        if (!el) return;
        el.className   = 'ajax-alert ' + (type==='error'?'error':'success');
        el.textContent = msg;
    }
    function clearImportAlert() {
        const el = document.getElementById('importFormAlert');
        if (el) { el.className='ajax-alert'; el.textContent=''; }
    }

    /* -- modal helpers -- */
    function openM(m) {
        if (!m) return;
        m.classList.add('show');
        m.setAttribute('aria-hidden','false');
        document.body.classList.add('cms-modal-open');
        const b = m.querySelector('.modal-body');
        if (b) b.scrollTop = 0;
    }
    function closeM(m) {
        if (!m) return;
        m.classList.remove('show');
        m.setAttribute('aria-hidden','true');
        if (!document.querySelector('.member-modal-overlay.show'))
            document.body.classList.remove('cms-modal-open');
    }

    /* -- fetch helper -- */
    async function post(action, data) {
        const fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            fd.append('action', action);
            Object.keys(data||{}).forEach(k => fd.append(k, data[k]));
        } else if (!fd.get('action')) fd.append('action', action);
        const r    = await fetch(processUrl, { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, credentials:'same-origin' });
        let   text = (await r.text()).trim();
        if (!text) throw new Error('Empty server response.');
        const s = text.indexOf('{'), e = text.lastIndexOf('}');
        if (s > 0 && e > s) text = text.substring(s, e+1);
        try { return JSON.parse(text); }
        catch(err) { throw new Error('Invalid server response: ' + text.substring(0,300)); }
    }

    /* --------------------------------------------------------
       IMPORT CSV  -  preview + submit
    -------------------------------------------------------- */
    document.addEventListener('click', e => {
        if (!e.target.closest('.js-open-import-modal')) return;
        e.preventDefault();
        if (importForm) importForm.reset();
        clearImportAlert();
        const pw = document.getElementById('csvPreviewWrap');
        if (pw) pw.style.display = 'none';
        openM(importModal);
    });

    /* Live CSV preview when file is selected */
    document.getElementById('csvFileInput')?.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(ev) {
            const lines = ev.target.result.split(/\r?\n/).filter(l => l.trim());
            const preview = lines.slice(0, 6); // header + 5 rows
            if (!preview.length) return;

            const pw = document.getElementById('csvPreviewWrap');
            const pt = document.getElementById('csvPreviewTable');
            if (!pw || !pt) return;

            let html = '<thead><tr>';
            const headers = parseCSVRow(preview[0]);
            headers.forEach(h => { html += `<th>${esc(h)}</th>`; });
            html += '</tr></thead><tbody>';
            preview.slice(1, 6).forEach(line => {
                const cols = parseCSVRow(line);
                html += '<tr>' + cols.map(c => `<td>${esc(c)}</td>`).join('') + '</tr>';
            });
            html += '</tbody>';
            pt.innerHTML = html;
            pw.style.display = '';
        };
        reader.readAsText(file);
    });

    function parseCSVRow(line) {
        const result = [];
        let cur = '', inQuote = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                if (inQuote && line[i+1] === '"') { cur += '"'; i++; }
                else inQuote = !inQuote;
            } else if (ch === ',' && !inQuote) {
                result.push(cur); cur = '';
            } else {
                cur += ch;
            }
        }
        result.push(cur);
        return result;
    }

    importForm?.addEventListener('submit', async function(e) {
        e.preventDefault();
        clearImportAlert();
        const file = document.getElementById('csvFileInput')?.files[0];
        if (!file) { showImportAlert('Please select a CSV file.', 'error'); return; }

        const btn     = document.getElementById('importSubmitBtn');
        const oldHTML = btn?.innerHTML;
        if (btn) { btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Importing...'; }

        try {
            const fd = new FormData(importForm);
            const res = await post('import_csv', fd);
            if (!res.success) throw new Error(res.message || 'Import failed.');
            closeM(importModal);
            alertMsg(res.message || 'Members imported successfully.', 'success');
            setTimeout(() => location.reload(), 800);
        } catch(err) {
            showImportAlert(err.message, 'error');
        } finally {
            if (btn) { btn.disabled=false; btn.innerHTML=oldHTML; }
        }
    });

    /* --------------------------------------------------------
       MULTI-MEMBER ADD  -  row factory
    -------------------------------------------------------- */
    let rowCounter = 0;

    function buildDeptOptions(selected='') {
        return '<option value="">- None -</option>' +
            cfg.depts.map(d =>
                `<option value="${d.id}" ${selected==d.id?'selected':''}>${esc(d.name)}</option>`
            ).join('');
    }
    function buildZoneOptions(selected='') {
        return '<option value="">- None -</option>' +
            cfg.zones.map(z =>
                `<option value="${z.id}" ${selected==z.id?'selected':''}>${esc(z.name)}</option>`
            ).join('');
    }

    function createRow(n=1) {
        const idx = ++rowCounter;
        const row = document.createElement('div');
        row.className  = 'member-bulk-row';
        row.dataset.idx = idx;
        row.style.cssText = `
            display:grid;
            grid-template-columns:24px 1.6fr 90px 100px 110px 110px 110px 120px 90px 36px;
            gap:6px;align-items:start;
            padding:8px 0;
            border-bottom:1px solid #f0ecfa;
        `;
        row.innerHTML = `
            <div style="padding-top:8px;color:var(--text-muted);font-size:11px;text-align:center;font-weight:700">${n}</div>
            <div class="form-group" style="margin:0">
                <input type="text" name="members[${idx}][full_name]"
                       class="form-control form-control-sm" placeholder="Full name" required>
            </div>
            <div class="form-group" style="margin:0">
                <select name="members[${idx}][gender]" class="form-control form-control-sm" required>
                    <option value="">-</option>
                    <option>Male</option>
                    <option>Female</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <input type="text" name="members[${idx}][pledge_no]"
                       class="form-control form-control-sm" placeholder="Pledge no">
            </div>
            <div class="form-group" style="margin:0">
                <input type="text" name="members[${idx}][phone]"
                       class="form-control form-control-sm" placeholder="Phone">
            </div>
            <div class="form-group" style="margin:0">
                <input type="email" name="members[${idx}][email]"
                       class="form-control form-control-sm" placeholder="Email">
            </div>
            <div class="form-group" style="margin:0">
                <input type="text" name="members[${idx}][occupation]"
                       class="form-control form-control-sm" placeholder="Occupation">
            </div>
            <div class="form-group" style="margin:0">
                <input type="text" name="members[${idx}][service_attended]" list="memberServiceOptions"
                       class="form-control form-control-sm" placeholder="Service"
                       value="${esc(document.getElementById('bulkService')?.value || '')}">
            </div>
            <div class="form-group" style="margin:0">
                <select name="members[${idx}][status]" class="form-control form-control-sm">
                    <option>Active</option>
                    <option>Inactive</option>
                </select>
            </div>
            <div style="padding-top:4px">
                <button type="button" class="btn btn-danger btn-icon btn-sm remove-member-row"
                        title="Remove row" style="display:none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        return row;
    }

    function getContainer()   { return document.getElementById('memberRowsContainer'); }
    function getAllRows()      { return Array.from(getContainer()?.querySelectorAll('.member-bulk-row') ?? []); }

    function refreshRowNumbers() {
        const rows = getAllRows();
        rows.forEach((row, i) => {
            const numEl = row.querySelector('div:first-child');
            if (numEl) numEl.textContent = i + 1;
            const rmBtn = row.querySelector('.remove-member-row');
            if (rmBtn) rmBtn.style.display = rows.length > 1 ? '' : 'none';
        });
        const n = rows.length;
        const badge = document.getElementById('addRowCountBadge');
        if (badge) badge.textContent = n === 1 ? '1 member' : n + ' members';
        const lbl = document.getElementById('bulkSubmitLabel');
        if (lbl) lbl.textContent = n === 1 ? 'Save 1 Member' : `Save ${n} Members`;
        const hint = document.getElementById('addRowsHint');
        if (hint) hint.textContent = n > 1 ? `${n} members will be added` : '';
    }

    function initBulkForm() {
        const container = getContainer();
        if (!container) return;
        container.innerHTML = '';
        rowCounter = 0;
        container.appendChild(createRow(1));
        clearBulkAlert();
        refreshRowNumbers();
    }

    /* "Apply defaults to all rows" */
    document.getElementById('btnApplyDefaults')?.addEventListener('click', () => {
        const joinedDate = document.getElementById('bulkJoinedDate')?.value || cfg.today;
        const deptId     = document.getElementById('bulkDeptId')?.value     || '';
        const zoneId     = document.getElementById('bulkZoneId')?.value     || '';
        const status     = document.getElementById('bulkStatus')?.value     || 'Active';
        const service    = document.getElementById('bulkService')?.value.trim() || '';

        getAllRows().forEach(row => {
            const sel = (name) => row.querySelector(`[name$="[${name}]"]`);
            if (sel('status')) sel('status').value = status;
            if (service && sel('service_attended')) sel('service_attended').value = service;
        });
    });

    /* Add row button */
    document.getElementById('btnAddMemberRow')?.addEventListener('click', () => {
        const container = getContainer();
        if (!container) return;
        const n = getAllRows().length + 1;
        container.appendChild(createRow(n));
        refreshRowNumbers();
        container.scrollTop = container.scrollHeight;
    });

    /* Remove row (delegated) */
    document.addEventListener('click', e => {
        const rm = e.target.closest('.remove-member-row');
        if (!rm) return;
        rm.closest('.member-bulk-row')?.remove();
        refreshRowNumbers();
    });

    /* -- open Add Members modal -- */
    document.addEventListener('click', e => {
        if (!e.target.closest('.js-open-add-members')) return;
        e.preventDefault();
        initBulkForm();
        openM(addModal);
    });

    /* -- bulk form submit -- */
    bulkForm?.addEventListener('submit', async function(e) {
        e.preventDefault();
        clearBulkAlert();

        const rows = getAllRows();
        if (!rows.length) { showBulkAlert('Add at least one member row.','error'); return; }

        let valid = true;
        rows.forEach((row, i) => {
            const fn = row.querySelector('[name$="[full_name]"]')?.value.trim();
            const gn = row.querySelector('[name$="[gender]"]')?.value;
            if (!fn || !gn) {
                row.style.outline = '2px solid var(--danger)';
                valid = false;
            } else {
                row.style.outline = '';
            }
        });
        if (!valid) { showBulkAlert('Please fill in Full Name and Gender for every row.','error'); return; }

        const sharedJoined = document.getElementById('bulkJoinedDate')?.value || cfg.today;
        const sharedDept   = document.getElementById('bulkDeptId')?.value     || '';
        const sharedZone   = document.getElementById('bulkZoneId')?.value     || '';
        const sharedStatus = document.getElementById('bulkStatus')?.value     || 'Active';
        const sharedService= document.getElementById('bulkService')?.value.trim() || '';

        const fd = new FormData();
        fd.append('action', 'bulk_create');

        rows.forEach((row, i) => {
            const g = name => row.querySelector(`[name$="[${name}]"]`)?.value.trim() || '';
            fd.append(`members[${i}][full_name]`,   g('full_name'));
            fd.append(`members[${i}][gender]`,      g('gender'));
            fd.append(`members[${i}][pledge_no]`,   g('pledge_no'));
            fd.append(`members[${i}][service_attended]`, g('service_attended') || sharedService);
            fd.append(`members[${i}][phone]`,       g('phone'));
            fd.append(`members[${i}][email]`,       g('email'));
            fd.append(`members[${i}][occupation]`,  g('occupation'));
            fd.append(`members[${i}][status]`,      g('status') || sharedStatus);
            fd.append(`members[${i}][joined_date]`,    sharedJoined);
            fd.append(`members[${i}][dept_id]`,         sharedDept);
            fd.append(`members[${i}][church_zone_id]`,  sharedZone);
        });

        const btn     = document.getElementById('bulkSubmitBtn');
        const oldHTML = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...'; }

        try {
            const res = await post('bulk_create', fd);
            if (!res.success) throw new Error(res.message || 'Save failed.');
            closeM(addModal);
            alertMsg(res.message || `${rows.length} member(s) added successfully.`, 'success');
            setTimeout(() => location.reload(), 700);
        } catch(err) {
            showBulkAlert(err.message, 'error');
        } finally {
            if (btn) { btn.disabled=false; btn.innerHTML=oldHTML; }
        }
    });

    /* --------------------------------------------------------
       EDIT (single member)
    -------------------------------------------------------- */
    function setVal(n, v) {
        const f = editForm?.querySelector(`[name="${n}"]`);
        if (f) f.value = v==null ? '' : v;
    }

    async function openEdit(id) {
        if (!editForm) return;
        editForm.reset();
        setVal('action','update'); setVal('id','0');
        document.getElementById('memberFormModalTitle').innerHTML =
            '<i class="fas fa-edit"></i> Edit Member';
        document.getElementById('memberSubmitBtn').innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Loading...';
        openM(editModal);
        try {
            const res = await post('get', {id});
            if (!res.success) throw new Error(res.message || 'Failed to load member.');
            const m = res.member || {};
            ['member_no','pledge_no','service_attended','full_name','gender','date_of_birth','phone','email','address',
             'marital_status','occupation','dept_id','dept_role','joined_date','baptism_date',
             'church_zone_id','status','notes'].forEach(k => setVal(k, m[k]));
            setVal('action','update'); setVal('id', m.id || id);
            document.getElementById('memberSubmitBtn').innerHTML =
                '<i class="fas fa-save"></i> Update Member';
        } catch(err) {
            closeM(editModal);
            alertMsg(err.message, 'danger');
        }
    }

    editForm?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn     = document.getElementById('memberSubmitBtn');
        const oldHTML = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        try {
            const fd  = new FormData(editForm);
            const res = await post(fd.get('action') || 'update', fd);
            if (!res.success) throw new Error(res.message || 'Failed to save member.');
            alertMsg(res.message || 'Saved successfully.', 'success');
            closeM(editModal);
            setTimeout(() => location.reload(), 650);
        } catch(err) {
            alertMsg(err.message, 'danger');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = oldHTML;
        }
    });

    /* --------------------------------------------------------
       VIEW
    -------------------------------------------------------- */
    function row(l, v) {
        return `<div class="member-view-row">
            <div class="member-view-label">${esc(l)}</div>
            <div class="member-view-value">${esc(v||'-')}</div>
        </div>`;
    }

    async function openView(id) {
        const b = document.getElementById('memberViewBody');
        b.innerHTML = '<div class="text-center text-muted" style="padding:28px"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
        openM(viewModal);
        try {
            const res = await post('view', {id});
            if (!res.success) throw new Error(res.message || 'Failed to load member.');
            const m    = res.member || {};
            const full = (m.full_name||'').trim() || 'Unknown Member';
            const init = (m.full_name||'M').trim().substring(0,1).toUpperCase() || 'M';
            b.innerHTML = `
            <div class="member-view-profile">
                <div class="member-view-card text-center">
                    <div class="member-view-avatar">${esc(init)}</div>
                    <h3>${esc(full)}</h3>
                    <div class="text-muted">${esc(m.member_no||'No member number')}</div>
                    <span class="badge ${m.status==='Active'?'badge-success':'badge-secondary'}">
                        ${esc(m.status||'-')}
                    </span>
                </div>
                <div class="member-view-card">
                    ${row('Pledge No',m.pledge_no)}
                    ${row('Service Attended',m.service_attended)}
                    ${row('Gender',m.gender)}
                    ${row('Date of Birth',m.date_of_birth_formatted)}
                    ${row('Phone',m.phone)}
                    ${row('Email',m.email)}
                    ${row('Department',m.dept_name||m.department)}
                    ${row('Church Zone',m.zone_name)}
                    ${row('Role',m.dept_role)}
                    ${row('Occupation',m.occupation)}
                    ${row('Marital Status',m.marital_status)}
                    ${row('Joined',m.joined_date_formatted)}
                    ${row('Baptism Date',m.baptism_date_formatted)}
                    ${row('Address',m.address)}
                    ${row('Notes',m.notes)}
                </div>
            </div>`;
        } catch(err) {
            b.innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`;
        }
    }

    /* --------------------------------------------------------
       DELETE
    -------------------------------------------------------- */
    const confirmDeleteBtn = document.getElementById('confirmDeleteMemberBtn');
    confirmDeleteBtn?.addEventListener('click', async function() {
        const id      = document.getElementById('deleteMemberId').value || 0;
        const oldHTML = confirmDeleteBtn.innerHTML;
        confirmDeleteBtn.disabled  = true;
        confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deactivating...';
        try {
            const res = await post('delete', {id});
            if (!res.success) throw new Error(res.message || 'Failed to delete member.');
            alertMsg(res.message, 'success');
            closeM(deleteModal);
            setTimeout(() => location.reload(), 650);
        } catch(err) {
            alertMsg(err.message, 'danger');
        } finally {
            confirmDeleteBtn.disabled  = false;
            confirmDeleteBtn.innerHTML = oldHTML;
        }
    });

    /* --------------------------------------------------------
       GLOBAL CLICK ROUTING
    -------------------------------------------------------- */

    /* -- public member self-registration link / QR -- */
    const registrationLinkAlert = document.getElementById('registrationLinkAlert');
    const registrationResult = document.getElementById('registrationLinkResult');
    const registrationUrlInput = document.getElementById('registrationPublicUrl');
    const registrationQr = document.getElementById('memberRegistrationQr');
    const registrationMeta = document.getElementById('registrationLinkMeta');
    const openRegistrationLinkBtn = document.getElementById('openRegistrationLinkBtn');

    function registrationAlert(msg, type='success') {
        if (!registrationLinkAlert) return;
        registrationLinkAlert.className = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        registrationLinkAlert.textContent = msg;
    }

    function renderRegistrationQr(url) {
        if (!registrationQr) return;
        registrationQr.innerHTML = '';
        if (typeof QRCode === 'undefined') {
            registrationQr.innerHTML = '<div class="text-muted">QR library could not load. The registration link can still be copied and shared.</div>';
            return;
        }
        new QRCode(registrationQr, {
            text: url,
            width: 220,
            height: 220,
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    const openRegistrationBtn = document.querySelector('.js-open-registration-link');
    if (openRegistrationBtn) openRegistrationBtn.addEventListener('click', function() {
        if (registrationLinkAlert) { registrationLinkAlert.className='ajax-alert'; registrationLinkAlert.textContent=''; }
        openM(registrationLinkModal);
    });

    const generateRegistrationBtn = document.getElementById('generateRegistrationLinkBtn');
    if (generateRegistrationBtn) generateRegistrationBtn.addEventListener('click', async function() {
        const original = generateRegistrationBtn.innerHTML;
        generateRegistrationBtn.disabled = true;
        generateRegistrationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        try {
            const fd = new FormData();
            fd.append('action','generate_registration_link');
            fd.append('label', document.getElementById('registrationLinkLabel')?.value || 'Member self-registration');
            fd.append('valid_days', document.getElementById('registrationLinkDays')?.value || '30');
            fd.append('max_uses', document.getElementById('registrationLinkMaxUses')?.value || '0');
            const res = await post('generate_registration_link', fd);
            if (!res.success) throw new Error(res.message || 'Unable to generate registration link.');
            const url = String(res.url || '');
            registrationUrlInput.value = url;
            if (openRegistrationLinkBtn) openRegistrationLinkBtn.href = url;
            if (registrationMeta) {
                const limit = Number(res.max_uses || 0) > 0 ? `${res.max_uses} registration(s)` : 'Unlimited registrations';
                const expiry = res.expires_at ? `Expires ${res.expires_at}` : 'No expiry';
                registrationMeta.textContent = `${expiry} • ${limit} • Generating a new link revokes the previous link.`;
            }
            renderRegistrationQr(url);
            if (registrationResult) registrationResult.style.display = 'block';
            registrationAlert('Registration link and QR code are ready to share.');
        } catch (err) {
            registrationAlert(err.message || 'Unable to generate registration link.', 'error');
        } finally {
            generateRegistrationBtn.disabled = false;
            generateRegistrationBtn.innerHTML = original;
        }
    });

    const copyRegistrationBtn = document.getElementById('copyRegistrationLinkBtn');
    if (copyRegistrationBtn) copyRegistrationBtn.addEventListener('click', async function() {
        const url = registrationUrlInput?.value || '';
        if (!url) return registrationAlert('Generate a registration link first.', 'error');
        try {
            await navigator.clipboard.writeText(url);
            registrationAlert('Registration link copied to clipboard.');
        } catch (e) {
            registrationUrlInput.focus(); registrationUrlInput.select();
            document.execCommand('copy');
            registrationAlert('Registration link copied to clipboard.');
        }
    });

    const shareRegistrationBtn = document.getElementById('shareRegistrationLinkBtn');
    if (shareRegistrationBtn) shareRegistrationBtn.addEventListener('click', async function() {
        const url = registrationUrlInput?.value || '';
        if (!url) return registrationAlert('Generate a registration link first.', 'error');
        const shareData = { title:'Church Member Registration', text:'Use this link to register yourself as a church member.', url };
        if (navigator.share) {
            try { await navigator.share(shareData); return; } catch (e) { if (e && e.name === 'AbortError') return; }
        }
        try { await navigator.clipboard.writeText(url); registrationAlert('Sharing is not available on this browser, so the link was copied instead.'); }
        catch (e) { registrationAlert('Copy the registration link above and share it manually.', 'error'); }
    });

    const downloadQrBtn = document.getElementById('downloadRegistrationQrBtn');
    if (downloadQrBtn) downloadQrBtn.addEventListener('click', function() {
        const canvas = registrationQr?.querySelector('canvas');
        const image = registrationQr?.querySelector('img');
        let dataUrl = '';
        if (canvas) dataUrl = canvas.toDataURL('image/png');
        else if (image) dataUrl = image.src;
        if (!dataUrl) return registrationAlert('Generate the QR code first.', 'error');
        const a = document.createElement('a');
        a.href = dataUrl; a.download = 'member-registration-qr.png';
        document.body.appendChild(a); a.click(); a.remove();
    });

    document.addEventListener('click', function(e) {
        const closeBtn = e.target.closest('.js-close-member-modal');
        if (closeBtn) { e.preventDefault(); closeM(closeBtn.closest('.member-modal-overlay')); return; }
        if (e.target.classList.contains('member-modal-overlay')) { closeM(e.target); return; }
        const vBtn = e.target.closest('.js-member-view');
        if (vBtn) { e.preventDefault(); openView(vBtn.dataset.id); return; }
        const eBtn = e.target.closest('.js-member-edit');
        if (eBtn) { e.preventDefault(); openEdit(eBtn.dataset.id); return; }
        const dBtn = e.target.closest('.js-member-delete');
        if (dBtn) {
            e.preventDefault();
            document.getElementById('deleteMemberId').value = dBtn.dataset.id || 0;
            document.getElementById('deleteMemberName').textContent = dBtn.dataset.name || 'this member';
            openM(deleteModal);
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape')
            document.querySelectorAll('.member-modal-overlay.show').forEach(closeM);
    });

})();
</script>

<?php if(isset($stmt)) $stmt->close(); require_once '../includes/footer.php'; ?>