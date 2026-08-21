<div class="admin-main">
    <header class="admin-topbar enterprise-topbar">
        <div class="topbar-left">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation" aria-controls="adminSidebar" aria-expanded="true">
                <span></span><span></span><span></span>
            </button>

            <div class="topbar-title">
                <strong><?php echo propertyPortalEscape($pageTitle); ?></strong>
                <span class="topbar-context"><?php echo propertyPortalEscape($currentPropertyName); ?></span>
            </div>
        </div>

        <div class="topbar-actions">
            <div class="topbar-search" role="search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
                <input type="search" id="cpmsGlobalSearch" placeholder="Quick search" aria-label="Quick search current page">
            </div>
            <span class="topbar-property-id">Property <?php echo (int) $currentPropertyId; ?></span>
            <div class="topbar-user-chip" title="<?php echo propertyPortalEscape((string) $propertyPortalUser['full_name']); ?>">
                <span><?php echo strtoupper(substr((string) $propertyPortalUser['full_name'], 0, 1)); ?></span>
                <div>
                    <strong><?php echo propertyPortalEscape((string) $propertyPortalUser['full_name']); ?></strong>
                    <small><?php echo propertyPortalEscape(cpmsPropertyRoleLabel()); ?></small>
                </div>
            </div>
        </div>
    </header>

    <main class="admin-content">
