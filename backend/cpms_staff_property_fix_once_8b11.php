<?php
declare(strict_types=1);

/*
 * CPMS v3.6.3 - Staff Property Context, Review & Report Selection Fix
 * Upload to public_html and run once:
 * https://cpmspro.my/cpms_staff_property_fix_once_8b11.php
 */

session_start();
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit("Database connection unavailable.\n");
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function runSql(mysqli $conn, string $sql, string $label): void
{
    if (!$conn->query($sql)) {
        throw new RuntimeException($label . ': ' . $conn->error);
    }
    echo "[OK] {$label}\n";
}

try {
    if (!tableExists($conn, 'daily_work_logs')) {
        throw new RuntimeException('Table daily_work_logs not found.');
    }

    if (!columnExists($conn, 'daily_work_logs', 'property_id')) {
        runSql(
            $conn,
            "ALTER TABLE daily_work_logs
             ADD COLUMN property_id INT UNSIGNED NULL AFTER staff_id,
             ADD KEY idx_daily_work_property_staff
                (property_id, staff_id, work_date)",
            'daily_work_logs.property_id added'
        );
    } else {
        echo "[SKIP] daily_work_logs.property_id already exists\n";
    }

    if (!columnExists($conn, 'daily_work_logs', 'include_in_newsletter')) {
        runSql(
            $conn,
            "ALTER TABLE daily_work_logs
             ADD COLUMN include_in_newsletter TINYINT(1) NOT NULL DEFAULT 0
             AFTER work_status",
            'daily_work_logs.include_in_newsletter added'
        );
    } else {
        echo "[SKIP] daily_work_logs.include_in_newsletter already exists\n";
    }

    if (!columnExists($conn, 'daily_work_logs', 'include_in_monthly_report')) {
        runSql(
            $conn,
            "ALTER TABLE daily_work_logs
             ADD COLUMN include_in_monthly_report TINYINT(1) NOT NULL DEFAULT 0
             AFTER include_in_newsletter",
            'daily_work_logs.include_in_monthly_report added'
        );
    } else {
        echo "[SKIP] daily_work_logs.include_in_monthly_report already exists\n";
    }

    if (!columnExists($conn, 'daily_work_logs', 'supervisor_remarks')) {
        runSql(
            $conn,
            "ALTER TABLE daily_work_logs
             ADD COLUMN supervisor_remarks TEXT NULL
             AFTER include_in_monthly_report",
            'daily_work_logs.supervisor_remarks added'
        );
    } else {
        echo "[SKIP] daily_work_logs.supervisor_remarks already exists\n";
    }

    if (!columnExists($conn, 'daily_work_logs', 'verified_by')) {
        runSql(
            $conn,
            "ALTER TABLE daily_work_logs
             ADD COLUMN verified_by VARCHAR(150) NULL
             AFTER supervisor_remarks",
            'daily_work_logs.verified_by added'
        );
    } else {
        echo "[SKIP] daily_work_logs.verified_by already exists\n";
    }

    if (!columnExists($conn, 'daily_work_logs', 'verified_at')) {
        runSql(
            $conn,
            "ALTER TABLE daily_work_logs
             ADD COLUMN verified_at DATETIME NULL
             AFTER verified_by",
            'daily_work_logs.verified_at added'
        );
    } else {
        echo "[SKIP] daily_work_logs.verified_at already exists\n";
    }

    if (tableExists($conn, 'staff')
        && columnExists($conn, 'staff', 'property_id')) {
        runSql(
            $conn,
            "UPDATE daily_work_logs d
             JOIN staff s ON s.id = d.staff_id
             SET d.property_id = s.property_id
             WHERE (d.property_id IS NULL OR d.property_id = 0)
               AND s.property_id IS NOT NULL
               AND s.property_id > 0",
            'daily_work_logs.property_id backfilled from staff'
        );
    }

    if (tableExists($conn, 'work_orders')
        && columnExists($conn, 'work_orders', 'property_id')) {
        runSql(
            $conn,
            "UPDATE daily_work_logs d
             JOIN work_orders w ON w.id = d.work_order_id
             SET d.property_id = w.property_id
             WHERE (d.property_id IS NULL OR d.property_id = 0)
               AND w.property_id IS NOT NULL
               AND w.property_id > 0",
            'daily_work_logs.property_id backfilled from work_orders'
        );
    }

    if (tableExists($conn, 'daily_work_images')) {
        if (!columnExists($conn, 'daily_work_images', 'property_id')) {
            runSql(
                $conn,
                "ALTER TABLE daily_work_images
                 ADD COLUMN property_id INT UNSIGNED NULL AFTER daily_work_id,
                 ADD KEY idx_daily_work_images_property
                    (property_id, daily_work_id)",
                'daily_work_images.property_id added'
            );
        } else {
            echo "[SKIP] daily_work_images.property_id already exists\n";
        }

        if (!columnExists($conn, 'daily_work_images', 'image_path')) {
            runSql(
                $conn,
                "ALTER TABLE daily_work_images
                 ADD COLUMN image_path VARCHAR(500) NULL AFTER image_name",
                'daily_work_images.image_path added'
            );
        } else {
            echo "[SKIP] daily_work_images.image_path already exists\n";
        }

        runSql(
            $conn,
            "UPDATE daily_work_images i
             JOIN daily_work_logs d ON d.id = i.daily_work_id
             SET i.property_id = d.property_id
             WHERE (i.property_id IS NULL OR i.property_id = 0)
               AND d.property_id IS NOT NULL
               AND d.property_id > 0",
            'daily_work_images.property_id backfilled'
        );

        runSql(
            $conn,
            "UPDATE daily_work_images
             SET image_path = CONCAT(
                'uploads/daily_work/property_',
                property_id,
                '/',
                image_name
             )
             WHERE (image_path IS NULL OR image_path = '')
               AND property_id IS NOT NULL
               AND property_id > 0
               AND image_name IS NOT NULL
               AND image_name <> ''",
            'daily_work_images.image_path prepared'
        );
    }

    echo "\nDONE: Staff property daily work fix completed.\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo "[ERROR] " . $error->getMessage() . "\n";
}
