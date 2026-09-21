<?php
/**
 * QuizArena — Report query builders shared by Super Admin, School Admin and Teacher reports.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/**
 * Build a date-window SQL fragment from ?from= & ?to= parameters.
 */
function report_date_filter($alias = 'a') {
    $from = get('from');
    $to = get('to');
    $sql = '';
    $params = [];
    if ($from) { $sql .= " AND DATE($alias.created_at) >= ?"; $params[] = $from; }
    if ($to)   { $sql .= " AND DATE($alias.created_at) <= ?"; $params[] = $to; }
    return [$sql, $params];
}

/**
 * School-wise performance report rows.
 */
function report_school_performance($school_id = null, $from = null, $to = null) {
    $where = "WHERE 1=1";
    $params = [];
    if ($school_id) { $where .= " AND s.id = ?"; $params[] = $school_id; }
    if ($from) { $where .= " AND DATE(s.created_at) >= ?"; $params[] = $from; }
    if ($to)   { $where .= " AND DATE(s.created_at) <= ?"; $params[] = $to; }
    return dball(
        "SELECT s.id, s.name AS school_name, s.code, s.status, s.subscription_expiry,
                (SELECT COUNT(*) FROM qa_users u WHERE u.school_id = s.id AND u.role = 'student') AS students,
                (SELECT COUNT(*) FROM qa_users u WHERE u.school_id = s.id AND u.role = 'teacher') AS teachers,
                (SELECT COUNT(*) FROM qa_quizzes q WHERE q.school_id = s.id) AS quizzes,
                (SELECT COUNT(*) FROM qa_quiz_attempts a WHERE a.school_id = s.id AND a.status = 'submitted') AS attempts,
                COALESCE((SELECT SUM(score) FROM qa_quiz_attempts a WHERE a.school_id = s.id AND a.status = 'submitted'), 0) AS total_score,
                (SELECT COUNT(*) FROM qa_certificates c WHERE c.school_id = s.id) AS certificates
           FROM qa_schools s
           $where
          ORDER BY s.created_at DESC", $params
    );
}

/** Class-wise performance for a school (optionally a quiz). */
function report_class_performance($school_id, $quiz_id = null) {
    $where = "WHERE u.school_id = ? AND u.role = 'student' AND u.class_id > 0";
    $params = [$school_id];
    if ($quiz_id) { $where .= " AND a.quiz_id = ?"; $params[] = $quiz_id; }
    return dball(
        "SELECT c.id AS class_id, c.name AS class_name,
                COUNT(DISTINCT a.id) AS attempts,
                COALESCE(AVG(a.percentage), 0) AS avg_pct,
                COALESCE(SUM(a.score), 0) AS total_score,
                COALESCE(SUM(a.points_earned), 0) AS total_points,
                SUM(CASE WHEN a.pass_status = 'pass' THEN 1 ELSE 0 END) AS passes,
                COUNT(DISTINCT u.id) AS students
           FROM qa_classes c
           LEFT JOIN qa_users u ON u.class_id = c.id AND u.school_id = c.school_id
           LEFT JOIN qa_quiz_attempts a ON a.user_id = u.id AND a.status = 'submitted' $where
          GROUP BY c.id, c.name
          ORDER BY avg_pct DESC", $params
    );
}

/** Student-wise performance for a school (optionally class/quiz). */
function report_student_performance($school_id, $class_id = null, $quiz_id = null) {
    $where = "WHERE u.school_id = ? AND u.role = 'student'";
    $params = [$school_id];
    if ($class_id) { $where .= " AND u.class_id = ?"; $params[] = $class_id; }
    $extra = '';
    if ($quiz_id) { $extra = " AND a.quiz_id = ?"; $params[] = $quiz_id; }
    return dball(
        "SELECT u.id, u.full_name, u.username, u.email,
                c.name AS class_name, sec.name AS section_name,
                COUNT(a.id) AS quizzes_played,
                COALESCE(SUM(a.score), 0) AS total_score,
                COALESCE(SUM(a.points_earned), 0) AS total_points,
                COALESCE(AVG(a.percentage), 0) AS avg_pct,
                COALESCE(MAX(a.percentage), 0) AS best_pct,
                SUM(CASE WHEN a.pass_status = 'pass' THEN 1 ELSE 0 END) AS passes,
                COALESCE(ls.total_coins_earned, 0) AS coins_earned
           FROM qa_users u
           LEFT JOIN qa_classes c ON c.id = u.class_id
           LEFT JOIN qa_sections sec ON sec.id = u.section_id
           LEFT JOIN qa_quiz_attempts a ON a.user_id = u.id AND a.status = 'submitted' $extra
           LEFT JOIN qa_leaderboard_stats ls ON ls.user_id = u.id
           $where
          GROUP BY u.id, u.full_name, u.username, u.email, c.name, sec.name, ls.total_coins_earned
          ORDER BY total_points DESC", $params
    );
}

/** Teacher-wise performance for a school. */
function report_teacher_performance($school_id) {
    return dball(
        "SELECT t.id, t.full_name, t.username, t.email,
                COUNT(DISTINCT q.id) AS quizzes_created,
                COUNT(DISTINCT a.id) AS attempts,
                COALESCE(SUM(a.score), 0) AS total_score,
                COALESCE(AVG(a.percentage), 0) AS avg_pct
           FROM qa_users t
           LEFT JOIN qa_quizzes q ON q.created_by = t.id AND q.school_id = t.school_id
           LEFT JOIN qa_quiz_attempts a ON a.quiz_id = q.id AND a.status = 'submitted'
          WHERE t.school_id = ? AND t.role = 'teacher'
          GROUP BY t.id, t.full_name, t.username, t.email
          ORDER BY quizzes_created DESC", [$school_id]
    );
}

/** Quiz-wise performance for a school (or all, for super admin). */
function report_quiz_performance($school_id = null) {
    $where = "WHERE 1=1";
    $params = [];
    if ($school_id) { $where .= " AND q.school_id = ?"; $params[] = $school_id; }
    return dball(
        "SELECT q.id, q.title, q.status, s.name AS school_name,
                (SELECT COUNT(*) FROM qa_questions x WHERE x.quiz_id = q.id) AS questions,
                COUNT(DISTINCT a.id) AS attempts,
                COALESCE(AVG(a.percentage), 0) AS avg_pct,
                COALESCE(SUM(a.score), 0) AS total_score,
                SUM(CASE WHEN a.pass_status = 'pass' THEN 1 ELSE 0 END) AS passes,
                COALESCE(SUM(a.coins_earned), 0) AS coins_awarded
           FROM qa_quizzes q
           LEFT JOIN qa_schools s ON s.id = q.school_id
           LEFT JOIN qa_quiz_attempts a ON a.quiz_id = q.id AND a.status = 'submitted'
           $where
          GROUP BY q.id, q.title, q.status, s.name
          ORDER BY attempts DESC", $params
    );
}

/** Wallet transactions for a school (or all). */
function report_wallet_transactions($school_id = null, $user_id = null, $type = null, $category = null) {
    $where = "WHERE 1=1";
    $params = [];
    if ($school_id) { $where .= " AND t.school_id = ?"; $params[] = $school_id; }
    if ($user_id)   { $where .= " AND t.user_id = ?"; $params[] = $user_id; }
    if ($type)      { $where .= " AND t.type = ?"; $params[] = $type; }
    if ($category)  { $where .= " AND t.category = ?"; $params[] = $category; }
    return dball(
        "SELECT t.transaction_id, t.type, t.category, t.amount, t.balance_after,
                t.sender_username, t.receiver_username, t.description, t.status, t.created_at,
                u.username AS user_username, u.full_name AS user_name
           FROM qa_wallet_transactions t
           LEFT JOIN qa_users u ON u.id = t.user_id
           $where
          ORDER BY t.created_at DESC", $params
    );
}

/** Subscription usage summary per school. */
function report_subscriptions() {
    return dball(
        "SELECT s.id, s.name AS school_name, s.code, pl.name AS plan_name, pl.type AS plan_type,
                pl.price, s.subscription_start, s.subscription_expiry, s.status,
                (SELECT COUNT(*) FROM qa_users u WHERE u.school_id = s.id AND u.role = 'student') AS students,
                (SELECT COUNT(*) FROM qa_quizzes q WHERE q.school_id = s.id) AS quizzes
           FROM qa_schools s
           LEFT JOIN qa_subscription_plans pl ON pl.id = s.plan_id
          ORDER BY s.created_at DESC"
    );
}

/** Reward distribution summary. */
function report_rewards() {
    return dball(
        "SELECT category, COUNT(*) AS txns, SUM(amount) AS total_coins
           FROM qa_wallet_transactions
          WHERE category IN ('completion_bonus','passing_reward','rank_reward','quiz_reward')
          GROUP BY category ORDER BY total_coins DESC"
    );
}
