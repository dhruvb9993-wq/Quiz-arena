<?php
/** QuizArena — Teacher: detailed result view for one attempt */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('teacher');

$id = (int) get('id', 0);
$attempt = dbrow(
    "SELECT a.*, q.title AS quiz_title FROM qa_quiz_attempts a
      JOIN qa_quizzes q ON q.id = a.quiz_id
     WHERE a.id = ? AND q.created_by = ?", [$id, $user['id']]
);
if (!$attempt) { flash('danger', 'Attempt not found.'); redirect('teacher/attempts.php'); }

$detail_user = dbrow("SELECT * FROM qa_users WHERE id = ?", [$attempt['user_id']]);
$detail_quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);

$title = 'Result — ' . $detail_user['full_name'];
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a class="small" href="<?= url('teacher/attempts.php?quiz_id=' . $attempt['quiz_id']) ?>"><i class="bi bi-arrow-left"></i> Back to attempts</a>
        <h5 class="fw-bold mb-0 mt-1"><?= e($detail_user['full_name']) ?> — <?= e($detail_quiz['title']) ?></h5>
    </div>
</div>
<?php
$allow_download = false;
require __DIR__ . '/../app/partials/result_detail.php';
?>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
