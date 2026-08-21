    </main>

    <footer class="admin-footer">
        <span><?php echo propertyPortalEscape(
            cpmsBrandingFooter($propertyPortalUser)
        ); ?></span>
        <span>
        <?php echo date('Y'); ?>
        <?php if (cpmsBrandingShowCpms($propertyPortalUser)): ?>
            · Powered by CPMS
        <?php endif; ?>
    </span>
    </footer>
</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

<?php cpmsGenesisFooter(); ?>
<script src="assets/admin.js"></script>
<script src="assets/enterprise-ui-v2-1.js?v=2101"></script>
<script src="assets/smart-table.js"></script>
<script src="assets/branding-image-fallback.js"></script>
</body>
</html>
