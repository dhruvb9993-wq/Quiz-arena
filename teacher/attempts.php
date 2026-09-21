<?php
/** QuizArena — Teacher: view student attempts/results across own quizzes */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('teacher');

$quiz_id = (int) get('quiz_id', 0);
$q = trim((string) get('q', ''));
$pass = get('pass', '');
$page = (int) get('p', 1);

$where = "JOIN qa_quizzes q ON q.id = a.quiz_id WHERE q.created_by = ?";
$params = [$user['id']];
if ($quiz_id) { $where .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }
if ($q !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; }
if ($pass !== '') { $where .= " AND a.pass_status = ?"; $params[] = $pass; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts a $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'teacher/attempts.php', ['quiz_id' => $quiz_id, 'q' => $q, 'pass' => $pass]);

$rows = dball(
    "SELECT a.*, u.full_name, u.username, u.profile_photo, u.class_id, q.title AS quiz_title, c.name AS class_name
       FROM qa_quiz_attempts a $where
      ORDER BY a.id DESC LIMIT $per OFFSET $off", $params
);

$my_quizzes = dball("SELECT id, title FROM qa_quizzes WHERE created_by = ? ORDER BY id DESC", [$user['id']]);

$title = 'Student Attempts';
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Quiz</label>
            <select class="form-select form-select-sm" name="quiz_id">
                <option value="0">All my quizzes</option>
                <?php foreach ($my_quizzes as $mz): ?>
                    <option value="<?= (int) $mz['id'] ?>" <?= $quiz_id === (int) $mz['id'] ? 'selected' : '' ?>><?= e(mb_substr($mz['title'], 0, 40)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Search student</label>
            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Name or username">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Result</label>
            <select class="form-select form-select-sm" name="pass">
                <option value="">All</option>
                <option value="pass" <?= $pass === 'pass' ? 'selected' : '' ?>>Pass</option>
                <option value="fail" <?= $pass === 'fail' ? 'selected' : '' ?>>Fail</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-sm btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Student</th><th>Quiz</th><th>Class</th><th class="text-end">Score</th><th>%</th><th class="text-end">Correct/Wrong</th><th class="text-end">Time</th><th>Result</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="30" height="30" src="<?= e(avatar_url($a)) ?>" alt="">
                            <div><div class="fw-semibold small"><?= e($a['full_name']) ?></div><div class="fs-8 text-muted">@<?= e($a['username']) ?></div></div>
                        </div>
                    </td>
                    <td class="small"><?= e(mb_substr($a['quiz_title'], 0, 30)) ?></td>
                    <td class="small"><?= e($a['class_name'] ?? '—') ?></td>
                    <td class="text-end"><?= round((float) $a['score'], 1) ?>/<?= round((float) $a['total_marks'], 1) ?></td>
                    <td><?= pct_badge($a['percentage']) ?></td>
                    <td class="text-end small text-nowrap">
                        <span class="text-success"><?= (int) $a['correct_count'] ?> ✓</span> /
                        <span class="text-danger"><?= (int) $a['wrong_count'] ?> ✗</span> /
                        <span class="text-muted"><?= (int) $a['unanswered_count'] ?> —</span>
                    </td>
                    <td class="text-end small"><?= $a['duration_seconds'] ? gmdate('i:s', (int) $a['duration_seconds']) : '—' ?></td>
                    <td>
                        <?php if ($a['status'] === 'submitted'): ?>
                            <span class="badge <?= $a['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= strtoupper(e($a['pass_status'])) ?></span>
                        <?php else: ?><span class="badge badge-soft-secondary"><?= e($a['status']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light" href="<?= url('teacher/result.php?id=' . $a['id']) ?>"><i class="bi bi-eye"></i> View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No attempts found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
