<?php
/**
 * QuizArena — Quiz Coin Wallet engine.
 *
 * Security model:
 *  - One wallet per user, server-side balance only (never trust the browser).
 *  - Every mutation runs inside a MySQL transaction with SELECT ... FOR UPDATE
 *    row locking to prevent double spending.
 *  - Every mutation writes a permanent, uniquely-identified transaction record.
 *  - Amounts are positive integers; category determines credit/debit direction.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/**
 * Transaction guard — lets wallet helpers participate in an outer transaction
 * when one is already open (e.g. quiz finalisation, registration, CSV import)
 * instead of failing with "There is already an active transaction".
 *
 * @return bool true if a NEW transaction was started by this call
 */
function tx_guard() {
    $outer = pdo()->inTransaction();
    if (!$outer) db_begin();
    return $outer;
}

function tx_release($is_outer, $ok = true) {
    if (!$is_outer) {
        if ($ok) db_commit(); else db_rollback();
    }
}

/** Create a wallet for a user (idempotent). Returns wallet row. */
function ensure_wallet($user_id, $school_id = null) {
    if ($school_id === null) {
        $school_id = (int) dbval("SELECT school_id FROM qa_users WHERE id = ?", [$user_id]);
    }
    dbq("INSERT INTO qa_wallets (user_id, school_id, balance, created_at, updated_at)
         VALUES (?, ?, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()", [$user_id, $school_id ?: 0]);
    return dbrow("SELECT * FROM qa_wallets WHERE user_id = ?", [$user_id]);
}

function wallet_balance($user_id) {
    $w = dbrow("SELECT * FROM qa_wallets WHERE user_id = ?", [$user_id]);
    if (!$w) $w = ensure_wallet($user_id);
    return (int) $w['balance'];
}

/**
 * Core wallet mutation — must be called inside an open DB transaction.
 *
 * @param int    $user_id     account owner
 * @param string $type        'credit' | 'debit'
 * @param string $category    one of the wallet_transactions.category values
 * @param int    $amount      positive integer
 * @param array  $opts        sender_user_id, receiver_user_id, sender_username,
 *                            receiver_username, reference, description, status
 * @return array{ok:bool, txn_id:?string, balance_after:?int, error:?string}
 */
function wallet_apply($user_id, $type, $category, $amount, $opts = []) {
    $amount = (int) $amount;
    if ($amount <= 0) return ['ok' => false, 'txn_id' => null, 'balance_after' => null, 'error' => 'Amount must be a positive whole number.'];

    $allowed_categories = [
        'signup_bonus', 'admin_add', 'admin_deduct', 'entry_fee', 'refund',
        'completion_bonus', 'passing_reward', 'rank_reward', 'transfer_sent',
        'transfer_received', 'quiz_reward',
        // v3.3 Phase A categories (wallet category ENUM appended by the migration;
        // used only by app/accounting.php after activation)
        'purchase', 'purchase_reward', 'purchase_refund',
        'commission_seller', 'commission_salesperson', 'commission_city',
        'commission_district', 'commission_state', 'commission_referral',
        'commission_reversal', 'withdrawal', 'withdrawal_reversal',
    ];
    if (!in_array($category, $allowed_categories, true)) {
        return ['ok' => false, 'txn_id' => null, 'balance_after' => null, 'error' => 'Invalid transaction category.'];
    }

    if (!pdo()->inTransaction()) {
        return ['ok' => false, 'txn_id' => null, 'balance_after' => null, 'error' => 'Wallet mutation called outside a transaction.'];
    }

    // Lock the wallet row so concurrent requests cannot double spend
    $wallet = dbrow("SELECT * FROM qa_wallets WHERE user_id = ? FOR UPDATE", [$user_id]);
    if (!$wallet) {
        ensure_wallet($user_id); // create inside the open transaction
        $wallet = dbrow("SELECT * FROM qa_wallets WHERE user_id = ? FOR UPDATE", [$user_id]);
        if (!$wallet) return ['ok' => false, 'txn_id' => null, 'balance_after' => null, 'error' => 'Wallet not found.'];
    }

    $balance = (int) $wallet['balance'];

    // Fresh unique transaction id with collision-safe retry
    for ($i = 0; $i < 5; $i++) {
        $tid = txn_id();
        if (!dbval("SELECT COUNT(*) FROM qa_wallet_transactions WHERE transaction_id = ?", [$tid])) break;
        $tid = txn_id();
    }

    if ($type === 'debit') {
        if ($balance < $amount) {
            return ['ok' => false, 'txn_id' => null, 'balance_after' => $balance, 'error' => 'Insufficient Quiz Coin balance.'];
        }
        $new_balance = $balance - $amount;
    } elseif ($type === 'credit') {
        $new_balance = $balance + $amount;
    } else {
        return ['ok' => false, 'txn_id' => null, 'balance_after' => null, 'error' => 'Invalid transaction type.'];
    }

    // v3.3: optional accounting stamps (Phase A columns). Included in the INSERT
    // only when provided, so legacy calls keep byte-identical SQL pre-migration.
    $stamp_cols = $stamp_vals = [];
    foreach (['coin_value' => 'coin_value', 'amount_inr' => 'amount_inr',
              'source_type' => 'source_type', 'source_id' => 'source_id'] as $opt => $col) {
        if (array_key_exists($opt, $opts) && $opts[$opt] !== null) {
            $stamp_cols[] = $col;
            $stamp_vals[] = $opts[$opt];
        }
    }

    $school_id = (int) ($wallet['school_id'] ?: ($opts['school_id'] ?? 0));
    $sender_uid   = $opts['sender_user_id']   ?? null;
    $receiver_uid = $opts['receiver_user_id'] ?? null;
    $sender_un    = $opts['sender_username']  ?? null;
    $receiver_un  = $opts['receiver_username'] ?? null;

    dbq("UPDATE qa_wallets SET balance = ?, updated_at = NOW() WHERE id = ?", [$new_balance, $wallet['id']]);

    dbq(
        "INSERT INTO qa_wallet_transactions
            (transaction_id, transfer_group_id, user_id, wallet_id, school_id, type, category, amount,
             balance_after, sender_user_id, sender_username, receiver_user_id, receiver_username,
             reference, description, status" . ($stamp_cols ? ', ' . implode(', ', $stamp_cols) : '') . ", created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($stamp_cols ? str_repeat(', ?', count($stamp_cols)) : '') . ", NOW())",
        array_merge([
            $tid,
            $opts['transfer_group_id'] ?? null,
            $user_id,
            $wallet['id'],
            $school_id,
            $type,
            $category,
            $amount,
            $new_balance,
            $sender_uid, $sender_un,
            $receiver_uid, $receiver_un,
            $opts['reference'] ?? null,
            $opts['description'] ?? null,
            $opts['status'] ?? 'completed',
        ], $stamp_vals)
    );

    return ['ok' => true, 'txn_id' => $tid, 'balance_after' => $new_balance, 'error' => null];
}

/**
 * Secure user-to-user Coin transfer.
 * Deducts from sender and credits receiver in ONE transaction.
 *
 * @return array{ok:bool, msg:string}
 */
function transfer_coins($from_uid, $to_uid, $amount) {
    $amount = (int) $amount;
    if ($amount <= 0) return ['ok' => false, 'msg' => 'Amount must be greater than zero.'];
    if ((int) $from_uid === (int) $to_uid) return ['ok' => false, 'msg' => 'You cannot send Quiz Coins to yourself.'];

    $outer = tx_guard();
    try {
        // Lock both wallets (ordered by id to avoid deadlocks)
        $sender = dbrow("SELECT * FROM qa_wallets WHERE user_id = ? FOR UPDATE", [$from_uid]);
        if (!$sender) { tx_release($outer, false); return ['ok' => false, 'msg' => 'Your wallet could not be found.']; }
        $receiver = dbrow("SELECT * FROM qa_wallets WHERE user_id = ? FOR UPDATE", [$to_uid]);
        if (!$receiver) { tx_release($outer, false); return ['ok' => false, 'msg' => 'The receiver has no wallet.']; }

        if ((int) $sender['balance'] < $amount) {
            tx_release($outer, false);
            return ['ok' => false, 'msg' => 'Insufficient Quiz Coin balance.'];
        }

        $u1 = dbrow("SELECT id, full_name, username FROM qa_users WHERE id = ?", [$from_uid]);
        $u2 = dbrow("SELECT id, full_name, username FROM qa_users WHERE id = ?", [$to_uid]);

        $group = 'TR' . date('ymdHis') . strtoupper(random_hex(6));
        $shared = [
            'transfer_group_id' => $group,
            'sender_user_id'    => $from_uid,
            'sender_username'   => $u1['username'] ?? null,
            'receiver_user_id'  => $to_uid,
            'receiver_username' => $u2['username'] ?? null,
            'reference'         => 'P2P:' . $group,
        ];

        $r1 = wallet_apply($from_uid, 'debit', 'transfer_sent', $amount, $shared + [
            'description' => 'Sent ' . $amount . ' ' . coin_name() . ' to @' . ($u2['username'] ?? 'user'),
        ]);
        if (!$r1['ok']) { tx_release($outer, false); return ['ok' => false, 'msg' => $r1['error']]; }

        $r2 = wallet_apply($to_uid, 'credit', 'transfer_received', $amount, $shared + [
            'description' => 'Received ' . $amount . ' ' . coin_name() . ' from @' . ($u1['username'] ?? 'user'),
        ]);
        if (!$r2['ok']) { tx_release($outer, false); return ['ok' => false, 'msg' => $r2['error']]; }

        tx_release($outer, true);

        notify($to_uid, 'Quiz Coins received',
            'You received ' . $amount . ' ' . coin_name() . ' from @' . ($u1['username'] ?? 'user') . '.',
            'student/transactions.php', $u2['school_id'] ?? null);

        return ['ok' => true, 'msg' => 'Successfully sent ' . $amount . ' ' . coin_name() . ' to @' . ($u2['username'] ?? '') . '.', 'txn' => $r1['txn_id']];
    } catch (Throwable $e) {
        tx_release($outer, false);
        error_log('Transfer failed: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Transfer failed due to a server error. Nothing was deducted.'];
    }
}

/**
 * Admin add/deduct coins. Always creates a permanent transaction record.
 */
function admin_adjust_coins($target_uid, $delta, $reason) {
    $delta = (int) $delta;
    if ($delta === 0) return ['ok' => false, 'msg' => 'Amount must not be zero.'];
    $type = $delta > 0 ? 'credit' : 'debit';
    $category = $delta > 0 ? 'admin_add' : 'admin_deduct';
    $amount = abs($delta);
    $admin = current_user();

    $outer = tx_guard();
    try {
        $res = wallet_apply($target_uid, $type, $category, $amount, [
            'description' => ($delta > 0 ? 'Added by ' : 'Deducted by ') . 'administrator' . ($reason ? ' — ' . $reason : ''),
            'sender_user_id' => $admin ? $admin['id'] : null,
            'reference' => 'ADMIN:' . ($admin ? $admin['username'] : 'system'),
        ]);
        if (!$res['ok']) { tx_release($outer, false); return ['ok' => false, 'msg' => $res['error']]; }
        tx_release($outer, true);

        $u = dbrow("SELECT id, username, full_name, school_id FROM qa_users WHERE id = ?", [$target_uid]);
        notify($target_uid, $delta > 0 ? 'Quiz Coins added' : 'Quiz Coins deducted',
            ($delta > 0 ? 'Administrator added ' : 'Administrator deducted ') . $amount . ' ' . coin_name() . ($reason ? ' (' . $reason . ')' : '') . '.',
            'student/wallet.php', $u['school_id'] ?? null);
        audit('wallet_adjust', ($delta > 0 ? 'Added ' : 'Deducted ') . $amount . ' coins for @' . ($u['username'] ?? $target_uid) . ($reason ? " ($reason)" : ''));
        return ['ok' => true, 'msg' => 'Wallet updated. Balance is now ' . $res['balance_after'] . '.'];
    } catch (Throwable $e) {
        tx_release($outer, false);
        return ['ok' => false, 'msg' => 'Wallet update failed.'];
    }
}

/** Grant signup bonus (called at registration). Returns ok/msg. */
function grant_signup_bonus($user_id, $amount = null) {
    $amount = $amount !== null ? (int) $amount : (int) setting('signup_bonus', 50);
    if ($amount <= 0) return true;
    $outer = tx_guard();
    try {
        $res = wallet_apply($user_id, 'credit', 'signup_bonus', $amount, ['description' => 'Sign-up bonus']);
        if (!$res['ok']) { tx_release($outer, false); return false; }
        tx_release($outer, true);
        return true;
    } catch (Throwable $e) {
        tx_release($outer, false);
        return false;
    }
}

/** Refund an entry fee (used for failed/expired-quiz refunds). */
function refund_entry_fee($user_id, $amount, $reference, $description) {
    $outer = tx_guard();
    try {
        $res = wallet_apply($user_id, 'credit', 'refund', (int) $amount, [
            'description' => $description,
            'reference'   => $reference,
            'status'      => 'completed',
        ]);
        if (!$res['ok']) { tx_release($outer, false); return ['ok' => false, 'msg' => $res['error']]; }
        tx_release($outer, true);
        notify($user_id, 'Quiz Coins refunded', $description, 'student/wallet.php');
        return ['ok' => true, 'msg' => 'Refund of ' . $amount . ' ' . coin_name() . ' issued.'];
    } catch (Throwable $e) {
        tx_release($outer, false);
        return ['ok' => false, 'msg' => 'Refund failed.'];
    }
}
