<?php
/** QuizArena — Super Admin: all quiz attempts/results */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$school_id = (int) get('school_id', 0);
$quiz_id = (int) get('quiz_id', 0);
$pass = get('pass', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; }
if ($school_id) { $where .= " AND a.school_id = ?"; $params[] = $school_id; }
if ($quiz_id) { $where .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }
if ($pass !== '') { $where .= " AND a.pass_status = ?"; $params[] = $pass; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts a JOIN qa_users u ON u.id = a.user_id $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 20, $page, 'admin/results.php', ['q' => $q, 'school_id' => $school_id, 'quiz_id' => $quiz_id, 'pass' => $pass]);

$rows = dball(
    "SELECT a.*, u.full_name, u.username, s.name AS school_name, q.title AS quiz_title, c.name AS class_name
       FROM qa_quiz_attempts a
       JOIN qa_users u ON u.id = a.user_id
       JOIN qa_schools s ON s.id = a.school_id
       JOIN qa_quizzes q ON q.id = a.quiz_id
       LEFT JOIN qa_classes c ON c.id = u.class_id
       $where ORDER BY a.id DESC LIMIT $per OFFSET $off", $params
);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");
$quizzes = $school_id ? dball("SELECT id, title FROM qa_quizzes WHERE school_id = ? ORDER BY id DESC", [$school_id]) : [];

if (get('export') === 'csv') {
    $all = dball(
        "SELECT a.*, u.full_name, u.username, s.name AS school_name, q.title AS quiz_title
           FROM qa_quiz_attempts a
           JOIN qa_users u ON u.id = a.user_id
           JOIN qa_schools s ON s.id = a.school_id
           JOIN qa_quizzes q ON q.id = a.quiz_id
           $where ORDER BY a.id DESC", $params
    );
    export_csv('results.csv',
        ['Student', 'Username', 'School', 'Quiz', 'Score', 'Total', 'Percentage', 'Correct', 'Wrong', 'Unanswered', 'Rank', 'Points', 'Coins', 'Pass/Fail', 'Submitted'],
        array_map(fn($r) => [$r['full_name'], '@' . $r['username'], $r['school_name'], $r['quiz_title'], $r['score'], $r['total_marks'], $r['percentage'], $r['correct_count'], $r['wrong_count'], $r['unanswered_count'], $r['rank'] ?? '', $r['points_earned'], $r['coins_earned'], strtoupper($r['pass_status']), $r['submitted_at']], $all));
}

$title = 'All Results';
$active = 'results';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:180px" name="q" placeholder="Search student…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:170px" name="school_id" onchange="this.form.submit()">
            <option value="0">All schools</option>
            <?php foreach ($schools as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 24)) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:170px" name="quiz_id">
            <option value="0">All quizzes</option>
            <?php foreach ($quizzes as $qz): ?><option value="<?= (int) $qz['id'] ?>" <?= $quiz_id === (int) $qz['id'] ? 'selected' : '' ?>><?= e(mb_substr($qz['title'], 0, 24)) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:120px" name="pass">
            <option value="">All</option>
            <option value="pass" <?= $pass === 'pass' ? 'selected' : '' ?>>Pass</option>
            <option value="fail" <?= $pass === 'fail' ? 'selected' : '' ?>>Fail</option>
        </select>
        <button class="btn btn-sm btn-outline-primary">Filter</button>
    </form>
    <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&school_id=<?= $school_id ?>&quiz_id=<?= $quiz_id ?>&pass=<?= urlencode($pass) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Student</th><th>School</th><th>Quiz</th><th class="text-end">Score</th><th>%</th><th>Rank</th><th class="text-end">Coins</th><th>Result</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="30" height="30" src="<?= e(avatar_url($a)) ?>" alt="">
                            <div><div class="fw-semibold small"><?= e($a['full_name']) ?></div><div class="fs-8 text-muted">@<?= e($a['username']) ?></div></div>
                        </div>
                    </td>
                    <td class="small"><?= e(mb_substr($a['school_name'] ?? '—', 0, 22)) ?></td>
                    <td class="small"><?= e(mb_substr($a['quiz_title'], 0, 30)) ?></td>
                    <td class="text-end"><?= round((float) $a['score'], 1) ?></td>
                    <td><?= pct_badge($a['percentage']) ?></td>
                    <td class="small"><?= rank_badge($a['rank']) ?></td>
                    <td class="text-end small text-warning"><?= (int) $a['coins_earned'] ?></td>
                    <td><span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span></td>
                    <td class="small text-muted"><?= time_ago($a['submitted_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No results found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
