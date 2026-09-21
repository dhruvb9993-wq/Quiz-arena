<?php
/** QuizArena — School Admin: subscription, invoices & payment history */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$school = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$sid]);
$plan = $school['plan_id'] ? dbrow("SELECT * FROM qa_subscription_plans WHERE id = ?", [$school['plan_id']]) : null;
$plans = dball("SELECT * FROM qa_subscription_plans WHERE status = 'active' ORDER BY price");
$requests = dball("SELECT ss.*, pl.name AS plan_name FROM qa_school_subscriptions ss LEFT JOIN qa_subscription_plans pl ON pl.id = ss.plan_id WHERE ss.school_id = ? ORDER BY ss.id DESC", [$sid]);
$invoices = dball("SELECT * FROM qa_invoices WHERE school_id = ? ORDER BY id DESC", [$sid]);

$error = null;
if (is_post() && post('action', '') === 'request') {
    csrf_check();
    $plan_id = (int) post('plan_id', 0);
    $p = dbrow("SELECT * FROM qa_subscription_plans WHERE id = ? AND status = 'active'", [$plan_id]);
    if (!$p) {
        $error = 'Please select a valid plan.';
    } else {
        $start = date('Y-m-d');
        $expiry = $p['duration_days'] ? date('Y-m-d', strtotime("+{$p['duration_days']} days")) : null;
        $notes = trim((string) post('notes', ''));
        dbq("INSERT INTO qa_school_subscriptions (school_id, plan_id, invoice_number, amount, start_date, expiry_date, status, payment_method, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 'manual', ?, NOW())",
            [$sid, $plan_id, null, $p['price'], $start, $expiry, $notes]);
        audit('subscription_request', 'School #' . $sid . ' requested plan #' . $plan_id);
        flash('success', 'Subscription request submitted. Our team will approve it manually after payment confirmation.');
        redirect('school/subscription.php');
    }
}

$title = 'Subscription';
$active = 'subscription';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Current Subscription</h5></div>
            <div class="dash-card-body">
                <?php
                $expired = $school['subscription_expiry'] && strtotime($school['subscription_expiry']) < time();
                ?>
                <div class="wallet-hero mb-3">
                    <div class="fs-8 text-white-50 text-uppercase fw-bold">Plan</div>
                    <div class="fw-bold fs-4"><?= e($plan['name'] ?? 'No plan assigned') ?></div>
                    <div class="fs-7 text-white-50 mt-1">
                        <?= e($plan ? ($plan['student_limit'] ? $plan['student_limit'] . ' students' : 'Unlimited students') : '') ?>
                        <?= $plan ? ' · ' . ($plan['quiz_limit'] ? $plan['quiz_limit'] . ' quizzes' : 'unlimited quizzes') : '' ?>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Status</span>
                    <span class="badge <?= $expired ? 'badge-soft-danger' : 'badge-soft-success' ?>"><?= $expired ? 'Expired' : 'Active' ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Start date</span><span class="small"><?= $school['subscription_start'] ? nice_date($school['subscription_start']) : '—' ?></span></div>
                <div class="d-flex justify-content-between mb-3"><span class="small text-muted">Expiry date</span><span class="small fw-semibold"><?= $school['subscription_expiry'] ? nice_date($school['subscription_expiry']) : '—' ?></span></div>
                <?php if ($plan): ?>
                    <h6 class="fw-bold fs-7 text-uppercase text-muted mb-2">Usage</h6>
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Students used</span>
                        <span><?= (int) dbval("SELECT COUNT(*) FROM qa_users WHERE school_id = ? AND role='student'", [$sid]) ?> / <?= $plan['student_limit'] ? number_format($plan['student_limit']) : '∞' ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span>Quizzes used</span>
                        <span><?= (int) dbval("SELECT COUNT(*) FROM qa_quizzes WHERE school_id = ?", [$sid]) ?> / <?= $plan['quiz_limit'] ? number_format($plan['quiz_limit']) : '∞' ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Invoices & Payment History</h5></div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Invoice</th><th class="text-end">Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="small fw-semibold"><?= e($inv['invoice_number']) ?></td>
                            <td class="text-end"><?= fmt_currency($inv['amount']) ?></td>
                            <td><span class="badge <?= $inv['status'] === 'paid' ? 'badge-soft-success' : 'badge-soft-warning' ?>"><?= ucfirst(e($inv['status'])) ?></span></td>
                            <td class="small text-muted"><?= nice_date($inv['paid_at'] ?: $inv['issued_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$invoices): ?><tr><td colspan="4" class="text-center text-muted py-3">No invoices yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Choose a Plan / Renew</h5></div>
            <div class="dash-card-body">
                <p class="small text-muted mb-3">Select a plan and submit a request. Payment is currently confirmed manually by the QuizArena team (online payment is coming soon).</p>
                <div class="row g-3">
                    <?php foreach ($plans as $p): ?>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="d-flex justify-content-between align-items-start">
                                    <h6 class="fw-bold mb-1"><?= e($p['name']) ?></h6>
                                    <?php if ($plan && $plan['id'] === $p['id']): ?><span class="badge badge-soft-success">Current</span><?php endif; ?>
                                </div>
                                <div class="fs-5 fw-bold text-primary mb-1"><?= fmt_currency($p['price']) ?><span class="fs-8 text-muted fw-normal">
                                    <?= $p['type'] === 'monthly' ? '/mo' : ($p['type'] === 'yearly' ? '/yr' : ($p['type'] === 'per_quiz' ? ' /quiz/student' : '')) ?></span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <?= $p['student_limit'] ? $p['student_limit'] . ' students' : 'Unlimited students' ?> ·
                                    <?= $p['quiz_limit'] ? $p['quiz_limit'] . ' quizzes' : 'unlimited quizzes' ?>
                                    <?= $p['duration_days'] ? ' · ' . (int) $p['duration_days'] . ' days' : '' ?>
                                </div>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request">
                                    <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                                    <input class="form-control form-control-sm mb-2" name="notes" placeholder="Payment reference / notes (optional)">
                                    <button class="btn btn-sm btn-outline-primary w-100" <?= $plan && $plan['id'] === $p['id'] && !$expired ? 'disabled' : '' ?>>
                                        <?= $plan && $plan['id'] === $p['id'] && !$expired ? 'Already Active' : 'Request This Plan' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Recent Requests</h5></div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Plan</th><th class="text-end">Amount</th><th>Period</th><th>Status</th><th>Requested</th></tr></thead>
                    <tbody>
                    <?php foreach ($requests as $rq): ?>
                        <tr>
                            <td class="small fw-semibold"><?= e($rq['plan_name'] ?? '—') ?></td>
                            <td class="text-end"><?= fmt_currency($rq['amount']) ?></td>
                            <td class="small"><?= nice_date($rq['start_date']) ?> → <?= nice_date($rq['expiry_date']) ?></td>
                            <td>
                                <?php $b = ['pending' => 'badge-soft-warning', 'approved' => 'badge-soft-success', 'rejected' => 'badge-soft-danger', 'expired' => 'badge-soft-secondary']; ?>
                                <span class="badge <?= $b[$rq['status']] ?? '' ?>"><?= ucfirst(e($rq['status'])) ?></span>
                            </td>
                            <td class="small text-muted"><?= time_ago($rq['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$requests): ?><tr><td colspan="5" class="text-center text-muted py-3">No requests yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
