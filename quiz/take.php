<?php
/** QuizArena — Quiz attempt (exam) interface */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];

$att_id = (int) get('att', 0);
$attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ? AND user_id = ?", [$att_id, $user['id']]);
if (!$attempt) { flash('danger', 'Attempt not found.'); redirect('student/quizzes.php'); }
require_school($attempt['school_id']);

$quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);

// Auto-finalize if expired
$expires = strtotime($attempt['started_at']) + (int) $quiz['duration_minutes'] * 60;
if ($attempt['status'] === 'in_progress' && time() > $expires) {
    $res = finalize_attempt($attempt, true);
    $attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ?", [$attempt['id']]);
    if ($attempt['status'] === 'submitted') {
        flash('info', 'Time was up — your quiz was submitted automatically.');
        redirect('student/result.php?id=' . $attempt['id']);
    }
}

// Already submitted → go to result
if ($attempt['status'] === 'submitted') {
    redirect('student/result.php?id=' . $attempt['id']);
}

$questions = attempt_questions($attempt, false);
$answers = attempt_answers_map($attempt['id']);
$remaining = max(0, $expires - time());
$letter = ['A', 'B', 'C', 'D', 'E', 'F'];

$title = $quiz['title'];
$active = 'quizzes';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — Quiz</title>
    <link rel="icon" type="image/png" href="<?= url('assets/img/favicon.png') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
</head>
<body class="dash-body">
<div class="quiz-topbar">
    <div class="container-fluid px-3">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold small d-none d-sm-inline"><?= e(mb_substr($quiz['title'], 0, 40)) ?></span>
            <span class="small text-white-50 d-none d-md-inline">Question <b id="curQ">1</b>/<?= count($questions) ?></span>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="small text-white-50 d-none d-sm-inline" id="saveStatus"><i class="bi bi-cloud"></i> Autosave on</span>
                <span class="quiz-timer" id="timer">--:--</span>
                <button class="btn btn-warning btn-sm rounded-pill px-3" onclick="openSubmitModal()"><i class="bi bi-send"></i> Submit</button>
            </div>
        </div>
        <div class="progress mt-2" style="height:6px">
            <div class="progress-bar bg-success" id="progressBar" style="width:0%"></div>
        </div>
    </div>
</div>

<div class="container-fluid py-3">
    <div class="row g-3">
        <!-- Question area -->
        <div class="col-lg-8">
            <div class="dash-card">
                <div class="dash-card-body" id="questionArea"></div>
                <div class="dash-card-body pt-0 d-flex justify-content-between">
                    <button class="btn btn-light" id="prevBtn" onclick="nav(-1)"><i class="bi bi-arrow-left"></i> Previous</button>
                    <button class="btn btn-primary" id="nextBtn" onclick="nav(1)">Next <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>
        </div>
        <!-- Palette -->
        <div class="col-lg-4">
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Questions</h5>
                    <span class="small text-muted"><span class="text-success">■</span> answered · <span class="text-muted">□</span> unanswered</span>
                </div>
                <div class="dash-card-body">
                    <div class="q-palette" id="palette"></div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-success" onclick="openSubmitModal()"><i class="bi bi-check2-square"></i> Submit Quiz</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submit confirmation modal -->
<div class="modal fade" id="submitModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div class="display-4 mb-2">📤</div>
                <h5 class="fw-bold">Submit your quiz?</h5>
                <p class="text-muted small mb-2">
                    You have answered <b id="modalAnswered">0</b> of <b><?= count($questions) ?></b> questions.
                    <span id="modalUnansweredWrap">There are <b id="modalUnanswered">0</b> unanswered questions.</span>
                </p>
                <p class="text-danger small">Once submitted, your answers cannot be changed.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-light px-4" data-bs-dismiss="modal">Keep Working</button>
                    <button class="btn btn-primary px-4" id="finalSubmitBtn" onclick="finalSubmit()"><i class="bi bi-send"></i> Submit Now</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= url('assets/vendor/bootstrap.bundle.min.js') ?>"></script>
<script>
    window.QA_QUIZ = {
        att: <?= (int) $attempt['id'] ?>,
        remaining: <?= (int) $remaining ?>,
        duration: <?= (int) $quiz['duration_minutes'] * 60 ?>,
        questions: <?= json_encode(array_map(function ($q) use ($letter) {
            return [
                'id' => (int) $q['id'],
                'text' => $q['question_text'],
                'options' => array_map(function ($o, $i) use ($letter) {
                    return ['id' => (int) $o['id'], 'text' => $o['option_text'], 'label' => $letter[$i] ?? chr(65 + $i)];
                }, $q['options'], array_keys($q['options'])),
            ];
        }, $questions)) ?>,
        answers: <?= json_encode($answers) ?>,
        csrf: <?= json_encode(csrf_token()) ?>
    };
</script>
<script src="<?= url('assets/js/quiz.js') ?>"></script>
</body>
</html>
