<?php
/**
 * QuizArena — Quiz builder (create/edit quiz + questions).
 * Access: Teachers (their own school) and Super Admin (any school).
 */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login(['teacher', 'superadmin']);

$id = (int) get('id', 0);
$school_id = (int) get('school_id', 0);

$quiz = null;
if ($id) {
    $quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$id]);
    if (!$quiz) { flash('danger', 'Quiz not found.'); redirect('teacher/quizzes.php'); }
    if ($user['role'] === 'teacher' && (int) $quiz['school_id'] !== (int) $user['school_id']) http403();
    if ($user['role'] === 'teacher' && (int) $quiz['created_by'] !== (int) $user['id']) http403();
    $school_id = (int) $quiz['school_id'];
}
if ($user['role'] === 'teacher') $school_id = (int) $user['school_id'];
if (!$school_id) { flash('danger', 'Select a school for this quiz.'); redirect('teacher/quizzes.php'); }
require_school($school_id);

$categories = dball("SELECT * FROM qa_categories WHERE status = 'active' ORDER BY name");
$classes = dball("SELECT * FROM qa_classes WHERE school_id = ? ORDER BY name", [$school_id]);
$sections = dball("SELECT * FROM qa_sections WHERE school_id = ? ORDER BY class_id, name", [$school_id]);

$assignments = [];
if ($quiz) {
    foreach (dball("SELECT * FROM qa_quiz_assignments WHERE quiz_id = ?", [$quiz['id']]) as $a) {
        $assignments[$a['class_id']][$a['section_id']] = true;
    }
}

$error = null;

/* ---------- Delete quiz ---------- */
if (is_post() && post('action', '') === 'delete_quiz') {
    csrf_check();
    db_begin();
    try {
        dbq("DELETE FROM qa_user_answers WHERE attempt_id IN (SELECT id FROM qa_quiz_attempts WHERE quiz_id = ?)", [$quiz['id']]);
        dbq("DELETE FROM qa_quiz_attempts WHERE quiz_id = ?", [$quiz['id']]);
        dbq("DELETE FROM qa_certificates WHERE quiz_id = ?", [$quiz['id']]);
        dbq("DELETE FROM qa_quiz_assignments WHERE quiz_id = ?", [$quiz['id']]);
        dbq("DELETE FROM qa_quiz_rewards WHERE quiz_id = ?", [$quiz['id']]);
        dbq("DELETE FROM qa_answer_options WHERE question_id IN (SELECT id FROM qa_questions WHERE quiz_id = ?)", [$quiz['id']]);
        dbq("DELETE FROM qa_questions WHERE quiz_id = ?", [$quiz['id']]);
        dbq("DELETE FROM qa_quizzes WHERE id = ?", [$quiz['id']]);
        db_commit();
        audit('quiz_delete', 'Deleted quiz #' . $quiz['id'] . ' ' . $quiz['title']);
        flash('success', 'Quiz deleted.');
    } catch (Throwable $e) { db_rollback(); flash('danger', 'Could not delete quiz.'); }
    redirect($user['role'] === 'superadmin' ? 'admin/quizzes.php' : 'teacher/quizzes.php');
}

/* ---------- Save quiz (settings + questions) ---------- */
if (is_post() && post('action', '') === 'save_quiz') {
    csrf_check();

    $d = [
        'title' => trim((string) post('title', '')),
        'description' => trim((string) post('description', '')),
        'category_id' => (int) post('category_id', 0) ?: null,
        'instructions' => trim((string) post('instructions', '')),
        'marks_per_question' => (float) post('marks_per_question', 1),
        'negative_marks' => (float) post('negative_marks', 0),
        'pass_percentage' => (float) post('pass_percentage', 40),
        'duration_minutes' => (int) post('duration_minutes', 10),
        'start_datetime' => post('start_datetime', '') ?: null,
        'end_datetime' => post('end_datetime', '') ?: null,
        'max_attempts' => (int) post('max_attempts', 1),
        'entry_fee' => (int) post('entry_fee', 0),
        'completion_bonus' => (int) post('completion_bonus', 0),
        'passing_reward' => (int) post('passing_reward', 0),
        'rank1_coins' => (int) post('rank1_coins', 0),
        'rank2_coins' => (int) post('rank2_coins', 0),
        'rank3_coins' => (int) post('rank3_coins', 0),
        'randomize_questions' => post('randomize_questions') ? 1 : 0,
        'randomize_options' => post('randomize_options') ? 1 : 0,
        'show_answers' => post('show_answers') ? 1 : 0,
        'enable_certificate' => post('enable_certificate') ? 1 : 0,
        'status' => in_array(post('status', 'draft'), ['draft', 'published', 'closed'], true) ? post('status', 'draft') : 'draft',
        'assign_class' => array_map('intval', (array) post('assign_class', [])),
        'assign_section' => array_map('intval', (array) post('assign_section', [])),
    ];

    if (mb_strlen($d['title']) < 3) $error = 'Quiz title is required (min 3 characters).';
    elseif ($d['duration_minutes'] < 1) $error = 'Quiz duration must be at least 1 minute.';
    elseif ($d['marks_per_question'] <= 0) $error = 'Marks per question must be greater than zero.';
    elseif ($d['start_datetime'] && $d['end_datetime'] && strtotime($d['end_datetime']) <= strtotime($d['start_datetime'])) $error = 'End date/time must be after start date/time.';

    // Collect questions from POST (server-side validation)
    $qtexts = (array) post('q_text', []);
    $qmarks = (array) post('q_marks', []);
    $qexpl = (array) post('q_expl', []);
    $qids = (array) post('q_id', []);
    $opts = (array) post('q_opt', []);      // [idx][optIdx]
    $correct = (array) post('q_correct', []); // [idx] => correct option index

    $valid_questions = [];
    foreach ($qtexts as $i => $text) {
        $text = trim((string) $text);
        if ($text === '') continue;
        $opt_arr = array_map('trim', array_map('strval', $opts[$i] ?? []));
        // remove empty trailing
        $opt_arr = array_values(array_filter($opt_arr, fn($o) => $o !== ''));
        if (count($opt_arr) < 2) { $error = "Question " . ($i + 1) . " needs at least 2 answer options."; continue; }
        $ci = (int) ($correct[$i] ?? -1);
        if ($ci < 0 || $ci >= count($opt_arr)) { $error = "Question " . ($i + 1) . " needs a selected correct answer."; continue; }
        $valid_questions[] = [
            'id' => (int) ($qids[$i] ?? 0),
            'text' => $text,
            'expl' => trim((string) ($qexpl[$i] ?? '')),
            'marks' => (float) ($qmarks[$i] ?? $d['marks_per_question']) ?: $d['marks_per_question'],
            'options' => $opt_arr,
            'correct' => $ci,
        ];
    }
    if (!$error && !$valid_questions) $error = 'Add at least one question with options and a correct answer.';

    if (!$error) {
        db_begin();
        try {
            if ($id) {
                dbq("UPDATE qa_quizzes SET title=?, description=?, category_id=?, instructions=?, marks_per_question=?, negative_marks=?, pass_percentage=?, duration_minutes=?, start_datetime=?, end_datetime=?, max_attempts=?, entry_fee=?, completion_bonus=?, passing_reward=?, randomize_questions=?, randomize_options=?, show_answers=?, enable_certificate=?, status=?, updated_at=NOW() WHERE id=?",
                    [$d['title'], $d['description'], $d['category_id'], $d['instructions'], $d['marks_per_question'], $d['negative_marks'], $d['pass_percentage'], $d['duration_minutes'], $d['start_datetime'], $d['end_datetime'], $d['max_attempts'], $d['entry_fee'], $d['completion_bonus'], $d['passing_reward'], $d['randomize_questions'], $d['randomize_options'], $d['show_answers'], $d['enable_certificate'], $d['status'], $id]);
                $quiz_id = $id;
            } else {
                dbq("INSERT INTO qa_quizzes (school_id, created_by, title, description, category_id, instructions, marks_per_question, negative_marks, pass_percentage, duration_minutes, start_datetime, end_datetime, max_attempts, entry_fee, completion_bonus, passing_reward, randomize_questions, randomize_options, show_answers, enable_certificate, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [$school_id, $user['id'], $d['title'], $d['description'], $d['category_id'], $d['instructions'], $d['marks_per_question'], $d['negative_marks'], $d['pass_percentage'], $d['duration_minutes'], $d['start_datetime'], $d['end_datetime'], $d['max_attempts'], $d['entry_fee'], $d['completion_bonus'], $d['passing_reward'], $d['randomize_questions'], $d['randomize_options'], $d['show_answers'], $d['enable_certificate'], $d['status']]);
                $quiz_id = db_id();
            }

            // Rewards
            dbq("DELETE FROM qa_quiz_rewards WHERE quiz_id = ?", [$quiz_id]);
            foreach ([[1, $d['rank1_coins']], [2, $d['rank2_coins']], [3, $d['rank3_coins']]] as $rr) {
                if ($rr[1] > 0) dbq("INSERT INTO qa_quiz_rewards (quiz_id, school_id, rank, coins, points) VALUES (?, ?, ?, ?, 0)", [$quiz_id, $school_id, $rr[0], $rr[1]]);
            }

            // Assignments
            dbq("DELETE FROM qa_quiz_assignments WHERE quiz_id = ?", [$quiz_id]);
            $classes_sel = $d['assign_class'];
            $sections_sel = $d['assign_section'];
            $all_class = in_array(0, $classes_sel, true);
            $all_section = in_array(0, $sections_sel, true);
            if (!$all_class && !$all_section) {
                // specific classes + optional specific sections
                foreach ($classes_sel as $cid) {
                    if (in_array(0, $sections_sel, true)) {
                        dbq("INSERT IGNORE INTO qa_quiz_assignments (quiz_id, school_id, class_id, section_id) VALUES (?, ?, ?, 0)", [$quiz_id, $school_id, $cid]);
                    } else {
                        foreach ($sections_sel as $sid) {
                            dbq("INSERT IGNORE INTO qa_quiz_assignments (quiz_id, school_id, class_id, section_id) VALUES (?, ?, ?, ?)", [$quiz_id, $school_id, $cid, $sid]);
                        }
                    }
                }
            } else {
                dbq("INSERT IGNORE INTO qa_quiz_assignments (quiz_id, school_id, class_id, section_id) VALUES (?, ?, 0, 0)", [$quiz_id, $school_id]);
            }

            // Questions — diff by id
            $existing = $id ? dball("SELECT id FROM qa_questions WHERE quiz_id = ?", [$quiz_id]) : [];
            $existing_ids = array_map(fn($q) => (int) $q['id'], $existing);
            $kept_ids = [];
            foreach ($valid_questions as $qi => $vq) {
                if ($vq['id'] && in_array($vq['id'], $existing_ids, true)) {
                    dbq("UPDATE qa_questions SET question_text=?, explanation=?, marks=?, order_index=? WHERE id=? AND quiz_id=?",
                        [$vq['text'], $vq['expl'], $vq['marks'], $qi, $vq['id'], $quiz_id]);
                    $question_id = $vq['id'];
                } else {
                    dbq("INSERT INTO qa_questions (quiz_id, school_id, question_text, explanation, marks, order_index, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                        [$quiz_id, $school_id, $vq['text'], $vq['expl'], $vq['marks'], $qi]);
                    $question_id = db_id();
                }
                $kept_ids[] = $question_id;
                // options
                dbq("DELETE FROM qa_answer_options WHERE question_id = ?", [$question_id]);
                foreach ($vq['options'] as $oi => $opt) {
                    dbq("INSERT INTO qa_answer_options (question_id, option_text, is_correct, order_index) VALUES (?, ?, ?, ?)",
                        [$question_id, $opt, $oi === $vq['correct'] ? 1 : 0, $oi]);
                }
            }
            // remove questions no longer present
            if ($existing_ids) {
                $remove = array_diff($existing_ids, $kept_ids);
                foreach ($remove as $rid) {
                    dbq("DELETE FROM qa_answer_options WHERE question_id = ?", [$rid]);
                    dbq("DELETE FROM qa_user_answers WHERE question_id = ?", [$rid]);
                    dbq("DELETE FROM qa_questions WHERE id = ?", [$rid]);
                }
            }

            db_commit();
            audit('quiz_save', 'Saved quiz #' . $quiz_id . ' (' . $d['title'] . ') with ' . count($valid_questions) . ' questions');
            flash('success', 'Quiz saved (' . count($valid_questions) . ' questions).');

            // Notify students when published
            if ($d['status'] === 'published') {
                $students = dball("SELECT id FROM qa_users WHERE school_id = ? AND role = 'student' AND status = 'active'", [$school_id]);
                foreach ($students as $stu) {
                    notify($stu['id'], 'New quiz available!', 'A new quiz "' . $d['title'] . '" is ready for you.', 'student/quizzes.php', $school_id);
                }
            }
            redirect($user['role'] === 'superadmin' ? 'admin/quizzes.php' : 'teacher/quizzes.php');
        } catch (Throwable $e) {
            db_rollback();
            error_log('quiz save error: ' . $e->getMessage());
            $error = 'Could not save the quiz: ' . $e->getMessage();
        }
    }
}

/* Preload questions for editing */
$questions = [];
if ($quiz) {
    foreach (dball("SELECT * FROM qa_questions WHERE quiz_id = ? ORDER BY order_index, id", [$quiz['id']]) as $q) {
        $q['options'] = dball("SELECT id, option_text, is_correct, order_index FROM qa_answer_options WHERE question_id = ? ORDER BY order_index, id", [$q['id']]);
        $q['correct_idx'] = null;
        foreach ($q['options'] as $oi => $o) { if ((int) $o['is_correct'] === 1) { $q['correct_idx'] = $oi; } }
        $questions[] = $q;
    }
    $rewards = dball("SELECT rank, coins FROM qa_quiz_rewards WHERE quiz_id = ?", [$quiz['id']]);
    $reward_map = [];
    foreach ($rewards as $rw) $reward_map[(int) $rw['rank']] = (int) $rw['coins'];
} else {
    $reward_map = [1 => (int) setting('default_rank1_coins', 100), 2 => (int) setting('default_rank2_coins', 50), 3 => (int) setting('default_rank3_coins', 25)];
}

$title = $quiz ? 'Edit Quiz' : 'Create Quiz';
$active = 'quizzes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="fw-bold mb-0"><?= $title ?></h5>
        <span class="small text-muted">School: <?= e(dbval("SELECT name FROM qa_schools WHERE id = ?", [$school_id])) ?></span>
    </div>
    <?php if ($quiz): ?>
        <form method="post" data-confirm="Delete this quiz and ALL its attempts, results and certificates?"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_quiz">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete Quiz</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

<form method="post" id="quizForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_quiz">
    <input type="hidden" name="school_id" value="<?= $school_id ?>">

    <div class="row g-4">
        <!-- Left: settings -->
        <div class="col-lg-5">
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Quiz Settings</h5></div>
                <div class="dash-card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Quiz Title *</label>
                            <input class="form-control" name="title" value="<?= e($quiz['title'] ?? '') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?= e($quiz['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Category</label>
                            <select class="form-select" name="category_id">
                                <option value="0">— None —</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= ($quiz['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="draft" <?= ($quiz['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="published" <?= ($quiz['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                                <option value="closed" <?= ($quiz['status'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Instructions (shown before quiz starts)</label>
                            <textarea class="form-control" name="instructions" rows="3" placeholder="Read each question carefully. Negative marking applies..."><?= e($quiz['instructions'] ?? '') ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Marks per Question</label>
                            <input class="form-control" type="number" step="0.25" min="0.25" name="marks_per_question" value="<?= e($quiz['marks_per_question'] ?? '1') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Negative Marks (per wrong)</label>
                            <input class="form-control" type="number" step="0.25" min="0" name="negative_marks" value="<?= e($quiz['negative_marks'] ?? '0') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Pass Percentage (%)</label>
                            <input class="form-control" type="number" min="1" max="100" name="pass_percentage" value="<?= e($quiz['pass_percentage'] ?? '40') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Duration (minutes)</label>
                            <input class="form-control" type="number" min="1" name="duration_minutes" value="<?= e($quiz['duration_minutes'] ?? '10') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Start Date & Time</label>
                            <input class="form-control" type="datetime-local" name="start_datetime" value="<?= e(!empty($quiz['start_datetime']) && $quiz['start_datetime'] !== '0000-00-00 00:00:00' ? date('Y-m-d\TH:i', strtotime($quiz['start_datetime'])) : '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">End Date & Time</label>
                            <input class="form-control" type="datetime-local" name="end_datetime" value="<?= e(!empty($quiz['end_datetime']) && $quiz['end_datetime'] !== '0000-00-00 00:00:00' ? date('Y-m-d\TH:i', strtotime($quiz['end_datetime'])) : '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Max Attempts (0 = unlimited)</label>
                            <input class="form-control" type="number" min="0" name="max_attempts" value="<?= e($quiz['max_attempts'] ?? '1') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Entry Fee (Quiz Coins)</label>
                            <input class="form-control" type="number" min="0" name="entry_fee" value="<?= e($quiz['entry_fee'] ?? setting('default_entry_fee', 20)) ?>">
                        </div>
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-12"><label class="form-label small fw-semibold">Rewards (Quiz Coins)</label></div>
                        <div class="col-4"><label class="form-label fs-8">Completion Bonus</label><input class="form-control form-control-sm" type="number" min="0" name="completion_bonus" value="<?= e($quiz['completion_bonus'] ?? setting('default_completion_bonus', 5)) ?>"></div>
                        <div class="col-4"><label class="form-label fs-8">Passing Reward</label><input class="form-control form-control-sm" type="number" min="0" name="passing_reward" value="<?= e($quiz['passing_reward'] ?? setting('default_passing_reward', 10)) ?>"></div>
                        <div class="col-4"></div>
                        <div class="col-4"><label class="form-label fs-8">1st Rank Coins</label><input class="form-control form-control-sm" type="number" min="0" name="rank1_coins" value="<?= e($reward_map[1] ?? 0) ?>"></div>
                        <div class="col-4"><label class="form-label fs-8">2nd Rank Coins</label><input class="form-control form-control-sm" type="number" min="0" name="rank2_coins" value="<?= e($reward_map[2] ?? 0) ?>"></div>
                        <div class="col-4"><label class="form-label fs-8">3rd Rank Coins</label><input class="form-control form-control-sm" type="number" min="0" name="rank3_coins" value="<?= e($reward_map[3] ?? 0) ?>"></div>
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="randomize_questions" id="rq" <?= ($quiz['randomize_questions'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="rq">Random question order</label></div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="randomize_options" id="ro" <?= ($quiz['randomize_options'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="ro">Random answer order</label></div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_answers" id="sa" <?= ($quiz['show_answers'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label small" for="sa">Show correct answers in analysis</label></div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enable_certificate" id="ec" <?= ($quiz['enable_certificate'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="ec">Issue certificate on passing</label></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Assign to Classes &amp; Sections</h5></div>
                <div class="dash-card-body">
                    <p class="small text-muted">Leave all unchecked to make it available to every class in your school.</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="assign_class[]" value="0" id="allClasses" <?= $assignments ? (isset($assignments[0]) ? 'checked' : '') : 'checked' ?>>
                        <label class="form-check-label small" for="allClasses"><b>All classes & sections</b></label>
                    </div>
                    <?php foreach ($classes as $cl): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input class-cb" type="checkbox" name="assign_class[]" value="<?= (int) $cl['id'] ?>" id="cls<?= $cl['id'] ?>" <?= isset($assignments[$cl['id']]) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="cls<?= $cl['id'] ?>"><?= e($cl['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="mt-2">
                        <label class="form-label fs-8 fw-semibold">Sections (optional; all sections if none selected)</label>
                        <?php foreach ($sections as $sec): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input section-cb" type="checkbox" name="assign_section[]" value="<?= (int) $sec['id'] ?>" id="sec<?= $sec['id'] ?>"
                                    <?= $assignments ? (in_array($sec['id'], array_keys($assignments[$sec['class_id']] ?? []) ?? [], true) ? 'checked' : '') : 'checked' ?>>
                                <label class="form-check-label fs-8" for="sec<?= $sec['id'] ?>"><?= e($sec['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: questions -->
        <div class="col-lg-7">
            <div class="dash-card">
                <div class="dash-card-header">
                    <h5 class="dash-card-title">Questions</h5>
                    <button class="btn btn-sm btn-primary" type="button" onclick="addQuestion()"><i class="bi bi-plus-lg"></i> Add Question</button>
                </div>
                <div class="dash-card-body" id="questionsBox">
                    <?php if ($questions): ?>
                        <?php foreach ($questions as $qi => $q): ?>
                            <?php render_question_block($qi, $q); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php render_question_block(0, null); ?>
                    <?php endif; ?>
                </div>
                <div class="dash-card-body pt-0 d-flex justify-content-between align-items-center">
                    <span class="small text-muted" id="qCount"></span>
                    <button class="btn btn-primary px-4" type="submit"><i class="bi bi-save"></i> Save Quiz</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
let qIdx = <?= $questions ? count($questions) : 1 ?>;
const letter = ['A','B','C','D','E','F'];

function addQuestion(data = null) {
    const box = document.getElementById('questionsBox');
    const div = document.createElement('div');
    div.className = 'question-block border rounded-3 p-3 mb-3';
    div.id = 'qb-' + qIdx;
    div.innerHTML = questionHTML(qIdx, data);
    box.appendChild(div);
    qIdx++;
    updateCount();
    div.querySelector('.q-text').focus();
}
function questionHTML(i, d) {
    d = d || {};
    const opts = d.options || [{text:'',c:false},{text:'',c:false},{text:'',c:false},{text:'',c:false}];
    const correctIdx = d.correct_idx != null ? d.correct_idx : 0;
    let optsHTML = '';
    opts.forEach(function (o, oi) {
        optsHTML += `<div class="input-group mb-2">
            <span class="input-group-text"><b>${letter[oi]}</b></span>
            <input class="form-control opt-in" name="q_opt[${i}][]" value="${escAttr(o.text)}" placeholder="Option ${letter[oi]}">
            <span class="input-group-text">
                <input class="form-check-input mt-0 correct-in" type="radio" name="q_correct[${i}]" value="${oi}" ${oi === correctIdx ? 'checked' : ''} title="Correct answer">
            </span>
        </div>`;
    });
    return `<div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0"><span class="badge text-bg-primary me-2">Q${i + 1}</span> Question</h6>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion(this)"><i class="bi bi-x-lg"></i></button>
            </div>
            <input type="hidden" name="q_id[]" value="${escAttr(d.id || '')}">
            <textarea class="form-control q-text mb-2" name="q_text[]" rows="2" placeholder="Type your question here..." required>${escHtml(d.text || '')}</textarea>
            <div class="row g-2 mb-2">
                <div class="col-md-4"><label class="form-label fs-8">Marks</label><input class="form-control form-control-sm" type="number" step="0.25" min="0.25" name="q_marks[]" value="${escAttr(d.marks || '1')}"></div>
                <div class="col-md-8"><label class="form-label fs-8">Explanation (shown in result analysis)</label><input class="form-control form-control-sm" name="q_expl[]" value="${escAttr(d.expl || '')}"></div>
            </div>
            <label class="form-label fs-8 fw-semibold">Answer Options — click the radio ● of the correct answer</label>
            ${optsHTML}`;
}
function removeQuestion(btn) { btn.closest('.question-block').remove(); updateCount(); }
function updateCount() {
    const n = document.querySelectorAll('.question-block').length;
    document.getElementById('qCount').textContent = n + ' question(s)';
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return escHtml(s); }
updateCount();
</script>

<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>

<?php
/** Renders a question block server-side (for edit preload) */
function render_question_block($i, $q) {
    $letter = ['A', 'B', 'C', 'D', 'E', 'F'];
    $text = $q['question_text'] ?? '';
    $expl = $q['explanation'] ?? '';
    $marks = $q['marks'] ?? 1;
    $qid = $q['id'] ?? '';
    $opts = $q['options'] ?? [['option_text' => ''], ['option_text' => ''], ['option_text' => ''], ['option_text' => '']];
    $correct_idx = $q['correct_idx'] ?? 0;
    ?>
    <div class="question-block border rounded-3 p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <h6 class="fw-bold mb-0"><span class="badge text-bg-primary me-2">Q<?= $i + 1 ?></span> Question</h6>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion(this)"><i class="bi bi-x-lg"></i></button>
        </div>
        <input type="hidden" name="q_id[]" value="<?= e($qid) ?>">
        <textarea class="form-control q-text mb-2" name="q_text[]" rows="2" placeholder="Type your question here..." required><?= e($text) ?></textarea>
        <div class="row g-2 mb-2">
            <div class="col-md-4"><label class="form-label fs-8">Marks</label><input class="form-control form-control-sm" type="number" step="0.25" min="0.25" name="q_marks[]" value="<?= e($marks) ?>"></div>
            <div class="col-md-8"><label class="form-label fs-8">Explanation (shown in result analysis)</label><input class="form-control form-control-sm" name="q_expl[]" value="<?= e($expl) ?>"></div>
        </div>
        <label class="form-label fs-8 fw-semibold">Answer Options — click the radio ● of the correct answer</label>
        <?php foreach ($opts as $oi => $o): ?>
            <div class="input-group mb-2">
                <span class="input-group-text"><b><?= $letter[$oi] ?? chr(65 + $oi) ?></b></span>
                <input class="form-control opt-in" name="q_opt[<?= $i ?>][]" value="<?= e($o['option_text'] ?? '') ?>" placeholder="Option <?= $letter[$oi] ?? chr(65 + $oi) ?>">
                <span class="input-group-text">
                    <input class="form-check-input mt-0 correct-in" type="radio" name="q_correct[<?= $i ?>]" value="<?= $oi ?>" <?= $oi === $correct_idx ? 'checked' : '' ?> title="Correct answer">
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
