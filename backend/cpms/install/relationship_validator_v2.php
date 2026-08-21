<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();
if (!$user || (string)($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}
$db = cpmsV2Database();

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dbname(mysqli $db): string {
    $r=$db->query('SELECT DATABASE() AS n'); $x=$r?$r->fetch_assoc():[];
    return (string)($x['n']??'');
}
function existsTable(mysqli $db,string $schema,string $table): bool {
    $s=$db->prepare('SELECT COUNT(*) t FROM information_schema.tables WHERE table_schema=? AND table_name=?');
    if(!$s)return false; $s->bind_param('ss',$schema,$table); $s->execute();
    $r=$s->get_result(); $x=$r?$r->fetch_assoc():[]; $s->close();
    return (int)($x['t']??0)>0;
}
function existsColumn(mysqli $db,string $schema,string $table,string $column): bool {
    $s=$db->prepare('SELECT COUNT(*) t FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?');
    if(!$s)return false; $s->bind_param('sss',$schema,$table,$column); $s->execute();
    $r=$s->get_result(); $x=$r?$r->fetch_assoc():[]; $s->close();
    return (int)($x['t']??0)>0;
}
function existsFk(mysqli $db,string $schema,string $table,string $column,string $pt,string $pc): bool {
    $s=$db->prepare('SELECT COUNT(*) t FROM information_schema.key_column_usage WHERE table_schema=? AND table_name=? AND column_name=? AND referenced_table_name=? AND referenced_column_name=?');
    if(!$s)return false; $s->bind_param('sssss',$schema,$table,$column,$pt,$pc); $s->execute();
    $r=$s->get_result(); $x=$r?$r->fetch_assoc():[]; $s->close();
    return (int)($x['t']??0)>0;
}
function scalar(mysqli $db,string $sql): int {
    $r=$db->query($sql); if(!$r)return -1; $x=$r->fetch_row(); return (int)($x[0]??0);
}
function qi(string $v): string { return '`'.str_replace('`','``',$v).'`'; }

$schema=dbname($db);
$defs=[
 ['complaints','property_id','cpms_properties','id',false,'RESTRICT'],
 ['complaint_images','property_id','cpms_properties','id',false,'RESTRICT'],
 ['work_orders','property_id','cpms_properties','id',false,'RESTRICT'],
 ['assets','property_id','cpms_properties','id',false,'RESTRICT'],
 ['notifications','property_id','cpms_properties','id',false,'RESTRICT'],
 ['security_patrols','property_id','cpms_properties','id',false,'RESTRICT'],
 ['work_orders','assigned_staff_id','staff','id',true,'SET NULL'],
 ['security_patrols','guard_id','security_guards','id',false,'RESTRICT'],
];

$rows=[]; $passed=0;
foreach($defs as $d){
    [$ct,$cc,$pt,$pc,$nullable,$deleteRule]=$d;
    $cte=existsTable($db,$schema,$ct); $pte=existsTable($db,$schema,$pt);
    $cce=$cte&&existsColumn($db,$schema,$ct,$cc);
    $pce=$pte&&existsColumn($db,$schema,$pt,$pc);
    $count=$nulls=$orphans=-1;
    if($cce){
        $count=scalar($db,'SELECT COUNT(*) FROM '.qi($ct));
        $nulls=scalar($db,'SELECT COUNT(*) FROM '.qi($ct).' WHERE '.qi($cc).' IS NULL');
    }
    if($cce&&$pce){
        $orphans=scalar($db,'SELECT COUNT(*) FROM '.qi($ct).' c LEFT JOIN '.qi($pt).' p ON p.'.qi($pc).'=c.'.qi($cc).' WHERE c.'.qi($cc).' IS NOT NULL AND p.'.qi($pc).' IS NULL');
    }
    $fk=$cce&&$pce?existsFk($db,$schema,$ct,$cc,$pt,$pc):false;
    $ready=$cte&&$pte&&$cce&&$pce&&$orphans===0&&($nullable||$nulls===0);
    if($ready)$passed++;
    $advice=[];
    if(!$cte)$advice[]='Child table missing.';
    if(!$pte)$advice[]='Parent table missing.';
    if($cte&&!$cce)$advice[]='Child column missing.';
    if($pte&&!$pce)$advice[]='Parent column missing.';
    if(!$nullable&&$nulls>0)$advice[]='Assign valid values to NULL records.';
    if($orphans>0)$advice[]='Repair orphan records.';
    if($ready&&!$fk)$advice[]='Ready for foreign-key enforcement.';
    if($fk)$advice[]='Foreign key already exists.';
    $rows[]=[
      'child_table'=>$ct,'child_column'=>$cc,'parent_table'=>$pt,'parent_column'=>$pc,
      'nullable_allowed'=>$nullable,'recommended_delete'=>$deleteRule,'row_count'=>$count,
      'null_count'=>$nulls,'orphan_count'=>$orphans,'foreign_key_exists'=>$fk,
      'ready'=>$ready,'advice'=>$advice
    ];
}
$total=count($rows); $score=$total?(int)round(($passed/$total)*100):0;
$report=['generated_at'=>date(DATE_ATOM),'database'=>$schema,'server_version'=>$db->server_info,
         'score'=>$score,'passed'=>$passed,'total'=>$total,'relationships'=>$rows];

if(($_GET['download']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="cpms_relationship_report_'.date('Ymd_His').'.json"');
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Relationship Validator</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1180px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}.notice{padding:14px;border-radius:10px;margin:16px 0}
.ok{background:#eaf8ef;color:#146c34}.warnbox{background:#fff7e5;color:#805800}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}.card strong{display:block;font-size:24px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:18px}th,td{padding:11px;border-bottom:1px solid #e5ebf3;text-align:left;font-size:13px;vertical-align:top}
.pass{color:#08783b;font-weight:700}.warn{color:#a36b00;font-weight:700}.fail{color:#b42318;font-weight:700}
.button{display:inline-block;background:#10213d;color:#fff;text-decoration:none;padding:11px 15px;border-radius:9px;font-weight:700}
@media(max-width:760px){main{margin:10px}.cards{grid-template-columns:1fr 1fr}table{display:block;overflow-x:auto}}
</style></head><body><main>
<h1>CPMS v2.0.7 Relationship Validator</h1>
<p class="muted">Read-only validation before foreign-key enforcement.</p>
<div class="notice <?= $score===100?'ok':'warnbox' ?>">
<?= $score===100?'All validated relationships are ready for enforcement.':'Some relationships still require correction. Do not enforce foreign keys yet.' ?>
</div>
<div class="cards">
<div class="card">Readiness Score<strong><?= $score ?>%</strong></div>
<div class="card">Passed<strong><?= $passed ?></strong></div>
<div class="card">Checked<strong><?= $total ?></strong></div>
<div class="card">Database<strong style="font-size:13px"><?= esc($schema) ?></strong></div>
</div>
<a class="button" href="?download=json">Download JSON Report</a>
<table><thead><tr><th>Relationship</th><th>Rows</th><th>NULL</th><th>Orphans</th><th>Foreign Key</th><th>Ready</th><th>Advice</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><strong><?= esc($r['child_table']) ?></strong>.<?= esc($r['child_column']) ?><br><span class="muted">→ <?= esc($r['parent_table']) ?>.<?= esc($r['parent_column']) ?></span></td>
<td><?= (int)$r['row_count'] ?></td>
<td class="<?= ($r['nullable_allowed']||$r['null_count']===0)?'pass':'warn' ?>"><?= (int)$r['null_count'] ?></td>
<td class="<?= $r['orphan_count']===0?'pass':'fail' ?>"><?= (int)$r['orphan_count'] ?></td>
<td class="<?= $r['foreign_key_exists']?'pass':'warn' ?>"><?= $r['foreign_key_exists']?'Present':'Missing' ?></td>
<td class="<?= $r['ready']?'pass':'fail' ?>"><?= $r['ready']?'Yes':'No' ?></td>
<td><?= esc(implode(' ',$r['advice'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<p class="muted">No data is modified. Download the JSON report before CPMS v2.0.8.</p>
</main></body></html>
