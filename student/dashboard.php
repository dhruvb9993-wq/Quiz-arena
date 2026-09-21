<?php
/** QuizArena — Student dashboard */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$school = dbrow("SELECT name FROM qa_schools WHERE id = ?", [$sid]);
$class = $user['class_id'] ? dbrow("SELECT name AS class_name FROM qa_classes WHERE id = ?", [$user['class_id']]) : null;
$section = $user['section_id'] ? dbrow("SELECT name AS section_name FROM qa_sections WHERE id = ?", [$user['section_id']]) : null;
$class_name = ($class['class_name'] ?? '') . ($section ? ' · ' . $section['section_name'] : '');

$stats = [
    'quizzes_played' => (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE user_id = ? AND status = 'submitted'", [$user['id']]),
    'total_score' => (float) dbval("SELECT COALESCE(SUM(score),0) FROM qa_quiz_attempts WHERE user_id = ? AND status='submitted'", [$user['id']]),
    'total_points' => (int) dbval("SELECT COALESCE(SUM(points_earned),0) FROM qa_quiz_attempts WHERE user_id = ? AND status='submitted'", [$user['id']]),
    'wallet' => (int) ($user['wallet_balance'] ?? 0),
    'certificates' => (int) dbval("SELECT COUNT(*) FROM qa_certificates WHERE user_id = ?", [$user['id']]),
    'best_pct' => (float) dbval("SELECT COALESCE(MAX(percentage),0) FROM qa_quiz_attempts WHERE user_id = ? AND status='submitted'", [$user['id']]),
];

$rank_info = student_rank_info($user['id'], $sid);

// Available quizzes
$available = dball(
    "SELECT q.*, c.name AS category_name,
            (SELECT COUNT(*) FROM qa_questions x WHERE x.quiz_id = q.id) AS questions,
            (SELECT COUNT(*) FROM qa_quiz_attempts a WHERE a.quiz_id = q.id AND a.user_id = ? AND a.status = 'submitted') AS my_attempts
       FROM qa_quizzes q
       LEFT JOIN qa_categories c ON c.id = q.category_id
      WHERE q.school_id = ? AND q.status = 'published'
        AND (q.end_datetime IS NULL OR q.end_datetime = '0000-00-00 00:00:00' OR q.end_datetime > NOW())
      ORDER BY q.id DESC LIMIT 6", [$user['id'], $sid]
);

$recent = dball(
    "SELECT a.*, q.title AS quiz_title FROM qa_quiz_attempts a
      JOIN qa_quizzes q ON q.id = a.quiz_id
     WHERE a.user_id = ? AND a.status = 'submitted' ORDER BY a.id DESC LIMIT 6", [$user['id']]
);

$title = 'Student Dashboard';
$active = 'dashboard';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><span class="stat-icon tile-indigo"><i class="bi bi-patch-question"></i></span>
            <div><div class="stat-value"><?= $stats['quizzes_played'] ?></div><div class="stat-label">Quizzes Played</div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><span class="stat-icon tile-violet"><i class="bi bi-123"></i></span>
            <div><div class="stat-value"><?= round($stats['total_score'], 1) ?></div><div class="stat-label">Total Score</div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><span class="stat-icon tile-cyan"><i class="bi bi-stars"></i></span>
            <div><div class="stat-value"><?= number_format($stats['total_points']) ?></div><div class="stat-label">Total Points</div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><span class="stat-icon tile-amber"><i class="bi bi-trophy"></i></span>
            <div><div class="stat-value"><?= $rank_info['rank'] ? '#' . $rank_info['rank'] : '—' ?></div><div class="stat-label">Current Rank</div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><span class="stat-icon tile-emerald"><i class="bi bi-award"></i></span>
            <div><div class="stat-value"><?= $stats['certificates'] ?></div><div class="stat-label">Certificates</div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="stat-card text-decoration-none" href="<?= url('student/wallet.php') ?>"><span class="stat-icon tile-rose"><i class="bi bi-coin"></i></span>
            <div><div class="stat-value"><?= fmt_coin($stats['wallet']) ?></div><div class="stat-label">Wallet Balance</div></div></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-header">
                <h5 class="dash-card-title">Available Quizzes</h5>
                <a class="small" href="<?= url('student/quizzes.php') ?>">Browse all →</a>
            </div>
            <div class="dash-card-body">
                <div class="row g-3">
                    <?php foreach ($available as $qz): ?>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <h6 class="fw-bold mb-0"><?= e(mb_substr($qz['title'], 0, 42)) ?></h6>
                                    <span class="badge <?= (int) $qz['entry_fee'] > 0 ? 'badge-soft-warning' : 'badge-soft-success' ?> text-nowrap">
                                        <?= (int) $qz['entry_fee'] > 0 ? fmt_coin($qz['entry_fee']) . ' coins' : 'Free' ?>
                                    </span>
                                </div>
                                <div class="fs-8 text-muted mb-2">
                                    <?= e($qz['category_name'] ?? 'General') ?> · <?= (int) $qz['questions'] ?> Q · <?= (int) $qz['duration_minutes'] ?> min
                                    <?php if ($qz['max_attempts'] > 0): ?> · <?= max(0, (int) $qz['max_attempts'] - (int) $qz['my_attempts']) ?> attempt(s) left<?php endif; ?>
                                </div>
                                <a class="btn btn-sm btn-primary rounded-pill px-3" href="<?= url('quiz/attempt.php?q=' . $qz['id']) ?>">Start Quiz</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$available): ?>
                        <div class="col-12 text-center text-muted py-4">
                            <i class="bi bi-inbox display-5 d-block mb-2"></i>
                            No quizzes available right now. Check back soon!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dash-card">
            <div class="dash-card-header">
                <h5 class="dash-card-title">Recent Quiz History</h5>
                <a class="small" href="<?= url('student/quiz-history.php') ?>">View all →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Quiz</th><th>Date</th><th class="text-end">Score</th><th>%</th><th>Rank</th><th>Coins</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $a): ?>
                        <tr>
                            <td class="small fw-semibold"><a class="text-decoration-none" href="<?= url('student/result.php?id=' . $a['id']) ?>"><?= e(mb_substr($a['quiz_title'], 0, 34)) ?></a></td>
                            <td class="small text-muted"><?= nice_date($a['submitted_at']) ?></td>
                            <td class="text-end"><?= round((float) $a['score'], 1) ?>/<?= round((float) $a['total_marks'], 1) ?></td>
                            <td><?= pct_badge($a['percentage']) ?></td>
                            <td class="small"><?= rank_badge($a['rank']) ?></td>
                            <td class="small text-warning"><?= (int) $a['coins_earned'] ? '+' . $a['coins_earned'] : '—' ?></td>
                            <td><span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><tr><td colspan="7" class="text-center text-muted py-4">Play your first quiz to see history here</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">My Rank</h5></div>
            <div class="dash-card-body text-center">
                <div class="display-4 fw-bold text-gradient"><?= $rank_info['rank'] ? '#' . $rank_info['rank'] : '—' ?></div>
                <div class="text-muted small">of <?= $rank_info['total'] ?> students in your school</div>
                <?php if ($rank_info['change'] !== null): ?>
                    <?php if ($rank_info['change'] > 0): ?><span class="badge badge-soft-success mt-2"><i class="bi bi-arrow-up"></i> +<?= $rank_info['change'] ?> from last quiz</span>
                    <?php elseif ($rank_info['change'] < 0): ?><span class="badge badge-soft-danger mt-2"><i class="bi bi-arrow-down"></i> <?= $rank_info['change'] ?> from last quiz</span>
                    <?php else: ?><span class="badge badge-soft-secondary mt-2">unchanged</span><?php endif; ?>
                <?php else: ?><span class="badge badge-soft-secondary mt-2">no previous rank</span><?php endif; ?>
                <div class="mt-3">
                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Total Points</span><b><?= number_format($stats['total_points']) ?></b></div>
                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Quizzes Played</span><b><?= $stats['quizzes_played'] ?></b></div>
                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Best Score %</span><b><?= round($stats['best_pct'], 1) ?>%</b></div>
                </div>
                <a class="btn btn-sm btn-outline-primary rounded-pill px-4 mt-2" href="<?= url('student/leaderboard.php') ?>">View Leaderboard</a>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">My Details</h5></div>
            <div class="dash-card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img class="rounded-circle object-fit-cover" width="64" height="64" src="<?= e(avatar_url($user)) ?>" alt="">
                    <div>
                        <div class="fw-bold"><?= e($user['full_name']) ?></div>
                        <div class="fs-8 text-muted">@<?= e($user['username']) ?></div>
                    </div>
                </div>
                <div class="small mb-1"><i class="bi bi-building text-muted"></i> <?= e($school['name'] ?? '—') ?></div>
                <div class="small mb-1"><i class="bi bi-diagram-3 text-muted"></i> <?= e($class_name ?: '—') ?></div>
                <div class="small"><i class="bi bi-envelope text-muted"></i> <?= e($user['email']) ?></div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
