<?php
/**
 * QuizArena — Application bootstrap.
 * Loaded at the very top of every public page.
 * Tolerates a missing configuration file (redirects to the installer).
 */

define('QA_RUNNING', true);
define('QA_ROOT', __DIR__ . '/../');

$GLOBALS['QA_CONFIG'] = null;
if (file_exists(__DIR__ . '/config.php')) {
    $loaded = require __DIR__ . '/config.php';
    if (is_array($loaded)) $GLOBALS['QA_CONFIG'] = $loaded;
}
define('QA_INSTALLED', is_array($GLOBALS['QA_CONFIG']));

// ---- PHP runtime settings ------------------------------------------------
error_reporting(E_ALL);
$debug = !empty($GLOBALS['QA_CONFIG']['debug']);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/error.log');

date_default_timezone_set($GLOBALS['QA_CONFIG']['timezone'] ?? 'Asia/Kolkata');
mb_internal_encoding('UTF-8');

// ---- Base URL --------------------------------------------------------------
if (!empty($GLOBALS['QA_CONFIG']['base_url'])) {
    define('BASE_URL', rtrim($GLOBALS['QA_CONFIG']['base_url'], '/'));
}

// ---- Core requires ---------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/quiz.php';
require_once __DIR__ . '/certificate.php';
require_once __DIR__ . '/reports.php';

// ---- Start the session -----------------------------------------------------
qa_session_start();

// ---- Remember-me auto login -------------------------------------------------
if (QA_INSTALLED) {
    try {
        auto_login_from_cookie();
        settings(); // warm settings cache
    } catch (Throwable $e) {
        // DB not reachable — pages will surface a friendly error
    }
}

// ---- Installer gate: if not installed, send the user to the wizard ---------
if (!QA_INSTALLED) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}
