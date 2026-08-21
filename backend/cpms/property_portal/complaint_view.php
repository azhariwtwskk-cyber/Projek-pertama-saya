<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('complaints.view');
require_once __DIR__ . '/includes/guards/guard_complaints_view.php';
require_once __DIR__ . '/includes/workflow_helpers.php';

function portalComplaintValue(
    array $complaint,
    array $keys,
    string $fallback = '-'
): string {
    foreach ($keys as $key) {
        if (
            array_key_exists($key, $complaint)
            && trim((string) $complaint[$key]) !== ''
        ) {
            return (string) $complaint[$key];
        }
    }

    return $fallback;
}

function portalComplaintImageUrl(string $imageName): string
{
    return '../../uploads/' . rawurlencode(basename($imageName));
}

$reference = trim((string) ($_GET['ref'] ?? ''));

if ($reference === '') {
    http_response_code(400);
    exit('Invalid complaint reference.');
}

$complaint = cpmsWorkflowComplaint(
    $conn,
    $reference,
    $currentPropertyId
);

if (!$complaint) {
    http_response_code(404);
    exit('Complaint not found or it does not belong to your property.');
}

$images = [];
$imageStmt = $conn->prepare(
    "SELECT image_name
     FROM complaint_images
     WHERE complaint_id = ?
       AND property_id = ?
     ORDER BY id ASC"
);

if ($imageStmt) {
    $imageStmt->bind_param('si', $reference, $currentPropertyId);
    $imageStmt->execute();
    $imageResult = $imageStmt->get_result();

    while ($row = $imageResult->fetch_assoc()) {
        if (!empty($row['image_name'])) {
            $images[] = (string) $row['image_name'];
        }
    }

    $imageStmt->close();
}

$staff = [];
$staffStmt = $conn->prepare(
    "SELECT id, full_name, role
     FROM staff
     WHERE property_id = ?
       AND account_status = 'Active'
     ORDER BY full_name ASC"
);

if ($staffStmt) {
    $staffStmt->bind_param('i', $currentPropertyId);
    $staffStmt->execute();
    $staffResult = $staffStmt->get_result();

    while ($row = $staffResult->fetch_assoc()) {
        $staff[] = $row;
    }

    $staffStmt->close();
}

$history = [];
$historyStmt = $conn->prepare(
    "SELECT *
     FROM cpms_complaint_history
     WHERE property_id = ?
       AND complaint_id = ?
     ORDER BY created_at DESC, id DESC"
);

if ($historyStmt) {
    $historyStmt->bind_param('is', $currentPropertyId, $reference);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();

    while ($row = $historyResult->fetch_assoc()) {
        $history[] = $row;
    }

    $historyStmt->close();
}

$workOrder = null;
$woStmt = $conn->prepare(
    "SELECT
        wo.*,
        s.full_name AS staff_name
     FROM work_orders wo
     LEFT JOIN staff s
        ON s.id = wo.assigned_staff_id
       AND s.property_id = wo.property_id
     WHERE wo.property_id = ?
       AND wo.complaint_id = ?
       AND wo.status <> 'Cancelled'
     ORDER BY wo.id DESC
     LIMIT 1"
);

if ($woStmt) {
    $woStmt->bind_param('is', $currentPropertyId, $reference);
    $woStmt->execute();
    $workOrder = $woStmt->get_result()->fetch_assoc() ?: null;
    $woStmt->close();
}

$pageTitle = 'Complaint Details';
$activeMenu = 'complaints';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';

$flash = cpmsWorkflowPullFlash();
$residentName = portalComplaintValue($complaint, ['name']);
$phone = portalComplaintValue($complaint, ['phone']);
$email = portalComplaintValue($complaint, ['email']);
$unit = portalComplaintValue($complaint, ['unit_no']);
$block = portalComplaintValue($complaint, ['block']);
$location = portalComplaintValue($complaint, ['location']);
$subject = portalComplaintValue($complaint, ['subject']);
$category = portalComplaintValue($complaint, ['category']);
$priority = portalComplaintValue($complaint, ['priority']);
$status = portalComplaintValue($complaint, ['status'], 'Pending');
$description = portalComplaintValue($complaint, ['description']);
$remarks = portalComplaintValue(
    $complaint,
    ['admin_remarks'],
    'No administrative remarks.'
);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo propertyPortalEscape($flash['type']); ?>">
        <?php echo propertyPortalEscape($flash['message']); ?>
    </div>
<?php endif; ?>

<section class="page-heading">
    <div>
        <a href="complaints.php" class="back-link">← Back to Complaints</a>
        <span class="section-label">COMPLAINT DETAILS</span>
        <h1><?php echo propertyPortalEscape($reference); ?></h1>
        <p>
            Complaint record for
            <strong><?php echo propertyPortalEscape($currentPropertyName); ?></strong>.
        </p>
    </div>

    <div class="complaint-status-summary">
        <span>Status</span>
        <strong><?php echo propertyPortalEscape($status); ?></strong>
    </div>
</section>

<section class="detail-grid">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">COMPLAINT</span>
                <h2><?php echo propertyPortalEscape($subject); ?></h2>
            </div>
        </div>

        <dl class="detail-list">
            <div><dt>Category</dt><dd><?php echo propertyPortalEscape($category); ?></dd></div>
            <div><dt>Priority</dt><dd><?php echo propertyPortalEscape($priority); ?></dd></div>
            <div><dt>Status</dt><dd><?php echo propertyPortalEscape($status); ?></dd></div>
            <div><dt>Location</dt><dd><?php echo propertyPortalEscape($location); ?></dd></div>
            <div class="detail-full"><dt>Description</dt><dd class="preserve-lines"><?php echo propertyPortalEscape($description); ?></dd></div>
            <div class="detail-full"><dt>Administrative Remarks</dt><dd class="preserve-lines"><?php echo propertyPortalEscape($remarks); ?></dd></div>
        </dl>
    </article>

    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">RESIDENT</span>
                <h2>Contact Information</h2>
            </div>
        </div>

        <dl class="detail-list">
            <div class="detail-full"><dt>Name</dt><dd><?php echo propertyPortalEscape($residentName); ?></dd></div>
            <div><dt>Block</dt><dd><?php echo propertyPortalEscape($block); ?></dd></div>
            <div><dt>Unit</dt><dd><?php echo propertyPortalEscape($unit); ?></dd></div>
            <div class="detail-full"><dt>Phone</dt><dd><?php echo propertyPortalEscape($phone); ?></dd></div>
            <div class="detail-full"><dt>Email</dt><dd><?php echo propertyPortalEscape($email); ?></dd></div>
        </dl>
    </article>
</section>

<section class="workflow-grid">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">WORKFLOW</span>
                <h2>Update Complaint</h2>
            </div>
        </div>

        <form action="complaint_update.php" method="post" class="workflow-form">
            <input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken()); ?>">
            <input type="hidden" name="complaint_id" value="<?php echo propertyPortalEscape($reference); ?>">

            <div class="field-group">
                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <?php foreach (['Pending', 'In Progress', 'Resolved', 'Closed'] as $option): ?>
                        <option value="<?php echo propertyPortalEscape($option); ?>" <?php echo $status === $option ? 'selected' : ''; ?>>
                            <?php echo propertyPortalEscape($option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-group">
                <label for="admin_remarks">Administrative Remarks</label>
                <textarea id="admin_remarks" name="admin_remarks" rows="5"><?php echo propertyPortalEscape((string) ($complaint['admin_remarks'] ?? '')); ?></textarea>
            </div>

            <button class="button button-primary" type="submit">
                Save Complaint Update
            </button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">WORK ORDER</span>
                <h2><?php echo $workOrder ? 'Existing Work Order' : 'Create Work Order'; ?></h2>
            </div>
        </div>

        <?php if ($workOrder): ?>
            <dl class="detail-list compact-details">
                <div class="detail-full"><dt>Reference</dt><dd><?php echo propertyPortalEscape((string) $workOrder['work_order_reference']); ?></dd></div>
                <div><dt>Status</dt><dd><?php echo propertyPortalEscape((string) $workOrder['status']); ?></dd></div>
                <div><dt>Staff</dt><dd><?php echo propertyPortalEscape((string) ($workOrder['staff_name'] ?? 'Unassigned')); ?></dd></div>
                <div><dt>Scheduled</dt><dd><?php echo propertyPortalEscape((string) ($workOrder['scheduled_date'] ?? '-')); ?></dd></div>
                <div><dt>Due</dt><dd><?php echo propertyPortalEscape((string) ($workOrder['due_date'] ?? '-')); ?></dd></div>
            </dl>
        <?php else: ?>
            <form action="work_order_create.php" method="post" class="workflow-form">
                <input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken()); ?>">
                <input type="hidden" name="complaint_id" value="<?php echo propertyPortalEscape($reference); ?>">

                <div class="field-group">
                    <label for="assigned_staff_id">Assign Staff</label>
                    <select id="assigned_staff_id" name="assigned_staff_id">
                        <option value="0">Leave Unassigned</option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?php echo (int) $member['id']; ?>">
                                <?php echo propertyPortalEscape(
                                    $member['full_name'] . ' — ' . $member['role']
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="two-column-fields">
                    <div class="field-group">
                        <label for="scheduled_date">Scheduled Date</label>
                        <input id="scheduled_date" name="scheduled_date" type="date" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="field-group">
                        <label for="due_date">Due Date</label>
                        <input id="due_date" name="due_date" type="date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                </div>

                <div class="field-group">
                    <label for="work_order_remarks">Work Order Remarks</label>
                    <textarea id="work_order_remarks" name="work_order_remarks" rows="4"></textarea>
                </div>

                <button class="button button-primary" type="submit">
                    Create Work Order
                </button>
            </form>
        <?php endif; ?>
    </article>
</section>

<section class="panel complaint-images-panel">
    <div class="panel-heading">
        <div>
            <span class="section-label">ATTACHMENTS</span>
            <h2>Complaint Images</h2>
        </div>
        <span class="property-scope-label"><?php echo count($images); ?> image(s)</span>
    </div>

    <?php if (!$images): ?>
        <div class="empty-state">
            <strong>No images attached</strong>
            <span>This complaint does not contain image attachments.</span>
        </div>
    <?php else: ?>
        <div class="complaint-image-grid">
            <?php foreach ($images as $image): ?>
                <a class="complaint-image-card" target="_blank" rel="noopener" href="<?php echo propertyPortalEscape(portalComplaintImageUrl($image)); ?>">
                    <img src="<?php echo propertyPortalEscape(portalComplaintImageUrl($image)); ?>" alt="Complaint attachment" loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel timeline-panel">
    <div class="panel-heading">
        <div>
            <span class="section-label">AUDIT TRAIL</span>
            <h2>Complaint Timeline</h2>
        </div>
    </div>

    <?php if (!$history): ?>
        <div class="empty-state">
            <strong>No workflow history yet</strong>
            <span>The first status update or work order creation will appear here.</span>
        </div>
    <?php else: ?>
        <div class="timeline">
            <?php foreach ($history as $item): ?>
                <article class="timeline-item">
                    <span class="timeline-dot"></span>
                    <div>
                        <strong><?php echo propertyPortalEscape((string) $item['action_type']); ?></strong>
                        <p>
                            <?php echo propertyPortalEscape((string) ($item['old_status'] ?? '-')); ?>
                            →
                            <?php echo propertyPortalEscape((string) $item['new_status']); ?>
                        </p>
                        <?php if (!empty($item['remarks'])): ?>
                            <p><?php echo nl2br(propertyPortalEscape((string) $item['remarks'])); ?></p>
                        <?php endif; ?>
                        <small>
                            <?php echo propertyPortalEscape((string) $item['changed_by_name']); ?>
                            ·
                            <?php echo propertyPortalEscape((string) $item['created_at']); ?>
                        </small>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
