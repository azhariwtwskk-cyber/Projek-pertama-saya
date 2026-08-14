(() => {
    const form = document.getElementById(
        'commercialLoginForm'
    );

    const button = document.getElementById(
        'commercialLoginButton'
    );

    const password = document.getElementById('password');
    const toggle = document.getElementById('passwordToggle');

    if (password && toggle) {
        toggle.addEventListener('click', () => {
            const showing = password.type === 'text';

            password.type = showing
                ? 'password'
                : 'text';

            toggle.textContent = showing
                ? 'Show'
                : 'Hide';

            toggle.setAttribute(
                'aria-label',
                showing
                    ? 'Show password'
                    : 'Hide password'
            );

            toggle.setAttribute(
                'aria-pressed',
                showing ? 'false' : 'true'
            );

            password.focus();
        });
    }

    if (form && button) {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            button.disabled = true;
            button.classList.add('is-loading');
        });
    }
})();
