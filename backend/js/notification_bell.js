/* CPMS Notification Bell — Langkah 5.8B-1 */

(function () {
    'use strict';

    const iconMap = {
        complaint: '📝',
        work_order: '🛠️',
        patrol: '🛡️',
        asset: '🏢',
        maintenance: '📅',
        system: '⚙️'
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function safeUrl(value, fallback) {
        const url = String(value || '').trim();

        if (!url) {
            return fallback;
        }

        if (
            url.startsWith('javascript:')
            || url.startsWith('data:')
            || url.startsWith('vbscript:')
        ) {
            return fallback;
        }

        return url;
    }

    function renderNotifications(root, data) {
        const badge = root.querySelector('.cpms-notification-bell__badge');
        const status = root.querySelector('.cpms-notification-bell__status');
        const list = root.querySelector('.cpms-notification-bell__list');
        const centerUrl = root.dataset.centerUrl || 'notification_center.php';
        const unread = Number(data.unread_count || 0);

        badge.textContent = unread > 99 ? '99+' : String(unread);
        badge.classList.toggle('is-hidden', unread <= 0);
        status.textContent = unread + ' belum dibaca';

        if (!Array.isArray(data.notifications) || data.notifications.length === 0) {
            list.innerHTML = '<div class="cpms-notification-bell__empty">Tiada notification.</div>';
            return;
        }

        list.innerHTML = data.notifications.map(function (item) {
            const icon = item.icon || iconMap[item.type] || '🔔';
            const itemUrl = safeUrl(item.action_url, centerUrl);
            const readClass = item.is_read ? '' : ' is-unread';
            const priority = ['info', 'warning', 'critical'].includes(item.priority)
                ? item.priority
                : 'info';

            return `
                <a class="cpms-notification-bell__item${readClass}"
                   href="${escapeHtml(itemUrl)}">
                    <span class="cpms-notification-bell__item-icon">
                        ${escapeHtml(icon)}
                    </span>

                    <span class="cpms-notification-bell__item-content">
                        <span class="cpms-notification-bell__item-title">
                            ${escapeHtml(item.title)}
                        </span>

                        <span class="cpms-notification-bell__item-message">
                            ${escapeHtml(item.message)}
                        </span>

                        <span class="cpms-notification-bell__item-meta">
                            <span class="cpms-notification-bell__priority cpms-notification-bell__priority--${priority}">
                                ${escapeHtml(priority)}
                            </span>
                            <span>${escapeHtml(item.created_label)}</span>
                        </span>
                    </span>
                </a>
            `;
        }).join('');
    }

    async function loadNotifications(root) {
        const apiUrl = root.dataset.apiUrl || 'api/notification_bell_api.php';
        const list = root.querySelector('.cpms-notification-bell__list');

        list.innerHTML = '<div class="cpms-notification-bell__loading">Memuatkan notification...</div>';

        try {
            const response = await fetch(apiUrl + '?limit=8', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Gagal memuatkan notification.');
            }

            renderNotifications(root, data);
        } catch (error) {
            list.innerHTML = '<div class="cpms-notification-bell__error">'
                + escapeHtml(error.message)
                + '</div>';
        }
    }

    function closeOtherDropdowns(currentRoot) {
        document.querySelectorAll('.cpms-notification-bell').forEach(function (root) {
            if (root === currentRoot) return;

            const dropdown = root.querySelector('.cpms-notification-bell__dropdown');
            const button = root.querySelector('.cpms-notification-bell__button');

            dropdown.hidden = true;
            button.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.cpms-notification-bell').forEach(function (root) {
            const button = root.querySelector('.cpms-notification-bell__button');
            const dropdown = root.querySelector('.cpms-notification-bell__dropdown');

            button.addEventListener('click', function (event) {
                event.stopPropagation();

                closeOtherDropdowns(root);

                const willOpen = dropdown.hidden;
                dropdown.hidden = !willOpen;
                button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

                if (willOpen) {
                    loadNotifications(root);
                }
            });
        });

        document.addEventListener('click', function (event) {
            document.querySelectorAll('.cpms-notification-bell').forEach(function (root) {
                if (root.contains(event.target)) return;

                const dropdown = root.querySelector('.cpms-notification-bell__dropdown');
                const button = root.querySelector('.cpms-notification-bell__button');

                dropdown.hidden = true;
                button.setAttribute('aria-expanded', 'false');
            });
        });
    });
})();
