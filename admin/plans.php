<?php
/** QuizArena — Super Admin: subscription plans & school subscriptions */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$tab = get('tab', 'plans');

function notify_admin_emails($subject, $message) {
    // Notify all super admins of an event
    foreach (dball("SELECT id FROM qa_users WHERE role = 'superadmin'") as $sa) {
        notify($sa['id'], $subject, $message, 'admin/plans.php?tab=subscriptions');
    }
}

/* Plan actions */
if (is_post() && post('action', '') === 'delete_plan') {
    csrf_check();
    $pid = (int) post('plan_id', 0);
    if (dbval("SELECT COUNT(*) FROM qa_schools WHERE plan_id = ?", [$pid])) {
        flash('danger', 'This plan is assigned to schools and cannot be deleted. Deactivate it instead.');
    } else {
        dbq("DELETE FROM qa_subscription_plans WHERE id = ?", [$pid]);
        audit('plan_delete', 'Deleted plan #' . $pid);
        flash('success', 'Plan deleted.');
    }
    redirect('admin/plans.php');
}

/* Subscription approval */
if (is_post() && post('action', '') === 'approve_sub') {
    csrf_check();
    $sub_id = (int) post('sub_id', 0);
    $sub = dbrow("SELECT * FROM qa_school_subscriptions WHERE id = ?", [$sub_id]);
    if ($sub) {
        db_begin();
        try {
            dbq("UPDATE qa_school_subscriptions SET status = 'approved' WHERE id = ?", [$sub_id]);
            // Create invoice
            $inv = 'INV-' . date('Y') . '-' . strtoupper(random_hex(5));
            dbq("INSERT INTO qa_invoices (invoice_number, school_id, subscription_id, amount, status, issued_at, paid_at)
                 VALUES (?, ?, ?, ?, 'paid', NOW(), NOW())", [$inv, $sub['school_id'], $sub['id'], $sub['amount']]);
            // Apply to school
            dbq("UPDATE qa_schools SET plan_id = ?, subscription_start = ?, subscription_expiry = ?, status = 'active' WHERE id = ?",
                [$sub['plan_id'], $sub['start_date'], $sub['expiry_date'], $sub['school_id']]);
            $school = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$sub['school_id']]);
            db_commit();
            notify_admin_emails('Subscription approved', $school['name'] . ' subscription approved: ' . $inv);
            audit('subscription_approve', 'Approved subscription #' . $sub_id . ' for school #' . $sub['school_id'] . ' (invoice ' . $inv . ')');
            flash('success', 'Subscription approved. Invoice ' . $inv . ' generated.');
        } catch (Throwable $e) { db_rollback(); flash('danger', 'Approval failed.'); }
    }
    redirect('admin/plans.php?tab=subscriptions');
}
if (is_post() && post('action', '') === 'reject_sub') {
    csrf_check();
    dbq("UPDATE qa_school_subscriptions SET status = 'rejected' WHERE id = ?", [(int) post('sub_id', 0)]);
    flash('success', 'Subscription request rejected.');
    redirect('admin/plans.php?tab=subscriptions');
}

$plans = dball("SELECT * FROM qa_subscription_plans ORDER BY price");
$subs = dball(
    "SELECT ss.*, s.name AS school_name, pl.name AS plan_name, pl.type AS plan_type
       FROM qa_school_subscriptions ss
       JOIN qa_schools s ON s.id = ss.school_id
       LEFT JOIN qa_subscription_plans pl ON pl.id = ss.plan_id
      ORDER BY ss.id DESC LIMIT 60"
);
$type_labels = ['per_quiz' => 'Per Quiz', 'per_student' => 'Per Student', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'custom' => 'Custom'];

$title = 'Plans & Subscriptions';
$active = 'plans';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab !== 'subscriptions' ? 'active' : '' ?>" href="?tab=plans">Subscription Plans</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'subscriptions' ? 'active' : '' ?>" href="?tab=subscriptions">School Subscriptions</a></li>
</ul>

<?php if ($tab !== 'subscriptions'): ?>
    <div class="d-flex justify-content-end mb-3">
        <a class="btn btn-sm btn-primary" href="<?= url('admin/plan-edit.php') ?>"><i class="bi bi-plus-lg"></i> New Plan</a>
    </div>
    <div class="row g-3">
        <?php foreach ($plans as $p): ?>
            <div class="col-md-6 col-xl-4">
                <div class="dash-card h-100">
                    <div class="dash-card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="fw-bold mb-1"><?= e($p['name']) ?></h5>
                                <span class="badge bg-secondary-subtle text-secondary"><?= e($type_labels[$p['type']] ?? ucfirst($p['type'])) ?></span>
                                <?php if ($p['status'] !== 'active'): ?><span class="badge badge-soft-danger ms-1">Inactive</span><?php endif; ?>
                            </div>
                            <div class="text-end">
                                <div class="stat-value"><?= e(setting('currency_symbol', '₹')) ?><?= rtrim(rtrim(number_format((float) $p['price'], 2), '0'), '.') ?></div>
                            </div>
                        </div>
                        <div class="small text-muted mt-3 mb-2">
                            <?= e($p['description'] ?? '') ?>
                        </div>
                        <div class="small mb-2">
                            <i class="bi bi-people"></i> <?= $p['student_limit'] ? e(number_format($p['student_limit'])) . ' students' : 'Unlimited students' ?>
                            &nbsp; <i class="bi bi-patch-question"></i> <?= $p['quiz_limit'] ? e(number_format($p['quiz_limit'])) . ' quizzes' : 'Unlimited quizzes' ?>
                            <?= $p['duration_days'] ? ' · ' . (int) $p['duration_days'] . ' days' : '' ?>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-light" href="<?= url('admin/plan-edit.php?id=' . $p['id']) ?>"><i class="bi bi-pencil"></i> Edit</a>
                            <?php if ($p['status'] === 'active'): ?>
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_plan"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-sm btn-light text-warning">Deactivate</button></form>
                            <?php else: ?>
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="activate_plan"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-sm btn-light text-success">Activate</button></form>
                            <?php endif; ?>
                            <form method="post" class="d-inline" data-confirm="Delete this plan?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button></form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    // handle activate/deactivate inline
    if (is_post() && in_array(post('action', ''), ['activate_plan', 'deactivate_plan'], true)) {
        csrf_check();
        $st = post('action') === 'activate_plan' ? 'active' : 'inactive';
        dbq("UPDATE qa_subscription_plans SET status = ? WHERE id = ?", [$st, (int) post('plan_id', 0)]);
        audit('plan_status', 'Set plan #' . post('plan_id') . ' to ' . $st);
        flash('success', 'Plan updated.');
        redirect('admin/plans.php');
    }
    ?>

<?php else: ?>
    <div class="dash-card">
        <div class="table-responsive">
            <table class="table table-dash">
                <thead><tr><th>School</th><th>Plan</th><th>Amount</th><th>Period</th><th>Method</th><th>Status</th><th>Requested</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($subs as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($s['school_name']) ?></td>
                        <td class="small"><?= e($s['plan_name'] ?? '—') ?></td>
                        <td><?= fmt_currency($s['amount']) ?></td>
                        <td class="small"><?= nice_date($s['start_date']) ?> → <?= nice_date($s['expiry_date']) ?></td>
                        <td class="small text-capitalize"><?= e($s['payment_method']) ?></td>
                        <td>
                            <?php $b = ['pending' => 'badge-soft-warning', 'approved' => 'badge-soft-success', 'rejected' => 'badge-soft-danger', 'expired' => 'badge-soft-secondary']; ?>
                            <span class="badge <?= $b[$s['status']] ?? 'badge-soft-secondary' ?>"><?= ucfirst(e($s['status'])) ?></span>
                        </td>
                        <td class="small text-muted"><?= time_ago($s['created_at']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($s['status'] === 'pending'): ?>
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve_sub"><input type="hidden" name="sub_id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-success">Approve</button></form>
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="reject_sub"><input type="hidden" name="sub_id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-light text-danger">Reject</button></form>
                            <?php elseif ($s['status'] === 'approved'): ?>
                                <span class="small text-muted"><?= e($s['invoice_number'] ?? '') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$subs): ?><tr><td colspan="8" class="text-center text-muted py-4">No subscription requests</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
