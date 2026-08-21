(function () {
    'use strict';

    var installPrompt = null;
    var installButton = null;
    var pushButton = null;
    var pushCsrf = '';

    function appName() {
        var app = document.body
            ? document.body.getAttribute('data-cpms-pwa')
            : '';
        return app === 'security' ? 'CPMS Security' : 'CPMS Staff';
    }

    function createStatus() {
        var status = document.createElement('div');
        status.id = 'cpmsNetworkStatus';
        status.className = 'cpms-network-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        document.body.appendChild(status);
        return status;
    }

    function updateNetworkStatus() {
        var status = document.getElementById('cpmsNetworkStatus')
            || createStatus();

        if (navigator.onLine) {
            status.textContent = 'Sambungan internet dipulihkan.';
            status.className =
                'cpms-network-status is-online is-visible';
            window.setTimeout(function () {
                status.classList.remove('is-visible');
            }, 2200);
        } else {
            status.textContent =
                'Anda sedang offline. Data baharu belum boleh dihantar.';
            status.className =
                'cpms-network-status is-offline is-visible';
        }
    }

    function createInstallButton() {
        if (installButton) {
            return installButton;
        }

        installButton = document.createElement('button');
        installButton.type = 'button';
        installButton.id = 'cpmsInstallApp';
        installButton.className = 'cpms-install-app';
        installButton.textContent = 'Pasang ' + appName();
        installButton.hidden = true;
        installButton.addEventListener('click', function () {
            if (!installPrompt) {
                return;
            }

            installPrompt.prompt();
            installPrompt.userChoice.finally(function () {
                installPrompt = null;
                installButton.hidden = true;
            });
        });
        document.body.appendChild(installButton);
        return installButton;
    }

    function normalizeImageInputs() {
        var inputs = document.querySelectorAll('input[type="file"]');

        Array.prototype.forEach.call(inputs, function (input) {
            if (!input.getAttribute('accept')) {
                input.setAttribute('accept', 'image/*');
            }

            /*
             * Allow both Camera and Gallery unless a future field is
             * explicitly marked data-camera-only="1".
             */
            if (input.getAttribute('data-camera-only') !== '1') {
                input.removeAttribute('capture');
            }
        });
    }

    function base64ToUint8Array(value) {
        var padding = '='.repeat((4 - value.length % 4) % 4);
        var base64 = (value + padding)
            .replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        return Uint8Array.from(raw, function (character) {
            return character.charCodeAt(0);
        });
    }

    function updatePushButton() {
        if (!pushButton || !('serviceWorker' in navigator)) {
            return;
        }
        navigator.serviceWorker.ready
            .then(function (registration) {
                return registration.pushManager.getSubscription();
            })
            .then(function (subscription) {
                pushButton.textContent = subscription
                    ? 'Notifikasi Aktif'
                    : 'Aktifkan Notifikasi';
                pushButton.classList.toggle('is-active', Boolean(subscription));
            });
    }

    function createPushButton() {
        if (pushButton || !('PushManager' in window)
            || !('Notification' in window)) {
            return pushButton;
        }
        pushButton = document.createElement('button');
        pushButton.type = 'button';
        pushButton.id = 'cpmsPushButton';
        pushButton.className = 'cpms-push-button';
        pushButton.textContent = 'Aktifkan Notifikasi';
        pushButton.addEventListener('click', togglePush);
        document.body.appendChild(pushButton);
        updatePushButton();
        return pushButton;
    }

    function togglePush() {
        pushButton.disabled = true;
        navigator.serviceWorker.ready
            .then(function (registration) {
                return registration.pushManager.getSubscription()
                    .then(function (existing) {
                        if (existing) {
                            return fetch('./pwa_push_public_key.php', {
                                cache: 'no-store',
                                credentials: 'same-origin'
                            }).then(function (response) {
                                return response.json();
                            }).then(function (config) {
                                pushCsrf = config.csrf || '';
                                return fetch('./pwa_push_unsubscribe.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CPMS-CSRF': pushCsrf
                                    },
                                    body: JSON.stringify({
                                        endpoint: existing.endpoint
                                    })
                                }).then(function () {
                                    return existing.unsubscribe();
                                });
                            });
                        }
                        return Notification.requestPermission()
                            .then(function (permission) {
                                if (permission !== 'granted') {
                                    throw new Error('permission_denied');
                                }
                                return fetch('./pwa_push_public_key.php', {
                                    cache: 'no-store',
                                    credentials: 'same-origin'
                                });
                            })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('push_not_ready');
                                }
                                return response.json();
                            })
                            .then(function (config) {
                                pushCsrf = config.csrf || '';
                                return registration.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey:
                                        base64ToUint8Array(config.publicKey)
                                });
                            })
                            .then(function (subscription) {
                                var json = subscription.toJSON();
                                return fetch('./pwa_push_subscribe.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CPMS-CSRF': pushCsrf
                                    },
                                    body: JSON.stringify(json)
                                });
                            });
                    });
            })
            .catch(function () {
                window.alert(
                    'Notifikasi tidak dapat diaktifkan. Semak tetapan '
                    + 'notification browser dan halaman Push Health.'
                );
            })
            .finally(function () {
                pushButton.disabled = false;
                updatePushButton();
            });
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
        createInstallButton().hidden = false;
    });

    window.addEventListener('appinstalled', function () {
        installPrompt = null;
        if (installButton) {
            installButton.hidden = true;
        }
    });

    window.addEventListener('online', updateNetworkStatus);
    window.addEventListener('offline', updateNetworkStatus);

    document.addEventListener('DOMContentLoaded', function () {
        createInstallButton();
        normalizeImageInputs();

        if (!navigator.onLine) {
            updateNetworkStatus();
        }

        if ('serviceWorker' in navigator && window.isSecureContext) {
            navigator.serviceWorker.register(
                './cpms-mobile-sw.js',
                {scope: './'}
            ).then(function () {
                createPushButton();
            }).catch(function () {
                /* pwa_health.php is used for visible diagnostics. */
            });
        }
    });
}());
