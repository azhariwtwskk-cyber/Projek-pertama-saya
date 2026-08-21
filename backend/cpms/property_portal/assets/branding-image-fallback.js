(() => {
    const images = document.querySelectorAll(
        'img[data-branding-image], '
        + '.sidebar-brand img, '
        + '.commercial-brand-logo img, '
        + '.branding-upload-preview img'
    );

    images.forEach((image) => {
        image.addEventListener('error', () => {
            image.style.display = 'none';

            const parent = image.parentElement;

            if (!parent) {
                return;
            }

            parent.classList.add('branding-image-missing');

            if (!parent.querySelector('.branding-fallback-mark')) {
                const fallback = document.createElement('span');
                fallback.className = 'branding-fallback-mark';
                fallback.textContent = 'LOGO';
                parent.appendChild(fallback);
            }
        }, { once: true });
    });
})();
