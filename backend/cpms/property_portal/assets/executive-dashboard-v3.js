(() => {
    const cards = document.querySelectorAll(
        '.premium-kpi-card, .executive-command-strip article, .executive-insight'
    );

    if (!('IntersectionObserver' in window)) {
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('cpms-card-visible');
                observer.unobserve(entry.target);
            }
        });
    }, {threshold: 0.08});

    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(10px)';
        card.style.transitionDelay = `${Math.min(index * 35, 280)}ms`;
        observer.observe(card);
    });

    const style = document.createElement('style');
    style.textContent = `
        .cpms-card-visible {
            opacity: 1 !important;
            transform: translateY(0) !important;
        }
    `;
    document.head.appendChild(style);
})();
