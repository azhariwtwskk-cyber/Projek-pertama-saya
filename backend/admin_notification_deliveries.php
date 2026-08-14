<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", "1");

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

$propertyId = cpmsRequireCurrentPropertyId($conn);
$currentProperty = cpmsCurrentProperty($conn);

if (!$currentProperty) {
    http_response_code(503);
    exit("Tiada property aktif dipilih.");
}

$statusFilter = strtoupper(trim((string) ($_GET["status"] ?? "")));
$channelFilter = strtoupper(trim((string) ($_GET["channel"] ?? "")));

$where = ["property_id = ?"];
$types = "i";
$values = [$propertyId];

if ($statusFilter !== "") {
    $where[] = "delivery_status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}

if ($channelFilter !== "") {
    $where[] = "channel_name = ?";
    $types .= "s";
    $values[] = $channelFilter;
}

$sql =
    "
    SELECT
        id,
        channel_name,
        recipient_name,
        recipient_address,
        reference_no,
        delivery_status,
        attempt_count,
        created_at
    FROM cpms_notification_deliveries
    WHERE " .
    implode(" AND ", $where) .
    "
    ORDER BY id DESC
    LIMIT 300
    ";

$stmt = $conn->prepare($sql);
$deliveries = [];

if ($stmt) {
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $deliveries[] = $row;
    }

    $stmt->close();
}

$summary = [
    "total" => 0,
    "pending" => 0,
    "sent" => 0,
    "failed" => 0
];

$summaryStmt = $conn->prepare(
    "
    SELECT
        COUNT(*) AS total,
        SUM(delivery_status = 'PENDING') AS pending,
        SUM(delivery_status = 'SENT') AS sent,
        SUM(delivery_status = 'FAILED') AS failed
    FROM cpms_notification_deliveries
    WHERE property_id = ?
    "
);

if ($summaryStmt) {
    $summaryStmt->bind_param("i", $propertyId);
    $summaryStmt->execute();

    $row = $summaryStmt->get_result()->fetch_assoc();

    foreach ($summary as $key => $value) {
        $summary[$key] = (int) ($row[$key] ?? 0);
    }

    $summaryStmt->close();
}

$conn->close();

$propertyName = (string) ($currentProperty["name"] ?? "Property");
$primaryColor = (string) ($currentProperty["primary_color"] ?? "#3a2419");
$secondaryColor = (string) ($currentProperty["secondary_color"] ?? "#b59b20");

?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notification Delivery Queue | <?php echo e($propertyName); ?></title>

<style>
:root{--primary:<?php echo e($primaryColor); ?>;--secondary:<?php echo e($secondaryColor); ?>}
*{box-sizing:border-box}
body{margin:0;background:#f6f2ea;font-family:Arial,sans-serif;color:#333}
.page{width:min(1160px,calc(100% - 24px));margin:28px auto}
.header,.card{padding:22px;border:1px solid #e5ddd2;border-radius:15px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.06)}
.header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;border-top:6px solid var(--secondary)}
.header h1{margin:0 0 5px;color:var(--primary)}
.header p{margin:0;color:#777}
.button{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border:0;border-radius:8px;background:var(--primary);color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
.button.gold{background:var(--secondary)}
.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.summary article{padding:15px;border:1px solid #e7e7e7;border-radius:10px;background:#fff}
.summary span{display:block;margin-bottom:5px;color:#777;font-size:12px;font-weight:700}
.summary strong{color:var(--primary);font-size:22px}
.filters{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;margin-bottom:18px}
.filters select{padding:10px;border:1px solid #d8d1c8;border-radius:8px;background:#fff}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:12px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;font-size:13px}
th{color:var(--primary)}
.badge{display:inline-flex;padding:5px 8px;border-radius:99px;font-size:10px;font-weight:800}
.pending{background:#fff6df;color:#8b6800}
.sent{background:#edf9f1;color:#176b36}
.failed{background:#fff0f0;color:#9f2626}
.cancelled{background:#eee;color:#666}
.empty{padding:30px;text-align:center;color:#777}
@media(max-width:760px){.header{align-items:flex-start;flex-direction:column}.summary,.filters{grid-template-columns:1fr}}
</style>
</head>

<body>
<main class="page">

<header class="header">
    <div>
        <h1>Notification Delivery Queue</h1>
        <p><?php echo e($propertyName); ?></p>
    </div>

    <a href="admin_dashboard.php" class="button">← Dashboard</a>
</header>

<section class="summary">
    <article><span>Jumlah</span><strong><?php echo $summary["total"]; ?></strong></article>
    <article><span>Pending</span><strong><?php echo $summary["pending"]; ?></strong></article>
    <article><span>Sent</span><strong><?php echo $summary["sent"]; ?></strong></article>
    <article><span>Failed</span><strong><?php echo $summary["failed"]; ?></strong></article>
</section>

<section class="card">

<form method="GET" class="filters">

    <select name="channel">
        <option value="">Semua Saluran</option>

        <?php foreach (["EMAIL", "WHATSAPP", "SMS", "PUSH"] as $channel): ?>
            <option
                value="<?php echo e($channel); ?>"
                <?php echo $channelFilter === $channel ? "selected" : ""; ?>
            >
                <?php echo e($channel); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="status">
        <option value="">Semua Status</option>

        <?php foreach (["PENDING", "SENT", "FAILED", "CANCELLED"] as $status): ?>
            <option
                value="<?php echo e($status); ?>"
                <?php echo $statusFilter === $status ? "selected" : ""; ?>
            >
                <?php echo e($status); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="button gold">Tapis</button>

</form>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Tarikh</th>
    <th>Saluran</th>
    <th>Penerima</th>
    <th>Rujukan</th>
    <th>Status</th>
    <th>Tindakan</th>
</tr>
</thead>

<tbody>

<?php if (count($deliveries) === 0): ?>
<tr>
    <td colspan="6" class="empty">Tiada rekod penghantaran dijumpai.</td>
</tr>
<?php else: ?>

<?php foreach ($deliveries as $delivery): ?>
<?php
$status = strtoupper((string) ($delivery["delivery_status"] ?? "PENDING"));
$statusClass = match ($status) {
    "SENT" => "sent",
    "FAILED" => "failed",
    "CANCELLED" => "cancelled",
    default => "pending"
};
?>

<tr>
    <td>
        <?php
        echo e(
            date(
                "d/m/Y h:i A",
                strtotime((string) $delivery["created_at"])
            )
        );
        ?>
    </td>

    <td><?php echo e((string) $delivery["channel_name"]); ?></td>

    <td>
        <?php echo e((string) ($delivery["recipient_name"] ?? "-")); ?>
        <br>
        <small><?php echo e((string) $delivery["recipient_address"]); ?></small>
    </td>

    <td><?php echo e((string) ($delivery["reference_no"] ?? "-")); ?></td>

    <td>
        <span class="badge <?php echo e($statusClass); ?>">
            <?php echo e($status); ?>
        </span>
        <br>
        <small>Percubaan: <?php echo (int) $delivery["attempt_count"]; ?></small>
    </td>

    <td>
        <a
            href="admin_notification_send.php?id=<?php echo (int) $delivery["id"]; ?>&return=admin_notification_deliveries.php"
            class="button gold"
        >
            Proses
        </a>
    </td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</section>
</main>
</body>
</html>
