/* CPMS v3.4.5 — GPS attendance client. Server performs final validation. */
'use strict';

(() => {
    const form = document.getElementById('attendance-form');
    const button = document.getElementById('attendance-button');
    const status = document.getElementById('gps-status');
    if (!form || !button || !status) return;

    function deviceId() {
        let value = localStorage.getItem('cpms_attendance_device_id');
        if (!value) {
            value = (crypto.randomUUID ? crypto.randomUUID()
                : `${Date.now()}-${Math.random()}`);
            localStorage.setItem('cpms_attendance_device_id', value);
        }
        return value;
    }
    button.addEventListener('click', () => {
        if (!navigator.geolocation) {
            status.textContent = 'Telefon/browser ini tidak menyokong GPS.';
            status.style.color = '#b42318';
            return;
        }
        button.disabled = true;
        status.textContent = 'Mendapatkan lokasi GPS berketepatan tinggi…';
        navigator.geolocation.getCurrentPosition((position) => {
            form.elements.latitude.value = position.coords.latitude.toFixed(7);
            form.elements.longitude.value = position.coords.longitude.toFixed(7);
            form.elements.accuracy.value = position.coords.accuracy.toFixed(2);
            form.elements.device_id.value = deviceId();
            status.textContent = `GPS ditemui · Ketepatan ±${Math.round(
                position.coords.accuracy
            )} meter. Menghantar…`;
            form.submit();
        }, (error) => {
            const messages = {
                1: 'Kebenaran lokasi ditolak. Benarkan Location untuk laman CPMS.',
                2: 'Lokasi tidak dapat dikesan. Hidupkan GPS dan cuba lagi.',
                3: 'GPS mengambil masa terlalu lama. Cuba di kawasan terbuka.'
            };
            status.textContent = messages[error.code] || 'GPS tidak dapat digunakan.';
            status.style.color = '#b42318';
            button.disabled = false;
        }, {
            enableHighAccuracy: true,
            timeout: 20000,
            maximumAge: 0
        });
    });
})();
