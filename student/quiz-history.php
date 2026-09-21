<?php
/** QuizArena — Student: my quiz history */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$q = trim((string) get('q', ''));
$quiz_id = (int) get('quiz', 0);
$pass = get('pass', '');
$from = get('from', '');
$to = get('to', '');
$page = (int) get('p', 1);

$where = "WHERE a.user_id = ? AND a.status = 'submitted'";
$params = [$user['id']];
if ($q !== '') { $where .= " AND q.title LIKE ?"; $params[] = "%$q%"; }
if ($quiz_id) { $where .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }
if ($pass !== '') { $where .= " AND a.pass_status = ?"; $params[] = $pass; }
if ($from) { $where .= " AND DATE(a.submitted_at) >= ?"; $params[] = $from; }
if ($to) { $where .= " AND DATE(a.submitted_at) <= ?"; $params[] = $to; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts a JOIN qa_quizzes q ON q.id = a.quiz_id $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'student/quiz-history.php', ['q' => $q, 'quiz' => $quiz_id, 'pass' => $pass, 'from' => $from, 'to' => $to]);

$rows = dball(
    "SELECT a.*, q.title AS quiz_title
       FROM qa_quiz_attempts a
       JOIN qa_quizzes q ON q.id = a.quiz_id
       $where ORDER BY a.id DESC LIMIT $per OFFSET $off", $params
);

$quizzes = dball("SELECT DISTINCT q.id, q.title FROM qa_quiz_attempts a JOIN qa_quizzes q ON q.id = a.quiz_id WHERE a.user_id = ? ORDER BY q.title", [$user['id']]);

$title = 'My Quiz History';
$active = 'history';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Search</label>
            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Quiz name…">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Quiz</label>
            <select class="form-select form-select-sm" name="quiz">
                <option value="0">All quizzes</option>
                <?php foreach ($quizzes as $qz): ?><option value="<?= (int) $qz['id'] ?>" <?= $quiz_id === (int) $qz['id'] ? 'selected' : '' ?>><?= e(mb_substr($qz['title'], 0, 30)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Result</label>
            <select class="form-select form-select-sm" name="pass">
                <option value="">All</option>
                <option value="pass" <?= $pass === 'pass' ? 'selected' : '' ?>>Passed</option>
                <option value="fail" <?= $pass === 'fail' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">From</label>
            <input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">To</label>
            <input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-12 col-md-2">
            <button class="btn btn-sm btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Quiz</th><th>Date</th><th class="text-end">Score</th><th>%</th><th class="text-end">✓/✗/—</th><th class="text-end">Time</th><th>Rank</th><th class="text-end">Points</th><th class="text-end">Coins</th><th class="text-end">Fee</th><th>Result</th><th>Cert</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <?php $cert = dbval("SELECT COUNT(*) FROM qa_certificates WHERE attempt_id = ?", [$a['id']]); ?>
                <tr>
                    <td class="small fw-semibold"><?= e(mb_substr($a['quiz_title'], 0, 34)) ?></td>
                    <td class="small text-muted"><?= nice_date($a['submitted_at'], true) ?></td>
                    <td class="text-end"><?= round((float) $a['score'], 1) ?>/<?= round((float) $a['total_marks'], 1) ?></td>
                    <td><?= pct_badge($a['percentage']) ?></td>
                    <td class="text-end small text-nowrap">
                        <span class="text-success"><?= (int) $a['correct_count'] ?></span>/<span class="text-danger"><?= (int) $a['wrong_count'] ?></span>/<span class="text-muted"><?= (int) $a['unanswered_count'] ?></span>
                    </td>
                    <td class="text-end small"><?= $a['duration_seconds'] ? gmdate('i:s', (int) $a['duration_seconds']) : '—' ?></td>
                    <td class="small"><?= rank_badge($a['rank']) ?></td>
                    <td class="text-end small"><?= (int) $a['points_earned'] ?></td>
                    <td class="text-end small text-warning"><?= (int) $a['coins_earned'] ?></td>
                    <td class="text-end small"><?= (int) $a['entry_fee'] ? fmt_coin($a['entry_fee']) : '—' ?></td>
                    <td><span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span></td>
                    <td class="small"><?= $cert ? '<i class="bi bi-patch-check-fill text-success"></i>' : '—' ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light" href="<?= url('student/result.php?id=' . $a['id']) ?>"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="13" class="text-center text-muted py-4">No quiz history found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
