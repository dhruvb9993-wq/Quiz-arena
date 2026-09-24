<?php
/**
 * QuizArena — Phase A upgrade: guarded DDL helpers.
 *
 * Implements the locked V3.3 migration rules:
 *  - Every operation is guarded individually and checkpointed in qa_settings
 *    (checkpoint keys: 'upg_' . md5(op-id)). A checkpoint is NEVER trusted
 *    alone: the live object is re-verified before a step is skipped.
 *  - ENUM appends are append-only and order-preserving; any unexpected
 *    existing value aborts BEFORE the column is modified.
 *  - Index guards distinguish UNIQUE (uq_*) from non-UNIQUE (idx_*), never
 *    spread a NULL lookup result, run a NULL-aware duplicate pre-check before
 *    any UNIQUE repair, and always post-verify.
 *  - All SQL identifiers used in generated statements are validated bare
 *    identifiers and backtick-quoted. No caller-controlled raw identifiers.
 *
 * This file only DEFINES behaviour. It is executed exclusively through
 * upgrade/index.php (superadmin + runner key + backup confirmation gate).
 */

if (!defined('QA_RUNNING')) { exit('Direct access denied'); }

/* ------------------------------------------------------------------ *
 *  Checkpoints (qa_settings: 'upg_' + md5(op))
 * ------------------------------------------------------------------ */

function upg_ckpt_key(string $op): string {
    return 'upg_' . md5($op);
}

function ckpt_done(PDO $p, string $op): bool {
    $st = $p->prepare("SELECT COUNT(*) FROM qa_settings WHERE setting_key = ?");
    $st->execute([upg_ckpt_key($op)]);
    return (int) $st->fetchColumn() > 0;
}

function ckpt_set(PDO $p, string $op): void {
    $st = $p->prepare("INSERT IGNORE INTO qa_settings (setting_key, setting_value, updated_at)
                        VALUES (?, '1', NOW())");
    $st->execute([upg_ckpt_key($op)]);
}

function ckpt_clear(PDO $p, string $op): void {
    $st = $p->prepare("DELETE FROM qa_settings WHERE setting_key = ?");
    $st->execute([upg_ckpt_key($op)]);
}

/* ------------------------------------------------------------------ *
 *  Error type carrying step context (id + SQL) for the runner UI
 * ------------------------------------------------------------------ */

class UpgStepException extends RuntimeException {
    public string $stepId;
    public string $stepSql;
    public function __construct(string $message, string $stepId = '', string $stepSql = '') {
        parent::__construct($message);
        $this->stepId = $stepId;
        $this->stepSql = $stepSql;
    }
}

/* ------------------------------------------------------------------ *
 *  Identifier safety
 * ------------------------------------------------------------------ */

/** Accepts ONLY bare SQL identifiers; backtick-quotes them; anything else throws. */
function quote_identifier(string $name): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException("Invalid SQL identifier: {$name}");
    }
    return '`' . $name . '`';
}

/**
 * Normalize an expected index-column expression:
 * trim, strip ONE outer paren pair, split by comma, trim parts, strip backticks.
 * Returns bare column names in the declared order. Validates each name.
 *
 * @return string[]
 */
function normalize_index_columns(string $expected): array {
    $s = trim($expected);
    if ($s !== '' && $s[0] === '(' && substr($s, -1) === ')') {
        $s = trim(substr($s, 1, -1));            // strip exactly one outer paren pair
    }
    $parts = array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== ''));
    if (!$parts) {
        throw new UpgStepException("Empty column list in index definition: {$expected}");
    }
    $out = [];
    foreach ($parts as $c) {
        $bare = trim($c, " \t\n\r`");            // strip surrounding backticks
        quote_identifier($bare);                  // validates bare-identifier shape
        $out[] = $bare;
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 *  Duplicate pre-check (MySQL UNIQUE semantics: multiple NULLs allowed)
 * ------------------------------------------------------------------ */

/**
 * Build safe SQL fragments for the duplicate pre-check.
 * Input: normalize_index_columns() output ONLY (bare, validated, ordered).
 * Defense in depth: every column is verified to exist on the table first.
 *
 * @return array{select_csv:string, where:string}
 */
function build_unique_cols_sql(PDO $p, string $table, array $normalized_cols): array {
    if (!$normalized_cols) {
        throw new UpgStepException('No columns given for unique pre-check.');
    }
    quote_identifier($table);
    $quoted = [];
    foreach ($normalized_cols as $c) {
        $quoted[] = quote_identifier($c);         // rejects non-bare identifiers
    }

    $ph = implode(',', array_fill(0, count($normalized_cols), '?'));
    $st = $p->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                          AND COLUMN_NAME IN ({$ph})");
    $st->execute(array_merge([$table], $normalized_cols));
    if ((int) $st->fetchColumn() !== count($normalized_cols)) {
        throw new UpgStepException("Column mismatch on `{$table}` for unique pre-check.");
    }

    $csv   = implode(',', $quoted);
    $where = implode(' AND ', array_map(fn($q) => $q . ' IS NOT NULL', $quoted));
    return ['select_csv' => $csv, 'where' => $where];
}

/**
 * Count duplicate NON-NULL value groups on the given columns.
 * NULL rows are excluded (MySQL UNIQUE permits multiple NULLs), so the
 * check is never weakened for real non-NULL duplicates but never blocks
 * on NULL-only rows either.
 *
 * @param string[] $normalized_cols output of normalize_index_columns()
 */
function unique_conflict_count(PDO $p, string $t, array $normalized_cols): int {
    $b  = build_unique_cols_sql($p, $t, $normalized_cols);
    $qt = quote_identifier($t);
    $st = $p->query("SELECT COUNT(*) FROM (
            SELECT {$b['select_csv']}
              FROM {$qt}
             WHERE {$b['where']}
             GROUP BY {$b['select_csv']}
            HAVING COUNT(*) > 1
         ) x");
    return (int) $st->fetchColumn();
}

/* ------------------------------------------------------------------ *
 *  Index introspection + verification
 * ------------------------------------------------------------------ */

/**
 * Live index lookup.
 * @return array{0:int,1:string}|null [NON_UNIQUE, GROUP_CONCAT(cols ORDER BY SEQ_IN_INDEX)] or NULL when missing
 */
function index_lookup(PDO $p, string $t, string $k): ?array {
    quote_identifier($t); quote_identifier($k);
    $st = $p->prepare("SELECT NON_UNIQUE, COALESCE(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), '')
                         FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                        GROUP BY NON_UNIQUE");
    $st->execute([$t, $k]);
    $row = $st->fetch(PDO::FETCH_NUM);
    return $row === false ? null : [(int) $row[0], (string) $row[1]];
}

/**
 * Compare a live index definition against the expected one.
 * $non_unique: 0 = UNIQUE, 1 = non-unique (MySQL NON_UNIQUE).
 * $actual_cols: paren-less, ordered GROUP_CONCAT output (or NULL).
 * $expected: raw expected column expression — normalized before compare.
 */
function verify_index_def(?int $non_unique, ?string $actual_cols, string $expected, bool $unique): bool {
    if ($non_unique === null || $actual_cols === null) return false;
    if (($non_unique === 0) !== $unique) return false;                 // uniqueness must match exactly
    $want = implode(',', normalize_index_columns($expected));
    $have = implode(',', array_map(fn($x) => trim($x), explode(',', $actual_cols)));
    return $want === $have;                                            // exact ordered comparison
}

/* ------------------------------------------------------------------ *
 *  guard_add_index — the approved NULL-safe guarded index step
 * ------------------------------------------------------------------ */

/**
 * Guarded index creation/repair.
 *  - Skips only when checkpoint done AND live index verifies.
 *  - NULL $live (index missing) is handled explicitly — never spread.
 *  - Wrong definition → drop+recreate (index-only, no data loss); for UNIQUE
 *    replacements a NULL-aware duplicate pre-check runs first: conflicts >
 *    0 → abort, existing index NOT dropped.
 *  - Always post-verifies before checkpointing.
 */
function guard_add_index(PDO $p, string $t, string $k, string $cols, bool $unique = false): bool {
    $op   = ($unique ? 'uidx:' : 'idx:') . "{$t}.{$k}";
    $live = index_lookup($p, $t, $k);                       // [int, string] or NULL

    // NULL-safe verification: a missing index is simply "not verified".
    $verified = false;
    if ($live !== null) {
        $verified = verify_index_def((int) $live[0], (string) $live[1], $cols, $unique);
    }

    // Skip only when checkpoint is done AND the live object verifies.
    if (ckpt_done($p, $op) && $verified) return false;

    if ($live !== null && !$verified) {                     // exists with wrong definition → repair path
        if ($unique) {
            $cols_csv   = implode(',', normalize_index_columns($cols));
            $conflicts  = unique_conflict_count($p, $t, normalize_index_columns($cols));
            if ($conflicts > 0) {
                throw new UpgStepException(
                    "UNIQUE repair for `{$t}`.`{$k}` aborted: {$conflicts} duplicate non-NULL value group(s) on ({$cols_csv}) " .
                    "conflict with the required UNIQUE constraint. Existing index left untouched — deduplicate the data, then re-run.",
                    $op);
            }
        }
        $p->exec("ALTER TABLE " . quote_identifier($t) . " DROP INDEX " . quote_identifier($k));
    }

    $p->exec("ALTER TABLE " . quote_identifier($t) . " ADD " . ($unique ? 'UNIQUE KEY' : 'KEY')
           . " " . quote_identifier($k) . " ({$cols})");

    $live2 = index_lookup($p, $t, $k);                      // mandatory post-verify
    if ($live2 === null || !verify_index_def((int) $live2[0], (string) $live2[1], $cols, $unique)) {
        throw new UpgStepException("Index post-verify failed: `{$t}`.`{$k}`", $op);
    }
    ckpt_set($p, $op);
    return true;
}

/* ------------------------------------------------------------------ *
 *  Column introspection + guarded ADD COLUMN
 * ------------------------------------------------------------------ */

/**
 * Live column lookup.
 * @return array{column_type:string, is_nullable:string, column_default:?string}|null
 */
function column_lookup(PDO $p, string $t, string $c): ?array {
    $st = $p->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                         FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$t, $c]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    return [
        'column_type'    => strtoupper((string) $row['COLUMN_TYPE']),
        'is_nullable'    => (string) $row['IS_NULLABLE'],
        'column_default' => $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
    ];
}

/**
 * Canonicalise a COLUMN_TYPE for comparison (upper-case, single spaces, drop
 * legacy display widths like INT(11)/BIGINT(20); keep the TINYINT(1) marker).
 */
function norm_coltype(string $t): string {
    $t = strtoupper(preg_replace('/\s+/', ' ', trim($t)));
    if (preg_match('/^(INT|BIGINT|SMALLINT|MEDIUMINT)\(\d+\)$/', $t)) {
        $t = substr($t, 0, strpos($t, '('));      // INT(11) -> INT, BIGINT(20) -> BIGINT, etc.
    }
    return $t;
}

/**
 * Compare a live column definition against the expected one.
 * $expected_type: canonical upper-case COLUMN_TYPE (e.g. 'VARCHAR(20)', 'INT UNSIGNED', 'TINYINT(1)', "ENUM('a','b')").
 * $expected_default: NULL = "no default" (accepts NULL; MariaDB may report '' for some types).
 */
function verify_column_def(?array $live, string $expected_type, bool $nullable, ?string $expected_default): bool {
    if ($live === null) return false;
    if (norm_coltype($live['column_type']) !== norm_coltype($expected_type)) return false;
    if (($live['is_nullable'] === 'YES') !== $nullable) return false;
    if ($expected_default === null) {
        return $live['column_default'] === null || $live['column_default'] === '';
    }
    return $live['column_default'] !== null
        && trim($live['column_default']) === trim($expected_default);
}

/**
 * Guarded ADD COLUMN.
 *  - Skips only when checkpoint done AND live column verifies.
 *  - Column exists with a DIFFERENT definition → ABORT with exact live-vs-
 *    expected detail (conservative: no silent MODIFY on an existing column).
 *  - Always post-verifies before checkpointing.
 */
function guard_add_column(PDO $p, string $t, string $c, string $definition,
                          string $expected_type, bool $nullable, ?string $expected_default): bool {
    $op   = "col:{$t}.{$c}";
    $live = column_lookup($p, $t, $c);

    $verified = $live !== null && verify_column_def($live, $expected_type, $nullable, $expected_default);
    if (ckpt_done($p, $op) && $verified) return false;

    if ($live !== null && !$verified) {
        $got = $live['column_type'] . ($live['is_nullable'] === 'YES' ? ' NULL' : ' NOT NULL')
             . ($live['column_default'] !== null ? ' DEFAULT ' . $live['column_default'] : '');
        throw new UpgStepException(
            "Column `{$t}`.`{$c}` exists with an unexpected definition. Aborting (no change made). " .
            "Live: [{$got}] — Expected: [" . strtoupper($expected_type) . ($nullable ? ' NULL' : ' NOT NULL')
            . ($expected_default !== null ? ' DEFAULT ' . $expected_default : '') . "]",
            $op);
    }

    if ($live === null) {
        quote_identifier($t);
        $p->exec("ALTER TABLE " . quote_identifier($t) . " ADD COLUMN " . quote_identifier($c) . " {$definition}");
    }

    $live2 = column_lookup($p, $t, $c);
    if (!verify_column_def($live2, $expected_type, $nullable, $expected_default)) {
        throw new UpgStepException("Column post-verify failed: `{$t}`.`{$c}`", $op);
    }
    ckpt_set($p, $op);
    return true;
}

/* ------------------------------------------------------------------ *
 *  ENUM introspection + guarded append-only modification
 * ------------------------------------------------------------------ */

/**
 * Parse the live ENUM value list of a column (in stored order).
 * @return string[]|null NULL when the column does not exist or is not an ENUM
 */
function enum_values(PDO $p, string $t, string $c): ?array {
    $live = column_lookup($p, $t, $c);
    if ($live === null || !preg_match('/^ENUM\((.*)\)$/i', $live['column_type'], $m)) return null;
    $inner = trim($m[1]);
    if ($inner === '') return [];
    // Our ENUM values never contain quotes or commas; simple split is exact.
    return array_map(fn($x) => trim(trim($x), "'"), explode(',', $inner));
}

/**
 * Guarded append-only ENUM extension.
 *  - Live values must equal a PREFIX of [legacy..., append...] (in order):
 *      * exactly the legacy list            → fresh install state, append all
 *      * legacy + first k of append         → previous partial run, resume
 *      * anything else (reordered / unknown / missing legacy value) → ABORT
 *        BEFORE any change.
 *  - After ALTER: post-verify exact combined list, then checkpoint.
 */
function guard_enum_append(PDO $p, string $t, string $c, array $legacy, array $append, ?string $default = null): bool {
    $op   = "enum:{$t}.{$c}";
    $live = enum_values($p, $t, $c);
    if ($live === null) {
        throw new UpgStepException("ENUM column `{$t}`.`{$c}` not found — aborting without changes.", $op);
    }

    $expected = array_merge(array_values($legacy), array_values($append));
    $n = count($live);
    if ($n > count($expected) || $live !== array_slice($expected, 0, $n)) {
        throw new UpgStepException(
            "ENUM `{$t}`.`{$c}` does not match the expected legacy prefix. Aborting BEFORE any change.\n" .
            "Live:   [" . implode(',', $live) . "]\n" .
            "Legacy: [" . implode(',', $legacy) . "]\n" .
            "Target: [" . implode(',', $expected) . "]", $op);
    }

    $missing = array_slice($append, $n - count($legacy));
    if ($missing) {
        quote_identifier($t); quote_identifier($c);
        $defs = implode(',', array_map(fn($v) => "'" . $v . "'", $expected));
        // Preserve NOT NULL and the column's DEFAULT (MODIFY would otherwise drop it).
        $p->exec("ALTER TABLE " . quote_identifier($t)
               . " MODIFY " . quote_identifier($c) . " ENUM({$defs}) NOT NULL"
               . ($default !== null ? " DEFAULT '" . $default . "'" : ''));
    }

    $live2 = enum_values($p, $t, $c);
    if ($live2 !== $expected) {
        throw new UpgStepException("ENUM post-verify failed: `{$t}`.`{$c}`", $op);
    }
    ckpt_set($p, $op);
    return $missing !== [];
}

/* ------------------------------------------------------------------ *
 *  Table existence + shape verification
 * ------------------------------------------------------------------ */

function table_exists(PDO $p, string $t): bool {
    $st = $p->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Verify the live table shape (column name → upper COLUMN_TYPE) equals the
 * expected map exactly (no missing, no extra columns).
 *
 * @param array<string,string> $expected_cols e.g. ['id' => 'INT UNSIGNED', ...]
 */
function verify_table_shape(PDO $p, string $t, array $expected_cols): bool {
    if (!table_exists($p, $t)) return false;
    $st = $p->prepare("SELECT COLUMN_NAME, UPPER(COLUMN_TYPE) AS CT
                         FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                        ORDER BY ORDINAL_POSITION");
    $st->execute([$t]);
    $live = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $live[$r['COLUMN_NAME']] = norm_coltype($r['CT']);
    }
    foreach ($expected_cols as $name => $type) {
        if (($live[$name] ?? null) !== norm_coltype($type)) return false;
    }
    return count($live) === count($expected_cols);
}

/* ------------------------------------------------------------------ *
 *  Guarded CREATE TABLE
 * ------------------------------------------------------------------ */

/**
 * Guarded CREATE TABLE IF NOT EXISTS + full shape verification.
 * Skips only when checkpoint done AND live shape matches exactly.
 * Existing table with a different shape → ABORT (never auto-mutate a live table's columns).
 *
 * @param array<string,string> $expected_cols column => COLUMN_TYPE map (from schema.php meta)
 */
function guard_create_table(PDO $p, string $t, string $create_sql, array $expected_cols): void {
    $op = "create:{$t}";
    if (ckpt_done($p, $op) && verify_table_shape($p, $t, $expected_cols)) return;

    if (table_exists($p, $t) && !verify_table_shape($p, $t, $expected_cols)) {
        throw new UpgStepException(
            "Table `{$t}` already exists with a different shape than the Phase A definition. " .
            "Aborting BEFORE any change — reconcile manually, then re-run.", $op);
    }
    $p->exec($create_sql);

    if (!verify_table_shape($p, $t, $expected_cols)) {
        throw new UpgStepException("Table shape post-verify failed: `{$t}`", $op);
    }
    ckpt_set($p, $op);
}
