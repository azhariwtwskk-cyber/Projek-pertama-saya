<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__)
    . '/includes/preventive_maintenance_service.php';

cpmsRequire('maintenance.budget', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$currentYear = (int) date('Y');
$year = (int) ($_GET['year'] ?? $_POST['budget_year'] ?? $currentYear);
if ($year < 2020 || $year > $currentYear + 5) {
    $year = $currentYear;
}
$errors = [];
$success = isset($_GET['saved'])
    ? 'Maintenance budget saved.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsPmVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid.';
    } else {
        $month = (int) ($_POST['budget_month'] ?? 0);
        $amount = (float) ($_POST['budget_amount'] ?? 0);
        $warningPercent = (float) (
            $_POST['warning_percent'] ?? 80
        );
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($month < 1 || $month > 12) {
            $errors[] = 'Select a valid budget month.';
        }
        if ($amount < 0) {
            $errors[] = 'Budget amount cannot be negative.';
        }
        if ($warningPercent < 1 || $warningPercent > 100) {
            $errors[] = 'Warning threshold must be between 1 and 100.';
        }

        if (!$errors) {
            $userId = cpmsPmCurrentUserId();
            $userName = cpmsPmCurrentUserName();
            $stmt = $conn->prepare(
                "INSERT INTO cpms_pm_budgets (
                    property_id, budget_year, budget_month,
                    budget_amount, warning_percent, notes,
                    created_by_user_id, created_by_name,
                    updated_by_user_id, updated_by_name
                 ) VALUES (
                    ?, ?, ?, ?, ?, NULLIF(?, ''),
                    NULLIF(?, 0), ?, NULLIF(?, 0), ?
                 )
                 ON DUPLICATE KEY UPDATE
                    budget_amount = VALUES(budget_amount),
                    warning_percent = VALUES(warning_percent),
                    notes = VALUES(notes),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_by_name = VALUES(updated_by_name),
                    updated_at = CURRENT_TIMESTAMP"
            );
            if (!$stmt) {
                $errors[] = 'Unable to prepare maintenance budget.';
            } else {
                $stmt->bind_param(
                    'iiiddsisis',
                    $propertyId,
                    $year,
                    $month,
                    $amount,
                    $warningPercent,
                    $notes,
                    $userId,
                    $userName,
                    $userId,
                    $userName
                );
                if ($stmt->execute()) {
                    $stmt->close();
                    header(
                        'Location: pm_budget.php?year='
                        . $year . '&saved=1'
                    );
                    exit();
                }
                $errors[] = $stmt->error;
                $stmt->close();
            }
        }
    }
}

$months = [];
for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
    $months[$monthNumber] = [
        'month' => $monthNumber,
        'label' => date('F', mktime(0, 0, 0, $monthNumber, 1)),
        'budget' => 0.0,
        'warning' => 80.0,
        'notes' => '',
        'actual' => 0.0,
        'planned' => 0.0,
        'jobs' => 0,
    ];
}

$budgetStmt = $conn->prepare(
    "SELECT budget_month, budget_amount,
            warning_percent, notes
     FROM cpms_pm_budgets
     WHERE property_id = ?
       AND budget_year = ?"
);
if ($budgetStmt) {
    $budgetStmt->bind_param('ii', $propertyId, $year);
    $budgetStmt->execute();
    $result = $budgetStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthNumber = (int) $row['budget_month'];
        if (isset($months[$monthNumber])) {
            $months[$monthNumber]['budget'] = (float) (
                $row['budget_amount'] ?? 0
            );
            $months[$monthNumber]['warning'] = (float) (
                $row['warning_percent'] ?? 80
            );
            $months[$monthNumber]['notes'] = (string) (
                $row['notes'] ?? ''
            );
        }
    }
    $budgetStmt->close();
}

$actualStmt = $conn->prepare(
    "SELECT MONTH(completed_date) AS month_number,
            COUNT(*) AS jobs,
            COALESCE(SUM(actual_cost), 0) AS actual_cost
     FROM cpms_pm_work_logs
     WHERE property_id = ?
       AND YEAR(completed_date) = ?
     GROUP BY MONTH(completed_date)"
);
if ($actualStmt) {
    $actualStmt->bind_param('ii', $propertyId, $year);
    $actualStmt->execute();
    $result = $actualStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthNumber = (int) $row['month_number'];
        if (isset($months[$monthNumber])) {
            $months[$monthNumber]['actual'] = (float) (
                $row['actual_cost'] ?? 0
            );
            $months[$monthNumber]['jobs'] = (int) (
                $row['jobs'] ?? 0
            );
        }
    }
    $actualStmt->close();
}

$plannedStmt = $conn->prepare(
    "SELECT MONTH(next_due_date) AS month_number,
            COALESCE(SUM(estimated_cost), 0) AS planned_cost
     FROM cpms_pm_schedules
     WHERE property_id = ?
       AND status = 'active'
       AND YEAR(next_due_date) = ?
     GROUP BY MONTH(next_due_date)"
);
if ($plannedStmt) {
    $plannedStmt->bind_param('ii', $propertyId, $year);
    $plannedStmt->execute();
    $result = $plannedStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthNumber = (int) $row['month_number'];
        if (isset($months[$monthNumber])) {
            $months[$monthNumber]['planned'] = (float) (
                $row['planned_cost'] ?? 0
            );
        }
    }
    $plannedStmt->close();
}

$annualBudget = 0.0;
$annualActual = 0.0;
$annualPlanned = 0.0;
$monthsExceeded = 0;
foreach ($months as $monthData) {
    $annualBudget += (float) $monthData['budget'];
    $annualActual += (float) $monthData['actual'];
    $annualPlanned += (float) $monthData['planned'];
    if (
        (float) $monthData['budget'] > 0
        && (float) $monthData['actual']
            > (float) $monthData['budget']
    ) {
        $monthsExceeded++;
    }
}
$annualVariance = $annualBudget - $annualActual;
$annualUsage = $annualBudget > 0
    ? ($annualActual / $annualBudget) * 100
    : 0.0;

$selectedMonth = (int) ($_GET['edit_month'] ?? date('n'));
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int) date('n');
}
$selectedBudget = $months[$selectedMonth];

$pageTitle = 'Maintenance Budget';
$activeMenu = 'preventive_maintenance';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.budget-wrap{padding:24px}.budget-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px}.budget-actions{display:flex;gap:8px;align-items:center}.budget-btn{display:inline-block;border:0;background:#173b73;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:800;cursor:pointer}.budget-btn.secondary{background:#e8eef7;color:#173b73}.budget-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.budget-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px}.budget-kpi span{display:block;color:#64748b;font-size:13px}.budget-kpi strong{display:block;color:#173b73;font-size:27px;margin-top:6px}.budget-negative{color:#b91c1c!important}.budget-positive{color:#15803d!important}.budget-layout{display:grid;grid-template-columns:1fr 1.5fr;gap:14px}.budget-field{margin-bottom:12px}.budget-field label{display:block;font-weight:800;margin-bottom:5px}.budget-field input,.budget-field select,.budget-field textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.budget-alert{padding:12px;border-radius:8px;background:#dcfce7;color:#166534;margin:10px 0}.budget-error{background:#fee2e2;color:#991b1b}.budget-table{width:100%;border-collapse:collapse}.budget-table th,.budget-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.budget-badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.budget-ok{background:#dcfce7;color:#166534}.budget-warning{background:#fef3c7;color:#92400e}.budget-exceeded{background:#fee2e2;color:#991b1b}.budget-progress{width:110px;height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:5px}.budget-progress span{display:block;height:100%;background:#16a34a}.budget-progress .warning{background:#d97706}.budget-progress .exceeded{background:#dc2626}.budget-note{max-width:220px;color:#64748b;font-size:12px}@media(max-width:950px){.budget-wrap{padding:14px}.budget-head{flex-direction:column}.budget-kpis{grid-template-columns:repeat(2,1fr)}.budget-layout{grid-template-columns:1fr}.budget-table{display:block;overflow-x:auto}.budget-actions{flex-wrap:wrap}}
</style>
<div class="budget-wrap">
    <div class="budget-head">
        <div>
            <a href="preventive_maintenance.php">← Preventive Maintenance</a>
            <h1>Maintenance Budget & Cost Variance</h1>
            <p>Monthly budget control for <?php echo $year; ?>.</p>
        </div>
        <div class="budget-actions">
            <a class="budget-btn secondary"
               href="?year=<?php echo $year - 1; ?>">←</a>
            <strong><?php echo $year; ?></strong>
            <a class="budget-btn secondary"
               href="?year=<?php echo $year + 1; ?>">→</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="budget-alert"><?php echo cpmsPmEscape($success); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="budget-alert budget-error"><?php echo cpmsPmEscape($error); ?></div>
    <?php endforeach; ?>

    <section class="budget-kpis">
        <div class="budget-card budget-kpi">
            <span>Annual Budget</span>
            <strong>RM <?php echo number_format($annualBudget, 2); ?></strong>
        </div>
        <div class="budget-card budget-kpi">
            <span>Actual Cost</span>
            <strong>RM <?php echo number_format($annualActual, 2); ?></strong>
        </div>
        <div class="budget-card budget-kpi">
            <span>Budget Balance</span>
            <strong class="<?php echo $annualVariance < 0 ? 'budget-negative' : 'budget-positive'; ?>">
                RM <?php echo number_format($annualVariance, 2); ?>
            </strong>
        </div>
        <div class="budget-card budget-kpi">
            <span>Budget Usage / Exceeded Months</span>
            <strong><?php echo number_format($annualUsage, 1); ?>%</strong>
            <small><?php echo $monthsExceeded; ?> month(s) exceeded</small>
        </div>
    </section>

    <section class="budget-layout">
        <div class="budget-card">
            <h2>Set Monthly Budget</h2>
            <form method="post">
                <input type="hidden" name="csrf_token"
                       value="<?php echo cpmsPmEscape(cpmsPmCsrfToken()); ?>">
                <input type="hidden" name="budget_year"
                       value="<?php echo $year; ?>">
                <div class="budget-field">
                    <label>Month</label>
                    <select name="budget_month"
                            onchange="window.location='?year=<?php echo $year; ?>&edit_month='+this.value">
                        <?php foreach ($months as $monthData): ?>
                            <option value="<?php echo $monthData['month']; ?>"
                                <?php echo $selectedMonth === $monthData['month'] ? 'selected' : ''; ?>>
                                <?php echo cpmsPmEscape($monthData['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="budget-field">
                    <label>Budget Amount (RM)</label>
                    <input type="number" name="budget_amount"
                           min="0" step="0.01" required
                           value="<?php echo number_format(
                               (float) $selectedBudget['budget'],
                               2,
                               '.',
                               ''
                           ); ?>">
                </div>
                <div class="budget-field">
                    <label>Warning Threshold (%)</label>
                    <input type="number" name="warning_percent"
                           min="1" max="100" step="1" required
                           value="<?php echo number_format(
                               (float) $selectedBudget['warning'],
                               0,
                               '.',
                               ''
                           ); ?>">
                </div>
                <div class="budget-field">
                    <label>Notes</label>
                    <textarea name="notes" rows="3"><?php echo cpmsPmEscape(
                        $selectedBudget['notes']
                    ); ?></textarea>
                </div>
                <button class="budget-btn">Save Budget</button>
            </form>
        </div>

        <div class="budget-card">
            <h2><?php echo $year; ?> Cost Summary</h2>
            <p>Planned schedule estimate: <strong>RM <?php echo number_format(
                $annualPlanned,
                2
            ); ?></strong></p>
            <table class="budget-table">
                <thead><tr><th>Month</th><th>Budget</th><th>Planned</th><th>Actual</th><th>Variance</th><th>Usage</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($months as $monthData):
                    $budget = (float) $monthData['budget'];
                    $actual = (float) $monthData['actual'];
                    $variance = $budget - $actual;
                    $usage = $budget > 0
                        ? ($actual / $budget) * 100
                        : 0.0;
                    $status = 'No Budget';
                    $statusClass = '';
                    $progressClass = '';
                    if ($budget > 0 && $actual > $budget) {
                        $status = 'Exceeded';
                        $statusClass = 'budget-exceeded';
                        $progressClass = 'exceeded';
                    } elseif (
                        $budget > 0
                        && $usage >= (float) $monthData['warning']
                    ) {
                        $status = 'Warning';
                        $statusClass = 'budget-warning';
                        $progressClass = 'warning';
                    } elseif ($budget > 0) {
                        $status = 'Within Budget';
                        $statusClass = 'budget-ok';
                    }
                ?>
                    <tr>
                        <td><strong><?php echo cpmsPmEscape($monthData['label']); ?></strong><br><small><?php echo (int) $monthData['jobs']; ?> job(s)</small></td>
                        <td>RM <?php echo number_format($budget, 2); ?></td>
                        <td>RM <?php echo number_format((float) $monthData['planned'], 2); ?></td>
                        <td>RM <?php echo number_format($actual, 2); ?></td>
                        <td class="<?php echo $variance < 0 ? 'budget-negative' : 'budget-positive'; ?>">RM <?php echo number_format($variance, 2); ?></td>
                        <td><?php echo $budget > 0 ? number_format($usage, 1) . '%' : '-'; ?>
                            <?php if ($budget > 0): ?><div class="budget-progress"><span class="<?php echo $progressClass; ?>" style="width:<?php echo min(100, $usage); ?>%"></span></div><?php endif; ?>
                        </td>
                        <td><span class="budget-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                        <td><a href="?year=<?php echo $year; ?>&edit_month=<?php echo (int) $monthData['month']; ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
