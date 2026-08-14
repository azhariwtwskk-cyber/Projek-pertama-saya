(function () {
    'use strict';

    var buttons = document.querySelectorAll('[data-dashboard-density]');
    if (!buttons.length) {
        return;
    }

    var storageKey = 'cpms_dashboard_density';
    var saved = 'compact';

    try {
        saved = window.localStorage.getItem(storageKey) || 'compact';
    } catch (error) {
        saved = 'compact';
    }

    function applyDensity(value) {
        var comfortable = value === 'comfortable';
        document.body.classList.toggle('dashboard-comfortable', comfortable);

        buttons.forEach(function (button) {
            var active = button.getAttribute('data-dashboard-density') === value;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        try {
            window.localStorage.setItem(storageKey, value);
        } catch (error) {
            // Dashboard remains functional when browser storage is unavailable.
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            applyDensity(button.getAttribute('data-dashboard-density') || 'compact');
        });
    });

    applyDensity(saved);
})();
