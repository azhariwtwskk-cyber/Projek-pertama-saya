/* CPMS v3.4.5 — shared Staff & Security service worker. */
'use strict';

const STATIC_CACHE = 'cpms-mobile-v345-static';
const OFFLINE_URL = './cpms-mobile-offline.html';
const STATIC_FILES = [
    OFFLINE_URL,
    './pwa/cpms-mobile.css?v=345',
    './pwa/cpms-mobile.js?v=345',
    './pwa/cpms-evidence.js?v=344',
    './pwa/cpms-attendance.js?v=345',
    './pwa/icons/icon-192.png',
    './pwa/icons/icon-512.png',
    './pwa/icons/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_FILES))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => (
                        key.indexOf('cpms-mobile-') === 0
                        && key !== STATIC_CACHE
                    ))
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    /*
     * Authenticated pages are never cached. This prevents data from one
     * user being shown to the next user of the same phone.
     */
    if (
        request.mode === 'navigate'
        || url.pathname.endsWith('.php')
        || url.pathname.indexOf('/api/') !== -1
        || url.pathname.indexOf('login') !== -1
        || url.pathname.indexOf('logout') !== -1
    ) {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    const isStatic = /\.(?:css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?)$/i
        .test(url.pathname);

    if (!isStatic) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request)
                .then((response) => {
                    if (response && response.ok) {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE)
                            .then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => cached);

            return cached || network;
        })
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

function evidenceDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('cpms-mobile-evidence', 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('queue')) {
                const store = db.createObjectStore('queue', {keyPath: 'clientId'});
                store.createIndex('ownerUserId', 'ownerUserId', {unique: false});
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function evidenceQueueAll() {
    const db = await evidenceDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('queue', 'readonly');
        const request = tx.objectStore('queue').getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        tx.oncomplete = () => db.close();
    });
}

async function evidenceQueueDelete(id) {
    const db = await evidenceDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('queue', 'readwrite');
        const request = tx.objectStore('queue').delete(id);
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
        tx.oncomplete = () => db.close();
    });
}

function evidenceFormData(item) {
    const data = new FormData();
    data.append('csrf_token', item.csrfToken);
    data.append('queue_owner_id', String(item.ownerUserId));
    data.append('task_type', item.taskType);
    data.append('task_id', String(item.taskId));
    data.append('client_upload_id', item.clientId);
    data.append('evidence_phase', item.phase);
    data.append('caption', item.caption);
    data.append('captured_at', item.capturedAt);
    data.append('evidence', item.blob, item.fileName);
    return data;
}

async function processEvidenceQueue() {
    const items = await evidenceQueueAll();
    let sent = 0;
    for (const item of items) {
        const response = await fetch('./mobile_evidence_upload.php', {
            method: 'POST',
            body: evidenceFormData(item),
            credentials: 'include'
        });
        if (response.ok) {
            await evidenceQueueDelete(item.clientId);
            sent += 1;
        } else if (response.status === 401 || response.status === 403) {
            break;
        }
    }
    if (sent > 0) {
        await self.registration.showNotification('CPMS Evidence', {
            body: `${sent} bukti gambar berjaya dihantar.`,
            icon: './pwa/icons/icon-192.png',
            badge: './pwa/icons/icon-192.png',
            tag: 'cpms-evidence-upload',
            data: {url: './mobile_task_inbox.php'}
        });
    }
}

self.addEventListener('sync', (event) => {
    if (event.tag === 'cpms-evidence-sync') {
        event.waitUntil(processEvidenceQueue());
    }
});

self.addEventListener('push', (event) => {
    event.waitUntil(
        fetch('./pwa_push_feed.php', {
            credentials: 'include',
            cache: 'no-store'
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('feed unavailable');
                }
                return response.json();
            })
            .catch(() => ({
                title: 'CPMS Task Reminder',
                message: 'Buka CPMS untuk menyemak tugasan terkini.',
                url: './cpms/login.php',
                tag: 'cpms-reminder'
            }))
            .then((data) => self.registration.showNotification(
                data.title || 'CPMS',
                {
                    body: data.message || 'Anda mempunyai kemas kini baharu.',
                    icon: './pwa/icons/icon-192.png',
                    badge: './pwa/icons/icon-192.png',
                    tag: data.tag || 'cpms-reminder',
                    renotify: true,
                    data: {url: data.url || './cpms/login.php'}
                }
            ))
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(
        event.notification.data.url || './cpms/login.php',
        self.location.origin
    ).href;
    event.waitUntil(
        self.clients.matchAll({type: 'window', includeUncontrolled: true})
            .then((windows) => {
                for (const client of windows) {
                    if (client.url.indexOf(self.location.origin) === 0) {
                        client.navigate(target);
                        return client.focus();
                    }
                }
                return self.clients.openWindow(target);
            })
    );
});
