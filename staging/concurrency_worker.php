<?php
/**
 * QuizArena staging concurrency worker — ONE real PHP CLI process.
 * Usage: php staging/concurrency_worker.php <task> <json-args>
 * Tasks: write_ledger | debit_wallet | credit_wallet | counts
 * Output: one JSON line on stdout.
 */

require __DIR__ . '/staging_bootstrap.php';
stg_load_product();

$task = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];

function out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

switch ($task) {
    case 'write_ledger': {
        try {
            $r = ledger_write([
                'source_type' => 'offline_sale', 'class' => 'revenue',
                'key' => (string) $args['key'], 'source_id' => (int) $args['source_id'],
                'inputs' => ['gross' => (int) $args['gross'], 'commission_seller' => 1000],
                'payouts' => [['col' => 'commission_seller', 'op' => 'credit', 'user_id' => 5, 'category' => 'commission_seller']],
                'seller_user_id' => 5,
            ]);
            out(['outcome' => $r['duplicate'] ? 'duplicate' : 'created', 'id' => $r['id']]);
        } catch (AccIdempotencyCollision $e) {
            out(['outcome' => 'collision', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            out(['outcome' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    case 'credit_wallet': {
        $outer = tx_guard();
        $r = wallet_apply((int) $args['user'], 'credit', 'admin_add', (int) $args['amt'], ['description' => 'staging preload']);
        tx_release($outer, $r['ok']);
        out(['ok' => $r['ok'], 'error' => $r['error'], 'balance' => wallet_balance((int) $args['user'])]);
        break;
    }

    case 'debit_wallet': {
        $r = wallet_apply((int) $args['user'], 'debit', 'entry_fee', (int) $args['amt'], ['description' => 'staging debit']);
        if ($r['ok']) {
            // caller wraps its own txn for overlap realism
            out(['ok' => true, 'balance' => $r['balance_after'], 'error' => null]);
        } else {
            out(['ok' => false, 'balance' => null, 'error' => $r['error']]);
        }
        break;
    }

    case 'counts': {
        $key = (string) $args['key'];
        out([
            'ledger_rows'  => (int) dbval("SELECT COUNT(*) FROM qa_company_ledger WHERE idempotency_key = ?", [$key]),
            'wallet_txns'  => (int) dbval("SELECT COUNT(*) FROM qa_wallet_transactions WHERE reference = ?", [$key]),
            'balance_user5' => wallet_balance(5),
            'collisions'   => (int) dbval("SELECT COUNT(*) FROM qa_audit_log WHERE action = 'idempotency_collision' AND source_ref = ?", [$key]),
        ]);
        break;
    }

    default:
        out(['outcome' => 'error', 'message' => "unknown task {$task}"]);
}
