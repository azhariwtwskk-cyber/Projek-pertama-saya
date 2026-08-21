<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/notification_engine.php';

function notification_bell_user(): array
{
    if (!empty($_SESSION['admin_id'])) {
        return ['role' => 'admin', 'user_id' => (int)$_SESSION['admin_id']];
    }

    if (!empty($_SESSION['staff_id'])) {
        return ['role' => 'staff', 'user_id' => (int)$_SESSION['staff_id']];
    }

    if (!empty($_SESSION['security_guard_id'])) {
        return ['role' => 'security', 'user_id' => (int)$_SESSION['security_guard_id']];
    }

    if (!empty($_SESSION['resident_id'])) {
        return ['role' => 'resident', 'user_id' => (int)$_SESSION['resident_id']];
    }

    return ['role' => null, 'user_id' => 0];
}

function notification_bell_render(mysqli $conn, array $options = []): void
{
    $user = notification_bell_user();

    if (!$user['role'] || $user['user_id'] <= 0) {
        return;
    }

    $propertyId = notification_current_property_id($conn);
    $unreadCount = notification_unread_count(
        $conn,
        $propertyId,
        $user['role'],
        $user['user_id']
    );

    $centerUrl = (string)($options['center_url'] ?? 'notification_center.php');
    $apiUrl = (string)($options['api_url'] ?? 'api/notification_bell_api.php');
    $label = (string)($options['label'] ?? 'Notifications');
    ?>
    <div class="cpms-notification-bell"
         data-api-url="<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>"
         data-center-url="<?= htmlspecialchars($centerUrl, ENT_QUOTES, 'UTF-8') ?>">

        <button type="button"
                class="cpms-notification-bell__button"
                aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                aria-expanded="false">
            <span class="cpms-notification-bell__icon" aria-hidden="true">🔔</span>
            <span class="cpms-notification-bell__badge<?= $unreadCount <= 0 ? ' is-hidden' : '' ?>">
                <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
            </span>
        </button>

        <div class="cpms-notification-bell__dropdown" hidden>
            <div class="cpms-notification-bell__header">
                <strong>Notifications</strong>
                <span class="cpms-notification-bell__status">
                    <?= $unreadCount ?> belum dibaca
                </span>
            </div>

            <div class="cpms-notification-bell__list">
                <div class="cpms-notification-bell__loading">
                    Memuatkan notification...
                </div>
            </div>

            <a class="cpms-notification-bell__footer"
               href="<?= htmlspecialchars($centerUrl, ENT_QUOTES, 'UTF-8') ?>">
                Lihat semua notification
            </a>
        </div>
    </div>
    <?php
}
