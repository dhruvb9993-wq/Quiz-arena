<?php
/** QuizArena — Student: browse available quizzes */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$q = trim((string) get('q', ''));
$category_id = (int) get('category', 0);
$page = (int) get('p', 1);

$where = "WHERE q.school_id = ? AND q.status = 'published'";
$params = [$sid];
if ($q !== '') { $where .= " AND q.title LIKE ?"; $params[] = "%$q%"; }
if ($category_id) { $where .= " AND q.category_id = ?"; $params[] = $category_id; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_quizzes q $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 9, $page, 'student/quizzes.php', ['q' => $q, 'category' => $category_id]);

$rows = dball(
    "SELECT q.*, c.name AS category_name,
            (SELECT COUNT(*) FROM qa_questions x WHERE x.quiz_id = q.id) AS questions,
            (SELECT COUNT(*) FROM qa_quiz_attempts a WHERE a.quiz_id = q.id AND a.user_id = ? AND a.status = 'submitted') AS my_attempts
       FROM qa_quizzes q
       LEFT JOIN qa_categories c ON c.id = q.category_id
       $where ORDER BY q.id DESC LIMIT $per OFFSET $off", array_merge([$user['id']], $params)
);

$categories = dball("SELECT * FROM qa_categories WHERE status = 'active' ORDER BY name");

$title = 'Available Quizzes';
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1">Search</label>
            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search quizzes…">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Category</label>
            <select class="form-select form-select-sm" name="category">
                <option value="0">All categories</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $category_id === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-sm btn-primary w-100">Search</button>
        </div>
    </form>
</div>

<div class="row g-3">
    <?php foreach ($rows as $qz): ?>
        <?php
        $remaining = $qz['max_attempts'] > 0 ? max(0, (int) $qz['max_attempts'] - (int) $qz['my_attempts']) : null;
        $can_start = ($remaining === null || $remaining > 0);
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="dash-card quiz-row h-100">
                <div class="dash-card-body d-flex flex-column">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <span class="badge badge-soft-primary"><?= e($qz['category_name'] ?? 'General') ?></span>
                        <span class="badge <?= (int) $qz['entry_fee'] > 0 ? 'badge-soft-warning' : 'badge-soft-success' ?>">
                            <?= (int) $qz['entry_fee'] > 0 ? '<i class="bi bi-coin"></i> ' . fmt_coin($qz['entry_fee']) . ' entry' : 'Free' ?>
                        </span>
                    </div>
                    <h6 class="fw-bold mb-1"><?= e($qz['title']) ?></h6>
                    <p class="small text-muted flex-grow-1 mb-2"><?= e(mb_substr($qz['description'] ?: 'No description', 0, 90)) ?><?= mb_strlen($qz['description'] ?? '') > 90 ? '…' : '' ?></p>
                    <div class="fs-8 text-muted mb-3">
                        <i class="bi bi-list-ol"></i> <?= (int) $qz['questions'] ?> questions
                        · <i class="bi bi-stopwatch"></i> <?= (int) $qz['duration_minutes'] ?> min
                        · <i class="bi bi-percent"></i> <?= (float) $qz['pass_percentage'] ?>% to pass
                        <?php if ($remaining !== null): ?> · <i class="bi bi-arrow-repeat"></i> <?= $remaining ?> left<?php endif; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fs-8 text-muted">
                            <?php if ((int) $qz['my_attempts'] > 0): ?><i class="bi bi-check-circle text-success"></i> Played <?= (int) $qz['my_attempts'] ?>×<?php else: ?>Not attempted yet<?php endif; ?>
                        </span>
                        <?php if ($can_start): ?>
                            <a class="btn btn-sm btn-primary rounded-pill px-3" href="<?= url('quiz/attempt.php?q=' . $qz['id']) ?>">Start <i class="bi bi-arrow-right"></i></a>
                        <?php else: ?>
                            <span class="badge badge-soft-secondary">Attempts exhausted</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="col-12 text-center text-muted py-5">
            <i class="bi bi-inbox display-4 d-block mb-3"></i>
            No quizzes found. Check back soon!
        </div>
    <?php endif; ?>
</div>
<?php if ($pager) echo $pager; ?>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
