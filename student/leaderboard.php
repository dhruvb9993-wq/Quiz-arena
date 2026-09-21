<?php
/** QuizArena — Student: my rank + school leaderboard */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$class_id = (int) get('class', 0);
$quiz_id = (int) get('quiz', 0);
$period = in_array(get('period', 'all'), ['today', 'week', 'month', 'year', 'all'], true) ? get('period', 'all') : 'all';

$win = '';
switch ($period) {
    case 'today': $win = "AND a.submitted_at >= CURDATE()"; break;
    case 'week':  $win = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
    case 'month': $win = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)"; break;
    case 'year':  $win = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)"; break;
}

$params = [$sid, $sid];
$extra = "a.school_id = ? AND a.status = 'submitted'";
$user_extra = '';
if ($class_id) { $user_extra = " AND u.class_id = " . (int) $class_id; }
if ($quiz_id) { $extra .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }

$rows = dball(
    "SELECT u.id, u.full_name, u.username, u.profile_photo, u.class_id, c.name AS class_name,
            COUNT(a.id) AS quizzes_played,
            COALESCE(SUM(a.score), 0) AS total_score,
            COALESCE(SUM(a.points_earned), 0) AS total_points,
            COALESCE(SUM(a.coins_earned), 0) AS coins_earned
       FROM qa_users u
       JOIN qa_quiz_attempts a ON a.user_id = u.id AND $extra $win
       LEFT JOIN qa_classes c ON c.id = u.class_id
      WHERE u.role = 'student' AND u.status = 'active' AND u.school_id = ? $user_extra
      GROUP BY u.id, u.full_name, u.username, u.profile_photo, u.class_id, c.name
      ORDER BY total_points DESC, total_score DESC LIMIT 100", $params
);
foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
unset($r);

$my_row = null;
foreach ($rows as $r) { if ((int) $r['id'] === (int) $user['id']) { $my_row = $r; break; } }

$classes = dball("SELECT id, name FROM qa_classes WHERE school_id = ? ORDER BY name", [$sid]);
$quizzes = dball("SELECT id, title FROM qa_quizzes WHERE school_id = ? ORDER BY id DESC", [$sid]);

$title = 'Leaderboard';
$active = 'leaderboard';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<?php if ($my_row): ?>
    <div class="wallet-hero mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="fs-8 text-white-50 text-uppercase fw-bold">Your Position</div>
            <div class="display-5 fw-bold"><?= rank_badge($my_row['rank']) ?></div>
            <div class="fs-8 text-white-50">among <?= count($rows) ?> ranked students</div>
        </div>
        <div class="text-end">
            <div class="fs-7 text-white-50">Points: <b class="text-white"><?= number_format((int) $my_row['total_points']) ?></b></div>
            <div class="fs-7 text-white-50">Quizzes: <b class="text-white"><?= (int) $my_row['quizzes_played'] ?></b></div>
            <div class="fs-7 text-white-50">Coins: <b class="text-white"><?= number_format((int) $my_row['coins_earned']) ?></b></div>
        </div>
    </div>
<?php endif; ?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Period</label>
            <select class="form-select form-select-sm" name="period">
                <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Class</label>
            <select class="form-select form-select-sm" name="class">
                <option value="0">All Classes</option>
                <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $class_id === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Quiz</label>
            <select class="form-select form-select-sm" name="quiz">
                <option value="0">All Quizzes</option>
                <?php foreach ($quizzes as $qz): ?><option value="<?= (int) $qz['id'] ?>" <?= $quiz_id === (int) $qz['id'] ? 'selected' : '' ?>><?= e(mb_substr($qz['title'], 0, 30)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-sm btn-primary w-100">Apply</button>
        </div>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Rank</th><th>Student</th><th>Class</th><th class="text-center">Quizzes</th><th class="text-end">Score</th><th class="text-end">Points</th><th class="text-end">Coins</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= (int) $r['id'] === (int) $user['id'] ? 'table-primary' : '' ?>">
                    <td class="fw-bold"><?= rank_badge($r['rank']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="34" height="34" src="<?= e(avatar_url($r)) ?>" alt="">
                            <div>
                                <div class="fw-semibold small"><?= e($r['full_name']) ?><?= (int) $r['id'] === (int) $user['id'] ? ' <span class="badge badge-soft-primary fs-8">You</span>' : '' ?></div>
                                <div class="fs-8 text-muted">@<?= e($r['username']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="small"><?= e($r['class_name'] ?? '—') ?></td>
                    <td class="text-center"><?= (int) $r['quizzes_played'] ?></td>
                    <td class="text-end"><?= round((float) $r['total_score'], 1) ?></td>
                    <td class="text-end fw-semibold"><?= number_format((int) $r['total_points']) ?></td>
                    <td class="text-end text-warning"><i class="bi bi-coin"></i> <?= number_format((int) $r['coins_earned']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No leaderboard data yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
