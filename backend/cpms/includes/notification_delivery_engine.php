<?php

declare(strict_types=1);

function cpmsQueueNotificationDelivery(
    mysqli $conn,
    int $propertyId,
    string $channelName,
    string $recipientAddress,
    string $messageBody,
    ?string $recipientName = null,
    ?string $subjectLine = null,
    ?string $referenceNo = null,
    ?int $notificationId = null
): bool {
    $channelName = strtoupper(trim($channelName));
    $recipientAddress = trim($recipientAddress);
    $messageBody = trim($messageBody);

    if (
        $propertyId < 1 ||
        $channelName === "" ||
        $recipientAddress === "" ||
        $messageBody === ""
    ) {
        return false;
    }

    $allowedChannels = [
        "EMAIL",
        "WHATSAPP",
        "SMS",
        "PUSH"
    ];

    if (!in_array($channelName, $allowedChannels, true)) {
        return false;
    }

    $stmt = $conn->prepare(
        "
        INSERT INTO cpms_notification_deliveries
        (
            property_id,
            notification_id,
            channel_name,
            recipient_name,
            recipient_address,
            subject_line,
            message_body,
            reference_no
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?
        )
        "
    );

    if (!$stmt) {
        error_log(
            "CPMS delivery queue prepare failed: " .
            $conn->error
        );

        return false;
    }

    $stmt->bind_param(
        "iissssss",
        $propertyId,
        $notificationId,
        $channelName,
        $recipientName,
        $recipientAddress,
        $subjectLine,
        $messageBody,
        $referenceNo
    );

    $success = $stmt->execute();

    if (!$success) {
        error_log(
            "CPMS delivery queue execute failed: " .
            $stmt->error
        );
    }

    $stmt->close();

    return $success;
}

function cpmsQueueWhatsApp(
    mysqli $conn,
    int $propertyId,
    string $phoneNumber,
    string $messageBody,
    ?string $recipientName = null,
    ?string $referenceNo = null,
    ?int $notificationId = null
): bool {
    $digits = preg_replace('/\D+/', '', $phoneNumber) ?? "";

    if ($digits === "") {
        return false;
    }

    if (str_starts_with($digits, "0")) {
        $digits = "60" . substr($digits, 1);
    } elseif (!str_starts_with($digits, "60")) {
        $digits = "60" . $digits;
    }

    return cpmsQueueNotificationDelivery(
        $conn,
        $propertyId,
        "WHATSAPP",
        $digits,
        $messageBody,
        $recipientName,
        null,
        $referenceNo,
        $notificationId
    );
}

function cpmsQueueEmail(
    mysqli $conn,
    int $propertyId,
    string $emailAddress,
    string $subjectLine,
    string $messageBody,
    ?string $recipientName = null,
    ?string $referenceNo = null,
    ?int $notificationId = null
): bool {
    if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    return cpmsQueueNotificationDelivery(
        $conn,
        $propertyId,
        "EMAIL",
        $emailAddress,
        $messageBody,
        $recipientName,
        $subjectLine,
        $referenceNo,
        $notificationId
    );
}

function cpmsDeliveryStatusClass(string $status): string
{
    return match (strtoupper(trim($status))) {
        "SENT" => "sent",
        "FAILED" => "failed",
        "CANCELLED" => "cancelled",
        default => "pending"
    };
}
