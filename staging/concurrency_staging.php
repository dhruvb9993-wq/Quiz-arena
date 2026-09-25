<?php
/**
 * QuizArena — REAL MySQL/MariaDB concurrency staging drill (runnable script).
 *
 * Two GENUINELY CONCURRENT PHP CLI processes hit a real InnoDB database through
 * the REAL app/accounting.php + app/wallet.php (no shims, no translation).
 *
 * Usage (from the repo root, on the staging host):
 *   export QA_STAGING_DB_HOST=... QA_STAGING_DB_PORT=3306
 *   export QA_STAGING_DB_NAME=quizarena_staging QA_STAGING_DB_USER=... QA_STAGING_DB_PASS=...
 *   export QA_STAGING_ALLOW_SCHEMA=1        # first run only — creates/RESETS tables (disposable DB!)
 *   php staging/concurrency_staging.php
 *
 * Exit code 0 = all expectations met. Any mismatch = non-zero with a report.
 */

require __DIR__ . '/staging_bootstrap.php';

$reset = ($argv[1] ?? '') === '--reset';
stg_setup_tables($reset || getenv('QA_STAGING_FORCE_RESET') === '1');
stg_load_product();

$pass = 0; $fail = 0; $lines = [];
function check(string $name, bool $ok, string $actual): void {
    global $pass, $fail, $lines;
    $ok ? $pass++ : $fail++;
    $lines[] = sprintf("  %s  %s  [actual: %s]", $ok ? 'PASS' : 'FAIL', $name, $actual);
}

/** Spawn a worker process and decode its JSON result. */
function worker(string $task, array $args): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/concurrency_worker.php')
         . ' ' . escapeshellarg($task) . ' ' . escapeshellarg(json_encode($args));
    $pipes = null;
    $proc = proc_open($cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['outcome' => 'error', 'message' => "worker output: {$out}"];
}

/** Spawn two workers AT THE SAME INSTANT (both started before either is awaited). */
function pair(string $task, array $a1, array $a2): array {
    $cmdBase = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/concurrency_worker.php');
    $p1 = proc_open($cmdBase . ' ' . escapeshellarg($task) . ' ' . escapeshellarg(json_encode($a1)) . ' 2>&1', [1 => ['pipe', 'w']], $w1);
    $p2 = proc_open($cmdBase . ' ' . escapeshellarg($task) . ' ' . escapeshellarg(json_encode($a2)) . ' 2>&1', [1 => ['pipe', 'w']], $w2);
    $o1 = json_decode(trim(stream_get_contents($w1[1])), true);
    $o2 = json_decode(trim(stream_get_contents($w2[1])), true);
    fclose($w1[1]); fclose($w2[1]);
    proc_close($p1); proc_close($p2);
    return [$o1 ?: ['outcome' => 'error'], $o2 ?: ['outcome' => 'error']];
}

$lines[] = '== SCENARIO A: same key + same payload, two parallel processes ==';
[$w1, $w2] = pair('write_ledger',
    ['key' => 'order:9001', 'source_id' => 9001, 'gross' => 50000],
    ['key' => 'order:9001', 'source_id' => 9001, 'gross' => 50000]);
$outcomes = [$w1['outcome'] ?? '?', $w2['outcome'] ?? '?'];
sort($outcomes);
check('A outcomes = created + duplicate', $outcomes === ['created', 'duplicate'], implode(',', $outcomes));
$c = worker('counts', ['key' => 'order:9001']);
check('A ledger row count = 1', $c['ledger_rows'] === 1, (string) $c['ledger_rows']);
check('A wallet txn count = 1', $c['wallet_txns'] === 1, (string) $c['wallet_txns']);
check('A seller balance = 10 (single 10.00 commission at cv ₹1)', $c['balance_user5'] === 10, (string) $c['balance_user5']);
check('A collision audit count = 0', $c['collisions'] === 0, (string) $c['collisions']);

$lines[] = '== SCENARIO B: same key + DIFFERENT payload, two parallel processes ==';
[$w1, $w2] = pair('write_ledger',
    ['key' => 'order:9002', 'source_id' => 9002, 'gross' => 50000],
    ['key' => 'order:9002', 'source_id' => 9002, 'gross' => 70000]);
$outcomes = [$w1['outcome'] ?? '?', $w2['outcome'] ?? '?'];
sort($outcomes);
check('B outcomes = created + collision', $outcomes === ['collision', 'created'], implode(',', $outcomes));
$c = worker('counts', ['key' => 'order:9002']);
check('B ledger row count = 1', $c['ledger_rows'] === 1, (string) $c['ledger_rows']);
check('B wallet txn count = 1 (only the winner pays)', $c['wallet_txns'] === 1, (string) $c['wallet_txns']);
check('B collision audit count = 1 (survives the loser rollback)', $c['collisions'] === 1, (string) $c['collisions']);
check('B seller balance still 10 (no double payout, no partial state)', $c['balance_user5'] === 10, (string) $c['balance_user5']);

$lines[] = '== SCENARIO C: competing wallet debits (100 balance, two parallel 100 debits) ==';
worker('credit_wallet', ['user' => 9, 'amt' => 100]);
[$w1, $w2] = pair('debit_wallet', ['user' => 9, 'amt' => 100], ['user' => 9, 'amt' => 100]);
$okCount = ($w1['ok'] ? 1 : 0) + ($w2['ok'] ? 1 : 0);
check('C exactly one of the two competing debits succeeds', $okCount === 1, "ok={$okCount}");
$bal = wallet_balance(9);
check('C final balance = 0 (never negative)', $bal === 0, (string) $bal);
$txns = (int) dbval("SELECT COUNT(*) FROM qa_wallet_transactions WHERE user_id = 9 AND status = 'completed'");
check('C completed txns for user 9 = 2 (1 credit + 1 debit)', $txns === 2, (string) $txns);
$neg = (int) dbval("SELECT COUNT(*) FROM qa_wallets WHERE balance < 0");
check('C no wallet ever negative', $neg === 0, (string) $neg);

$lines[] = '== SCENARIO D: overlapping transactions (different keys, same wallet, parallel) ==';
[$w1, $w2] = pair('write_ledger',
    ['key' => 'order:9003', 'source_id' => 9003, 'gross' => 50000],
    ['key' => 'order:9004', 'source_id' => 9004, 'gross' => 50000]);
$outcomes = [$w1['outcome'] ?? '?', $w2['outcome'] ?? '?'];
sort($outcomes);
check('D both distinct orders created', $outcomes === ['created', 'created'], implode(',', $outcomes));
$c3 = worker('counts', ['key' => 'order:9003']);
$c4 = worker('counts', ['key' => 'order:9004']);
check('D each order: 1 ledger row + 1 wallet txn',
    $c3['ledger_rows'] === 1 && $c3['wallet_txns'] === 1 && $c4['ledger_rows'] === 1 && $c4['wallet_txns'] === 1,
    "9003:{$c3['ledger_rows']}/{$c3['wallet_txns']} 9004:{$c4['ledger_rows']}/{$c4['wallet_txns']}");
check('D seller balance = 30 (10+10+10 across A/D, no lost update)', wallet_balance(5) === 30, (string) wallet_balance(5));

$lines[] = '';
$lines[] = "RESULT: {$pass} passed, {$fail} failed  (real MySQL/MariaDB InnoDB, parallel PHP processes)";
echo implode("\n", $lines), "\n";
exit($fail ? 1 : 0);
