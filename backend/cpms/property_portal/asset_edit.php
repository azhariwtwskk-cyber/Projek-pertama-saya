<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('assets.manage');
require_once dirname(__DIR__) . '/includes/asset_service.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$asset = cpmsAssetFind($conn, $currentPropertyId, $id);
if (!$asset) exit('Asset not found.');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['asset_name'] ?? ''));
    $category = trim((string)($_POST['asset_category'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    if ($name === '' || $category === '' || $location === '') $errors[] = 'Asset name, category and location are required.';
    $photoPath = (string)($asset['photo_path'] ?? '');
    if (isset($_FILES['photo']) && (int)$_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$_FILES['photo']['tmp_name']);
        $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($map[$mime])) $errors[] = 'Photo must be JPG, PNG or WebP.';
        elseif ((int)$_FILES['photo']['size'] > 5 * 1024 * 1024) $errors[] = 'Photo maximum size is 5 MB.';
        else {
            $dir = __DIR__ . '/uploads/assets/property_' . $currentPropertyId;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = bin2hex(random_bytes(12)) . '.' . $map[$mime];
            if (move_uploaded_file((string)$_FILES['photo']['tmp_name'], $dir . '/' . $filename)) {
                $photoPath = 'uploads/assets/property_' . $currentPropertyId . '/' . $filename;
            }
        }
    }
    if (!$errors) {
        $block = trim((string)($_POST['block_location'] ?? ''));
        $brand = trim((string)($_POST['brand'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $serial = trim((string)($_POST['serial_number'] ?? ''));
        $purchase = trim((string)($_POST['purchase_date'] ?? ''));
        $cost = (float)($_POST['purchase_cost'] ?? 0);
        $install = trim((string)($_POST['installation_date'] ?? ''));
        $warranty = trim((string)($_POST['warranty_expiry'] ?? ''));
        $vendor = trim((string)($_POST['vendor_name'] ?? ''));
        $phone = trim((string)($_POST['vendor_phone'] ?? ''));
        $frequency = (string)($_POST['maintenance_frequency'] ?? 'Monthly');
        $next = trim((string)($_POST['next_service_date'] ?? ''));
        $status = (string)($_POST['asset_status'] ?? 'Active');
        $condition = (string)($_POST['condition_rating'] ?? 'Good');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $stmt = $conn->prepare('UPDATE assets SET asset_name=?, asset_category=?, location=?, block_location=?, brand=?, model=?, serial_number=?, purchase_date=NULLIF(?,""), purchase_cost=NULLIF(?,0), installation_date=NULLIF(?,""), warranty_expiry=NULLIF(?,""), vendor_name=NULLIF(?,""), vendor_phone=NULLIF(?,""), maintenance_frequency=?, next_service_date=NULLIF(?,""), asset_status=?, condition_rating=?, notes=?, photo_path=NULLIF(?,"") WHERE id=? AND property_id=?');
        if (!$stmt) $errors[] = 'Unable to prepare asset update.';
        else {
            $stmt->bind_param('ssssssssdssssssssssii', $name,$category,$location,$block,$brand,$model,$serial,$purchase,$cost,$install,$warranty,$vendor,$phone,$frequency,$next,$status,$condition,$notes,$photoPath,$id,$currentPropertyId);
            if ($stmt->execute()) {
                $stmt->close();
                cpmsAssetHistoryAdd($conn, $id, $currentPropertyId, 'Updated', 'Asset information updated.', (string)$propertyPortalUser['full_name']);
                header('Location: asset_view.php?id=' . $id . '&updated=1'); exit;
            }
            $errors[] = 'Unable to update asset: ' . $stmt->error; $stmt->close();
        }
    }
}
$pageTitle='Edit Asset';$activeMenu='assets';
require __DIR__.'/includes/layout_header.php';require __DIR__.'/includes/layout_sidebar.php';require __DIR__.'/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/asset-register.css">
<section class="page-heading"><div><span class="section-label">ASSET MANAGEMENT</span><h1>Edit Asset</h1><p><?php echo propertyPortalEscape((string)$asset['asset_code']); ?></p></div></section>
<?php foreach($errors as $e):?><div class="alert-error"><?php echo propertyPortalEscape($e);?></div><?php endforeach;?>
<form method="post" enctype="multipart/form-data" class="panel asset-form"><input type="hidden" name="id" value="<?php echo $id;?>"><div class="form-grid">
<?php $fields=['asset_name'=>'Asset Name *','asset_category'=>'Category *','location'=>'Location *','block_location'=>'Block / Zone','brand'=>'Brand','model'=>'Model','serial_number'=>'Serial Number','purchase_date'=>'Purchase Date','purchase_cost'=>'Purchase Cost (RM)','installation_date'=>'Installation Date','warranty_expiry'=>'Warranty Expiry','vendor_name'=>'Vendor','vendor_phone'=>'Vendor Phone','next_service_date'=>'Next Service Date']; foreach($fields as $n=>$l): $v=(string)($_POST[$n]??$asset[$n]??'');?><div class="field-group"><label><?php echo $l;?></label><input <?php echo strpos($n,'date')!==false||$n==='warranty_expiry'?'type="date"':'';?> <?php echo $n==='purchase_cost'?'type="number" step="0.01"':'';?> name="<?php echo $n;?>" value="<?php echo propertyPortalEscape($v);?>"></div><?php endforeach;?>
<div class="field-group"><label>Maintenance Frequency</label><select name="maintenance_frequency"><?php foreach(['Weekly','Monthly','Quarterly','Half Yearly','Yearly','As Required'] as $o):?><option <?php echo (($_POST['maintenance_frequency']??$asset['maintenance_frequency'])===$o)?'selected':'';?>><?php echo $o;?></option><?php endforeach;?></select></div>
<div class="field-group"><label>Asset Status</label><select name="asset_status"><?php foreach(['Active','Under Maintenance','Out of Service','Expired','Disposed'] as $o):?><option <?php echo (($_POST['asset_status']??$asset['asset_status'])===$o)?'selected':'';?>><?php echo $o;?></option><?php endforeach;?></select></div>
<div class="field-group"><label>Condition</label><select name="condition_rating"><?php foreach(['Excellent','Good','Fair','Poor','Critical'] as $o):?><option <?php echo (($_POST['condition_rating']??$asset['condition_rating'])===$o)?'selected':'';?>><?php echo $o;?></option><?php endforeach;?></select></div>
<div class="field-group"><label>Replace Asset Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div></div>
<div class="field-group"><label>Notes</label><textarea name="notes" rows="4"><?php echo propertyPortalEscape((string)($_POST['notes']??$asset['notes']??''));?></textarea></div>
<button class="button button-primary">Update Asset</button><a class="button button-secondary" href="asset_view.php?id=<?php echo $id;?>">Cancel</a></form>
<?php require __DIR__.'/includes/layout_footer.php';?>
