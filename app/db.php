<?php
/**
 * QuizArena — Database connection layer.
 * The actual connection factory (pdo()) lives in functions.php.
 * This file is a thin module for connection helpers used by the installer.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/**
 * Test a MySQL connection and return diagnostics.
 * Used by the browser installer to validate credentials before writing config.
 *
 * @return array{ok:bool, pdo:?PDO, error:?string}
 */
function test_db_connection($host, $port, $name, $user, $pass) {
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        return ['ok' => true, 'pdo' => $pdo, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'pdo' => null, 'error' => $e->getMessage()];
    }
}
