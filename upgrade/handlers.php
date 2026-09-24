<?php
/**
 * QuizArena — Phase A upgrade: procedural step handlers (36–39).
 *
 * 36 seed     — transactional INSERT IGNORE of new settings (never overwrites).
 * 37 backfill — chunked referral_code backfill with unique-collision retry;
 *               referred_by is NEVER written (no auto-assigned referrers).
 * 38 verify   — the mandatory verification gate; every check must pass.
 * 39 activate — the ONLY feature-enabling step: one transaction, gated.
 */

if (!defined('QA_RUNNING')) { exit('Direct access denied'); }

/* ------------------------------------------------------------------ *
 *  Locked constants
 * ------------------------------------------------------------------ */

const UPG_REFERRAL_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // 31 chars, no I/L/O/0/1
const UPG_REFERRAL_REGEX    = '^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$';

const UPG_FEAT_FLAGS = [
    'feat_network', 'feat_referrals', 'feat_products_orders', 'feat_offline_sales',
    'feat_commissions', 'feat_withdrawals', 'feat_quiz_funding', 'feat_excel_import',
    'feat_audit_log',
];

const UPG_SEED_SETTINGS = [
    'schema_version'            => '1',     // INSERT IGNORE: absent-only; activation sets it to 2
    'coin_value_paise'          => '100',   // ₹1.00 per coin (SA-configurable later; stamped historically per txn)
    'withdrawal_min_coins'      => '500',
    'withdrawal_max_coins'      => '100000',
    'commission_cap_percent_bp' => '4000',  // 40.00% cap on the UNROUNDED sum (basis points, zero floats)
];

/** Get a raw setting straight from the DB (bypasses the app's static cache). */
function upg_raw_setting(PDO $p, string $k): ?string {
    $st = $p->prepare("SELECT setting_value FROM qa_settings WHERE setting_key = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/* ------------------------------------------------------------------ *
 *  Step 36 — seeds
 * ------------------------------------------------------------------ */

function upg_handler_seed(PDO $p): array {
    $log = [];
    db_begin();
    try {
        $st = $p->prepare("INSERT IGNORE INTO qa_settings (setting_key, setting_value, updated_at)
                           VALUES (?, ?, NOW())");
        foreach (UPG_SEED_SETTINGS as $k => $v) {
            $st->execute([$k, $v]);
            $log[] = "setting {$k} = {$v}" . ($st->rowCount() ? '' : ' (already present, left untouched)');
        }
        foreach (UPG_FEAT_FLAGS as $flag) {
            $st->execute([$flag, '0']);                 // feature flags OFF until step 39
            $log[] = "setting {$flag} = 0 (feature OFF)" . ($st->rowCount() ? '' : ' (already present, left untouched)');
        }
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return $log;
}

/* ------------------------------------------------------------------ *
 *  Step 37 — referral code backfill
 * ------------------------------------------------------------------ */

function upg_random_referral_code(): string {
    $n = strlen(UPG_REFERRAL_ALPHABET);                 // 31
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= UPG_REFERRAL_ALPHABET[random_int(0, $n - 1)];
    }
    return $code;
}

function upg_handler_backfill(PDO $p): array {
    $log = [];

    // Baseline user count (recorded once; INSERT IGNORE keeps the original on re-runs)
    $st = $p->prepare("INSERT IGNORE INTO qa_settings (setting_key, setting_value, updated_at)
                        VALUES ('upg_baseline_users', ?, NOW())");
    $st->execute([(string) dbcount('qa_users')]);

    $assigned = 0; $retries = 0;
    while (true) {
        $rows = dball("SELECT id FROM qa_users WHERE referral_code IS NULL ORDER BY id LIMIT 200");
        if (!$rows) break;
        foreach ($rows as $row) {
            $ok = false;
            for ($attempt = 0; $attempt < 5 && !$ok; $attempt++) {
                try {
                    $u = dbq("UPDATE qa_users SET referral_code = ? WHERE id = ? AND referral_code IS NULL",
                        [upg_random_referral_code(), (int) $row['id']]);
                    $ok = true;
                    if ($attempt > 0) $retries++;
                } catch (PDOException $e) {
                    // 23000 = duplicate key (uq_referral_code collision) → draw a new code and retry
                    if (($e->getCode() ?? '') !== '23000') throw $e;
                }
            }
            if (!$ok) {
                throw new UpgStepException("Could not assign a unique referral_code to user #{$row['id']} after 5 attempts.");
            }
            $assigned++;
        }
    }

    $total    = dbcount('qa_users');
    $withcode = dbcount('qa_users', 'referral_code IS NOT NULL');
    $nullcode = dbcount('qa_users', 'referral_code IS NULL');
    $norefill = dbcount('qa_users', 'referred_by IS NULL');
    $log[] = "backfill complete: assigned={$assigned}, collision_retries={$retries}";
    $log[] = "users total={$total}, with code={$withcode}, NULL code={$nullcode}";
    $log[] = "grandfathered report: referred_by NULL = {$norefill} (no referrer was auto-assigned)";
    if ($nullcode > 0) {
        throw new UpgStepException("Backfill finished but {$nullcode} users still have NULL referral_code.");
    }
    return $log;
}

/* ------------------------------------------------------------------ *
 *  Step 38 — verification gate
 * ------------------------------------------------------------------ */

/**
 * @return array<int,array{label:string, ok:bool, detail:string}>  Throws UpgStepException when any check fails.
 */
function upg_handler_verify(PDO $p): array {
    $checks = [];
    $fail = function (string $label, string $detail) use (&$checks): void {
        $checks[] = ['label' => $label, 'ok' => false, 'detail' => $detail];
    };

    // ---- 4 backfill gates (locked step-38 criteria) ----
    $nullcode = dbcount('qa_users', 'referral_code IS NULL');
    $checks[] = ['label' => '0 users with NULL referral_code', 'ok' => $nullcode === 0,
                 'detail' => "NULL codes: {$nullcode}"];

    $dups = (int) dbval("SELECT COUNT(*) FROM (
                            SELECT referral_code FROM qa_users
                             WHERE referral_code IS NOT NULL
                             GROUP BY referral_code HAVING COUNT(*) > 1) x");
    $checks[] = ['label' => '0 duplicate referral codes', 'ok' => $dups === 0,
                 'detail' => "duplicate codes: {$dups}"];

    $badfmt = dbcount('qa_users', "referral_code IS NULL OR referral_code NOT REGEXP '" . UPG_REFERRAL_REGEX . "'");
    $checks[] = ['label' => 'all codes match ' . UPG_REFERRAL_REGEX, 'ok' => $badfmt === 0,
                 'detail' => "mismatches: {$badfmt}"];

    $total     = dbcount('qa_users');
    $baseline  = (int) (upg_raw_setting($p, 'upg_baseline_users') ?? $total);
    $norefill  = dbcount('qa_users', 'referred_by IS NULL');
    $checks[] = ['label' => 'user count unchanged since baseline (no auto-assigned referrers)',
                 'ok' => $total === $baseline,
                 'detail' => "baseline={$baseline}, now={$total}; referred_by NULL = {$norefill} (grandfathered)"];

    // ---- 20 new tables exist with EXACT locked shape ----
    $schema = require __DIR__ . '/schema.php';
    foreach ($schema as $table => $def) {
        $okShape = verify_table_shape($p, $table, $def['cols']);
        $checks[] = ['label' => "table {$table}: shape exact (" . count($def['cols']) . " columns)",
                     'ok' => $okShape, 'detail' => $okShape ? 'verified live' : 'MISMATCH / missing'];
    }

    // ---- ENUM lists exact (live parse vs locked combined list) ----
    $enumExpect = [
        ['qa_users', 'role', ['superadmin', 'school_admin', 'teacher', 'student',
                              'general', 'seller', 'salesperson', 'city_head', 'district_head', 'state_head']],
        ['qa_users', 'status', ['active', 'suspended', 'pending', 'inactive', 'exited']],
        ['qa_wallet_transactions', 'category', ['signup_bonus', 'admin_add', 'admin_deduct', 'entry_fee', 'refund',
                                                'completion_bonus', 'passing_reward', 'rank_reward',
                                                'transfer_sent', 'transfer_received', 'quiz_reward',
                                                'purchase', 'purchase_reward', 'purchase_refund',
                                                'commission_seller', 'commission_salesperson', 'commission_city',
                                                'commission_district', 'commission_state', 'commission_referral',
                                                'commission_reversal', 'withdrawal', 'withdrawal_reversal']],
    ];
    foreach ($enumExpect as [$t, $c, $want]) {
        $live = enum_values($p, $t, $c);
        $checks[] = ['label' => "ENUM {$t}.{$c}: exact values + order (" . count($want) . ")",
                     'ok' => $live === $want,
                     'detail' => $live === $want ? 'verified live' : 'live: [' . implode(',', $live ?? ['<none>']) . ']'];
    }

    // ---- new columns present with locked definitions ----
    $colExpect = [
        ['qa_users', 'referral_code', 'VARCHAR(20)', true, null],
        ['qa_users', 'referred_by', 'INT UNSIGNED', true, null],
        ['qa_quizzes', 'audience', "ENUM('school','general','both')", false, 'school'],
        ['qa_quizzes', 'rewards_company_funded', 'TINYINT(1)', false, '0'],
        ['qa_wallets', 'status', "ENUM('active','blocked')", false, 'active'],
        ['qa_wallet_transactions', 'coin_value', 'INT', true, null],
        ['qa_wallet_transactions', 'amount_inr', 'BIGINT', true, null],
        ['qa_wallet_transactions', 'source_type', 'VARCHAR(40)', true, null],
        ['qa_wallet_transactions', 'source_id', 'BIGINT UNSIGNED', true, null],
    ];
    foreach ($colExpect as [$t, $c, $type, $nullable, $def]) {
        $ok = verify_column_def(column_lookup($p, $t, $c), $type, $nullable, $def);
        $checks[] = ['label' => "column {$t}.{$c} {$type}" . ($nullable ? ' NULL' : ' NOT NULL') . ($def !== null ? " DEFAULT {$def}" : ''),
                     'ok' => $ok, 'detail' => $ok ? 'verified live' : 'MISMATCH / missing'];
    }

    // ---- indexes: name + NON_UNIQUE + exact ordered columns ----
    $idxExpect = [
        ['qa_users', 'uq_referral_code', '(referral_code)', true],
        ['qa_users', 'idx_referred_by', '(referred_by)', false],
        ['qa_wallet_transactions', 'idx_source', '(source_type, source_id)', false],
    ];
    foreach ($idxExpect as [$t, $k, $cols, $uniq]) {
        $live = index_lookup($p, $t, $k);
        $ok = $live !== null && verify_index_def((int) $live[0], (string) $live[1], $cols, $uniq);
        $checks[] = ['label' => "index {$t}.{$k} (" . ($uniq ? 'UNIQUE' : 'KEY') . ') on ' . $cols,
                     'ok' => $ok, 'detail' => $ok ? 'verified live' : 'MISMATCH / missing'];
    }

    // ---- seeds present ----
    foreach (array_merge(UPG_SEED_SETTINGS, array_fill_keys(UPG_FEAT_FLAGS, '0')) as $k => $v) {
        $val = upg_raw_setting($p, $k);
        $checks[] = ['label' => "setting {$k} present", 'ok' => $val !== null, 'detail' => "value=" . ($val ?? '<absent>')];
    }

    $failed = array_values(array_filter($checks, fn($c) => !$c['ok']));
    if ($failed) {
        $lines = implode("\n", array_map(fn($c) => " - {$c['label']}: {$c['detail']}", $failed));
        throw new UpgStepException("Verification FAILED (" . count($failed) . "/" . count($checks) . " checks failed):\n{$lines}");
    }
    return $checks;
}

/* ------------------------------------------------------------------ *
 *  Step 39 — ACTIVATION (the only feature-enabling step)
 * ------------------------------------------------------------------ */

/**
 * @param int    $actorId superadmin user id
 * @param string $reason  mandatory activation reason (captured by the runner)
 */
function upg_handler_activate(PDO $p, int $actorId, string $reason): array {
    $log = [];

    if (upg_raw_setting($p, 'schema_version') === '2') {
        return ['already activated: schema_version = 2 — no-op success'];
    }

    // Re-run the critical backfill gates immediately before enabling anything.
    if (dbcount('qa_users', 'referral_code IS NULL') !== 0) {
        throw new UpgStepException('Activation blocked: users with NULL referral_code exist.');
    }
    if (dbcount('qa_users', "referral_code IS NULL OR referral_code NOT REGEXP '" . UPG_REFERRAL_REGEX . "'") !== 0) {
        throw new UpgStepException('Activation blocked: malformed referral codes present.');
    }

    $old = [];
    foreach (UPG_FEAT_FLAGS as $flag) { $old[$flag] = upg_raw_setting($p, $flag); }
    $oldVersion = upg_raw_setting($p, 'schema_version');

    db_begin();
    try {
        $upd = $p->prepare("UPDATE qa_settings SET setting_value = '1', updated_at = NOW() WHERE setting_key = ?");
        foreach (UPG_FEAT_FLAGS as $flag) {
            $upd->execute([$flag]);
            $log[] = "{$flag} -> 1";
        }
        $p->exec("UPDATE qa_settings SET setting_value = '2', updated_at = NOW()
                   WHERE setting_key = 'schema_version'");
        if ($p->exec("INSERT IGNORE INTO qa_settings (setting_key, setting_value, updated_at)
                      VALUES ('schema_version', '2', NOW())") === 0) {
            $log[] = 'schema_version -> 2';
        }
        $p->prepare("INSERT INTO qa_audit_log (actor_user_id, action, entity_type, entity_id,
                        old_value, new_value, reason, ip_address, user_agent, source_ref, created_at)
                     VALUES (?, 'phase_a_activation', 'system', NULL, ?, ?, ?, ?, ?, 'upgrade/index.php', NOW())")
           ->execute([
               $actorId,
               json_encode(['flags' => $old, 'schema_version' => $oldVersion]),
               json_encode(['flags' => array_fill_keys(UPG_FEAT_FLAGS, '1'), 'schema_version' => '2']),
               $reason,
               req_ip(),
               substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
           ]);
        $log[] = 'audit row written to qa_audit_log';
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return $log;
}
