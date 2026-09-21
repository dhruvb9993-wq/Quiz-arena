<?php
/** QuizArena — Teacher: my quizzes */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('teacher');

$q = trim((string) get('q', ''));
$status = get('status', '');
$page = (int) get('p', 1);

$where = "WHERE q.school_id = ? AND q.created_by = ?";
$params = [$user['school_id'], $user['id']];
if ($q !== '') { $where .= " AND q.title LIKE ?"; $params[] = "%$q%"; }
if ($status !== '') { $where .= " AND q.status = ?"; $params[] = $status; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quizzes q $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 12, $page, 'teacher/quizzes.php', ['q' => $q, 'status' => $status]);

$rows = dball(
    "SELECT q.*, c.name AS category_name,
            (SELECT COUNT(*) FROM qa_questions x WHERE x.quiz_id = q.id) AS question_count,
            (SELECT COUNT(*) FROM qa_quiz_attempts a WHERE a.quiz_id = q.id AND a.status = 'submitted') AS attempts
       FROM qa_quizzes q
       LEFT JOIN qa_categories c ON c.id = q.category_id
       $where
      ORDER BY q.id DESC LIMIT $per OFFSET $off", $params
);

$status_badge = ['draft' => 'badge-soft-secondary', 'published' => 'badge-soft-success', 'closed' => 'badge-soft-danger'];

$title = 'My Quizzes';
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
    <a class="btn btn-sm btn-primary" href="<?= url('quiz/builder.php') ?>"><i class="bi bi-plus-lg"></i> Create Quiz</a>
</div>

<div class="row g-3">
    <?php foreach ($rows as $r): ?>
        <div class="col-md-6 col-xl-4">
            <div class="dash-card quiz-row h-100">
                <div class="dash-card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h6 class="fw-bold mb-1"><?= e($r['title']) ?></h6>
                        <span class="badge <?= $status_badge[$r['status']] ?? 'badge-soft-secondary' ?>"><?= ucfirst(e($r['status'])) ?></span>
                    </div>
                    <div class="fs-8 text-muted mb-2">
                        <?= e($r['category_name'] ?? 'No category') ?> · <?= (int) $r['question_count'] ?> questions
                        · <?= (int) $r['duration_minutes'] ?> min · <?= fmt_coin($r['entry_fee']) ?> <?= e(coin_name()) ?> fee
                    </div>
                    <div class="small text-muted mb-3">
                        <?php if ($r['start_datetime'] && $r['start_datetime'] !== '0000-00-00 00:00:00'): ?>
                            <i class="bi bi-calendar-event"></i> <?= nice_date($r['start_datetime'], true) ?>
                        <?php else: ?><i class="bi bi-calendar-event"></i> No start time<?php endif; ?>
                        · <i class="bi bi-people"></i> <?= (int) $r['attempts'] ?> attempts
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-primary" href="<?= url('quiz/builder.php?id=' . $r['id']) ?>"><i class="bi bi-pencil"></i> Edit</a>
                        <a class="btn btn-sm btn-light" href="<?= url('teacher/attempts.php?quiz_id=' . $r['id']) ?>"><i class="bi bi-clipboard-data"></i> Attempts</a>
                        <a class="btn btn-sm btn-light" href="<?= url('teacher/analytics.php?quiz_id=' . $r['id']) ?>"><i class="bi bi-graph-up"></i> Analytics</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="col-12 text-center text-muted py-5">
            <i class="bi bi-patch-question display-4 d-block mb-3"></i>
            You have not created any quizzes yet.
            <div class="mt-3"><a class="btn btn-primary rounded-pill px-4" href="<?= url('quiz/builder.php') ?>">Create Your First Quiz</a></div>
        </div>
    <?php endif; ?>
</div>
<?php if ($pager) echo $pager; ?>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
