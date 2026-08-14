<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('reports.view');
require_once dirname(__DIR__) . '/includes/report_service.php';

$report = trim((string)($_GET['report'] ?? 'summary'));
$allowed = ['summary','complaints','work_orders','inspections','pm','assets'];
if (!in_array($report, $allowed, true)) $report = 'summary';
$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? date('Y-m-d')));
$status = trim((string)($_GET['status'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));

$cards = [];
$headers = [];
$rows = [];
$title = 'Executive Summary';
$notice = '';

function reportWhereFor(mysqli $conn, string $table, int $propertyId, string $from, string $to, array $dateChoices): array
{
    $dateColumn = cpmsReportDateColumn($conn, $table, $dateChoices);
    return cpmsReportBuildWhere(
        cpmsReportPropertyWhere($conn, $table, $propertyId),
        cpmsReportDateFilter($conn, $table, $dateColumn, $from, $to)
    );
}

if ($report === 'summary') {
    $datasets = [
        ['key'=>'Complaints','table'=>'complaints','dates'=>['created_at','complaint_date','date_created']],
        ['key'=>'Work Orders','table'=>'work_orders','dates'=>['created_at','scheduled_date']],
        ['key'=>'Inspections','table'=>'inspection_reports','dates'=>['inspection_date','created_at']],
        ['key'=>'Assets','table'=>'assets','dates'=>['created_at','installation_date']],
        ['key'=>'PM Schedules','table'=>'pm_schedules','dates'=>['created_at','next_due_date']],
    ];
    foreach ($datasets as $d) {
        if (!cpmsReportTableExists($conn, $d['table'])) { $cards[$d['key']] = null; continue; }
        list($where,$types,$params) = reportWhereFor($conn,$d['table'],$currentPropertyId,$from,$to,$d['dates']);
        $cards[$d['key']] = (int)cpmsReportScalar($conn, 'SELECT COUNT(*) FROM `'.$d['table'].'`'.$where, $types, $params);
    }
    if (cpmsReportTableExists($conn,'work_orders')) {
        list($where,$types,$params) = reportWhereFor($conn,'work_orders',$currentPropertyId,$from,$to,['created_at','scheduled_date']);
        $extra = cpmsReportHasColumn($conn,'work_orders','status') ? ($where === '' ? ' WHERE ' : $where.' AND ') . "status IN ('Completed','Verified')" : $where;
        $cards['Completed WO'] = (int)cpmsReportScalar($conn,'SELECT COUNT(*) FROM work_orders'.$extra,$types,$params);
    }
} elseif ($report === 'work_orders' && cpmsReportTableExists($conn,'work_orders')) {
    $title = 'Work Order Report';
    list($where,$types,$params) = reportWhereFor($conn,'work_orders',$currentPropertyId,$from,$to,['created_at','scheduled_date']);
    if ($status !== '' && cpmsReportHasColumn($conn,'work_orders','status')) { $where .= ($where===''?' WHERE ':' AND ').'status=?'; $types.='s'; $params[]=$status; }
    $select = [];
    foreach (['work_order_reference','title','category','priority','block_location','assigned_contractor','due_date','status','actual_cost','created_at'] as $c) if (cpmsReportHasColumn($conn,'work_orders',$c)) $select[]=$c;
    $data = cpmsReportFetch($conn,'SELECT '.implode(',',$select).' FROM work_orders'.$where.' ORDER BY id DESC LIMIT 1000',$types,$params);
    $headers = array_map(function($v){return ucwords(str_replace('_',' ',$v));},$select); $rows=$data;
} elseif ($report === 'inspections' && cpmsReportTableExists($conn,'inspection_reports')) {
    $title = 'Inspection Report';
    list($where,$types,$params) = reportWhereFor($conn,'inspection_reports',$currentPropertyId,$from,$to,['inspection_date','created_at']);
    if ($status !== '' && cpmsReportHasColumn($conn,'inspection_reports','status')) { $where .= ($where===''?' WHERE ':' AND ').'status=?'; $types.='s'; $params[]=$status; }
    $select=[]; foreach(['inspection_no','inspection_date','inspection_type','category','location','priority','status','compliance_result','reported_by_name','due_date'] as $c) if(cpmsReportHasColumn($conn,'inspection_reports',$c))$select[]=$c;
    $rows=cpmsReportFetch($conn,'SELECT '.implode(',',$select).' FROM inspection_reports'.$where.' ORDER BY id DESC LIMIT 1000',$types,$params);
    $headers=array_map(function($v){return ucwords(str_replace('_',' ',$v));},$select);
} elseif ($report === 'assets' && cpmsReportTableExists($conn,'assets')) {
    $title = 'Asset Register Report';
    list($where,$types,$params)=cpmsReportBuildWhere(cpmsReportPropertyWhere($conn,'assets',$currentPropertyId));
    if ($status!=='' && cpmsReportHasColumn($conn,'assets','asset_status')){$where.=($where===''?' WHERE ':' AND ').'asset_status=?';$types.='s';$params[]=$status;}
    $select=[];foreach(['asset_code','asset_name','asset_category','location','condition_rating','asset_status','maintenance_frequency','last_service_date','next_service_date','warranty_expiry'] as $c)if(cpmsReportHasColumn($conn,'assets',$c))$select[]=$c;
    $rows=cpmsReportFetch($conn,'SELECT '.implode(',',$select).' FROM assets'.$where.' ORDER BY asset_name ASC LIMIT 1000',$types,$params);
    $headers=array_map(function($v){return ucwords(str_replace('_',' ',$v));},$select);
} elseif ($report === 'pm' && cpmsReportTableExists($conn,'pm_schedules')) {
    $title='Preventive Maintenance Report';
    list($where,$types,$params)=reportWhereFor($conn,'pm_schedules',$currentPropertyId,$from,$to,['next_due_date','created_at']);
    if($status!=='' && cpmsReportHasColumn($conn,'pm_schedules','status')){$where.=($where===''?' WHERE ':' AND ').'status=?';$types.='s';$params[]=$status;}
    $select=[];foreach(['schedule_code','task_name','frequency_value','frequency_unit','next_due_date','priority','status','last_generated_at'] as $c)if(cpmsReportHasColumn($conn,'pm_schedules',$c))$select[]=$c;
    $rows=cpmsReportFetch($conn,'SELECT '.implode(',',$select).' FROM pm_schedules'.$where.' ORDER BY next_due_date ASC LIMIT 1000',$types,$params);
    $headers=array_map(function($v){return ucwords(str_replace('_',' ',$v));},$select);
} elseif ($report === 'complaints' && cpmsReportTableExists($conn,'complaints')) {
    $title='Complaint Report';
    list($where,$types,$params)=reportWhereFor($conn,'complaints',$currentPropertyId,$from,$to,['created_at','complaint_date','date_created']);
    $statusCol=cpmsReportHasColumn($conn,'complaints','status')?'status':(cpmsReportHasColumn($conn,'complaints','complaint_status')?'complaint_status':null);
    if($status!=='' && $statusCol){$where.=($where===''?' WHERE ':' AND ').'`'.$statusCol.'`=?';$types.='s';$params[]=$status;}
    $candidates=['complaint_id','reference_no','complaint_reference','subject','title','category','block','block_location','location',$statusCol,'created_at'];
    $select=[];foreach($candidates as $c)if($c && cpmsReportHasColumn($conn,'complaints',$c) && !in_array($c,$select,true))$select[]=$c;
    if(!$select){$notice='Complaint table exists, but compatible report columns were not found.';}else{$rows=cpmsReportFetch($conn,'SELECT '.implode(',',array_map(function($c){return '`'.$c.'`';},$select)).' FROM complaints'.$where.' ORDER BY id DESC LIMIT 1000',$types,$params);$headers=array_map(function($v){return ucwords(str_replace('_',' ',$v));},$select);}
} else {
    $notice='This report is not available because its database table has not been installed yet.';
}

if ($export === 'csv' && $headers) cpmsReportCsv('cpms_'.str_replace(' ','_',strtolower($title)).'_'.date('Ymd').'.csv',$headers,$rows);

$pageTitle='Reports Centre';$activeMenu='reports';
require __DIR__.'/includes/layout_header.php';require __DIR__.'/includes/layout_sidebar.php';require __DIR__.'/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/reports-centre.css">
<section class="page-heading no-print"><div><span class="section-label">MANAGEMENT REPORTING</span><h1>Reports Centre</h1><p>Operational reports for <strong><?php echo propertyPortalEscape($currentPropertyName); ?></strong>.</p></div></section>
<nav class="report-tabs no-print">
<?php foreach(['summary'=>'Executive Summary','complaints'=>'Complaints','work_orders'=>'Work Orders','inspections'=>'Inspections','pm'=>'Preventive Maintenance','assets'=>'Assets'] as $key=>$label):?>
<a class="<?php echo $report===$key?'active':'';?>" href="reports.php?report=<?php echo $key;?>&from=<?php echo urlencode($from);?>&to=<?php echo urlencode($to);?>"><?php echo propertyPortalEscape($label);?></a>
<?php endforeach;?>
</nav>
<section class="panel report-filter no-print"><form method="get"><input type="hidden" name="report" value="<?php echo propertyPortalEscape($report);?>"><div class="field-group"><label>Date From</label><input type="date" name="from" value="<?php echo propertyPortalEscape($from);?>"></div><div class="field-group"><label>Date To</label><input type="date" name="to" value="<?php echo propertyPortalEscape($to);?>"></div><?php if($report!=='summary'):?><div class="field-group"><label>Status</label><input name="status" value="<?php echo propertyPortalEscape($status);?>" placeholder="All statuses"></div><?php endif;?><button class="button button-primary">Apply Filter</button><a class="button button-secondary" href="reports.php?report=<?php echo propertyPortalEscape($report);?>">Reset</a></form></section>
<section class="panel report-print-header"><div><h2><?php echo propertyPortalEscape($title);?></h2><p><?php echo propertyPortalEscape($currentPropertyName);?> · <?php echo propertyPortalEscape($from);?> to <?php echo propertyPortalEscape($to);?></p></div><div class="report-actions no-print"><?php if($headers):?><a class="button button-secondary" href="?<?php echo propertyPortalEscape(http_build_query(array_merge($_GET,['export'=>'csv'])));?>">Export CSV</a><?php endif;?><button class="button button-primary" onclick="window.print()">Print / Save PDF</button></div></section>
<?php if($notice!==''):?><section class="panel"><div class="report-notice"><?php echo propertyPortalEscape($notice);?></div></section><?php endif;?>
<?php if($report==='summary'):?><section class="report-kpis"><?php foreach($cards as $label=>$value):?><article class="report-kpi"><span><?php echo propertyPortalEscape($label);?></span><strong><?php echo $value===null?'—':number_format((int)$value);?></strong><small><?php echo $value===null?'Module/table unavailable':'Selected period';?></small></article><?php endforeach;?></section>
<section class="panel"><h3>Report shortcuts</h3><div class="report-shortcuts"><?php foreach(['complaints'=>'Complaint Report','work_orders'=>'Work Order Report','inspections'=>'Inspection Report','pm'=>'PM Report','assets'=>'Asset Register Report'] as $key=>$label):?><a href="reports.php?report=<?php echo $key;?>&from=<?php echo urlencode($from);?>&to=<?php echo urlencode($to);?>"><strong><?php echo propertyPortalEscape($label);?></strong><span>Open detailed report →</span></a><?php endforeach;?></div></section>
<?php elseif($headers):?><section class="panel"><div class="table-wrap"><table class="data-table" data-smart-table data-page-size="20" data-export-title="<?php echo propertyPortalEscape($title);?>"><thead><tr><?php foreach($headers as $h):?><th><?php echo propertyPortalEscape($h);?></th><?php endforeach;?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($row as $value):?><td><?php echo propertyPortalEscape((string)($value??'-'));?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php if(!$rows):?><div class="empty-state"><strong>No records found</strong><span>Try another date range or status.</span></div><?php endif;?></div></section><?php endif;?>
<?php require __DIR__.'/includes/layout_footer.php';?>
