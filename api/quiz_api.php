<?php
/**
 * QuizArena — Quiz attempt API (autosave, answers, submit).
 * POST actions require an authenticated student session + CSRF token.
 */
require __DIR__ . '/../app/bootstrap.php';

$user = require_login('student');
$action = get('action', '');

/* ---------- Read attempt state ---------- */
if ($action === 'state') {
    $att_id = (int) get('att', 0);
    $attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ? AND user_id = ?", [$att_id, $user['id']]);
    if (!$attempt) json_out(['ok' => false, 'error' => 'Attempt not found.'], 404);
    $quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);
    $expires = strtotime($attempt['started_at']) + ((int) $quiz['duration_minutes'] * 60);
    $remaining = max(0, $expires - time());
    json_out([
        'ok' => true,
        'status' => $attempt['status'],
        'server_time' => time(),
        'expires_at' => $expires,
        'remaining_seconds' => $remaining,
        'answers' => attempt_answers_map($att_id),
        'question_count' => count(json_decode($attempt['question_order'] ?? '[]', true) ?: []),
    ]);
}

/* ---------- Save an answer (click or autosave) ---------- */
if ($action === 'save' || $action === 'autosave') {
    csrf_check();
    $att_id = (int) post('att', 0);
    $attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ? AND user_id = ?", [$att_id, $user['id']]);
    if (!$attempt) json_out(['ok' => false, 'error' => 'Attempt not found.'], 404);
    if ($attempt['status'] !== 'in_progress') json_out(['ok' => false, 'error' => 'This attempt is already closed.', 'closed' => true], 409);

    $answers = post('answers', null);
    if (is_string($answers)) $answers = json_decode($answers, true);

    $saved = 0;
    $ok = true;
    if (is_array($answers)) {
        foreach ($answers as $qid => $oid) {
            $oid = ($oid === '' || $oid === null) ? null : (int) $oid;
            $r = save_answer($attempt, (int) $qid, $oid);
            if ($r['ok']) $saved++;
            elseif (!empty($r['expired'])) { $ok = false; json_out(['ok' => false, 'error' => 'Time is up.', 'expired' => true, 'saved' => $saved], 409); }
        }
    }
    json_out(['ok' => $ok, 'saved' => $saved]);
}

/* ---------- Final submission ---------- */
if ($action === 'submit') {
    csrf_check();
    $att_id = (int) post('att', 0);
    $attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ? AND user_id = ?", [$att_id, $user['id']]);
    if (!$attempt) json_out(['ok' => false, 'error' => 'Attempt not found.'], 404);

    // Save any remaining answers sent along
    $answers = post('answers', null);
    if (is_string($answers)) $answers = json_decode($answers, true);
    if (is_array($answers)) {
        foreach ($answers as $qid => $oid) {
            $oid = ($oid === '' || $oid === null) ? null : (int) $oid;
            save_answer($attempt, (int) $qid, $oid);
        }
    }

    $res = finalize_attempt($attempt);
    if (!$res['ok']) json_out(['ok' => false, 'error' => $res['error']], 500);
    json_out(['ok' => true, 'status' => $res['status'], 'attempt_id' => (int) $attempt['id'],
              'redirect' => url('student/result.php?id=' . (int) $attempt['id'])]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
