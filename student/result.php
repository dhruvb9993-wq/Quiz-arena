<?php
/** QuizArena — Student: detailed quiz result */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];

$id = (int) get('id', 0);
$attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ? AND user_id = ?", [$id, $user['id']]);
if (!$attempt) { flash('danger', 'Result not found.'); redirect('student/quiz-history.php'); }
require_school($attempt['school_id']);

$detail_user = $user;
$detail_quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);
$certificate = dbrow("SELECT * FROM qa_certificates WHERE attempt_id = ?", [$attempt['id']]);
$allow_download = true;

$title = 'Quiz Result';
$active = 'history';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a class="small" href="<?= url('student/quiz-history.php') ?>"><i class="bi bi-arrow-left"></i> Back to history</a>
        <h5 class="fw-bold mb-0 mt-1"><?= e($detail_quiz['title']) ?> — Result</h5>
    </div>
    <div class="d-flex gap-2">
        <?php if ($certificate && $certificate['file_path']): ?>
            <a class="btn btn-outline-success btn-sm rounded-pill px-3" href="<?= url('student/certificate-download.php?id=' . $certificate['certificate_id']) ?>"><i class="bi bi-download"></i> Download Certificate</a>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>
<?php require __DIR__ . '/../app/partials/result_detail.php'; ?>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
