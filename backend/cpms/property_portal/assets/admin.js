(() => {
    const sidebar = document.getElementById('adminSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !toggle || !overlay) {
        return;
    }

    const mobileQuery = window.matchMedia('(max-width: 900px)');

    const setState = (isOpen) => {
        sidebar.classList.toggle('open', isOpen);
        overlay.classList.toggle('show', isOpen);
        document.body.classList.toggle('sidebar-open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        overlay.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    };

    const closeSidebar = () => setState(false);
    const openSidebar = () => setState(true);

    toggle.setAttribute('aria-controls', 'adminSidebar');
    toggle.setAttribute('aria-expanded', 'false');
    overlay.setAttribute('aria-hidden', 'true');

    toggle.addEventListener('click', () => {
        sidebar.classList.contains('open')
            ? closeSidebar()
            : openSidebar();
    });

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('open')) {
            closeSidebar();
            toggle.focus();
        }
    });

    sidebar.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileQuery.matches) {
                closeSidebar();
            }
        });
    });

    const handleViewportChange = (event) => {
        if (!event.matches) {
            closeSidebar();
        }
    };

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', handleViewportChange);
    } else {
        mobileQuery.addListener(handleViewportChange);
    }
})();
