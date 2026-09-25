# QuizArena — Phase A Upgrade Runner

Manual, resumable, stop-on-failure migration runner implementing the locked
v3.3 Phase A schema (39 checkpointed steps). **No step runs by itself** —
everything is executed explicitly through this runner, by a Super Admin,
after a verified backup.

## What it does

- **Steps 1–20** — create the 20 new tables (guarded `CREATE TABLE IF NOT EXISTS`
  + exact live-shape verification against the locked column map).
- **Steps 21–22** — append-only ENUM extensions on `qa_users.role` (4→10 values)
  and `qa_users.status` (3→5). Live values must equal the locked legacy prefix,
  in order, or the step **aborts before changing anything**.
- **Steps 23–26** — `referral_code` column, UNIQUE `uq_referral_code`
  (NULL-aware duplicate pre-check before any repair), `referred_by`, index.
- **Steps 27–28** — `qa_quizzes.audience` (default `school` — existing quizzes
  unchanged) and `rewards_company_funded` (default 0).
- **Step 29** — `qa_wallets.status` (default `active`).
- **Step 30** — append-only wallet `category` ENUM (11 legacy + 12 new = 23).
- **Steps 31–35** — stamped `coin_value` / `amount_inr` / `source_type` /
  `source_id` on wallet transactions + `idx_source`.
- **Step 36** — seed settings (transactional, `INSERT IGNORE`: never overwrites).
  All 9 `feat_*` flags are seeded **OFF**.
- **Step 37** — referral-code backfill (chunked of 200, unique-collision retry,
  resumable; `referred_by` is never written).
- **Step 38** — verification gate: 4 backfill gates + 20 table shapes + exact
  ENUM lists + 9 new columns + 3 indexes + seeds. All must pass.
- **Step 39** — **ACTIVATION** (gated): one transaction — `feat_*` ON,
  `schema_version = 2`, audit row. The only feature-enabling step.

## Safety rules (locked)

- Checkpoints live in `qa_settings` as `upg_` + md5(op-id) and are **never
  trusted alone**: every re-run re-verifies the live object (column type /
  null / default, index name + NON_UNIQUE + ordered columns, ENUM values+order,
  table shape) before skipping.
- Each operation is guarded **individually** — never combined ALTERs.
- DDL is treated as auto-commit: a failure leaves earlier steps checkpointed;
  fix the cause and re-run (completed steps are skipped after re-verification).
- UNIQUE repair runs a duplicate pre-check first (NULL rows excluded, matching
  MySQL semantics); conflicts > 0 ⇒ abort with the existing index untouched.
- All generated SQL identifiers are validated bare identifiers, backtick-quoted.

## How to run (staging first!)

### Web (Hostinger hPanel-friendly)

1. Back up the database (hPanel → Databases → phpMyAdmin → Export) and verify
   the dump file.
2. Sign in as Super Admin, open `/upgrade/`, click **Generate runner key**,
   bookmark the URL it offers.
3. Choose "Run all steps up to **38**", type the backup confirmation phrase
   `I HAVE VERIFIED TODAY'S BACKUP`, run.
4. Fix anything step 38 flags (the runner shows the failing check and SQL),
   re-run until 38/39 steps show *done*.
5. Only at final go-live: run step 39 (typed `Yes` + mandatory reason).

### CLI (staging drills)

```
php upgrade/index.php                       # status board
php upgrade/index.php --to=38 --backup-phrase="I HAVE VERIFIED TODAY'S BACKUP"
php upgrade/index.php --to=39 --backup-phrase="..." --activate --activation-reason="go-live"
```

## Notes

- Large user tables: step 37 commits per row-chunk (200). If PHP times out on a
  huge table, just re-run — it resumes from `WHERE referral_code IS NULL`.
- `verify_table_shape()` compares COLUMN_TYPE exactly (case/display-width
  normalised). Any drift aborts instead of mutating.
- The real MySQL/MariaDB staging drill is **mandatory** before production.

## qa_orders.uq_order_no — business & uniqueness behavior (Phase B documentation)

`uq_order_no` is a UNIQUE index on `qa_orders.order_no VARCHAR(30)`.

- **Business purpose:** `order_no` is the customer-facing order reference
  (shown on the order screen, wallet transaction references, refund records,
  and the company ledger source key `order:{id}` links by the numeric order id,
  while humans reference the order by its `order_no`).
- **Generation:** application-generated at cart confirmation, pattern
  `OR` + `ymdHis` timestamp + 6 random hex chars (same collision-safe
  construction as wallet `transaction_id`s), assigned server-side inside the
  order-creation transaction — never accepted from the browser.
- **Uniqueness guarantee:** the UNIQUE index makes a duplicated order number
  impossible at the storage level, even under concurrent checkouts or a
  manual retry after timeout. A generated collision (astronomically unlikely)
  surfaces as a duplicate-key error; the order flow regenerates and retries
  (same pattern as `txn_id()`'s 5-attempt retry).
- **Idempotency relationship:** order financial writes use the ledger key
  `order:{order_id}`, NOT `order_no` — so a regenerated `order_no` (pre-commit
  retry) never forks ledger history; `order_no` is presentation identity,
  `order_id` is the financial identity.
- **Format invariants enforced in code (order phase):** `^[A-Z0-9]{1,30}$`,
  uppercase, no separators — safe for invoices, QR codes, and CSV exports.
