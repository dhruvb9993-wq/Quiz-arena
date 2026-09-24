<?php
/**
 * QuizArena — Phase A upgrade: the locked 39-step migration registry.
 *
 * Every operation is a SEPARATE checkpointed step (never combined ALTERs).
 * op-id conventions (also used as checkpoint keys via 'upg_' . md5(op)):
 *   create:<table>          steps 1–20
 *   enum:<table>.<column>   steps 21, 22, 30
 *   col:<table>.<column>    steps 23, 25, 27, 28, 29, 31–34
 *   uidx:<table>.<index> / idx:<table>.<index>   steps 24, 26, 35
 *   seed / backfill / verify / activate          steps 36–39
 *
 * Legacy ENUM lists below MUST match app/schema.php exactly — the Phase A
 * validation harness verifies this on every change, and guard_enum_append()
 * re-verifies against the LIVE database at execution time (abort on mismatch).
 */

if (!defined('QA_RUNNING')) { exit('Direct access denied'); }

$schema = require __DIR__ . '/schema.php';

$create_step = function (int $n, string $table, string $desc) use ($schema): array {
    return [
        'n' => $n, 'id' => "create:{$table}", 'kind' => 'create',
        'table' => $table, 'sql' => $schema[$table]['sql'], 'cols' => $schema[$table]['cols'],
        'desc' => $desc,
    ];
};

$col_step = function (int $n, string $table, string $column, string $definition,
                      string $expected_type, bool $nullable, ?string $default,
                      string $desc): array {
    return [
        'n' => $n, 'id' => "col:{$table}.{$column}", 'kind' => 'col',
        'table' => $table, 'column' => $column, 'definition' => $definition,
        'expected_type' => $expected_type, 'nullable' => $nullable, 'default' => $default,
        'desc' => $desc,
    ];
};

return [

    /* ---------- 1–20: CREATE the 20 new tables (dependency order) ---------- */
    $create_step(1,  'qa_regions',             'Regions hierarchy: states, districts, cities (root sentinel parent_id = 0)'),
    $create_step(2,  'qa_network',             'Network members: salesperson / city / district / state roles per region'),
    $create_step(3,  'qa_referrals',           'Referral graph (one row per referred user; referrer_region_id snapshot)'),
    $create_step(4,  'qa_referral_events',     'Referral event log: registered / rewarded / reversed'),
    $create_step(5,  'qa_withdrawals',         'Coin withdrawal requests (coin value + INR stamped at request time)'),
    $create_step(6,  'qa_commission_rules',    'Rule-based commission configuration (percent basis-points or flat paise)'),
    $create_step(7,  'qa_commissions',         'Commission payout rows (purchase / offline_sale only; payout_key unique)'),
    $create_step(8,  'qa_company_ledger',      'Append-only company ledger (12 source types; V1+V3 identity columns)'),
    $create_step(9,  'qa_product_categories',  'Product categories'),
    $create_step(10, 'qa_products',            'Products (integer-paise pricing; stock mirrored by movements)'),
    $create_step(11, 'qa_shops',               'Seller shops (one active shop per seller)'),
    $create_step(12, 'qa_orders',              'Online orders (coins-only payment this phase)'),
    $create_step(13, 'qa_order_items',         'Order line items (partial-refund aware: refunded_qty)'),
    $create_step(14, 'qa_offline_sales',       'Offline POS sales (cash records; invoice unique per seller)'),
    $create_step(15, 'qa_offline_sale_items',  'Offline sale line items'),
    $create_step(16, 'qa_stock_movements',     'Append-only stock movements (9 types; idempotency_key unique)'),
    $create_step(17, 'qa_question_imports',    'Excel question-import batches (green-cell validated imports)'),
    $create_step(18, 'qa_question_import_rows','Excel import rows (validate first, commit later)'),
    $create_step(19, 'qa_audit_log',           'Structured audit log (actor, old, new, reason, IP, UA, source ref)'),
    $create_step(20, 'qa_reg_attempts',        'Registration attempt throttle (referral-code abuse control)'),

    /* ---------- 21–22: append-only ENUM extensions on qa_users ---------- */
    [
        'n' => 21, 'id' => 'enum:qa_users.role', 'kind' => 'enum',
        'table' => 'qa_users', 'column' => 'role',
        'legacy' => ['superadmin', 'school_admin', 'teacher', 'student'],
        'append' => ['general', 'seller', 'salesperson', 'city_head', 'district_head', 'state_head'],
        'default' => 'student',
        'desc' => 'qa_users.role: append 6 new roles after the 4 legacy values (order preserved; default student kept)',
    ],
    [
        'n' => 22, 'id' => 'enum:qa_users.status', 'kind' => 'enum',
        'table' => 'qa_users', 'column' => 'status',
        'legacy' => ['active', 'suspended', 'pending'],
        'append' => ['inactive', 'exited'],
        'default' => 'active',
        'desc' => "qa_users.status: append 'inactive','exited' after the 3 legacy values (default active kept)",
    ],

    /* ---------- 23–26: referral columns + indexes on qa_users ---------- */
    $col_step(23, 'qa_users', 'referral_code', 'VARCHAR(20) NULL',
        'VARCHAR(20)', true, null,
        'qa_users.referral_code VARCHAR(20) NULL — 8-char code, assigned in step 37 backfill / at registration'),
    [
        'n' => 24, 'id' => 'uidx:qa_users.uq_referral_code', 'kind' => 'index',
        'table' => 'qa_users', 'name' => 'uq_referral_code', 'cols' => '(referral_code)', 'unique' => true,
        'desc' => 'UNIQUE index uq_referral_code (nullable column: MySQL UNIQUE permits multiple NULLs; duplicate pre-check before any repair)',
    ],
    $col_step(25, 'qa_users', 'referred_by', 'INT UNSIGNED NULL',
        'INT UNSIGNED', true, null,
        'qa_users.referred_by INT UNSIGNED NULL — referrer user id (NULL for grandfathered users; never auto-assigned)'),
    [
        'n' => 26, 'id' => 'idx:qa_users.idx_referred_by', 'kind' => 'index',
        'table' => 'qa_users', 'name' => 'idx_referred_by', 'cols' => '(referred_by)', 'unique' => false,
        'desc' => 'Non-unique index idx_referred_by (referred_by)',
    ],

    /* ---------- 27–28: quiz audience + funding flag ---------- */
    $col_step(27, 'qa_quizzes', 'audience', "ENUM('school','general','both') NOT NULL DEFAULT 'school'",
        "ENUM('school','general','both')", false, 'school',
        "qa_quizzes.audience — DEFAULT 'school' preserves all existing quizzes' behaviour exactly"),
    $col_step(28, 'qa_quizzes', 'rewards_company_funded', 'TINYINT(1) NOT NULL DEFAULT 0',
        'TINYINT(1)', false, '0',
        'qa_quizzes.rewards_company_funded — 0 keeps existing self-funded coin behaviour; company funding only when flag on'),

    /* ---------- 29: wallet status ---------- */
    $col_step(29, 'qa_wallets', 'status', "ENUM('active','blocked') NOT NULL DEFAULT 'active'",
        "ENUM('active','blocked')", false, 'active',
        "qa_wallets.status — DEFAULT 'active'; all existing wallets behave unchanged"),

    /* ---------- 30: append-only wallet category ENUM (11 legacy + 12 new = 23) ---------- */
    [
        'n' => 30, 'id' => 'enum:qa_wallet_transactions.category', 'kind' => 'enum',
        'table' => 'qa_wallet_transactions', 'column' => 'category',
        'legacy' => ['signup_bonus', 'admin_add', 'admin_deduct', 'entry_fee', 'refund',
                     'completion_bonus', 'passing_reward', 'rank_reward',
                     'transfer_sent', 'transfer_received', 'quiz_reward'],
        'append' => ['purchase', 'purchase_reward', 'purchase_refund',
                     'commission_seller', 'commission_salesperson', 'commission_city',
                     'commission_district', 'commission_state', 'commission_referral',
                     'commission_reversal', 'withdrawal', 'withdrawal_reversal'],
        'default' => null,
        'desc' => 'qa_wallet_transactions.category: append 12 new categories in locked order (11 legacy preserved verbatim; no default)',
    ],

    /* ---------- 31–35: stamped INR value + source linkage on wallet transactions ---------- */
    $col_step(31, 'qa_wallet_transactions', 'coin_value', 'INT NULL',
        'INT', true, null,
        'qa_wallet_transactions.coin_value INT NULL — paise-per-coin stamped on new rows; NULL = legacy row'),
    $col_step(32, 'qa_wallet_transactions', 'amount_inr', 'BIGINT NULL',
        'BIGINT', true, null,
        'qa_wallet_transactions.amount_inr BIGINT NULL — integer paise mirror of the txn; NULL = legacy row'),
    $col_step(33, 'qa_wallet_transactions', 'source_type', 'VARCHAR(40) NULL',
        'VARCHAR(40)', true, null,
        'qa_wallet_transactions.source_type — SOURCE_MATRIX source type for audit linkage'),
    $col_step(34, 'qa_wallet_transactions', 'source_id', 'BIGINT UNSIGNED NULL',
        'BIGINT UNSIGNED', true, null,
        'qa_wallet_transactions.source_id — source record id for audit linkage'),
    [
        'n' => 35, 'id' => 'idx:qa_wallet_transactions.idx_source', 'kind' => 'index',
        'table' => 'qa_wallet_transactions', 'name' => 'idx_source', 'cols' => '(source_type, source_id)', 'unique' => false,
        'desc' => 'Non-unique index idx_source (source_type, source_id)',
    ],

    /* ---------- 36: seed settings (INSERT IGNORE — never overwrites) ---------- */
    [
        'n' => 36, 'id' => 'seed', 'kind' => 'handler', 'handler' => 'seed',
        'desc' => 'Seed settings (transactional, INSERT IGNORE): coin_value_paise, withdrawal min/max, commission cap, 9 feat_* flags = OFF, schema_version = 1',
    ],

    /* ---------- 37: referral-code backfill (chunked, resumable, collision-retry) ---------- */
    [
        'n' => 37, 'id' => 'backfill', 'kind' => 'handler', 'handler' => 'backfill',
        'desc' => 'Backfill referral_code for all users (chunked, unique-code collision retry; referred_by untouched — no auto-assigned referrers)',
    ],

    /* ---------- 38: verification gate ---------- */
    [
        'n' => 38, 'id' => 'verify', 'kind' => 'handler', 'handler' => 'verify',
        'desc' => 'Verification suite: 4 backfill gates + 20 table shapes + exact ENUM lists + new columns + indexes + seeds (all must pass)',
    ],

    /* ---------- 39: ACTIVATION (gated; only step that enables features) ---------- */
    [
        'n' => 39, 'id' => 'activate', 'kind' => 'handler', 'handler' => 'activate',
        'desc' => 'ACTIVATION (requires step 38 passed + typed confirmation + reason): feat_* flags ON + schema_version = 2 + audit row, one transaction',
    ],
];
