<?php
/**
 * QuizArena — Centralized accounting engine (locked v3.3).
 *
 * THE single writer of qa_company_ledger, qa_commissions (rule-based
 * purchase/offline_sale rows) and qa_stock_movements. All money is INTEGER
 * PAISE — zero floats anywhere in this module.
 *
 * Universal identities (asserted after all values are computed, inside
 * ledger_write()):
 *   V1: gross − (customer_reward + commissions + withdrawal_amount
 *               + adjustment_amount) − fees − taxes − company_share = 0
 *       ⇒ company_share is always COMPUTED here (callers never pass it):
 *         share = gross − fees − taxes − Σ(payout columns)
 *   mirror = Σ wallet INR of this row's payouts  = Σ ±(coins × coin_value)
 *   diff   = mirror − Σ(payout columns)          (rounding residual)
 *   V3: gross − fees − taxes − share − (coin_amount × coin_value) + diff = 0
 *       (holds by construction; verified numerically per row)
 *
 * Rounding: coins = to_coins(paise, cv) — round-half-up, POSITIVE amounts
 * only; reversals NEVER re-round negatives, they negate the original coins
 * exactly (coins_exact).
 *
 * Idempotency: every write carries a unique key + SHA-256 of its canonical
 * payload. Same key + same hash ⇒ no-op success (existing id returned).
 * Same key + different hash ⇒ hard failure + qa_audit_log
 * 'idempotency_collision' (written after rollback so it survives) + throw.
 *
 * SOURCE_MATRIX (locked, 12 source types) validates: allowed class, allowed
 * amount columns, per-column signs, coin eligibility, reversibility, key
 * pattern and required references — BEFORE anything is written.
 *
 * This module is inert until the Phase A migration is activated
 * (schema_version = 2) — every entry point calls acc_assert_active().
 */

if (!defined('QA_RUNNING')) { exit('Direct access denied'); }

/* ------------------------------------------------------------------ *
 *  Exceptions
 * ------------------------------------------------------------------ */

class AccException extends RuntimeException {}
class AccIdempotencyCollision extends AccException {
    public string $key;
    public string $table;
    public function __construct(string $message, string $key, string $table) {
        parent::__construct($message);
        $this->key = $key;
        $this->table = $table;
    }
}

/* ------------------------------------------------------------------ *
 *  Canonical payloads (locked field lists — order is part of the lock)
 * ------------------------------------------------------------------ */

const QA_CANON_LEDGER = [
    'source_type', 'source_id', 'class',
    'gross', 'fees', 'taxes', 'customer_reward',
    'commission_seller', 'commission_salesperson', 'commission_city',
    'commission_district', 'commission_state', 'commission_referral',
    'withdrawal_amount', 'adjustment_amount', 'company_share',
    'coin_amount', 'coin_value', 'rounding_diff', 'reversal_of',
];
const QA_CANON_COMMISSION = [
    'payout_key', 'rule_id', 'source_type', 'source_id', 'line_no',
    'beneficiary_user_id', 'beneficiary_role', 'amount',
    'coin_amount', 'coin_value', 'reversal_of',
];
const QA_CANON_STOCK = ['product_id', 'delta', 'type', 'ref_type', 'ref_id'];

/** Canonical JSON of an ORDERED field map (insertion order preserved). */
function acc_canonical(array $ordered): string {
    return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** SHA-256 payload hash of an ordered field map. */
function acc_hash(array $ordered): string {
    return hash('sha256', acc_canonical($ordered));
}

/* ------------------------------------------------------------------ *
 *  Money primitives (integer paise — zero floats)
 * ------------------------------------------------------------------ */

/** Current coin value in paise (one cv per ledger row). */
function acc_cv(): int {
    $cv = (int) setting('coin_value_paise', 100);
    if ($cv <= 0) throw new AccException('coin_value_paise setting must be a positive integer (paise per coin).');
    return $cv;
}

/**
 * Convert paise → coins, round-half-up. POSITIVE amounts only:
 * reversals must negate the ORIGINAL coin count, never re-round a negative.
 */
function to_coins(int $paise, int $cv): int {
    if ($paise < 0) {
        throw new AccException('to_coins(): negative amount — reversals must negate the original coins exactly (use coins_exact).');
    }
    if ($cv <= 0) throw new AccException('to_coins(): coin value must be positive.');
    return intdiv(2 * $paise + $cv, 2 * $cv);
}

/** Exact negation of every amount/coin column of a ledger row (helper for full reversals). */
function acc_negate_row(array $row): array {
    foreach (['gross', 'fees', 'taxes', 'customer_reward', 'commission_seller', 'commission_salesperson',
              'commission_city', 'commission_district', 'commission_state', 'commission_referral',
              'withdrawal_amount', 'adjustment_amount', 'company_share', 'coin_amount', 'rounding_diff'] as $c) {
        if (array_key_exists($c, $row)) $row[$c] = -$row[$c];
    }
    return $row;
}

/* ------------------------------------------------------------------ *
 *  SOURCE_MATRIX (locked — 12 source types)
 * ------------------------------------------------------------------ *
 * Per type:
 *   classes        allowed ledger class(es)
 *   cols           amount columns this type may use (non-zero)
 *   sign           'nonneg' (revenue-side rows) | 'nonpos' (reversal/expense-out rows)
 *   coins          coin payouts allowed on this row
 *   reversible     whether dedicated reversal source types exist
 *   key            idempotency-key pattern ({id} placeholder)
 *   reverses       the source type this type reverses (reversal types only)
 */

const QA_SOURCE_MATRIX = [

    'order' => [
        'classes' => ['revenue'],
        'cols' => ['gross', 'fees', 'taxes', 'customer_reward', 'commission_seller', 'commission_salesperson',
                   'commission_city', 'commission_district', 'commission_state', 'commission_referral'],
        'sign' => 'nonneg', 'coins' => true, 'reversible' => true,
        'key' => 'order:{id}',
        'desc' => 'online order (coins-only payment this phase)',
    ],
    'offline_sale' => [
        'classes' => ['revenue'],
        'cols' => ['gross', 'fees', 'taxes', 'commission_seller', 'commission_salesperson',
                   'commission_city', 'commission_district', 'commission_state', 'commission_referral'],
        'sign' => 'nonneg', 'coins' => true, 'reversible' => true,
        'key' => 'offline_sale:{id}',
        'desc' => 'offline POS sale (cash record)',
    ],
    'quiz' => [
        'classes' => ['revenue', 'expense'],
        'cols' => ['gross', 'fees', 'taxes', 'customer_reward'],   // no commissions on quiz rows (locked)
        'sign' => 'nonneg', 'coins' => true, 'reversible' => true,
        'key' => 'quiz:attempt:{id}[:fee|:reward]',
        'desc' => 'company-funded quiz — TWO rows: revenue fee row (gross=fee, payouts=0) + expense reward row '
                . '(gross=0, customer_reward=rewards); self-funded attempts write only the fee row; '
                . 'fee=0 company-funded writes only the reward row',
    ],
    'refund' => [
        'classes' => ['expense'],
        'cols' => ['gross', 'fees', 'taxes', 'customer_reward', 'commission_seller', 'commission_salesperson',
                   'commission_city', 'commission_district', 'commission_state', 'commission_referral'],
        'sign' => 'nonpos', 'coins' => true, 'reversible' => false,
        'key' => 'refund:order:{id}[:line:{n}]',
        'reverses' => 'order',
        'desc' => 'order refund (full or per-line partial) — negates affected columns of the original order row',
    ],
    'offline_refund' => [
        'classes' => ['expense'],
        'cols' => ['gross', 'fees', 'taxes', 'commission_seller', 'commission_salesperson',
                   'commission_city', 'commission_district', 'commission_state', 'commission_referral'],
        'sign' => 'nonpos', 'coins' => true, 'reversible' => false,
        'key' => 'offline_refund:{id}',
        'reverses' => 'offline_sale',
        'desc' => 'offline sale refund',
    ],
    'quiz_refund' => [
        'classes' => ['expense'],
        'cols' => ['gross', 'fees', 'taxes'],
        'sign' => 'nonpos', 'coins' => false, 'reversible' => false,
        'key' => 'quiz_refund:attempt:{id}',
        'reverses' => 'quiz',
        'desc' => 'refunded attempt — negates the quiz fee row; reward wallet txns are set status=reversed '
                . 'and the company reward row is flipped to reversed (D16: zero net reward) by quiz_refund_rewards()',
    ],
    'referral' => [
        'classes' => ['expense'],
        'cols' => ['commission_referral'],
        'sign' => 'nonneg', 'coins' => true, 'reversible' => true,
        'key' => 'referral:{id}',
        'desc' => 'referral reward to the referrer (wallet + ledger only — never qa_commissions)',
    ],
    'referral_reversal' => [
        'classes' => ['revenue'],
        'cols' => ['commission_referral'],
        'sign' => 'nonpos', 'coins' => true, 'reversible' => false,
        'key' => 'referral_reversal:{id}',
        'reverses' => 'referral',
        'desc' => 'negates a referral reward',
    ],
    'signup' => [
        'classes' => ['expense'],
        'cols' => ['customer_reward'],
        'sign' => 'nonneg', 'coins' => true, 'reversible' => false,
        'key' => 'signup:{uid}',
        'desc' => 'signup bonus cost (row written only when the bonus is > 0)',
    ],
    'withdrawal' => [
        'classes' => ['expense'],
        'cols' => ['withdrawal_amount'],
        'sign' => 'nonneg', 'coins' => false, 'reversible' => true,
        'key' => 'withdrawal:{id}',
        'desc' => 'coin withdrawal at stamped value — wallet debit happens on the withdrawal request; '
                . 'this row has NO coin payouts (mirror 0, diff = −withdrawal_amount by the universal formula)',
    ],
    'withdrawal_reversal' => [
        'classes' => ['revenue'],
        'cols' => ['withdrawal_amount'],
        'sign' => 'nonpos', 'coins' => false, 'reversible' => false,
        'key' => 'withdrawal_reversal:{id}',
        'reverses' => 'withdrawal',
        'desc' => 'rejected/reversed withdrawal — coins returned via the linked wallet credit',
    ],
    'adjustment' => [
        'classes' => ['revenue', 'expense'],
        'cols' => ['adjustment_amount'],
        'sign' => 'both', 'coins' => true, 'reversible' => false,
        'key' => 'adjust:{wallet_txn_id}',
        'desc' => 'manual SA adjustment (incl. standalone commission payout reversals: '
                . 'gross 0, affected commission column negative on the ORIGINAL-free row, share positive, '
                . 'audit_ref = payout_key, key payout_reversal:{original_payout_key})',
    ],
];

/** Payout-capable ledger columns (V1 payout set). */
function acc_payout_cols(): array {
    return ['customer_reward', 'commission_seller', 'commission_salesperson', 'commission_city',
            'commission_district', 'commission_state', 'commission_referral',
            'withdrawal_amount', 'adjustment_amount'];
}

/**
 * PURE validation of a ledger row request against SOURCE_MATRIX.
 * $inputs: amount columns (paise, signed), plus 'reversal_of' when reversing.
 * Returns the normalized inputs; throws AccException on any violation.
 * (DB-independent — fully unit-testable.)
 */
function acc_validate_inputs(string $source_type, string $class, array $inputs): array {
    if (!isset(QA_SOURCE_MATRIX[$source_type])) {
        throw new AccException("Unknown ledger source_type '{$source_type}'.");
    }
    $spec = QA_SOURCE_MATRIX[$source_type];
    if (!in_array($class, $spec['classes'], true)) {
        throw new AccException("source_type '{$source_type}' does not allow class '{$class}'.");
    }

    $cols = acc_payout_cols();
    $sum_payouts = 0;
    foreach ($cols as $c) {
        $v = (int) ($inputs[$c] ?? 0);
        if (!isset($spec['cols']) || !in_array($c, $spec['cols'], true)) {
            if ($v !== 0) throw new AccException("Column '{$c}' is not permitted on {$source_type} rows.");
        }
        if ($v !== 0 && $spec['sign'] === 'nonneg' && $v < 0) {
            throw new AccException("Sign violation: '{$c}' must be >= 0 on {$source_type}/{$class} rows.");
        }
        if ($v !== 0 && $spec['sign'] === 'nonpos' && $v > 0) {
            throw new AccException("Sign violation: '{$c}' must be <= 0 on {$source_type}/{$class} rows.");
        }
        $inputs[$c] = $v;
        $sum_payouts += $v;
    }

    foreach (['gross', 'fees', 'taxes'] as $c) {
        $v = (int) ($inputs[$c] ?? 0);
        if ($v !== 0 && $spec['sign'] === 'nonneg' && $v < 0) {
            throw new AccException("Sign violation: '{$c}' must be >= 0 on {$source_type}/{$class} rows.");
        }
        if ($v !== 0 && $spec['sign'] === 'nonpos' && $v > 0) {
            throw new AccException("Sign violation: '{$c}' must be <= 0 on {$source_type}/{$class} rows.");
        }
        $inputs[$c] = $v;
    }

    // Quiz two-row contract (locked Correction 1):
    if ($source_type === 'quiz') {
        if ($class === 'revenue') {
            if (($inputs['gross'] ?? 0) <= 0) throw new AccException('quiz fee row requires gross > 0.');
            // Self-funded (E12): gross 120 + customer_reward 15 → share 105 (single revenue row).
            // Company-funded fee row: payouts 0 → share = gross.
        } else {
            if (($inputs['gross'] ?? 0) !== 0) throw new AccException('quiz reward row must have gross = 0.');
            if (($inputs['customer_reward'] ?? 0) <= 0) throw new AccException('quiz reward row requires customer_reward > 0.');
            if ($sum_payouts !== (int) $inputs['customer_reward']) {
                throw new AccException('quiz reward row may only pay customer_reward.');
            }
        }
    }

    // Universal: gross > 0 with negative share is forbidden on ONE row (locked) — i.e.
    // a revenue row must not pay out more than it keeps; expense rows with gross 0 are the exception.
    if ($class === 'revenue' && ($inputs['gross'] ?? 0) > 0) {
        // (share is computed later; this guards the raw shape)
        if ($sum_payouts + ($inputs['fees'] ?? 0) + ($inputs['taxes'] ?? 0) > $inputs['gross']) {
            throw new AccException('Revenue row payouts + fees + taxes exceed gross — invalid shape.');
        }
    }

    $inputs['class'] = $class;
    $inputs['source_type'] = $source_type;
    return $inputs;
}

/**
 * Build a source idempotency key from the locked pattern.
 * Bracketed optional segments in the pattern (e.g. '[:fee|:reward]') are
 * documentation; pass the concrete suffix as $suffix ('fee', 'reward',
 * 'line:3', ...) to append it.
 */
function acc_source_key(string $source_type, int $id, ?string $suffix = null): string {
    if (!isset(QA_SOURCE_MATRIX[$source_type])) {
        throw new AccException("Unknown source_type '{$source_type}'.");
    }
    $key = preg_replace('/\[.*\]/', '', QA_SOURCE_MATRIX[$source_type]['key']);
    $key = str_replace('{id}', (string) $id, $key);
    $key = str_replace('{uid}', (string) $id, $key);
    if (strpos($key, '{') !== false) {
        throw new AccException("Incomplete source key for {$source_type}: '{$key}'.");
    }
    return $suffix !== null && $suffix !== '' ? $key . ':' . $suffix : $key;
}

/* ------------------------------------------------------------------ *
 *  Pure row math — share / mirror / diff / V1 / V3
 * ------------------------------------------------------------------ */

/**
 * Compute the accounting-complete row from validated inputs.
 * $payout_plan: list of ['col'=>..., 'op'=>'credit'|'debit', 'coins'=>int, 'coins_exact'?:int]
 *   (coins > 0 only when the column amount allows it; debit payouts belong to
 *    reversal rows and carry coins_exact = original coin count).
 * Returns [row(array with company_share, coin_amount, rounding_diff), mirror(int)].
 */
function acc_compute_row(array $inputs, array $payout_plan, int $cv): array {
    $sum_payouts = 0;
    foreach (acc_payout_cols() as $c) $sum_payouts += (int) ($inputs[$c] ?? 0);

    $mirror = 0; $coin_amount = 0;
    foreach ($payout_plan as $po) {
        $col = $po['col'];
        $colv = (int) ($inputs[$col] ?? 0);
        $credit = ($po['op'] ?? 'credit') === 'credit';
        if ($credit && $colv < 0) throw new AccException("Payout credit on non-positive column '{$col}'.");
        if (!$credit && $colv > 0) throw new AccException("Payout debit on non-negative column '{$col}'.");
        if (isset($po['coins_exact'])) {
            $coins = (int) $po['coins_exact'];
            if ($coins < 0) throw new AccException('coins_exact must be >= 0.');
        } else {
            $coins = to_coins(abs($colv), $cv);      // single rounding convention
        }
        $sign = $credit ? 1 : -1;
        $mirror    += $sign * $coins * $cv;
        $coin_amount += $sign * $coins;
    }


    $row = $inputs;
    $row['company_share'] = ($inputs['gross'] ?? 0) - ($inputs['fees'] ?? 0) - ($inputs['taxes'] ?? 0) - $sum_payouts;
    $row['coin_amount']   = $coin_amount;
    $row['coin_value']    = $coin_amount !== 0 ? $cv : 0;   // one cv per row; 0 when no coins moved
    $row['rounding_diff'] = $mirror - $sum_payouts;

    // V1 (locked): gross − Σ payouts − fees − taxes − share = 0
    $v1 = $row['gross'] - $sum_payouts - $row['fees'] - $row['taxes'] - $row['company_share'];
    if ($v1 !== 0) throw new AccException("V1 identity failed (={$v1}) — refusing to write.");
    // V3 (locked): gross − fees − taxes − share − mirror + diff = 0
    $v3 = $row['gross'] - $row['fees'] - $row['taxes'] - $row['company_share'] - $mirror + $row['rounding_diff'];
    if ($v3 !== 0) throw new AccException("V3 identity failed (={$v3}) — refusing to write.");

    return [$row, $mirror];
}

/* ------------------------------------------------------------------ *
 *  Activation guard + audit
 * ------------------------------------------------------------------ */

function acc_assert_active(): void {
    if ((string) setting('schema_version', '1') !== '2') {
        throw new AccException('Accounting engine is locked: run (and activate) the Phase A migration first.');
    }
}

/** Structured financial audit row (actor, old, new, reason, IP, UA, source ref). */
function acc_audit(string $action, string $entity_type, ?int $entity_id,
                   $old, $new, string $reason = '', string $source_ref = ''): void {
    dbq("INSERT INTO qa_audit_log (actor_user_id, action, entity_type, entity_id,
            old_value, new_value, reason, ip_address, user_agent, source_ref, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            (int) ($_SESSION['user_id'] ?? 0),
            $action, $entity_type, $entity_id,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            $reason, req_ip(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $source_ref,
        ]);
}

/** Collision audit — called AFTER rollback so it survives. */
function acc_audit_collision(string $table, string $key, string $given_hash, string $stored_hash): void {
    acc_audit('idempotency_collision', $table, null,
        ['key' => $key, 'stored_hash' => $stored_hash],
        ['key' => $key, 'given_hash' => $given_hash],
        'Idempotency key reused with a different payload — write refused.', $key);
}

/* ------------------------------------------------------------------ *
 *  ledger_write() — the only writer of qa_company_ledger
 * ------------------------------------------------------------------ *
 * $args = [
 *   'source_type' => ..., 'key' => explicit idempotency key (validated), 'class' => ...,
 *   'inputs'      => [amount columns...],           // validated against SOURCE_MATRIX
 *   'payouts'     => [ ['col','op','coins_exact'?], ... ],  // wallet movements (executed here)
 *   'cv'          => int paise-per-coin (default current setting; one cv per row),
 *   'reversal_of' => ledger row id (for reversal rows),
 *   'customer_user_id', seller/salesperson/city/district/state/referral user ids,
 *   'audit_ref', 'note', 'source_id'
 * ]
 * Returns ['id'=>, 'duplicate'=>bool, 'wallet_txns'=>[ids], 'row'=>computed row].
 */
function ledger_write(array $args): array {
    acc_assert_active();

    $type  = (string) ($args['source_type'] ?? '');
    $class = (string) ($args['class'] ?? '');
    $key   = trim((string) ($args['key'] ?? ''));
    if ($key === '' || strlen($key) > 120) throw new AccException('ledger_write: invalid idempotency key.');

    $inputs = acc_validate_inputs($type, $class, (array) ($args['inputs'] ?? []));
    $cv     = (int) ($args['cv'] ?? acc_cv());
    if ($cv <= 0) throw new AccException('ledger_write: coin value must be positive.');

    foreach (['source_id'] as $req) {
        if (!isset($args[$req]) || (int) $args[$req] < 0) throw new AccException("ledger_write: {$req} required.");
    }
    $source_id = (int) $args['source_id'];
    $reversal_of = isset($args['reversal_of']) ? (int) $args['reversal_of'] : null;

    // Canonical payload hash — computed from the COMPLETE row (including the
    // derived share/coins/diff), so a replay with any different economics fails.
    $hashRow = function (array $r) use ($type, $source_id, $class, $reversal_of): string {
        return acc_hash([
            'source_type' => $type, 'source_id' => $source_id, 'class' => $class,
            'gross' => $r['gross'], 'fees' => $r['fees'], 'taxes' => $r['taxes'],
            'customer_reward' => $r['customer_reward'],
            'commission_seller' => $r['commission_seller'], 'commission_salesperson' => $r['commission_salesperson'],
            'commission_city' => $r['commission_city'], 'commission_district' => $r['commission_district'],
            'commission_state' => $r['commission_state'], 'commission_referral' => $r['commission_referral'],
            'withdrawal_amount' => $r['withdrawal_amount'], 'adjustment_amount' => $r['adjustment_amount'],
            'company_share' => $r['company_share'], 'coin_amount' => $r['coin_amount'],
            'coin_value' => $r['coin_value'], 'rounding_diff' => $r['rounding_diff'],
            'reversal_of' => $reversal_of,
        ]);
    };

    $outer = tx_guard();
    $committed = false;
    try {
        $existing = dbrow("SELECT * FROM qa_company_ledger WHERE idempotency_key = ? FOR UPDATE", [$key]);
        [$row] = acc_compute_row($inputs, (array) ($args['payouts'] ?? []), $cv);   // V1+V3 asserted
        $row_hash = $hashRow($row);
        if ($existing) {
            tx_release($outer, true); $committed = true;
            $stored_hash = (string) $existing['payload_hash'];
            if ($stored_hash === $row_hash) {
                return ['id' => (int) $existing['id'], 'duplicate' => true, 'wallet_txns' => [], 'row' => $row];
            }
            acc_audit_collision('qa_company_ledger', $key, $row_hash, $stored_hash);
            throw new AccIdempotencyCollision(
                "Idempotency collision on ledger key '{$key}': same key, different payload. Write refused.",
                $key, 'qa_company_ledger');
        }

        // Reversal pre-checks (locked 8-step flow: eligibility + pre-check).
        if ($reversal_of !== null) {
            $orig = dbrow("SELECT * FROM qa_company_ledger WHERE id = ? FOR UPDATE", [$reversal_of]);
            if (!$orig) throw new AccException("Reversal target ledger row #{$reversal_of} not found.");
            if ($orig['status'] === 'reversed') throw new AccException('Ledger row is already fully reversed.');
            if ($orig['reversal_of'] !== null) throw new AccException('Cannot reverse a reversal row.');

            // Every non-zero reversal column must move OPPOSITE to the original,
            // and never exceed the original magnitude (per column and in total).
            $tot_new = 0; $tot_orig = 0;
            $check = function (string $c) use ($inputs, $orig, &$tot_new, &$tot_orig): void {
                $nv = (int) $inputs[$c]; $ov = (int) $orig[$c];
                $tot_new += abs($nv); $tot_orig += abs($ov);
                if ($nv === 0) return;
                if ($ov === 0 || (($nv > 0) === ($ov > 0))) {
                    throw new AccException("Reversal column '{$c}' must move opposite to the original row.");
                }
                if (abs($nv) > abs($ov)) {
                    throw new AccException("Reversal column '{$c}' exceeds the original magnitude.");
                }
            };
            foreach (['gross', 'fees', 'taxes'] as $c) $check($c);
            foreach (acc_payout_cols() as $c) $check($c);
            if ($tot_new > $tot_orig) {
                throw new AccException('Reversal total magnitude exceeds the original row.');
            }
        }

        // Execute the payout plan on the wallets (stamped) — inside this transaction.
        $txn_ids = [];
        foreach ((array) ($args['payouts'] ?? []) as $po) {
            $colv = (int) ($inputs[$po['col']] ?? 0);
            $coins = isset($po['coins_exact']) ? (int) $po['coins_exact'] : to_coins(abs($colv), $cv);
            if ($coins <= 0) continue;
            $op = ($po['op'] ?? 'credit') === 'debit' ? 'debit' : 'credit';
            $res = wallet_apply(
                (int) $po['user_id'], $op, (string) $po['category'], $coins,
                [
                    'reference'    => $key,
                    'description'  => (string) ($po['description'] ?? ($args['note'] ?? $key)),
                    'coin_value'   => $cv,
                    'amount_inr'   => $coins * $cv,
                    'source_type'  => $type,
                    'source_id'    => $source_id,
                ]
            );
            if (!$res['ok']) throw new AccException('Wallet payout failed: ' . $res['error']);
            $txn_ids[] = $res['txn_id'];
        }

        // User-id snapshot columns (reports never depend on live hierarchy).
        $uid = fn(string $k) => isset($args[$k]) ? ((int) $args[$k] ?: null) : null;

        dbq(
            "INSERT INTO qa_company_ledger
             (idempotency_key, payload_hash, source_type, source_id, class,
              gross, fees, taxes, customer_reward, customer_user_id,
              commission_seller, seller_user_id,
              commission_salesperson, salesperson_user_id,
              commission_city, city_user_id,
              commission_district, district_user_id,
              commission_state, state_user_id,
              commission_referral, referral_user_id,
              withdrawal_amount, adjustment_amount, company_share,
              coin_amount, coin_value, rounding_diff,
              reversal_of, status, audit_ref, note, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', ?,?,?,NOW())",
            [
                $key, $row_hash, $type, $source_id, $class,
                $row['gross'], $row['fees'], $row['taxes'], $row['customer_reward'], $uid('customer_user_id'),
                $row['commission_seller'], $uid('seller_user_id'),
                $row['commission_salesperson'], $uid('salesperson_user_id'),
                $row['commission_city'], $uid('city_user_id'),
                $row['commission_district'], $uid('district_user_id'),
                $row['commission_state'], $uid('state_user_id'),
                $row['commission_referral'], $uid('referral_user_id'),
                $row['withdrawal_amount'], $row['adjustment_amount'], $row['company_share'],
                $row['coin_amount'], $row['coin_value'], $row['rounding_diff'],
                $reversal_of,
                (string) ($args['audit_ref'] ?? null),
                (string) ($args['note'] ?? null),
                (int) ($_SESSION['user_id'] ?? 0),
            ]
        );
        $ledger_id = db_id();

        acc_audit('ledger_write', 'qa_company_ledger', $ledger_id, null,
            ['key' => $key, 'source_type' => $type, 'class' => $class,
             'gross' => $row['gross'], 'share' => $row['company_share'],
             'coins' => $row['coin_amount'], 'diff' => $row['rounding_diff']],
            (string) ($args['note'] ?? ''), $key);

        // Link reversal: flip the original row's status (partial vs full is the caller's
        // domain decision — computed from order items — via ledger_mark_reversed()).
        if ($reversal_of !== null) {
            dbq("UPDATE qa_company_ledger SET status = 'partially_reversed' WHERE id = ? AND status = 'active'",
                [$reversal_of]);
        }

        tx_release($outer, true); $committed = true;
        return ['id' => $ledger_id, 'duplicate' => false, 'wallet_txns' => $txn_ids, 'row' => $row];
    } catch (AccIdempotencyCollision $e) {
        if (!$committed) tx_release($outer, false);
        throw $e;
    } catch (Throwable $e) {
        if (!$committed) tx_release($outer, false);
        throw $e;
    }
}

/**
 * Flip a ledger row's reversal status precisely ('reversed') once the caller
 * has established (from order items) that every line is reversed.
 */
function ledger_mark_reversed(int $ledger_id): void {
    acc_assert_active();
    dbq("UPDATE qa_company_ledger SET status = 'reversed'
          WHERE id = ? AND status IN ('active','partially_reversed') AND reversal_of IS NULL", [$ledger_id]);
}

/* ------------------------------------------------------------------ *
 *  commission_write() — rule-based purchase/offline_sale rows only
 * ------------------------------------------------------------------ */

function commission_write(array $args): array {
    acc_assert_active();

    $key = trim((string) ($args['payout_key'] ?? ''));
    $rule_id = (int) ($args['rule_id'] ?? 0);
    $type = (string) ($args['source_type'] ?? '');
    if ($key === '' || strlen($key) > 120) throw new AccException('commission_write: invalid payout_key.');
    if ($rule_id <= 0) throw new AccException('commission_write: rule_id is mandatory (no fake rows).');
    if (!in_array($type, ['purchase', 'offline_sale'], true)) {
        throw new AccException("commission_write: source_type must be purchase|offline_sale (got '{$type}').");
    }
    $amount = (int) ($args['amount'] ?? 0);
    if ($amount < 0) throw new AccException('commission_write: amount must be >= 0 (reversals negate the original row).');

    $cv = (int) ($args['cv'] ?? acc_cv());
    $coins = isset($args['coins_exact']) ? (int) $args['coins_exact'] : ($amount > 0 ? to_coins($amount, $cv) : 0);
    if ($coins < 0) throw new AccException('commission_write: negative coins forbidden (use reversal rows).');

    $hash = acc_hash([
        'payout_key' => $key, 'rule_id' => $rule_id, 'source_type' => $type,
        'source_id' => (int) $args['source_id'], 'line_no' => $args['line_no'] ?? null,
        'beneficiary_user_id' => (int) $args['beneficiary_user_id'],
        'beneficiary_role' => (string) $args['beneficiary_role'],
        'amount' => $amount, 'coin_amount' => $coins, 'coin_value' => $coins > 0 ? $cv : 0,
        'reversal_of' => $args['reversal_of'] ?? null,
    ]);

    $outer = tx_guard(); $committed = false;
    try {
        $existing = dbrow("SELECT * FROM qa_commissions WHERE payout_key = ? FOR UPDATE", [$key]);
        if ($existing) {
            tx_release($outer, true); $committed = true;
            if ((string) $existing['payload_hash'] === $hash) {
                return ['id' => (int) $existing['id'], 'duplicate' => true];
            }
            acc_audit_collision('qa_commissions', $key, $hash, (string) $existing['payload_hash']);
            throw new AccIdempotencyCollision(
                "Idempotency collision on commission payout_key '{$key}'.", $key, 'qa_commissions');
        }

        dbq("INSERT INTO qa_commissions
             (payout_key, payload_hash, rule_id, source_type, source_id, line_no,
              beneficiary_user_id, beneficiary_role, amount, coin_amount, coin_value,
              reversal_of, status, hierarchy_snapshot, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'active', ?, NOW())",
            [
                $key, $hash, $rule_id, $type, (int) $args['source_id'], $args['line_no'] ?? null,
                (int) $args['beneficiary_user_id'], (string) $args['beneficiary_role'],
                $amount, $coins, $coins > 0 ? $cv : 0,
                $args['reversal_of'] ?? null,
                json_encode($args['hierarchy_snapshot'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        $id = db_id();
        tx_release($outer, true); $committed = true;
        return ['id' => $id, 'duplicate' => false];
    } catch (Throwable $e) {
        if (!$committed) tx_release($outer, false);
        throw $e;
    }
}

/* ------------------------------------------------------------------ *
 *  Commission cap — on the UNROUNDED sum, skip lowest priority
 * ------------------------------------------------------------------ *
 * $items: [['role'=>.., 'rule_id'=>.., 'priority'=>int (lower = higher priority),
 *           'rate_type'=>'percent_bp'|'flat_paise', 'rate_value'=>int, 'raw_num'=>int]], gross paise.
 * percent_bp raw numerator = gross × rate_value (denominator 10000) — exact integer math.
 * Cap: Σ unrounded ≤ cap_bp% of gross. Over-cap items are skipped lowest-priority-first.
 * Returns per-item ['role','rule_id','paise','status'] with half-up rounding to the paise.
 */
function apply_commission_cap(array $items, int $gross, ?int $cap_bp = null): array {
    $cap_bp = $cap_bp ?? (int) setting('commission_cap_percent_bp', 4000);
    if ($gross < 0) throw new AccException('apply_commission_cap: gross must be >= 0.');

    foreach ($items as &$it) {
        $it['num'] = $it['rate_type'] === 'percent_bp'
            ? $gross * (int) $it['rate_value']                 // /10000
            : ((int) $it['rate_value']) * 10000;               // flat paise → same denominator
        $it['den'] = 10000;
    }
    unset($it);

    $cap_num = $cap_bp * $gross;                               // /10000
    $sum_num = 0;
    foreach ($items as $it) $sum_num += $it['num'];

    if ($sum_num > $cap_num) {
        // skip lowest priority (largest priority number) first, tie-break larger raw first
        usort($items, fn($a, $b) => [$b['priority'], $b['num']] <=> [$a['priority'], $a['num']]);
        $need = $sum_num - $cap_num;
        foreach ($items as &$it) {
            if ($need <= 0) break;
            $need -= $it['num'];
            $it['num'] = 0;
            $it['skipped'] = true;
        }
        unset($it);
        usort($items, fn($a, $b) => [$a['role'], $a['rule_id']] <=> [$b['role'], $b['rule_id']]);
    }

    $out = [];
    foreach ($items as $it) {
        $out[] = [
            'role' => $it['role'], 'rule_id' => $it['rule_id'],
            'paise' => intdiv($it['num'] + 5000, 10000),        // half-up, zero floats
            'status' => !empty($it['skipped']) ? 'skipped' : 'active',
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 *  stock_move() — the only writer of qa_stock_movements
 * ------------------------------------------------------------------ *
 * 8-step transactional op: guard → key → payload → lock product →
 * eligibility (non-negative) → duplicate check → insert movement → update stock.
 */

function stock_move(array $args): array {
    acc_assert_active();

    $type = (string) ($args['type'] ?? '');
    $allowed = ['purchase_in', 'sale', 'offline_sale', 'refund_in', 'offline_refund_in',
                'cancel_return', 'adjust_in', 'adjust_out', 'initial'];
    if (!in_array($type, $allowed, true)) throw new AccException("stock_move: unknown type '{$type}'.");
    $product_id = (int) ($args['product_id'] ?? 0);
    $delta = (int) ($args['delta'] ?? 0);
    if ($product_id <= 0 || $delta === 0) throw new AccException('stock_move: product_id and non-zero delta required.');
    if ($type === 'initial' && $delta < 0) throw new AccException('stock_move: initial delta must be positive.');

    $ref_type = (string) ($args['ref_type'] ?? '');
    $ref_id   = (int) ($args['ref_id'] ?? 0);
    $line     = (int) ($args['line'] ?? 0);
    $key = "mv:{$type}:{$ref_type}:{$ref_id}:{$product_id}:{$line}";
    if (strlen($key) > 120) throw new AccException("stock_move: idempotency key too long ('{$key}').");

    $hash = acc_hash([
        'product_id' => $product_id, 'delta' => $delta, 'type' => $type,
        'ref_type' => $ref_type, 'ref_id' => $ref_id,
    ]);

    $outer = tx_guard(); $committed = false;
    try {
        // Lock the product row — serialises concurrent stock ops on the same product.
        $p = dbrow("SELECT id, stock, status FROM qa_products WHERE id = ? FOR UPDATE", [$product_id]);
        if (!$p) throw new AccException("stock_move: product #{$product_id} not found.");

        $existing = dbrow("SELECT id, payload_hash FROM qa_stock_movements WHERE idempotency_key = ? FOR UPDATE", [$key]);
        if ($existing) {
            tx_release($outer, true); $committed = true;
            if ((string) $existing['payload_hash'] === $hash) {
                return ['id' => (int) $existing['id'], 'duplicate' => true, 'balance_after' => null];
            }
            acc_audit_collision('qa_stock_movements', $key, $hash, (string) $existing['payload_hash']);
            throw new AccIdempotencyCollision("Idempotency collision on stock key '{$key}'.", $key, 'qa_stock_movements');
        }

        $new_stock = ((int) $p['stock']) + $delta;
        if ($new_stock < 0) {
            throw new AccException("stock_move: non-negative assert failed (stock {$p['stock']} + delta {$delta} < 0).");
        }

        dbq("INSERT INTO qa_stock_movements
             (product_id, type, delta, balance_after, ref_type, ref_id,
              idempotency_key, payload_hash, note, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $product_id, $type, $delta, $new_stock, $ref_type, $ref_id,
                $key, $hash, (string) ($args['note'] ?? null) ?: null,
                (int) ($_SESSION['user_id'] ?? 0),
            ]);
        $mid = db_id();

        dbq("UPDATE qa_products SET stock = ?, updated_at = NOW() WHERE id = ?", [$new_stock, $product_id]);

        acc_audit('stock_move', 'qa_products', $product_id,
            ['stock' => (int) $p['stock']], ['stock' => $new_stock],
            (string) ($args['note'] ?? ''), $key);

        tx_release($outer, true); $committed = true;
        return ['id' => $mid, 'duplicate' => false, 'balance_after' => $new_stock];
    } catch (Throwable $e) {
        if (!$committed) tx_release($outer, false);
        throw $e;
    }
}

/** Invariant check: product stock == Σ movements (used by verification + reports). */
function stock_invariant_ok(int $product_id): bool {
    $stock = (int) dbval("SELECT stock FROM qa_products WHERE id = ?", [$product_id]);
    $sum   = (int) dbval("SELECT COALESCE(SUM(delta),0) FROM qa_stock_movements WHERE product_id = ?", [$product_id]);
    return $stock === $sum;
}

/* ------------------------------------------------------------------ *
 *  quiz_refund_rewards() — D16 helper (fee row reversed separately)
 * ------------------------------------------------------------------ *
 * Locked D16 behaviour for a refunded attempt: the quiz fee ledger row is
 * negated by a quiz_refund row (caller writes it via ledger_write); the
 * company-funded reward expense row is flipped to 'reversed' and every reward
 * wallet transaction of the attempt is set to status='reversed' — net reward
 * effect zero, no double-spending, one transaction, audited.
 */
function quiz_refund_rewards(int $attempt_id, string $reason): int {
    acc_assert_active();
    $outer = tx_guard(); $committed = false;
    try {
        // Reward ledger row of the attempt (expense, company-funded rewards).
        $reward_row = dbrow(
            "SELECT id, status FROM qa_company_ledger
              WHERE source_type = 'quiz' AND class = 'expense' AND source_id = ? AND status <> 'reversed'
              ORDER BY id DESC LIMIT 1", [$attempt_id]);
        $reward_row_id = $reward_row ? (int) $reward_row['id'] : null;
        if ($reward_row_id !== null) {
            dbq("UPDATE qa_company_ledger SET status = 'reversed' WHERE id = ?", [$reward_row_id]);
        }

        // Reward wallet transactions of this attempt → status 'reversed' (net zero).
        dbq("UPDATE qa_wallet_transactions
              SET status = 'reversed'
              WHERE source_type = 'quiz' AND source_id = ? AND type = 'credit' AND status = 'completed'
                AND category IN ('completion_bonus','passing_reward','rank_reward')", [$attempt_id]);

        acc_audit('quiz_refund_rewards', 'qa_quiz_attempts', $attempt_id,
            ['reward_row' => $reward_row_id], ['reward_row' => 'reversed', 'wallet' => 'reversed'], $reason,
            "quiz:attempt:{$attempt_id}");
        tx_release($outer, true); $committed = true;
        return (int) $reward_row_id;
    } catch (Throwable $e) {
        if (!$committed) tx_release($outer, false);
        throw $e;
    }
}
