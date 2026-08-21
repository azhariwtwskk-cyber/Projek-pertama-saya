<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('assets.view');
require_once dirname(__DIR__) . '/includes/asset_service.php';

if (!cpmsAssetSchemaReady($conn)) {
    exit('Asset migration not imported. Import cpms/sql/asset_bci_sprint_2_7.sql first.');
}
$status = trim((string)($_GET['status'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$sql = 'SELECT a.*, (SELECT COUNT(*) FROM inspection_reports i WHERE i.property_id=a.property_id AND i.asset_id=a.id) inspection_count, (SELECT COUNT(*) FROM work_orders w WHERE w.property_id=a.property_id AND w.inspection_id IN (SELECT id FROM inspection_reports WHERE asset_id=a.id)) work_order_count FROM assets a WHERE a.property_id=?';
$types='i'; $params=[$currentPropertyId];
if ($status !== '') { $sql .= ' AND a.asset_status=?'; $types.='s'; $params[]=$status; }
if ($category !== '') { $sql .= ' AND a.asset_category=?'; $types.='s'; $params[]=$category; }
$sql .= ' ORDER BY a.asset_name ASC, a.id DESC';
$stmt=$conn->prepare($sql); $rows=[];
if($stmt){$stmt->bind_param($types,...$params);$stmt->execute();$r=$stmt->get_result();while($x=$r->fetch_assoc())$rows[]=$x;$stmt->close();}
$cats=[];$c=$conn->prepare('SELECT DISTINCT asset_category FROM assets WHERE property_id=? ORDER BY asset_category');if($c){$c->bind_param('i',$currentPropertyId);$c->execute();$r=$c->get_result();while($x=$r->fetch_assoc())$cats[]=$x['asset_category'];$c->close();}
$pageTitle='Asset Register';$activeMenu='assets';
require __DIR__.'/includes/layout_header.php';require __DIR__.'/includes/layout_sidebar.php';require __DIR__.'/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/asset-register.css">
<section class="page-heading"><div><span class="section-label">ASSET MANAGEMENT</span><h1>Asset Register</h1><p>Central asset register for <strong><?php echo propertyPortalEscape($currentPropertyName); ?></strong>.</p></div><div class="record-total"><span>Assets</span><strong><?php echo count($rows); ?></strong></div></section>
<section class="panel"><div class="asset-actions"><a class="button button-primary" href="asset_create.php">+ Add Asset</a><a class="button button-secondary" href="bci.php">Building Condition Index</a></div><form method="get" class="simple-filter-form"><div class="field-group"><label>Status</label><select name="status"><option value="">All</option><?php foreach(['Active','Under Maintenance','Out of Service','Expired','Disposed'] as $o):?><option <?php echo $status===$o?'selected':'';?>><?php echo propertyPortalEscape($o);?></option><?php endforeach;?></select></div><div class="field-group"><label>Category</label><select name="category"><option value="">All</option><?php foreach($cats as $o):?><option <?php echo $category===$o?'selected':'';?>><?php echo propertyPortalEscape((string)$o);?></option><?php endforeach;?></select></div><button class="button button-primary">Filter</button><a class="button button-secondary" href="assets.php">Reset</a></form></section>
<section class="panel"><div class="table-wrap"><table class="data-table" data-smart-table data-page-size="10" data-export-title="Asset Register"><thead><tr><th>Asset ID</th><th>Name</th><th>Category</th><th>Location</th><th>Condition</th><th>Status</th><th>Next Service</th><th>History</th></tr></thead><tbody><?php foreach($rows as $x):?><tr><td><a href="asset_view.php?id=<?php echo (int)$x['id'];?>"><strong><?php echo propertyPortalEscape((string)$x['asset_code']);?></strong></a></td><td><?php echo propertyPortalEscape((string)$x['asset_name']);?></td><td><?php echo propertyPortalEscape((string)$x['asset_category']);?></td><td><?php echo propertyPortalEscape((string)$x['location']);?></td><td><span class="asset-pill"><?php echo propertyPortalEscape((string)$x['condition_rating']);?></span></td><td><?php echo propertyPortalEscape((string)$x['asset_status']);?></td><td><?php echo propertyPortalEscape((string)($x['next_service_date']?:'-'));?></td><td><?php echo (int)$x['inspection_count'];?> inspections · <?php echo (int)$x['work_order_count'];?> WO</td></tr><?php endforeach;?></tbody></table><?php if(!$rows):?><div class="empty-state"><strong>No assets found</strong><span>Add the first asset for this property.</span></div><?php endif;?></div></section>
<?php require __DIR__.'/includes/layout_footer.php';?>
