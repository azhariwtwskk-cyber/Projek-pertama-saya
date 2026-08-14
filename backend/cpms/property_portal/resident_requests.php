<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('resident.request.manage');
$rows = [];
$stmt = $conn->prepare(
    "SELECT sr.*,r.full_name,r.block_name,r.unit_no,
            l.complaint_reference,l.work_order_id,
            w.work_order_reference,w.status work_order_status
     FROM cpms_resident_service_requests sr
     JOIN cpms_residents r ON r.id=sr.resident_id
     LEFT JOIN cpms_resident_request_links l ON l.service_request_id=sr.id
     LEFT JOIN work_orders w ON w.id=l.work_order_id
     WHERE sr.property_id=?
     ORDER BY sr.id DESC LIMIT 300"
);
$stmt->bind_param('i', $currentPropertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();
function rrEsc($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resident Requests | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}main{max-width:1150px;margin:auto;padding:24px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}.btn{display:inline-block;background:#17457f;color:#fff;padding:9px 12px;border-radius:8px;text-decoration:none;font-weight:bold}.badge{background:#e6eef9;padding:5px 9px;border-radius:99px;font-size:12px}@media(max-width:760px){main{padding:12px}.table{overflow:auto}}</style></head><body><main><section class="card"><h1>Resident Service Requests</h1><p>Semak, tukar kepada complaint dan hasilkan work order.</p><a href="dashboard.php">← Dashboard</a></section><section class="card table"><table><thead><tr><th>Reference</th><th>Resident/Unit</th><th>Permohonan</th><th>Status</th><th>Complaint</th><th>Work Order</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">Tiada permohonan resident.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td><strong><?=rrEsc($row['request_reference'])?></strong></td><td><?=rrEsc($row['full_name'])?><br><small><?=rrEsc(($row['block_name']??'').' / '.($row['unit_no']??'-'))?></small></td><td><?=rrEsc($row['subject'])?><br><small><?=rrEsc($row['request_type'])?></small></td><td><span class="badge"><?=rrEsc($row['status'])?></span></td><td><?=rrEsc($row['complaint_reference']??'-')?></td><td><?=rrEsc($row['work_order_reference']??'-')?></td><td><a class="btn" href="resident_request_view.php?id=<?=(int)$row['id']?>">Buka</a></td></tr><?php endforeach; ?>
</tbody></table></section></main></body></html>
