<?php
declare(strict_types=1);

function createNotification(
    mysqli $conn,
    int $propertyId,
    string $category,
    string $priority,
    string $title,
    ?string $message = null,
    ?string $module = null,
    ?string $referenceType = null,
    string|int|null $referenceId = null,
    string $targetRole = 'All',
    ?int $targetUserId = null,
    ?string $actionUrl = null,
    ?string $createdByRole = null,
    ?int $createdByUserId = null,
    ?string $expiresAt = null
): int {
    $allowed = ['Critical','High','Medium','Low'];
    if (!in_array($priority, $allowed, true)) $priority = 'Medium';
    if ($propertyId < 1 || trim($title) === '') {
        throw new InvalidArgumentException('Data notifikasi tidak lengkap.');
    }

    $referenceId = $referenceId === null ? null : (string)$referenceId;

    $stmt = $conn->prepare(
        "INSERT INTO notifications
        (property_id,category,priority,title,message,module,reference_type,
         reference_id,target_role,target_user_id,action_url,
         created_by_role,created_by_user_id,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    if (!$stmt) throw new RuntimeException($conn->error);

    $stmt->bind_param(
        "issssssssissis",
        $propertyId,$category,$priority,$title,$message,$module,
        $referenceType,$referenceId,$targetRole,$targetUserId,$actionUrl,
        $createdByRole,$createdByUserId,$expiresAt
    );

    if (!$stmt->execute()) {
        $e = $stmt->error;
        $stmt->close();
        throw new RuntimeException($e);
    }
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getUnreadNotificationCount(
    mysqli $conn,int $propertyId,string $role,int $userId
): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total
         FROM notifications n
         LEFT JOIN notification_reads r
          ON r.notification_id=n.id AND r.user_role=? AND r.user_id=?
         LEFT JOIN notification_archives a
          ON a.notification_id=n.id AND a.user_role=? AND a.user_id=?
         WHERE n.property_id=?
          AND (n.target_role='All' OR n.target_role=?)
          AND (n.target_user_id IS NULL OR n.target_user_id=?)
          AND r.id IS NULL AND a.id IS NULL
          AND (n.expires_at IS NULL OR n.expires_at>NOW())"
    );
    $stmt->bind_param("sisisii",$role,$userId,$role,$userId,$propertyId,$role,$userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function markNotificationRead(
    mysqli $conn,int $id,string $role,int $userId
): bool {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO notification_reads
         (notification_id,user_role,user_id,read_at)
         VALUES (?,?,?,NOW())"
    );
    $stmt->bind_param("isi",$id,$role,$userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function archiveNotification(
    mysqli $conn,int $id,string $role,int $userId
): bool {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO notification_archives
         (notification_id,user_role,user_id,archived_at)
         VALUES (?,?,?,NOW())"
    );
    $stmt->bind_param("isi",$id,$role,$userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function notificationIcon(string $category): string {
    return match(strtolower($category)) {
        'complaint'=>'📢','work order','work_order'=>'🔧',
        'patrol'=>'🛡️','asset'=>'📦','maintenance'=>'🗓️',
        'resident'=>'👤','staff'=>'👷','announcement'=>'📣',
        default=>'🔔'
    };
}

function notificationTimeAgo(string $dt): string {
    $t = strtotime($dt);
    if (!$t) return '';
    $s = time()-$t;
    if ($s<60) return 'Baru sahaja';
    if ($s<3600) return floor($s/60).' minit lalu';
    if ($s<86400) return floor($s/3600).' jam lalu';
    if ($s<604800) return floor($s/86400).' hari lalu';
    return date('d/m/Y H:i',$t);
}
