<?php
/** QuizArena — Super Admin Dashboard */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$stats = [
    'schools'        => (int) dbval("SELECT COUNT(*) FROM qa_schools"),
    'active_schools' => (int) dbval("SELECT COUNT(*) FROM qa_schools WHERE status = 'active'"),
    'expired'        => (int) dbval("SELECT COUNT(*) FROM qa_schools WHERE status = 'expired' OR subscription_expiry < CURDATE()"),
    'users'          => (int) dbval("SELECT COUNT(*) FROM qa_users"),
    'students'       => (int) dbval("SELECT COUNT(*) FROM qa_users WHERE role = 'student'"),
    'teachers'       => (int) dbval("SELECT COUNT(*) FROM qa_users WHERE role = 'teacher'"),
    'quizzes'        => (int) dbval("SELECT COUNT(*) FROM qa_quizzes"),
    'attempts'       => (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE status = 'submitted'"),
    'certificates'   => (int) dbval("SELECT COUNT(*) FROM qa_certificates"),
    'coins'          => (int) dbval("SELECT COALESCE(SUM(balance), 0) FROM qa_wallets"),
    'revenue'        => (float) dbval("SELECT COALESCE(SUM(amount), 0) FROM qa_invoices WHERE status = 'paid'"),
];

// Recent registrations
$recent_users = dball(
    "SELECT u.id, u.full_name, u.username, u.role, u.status, u.created_at, s.name AS school_name
       FROM qa_users u LEFT JOIN qa_schools s ON s.id = u.school_id
      ORDER BY u.id DESC LIMIT 8"
);
// Recent attempts
$recent_attempts = dball(
    "SELECT a.id, a.score, a.total_marks, a.percentage, a.status, a.submitted_at, a.pass_status,
            u.full_name, u.username, q.title AS quiz_title, s.name AS school_name
       FROM qa_quiz_attempts a
       JOIN qa_users u ON u.id = a.user_id
       JOIN qa_quizzes q ON q.id = a.quiz_id
       JOIN qa_schools s ON s.id = a.school_id
      ORDER BY a.id DESC LIMIT 8"
);
// Recent wallet transactions
$recent_txns = dball(
    "SELECT t.*, u.username FROM qa_wallet_transactions t
      JOIN qa_users u ON u.id = t.user_id
     ORDER BY t.id DESC LIMIT 8"
);

// Charts data
$attempts_14d = dball(
    "SELECT DATE(submitted_at) d, COUNT(*) c FROM qa_quiz_attempts
      WHERE status = 'submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      GROUP BY DATE(submitted_at) ORDER BY d"
);
$labels14 = []; $values14 = [];
$start = strtotime('-13 days');
for ($i = 0; $i < 14; $i++) {
    $key = date('Y-m-d', $start + $i * 86400);
    $labels14[] = date('d M', $start + $i * 86400);
    $values14[] = 0;
}
foreach ($attempts_14d as $a) {
    $idx = array_search($a['d'], array_map(fn($t) => date('Y-m-d', $t), array_map(fn($i) => $start + $i * 86400, range(0, 13))));
    if ($idx !== false) $values14[$idx] = (int) $a['c'];
}

$school_status = dball("SELECT status, COUNT(*) c FROM qa_schools GROUP BY status");
$rev_monthly = dball(
    "SELECT DATE_FORMAT(paid_at, '%Y-%m') m, SUM(amount) total FROM qa_invoices
      WHERE status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
      GROUP BY DATE_FORMAT(paid_at, '%Y-%m') ORDER BY m"
);

$title = 'Super Admin Dashboard';
$active = 'dashboard';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Schools', $stats['schools'], 'bi-building', 'tile-indigo', 'admin/schools.php'],
        ['Active Schools', $stats['active_schools'], 'bi-check-circle', 'tile-emerald', 'admin/schools.php'],
        ['Expired Subs', $stats['expired'], 'bi-exclamation-triangle', 'tile-rose', 'admin/schools.php'],
        ['Total Users', $stats['users'], 'bi-people', 'tile-violet', 'admin/users.php'],
        ['Students', $stats['students'], 'bi-mortarboard', 'tile-cyan', 'admin/users.php?role=student'],
        ['Teachers', $stats['teachers'], 'bi-person-video3', 'tile-amber', 'admin/users.php?role=teacher'],
        ['Quizzes', $stats['quizzes'], 'bi-patch-question', 'tile-indigo', 'admin/quizzes.php'],
        ['Attempts', $stats['attempts'], 'bi-lightning', 'tile-violet', 'admin/results.php'],
        ['Certificates', $stats['certificates'], 'bi-award', 'tile-emerald', 'admin/certificates.php'],
        ['Quiz Coins in System', fmt_coin($stats['coins']), 'bi-coin', 'tile-amber', 'admin/wallets.php'],
        ['Paid Revenue', fmt_currency($stats['revenue']), 'bi-cash-stack', 'tile-emerald', 'admin/reports.php'],
        ['Plans', dbcount('qa_subscription_plans', "status='active'"), 'bi-card-checklist', 'tile-rose', 'admin/plans.php'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <a class="stat-card text-decoration-none" href="<?= url($c[4]) ?>">
                <span class="stat-icon <?= $c[3] ?>"><i class="bi <?= $c[2] ?>"></i></span>
                <div>
                    <div class="stat-value"><?= $c[1] ?></div>
                    <div class="stat-label"><?= $c[0] ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Quiz Activity — last 14 days</h5></div>
            <div class="dash-card-body"><canvas id="attemptsChart" height="120"></canvas></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header">
                <h5 class="dash-card-title">Recent Quiz Attempts</h5>
                <a class="small" href="<?= url('admin/results.php') ?>">View all →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Student</th><th>Quiz</th><th>School</th><th class="text-end">Score</th><th>Status</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_attempts as $a): ?>
                        <tr>
                            <td><b><?= e($a['full_name']) ?></b> <span class="text-muted fs-8">@<?= e($a['username']) ?></span></td>
                            <td class="small"><?= e(mb_substr($a['quiz_title'], 0, 30)) ?></td>
                            <td class="small"><?= e(mb_substr($a['school_name'] ?? '—', 0, 22)) ?></td>
                            <td class="text-end"><?= round((float) $a['score'], 1) ?>/<?= round((float) $a['total_marks'], 1) ?></td>
                            <td>
                                <?php if ($a['status'] === 'submitted'): ?>
                                    <span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span>
                                <?php else: ?><span class="badge badge-soft-secondary"><?= e($a['status']) ?></span><?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= time_ago($a['submitted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent_attempts): ?><tr><td colspan="6" class="text-center text-muted py-4">No attempts yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">School Status</h5></div>
            <div class="dash-card-body"><canvas id="statusChart" height="150"></canvas></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Revenue (12 months)</h5></div>
            <div class="dash-card-body"><canvas id="revenueChart" height="150"></canvas></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Recent Registrations</h5><a class="small" href="<?= url('admin/users.php') ?>">All →</a></div>
            <div class="dash-card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($recent_users as $u): ?>
                        <li class="list-group-item d-flex align-items-center gap-2 py-2">
                            <img class="rounded-circle object-fit-cover" width="32" height="32" src="<?= e(avatar_url($u)) ?>" alt="">
                            <div class="flex-grow-1">
                                <div class="small fw-semibold"><?= e($u['full_name']) ?></div>
                                <div class="fs-8 text-muted"><?= e(role_label($u['role'])) ?><?= $u['school_name'] ? ' · ' . e(mb_substr($u['school_name'], 0, 24)) : '' ?></div>
                            </div>
                            <span class="fs-8 text-muted"><?= time_ago($u['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Recent Wallet Transactions</h5><a class="small" href="<?= url('admin/transactions.php') ?>">All →</a></div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <tbody>
                    <?php foreach ($recent_txns as $t): ?>
                        <tr>
                            <td class="small">@<?= e($t['username']) ?></td>
                            <td class="small text-muted"><?= e($t['category']) ?></td>
                            <td class="text-end <?= $t['type'] === 'credit' ? 'text-success' : 'text-danger' ?> fw-semibold">
                                <?= $t['type'] === 'credit' ? '+' : '−' ?><?= number_format((int) $t['amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent_txns): ?><tr><td colspan="3" class="text-center text-muted py-3">No transactions yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = "<script>
new Chart(document.getElementById('attemptsChart'), { type: 'line', data: { labels: " . json_encode($labels14) . ", datasets: [{ label: 'Attempts', data: " . json_encode($values14) . ", borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.12)', fill: true, tension: .35, pointRadius: 3 }] }, options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
new Chart(document.getElementById('statusChart'), { type: 'doughnut', data: { labels: " . json_encode(array_map(fn($s) => ucfirst($s['status']), $school_status)) . ", datasets: [{ data: " . json_encode(array_map(fn($s) => (int) $s['c'], $school_status)) . ", backgroundColor: ['#059669','#d97706','#dc2626'] }] }, options: { plugins: { legend: { position: 'bottom' } } } });
new Chart(document.getElementById('revenueChart'), { type: 'bar', data: { labels: " . json_encode(array_map(fn($r) => $r['m'], $rev_monthly)) . ", datasets: [{ label: 'Revenue', data: " . json_encode(array_map(fn($r) => (float) $r['total'], $rev_monthly)) . ", backgroundColor: '#8b5cf6', borderRadius: 6 }] }, options: { plugins: { legend: { display: false } } } });
</script>";
require __DIR__ . '/../app/layouts/dash_footer.php';
