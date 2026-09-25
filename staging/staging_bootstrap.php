<?php
/**
 * QuizArena — MySQL/MariaDB STAGING bootstrap (NOT part of the product runtime).
 *
 * Connects to a REAL MySQL/MariaDB staging database using environment variables:
 *   QA_STAGING_DB_HOST   (default 127.0.0.1)
 *   QA_STAGING_DB_PORT   (default 3306)
 *   QA_STAGING_DB_NAME   required — use a DISPOSABLE staging database
 *   QA_STAGING_DB_USER   required
 *   QA_STAGING_DB_PASS   required
 *
 * Table creation only happens when QA_STAGING_ALLOW_SCHEMA=1 (guard against
 * pointing this at a live database by accident). The DDL below mirrors the
 * locked Phase A/v3.3 schema for the tables the accounting tests touch.
 *
 * Usage: require __DIR__ . '/staging_bootstrap.php';  from the worker/driver.
 */

if (PHP_SAPI !== 'cli') { exit("staging scripts are CLI-only\n"); }

foreach (['QA_STAGING_DB_NAME', 'QA_STAGING_DB_USER'] as $req) {
    if (!getenv($req)) { fwrite(STDERR, "Missing env var {$req}\n"); exit(1); }
}

$GLOBALS['STG_CONFIG'] = [
    'host' => getenv('QA_STAGING_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('QA_STAGING_DB_PORT') ?: '3306',
    'name' => getenv('QA_STAGING_DB_NAME'),
    'user' => getenv('QA_STAGING_DB_USER'),
    'pass' => getenv('QA_STAGING_DB_PASS'),
];

/* ------------------------------------------------------------------ *
 *  Minimal harness (same shape as the local test harness, but the SQL
 *  goes to MySQL UNTRANSLATED — product SQL is MySQL-native).
 * ------------------------------------------------------------------ */

$GLOBALS['STG_SETTINGS'] = ['schema_version' => '2', 'coin_value_paise' => '100'];

function setting($k, $d = null) {
    return array_key_exists($k, $GLOBALS['STG_SETTINGS']) ? $GLOBALS['STG_SETTINGS'][$k] : $d;
}

function pdo() {
    static $p = null;
    if ($p === null) {
        $c = $GLOBALS['STG_CONFIG'];
        $p = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
    }
    return $p;
}

function dbq($sql, $params = []) { $st = pdo()->prepare($sql); $st->execute($params); return $st; }
function dbrow($sql, $params = []) { return dbq($sql, $params)->fetch(); }
function dball($sql, $params = []) { return dbq($sql, $params)->fetchAll(); }
function dbval($sql, $params = []) { $v = dbq($sql, $params)->fetchColumn(); return $v === false ? null : $v; }
function db_id() { return (int) pdo()->lastInsertId(); }
function db_begin() { pdo()->beginTransaction(); }
function db_commit() { pdo()->commit(); }
function db_rollback() { if (pdo()->inTransaction()) pdo()->rollBack(); }
function req_ip() { return '127.0.0.1'; }
function txn_id() { return 'WC' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3))) . getmypid(); }
function coin_name() { return 'Quiz Coins'; }
function audit($a, $d = '') {}
function notify($u, $t, $m, $l = null, $s = null) {}
function current_user() { return null; }
$_SESSION = ['user_id' => 1];

/** Create (or reset) the minimal staging tables. Guarded by env flag. */
function stg_setup_tables(bool $reset): void {
    if (getenv('QA_STAGING_ALLOW_SCHEMA') !== '1') {
        fwrite(STDERR, "Refusing to touch schema: set QA_STAGING_ALLOW_SCHEMA=1 (use a DISPOSABLE staging DB).\n");
        exit(1);
    }
    if ($reset) {
        foreach (['qa_audit_log','qa_stock_movements','qa_commissions','qa_company_ledger',
                  'qa_wallet_transactions','qa_wallets','qa_products','qa_users','qa_settings'] as $t) {
            pdo()->exec("DROP TABLE IF EXISTS `{$t}`");
        }
    }
    $ddl = [
        "CREATE TABLE IF NOT EXISTS qa_settings (
            setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS qa_users (
            id INT UNSIGNED NOT NULL PRIMARY KEY, school_id INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_wallets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL, school_id INT UNSIGNED NOT NULL DEFAULT 0,
            balance INT NOT NULL DEFAULT 0, status ENUM('active','blocked') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_user (user_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_wallet_transactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(40) NOT NULL, transfer_group_id VARCHAR(40) NULL,
            user_id INT UNSIGNED NOT NULL, wallet_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL DEFAULT 0,
            type ENUM('credit','debit') NOT NULL,
            category ENUM('signup_bonus','admin_add','admin_deduct','entry_fee','refund',
                          'completion_bonus','passing_reward','rank_reward',
                          'transfer_sent','transfer_received','quiz_reward',
                          'purchase','purchase_reward','purchase_refund',
                          'commission_seller','commission_salesperson','commission_city',
                          'commission_district','commission_state','commission_referral',
                          'commission_reversal','withdrawal','withdrawal_reversal') NOT NULL,
            amount INT NOT NULL, balance_after INT NOT NULL,
            sender_user_id INT UNSIGNED NULL, sender_username VARCHAR(50) NULL,
            receiver_user_id INT UNSIGNED NULL, receiver_username VARCHAR(50) NULL,
            reference VARCHAR(100) NULL, description VARCHAR(255) NULL,
            status ENUM('completed','failed','pending','reversed') NOT NULL DEFAULT 'completed',
            coin_value INT NULL, amount_inr BIGINT NULL,
            source_type VARCHAR(40) NULL, source_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_txn (transaction_id),
            KEY idx_reference (reference)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_company_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            idempotency_key VARCHAR(120) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            source_type ENUM('order','offline_sale','quiz','refund','offline_refund','quiz_refund','referral','referral_reversal','signup','withdrawal','withdrawal_reversal','adjustment') NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            class ENUM('revenue','expense') NOT NULL,
            gross BIGINT NOT NULL DEFAULT 0, fees BIGINT NOT NULL DEFAULT 0, taxes BIGINT NOT NULL DEFAULT 0,
            customer_reward BIGINT NOT NULL DEFAULT 0, customer_user_id INT UNSIGNED NULL,
            commission_seller BIGINT NOT NULL DEFAULT 0, seller_user_id INT UNSIGNED NULL,
            commission_salesperson BIGINT NOT NULL DEFAULT 0, salesperson_user_id INT UNSIGNED NULL,
            commission_city BIGINT NOT NULL DEFAULT 0, city_user_id INT UNSIGNED NULL,
            commission_district BIGINT NOT NULL DEFAULT 0, district_user_id INT UNSIGNED NULL,
            commission_state BIGINT NOT NULL DEFAULT 0, state_user_id INT UNSIGNED NULL,
            commission_referral BIGINT NOT NULL DEFAULT 0, referral_user_id INT UNSIGNED NULL,
            withdrawal_amount BIGINT NOT NULL DEFAULT 0, adjustment_amount BIGINT NOT NULL DEFAULT 0,
            company_share BIGINT NOT NULL DEFAULT 0,
            coin_amount INT NOT NULL DEFAULT 0, coin_value INT NOT NULL DEFAULT 0,
            rounding_diff BIGINT NOT NULL DEFAULT 0,
            reversal_of BIGINT UNSIGNED NULL,
            status ENUM('active','partially_reversed','reversed') NOT NULL DEFAULT 'active',
            audit_ref VARCHAR(120) NULL, note VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_ledger_key (idempotency_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_commissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payout_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
            rule_id INT UNSIGNED NOT NULL,
            source_type ENUM('purchase','offline_sale') NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL, line_no INT UNSIGNED NULL,
            beneficiary_user_id INT UNSIGNED NOT NULL,
            beneficiary_role ENUM('seller','salesperson','city_head','district_head','state_head','referral') NOT NULL,
            amount BIGINT NOT NULL DEFAULT 0, coin_amount INT NOT NULL DEFAULT 0, coin_value INT NOT NULL DEFAULT 0,
            reversal_of BIGINT UNSIGNED NULL, status ENUM('active','reversed') NOT NULL DEFAULT 'active',
            hierarchy_snapshot TEXT NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_payout_key (payout_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_stock_movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            type ENUM('purchase_in','sale','offline_sale','refund_in','offline_refund_in','cancel_return','adjust_in','adjust_out','initial') NOT NULL,
            delta INT NOT NULL, balance_after INT NULL,
            ref_type VARCHAR(40) NOT NULL DEFAULT '', ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
            note VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_stock_key (idempotency_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            stock INT NOT NULL DEFAULT 0, status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            updated_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS qa_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT UNSIGNED NOT NULL, action VARCHAR(120) NOT NULL,
            entity_type VARCHAR(60) NOT NULL, entity_id BIGINT UNSIGNED NULL,
            old_value LONGTEXT NULL, new_value LONGTEXT NULL, reason VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL,
            source_ref VARCHAR(120) NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $q) pdo()->exec($q);

    // settings + wallets (fresh scenario state on reset)
    if ($reset) {
        dbq("INSERT INTO qa_settings (setting_key, setting_value) VALUES ('schema_version','2'),('coin_value_paise','100')");
        dbq("INSERT INTO qa_users (id, school_id) VALUES (5,0),(9,0)");
        dbq("INSERT INTO qa_wallets (user_id, school_id, balance, created_at) VALUES (5,0,0,NOW()),(9,0,0,NOW())");
    }
}

/** Load the REAL product accounting + wallet modules. */
function stg_load_product(): void {
    require_once __DIR__ . '/../app/wallet.php';
    require_once __DIR__ . '/../app/accounting.php';
}
