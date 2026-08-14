<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/checklist_service.php';
cpmsRequire('inspection.checklist.manage', $conn);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsChecklistVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid.';
    } else {
        $lines = preg_split('/\R/', (string) ($_POST['items'] ?? ''));
        $items = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (($parts[0] ?? '') === '') {
                continue;
            }
            $items[] = [
                'name' => $parts[0],
                'weight' => max(0.01, (float) ($parts[1] ?? 1)),
                'required' => strtolower((string) ($parts[2] ?? 'required'))
                    !== 'optional',
            ];
        }
        try {
            cpmsChecklistCreateTemplate($conn, $propertyId, $_POST, $items);
            header('Location: checklist_templates.php?created=1');
            exit();
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}
$pageTitle = 'Create Checklist Template'; $activeMenu = 'inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>.ck-wrap{max-width:850px;padding:24px;margin:auto}.ck-card{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:22px}.ck-form{display:grid;gap:12px}.ck-form input,.ck-form textarea{padding:12px;border:1px solid #cbd5e1;border-radius:9px}.ck-form textarea{min-height:220px}.ck-btn{border:0;background:#173b73;color:#fff;padding:11px 15px;border-radius:9px;font-weight:800}.ck-error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:9px}</style>
<div class="ck-wrap"><a href="checklist_templates.php">← Templates</a><h1>Create Checklist Template</h1>
<?php foreach ($errors as $error): ?><div class="ck-error"><?php echo cpmsChecklistEscape($error); ?></div><?php endforeach; ?>
<section class="ck-card"><form method="post" class="ck-form">
<input type="hidden" name="csrf_token" value="<?php echo cpmsChecklistEscape(cpmsChecklistCsrfToken()); ?>">
<label>Template Name</label><input name="template_name" required>
<label>Category</label><input name="category" value="General" required>
<label>Description</label><textarea name="description" style="min-height:80px"></textarea>
<label>Passing Score (%)</label><input type="number" name="passing_score" min="1" max="100" step="0.01" value="80" required>
<label>Checklist Items — one item per line</label>
<textarea name="items" required placeholder="Fire extinguisher is valid|10|required&#10;Emergency light is working|5|required&#10;Area is clean|2|optional"></textarea>
<small>Format: Item name | Weight | required/optional</small>
<button class="ck-btn">Create Template</button></form></section></div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
