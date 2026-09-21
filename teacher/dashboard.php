<?php
/** QuizArena — Teacher dashboard */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('teacher');

$stats = [
    'quizzes' => (int) dbval("SELECT COUNT(*) FROM qa_quizzes WHERE created_by = ?", [$user['id']]),
    'published' => (int) dbval("SELECT COUNT(*) FROM qa_quizzes WHERE created_by = ? AND status = 'published'", [$user['id']]),
    'attempts' => (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts a JOIN qa_quizzes q ON q.id = a.quiz_id WHERE q.created_by = ? AND a.status = 'submitted'", [$user['id']]),
    'avg_pct' => (float) dbval("SELECT COALESCE(AVG(a.percentage),0) FROM qa_quiz_attempts a JOIN qa_quizzes q ON q.id = a.quiz_id WHERE q.created_by = ? AND a.status='submitted'", [$user['id']]),
    'certificates' => (int) dbval("SELECT COUNT(*) FROM qa_certificates c JOIN qa_quizzes q ON q.id = c.quiz_id WHERE q.created_by = ?", [$user['id']]),
];

$recent = dball(
    "SELECT a.*, u.full_name, u.username, u.profile_photo, q.title AS quiz_title, c.name AS class_name
       FROM qa_quiz_attempts a
       JOIN qa_users u ON u.id = a.user_id
       JOIN qa_quizzes q ON q.id = a.quiz_id
       LEFT JOIN qa_classes c ON c.id = u.class_id
      WHERE q.created_by = ? AND a.status = 'submitted'
      ORDER BY a.id DESC LIMIT 10", [$user['id']]
);

$best = dball(
    "SELECT q.title, a.score, a.total_marks, a.percentage, a.submitted_at
       FROM qa_quiz_attempts a JOIN qa_quizzes q ON q.id = a.quiz_id
      WHERE q.created_by = ? AND a.status = 'submitted' ORDER BY a.percentage DESC LIMIT 5", [$user['id']]
);

$title = 'Teacher Dashboard';
$active = 'dashboard';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['My Quizzes', $stats['quizzes'], 'bi-patch-question', 'tile-indigo', 'teacher/quizzes.php'],
        ['Published', $stats['published'], 'bi-rocket-takeoff', 'tile-emerald', 'teacher/quizzes.php?status=published'],
        ['Total Attempts', $stats['attempts'], 'bi-lightning', 'tile-violet', 'teacher/attempts.php'],
        ['Avg Score %', round($stats['avg_pct'], 1) . '%', 'bi-percent', 'tile-cyan', 'teacher/analytics.php'],
        ['Certificates Issued', $stats['certificates'], 'bi-award', 'tile-amber', 'teacher/analytics.php'],
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
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header">
                <h5 class="dash-card-title">Recent Student Attempts</h5>
                <a class="small" href="<?= url('teacher/attempts.php') ?>">View all →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Student</th><th>Quiz</th><th>Class</th><th class="text-end">Score</th><th>%</th><th>Status</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $a): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img class="rounded-circle object-fit-cover" width="30" height="30" src="<?= e(avatar_url($a)) ?>" alt="">
                                    <div><div class="fw-semibold small"><?= e($a['full_name']) ?></div><div class="fs-8 text-muted">@<?= e($a['username']) ?></div></div>
                                </div>
                            </td>
                            <td class="small"><?= e(mb_substr($a['quiz_title'], 0, 28)) ?></td>
                            <td class="small"><?= e($a['class_name'] ?? '—') ?></td>
                            <td class="text-end"><?= round((float) $a['score'], 1) ?></td>
                            <td><?= pct_badge($a['percentage']) ?></td>
                            <td><span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span></td>
                            <td class="small text-muted"><?= time_ago($a['submitted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><tr><td colspan="7" class="text-center text-muted py-4">No attempts yet</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Best Performances</h5></div>
            <div class="dash-card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($best as $b): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <div class="small fw-semibold"><?= e(mb_substr($b['title'], 0, 30)) ?></div>
                            <div class="text-end">
                                <span class="badge badge-soft-success"><?= round((float) $b['percentage'], 1) ?>%</span>
                                <div class="fs-8 text-muted mt-1"><?= round((float) $b['score'], 1) ?>/<?= round((float) $b['total_marks'], 1) ?> · <?= time_ago($b['submitted_at']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$best): ?><li class="list-group-item text-muted small py-4 text-center">No completed quizzes yet</li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-body text-center py-4">
                <i class="bi bi-lightbulb display-5 text-warning"></i>
                <h6 class="fw-bold mt-2">Create a new quiz</h6>
                <p class="small text-muted mb-3">Set questions, timer, rewards and publish to your classes.</p>
                <a class="btn btn-primary rounded-pill px-4" href="<?= url('quiz/builder.php') ?>"><i class="bi bi-plus-lg"></i> Create Quiz</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
