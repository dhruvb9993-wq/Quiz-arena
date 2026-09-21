<?php
/** QuizArena — Super Admin: all quizzes across schools */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$school_id = (int) get('school_id', 0);
$status = get('status', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND q.title LIKE ?"; $params[] = "%$q%"; }
if ($school_id) { $where .= " AND q.school_id = ?"; $params[] = $school_id; }
if ($status !== '') { $where .= " AND q.status = ?"; $params[] = $status; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quizzes q $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'admin/quizzes.php', ['q' => $q, 'school_id' => $school_id, 'status' => $status]);

$rows = dball(
    "SELECT q.*, s.name AS school_name, u.full_name AS teacher_name,
            (SELECT COUNT(*) FROM qa_questions x WHERE x.quiz_id = q.id) AS questions,
            (SELECT COUNT(*) FROM qa_quiz_attempts a WHERE a.quiz_id = q.id AND a.status='submitted') AS attempts
       FROM qa_quizzes q
       LEFT JOIN qa_schools s ON s.id = q.school_id
       LEFT JOIN qa_users u ON u.id = q.created_by
       $where ORDER BY q.id DESC LIMIT $per OFFSET $off", $params
);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");

if (get('export') === 'csv') {
    export_csv('quizzes.csv',
        ['ID', 'Title', 'School', 'Created By', 'Status', 'Questions', 'Attempts', 'Duration (min)', 'Entry Fee', 'Created'],
        array_map(fn($r) => [$r['id'], $r['title'], $r['school_name'], $r['teacher_name'], $r['status'], $r['questions'], $r['attempts'], $r['duration_minutes'], $r['entry_fee'], $r['created_at']], $rows));
}

$title = 'All Quizzes';
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:200px" name="q" placeholder="Search quizzes…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:180px" name="school_id">
            <option value="0">All schools</option>
            <?php foreach ($schools as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 26)) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:130px" name="status">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'published', 'closed'] as $st): ?><option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&school_id=<?= $school_id ?>&status=<?= urlencode($status) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        <a class="btn btn-sm btn-primary" href="<?= url('quiz/builder.php?school_id=' . ($school_id ?: 0)) ?>"><i class="bi bi-plus-lg"></i> Create Quiz</a>
    </div>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Quiz</th><th>School</th><th>Created By</th><th class="text-center">Questions</th><th class="text-center">Attempts</th><th class="text-center">Fee</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small fw-semibold"><?= e(mb_substr($r['title'], 0, 42)) ?></td>
                    <td class="small"><?= e(mb_substr($r['school_name'] ?? '—', 0, 24)) ?></td>
                    <td class="small"><?= e($r['teacher_name'] ?? '—') ?></td>
                    <td class="text-center"><?= (int) $r['questions'] ?></td>
                    <td class="text-center"><?= (int) $r['attempts'] ?></td>
                    <td class="text-center"><?= (int) $r['entry_fee'] > 0 ? fmt_coin($r['entry_fee']) : 'Free' ?></td>
                    <td><span class="badge <?= ['draft' => 'badge-soft-secondary', 'published' => 'badge-soft-success', 'closed' => 'badge-soft-danger'][$r['status']] ?? '' ?>"><?= ucfirst(e($r['status'])) ?></span></td>
                    <td class="small text-muted"><?= nice_date($r['created_at']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light" href="<?= url('quiz/builder.php?id=' . $r['id'] . '&school_id=' . $r['school_id']) ?>"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No quizzes found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
