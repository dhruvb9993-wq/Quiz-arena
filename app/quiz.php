<?php
/**
 * QuizArena — Quiz engine.
 * Accessibility checks, attempt lifecycle, scoring, ranks, rewards and leaderboard.
 * All scoring is computed server-side; the browser never supplies scores.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/* ------------------------------------------------------------------ *
 *  Accessibility
 * ------------------------------------------------------------------ */

/**
 * Check whether a user may start (or continue) a quiz.
 * @return array{ok:bool, error:?string, attempt:?array}
 */
function quiz_accessible($quiz, $user) {
    $now = date('Y-m-d H:i:s');

    if ($quiz['status'] === 'draft') return ['ok' => false, 'error' => 'This quiz has not been published yet.', 'attempt' => null];
    if ($quiz['status'] === 'closed') return ['ok' => false, 'error' => 'This quiz has been closed by the organiser.', 'attempt' => null];
    if ((int) $quiz['school_id'] !== (int) $user['school_id']) return ['ok' => false, 'error' => 'This quiz is not available for your school.', 'attempt' => null];

    if ($quiz['start_datetime'] && $quiz['start_datetime'] !== '0000-00-00 00:00:00' && $quiz['start_datetime'] > $now) {
        return ['ok' => false, 'error' => 'This quiz starts on ' . nice_date($quiz['start_datetime'], true) . '.', 'attempt' => null];
    }
    if ($quiz['end_datetime'] && $quiz['end_datetime'] !== '0000-00-00 00:00:00' && $quiz['end_datetime'] < $now) {
        return ['ok' => false, 'error' => 'This quiz ended on ' . nice_date($quiz['end_datetime'], true) . '.', 'attempt' => null];
    }

    // Assignment check (class / section)
    if ($user['role'] === 'student') {
        $assigned = dbval(
            "SELECT COUNT(*) FROM qa_quiz_assignments
              WHERE quiz_id = ? AND school_id = ?
                AND (class_id = 0 OR class_id = ?)
                AND (section_id = 0 OR section_id = ?)",
            [$quiz['id'], $quiz['school_id'], (int) $user['class_id'], (int) $user['section_id']]
        );
        $hasAny = dbval("SELECT COUNT(*) FROM qa_quiz_assignments WHERE quiz_id = ? AND school_id = ?", [$quiz['id'], $quiz['school_id']]);
        if ($hasAny > 0 && $assigned < 1) {
            return ['ok' => false, 'error' => 'This quiz is not assigned to your class or section.', 'attempt' => null];
        }
    }

    // Attempt count
    $attempts = (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status IN ('submitted','expired')", [$quiz['id'], $user['id']]);
    if ($quiz['max_attempts'] > 0 && $attempts >= (int) $quiz['max_attempts']) {
        return ['ok' => false, 'error' => 'You have reached the maximum number of attempts (' . $quiz['max_attempts'] . ') for this quiz.', 'attempt' => null];
    }

    // Existing in-progress attempt → resume
    $inProgress = dbrow("SELECT * FROM qa_quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1", [$quiz['id'], $user['id']]);
    if ($inProgress) {
        $expires = strtotime($inProgress['started_at']) + ((int) $quiz['duration_minutes'] * 60);
        if (time() > $expires) {
            // Time ran out while away → finalize it server-side
            finalize_attempt($inProgress, true);
            $attempts++;
            if ($quiz['max_attempts'] > 0 && $attempts >= (int) $quiz['max_attempts']) {
                return ['ok' => false, 'error' => 'You have reached the maximum number of attempts for this quiz.', 'attempt' => null];
            }
            return ['ok' => true, 'error' => null, 'attempt' => null];
        }
        return ['ok' => true, 'error' => null, 'attempt' => $inProgress];
    }

    return ['ok' => true, 'error' => null, 'attempt' => null];
}

/* ------------------------------------------------------------------ *
 *  Start an attempt (charges entry fee inside the same transaction)
 * ------------------------------------------------------------------ */

/**
 * Start (or resume) a quiz attempt.
 * Entry fee is deducted atomically with attempt creation — if anything
 * fails, the transaction rolls back so no coins are lost.
 *
 * @return array{ok:bool, attempt_id:?int, error:?string}
 */
function start_attempt($quiz, $user) {
    $check = quiz_accessible($quiz, $user);
    if (!$check['ok']) return ['ok' => false, 'attempt_id' => null, 'error' => $check['error']];
    if ($check['attempt']) return ['ok' => true, 'attempt_id' => (int) $check['attempt']['id'], 'error' => null];

    $fee = (int) $quiz['entry_fee'];
    $balance = wallet_balance($user['id']);
    if ($fee > 0 && $balance < $fee) {
        return ['ok' => false, 'attempt_id' => null, 'error' => 'You need ' . fmt_coin($fee) . ' ' . coin_name() . ' to enter this quiz. Your balance is ' . fmt_coin($balance) . '.'];
    }

    $attempt_number = (int) dbval(
        "SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM qa_quiz_attempts WHERE quiz_id = ? AND user_id = ?",
        [$quiz['id'], $user['id']]
    );

    db_begin();
    try {
        // 1) Deduct entry fee (locks wallet row)
        if ($fee > 0) {
            $r = wallet_apply($user['id'], 'debit', 'entry_fee', $fee, [
                'description' => 'Entry fee for quiz "' . $quiz['title'] . '"',
                'reference'   => 'QUIZ:' . $quiz['id'],
            ]);
            if (!$r['ok']) { db_rollback(); return ['ok' => false, 'attempt_id' => null, 'error' => $r['error']]; }
        }

        // 2) Build per-attempt randomization (locked in for this attempt)
        $question_order = [];
        $option_order = [];
        $questions = dball("SELECT id FROM qa_questions WHERE quiz_id = ? ORDER BY order_index ASC, id ASC", [$quiz['id']]);
        foreach ($questions as $q) $question_order[] = (int) $q['id'];
        if ($quiz['randomize_questions']) shuffle($question_order);
        if ($quiz['randomize_options']) {
            foreach ($question_order as $qid) {
                $opts = dball("SELECT id FROM qa_answer_options WHERE question_id = ? ORDER BY order_index ASC, id ASC", [$qid]);
                $ids = array_map(fn($o) => (int) $o['id'], $opts);
                shuffle($ids);
                $option_order[$qid] = $ids;
            }
        }

        // 3) Create attempt
        dbq(
            "INSERT INTO qa_quiz_attempts
                (quiz_id, user_id, school_id, attempt_number, question_order, option_order, status,
                 started_at, entry_fee, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, 'in_progress', NOW(), ?, ?)",
            [$quiz['id'], $user['id'], $quiz['school_id'], $attempt_number,
             json_encode($question_order), json_encode($option_order), $fee, req_ip()]
        );
        $attempt_id = db_id();

        db_commit();
        return ['ok' => true, 'attempt_id' => $attempt_id, 'error' => null];
    } catch (Throwable $e) {
        db_rollback();
        error_log('start_attempt error: ' . $e->getMessage());
        // Entry fee was rolled back with the transaction → nothing to refund
        return ['ok' => false, 'attempt_id' => null, 'error' => 'Could not start the quiz due to a server error. Your coins were not deducted.'];
    }
}

/* ------------------------------------------------------------------ *
 *  Attempt data access
 * ------------------------------------------------------------------ */

/** Load questions for an attempt in the attempt's locked order, with shuffled options. */
function attempt_questions($attempt, $include_correct = false) {
    $qorder = json_decode($attempt['question_order'] ?? '[]', true) ?: [];
    $oorder = json_decode($attempt['option_order'] ?? '[]', true) ?: [];
    if (!$qorder) {
        foreach (dball("SELECT id FROM qa_questions WHERE quiz_id = ? ORDER BY order_index ASC, id ASC", [$attempt['quiz_id']]) as $q) {
            $qorder[] = (int) $q['id'];
        }
    }
    $list = [];
    foreach ($qorder as $qid) {
        $q = dbrow("SELECT * FROM qa_questions WHERE id = ? AND quiz_id = ?", [$qid, $attempt['quiz_id']]);
        if (!$q) continue;
        $opts = dball("SELECT id, option_text, is_correct FROM qa_answer_options WHERE question_id = ? ORDER BY order_index ASC, id ASC", [$qid]);
        if (!empty($oorder[$qid])) {
            $byId = [];
            foreach ($opts as $o) $byId[$o['id']] = $o;
            $ordered = [];
            foreach ($oorder[$qid] as $oid) {
                if (isset($byId[$oid])) $ordered[] = $byId[$oid];
            }
            $opts = $ordered;
        }
        if (!$include_correct) {
            foreach ($opts as &$o) $o['is_correct'] = null;
            unset($o);
        }
        $q['options'] = $opts;
        $q['correct_option_id'] = $include_correct
            ? (int) dbval("SELECT id FROM qa_answer_options WHERE question_id = ? AND is_correct = 1 LIMIT 1", [$qid])
            : null;
        $list[] = $q;
    }
    return $list;
}

/** Map of question_id => selected option id for an attempt */
function attempt_answers_map($attempt_id) {
    $map = [];
    foreach (dball("SELECT question_id, selected_option_id FROM qa_user_answers WHERE attempt_id = ?", [$attempt_id]) as $r) {
        $map[(int) $r['question_id']] = $r['selected_option_id'] !== null ? (int) $r['selected_option_id'] : null;
    }
    return $map;
}

/** Save/update a single answer (autosave + explicit saves). Returns [ok, is_correct]. */
function save_answer($attempt, $question_id, $option_id) {
    // Ownership & state guard
    if ((int) $attempt['user_id'] !== (int) ($_SESSION['user_id'] ?? 0)) return ['ok' => false, 'is_correct' => null];
    if ($attempt['status'] !== 'in_progress') return ['ok' => false, 'is_correct' => null];

    $quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);
    if (!$quiz) return ['ok' => false, 'is_correct' => null];

    $expires = strtotime($attempt['started_at']) + ((int) $quiz['duration_minutes'] * 60);
    if (time() > $expires + 5) return ['ok' => false, 'is_correct' => null, 'expired' => true];

    // Validate the question belongs to the quiz and the option belongs to the question
    $q = dbrow("SELECT id, marks FROM qa_questions WHERE id = ? AND quiz_id = ?", [$question_id, $attempt['quiz_id']]);
    if (!$q) return ['ok' => false, 'is_correct' => null];

    $option_id = $option_id !== null ? (int) $option_id : null;
    if ($option_id !== null) {
        $opt = dbrow("SELECT id, is_correct FROM qa_answer_options WHERE id = ? AND question_id = ?", [$option_id, $question_id]);
        if (!$opt) return ['ok' => false, 'is_correct' => null];
        $is_correct = (int) $opt['is_correct'] === 1;
    } else {
        $is_correct = null;
    }

    dbq(
        "INSERT INTO qa_user_answers (attempt_id, question_id, selected_option_id, is_correct, marks_awarded, answered_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE selected_option_id = VALUES(selected_option_id), is_correct = VALUES(is_correct), answered_at = NOW()",
        [$attempt['id'], $question_id, $option_id, $is_correct === null ? null : ($is_correct ? 1 : 0), 0]
    );
    return ['ok' => true, 'is_correct' => $is_correct];
}

/* ------------------------------------------------------------------ *
 *  Finalize (server-side scoring)
 * ------------------------------------------------------------------ */

/**
 * Score and close an attempt. Called on manual submit, timeout or expiry.
 * Idempotent: a submitted attempt is never rescored.
 *
 * @param array $attempt      attempt row
 * @param bool  $auto_expired whether this is a timeout/expiry finalization
 * @return array{ok:bool, status:string, error:?string}
 */
function finalize_attempt($attempt, $auto_expired = false) {
    if (in_array($attempt['status'], ['submitted'], true)) {
        return ['ok' => true, 'status' => 'already_submitted', 'error' => null];
    }

    $quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);
    if (!$quiz) return ['ok' => false, 'status' => 'error', 'error' => 'Quiz not found.'];

    $user = dbrow("SELECT * FROM qa_users WHERE id = ?", [$attempt['user_id']]);

    db_begin();
    try {
        // Lock the attempt row against double submission
        $locked = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ? FOR UPDATE", [$attempt['id']]);
        if ($locked['status'] === 'submitted') { db_commit(); return ['ok' => true, 'status' => 'already_submitted', 'error' => null]; }

        $questions = attempt_questions($locked, true);
        $answers = attempt_answers_map($locked['id']);

        $total_marks = 0; $score = 0; $correct = 0; $wrong = 0; $unanswered = 0;
        foreach ($questions as $q) {
            $marks = (float) ($q['marks'] ?? $quiz['marks_per_question']);
            $total_marks += $marks;
            $sel = $answers[$q['id']] ?? null;
            if ($sel === null || $sel === 0) {
                $unanswered++;
                dbq("UPDATE qa_user_answers SET marks_awarded = 0 WHERE attempt_id = ? AND question_id = ?", [$locked['id'], $q['id']]);
            } elseif ($sel === (int) $q['correct_option_id']) {
                $correct++;
                $score += $marks;
                dbq("UPDATE qa_user_answers SET marks_awarded = ? WHERE attempt_id = ? AND question_id = ?", [$marks, $locked['id'], $q['id']]);
            } else {
                $wrong++;
                $score -= (float) $quiz['negative_marks'];
                dbq("UPDATE qa_user_answers SET marks_awarded = ? WHERE attempt_id = ? AND question_id = ?", [0 - (float) $quiz['negative_marks'], $locked['id'], $q['id']]);
            }
        }
        $score = max(0, round($score, 2));
        $total_marks = max(0, round($total_marks, 2));
        $percentage = $total_marks > 0 ? round($score / $total_marks * 100, 2) : 0;
        $pass = $percentage >= (float) $quiz['pass_percentage'];

        $submitted_at = date('Y-m-d H:i:s');
        $duration_seconds = max(0, strtotime($submitted_at) - strtotime($locked['started_at']));

        // 3) Update attempt row with scores
        dbq(
            "UPDATE qa_quiz_attempts
                SET status = 'submitted', submitted_at = ?, duration_seconds = ?,
                    score = ?, total_marks = ?, percentage = ?,
                    correct_count = ?, wrong_count = ?, unanswered_count = ?,
                    pass_status = ?, points_earned = 0, coins_earned = 0
              WHERE id = ?",
            [$submitted_at, $duration_seconds, $score, $total_marks, $percentage,
             $correct, $wrong, $unanswered, $pass ? 'pass' : 'fail', $locked['id']]
        );

        // 4) Auto-refund when the quiz expired with zero answers (technical failure safety net)
        if ($auto_expired && ($correct + $wrong + $unanswered) === 0 && (int) $locked['entry_fee'] > 0) {
            $r = wallet_apply($locked['user_id'], 'credit', 'refund', (int) $locked['entry_fee'], [
                'description' => 'Entry fee refund — quiz "' . $quiz['title'] . '" could not be completed',
                'reference'   => 'QUIZ:' . $quiz['id'] . ':REFUND',
                'status'      => 'completed',
            ]);
            if ($r['ok']) {
                dbq("UPDATE qa_quiz_attempts SET refunded = 1 WHERE id = ?", [$locked['id']]);
            }
        }

        // 5) Rank among all submitted attempts of this quiz
        $rank = (int) dbval(
            "SELECT COUNT(*) + 1 FROM qa_quiz_attempts
              WHERE quiz_id = ? AND id != ? AND status = 'submitted'
                AND (score > ? OR (score = ? AND COALESCE(duration_seconds, 0) < ?))",
            [$quiz['id'], $locked['id'], $score, $score, $duration_seconds]
        );
        dbq("UPDATE qa_quiz_attempts SET rank = ? WHERE id = ?", [$rank, $locked['id']]);

        // 6) Rewards (coins) — atomic wallet credits inside this transaction
        $coins_earned = 0;
        $points_earned = (int) round($score);

        $credit = function ($category, $amount, $desc) use ($locked, $user) {
            if ($amount <= 0) return ['ok' => true];
            return wallet_apply($locked['user_id'], 'credit', $category, $amount, [
                'description' => $desc,
                'reference'   => 'QUIZ:' . $locked['quiz_id'] . ':ATT:' . $locked['id'],
            ]);
        };

        $completion_bonus = (int) $quiz['completion_bonus'];
        $r = $credit('completion_bonus', $completion_bonus, 'Completion bonus for quiz "' . $quiz['title'] . '"');
        if (!$r['ok']) throw new RuntimeException($r['error']);
        $coins_earned += $completion_bonus;

        if ($pass) {
            $passing_reward = (int) $quiz['passing_reward'];
            $r = $credit('passing_reward', $passing_reward, 'Passing reward for quiz "' . $quiz['title'] . '"');
            if (!$r['ok']) throw new RuntimeException($r['error']);
            $coins_earned += $passing_reward;
        }

        $rank_reward = dbrow("SELECT coins FROM qa_quiz_rewards WHERE quiz_id = ? AND rank = ?", [$quiz['id'], $rank]);
        $rank_coins = (int) ($rank_reward['coins'] ?? 0);
        if ($rank_coins > 0) {
            $r = $credit('rank_reward', $rank_coins, 'Rank ' . $rank . ' reward for quiz "' . $quiz['title'] . '"');
            if (!$r['ok']) throw new RuntimeException($r['error']);
            $coins_earned += $rank_coins;
        }

        if ($coins_earned > 0) {
            dbq("UPDATE qa_quiz_attempts SET coins_earned = ?, points_earned = ? WHERE id = ?",
                [$coins_earned, $points_earned, $locked['id']]);
        } else {
            dbq("UPDATE qa_quiz_attempts SET points_earned = ? WHERE id = ?", [$points_earned, $locked['id']]);
        }

        // 7) Update leaderboard stats for the student
        dbq(
            "INSERT INTO qa_leaderboard_stats (user_id, school_id, total_quizzes, total_score, total_points, total_coins_earned, best_percentage, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                total_quizzes = total_quizzes + 1,
                total_score = total_score + VALUES(total_score),
                total_points = total_points + VALUES(total_points),
                total_coins_earned = total_coins_earned + VALUES(total_coins_earned),
                best_percentage = GREATEST(best_percentage, VALUES(best_percentage)),
                updated_at = NOW()",
            [$locked['user_id'], $locked['school_id'], $score, $points_earned, $coins_earned, $percentage]
        );

        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        error_log('finalize_attempt error: ' . $e->getMessage());
        return ['ok' => false, 'status' => 'error', 'error' => 'Could not finalise the quiz. Please try again.'];
    }

    // 8) Post-commit side effects (certificate, notifications)
    $updated = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ?", [$attempt['id']]);

    if ($updated['pass_status'] === 'pass' && (int) $quiz['enable_certificate'] === 1) {
        generate_certificate($updated);
    }

    notify(
        $updated['user_id'],
        'Quiz result ready',
        'You scored ' . round($updated['score'], 1) . '/' . round($updated['total_marks'], 1)
            . ' (' . $updated['percentage'] . '%) in "' . $quiz['title'] . '"'
            . ($updated['coins_earned'] > 0 ? ' and earned ' . $updated['coins_earned'] . ' ' . coin_name() . '.' : '.'),
        'student/result.php?id=' . $updated['id'],
        $updated['school_id']
    );

    audit('quiz_submitted', 'Attempt #' . $updated['id'] . ' for quiz #' . $quiz['id'] . ' scored ' . $score . '/' . $total_marks);

    return ['ok' => true, 'status' => 'submitted', 'error' => null, 'attempt_id' => (int) $updated['id']];
}

/** Current user's live rank for the student dashboard */
function student_rank_info($user_id, $school_id) {
    $stats = dbrow("SELECT * FROM qa_leaderboard_stats WHERE user_id = ?", [$user_id]);
    $total_quizzes = (int) ($stats['total_quizzes'] ?? 0);
    $total_points = (int) ($stats['total_points'] ?? 0);

    $rank = (int) dbval(
        "SELECT COUNT(*) + 1 FROM qa_leaderboard_stats
          WHERE school_id = ?
            AND (total_points > ? OR (total_points = ? AND total_score > ?))",
        [$school_id, $total_points, $total_points, (float) ($stats['total_score'] ?? 0)]
    );
    $total = max(1, (int) dbval("SELECT COUNT(*) FROM qa_leaderboard_stats WHERE school_id = ?", [$school_id]));
    if ($total_quizzes === 0) $rank = null;

    $prev = dbrow("SELECT rank FROM qa_user_rank_history WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$user_id]);
    $prev_rank = $prev ? (int) $prev['rank'] : null;

    return [
        'rank' => $rank, 'total' => $total,
        'prev_rank' => $prev_rank,
        'change' => ($rank !== null && $prev_rank !== null) ? ($prev_rank - $rank) : null,
        'total_quizzes' => $total_quizzes,
        'total_points' => $total_points,
        'total_score' => (float) ($stats['total_score'] ?? 0),
    ];
}

/** Persist a rank snapshot (called after finalizing a student's attempt) */
function save_rank_snapshot($user_id, $rank) {
    dbq("INSERT INTO qa_user_rank_history (user_id, rank, recorded_at) VALUES (?, ?, NOW())", [$user_id, $rank]);
}
