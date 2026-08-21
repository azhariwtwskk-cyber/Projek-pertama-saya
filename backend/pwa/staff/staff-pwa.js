(() => {
  'use strict';

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(
        'pwa/staff/service-worker.js?v=2-gallery',
        {
        scope: './'
        }
      ).catch(() => {});
    });
  }

  let deferredPrompt = null;

  const buildInstallButton = () => {
    if (document.getElementById('cpms-install-app')) return;

    const button = document.createElement('button');
    button.id = 'cpms-install-app';
    button.type = 'button';
    button.className = 'cpms-install-app';
    button.textContent = 'Pasang Aplikasi';
    button.hidden = true;
    document.body.appendChild(button);

    button.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      button.hidden = true;
    });
  };

  buildInstallButton();

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredPrompt = event;
    const button = document.getElementById('cpms-install-app');
    if (button) button.hidden = false;
  });

  window.addEventListener('appinstalled', () => {
    const button = document.getElementById('cpms-install-app');
    if (button) button.hidden = true;
  });

  const addMobileBottomNav = () => {
    if (!document.body.classList.contains('pms-body')) return;
    if (document.querySelector('.cpms-mobile-nav')) return;

    const path = location.pathname.split('/').pop() || '';
    const items = [
      ['staff_dashboard.php', '⌂', 'Utama'],
      ['staff_work_orders.php', '☑', 'Tugasan'],
      ['staff_work_form.php', '＋', 'Rekod'],
      ['staff_work_history.php', '◷', 'Sejarah']
    ];

    const nav = document.createElement('nav');
    nav.className = 'cpms-mobile-nav';
    nav.setAttribute('aria-label', 'Navigasi Staff');

    items.forEach(([href, icon, label]) => {
      const link = document.createElement('a');
      link.href = href;
      link.className = path === href ? 'active' : '';
      link.innerHTML =
        `<span aria-hidden="true">${icon}</span><small>${label}</small>`;
      nav.appendChild(link);
    });

    document.body.appendChild(nav);
  };

  addMobileBottomNav();

  document.querySelectorAll(
    'input[type="file"][accept*="image"]'
  ).forEach(input => {
    /*
     * Do not force capture="environment".
     * Without capture, mobile devices can offer both Camera and Gallery.
     */
    input.removeAttribute('capture');
  });

  const setOnlineState = () => {
    document.documentElement.dataset.network =
      navigator.onLine ? 'online' : 'offline';
  };

  setOnlineState();
  window.addEventListener('online', setOnlineState);
  window.addEventListener('offline', setOnlineState);
})();
