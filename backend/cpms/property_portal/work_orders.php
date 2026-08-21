<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('work_orders.view');
require_once __DIR__ . '/includes/guards/guard_work_orders_view.php';

$status = trim((string) ($_GET['status'] ?? ''));
$allowed = [
    'Open',
    'Assigned',
    'In Progress',
    'Pending Material',
    'Pending Contractor',
    'Completed',
    'Verified',
    'Cancelled',
];

$sql = "
    SELECT
        wo.*,
        s.full_name AS staff_name
    FROM work_orders wo
    LEFT JOIN staff s
        ON s.id = wo.assigned_staff_id
       AND s.property_id = wo.property_id
    WHERE wo.property_id = ?
";

$types = 'i';
$params = [$currentPropertyId];

if ($status !== '' && in_array($status, $allowed, true)) {
    $sql .= ' AND wo.status = ?';
    $types .= 's';
    $params[] = $status;
}

$sql .= ' ORDER BY wo.created_at DESC, wo.id DESC';

$stmt = $conn->prepare($sql);
$rows = [];

if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
}

$pageTitle = 'Work Orders';
$activeMenu = 'work_orders';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<section class="page-heading">
    <div>
        <span class="section-label">OPERATIONS</span>
        <h1>Work Orders</h1>
        <p>
            Work orders created for
            <strong><?php echo propertyPortalEscape($currentPropertyName); ?></strong>.
        </p>
    </div>

    <div class="record-total">
        <span>Records</span>
        <strong><?php echo count($rows); ?></strong>
    </div>
</section>

<section class="panel">
    <form method="get" class="simple-filter-form">
        <div class="field-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All Statuses</option>
                <?php foreach ($allowed as $option): ?>
                    <option
                        value="<?php echo propertyPortalEscape($option); ?>"
                        <?php echo $status === $option ? 'selected' : ''; ?>
                    >
                        <?php echo propertyPortalEscape($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="button button-primary" type="submit">Filter</button>
        <a class="button button-secondary" href="work_orders.php">Reset</a>
    </form>
</section>

<section class="panel complaint-list-panel">
    <?php if (!$rows): ?>
        <div class="empty-state">
            <strong>No work orders found</strong>
            <span>Create a work order from a complaint details page.</span>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table" data-smart-table data-page-size="10" data-export-title="Work Order List">
                <thead>
                <tr>
                    <th>Reference</th>
                    <th>Complaint</th>
                    <th>Title</th>
                    <th>Staff</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Due Date</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong>
                                <?php echo propertyPortalEscape(
                                    (string) $row['work_order_reference']
                                ); ?>
                            </strong>
                        </td>
                        <td>
                            <?php if (!empty($row['complaint_id'])): ?>
                                <a href="complaint_view.php?ref=<?php
                                    echo rawurlencode((string) $row['complaint_id']);
                                ?>">
                                    <?php echo propertyPortalEscape(
                                        (string) $row['complaint_id']
                                    ); ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo propertyPortalEscape((string) $row['title']); ?></td>
                        <td><?php echo propertyPortalEscape((string) ($row['staff_name'] ?? 'Unassigned')); ?></td>
                        <td><?php echo propertyPortalEscape((string) $row['priority']); ?></td>
                        <td><span class="status-badge status-neutral"><?php echo propertyPortalEscape((string) $row['status']); ?></span></td>
                        <td><?php echo propertyPortalEscape((string) ($row['due_date'] ?? '-')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
