<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('dashboard.view');

$matrix = cpmsPropertyPermissionMatrix();
$roles = [
    'property_admin' => 'Property Administrator',
    'manager' => 'Manager',
    'clerk' => 'Clerk',
];

$pageTitle = 'Access Control';
$activeMenu = 'dashboard';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<section class="page-heading">
    <div>
        <span class="section-label">FOUNDATION PHASE 3</span>
        <h1>Access Control</h1>
        <p>
            Current role:
            <strong>
                <?php echo propertyPortalEscape(
                    cpmsPropertyRoleLabel()
                ); ?>
            </strong>
        </p>
    </div>
</section>

<section class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Permission</th>
                <?php foreach ($roles as $label): ?>
                    <th>
                        <?php echo propertyPortalEscape($label); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($matrix as $permission => $allowed): ?>
                <tr>
                    <td>
                        <strong>
                            <?php echo propertyPortalEscape(
                                $permission
                            ); ?>
                        </strong>
                    </td>

                    <?php foreach (array_keys($roles) as $role): ?>
                        <td>
                            <?php echo in_array(
                                $role,
                                $allowed,
                                true
                            ) ? 'PASS' : '—'; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
