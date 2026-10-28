<?php
declare(strict_types=1);

function mp_h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mp_set_error(string $message): void { $GLOBALS['mp_last_error']=$message; error_log('[Member Portal] '.$message); }
function mp_last_error(): string { return (string)($GLOBALS['mp_last_error'] ?? ''); }
function mp_valid_identifier(string $identifier): bool { return preg_match('/^[A-Za-z0-9_]+$/',$identifier)===1; }
function mp_bind_params(mysqli_stmt $statement,string $types,array &$params): bool {
    if($types==='') return true;
    if(strlen($types)!==count($params)){mp_set_error('Parameter count does not match bind types.');return false;}
    $args=[$types]; foreach($params as $k=>&$v){$args[]=&$v;} unset($v);
    try{return (bool)call_user_func_array([$statement,'bind_param'],$args);}catch(Throwable $e){mp_set_error($e->getMessage());return false;}
}
function mp_rows(mysqli $conn,string $sql,string $types='',array $params=[]): array {
    $sql=trim($sql); if($sql==='') return [];
    if(($types===''&&$params!==[])||($types!==''&&strlen($types)!==count($params))){mp_set_error('Invalid query parameters.');return [];}
    $st=null;
    try{
        $st=$conn->prepare($sql); if(!$st instanceof mysqli_stmt){mp_set_error($conn->error.' | '.$sql);return [];}
        if($types!==''&&!mp_bind_params($st,$types,$params)){ $st->close(); return []; }
        if(!$st->execute()){mp_set_error($st->error.' | '.$sql);$st->close();return [];}
        $r=$st->get_result(); $out=[]; while($r instanceof mysqli_result && $row=$r->fetch_assoc()){$out[]=$row;}
        if($r instanceof mysqli_result)$r->free(); $st->close(); return $out;
    }catch(Throwable $e){ if($st instanceof mysqli_stmt)$st->close(); mp_set_error($e->getMessage().' | '.$sql); return []; }
}
function mp_row(mysqli $conn,string $sql,string $types='',array $params=[]): ?array { return mp_rows($conn,$sql,$types,$params)[0]??null; }
function mp_value(mysqli $conn,string $sql,string $types='',array $params=[],mixed $default=null): mixed { $r=mp_row($conn,$sql,$types,$params); return $r?array_values($r)[0]:$default; }
function mp_table(mysqli $conn,string $table): bool {
    if(!mp_valid_identifier($table))return false;
    try{$st=$conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$st->bind_param('s',$table);$st->execute();$v=(int)($st->get_result()->fetch_row()[0]??0);$st->close();return $v>0;}catch(Throwable $e){mp_set_error($e->getMessage());return false;}
}
function mp_cols(mysqli $conn,string $table): array {
    if(!mp_valid_identifier($table)||!mp_table($conn,$table))return [];
    try{$r=$conn->query("SHOW COLUMNS FROM `{$table}`");$o=[];while($r&&$x=$r->fetch_assoc())$o[]=(string)$x['Field'];return $o;}catch(Throwable $e){mp_set_error($e->getMessage());return [];}
}
function mp_has_col(mysqli $conn,string $table,string $column): bool { return mp_valid_identifier($column)&&in_array($column,mp_cols($conn,$table),true); }
function mp_money(mixed $v,int $decimals=2): string { return number_format(is_numeric($v)?(float)$v:0,$decimals); }
function mp_date(mixed $v,string $format='d M Y'): string { $s=trim((string)($v??''));if($s==='')return '-';$t=strtotime($s);return $t===false?$s:date($format,$t); }
function mp_tabs(array $tabs,string $active): void { echo '<div class="member-tabs">';foreach($tabs as $k=>$label){$q=$_GET;$q['tab']=$k;$q['page']=1;echo '<a class="'.($k===$active?'active':'').'" href="?'.mp_h(http_build_query($q)).'">'.mp_h($label).'</a>';}echo '</div>'; }
function mp_query_string(array $changes=[]): string { $q=array_merge($_GET,$changes);foreach($q as $k=>$v){if($v===null||$v==='')unset($q[$k]);}return http_build_query($q); }
function mp_filter_rows(array $rows,string $search): array {
    $search=mb_strtolower(trim($search)); if($search==='')return $rows;
    return array_values(array_filter($rows,static function(array $row)use($search):bool{foreach($row as $value){if(str_contains(mb_strtolower((string)$value),$search))return true;}return false;}));
}
function mp_paginate_rows(array $rows,int $page,int $perPage): array {
    $perPage=max(5,min(100,$perPage));$total=count($rows);$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($page,$pages));
    return ['rows'=>array_slice($rows,($page-1)*$perPage,$perPage),'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages];
}
function mp_stats_from_rows(array $rows,array $definitions): array {
    $out=[];foreach($definitions as $definition){$type=$definition['type']??'count';$key=$definition['key']??null;$value=0;
        if($type==='sum'&&$key){foreach($rows as $r)$value+=(float)($r[$key]??0);}elseif($type==='unique'&&$key){$value=count(array_unique(array_filter(array_map(fn($r)=>(string)($r[$key]??''),$rows))));}else{$value=count($rows);} 
        $out[]=['label'=>$definition['label']??'Total','value'=>$value,'icon'=>$definition['icon']??'fa-chart-simple','money'=>($definition['money']??false)];}
    return $out;
}
function mp_render_stats(array $stats): void { echo '<div class="stats-grid module-stats-grid">';foreach($stats as $s){$v=($s['money']??false)?'UGX '.mp_money($s['value'],0):number_format((float)$s['value'],is_float($s['value'])?2:0);echo '<div class="stat-card"><span class="stat-icon"><i class="fas '.mp_h($s['icon']).'"></i></span><div><strong class="stat-value">'.mp_h($v).'</strong><span class="stat-label">'.mp_h($s['label']).'</span></div></div>';}echo '</div>'; }
function mp_render_toolbar(string $pdfUrl,string $placeholder='Search records...'): void { $search=(string)($_GET['search']??'');$per=(int)($_GET['per_page']??10);echo '<form method="get" class="page-toolbar">';foreach($_GET as $k=>$v){if(!in_array($k,['search','page','per_page'],true)&&!is_array($v))echo '<input type="hidden" name="'.mp_h($k).'" value="'.mp_h($v).'">';}echo '<div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" name="search" value="'.mp_h($search).'" placeholder="'.mp_h($placeholder).'" aria-label="Search"></div><div class="page-toolbar-filters"><select name="per_page" aria-label="Rows per page"><option value="10"'.($per===10?' selected':'').'>10 rows</option><option value="25"'.($per===25?' selected':'').'>25 rows</option><option value="50"'.($per===50?' selected':'').'>50 rows</option></select></div><div class="page-toolbar-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button><a class="btn btn-outline-primary page-toolbar-refresh" href="?'.mp_h(mp_query_string(['search'=>null,'per_page'=>null,'page'=>null])).'"><i class="fas fa-rotate-right"></i> Refresh</a><a class="btn btn-danger" href="'.mp_h($pdfUrl).'" target="_blank"><i class="fas fa-file-pdf"></i> Export PDF</a></div></form>'; }
function mp_render_table(array $rows,array $labels=[]): void {
    if(!$rows){echo '<div class="alert alert-info"><i class="fas fa-circle-info"></i> No records found.</div>';return;}$keys=array_keys($rows[0]);echo '<div class="table-responsive"><table class="table member-table"><thead><tr>';foreach($keys as $k)echo '<th>'.mp_h($labels[$k]??ucwords(str_replace('_',' ',$k))).'</th>';echo '</tr></thead><tbody>';
    foreach($rows as $row){echo '<tr>';foreach($keys as $k){$v=$row[$k]??'';if(is_numeric($v)&&preg_match('/amount|total|paid|balance|target|raised/i',$k))$v='UGX '.mp_money($v,0);elseif(preg_match('/date|_on$/i',$k)&&$v)$v=mp_date($v);echo '<td>'.mp_h($v===''?'-':$v).'</td>';}echo '</tr>';}echo '</tbody></table></div>';
}
function mp_render_pagination(array $p): void { if(($p['pages']??1)<=1)return;echo '<div class="member-pagination"><span>Showing '.((($p['page']-1)*$p['per_page'])+1).'–'.min($p['page']*$p['per_page'],$p['total']).' of '.$p['total'].'</span><nav>';for($i=1;$i<=$p['pages'];$i++){if($i>1&&$i<$p['page']-2){if($i===2)echo '<span>…</span>';continue;}if($i<$p['pages']&&$i>$p['page']+2){if($i===$p['pages']-1)echo '<span>…</span>';continue;}echo '<a class="'.($i===$p['page']?'active':'').'" href="?'.mp_h(mp_query_string(['page'=>$i])).'">'.$i.'</a>';}echo '</nav></div>'; }
function mp_make_pdf_token(string $title,array $rows,array $labels=[]): string { if(empty($_SESSION['mp_pdf']))$_SESSION['mp_pdf']=[];$token=bin2hex(random_bytes(16));$_SESSION['mp_pdf'][$token]=['title'=>$title,'rows'=>$rows,'labels'=>$labels,'created'=>time()];foreach($_SESSION['mp_pdf'] as $k=>$v){if(($v['created']??0)<time()-1800)unset($_SESSION['mp_pdf'][$k]);}return $token; }
function mp_standard_styles(): void { echo '<style>.member-note{padding:12px 14px;background:#eef6ff;border-left:4px solid #2563eb;border-radius:8px;margin-bottom:16px}.member-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.member-tabs a{padding:9px 14px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333;background:#fff}.member-tabs a.active{background:var(--primary);color:#fff;border-color:var(--primary)}.member-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:0 0 18px}.member-stat{display:flex;align-items:center;gap:13px;padding:17px;border:1px solid #e2e6eb;border-radius:13px;background:#fff;box-shadow:0 4px 14px rgba(22,29,40,.05)}.member-stat>span{width:43px;height:43px;display:grid;place-items:center;border-radius:11px;background:var(--primary-fade,#f1ebfa);color:var(--primary)}.member-stat small{display:block;color:#6b7280}.member-stat strong{display:block;margin-top:3px;font-size:21px}.member-table th{white-space:nowrap}.member-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;color:#6b7280}.member-pagination nav{display:flex;gap:5px}.member-pagination a,.member-pagination nav span{min-width:34px;height:34px;display:grid;place-items:center;border:1px solid #ddd;border-radius:7px;text-decoration:none;color:#333}.member-pagination a.active{background:var(--primary);color:#fff;border-color:var(--primary)}@media(max-width:700px){.member-pagination{align-items:stretch;flex-direction:column}}</style>'; }
