<?php
/**
 * QuizArena — Authentication, RBAC, rate limiting, password reset
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/* ------------------------------------------------------------------ *
 *  Session bootstrap (called from bootstrap.php)
 * ------------------------------------------------------------------ */

function qa_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = defined('BASE_URL') && strpos(BASE_URL, 'https://') === 0;
    session_name('QA_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Absolute session lifetime (hours from settings or default)
    $abs = (int) setting('session_lifetime_hours', 12);
    if (!empty($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > $abs * 3600) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    if (empty($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }
}

/* ------------------------------------------------------------------ *
 *  Role-based access control
 * ------------------------------------------------------------------ */

/** Require a logged-in user; optionally restrict to certain roles */
function require_login($roles = null) {
    $user = current_user();
    if (!$user) {
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('info', 'Please sign in to continue.');
        redirect('login.php');
    }
    if ($roles !== null) {
        $roles = (array) $roles;
        if (!in_array($user['role'], $roles, true)) {
            http403('Your account does not have permission to access that page.');
        }
    }
    return $user;
}

/** School-level guard: ensure the page's school scope matches the user's school (multi-school isolation) */
function require_school($school_id) {
    $user = require_login();
    if ($user['role'] === 'superadmin') return true;
    if ((int) $school_id !== (int) $user['school_id']) {
        http403('You are not authorised to access data from another school.');
    }
    return true;
}

/* ------------------------------------------------------------------ *
 *  Login / logout
 * ------------------------------------------------------------------ */

function login_user(array $user, $remember = false) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['school_id'] = $user['school_id'] ?? null;
    unset($_SESSION['csrf']); // rotate CSRF token after privilege change
    csrf_token();

    if ($remember) {
        // 30-day persistent cookie, validated server-side against the user's remember token
        $token = bin2hex(random_bytes(32));
        dbq("UPDATE qa_users SET remember_token = ?, remember_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?",
            [hash('sha256', $token), $user['id']]);
        setcookie('QA_REMEMBER', $user['id'] . ':' . $token, time() + 2592000, '/', '', false, true);
    }

    dbq("UPDATE qa_users SET last_login = NOW() WHERE id = ?", [$user['id']]);
    dbq("DELETE FROM qa_login_attempts WHERE username = ?", [$user['username']]);
    audit('login', 'User logged in (' . $user['role'] . ')');
}

function logout_user() {
    audit('logout', 'User logged out');
    setcookie('QA_REMEMBER', '', time() - 3600, '/');
    dbq("UPDATE qa_users SET remember_token = NULL WHERE id = ?", [$_SESSION['user_id'] ?? 0]);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Attempt auto-login via remember cookie */
function auto_login_from_cookie() {
    if (!empty($_SESSION['user_id'])) return;
    if (empty($_COOKIE['QA_REMEMBER'])) return;
    $parts = explode(':', $_COOKIE['QA_REMEMBER'], 2);
    if (count($parts) !== 2) return;
    [$uid, $token] = [$parts[0], $parts[1]];
    $user = dbrow("SELECT * FROM qa_users WHERE id = ? AND remember_token IS NOT NULL AND remember_expires > NOW()", [(int) $uid]);
    if ($user && hash_equals($user['remember_token'], hash('sha256', $token))) {
        if ($user['status'] === 'active') login_user($user);
    } else {
        setcookie('QA_REMEMBER', '', time() - 3600, '/');
    }
}

/* ------------------------------------------------------------------ *
 *  Login rate limiting
 * ------------------------------------------------------------------ */

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

function login_blocked_remaining($username) {
    $row = dbrow(
        "SELECT COUNT(*) AS c, MAX(attempted_at) AS last_ts
           FROM qa_login_attempts
          WHERE (username = ? OR ip_address = ?)
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)",
        [$username, req_ip()]
    );
    if (!$row || (int) $row['c'] < LOGIN_MAX_ATTEMPTS) return 0;
    $last = strtotime($row['last_ts']);
    $remaining = LOGIN_WINDOW_MINUTES * 60 - (time() - $last);
    return max(0, (int) ceil($remaining / 60));
}

function record_login_attempt($username, $success) {
    dbq("INSERT INTO qa_login_attempts (username, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())",
        [$username, req_ip(), $success ? 1 : 0]);
    if ($success) {
        dbq("DELETE FROM qa_login_attempts WHERE username = ? OR ip_address = ?", [$username, req_ip()]);
    }
    // keep table small
    dbq("DELETE FROM qa_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}

/* ------------------------------------------------------------------ *
 *  Password reset (token-based, hashed, expiring)
 * ------------------------------------------------------------------ */

function request_password_reset($email) {
    $user = dbrow("SELECT * FROM qa_users WHERE email = ?", [$email]);
    if (!$user) return ['ok' => false, 'msg' => 'If that email exists in our system, a reset link has been sent.'];
    if ($user['status'] !== 'active') return ['ok' => false, 'msg' => 'If that email exists in our system, a reset link has been sent.'];

    $raw = bin2hex(random_bytes(32));
    dbq("UPDATE qa_password_resets SET used = 1 WHERE user_id = ?", [$user['id']]);
    dbq("INSERT INTO qa_password_resets (user_id, token_hash, expires_at, used, created_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 0, NOW())",
        [$user['id'], hash('sha256', $raw)]);
    audit('password_reset_requested', 'Reset requested for ' . $user['username']);

    $link = url('reset-password.php?token=' . $raw);
    $sent = send_mail(
        $user['email'],
        setting('site_name', 'QuizArena') . ' — Password Reset',
        '<p>Hello ' . e($user['full_name']) . ',</p>'
        . '<p>You requested a password reset for your QuizArena account. Click the button below to choose a new password. This link expires in 30 minutes.</p>'
        . '<p style="text-align:center"><a href="' . e($link) . '" style="background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block">Reset Password</a></p>'
        . '<p>If you did not request this, you can safely ignore this email.</p>'
        . '<p>— ' . e(setting('site_name', 'QuizArena')) . ' Team</p>'
    );

    // Developer fallback: expose the link when mail cannot be delivered (e.g. local testing)
    if (!$sent && setting('dev_show_reset_link', '0') === '1') {
        return ['ok' => true, 'msg' => 'Reset link: <a href="' . e($link) . '">' . e($link) . '</a> (mail could not be delivered — showing link in developer mode).'];
    }
    return ['ok' => true, 'msg' => 'If that email exists in our system, a reset link has been sent.'];
}

function validate_reset_token($token) {
    $row = dbrow(
        "SELECT * FROM qa_password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()",
        [hash('sha256', $token)]
    );
    return $row;
}

function consume_reset_token($token, $new_password) {
    $row = validate_reset_token($token);
    if (!$row) return ['ok' => false, 'msg' => 'This reset link is invalid or has expired.'];
    db_begin();
    try {
        dbq("UPDATE qa_password_resets SET used = 1 WHERE id = ?", [$row['id']]);
        dbq("UPDATE qa_users SET password_hash = ?, remember_token = NULL WHERE id = ?",
            [password_hash($new_password, PASSWORD_DEFAULT), $row['user_id']]);
        db_commit();
        audit('password_reset', 'Password reset completed for user #' . $row['user_id']);
        return ['ok' => true, 'msg' => 'Your password has been reset. Please sign in.'];
    } catch (Throwable $e) {
        db_rollback();
        return ['ok' => false, 'msg' => 'Something went wrong. Please try again.'];
    }
}
