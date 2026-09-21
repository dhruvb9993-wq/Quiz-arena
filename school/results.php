<?php
/** QuizArena — School Admin: all school results */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$q = trim((string) get('q', ''));
$quiz_id = (int) get('quiz_id', 0);
$class_id = (int) get('class_id', 0);
$pass = get('pass', '');
$page = (int) get('p', 1);

$where = "WHERE a.school_id = ?";
$params = [$sid];
if ($q !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; }
if ($quiz_id) { $where .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }
if ($class_id) { $where .= " AND u.class_id = ?"; $params[] = $class_id; }
if ($pass !== '') { $where .= " AND a.pass_status = ?"; $params[] = $pass; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts a JOIN qa_users u ON u.id = a.user_id $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 20, $page, 'school/results.php', ['q' => $q, 'quiz_id' => $quiz_id, 'class_id' => $class_id, 'pass' => $pass]);

$rows = dball(
    "SELECT a.*, u.full_name, u.username, u.profile_photo, u.class_id, c.name AS class_name, q.title AS quiz_title
       FROM qa_quiz_attempts a
       JOIN qa_users u ON u.id = a.user_id
       JOIN qa_quizzes q ON q.id = a.quiz_id
       LEFT JOIN qa_classes c ON c.id = u.class_id
       $where ORDER BY a.id DESC LIMIT $per OFFSET $off", $params
);

$quizzes = dball("SELECT id, title FROM qa_quizzes WHERE school_id = ? ORDER BY id DESC", [$sid]);
$classes = dball("SELECT id, name FROM qa_classes WHERE school_id = ? ORDER BY name", [$sid]);

if (get('export') === 'csv' || get('export') === 'xlsx') {
    $all = dball(
        "SELECT a.*, u.full_name, u.username, u.class_id, c.name AS class_name, q.title AS quiz_title, s.name AS section_name
           FROM qa_quiz_attempts a
           JOIN qa_users u ON u.id = a.user_id
           JOIN qa_quizzes q ON q.id = a.quiz_id
           LEFT JOIN qa_classes c ON c.id = u.class_id
           LEFT JOIN qa_sections s ON s.id = u.section_id
           $where ORDER BY a.id DESC", $params
    );
    $h = ['Student', 'Username', 'Class', 'Section', 'Quiz', 'Score', 'Total Marks', 'Percentage', 'Correct', 'Wrong', 'Unanswered', 'Time (sec)', 'Rank', 'Points', 'Coins', 'Pass/Fail', 'Submitted'];
    $d = array_map(fn($r) => [$r['full_name'], '@' . $r['username'], $r['class_name'] ?? '', $r['section_name'] ?? '', $r['quiz_title'], (float) $r['score'], (float) $r['total_marks'], (float) $r['percentage'], (int) $r['correct_count'], (int) $r['wrong_count'], (int) $r['unanswered_count'], (int) $r['duration_seconds'], $r['rank'] ?? '', (int) $r['points_earned'], (int) $r['coins_earned'], strtoupper($r['pass_status']), $r['submitted_at']], $all);
    get('export') === 'csv' ? export_csv('results.csv', $h, $d) : export_xlsx('results.xlsx', $h, $d);
}

$title = 'School Results';
$active = 'results';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:180px" name="q" placeholder="Search student…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:180px" name="quiz_id">
            <option value="0">All quizzes</option>
            <?php foreach ($quizzes as $qz): ?><option value="<?= (int) $qz['id'] ?>" <?= $quiz_id === (int) $qz['id'] ? 'selected' : '' ?>><?= e(mb_substr($qz['title'], 0, 26)) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:140px" name="class_id">
            <option value="0">All classes</option>
            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $class_id === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:120px" name="pass">
            <option value="">All results</option>
            <option value="pass" <?= $pass === 'pass' ? 'selected' : '' ?>>Pass</option>
            <option value="fail" <?= $pass === 'fail' ? 'selected' : '' ?>>Fail</option>
        </select>
        <button class="btn btn-sm btn-outline-primary">Filter</button>
    </form>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&quiz_id=<?= $quiz_id ?>&class_id=<?= $class_id ?>&pass=<?= urlencode($pass) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        <a class="btn btn-sm btn-outline-success" href="?export=xlsx&q=<?= urlencode($q) ?>&quiz_id=<?= $quiz_id ?>&class_id=<?= $class_id ?>&pass=<?= urlencode($pass) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Student</th><th>Class</th><th>Quiz</th><th class="text-end">Score</th><th>%</th><th class="text-end">Correct/Wrong</th><th class="text-end">Time</th><th>Rank</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="30" height="30" src="<?= e(avatar_url($a)) ?>" alt="">
                            <div><div class="fw-semibold small"><?= e($a['full_name']) ?></div><div class="fs-8 text-muted">@<?= e($a['username']) ?></div></div>
                        </div>
                    </td>
                    <td class="small"><?= e($a['class_name'] ?? '—') ?></td>
                    <td class="small"><?= e(mb_substr($a['quiz_title'], 0, 30)) ?></td>
                    <td class="text-end"><?= round((float) $a['score'], 1) ?>/<?= round((float) $a['total_marks'], 1) ?></td>
                    <td><?= pct_badge($a['percentage']) ?></td>
                    <td class="text-end small text-nowrap">
                        <span class="text-success"><?= (int) $a['correct_count'] ?> ✓</span> /
                        <span class="text-danger"><?= (int) $a['wrong_count'] ?> ✗</span>
                    </td>
                    <td class="text-end small"><?= $a['duration_seconds'] ? gmdate('i:s', (int) $a['duration_seconds']) : '—' ?></td>
                    <td><?= rank_badge($a['rank']) ?></td>
                    <td><span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No results found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
