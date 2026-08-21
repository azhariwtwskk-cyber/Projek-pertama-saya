</main>
</div>
</div>
<script>
(function () {
    var sidebar = document.getElementById('adminSidebar');
    var toggle = document.getElementById('sidebarToggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
    }

    var search = document.getElementById('cpmsGlobalSearch');
    if (search) {
        search.addEventListener('input', function () {
            var term = search.value.toLowerCase().trim();
            document.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().indexOf(term) >= 0
                    ? ''
                    : 'none';
            });
        });
    }
}());
    // Smooth Genesis interactions: keyboard search, mobile overlay and progressive reveal.
    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            if (search) { search.focus(); search.select(); }
        }
        if (event.key === 'Escape' && sidebar) sidebar.classList.remove('open');
    });
    document.querySelectorAll('.card,.kpi,.ai-card,.form-card,.genesis-action-card').forEach(function (el, index) {
        el.classList.add('genesis-reveal');
        el.style.setProperty('--reveal-delay', Math.min(index * 24, 180) + 'ms');
    });
    requestAnimationFrame(function () { document.body.classList.add('genesis-ready'); });
</script>
</body></html>
