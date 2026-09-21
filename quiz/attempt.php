<?php
/** QuizArena — Quiz intro/start page */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$quiz_id = (int) get('q', 0);
$quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$quiz_id]);
if (!$quiz) { flash('danger', 'Quiz not found.'); redirect('student/quizzes.php'); }

$check = quiz_accessible($quiz, $user);
if (!$check['ok']) {
    flash('danger', $check['error']);
    redirect('student/quizzes.php');
}

$resume_attempt = $check['attempt'];
$question_count = (int) dbval("SELECT COUNT(*) FROM qa_questions WHERE quiz_id = ?", [$quiz_id]);
$my_attempts = (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status IN ('submitted','expired')", [$quiz_id, $user['id']]);
$category = dbrow("SELECT * FROM qa_categories WHERE id = ?", [$quiz['category_id']]);

if (is_post()) {
    csrf_check();
    $res = start_attempt($quiz, $user);
    if (!$res['ok']) {
        flash('danger', $res['error']);
        redirect('quiz/attempt.php?q=' . $quiz_id);
    }
    redirect('quiz/take.php?att=' . $res['attempt_id']);
}

$title = 'Start Quiz';
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <span class="badge badge-soft-primary mb-2"><?= e($category['name'] ?? 'General') ?></span>
                        <h4 class="fw-bold mb-1"><?= e($quiz['title']) ?></h4>
                        <p class="text-muted small mb-0"><?= e($quiz['description'] ?: '') ?></p>
                    </div>
                    <?php if ((int) $quiz['entry_fee'] > 0): ?>
                        <span class="badge badge-soft-warning fs-6"><i class="bi bi-coin"></i> <?= fmt_coin($quiz['entry_fee']) ?> <?= e(coin_name()) ?> entry fee</span>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-4">
                    <?php
                    $facts = [
                        ['bi-list-ol', 'Questions', (int) $question_count],
                        ['bi-stopwatch', 'Duration', (int) $quiz['duration_minutes'] . ' minutes'],
                        ['bi-trophy', 'Pass %', (float) $quiz['pass_percentage'] . '%'],
                        ['bi-patch-check', 'Marks per Q', (float) $quiz['marks_per_question']],
                        ['bi-dash-circle', 'Negative', (float) $quiz['negative_marks'] ? '−' . $quiz['negative_marks'] : 'None'],
                        ['bi-arrow-repeat', 'Attempts', $quiz['max_attempts'] > 0 ? (int) $quiz['max_attempts'] : 'Unlimited'],
                    ];
                    foreach ($facts as $f): ?>
                        <div class="col-6 col-md-4">
                            <div class="border rounded-3 p-3 text-center">
                                <div class="fs-4 mb-1"><i class="bi <?= $f[0] ?> text-primary"></i></div>
                                <div class="fw-bold small"><?= $f[2] ?></div>
                                <div class="fs-8 text-muted"><?= $f[1] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($quiz['instructions']): ?>
                    <div class="alert alert-light border mb-4">
                        <h6 class="fw-bold mb-2"><i class="bi bi-journal-text"></i> Instructions</h6>
                        <div class="small text-muted" style="white-space:pre-line"><?= e($quiz['instructions']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($resume_attempt): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-clock-history"></i> You have an in-progress attempt for this quiz (started <?= time_ago($resume_attempt['started_at']) ?>).
                        <b>Time left:</b> <?php
                        $left = strtotime($resume_attempt['started_at']) + (int) $quiz['duration_minutes'] * 60 - time();
                        echo $left > 0 ? gmdate('i:s', $left) . ' minutes' : 'expired — will be submitted automatically';
                        ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <?= csrf_field() ?>
                    <div class="small text-muted">
                        <?php if ((int) $quiz['entry_fee'] > 0): ?>
                            Your wallet balance: <b><i class="bi bi-coin text-warning"></i> <?= fmt_coin($user['wallet_balance'] ?? 0) ?></b> — the entry fee will be deducted when you start.
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-primary btn-lg rounded-pill px-5" type="submit">
                        <?= $resume_attempt ? '<i class="bi bi-play-fill"></i> Resume Quiz' : '<i class="bi bi-play-fill"></i> Start Quiz' ?>
                    </button>
                </form>

                <p class="fs-8 text-muted mt-3 mb-0"><i class="bi bi-shield-check"></i> Answers are auto-saved. If you close the page, you can resume within the time window. Submitting is final — you cannot retake unless attempts allow.</p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
