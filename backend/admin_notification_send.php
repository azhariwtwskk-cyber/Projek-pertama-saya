<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", "1");

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";
require_once __DIR__ . "/cpms/includes/audit_engine.php";

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

function safeReturnUrl(string $value): string
{
    $value = trim($value);

    if (
        $value === "" ||
        !preg_match(
            '/^[a-zA-Z0-9_\-\.]+(?:\?[a-zA-Z0-9_\-=&%]*)?$/',
            $value
        )
    ) {
        return "admin_notification_deliveries.php";
    }

    return $value;
}

if (!isset($_SESSION["delivery_processor_csrf"])) {
    $_SESSION["delivery_processor_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string) $_SESSION["delivery_processor_csrf"];

$propertyId =
    cpmsRequireCurrentPropertyId($conn);

$currentProperty =
    cpmsCurrentProperty($conn);

if (!$currentProperty) {
    http_response_code(503);
    exit("Tiada property aktif dipilih.");
}

$deliveryId =
    (int) (
        $_GET["id"] ??
        $_POST["id"] ??
        0
    );

$returnUrl =
    safeReturnUrl(
        (string) (
            $_GET["return"] ??
            $_POST["return"] ??
            "admin_notification_deliveries.php"
        )
    );

if ($deliveryId < 1) {
    http_response_code(400);
    exit("ID penghantaran tidak sah.");
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = "Permintaan tidak sah.";
    } else {
        $action =
            (string) ($_POST["action"] ?? "");

        $allowedActions = [
            "mark_sent",
            "mark_failed",
            "reset_pending"
        ];

        if (!in_array($action, $allowedActions, true)) {
            $error = "Tindakan tidak sah.";
        } else {
            $statusMap = [
                "mark_sent" => "SENT",
                "mark_failed" => "FAILED",
                "reset_pending" => "PENDING"
            ];

            $newStatus =
                $statusMap[$action];

            $lastError =
                $action === "mark_failed"
                    ? trim(
                        (string) (
                            $_POST["last_error"] ??
                            "Penghantaran manual gagal."
                        )
                    )
                    : null;

            $sentAtSql =
                $newStatus === "SENT"
                    ? "NOW()"
                    : "NULL";

            $stmt = $conn->prepare(
                "
                UPDATE cpms_notification_deliveries
                SET
                    delivery_status = ?,
                    attempt_count =
                        attempt_count + 1,
                    last_error = ?,
                    sent_at = {$sentAtSql}
                WHERE id = ?
                  AND property_id = ?
                "
            );

            if (!$stmt) {
                $error =
                    "Database prepare error: " .
                    $conn->error;
            } else {
                $stmt->bind_param(
                    "ssii",
                    $newStatus,
                    $lastError,
                    $deliveryId,
                    $propertyId
                );

                if (!$stmt->execute()) {
                    $error =
                        "Database execute error: " .
                        $stmt->error;
                } elseif ($stmt->affected_rows < 1) {
                    $error =
                        "Rekod penghantaran tidak dijumpai.";
                } else {
                    cpmsAuditAdmin(
                        $conn,
                        $propertyId,
                        "Notification Delivery",
                        $newStatus,
                        $deliveryId,
                        (string) $deliveryId,
                        "Delivery status updated manually.",
                        null,
                        [
                            "delivery_status" => $newStatus,
                            "last_error" => $lastError
                        ]
                    );

                    $message =
                        "Status penghantaran berjaya dikemas kini.";
                }

                $stmt->close();
            }
        }
    }
}

$stmt = $conn->prepare(
    "
    SELECT
        id,
        property_id,
        channel_name,
        recipient_name,
        recipient_address,
        subject_line,
        message_body,
        reference_no,
        delivery_status,
        attempt_count,
        last_error,
        sent_at,
        created_at
    FROM cpms_notification_deliveries
    WHERE id = ?
      AND property_id = ?
    LIMIT 1
    "
);

$delivery = null;

if ($stmt) {
    $stmt->bind_param(
        "ii",
        $deliveryId,
        $propertyId
    );

    $stmt->execute();

    $delivery =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();
}

if (!$delivery) {
    $conn->close();
    http_response_code(404);
    exit("Rekod penghantaran tidak dijumpai.");
}

$channel =
    strtoupper(
        (string) $delivery["channel_name"]
    );

$recipient =
    (string) $delivery["recipient_address"];

$messageBody =
    (string) $delivery["message_body"];

$subject =
    (string) (
        $delivery["subject_line"] ??
        "CPMS Notification"
    );

$manualUrl = "";

if ($channel === "WHATSAPP") {
    $phone =
        preg_replace(
            '/\D+/',
            '',
            $recipient
        ) ?? "";

    $manualUrl =
        "https://wa.me/" .
        rawurlencode($phone) .
        "?text=" .
        rawurlencode($messageBody);

} elseif ($channel === "EMAIL") {
    $manualUrl =
        "mailto:" .
        rawurlencode($recipient) .
        "?subject=" .
        rawurlencode($subject) .
        "&body=" .
        rawurlencode($messageBody);
}

$conn->close();

$propertyName =
    (string) (
        $currentProperty["name"] ??
        "Property"
    );

$primaryColor =
    (string) (
        $currentProperty["primary_color"] ??
        "#3a2419"
    );

$secondaryColor =
    (string) (
        $currentProperty["secondary_color"] ??
        "#b59b20"
    );

?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manual Delivery Processor | <?php echo e($propertyName); ?></title>

<style>
:root{
    --primary:<?php echo e($primaryColor); ?>;
    --secondary:<?php echo e($secondaryColor); ?>;
}
*{box-sizing:border-box}
body{margin:0;background:#f6f2ea;font-family:Arial,sans-serif;color:#333}
.page{width:min(860px,calc(100% - 24px));margin:28px auto}
.header,.card{padding:22px;border:1px solid #e5ddd2;border-radius:15px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.06)}
.header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;border-top:6px solid var(--secondary)}
.header h1{margin:0 0 5px;color:var(--primary)}
.header p{margin:0;color:#777}
.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border:0;border-radius:8px;background:var(--primary);color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
.button.gold{background:var(--secondary)}
.button.danger{background:#a52d2d}
.button.light{background:#777}
.alert{margin-bottom:15px;padding:13px;border-radius:9px;font-weight:700}
.ok{background:#edf9f1;color:#176b36}
.err{background:#fff0f0;color:#9f2626}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.field{padding:14px;border:1px solid #e7e7e7;border-radius:10px;background:#fafafa}
.field span{display:block;margin-bottom:5px;color:#777;font-size:12px;font-weight:700}
.full{grid-column:1/-1}
pre{margin:0;overflow:auto;padding:14px;border-radius:9px;background:#f3f3f3;white-space:pre-wrap;word-break:break-word;line-height:1.6}
.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}
.fail-form{display:grid;grid-template-columns:1fr auto;gap:8px;width:100%;margin-top:10px}
.fail-form input{padding:10px;border:1px solid #d8d1c8;border-radius:8px;font:inherit}
@media(max-width:700px){.header{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}.full{grid-column:auto}.fail-form{grid-template-columns:1fr}}
</style>
</head>

<body>
<main class="page">

<header class="header">
    <div>
        <h1>Manual Delivery Processor</h1>
        <p><?php echo e($propertyName); ?></p>
    </div>

    <a href="<?php echo e($returnUrl); ?>" class="button">
        ← Kembali
    </a>
</header>

<?php if ($message !== ""): ?>
    <div class="alert ok"><?php echo e($message); ?></div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert err"><?php echo e($error); ?></div>
<?php endif; ?>

<section class="card">

<div class="grid">

    <div class="field">
        <span>ID</span>
        <strong><?php echo (int) $delivery["id"]; ?></strong>
    </div>

    <div class="field">
        <span>Status</span>
        <strong><?php echo e((string) $delivery["delivery_status"]); ?></strong>
    </div>

    <div class="field">
        <span>Saluran</span>
        <strong><?php echo e($channel); ?></strong>
    </div>

    <div class="field">
        <span>Rujukan</span>
        <strong><?php echo e((string) ($delivery["reference_no"] ?? "-")); ?></strong>
    </div>

    <div class="field full">
        <span>Penerima</span>
        <strong>
            <?php echo e((string) ($delivery["recipient_name"] ?? "-")); ?>
            ·
            <?php echo e($recipient); ?>
        </strong>
    </div>

    <?php if (!empty($delivery["subject_line"])): ?>
        <div class="field full">
            <span>Subjek</span>
            <strong><?php echo e($subject); ?></strong>
        </div>
    <?php endif; ?>

    <div class="field full">
        <span>Mesej</span>
        <pre><?php echo e($messageBody); ?></pre>
    </div>

    <div class="field">
        <span>Jumlah Percubaan</span>
        <strong><?php echo (int) $delivery["attempt_count"]; ?></strong>
    </div>

    <div class="field">
        <span>Dicipta</span>
        <strong>
            <?php
            echo e(
                date(
                    "d/m/Y h:i A",
                    strtotime((string) $delivery["created_at"])
                )
            );
            ?>
        </strong>
    </div>

</div>

<div class="actions">

    <?php if ($manualUrl !== ""): ?>
        <a
            href="<?php echo e($manualUrl); ?>"
            class="button gold"
            target="_blank"
            rel="noopener noreferrer"
        >
            <?php
            echo
                $channel === "WHATSAPP"
                    ? "Buka WhatsApp"
                    : "Buka Aplikasi Email";
            ?>
        </a>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $delivery["id"]; ?>">
        <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
        <input type="hidden" name="action" value="mark_sent">

        <button type="submit" class="button">
            Tanda SENT
        </button>
    </form>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $delivery["id"]; ?>">
        <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
        <input type="hidden" name="action" value="reset_pending">

        <button type="submit" class="button light">
            Reset PENDING
        </button>
    </form>

</div>

<form method="POST" class="fail-form">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo (int) $delivery["id"]; ?>">
    <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
    <input type="hidden" name="action" value="mark_failed">

    <input
        type="text"
        name="last_error"
        placeholder="Sebab penghantaran gagal"
        required
    >

    <button type="submit" class="button danger">
        Tanda FAILED
    </button>
</form>

</section>
</main>
</body>
</html>
