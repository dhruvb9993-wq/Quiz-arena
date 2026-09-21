<?php
/** QuizArena — Public leaderboard (also used by student & school dashboards with extra scope) */
require __DIR__ . '/app/bootstrap.php';

if (setting('leaderboard_public', '1') !== '1' && !current_user()) {
    http403('The leaderboard is currently private. Please sign in to view it.');
}

$school_id = (int) get('school', 0);
$class_id  = (int) get('class', 0);
$quiz_id   = (int) get('quiz', 0);
$period    = in_array(get('period', 'all'), ['today', 'week', 'month', 'year', 'all'], true) ? get('period', 'all') : 'all';

// Period window on attempts.submitted_at
$win = '';
switch ($period) {
    case 'today': $win = "AND a.submitted_at >= CURDATE()"; break;
    case 'week':  $win = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
    case 'month': $win = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)"; break;
    case 'year':  $win = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)"; break;
}

$params = [];
$extra = "a.status = 'submitted'";
$user_extra = '';
if ($school_id) { $extra .= " AND a.school_id = ?"; $params[] = $school_id; }
if ($quiz_id)   { $extra .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }
if ($class_id)  { $user_extra = " AND u.class_id = " . (int) $class_id; }

$rows = dball(
    "SELECT u.id, u.full_name, u.username, u.profile_photo, u.school_id, u.class_id,
            s.name AS school_name, c.name AS class_name,
            COUNT(a.id) AS quizzes_played,
            COALESCE(SUM(a.score), 0) AS total_score,
            COALESCE(SUM(a.points_earned), 0) AS total_points,
            COALESCE(SUM(a.coins_earned), 0) AS coins_earned,
            COALESCE(MAX(a.percentage), 0) AS best_pct
       FROM qa_users u
       JOIN qa_quiz_attempts a ON a.user_id = u.id AND $extra $win
       LEFT JOIN qa_schools s ON s.id = u.school_id
       LEFT JOIN qa_classes c ON c.id = u.class_id
      WHERE u.role = 'student' AND u.status = 'active' AND u.school_id IS NOT NULL $user_extra
        AND (SELECT COUNT(*) FROM qa_quiz_attempts x WHERE x.user_id = u.id AND x.status = 'submitted') > 0
      GROUP BY u.id, u.full_name, u.username, u.profile_photo, u.school_id, u.class_id, s.name, c.name
      ORDER BY total_points DESC, total_score DESC, quizzes_played DESC
      LIMIT 100", $params
);

// Rank numbers
foreach ($rows as $i => &$r) { $r['rank'] = $i + 1; }
unset($r);

$schools = dball("SELECT id, name FROM qa_schools WHERE status = 'active' ORDER BY name");
$classes = $school_id ? dball("SELECT id, name FROM qa_classes WHERE school_id = ? ORDER BY name", [$school_id]) : [];
$quizzes = $school_id ? dball("SELECT id, title FROM qa_quizzes WHERE school_id = ? AND status IN ('published','closed') ORDER BY created_at DESC", [$school_id]) : [];

$title = 'Leaderboard';
$active = 'leaderboard';
require __DIR__ . '/app/layouts/site_header.php';

function lb_medal($rank) { return $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : ($rank == 3 ? '🥉' : '#' . $rank)); }
?>
<section class="page-head">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div>
                <span class="eyebrow">Leaderboard</span>
                <h1 class="display-6 fw-bold mb-0">Top Quiz Champions</h1>
            </div>
        </div>
    </div>
</section>
<section class="section">
    <div class="container">
        <!-- Filters -->
        <form class="filter-bar mb-4" method="get">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Period</label>
                    <select class="form-select form-select-sm" name="period">
                        <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">School</label>
                    <select class="form-select form-select-sm" name="school" onchange="this.form.submit()">
                        <option value="0">All Schools</option>
                        <?php foreach ($schools as $sc): ?>
                            <option value="<?= (int) $sc['id'] ?>" <?= $school_id === (int) $sc['id'] ? 'selected' : '' ?>><?= e($sc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Class</label>
                    <select class="form-select form-select-sm" name="class">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= (int) $cl['id'] ?>" <?= $class_id === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Quiz</label>
                    <select class="form-select form-select-sm" name="quiz">
                        <option value="0">All Quizzes</option>
                        <?php foreach ($quizzes as $q): ?>
                            <option value="<?= (int) $q['id'] ?>" <?= $quiz_id === (int) $q['id'] ? 'selected' : '' ?>><?= e(mb_substr($q['title'], 0, 40)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-1">
                    <button class="btn btn-primary btn-sm w-100">Apply</button>
                </div>
            </div>
        </form>

        <?php if (!$rows): ?>
            <div class="text-center text-muted py-5"><i class="bi bi-trophy display-4 d-block mb-3"></i>No results yet. Be the first champion!</div>
        <?php else: ?>
            <!-- Podium -->
            <div class="lb-podium mb-5">
                <?php
                $podium = [];
                foreach ([2 => 2, 1 => 1, 3 => 3] as $rank => $ignore) {
                    foreach ($rows as $r) { if ($r['rank'] == $rank) { $podium[$rank] = $r; break; } }
                }
                $order = [1 => 3, 2 => 2, 3 => 4]; // columns
                $cls = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];
                $crown = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                foreach ($order as $rank => $col):
                    $r = $podium[$rank] ?? null; ?>
                    <div class="lb-col col-lg-<?= $col ?>">
                        <?php if ($r): ?>
                            <div class="lb-crown"><?= $crown[$rank] ?></div>
                            <img class="lb-avatar <?= $cls[$rank] ?>" src="<?= e(avatar_url($r)) ?>" alt="">
                            <div class="lb-rank-big mt-2"><?= (int) $r['rank'] ?></div>
                            <div class="fw-bold small"><?= e($r['full_name']) ?></div>
                            <div class="text-muted fs-8">@<?= e($r['username']) ?></div>
                            <div class="text-muted fs-8"><?= e($r['school_name'] ?? '—') ?></div>
                            <div class="mt-2"><span class="badge text-bg-primary"><?= number_format((int) $r['total_points']) ?> pts</span></div>
                        <?php else: ?>
                            <div class="lb-crown"><?= $crown[$rank] ?></div>
                            <div class="lb-avatar d-inline-flex align-items-center justify-content-center border"><span class="text-muted">—</span></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Table -->
            <div class="qa-card">
                <div class="table-responsive">
                    <table class="table table-modern">
                        <thead>
                        <tr>
                            <th>Rank</th><th>Student</th><th>School</th><th>Class</th>
                            <th class="text-center">Quizzes</th><th class="text-end">Total Score</th>
                            <th class="text-end">Points</th><th class="text-end">Coins</th><th class="text-end">Best %</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><b><?= lb_medal($r['rank']) ?></b></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img class="rounded-circle object-fit-cover" width="34" height="34" src="<?= e(avatar_url($r)) ?>" alt="">
                                        <div>
                                            <div class="fw-semibold"><?= e($r['full_name']) ?></div>
                                            <div class="text-muted fs-8">@<?= e($r['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="small"><?= e($r['school_name'] ?? '—') ?></td>
                                <td class="small"><?= e($r['class_name'] ?? '—') ?></td>
                                <td class="text-center"><?= (int) $r['quizzes_played'] ?></td>
                                <td class="text-end"><?= round((float) $r['total_score'], 1) ?></td>
                                <td class="text-end fw-semibold"><?= number_format((int) $r['total_points']) ?></td>
                                <td class="text-end text-warning"><i class="bi bi-coin"></i> <?= number_format((int) $r['coins_earned']) ?></td>
                                <td class="text-end"><?= round((float) $r['best_pct'], 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
