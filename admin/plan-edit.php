<?php
/** QuizArena — Super Admin: add/edit subscription plan */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$id = (int) get('id', 0);
$plan = $id ? dbrow("SELECT * FROM qa_subscription_plans WHERE id = ?", [$id]) : null;
if ($id && !$plan) { flash('danger', 'Plan not found.'); redirect('admin/plans.php'); }

$error = null;
if (is_post()) {
    csrf_check();
    $d = [
        'name' => trim((string) post('name', '')),
        'type' => in_array(post('type', 'custom'), ['per_quiz', 'per_student', 'monthly', 'yearly', 'custom'], true) ? post('type', 'custom') : 'custom',
        'price' => (float) post('price', 0),
        'student_limit' => (int) post('student_limit', 0) ?: null,
        'quiz_limit' => (int) post('quiz_limit', 0) ?: null,
        'duration_days' => (int) post('duration_days', 0) ?: null,
        'description' => trim((string) post('description', '')),
        'status' => post('status', 'active') === 'inactive' ? 'inactive' : 'active',
    ];
    if (mb_strlen($d['name']) < 3) $error = 'Plan name is required.';
    elseif ($d['price'] < 0) $error = 'Price cannot be negative.';

    if (!$error) {
        if ($id) {
            dbq("UPDATE qa_subscription_plans SET name=?, type=?, price=?, student_limit=?, quiz_limit=?, duration_days=?, description=?, status=? WHERE id=?",
                [$d['name'], $d['type'], $d['price'], $d['student_limit'], $d['quiz_limit'], $d['duration_days'], $d['description'], $d['status'], $id]);
            audit('plan_update', 'Updated plan #' . $id);
            flash('success', 'Plan updated.');
        } else {
            dbq("INSERT INTO qa_subscription_plans (name, type, price, student_limit, quiz_limit, duration_days, description, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$d['name'], $d['type'], $d['price'], $d['student_limit'], $d['quiz_limit'], $d['duration_days'], $d['description'], $d['status']]);
            audit('plan_create', 'Created plan ' . $d['name']);
            flash('success', 'Plan created.');
        }
        redirect('admin/plans.php');
    }
}

$title = $id ? 'Edit Plan' : 'New Plan';
$active = 'plans';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title"><?= $id ? 'Edit Plan' : 'Create Subscription Plan' ?></h5></div>
            <div class="dash-card-body">
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Plan Name *</label>
                            <input class="form-control" name="name" value="<?= e($plan['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Price (<?= e(setting('currency_symbol', '₹')) ?>)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="price" value="<?= e($plan['price'] ?? '0') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Plan Type</label>
                            <select class="form-select" name="type">
                                <?php foreach (['per_quiz' => 'Per Quiz', 'per_student' => 'Per Student', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'custom' => 'Custom'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($plan['type'] ?? 'custom') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Student Limit</label>
                            <input class="form-control" type="number" min="0" name="student_limit" value="<?= e($plan['student_limit'] ?? '') ?>" placeholder="0 = unlimited">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Quiz Limit</label>
                            <input class="form-control" type="number" min="0" name="quiz_limit" value="<?= e($plan['quiz_limit'] ?? '') ?>" placeholder="0 = unlimited">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Duration (days)</label>
                            <input class="form-control" type="number" min="0" name="duration_days" value="<?= e($plan['duration_days'] ?? '') ?>" placeholder="e.g. 30 or 365">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= ($plan['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($plan['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?= e($plan['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary px-4"><?= $id ? 'Save Changes' : 'Create Plan' ?></button>
                            <a class="btn btn-light" href="<?= url('admin/plans.php') ?>">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
