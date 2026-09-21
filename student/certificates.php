<?php
/** QuizArena — Student: my certificates */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$page = (int) get('p', 1);
$total = (int) dbval("SELECT COUNT(*) FROM qa_certificates WHERE user_id = ?", [$user['id']]);
[$off, $per, $page, $pages, $pager] = paginate($total, 12, $page, 'student/certificates.php');

$rows = dball(
    "SELECT c.*, q.title AS quiz_title, s.name AS school_name
       FROM qa_certificates c
       LEFT JOIN qa_quizzes q ON q.id = c.quiz_id
       LEFT JOIN qa_schools s ON s.id = c.school_id
      WHERE c.user_id = ?
      ORDER BY c.id DESC LIMIT $per OFFSET $off", [$user['id']]
);

$title = 'My Certificates';
$active = 'certificates';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-3">
    <?php foreach ($rows as $c): ?>
        <div class="col-md-6 col-xl-4">
            <div class="dash-card quiz-row h-100">
                <div class="dash-card-body text-center">
                    <div class="display-4 mb-2">🎓</div>
                    <h6 class="fw-bold"><?= e($c['quiz_title'] ?? 'Certificate') ?></h6>
                    <div class="fs-8 text-muted mb-2"><?= e($c['school_name'] ?? '') ?></div>
                    <div class="mb-2">
                        <span class="badge badge-soft-success"><?= round((float) $c['percentage'], 1) ?>%</span>
                        <?php if ($c['rank']): ?><span class="badge badge-soft-primary">Rank <?= ordinal((int) $c['rank']) ?></span><?php endif; ?>
                        <span class="badge badge-soft-warning"><?= nice_date($c['issue_date']) ?></span>
                    </div>
                    <div class="fs-8 text-muted mb-3">ID: <?= e($c['certificate_id']) ?></div>
                    <div class="d-flex justify-content-center gap-2">
                        <?php if ($c['file_path']): ?>
                            <a class="btn btn-sm btn-primary rounded-pill px-3" href="<?= url('student/certificate-download.php?id=' . urlencode($c['certificate_id'])) ?>"><i class="bi bi-download"></i> PDF</a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-primary rounded-pill px-3" href="<?= url('verify-certificate.php?id=' . urlencode($c['certificate_id'])) ?>" target="_blank"><i class="bi bi-shield-check"></i> Verify</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="col-12 text-center text-muted py-5">
            <i class="bi bi-award display-4 d-block mb-3"></i>
            No certificates yet. Pass a quiz with certificate enabled to earn one!
        </div>
    <?php endif; ?>
</div>
<?php if ($pager) echo $pager; ?>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
