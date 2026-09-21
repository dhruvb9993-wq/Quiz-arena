<?php
/** QuizArena — School Admin dashboard */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$school = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$sid]);
$plan = $school['plan_id'] ? dbrow("SELECT * FROM qa_subscription_plans WHERE id = ?", [$school['plan_id']]) : null;

$stats = [
    'students' => (int) dbval("SELECT COUNT(*) FROM qa_users WHERE school_id = ? AND role = 'student' AND status = 'active'", [$sid]),
    'teachers' => (int) dbval("SELECT COUNT(*) FROM qa_users WHERE school_id = ? AND role = 'teacher' AND status = 'active'", [$sid]),
    'classes' => (int) dbval("SELECT COUNT(*) FROM qa_classes WHERE school_id = ?", [$sid]),
    'quizzes' => (int) dbval("SELECT COUNT(*) FROM qa_quizzes WHERE school_id = ?", [$sid]),
    'attempts' => (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE school_id = ? AND status = 'submitted'", [$sid]),
    'certificates' => (int) dbval("SELECT COUNT(*) FROM qa_certificates WHERE school_id = ?", [$sid]),
    'coins' => (int) dbval("SELECT COALESCE(SUM(balance),0) FROM qa_wallets WHERE school_id = ?", [$sid]),
];

$expired = $school['subscription_expiry'] && strtotime($school['subscription_expiry']) < time();
$days_left = $school['subscription_expiry'] ? (int) ceil((strtotime($school['subscription_expiry']) - time()) / 86400) : null;

$recent = dball(
    "SELECT a.*, u.full_name, u.username, u.profile_photo, q.title AS quiz_title, c.name AS class_name
       FROM qa_quiz_attempts a
       JOIN qa_users u ON u.id = a.user_id
       JOIN qa_quizzes q ON q.id = a.quiz_id
       LEFT JOIN qa_classes c ON c.id = u.class_id
      WHERE a.school_id = ? AND a.status = 'submitted'
      ORDER BY a.id DESC LIMIT 8", [$sid]
);

$attempts_14d = dball(
    "SELECT DATE(submitted_at) d, COUNT(*) c FROM qa_quiz_attempts
      WHERE school_id = ? AND status = 'submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      GROUP BY DATE(submitted_at) ORDER BY d", [$sid]
);
$start = strtotime('-13 days');
$labels14 = []; $values14 = [];
for ($i = 0; $i < 14; $i++) { $labels14[] = date('d M', $start + $i * 86400); $values14[] = 0; }
foreach ($attempts_14d as $a) {
    $idx = (int) ((strtotime($a['d']) - $start) / 86400);
    if ($idx >= 0 && $idx < 14) $values14[$idx] = (int) $a['c'];
}

$title = 'School Dashboard';
$active = 'dashboard';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<?php if ($expired): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> Your subscription has expired. Please renew it from the <a class="alert-link" href="<?= url('school/subscription.php') ?>">Subscription page</a> to continue using QuizArena.</div>
<?php elseif ($school['subscription_expiry'] && $days_left !== null && $days_left <= 15): ?>
    <div class="alert alert-warning"><i class="bi bi-hourglass-split"></i> Your subscription expires on <?= nice_date($school['subscription_expiry']) ?> (<?= $days_left ?> day<?= $days_left == 1 ? '' : 's' ?> left). <a class="alert-link" href="<?= url('school/subscription.php') ?>">Renew now</a>.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Students', $stats['students'], 'bi-mortarboard', 'tile-indigo', 'school/students.php'],
        ['Teachers', $stats['teachers'], 'bi-person-video3', 'tile-violet', 'school/teachers.php'],
        ['Classes', $stats['classes'], 'bi-diagram-3', 'tile-cyan', 'school/classes.php'],
        ['Quizzes', $stats['quizzes'], 'bi-patch-question', 'tile-emerald', 'school/quizzes.php'],
        ['Attempts', $stats['attempts'], 'bi-lightning', 'tile-amber', 'school/results.php'],
        ['Certificates', $stats['certificates'], 'bi-award', 'tile-rose', 'school/results.php'],
        ['Coins in School', fmt_coin($stats['coins']), 'bi-coin', 'tile-indigo', 'school/reports.php'],
        ['Plan', $plan['name'] ?? '—', 'bi-card-checklist', 'tile-violet', 'school/subscription.php'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <a class="stat-card text-decoration-none" href="<?= url($c[4]) ?>">
                <span class="stat-icon <?= $c[3] ?>"><i class="bi <?= $c[2] ?>"></i></span>
                <div><div class="stat-value"><?= $c[1] ?></div><div class="stat-label"><?= $c[0] ?></div></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Quiz Activity — last 14 days</h5></div>
            <div class="dash-card-body"><canvas id="activityChart" height="110"></canvas></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header">
                <h5 class="dash-card-title">Recent Results</h5>
                <a class="small" href="<?= url('school/results.php') ?>">View all →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Student</th><th>Quiz</th><th>Class</th><th class="text-end">Score</th><th>%</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $a): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img class="rounded-circle object-fit-cover" width="30" height="30" src="<?= e(avatar_url($a)) ?>" alt="">
                                    <div><div class="fw-semibold small"><?= e($a['full_name']) ?></div><div class="fs-8 text-muted">@<?= e($a['username']) ?></div></div>
                                </div>
                            </td>
                            <td class="small"><?= e(mb_substr($a['quiz_title'], 0, 30)) ?></td>
                            <td class="small"><?= e($a['class_name'] ?? '—') ?></td>
                            <td class="text-end"><?= round((float) $a['score'], 1) ?></td>
                            <td><?= pct_badge($a['percentage']) ?></td>
                            <td><span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-4">No results yet — quizzes will appear here</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Subscription</h5><a class="small" href="<?= url('school/subscription.php') ?>">Manage →</a></div>
            <div class="dash-card-body">
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Plan</span><span class="fw-semibold small"><?= e($plan['name'] ?? 'No plan') ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Status</span>
                    <span class="badge <?= $expired ? 'badge-soft-danger' : ($school['status'] === 'active' ? 'badge-soft-success' : 'badge-soft-warning') ?>">
                        <?= $expired ? 'Expired' : ucfirst(e($school['status'])) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Expires</span><span class="small fw-semibold"><?= $school['subscription_expiry'] ? nice_date($school['subscription_expiry']) : '—' ?></span></div>
                <?php if ($school['subscription_expiry']): ?>
                    <div class="progress mb-2" style="height:6px">
                        <?php
                        $total = max(1, (strtotime($school['subscription_expiry']) - strtotime($school['subscription_start'] ?: $school['subscription_expiry'])) / 86400);
                        $used = max(0, min(100, (time() - strtotime($school['subscription_start'] ?: date('Y-m-d'))) / 86400 / $total * 100));
                        ?>
                        <div class="progress-bar bg-primary" style="width:<?= round($used) ?>%"></div>
                    </div>
                <?php endif; ?>
                <?php if ($plan): ?>
                    <div class="small text-muted mb-1">Usage</div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Students</span><span><?= $stats['students'] ?>/<?= $plan['student_limit'] ? number_format($plan['student_limit']) : '∞' ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span>Quizzes</span><span><?= $stats['quizzes'] ?>/<?= $plan['quiz_limit'] ? number_format($plan['quiz_limit']) : '∞' ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-body text-center py-4">
                <i class="bi bi-person-plus display-5 text-primary"></i>
                <h6 class="fw-bold mt-2">Import students via CSV</h6>
                <p class="small text-muted mb-3">Add hundreds of students in one click.</p>
                <a class="btn btn-outline-primary btn-sm rounded-pill px-4" href="<?= url('school/import-students.php') ?>">Import CSV</a>
            </div>
        </div>
    </div>
</div>

<script>
    new Chart(document.getElementById('activityChart'), {
        type: 'line',
        data: { labels: <?= json_encode($labels14) ?>, datasets: [{ label: 'Attempts', data: <?= json_encode($values14) ?>, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.12)', fill: true, tension: .35 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
</script>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
