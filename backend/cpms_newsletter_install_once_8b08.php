<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$dbFile = $root . '/cpms/db.php';
$sidebarFile = $root . '/cpms/property_portal/includes/layout_sidebar.php';
$newsletterFile = $root . '/cpms/property_portal/newsletter.php';
$cssFile = $root . '/cpms/property_portal/assets/newsletter-manager.css';

if (!is_file($dbFile) || !is_file($sidebarFile) || !is_file($newsletterFile) || !is_file($cssFile)) {
    http_response_code(500);
    exit("INSTALL FAILED\nRequired CPMS Newsletter files are missing. Extract the ZIP into public_html first.\n");
}

require_once $dbFile;
if (!isset($conn) || !$conn instanceof mysqli) {
    http_response_code(500);
    exit("INSTALL FAILED\nDatabase connection was not available.\n");
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS cpms_newsletters (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            property_id INT UNSIGNED NOT NULL,
            newsletter_year SMALLINT UNSIGNED NOT NULL,
            newsletter_month TINYINT UNSIGNED NOT NULL,
            title VARCHAR(220) NOT NULL,
            manager_message TEXT NULL,
            highlights MEDIUMTEXT NULL,
            upcoming_activities TEXT NULL,
            statistics_json LONGTEXT NULL,
            status ENUM('draft','review','published','archived') NOT NULL DEFAULT 'draft',
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            published_by INT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cpms_newsletter_property_month (property_id,newsletter_year,newsletter_month),
            KEY idx_cpms_newsletter_property_status (property_id,status),
            KEY idx_cpms_newsletter_published (property_id,published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS cpms_newsletter_sections (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            newsletter_id INT UNSIGNED NOT NULL,
            property_id INT UNSIGNED NOT NULL,
            section_key VARCHAR(80) NOT NULL,
            heading VARCHAR(220) NULL,
            content MEDIUMTEXT NULL,
            source_mode ENUM('manual','auto') NOT NULL DEFAULT 'manual',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cpms_newsletter_sections (property_id,newsletter_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS cpms_newsletter_media (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            newsletter_id INT UNSIGNED NOT NULL,
            property_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(80) NULL,
            caption VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            uploaded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cpms_newsletter_media (property_id,newsletter_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit("INSTALL FAILED\nDatabase tables could not be created.\n");
}

$sidebar = file_get_contents($sidebarFile);
if (!is_string($sidebar)) {
    http_response_code(500);
    exit("INSTALL FAILED\nSidebar could not be read.\n");
}

$patched = false;
if (strpos($sidebar, 'href="newsletter.php"') === false) {
    $menuNeedle = "        <?php if (\n            cpmsModuleEnabled('visitor_management')";
    $menuBlock = <<<'PHP'
        <?php if (
            cpmsCan('reports.view', $conn)
            || cpmsCan('resident.announcement.manage', $conn)
            || cpmsCan('settings.manage', $conn)
        ): ?>
            <a href="newsletter.php"
               class="sidebar-link <?php echo $activeMenu === 'newsletter' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('newsletter'); ?></span>
                <span>Monthly Newsletter</span>
            </a>
        <?php endif; ?>

PHP;
    if (strpos($sidebar, $menuNeedle) === false) {
        http_response_code(500);
        exit("INSTALL FAILED\nCurrent sidebar structure does not match the verified dashboard version. No sidebar change was made.\n");
    }
    $sidebar = str_replace($menuNeedle, $menuBlock . $menuNeedle, $sidebar, $count);
    if ($count !== 1) {
        http_response_code(500);
        exit("INSTALL FAILED\nSidebar menu insertion was not unique. No sidebar change was made.\n");
    }

    $iconNeedle = "        'access' => '<svg viewBox=\"0 0 24 24\"";
    $icon = "        'newsletter' => '<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M4 3h16v18H4V3Zm2 2v14h12V5H6Zm2 2h8v2H8V7Zm0 4h8v2H8v-2Zm0 4h5v2H8v-2Z\"/></svg>',\n";
    if (strpos($sidebar, $iconNeedle) === false) {
        http_response_code(500);
        exit("INSTALL FAILED\nSidebar icon insertion point was not found. No sidebar change was made.\n");
    }
    $sidebar = str_replace($iconNeedle, $icon . $iconNeedle, $sidebar, $count);
    if ($count !== 1) {
        http_response_code(500);
        exit("INSTALL FAILED\nSidebar icon insertion was not unique. No sidebar change was made.\n");
    }
    $patched = true;
}

$backupMade = false;
if ($patched) {
    $home = dirname($root);
    $backupDir = $home . '/cpmspro_backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) {
        http_response_code(500);
        exit("INSTALL FAILED\nBackup directory could not be created. No sidebar change was written.\n");
    }
    $backup = $backupDir . '/layout_sidebar_before_newsletter_'
        . date('Ymd_His') . '.php';
    if (!copy($sidebarFile, $backup)) {
        http_response_code(500);
        exit("INSTALL FAILED\nSidebar backup failed. No sidebar change was written.\n");
    }
    $backupMade = true;
    $temp = $sidebarFile . '.newsletter.tmp';
    if (file_put_contents($temp, $sidebar, LOCK_EX) === false || !rename($temp, $sidebarFile)) {
        @unlink($temp);
        http_response_code(500);
        exit("INSTALL FAILED\nSidebar could not be updated. Backup remains available.\n");
    }
}

$selfDeleted = @unlink(__FILE__);

echo "CPMSPRO MONTHLY NEWSLETTER - PHASE 1 INSTALLED\n";
echo "Database tables: READY\n";
echo "Property isolation by property_id: READY\n";
echo "Newsletter Manager: READY\n";
echo "Photo upload: READY\n";
echo "Auto monthly statistics: READY\n";
echo "Sidebar menu: " . ($patched ? 'ADDED' : 'ALREADY PRESENT') . "\n";
echo "Backup outside public_html: " . ($backupMade ? 'YES' : 'NOT REQUIRED') . "\n";
echo "Temporary installer deleted: " . ($selfDeleted ? 'YES' : 'NO') . "\n";
echo "\nOpen: /cpms/property_portal/newsletter.php\n";
