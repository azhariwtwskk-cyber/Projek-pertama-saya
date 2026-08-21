<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('complaints.view');
require_once __DIR__ . '/includes/guards/guard_complaints_view.php';

function complaintColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'complaints'
           AND column_name = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function complaintDisplayDate(array $row): string
{
    $value = trim((string) (
        $row['created_at']
        ?? $row['submitted_at']
        ?? $row['date_created']
        ?? ''
    ));

    if ($value !== '') {
        $timestamp = strtotime($value);

        if ($timestamp !== false) {
            return date('d M Y, h:i A', $timestamp);
        }

        return $value;
    }

    $reference = (string) ($row['complaint_id'] ?? '');

    if (
        preg_match(
            '/^[A-Za-z0-9]+-(\d{2})(\d{2})(\d{2})-/',
            $reference,
            $parts
        )
    ) {
        return sprintf(
            '%02d/%02d/20%02d',
            (int) $parts[3],
            (int) $parts[2],
            (int) $parts[1]
        );
    }

    return '-';
}

function complaintStatusClass(string $status): string
{
    return match (strtolower(trim($status))) {
        'pending' => 'status-pending',
        'in progress', 'in-progress', 'processing' => 'status-progress',
        'resolved', 'completed' => 'status-resolved',
        'closed' => 'status-closed',
        default => 'status-neutral',
    };
}

function complaintPriorityClass(string $priority): string
{
    return match (strtolower(trim($priority))) {
        'high', 'urgent', 'critical' => 'priority-high',
        'medium', 'normal' => 'priority-medium',
        'low' => 'priority-low',
        default => 'priority-neutral',
    };
}

$hasCreatedAt = complaintColumnExists($conn, 'created_at');

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$priorityFilter = trim((string) ($_GET['priority'] ?? ''));

$allowedStatuses = [
    'Pending',
    'In Progress',
    'Resolved',
    'Closed',
    'Completed',
];

$allowedPriorities = [
    'Low',
    'Medium',
    'High',
    'Urgent',
];

$where = ['property_id = ?'];
$types = 'i';
$values = [$currentPropertyId];

if ($search !== '') {
    $where[] = "(
        complaint_id LIKE ?
        OR name LIKE ?
        OR phone LIKE ?
        OR unit_no LIKE ?
        OR subject LIKE ?
        OR category LIKE ?
    )";

    $keyword = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $types .= 's';
        $values[] = $keyword;
    }
}

if (
    $statusFilter !== ''
    && in_array($statusFilter, $allowedStatuses, true)
) {
    $where[] = 'status = ?';
    $types .= 's';
    $values[] = $statusFilter;
}

if (
    $priorityFilter !== ''
    && in_array($priorityFilter, $allowedPriorities, true)
) {
    $where[] = 'priority = ?';
    $types .= 's';
    $values[] = $priorityFilter;
}

$orderBy = $hasCreatedAt
    ? 'created_at DESC, complaint_id DESC'
    : 'complaint_id DESC';

$sql = "
    SELECT *
    FROM complaints
    WHERE " . implode(' AND ', $where) . "
    ORDER BY {$orderBy}
";

$stmt = $conn->prepare($sql);
$complaints = [];
$queryError = '';

if (!$stmt) {
    $queryError = 'The complaint list could not be loaded.';
} else {
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $complaints[] = $row;
    }

    $stmt->close();
}

$pageTitle = 'Complaints';
$activeMenu = 'complaints';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<section class="page-heading">
    <div>
        <span class="section-label">OPERATIONS</span>
        <h1>Complaints</h1>
        <p>
            Review complaints submitted for
            <strong><?php echo propertyPortalEscape($currentPropertyName); ?></strong>.
            Records from other properties are not displayed.
        </p>
    </div>

    <div class="record-total">
        <span>Records</span>
        <strong><?php echo count($complaints); ?></strong>
    </div>
</section>

<section class="panel complaint-filter-panel">
    <form method="get" class="complaint-filter-grid">
        <div class="field-group field-search">
            <label for="search">Search</label>
            <input
                id="search"
                name="search"
                type="search"
                value="<?php echo propertyPortalEscape($search); ?>"
                placeholder="Reference, name, phone, unit or subject"
            >
        </div>

        <div class="field-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All Statuses</option>

                <?php foreach ($allowedStatuses as $option): ?>
                    <option
                        value="<?php echo propertyPortalEscape($option); ?>"
                        <?php echo $statusFilter === $option ? 'selected' : ''; ?>
                    >
                        <?php echo propertyPortalEscape($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field-group">
            <label for="priority">Priority</label>
            <select id="priority" name="priority">
                <option value="">All Priorities</option>

                <?php foreach ($allowedPriorities as $option): ?>
                    <option
                        value="<?php echo propertyPortalEscape($option); ?>"
                        <?php echo $priorityFilter === $option ? 'selected' : ''; ?>
                    >
                        <?php echo propertyPortalEscape($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-buttons">
            <button type="submit" class="button button-primary">
                Search
            </button>

            <a href="complaints.php" class="button button-secondary">
                Reset
            </a>
        </div>
    </form>
</section>

<section class="panel complaint-list-panel">
    <div class="panel-heading complaint-panel-heading">
        <div>
            <span class="section-label">PROPERTY RECORDS</span>
            <h2>Complaint List</h2>
        </div>

        <span class="property-scope-label">
            Property ID <?php echo (int) $currentPropertyId; ?>
        </span>
    </div>

    <?php if ($queryError !== ''): ?>
        <div class="alert alert-danger">
            <?php echo propertyPortalEscape($queryError); ?>
        </div>
    <?php elseif (!$complaints): ?>
        <div class="empty-state">
            <strong>No complaints found</strong>
            <span>Try changing the search or filter selection.</span>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table" data-smart-table data-page-size="10" data-export-title="Complaint List">
                <thead>
                <tr>
                    <th>Reference</th>
                    <th>Resident</th>
                    <th>Unit</th>
                    <th>Subject / Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th data-no-sort></th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($complaints as $complaint): ?>
                    <?php
                    $reference = (string) (
                        $complaint['complaint_id'] ?? ''
                    );

                    $residentName = trim((string) (
                        $complaint['name']
                        ?? $complaint['resident_name']
                        ?? '-'
                    ));

                    $unit = trim((string) (
                        $complaint['unit_no']
                        ?? $complaint['unit']
                        ?? '-'
                    ));

                    $subject = trim((string) (
                        $complaint['subject']
                        ?? $complaint['title']
                        ?? '-'
                    ));

                    $category = trim((string) (
                        $complaint['category'] ?? '-'
                    ));

                    $priority = trim((string) (
                        $complaint['priority'] ?? '-'
                    ));

                    $status = trim((string) (
                        $complaint['status'] ?? 'Pending'
                    ));
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <?php echo propertyPortalEscape($reference); ?>
                            </strong>
                        </td>

                        <td>
                            <?php echo propertyPortalEscape($residentName); ?>
                        </td>

                        <td>
                            <?php echo propertyPortalEscape($unit); ?>
                        </td>

                        <td>
                            <strong>
                                <?php echo propertyPortalEscape($subject); ?>
                            </strong>
                            <small>
                                <?php echo propertyPortalEscape($category); ?>
                            </small>
                        </td>

                        <td>
                            <span class="priority-badge <?php
                                echo complaintPriorityClass($priority);
                            ?>">
                                <?php echo propertyPortalEscape($priority); ?>
                            </span>
                        </td>

                        <td>
                            <span class="status-badge <?php
                                echo complaintStatusClass($status);
                            ?>">
                                <?php echo propertyPortalEscape($status); ?>
                            </span>
                        </td>

                        <td>
                            <?php echo propertyPortalEscape(
                                complaintDisplayDate($complaint)
                            ); ?>
                        </td>

                        <td class="table-action-cell">
                            <a
                                class="table-action"
                                href="complaint_view.php?ref=<?php
                                    echo rawurlencode($reference);
                                ?>"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
