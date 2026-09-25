# QuizArena — MySQL/MariaDB Staging Drill (Phase B sign-off)

These scripts run the **real** `app/accounting.php` + `app/wallet.php` against a
**real** MySQL/MariaDB InnoDB database with **genuinely concurrent PHP
processes**. No shims, no translation — this is the verification that cannot be
performed in the development sandbox.

## Preconditions

- PHP ≥ 8.0 CLI with `pdo_mysql` (Hostinger staging or any MySQL host with SSH).
- A **disposable** staging database (the `--reset` run DROPS and recreates the
  tables listed in `staging/staging_bootstrap.php`).
- This directory is web-blocked by the root `.htaccess` (`RewriteRule ^staging/ - [F,L]`).

## Run

```bash
cd <repo root>
export QA_STAGING_DB_HOST=127.0.0.1
export QA_STAGING_DB_PORT=3306
export QA_STAGING_DB_NAME=quizarena_staging
export QA_STAGING_DB_USER=<staging user>
export QA_STAGING_DB_PASS=<staging password>
export QA_STAGING_ALLOW_SCHEMA=1     # first run only — allows table creation/reset

php staging/concurrency_staging.php --reset     # first run: fresh tables
php staging/concurrency_staging.php             # repeat runs (fresh state each time)
```

(`--reset` recreates the scenario tables; subsequent runs also reset unless
`QA_STAGING_ALLOW_SCHEMA` is unset, in which case the script refuses to touch
the schema and expects an already-migrated staging database.)

## Scenarios and EXACT expected results

| Scenario | Setup | Expected outcomes | Expected state afterwards |
|---|---|---|---|
| **A** same key + same payload | 2 parallel `ledger_write`, key `order:9001`, gross 500.00, commission 10.00 | one returns `created`, one returns `duplicate` | ledger rows **1**, wallet txns **1**, seller balance **10**, collision audit **0** |
| **B** same key + different payload | 2 parallel `ledger_write`, key `order:9002`, gross 500.00 vs 700.00 | one returns `created`, one returns **`collision`** (hard error) | ledger rows **1**, wallet txns **1** (winner only), collision audit **1**, balance **10** |
| **C** competing wallet debits | balance 100 coins; 2 parallel debits of 100 | exactly **one** succeeds; loser gets `Insufficient Quiz Coin balance.` | final balance **0**, never negative; completed txns for the user **2** (1 credit + 1 debit) |
| **D** overlapping transactions | 2 parallel `ledger_write`, different keys `order:9003`/`order:9004`, same beneficiary wallet | both `created` | each: ledger rows **1**, wallet txns **1**; beneficiary balance **30** total (A + D commissions, no lost update) |

The script prints `PASS`/`FAIL` per expectation and exits non-zero on any
mismatch. Attach the full output to the Phase B sign-off record.

## What this proves (and what it doesn't)

- **Proves on real InnoDB:** UNIQUE-key arbitration under true concurrency,
  `FOR UPDATE` row locking on wallets (no lost updates, no double-spend),
  `AccIdempotencyCollision` behaviour, collision-audit persistence across the
  loser's rollback, error 1062 classification (`acc_is_dup_key`), ENUM
  category enforcement, single-row single-payout economics.
- **Still separate gates:** the Phase A migration runner drill itself
  (`php upgrade/index.php --to=38 …` on a copy of the production data), and
  full-site regression on staging after activation.
