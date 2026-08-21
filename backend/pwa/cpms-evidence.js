/* CPMS v3.4.4 — image compression and offline evidence queue. */
'use strict';

(() => {
    const config = window.CPMS_EVIDENCE || {};
    const form = document.getElementById('evidence-form');
    if (!form || !window.indexedDB) return;
    const fileInput = document.getElementById('evidence');
    const preview = document.getElementById('evidence-preview');
    const status = document.getElementById('evidence-status');
    const count = document.getElementById('evidence-queue-count');
    const DB_NAME = 'cpms-mobile-evidence';
    const STORE = 'queue';

    function setStatus(message, kind) {
        status.textContent = message;
        status.className = `status ${kind || ''}`;
    }
    function openDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    const store = db.createObjectStore(STORE, {keyPath: 'clientId'});
                    store.createIndex('ownerUserId', 'ownerUserId', {unique: false});
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }
    async function withStore(mode, action) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(STORE, mode);
            const request = action(transaction.objectStore(STORE));
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
            transaction.oncomplete = () => db.close();
        });
    }
    const put = (item) => withStore('readwrite', (store) => store.put(item));
    const remove = (id) => withStore('readwrite', (store) => store.delete(id));
    const all = () => withStore('readonly', (store) => store.getAll());

    function clientId() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
            return (c === 'x' ? r : (r & 3) | 8).toString(16);
        });
    }
    function loadImage(file) {
        if ('createImageBitmap' in window) return createImageBitmap(file);
        return new Promise((resolve, reject) => {
            const image = new Image();
            const url = URL.createObjectURL(file);
            image.onload = () => {
                URL.revokeObjectURL(url);
                resolve(image);
            };
            image.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('image_decode_failed'));
            };
            image.src = url;
        });
    }
    async function compress(file) {
        setStatus('Memampatkan gambar…');
        const bitmap = await loadImage(file);
        const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        if (bitmap.close) bitmap.close();
        return new Promise((resolve, reject) => canvas.toBlob(
            (blob) => blob ? resolve(blob) : reject(new Error('compression_failed')),
            'image/jpeg', 0.78
        ));
    }
    function formData(item) {
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
    async function upload(item) {
        const response = await fetch(config.endpoint, {
            method: 'POST', body: formData(item), credentials: 'same-origin'
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) {
            const error = new Error(result.error || `http_${response.status}`);
            error.status = response.status;
            throw error;
        }
        await remove(item.clientId);
    }
    async function refreshCount() {
        const items = await all();
        count.textContent = String(items.filter(
            (item) => Number(item.ownerUserId) === Number(config.ownerUserId)
        ).length);
    }
    async function retryQueue() {
        if (!navigator.onLine) {
            setStatus('Offline — bukti kekal dalam queue telefon.', 'bad');
            await refreshCount();
            return;
        }
        const items = await all();
        const owned = items.filter(
            (item) => Number(item.ownerUserId) === Number(config.ownerUserId)
        );
        for (const item of owned) {
            try {
                setStatus('Menghantar bukti dalam queue…');
                await upload(item);
            } catch (error) {
                if (error.status === 401 || error.status === 403) break;
            }
        }
        await refreshCount();
    }
    async function registerSync() {
        if (!('serviceWorker' in navigator)) return;
        try {
            const registration = await navigator.serviceWorker.ready;
            if ('sync' in registration) {
                await registration.sync.register('cpms-evidence-sync');
            }
        } catch (error) {
            /* Online event is the supported fallback. */
        }
    }

    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.style.display = 'block';
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const file = fileInput.files && fileInput.files[0];
        if (!file || !file.type.startsWith('image/')) {
            setStatus('Sila pilih gambar daripada kamera atau Gallery.', 'bad');
            return;
        }
        try {
            const blob = await compress(file);
            const item = {
                clientId: clientId(),
                ownerUserId: Number(config.ownerUserId),
                taskType: String(config.taskType),
                taskId: Number(config.taskId),
                csrfToken: String(form.elements.csrf_token.value),
                phase: String(form.elements.evidence_phase.value),
                caption: String(form.elements.caption.value),
                capturedAt: new Date().toISOString(),
                fileName: `cpms-evidence-${Date.now()}.jpg`,
                blob,
                queuedAt: Date.now()
            };
            await put(item);
            await refreshCount();
            if (navigator.onLine) {
                setStatus('Menghantar bukti…');
                await upload(item);
                setStatus('Bukti gambar berjaya dihantar.', 'good');
                form.reset();
                preview.style.display = 'none';
                await refreshCount();
                setTimeout(() => window.location.reload(), 800);
            } else {
                setStatus('Offline — bukti disimpan dalam queue telefon.', 'good');
                await registerSync();
            }
        } catch (error) {
            setStatus('Bukti disimpan dalam queue dan akan dicuba semula.', 'bad');
            await registerSync();
            await refreshCount();
        }
    });
    window.addEventListener('online', retryQueue);
    refreshCount().then(retryQueue);
})();
