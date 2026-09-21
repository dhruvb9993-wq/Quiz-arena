<?php
/** QuizArena — School Admin: view all school quizzes */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$q = trim((string) get('q', ''));
$status = get('status', '');
$page = (int) get('p', 1);

$where = "WHERE q.school_id = ?";
$params = [$sid];
if ($q !== '') { $where .= " AND q.title LIKE ?"; $params[] = "%$q%"; }
if ($status !== '') { $where .= " AND q.status = ?"; $params[] = $status; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quizzes q $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'school/quizzes.php', ['q' => $q, 'status' => $status]);

$rows = dball(
    "SELECT q.*, u.full_name AS teacher_name, c.name AS category_name,
            (SELECT COUNT(*) FROM qa_questions x WHERE x.quiz_id = q.id) AS questions,
            (SELECT COUNT(*) FROM qa_quiz_attempts a WHERE a.quiz_id = q.id AND a.status='submitted') AS attempts,
            (SELECT COUNT(DISTINCT a.user_id) FROM qa_quiz_attempts a WHERE a.quiz_id = q.id) AS participants
       FROM qa_quizzes q
       LEFT JOIN qa_users u ON u.id = q.created_by
       LEFT JOIN qa_categories c ON c.id = q.category_id
       $where ORDER BY q.id DESC LIMIT $per OFFSET $off", $params
);

$status_badge = ['draft' => 'badge-soft-secondary', 'published' => 'badge-soft-success', 'closed' => 'badge-soft-danger'];

$title = 'School Quizzes';
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2" method="get">
        <input class="form-control form-control-sm" style="width:200px" name="q" placeholder="Search quizzes…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:140px" name="status">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'published', 'closed'] as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Quiz</th><th>Teacher</th><th>Category</th><th class="text-center">Questions</th><th class="text-center">Attempts</th><th class="text-center">Students</th><th class="text-center">Fee</th><th>Schedule</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="fw-semibold small"><?= e($r['title']) ?></div>
                        <div class="fs-8 text-muted"><?= (int) $r['duration_minutes'] ?> min · <?= (float) $r['marks_per_question'] ?> mark/q</div>
                    </td>
                    <td class="small"><?= e($r['teacher_name'] ?? '—') ?></td>
                    <td class="small"><?= e($r['category_name'] ?? '—') ?></td>
                    <td class="text-center"><?= (int) $r['questions'] ?></td>
                    <td class="text-center"><?= (int) $r['attempts'] ?></td>
                    <td class="text-center"><?= (int) $r['participants'] ?></td>
                    <td class="text-center"><?= (int) $r['entry_fee'] > 0 ? fmt_coin($r['entry_fee']) : 'Free' ?></td>
                    <td class="small">
                        <?php if ($r['start_datetime'] && $r['start_datetime'] !== '0000-00-00 00:00:00'): ?>
                            <?= nice_date($r['start_datetime'], true) ?><br>
                            <span class="fs-8 text-muted">→ <?= nice_date($r['end_datetime'], true) ?></span>
                        <?php else: ?><span class="text-muted">Always open</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $status_badge[$r['status']] ?? '' ?>"><?= ucfirst(e($r['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No quizzes found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
