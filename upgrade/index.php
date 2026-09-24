<?php
/**
 * QuizArena — Phase A upgrade runner (manual, resumable, stop-on-failure).
 *
 * Route: /upgrade/  (Super Admin + runner key; CLI for staging drills)
 *
 * SAFETY GATES
 *  1. Super Admin session + per-deployment runner key (web).
 *  2. CSRF token on every POST.
 *  3. Backup confirmation: the exact phrase below, typed per run.
 *  4. Step 39 (ACTIVATION) additionally requires: step 38 checkpoint done,
 *     the word "Yes" typed, and a written reason (audited).
 *  5. Every step is guarded + checkpointed; re-running is always safe.
 */

if (!defined('QA_UPGRADE')) { exit('Direct access denied'); }

$p = pdo();
$steps = upg_steps();
$states = upg_states($p);

$done_count = count(array_filter($states, fn($s) => $s === 'done'));
$next = null;
foreach ($steps as $s) { if ($states[$s['n']] !== 'done') { $next = $s['n']; break; } }

$run_log = null;      // successful run log
$error  = null;       // ['step_id'=>, 'sql'=>, 'message'=>]
$notice = null;

const UPG_BACKUP_PHRASE = "I HAVE VERIFIED TODAY'S BACKUP";

/* ------------------------------------------------------------------ *
 *  CLI mode (staging drills / SSH)
 * ------------------------------------------------------------------ */
if ($UPG['cli']) {
    $args = getopt('', ['to:', 'backup-phrase:', 'activate', 'activation-reason:']);
    $to = isset($args['to']) ? (int) $args['to'] : 0;
    $phrase = (string) ($args['backup-phrase'] ?? '');
    $activate = array_key_exists('activate', $args);

    if ($activate) { $to = max($to, 39); }

    if ($to < 1 || $to > 39) {
        echo "Usage: php upgrade/index.php --to=N --backup-phrase=\"I HAVE VERIFIED TODAY'S BACKUP\" [--activate --activation-reason=\"…\"]\n";
        echo "       (omit --to to print the current step status)\n\n";
        foreach ($steps as $s) {
            printf("  %2d  %-8s %-45s %s\n", $s['n'], $states[$s['n']], $s['id'], $s['desc']);
        }
        exit(0);
    }
    if (strcasecmp(trim($phrase), UPG_BACKUP_PHRASE) !== 0) {
        fwrite(STDERR, "REFUSED: backup confirmation phrase missing/incorrect. Nothing was executed.\n");
        exit(1);
    }
    if ($to >= 39) {
        if (!ckpt_done($p, 'verify')) {
            fwrite(STDERR, "REFUSED: activation requires the step-38 verification checkpoint.\n");
            exit(1);
        }
        $GLOBALS['UPG_ACTIVATE_CONFIRM'] = 'Yes';
        $GLOBALS['UPG_ACTIVATE_REASON'] = (string) ($args['activation-reason'] ?? '');
        $GLOBALS['UPG_ACTIVATE_ACTOR'] = 0; // CLI operator — filesystem access is the credential
    }
    try {
        $run_log = upg_run_to($p, $to);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($run_log as $l) {
        printf("  %2d  [%s] %s%s\n", $l['n'], strtoupper($l['action']), $l['id'],
               $l['log'] ? '  — ' . implode(' | ', $l['log']) : '');
    }
    echo "OK: steps up to {$to} are checkpointed.\n";
    exit(0);
}

/* ------------------------------------------------------------------ *
 *  Web actions
 * ------------------------------------------------------------------ */
if (is_post() && $UPG['user']) {
    csrf_check();

    if (post('action') === 'key_generate') {
        $new = bin2hex(random_bytes(16));
        save_setting('upg_runner_key', $new);
        audit('upgrade_key_generated', 'Phase A upgrade runner key generated');
        $notice = 'Runner key generated. Bookmark this URL now (it contains the key): '
                . url('upgrade/index.php?key=' . $new);
    } elseif (post('action') === 'run' && $UPG['authed']) {
        $to = (int) post('to', 0);
        $phrase = (string) post('backup_phrase', '');

        if ($to < 1 || $to > 39) {
            $error = ['step_id' => '—', 'sql' => '', 'message' => 'Invalid target step.'];
        } elseif (strcasecmp(trim($phrase), UPG_BACKUP_PHRASE) !== 0) {
            $error = ['step_id' => '—', 'sql' => '', 'message' => 'Backup confirmation phrase missing or incorrect. Nothing was executed.'];
        } elseif ($to >= 39) {
            if (!ckpt_done($p, 'verify')) {
                $error = ['step_id' => 'activate', 'sql' => '', 'message' => 'Activation requires the step-38 verification checkpoint to be done first.'];
            } elseif (trim((string) post('activation_confirm', '')) !== 'Yes') {
                $error = ['step_id' => 'activate', 'sql' => '', 'message' => 'Activation confirmation must be typed exactly as "Yes". Nothing was executed.'];
            } elseif (strlen(trim((string) post('activation_reason', ''))) < 10) {
                $error = ['step_id' => 'activate', 'sql' => '', 'message' => 'A written activation reason (min 10 chars) is required — it is stored in the audit log. Nothing was executed.'];
            }
        }

        if (!$error) {
            if ($to >= 39) {
                $GLOBALS['UPG_ACTIVATE_CONFIRM'] = 'Yes';
                $GLOBALS['UPG_ACTIVATE_REASON'] = trim((string) post('activation_reason', ''));
                $GLOBALS['UPG_ACTIVATE_ACTOR'] = (int) $UPG['user']['id'];
            }
            try {
                $run_log = upg_run_to($p, $to);
            } catch (Throwable $e) {
                $error = [
                    'step_id' => $e instanceof UpgStepException ? ($e->stepId ?: '?') : '?',
                    'sql'     => $e instanceof UpgStepException ? $e->stepSql : '',
                    'message' => $e->getMessage(),
                ];
            }
            $states = upg_states($p);
            $done_count = count(array_filter($states, fn($s) => $s === 'done'));
            $next = null;
            foreach ($steps as $s) { if ($states[$s['n']] !== 'done') { $next = $s['n']; break; } }
        }
    }
}

$site_name = setting('site_name', 'QuizArena');
$all_done = $done_count === count($steps);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Upgrade Runner — <?= e($site_name) ?></title>
<link href="../assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background:#f2f4f8; }
    .runner-card { max-width: 1080px; margin: 2rem auto; }
    .mono { font-family: SFMono-Regular, Menlo, Consolas, monospace; font-size: .82rem; }
</style>
</head>
<body>
<div class="runner-card card shadow-sm">

    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-up-circle me-2"></i>QuizArena — Phase A Upgrade Runner</span>
        <span class="small">signed in: <?= e($UPG['user']['username'] ?? '—') ?></span>
    </div>

    <div class="card-body">

        <?php if (!$UPG['authed']): ?>

            <div class="alert alert-warning"><i class="bi bi-lock me-2"></i><?= e($UPG['reason']) ?></div>

            <?php if ($UPG['user']): ?>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="key_generate">
                    <div class="col-auto">
                        <button class="btn btn-primary"><i class="bi bi-key me-1"></i>Generate runner key</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($UPG['has_key']): ?>
                <form method="get" class="row g-2 align-items-end mt-3">
                    <div class="col-auto">
                        <label class="form-label small mb-0">Runner key</label>
                        <input type="password" name="key" class="form-control form-control-sm" placeholder="paste key">
                    </div>
                    <div class="col-auto"><button class="btn btn-sm btn-secondary">Unlock</button></div>
                </form>
            <?php endif; ?>

        <?php else: ?>

            <?php if ($notice): ?><div class="alert alert-info mono"><?= e($notice) ?></div><?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <div class="fw-bold mb-1"><i class="bi bi-x-octagon me-1"></i>Run stopped on failure</div>
                    <div class="small">failed step: <span class="mono"><?= e($error['step_id']) ?></span></div>
                    <div class="small mt-1" style="white-space:pre-wrap"><?= e($error['message']) ?></div>
                    <?php if ($error['sql']): ?>
                        <div class="mt-2 mono p-2 bg-white border rounded" style="white-space:pre-wrap"><?= e($error['sql']) ?></div>
                    <?php endif; ?>
                    <div class="small mt-2 text-muted">Earlier completed steps remain checkpointed — fix the cause and re-run; completed steps are skipped automatically.</div>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-primary">schema_version: <?= e(upg_raw_setting($p, 'schema_version') ?? 'unset') ?></span>
                <span class="badge bg-secondary">coin_value_paise: <?= e(upg_raw_setting($p, 'coin_value_paise') ?? 'unset') ?></span>
                <span class="badge bg-secondary">withdrawal min/max: <?= e(upg_raw_setting($p, 'withdrawal_min_coins') ?? '—') ?> / <?= e(upg_raw_setting($p, 'withdrawal_max_coins') ?? '—') ?></span>
                <span class="badge bg-success">steps done: <?= $done_count ?>/<?= count($steps) ?></span>
                <?php if ($all_done): ?><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Phase A schema ready</span>
                <?php else: ?><span class="badge bg-warning text-dark">next pending: step <?= $next ?></span><?php endif; ?>
            </div>

            <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                <table class="table table-sm table-hover align-middle">
                    <thead class="table-light">
                        <tr><th>#</th><th>state</th><th>op id</th><th>description</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($steps as $s): ?>
                            <tr>
                                <td><?= $s['n'] ?></td>
                                <td>
                                    <?php if ($states[$s['n']] === 'done'): ?>
                                        <span class="badge bg-success">done</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?= e($s['id']) ?></td>
                                <td class="small"><?= e($s['desc']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($run_log): ?>
                <div class="alert alert-success mt-3 mb-0">
                    <div class="fw-bold mb-1"><i class="bi bi-check2-circle me-1"></i>Run log</div>
                    <ul class="small mb-0 mono" style="max-height:220px; overflow-y:auto;">
                        <?php foreach ($run_log as $l): ?>
                            <li>[<?= e(strtoupper($l['action'])) ?>] step <?= $l['n'] ?> — <?= e($l['id']) ?>
                                <?php if ($l['log']): ?><br><span class="text-muted">— <?= e(implode(' | ', $l['log'])) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!$all_done): ?>
                <form method="post" class="card mt-3">
                    <div class="card-body">
                        <?= csrf_field() ?>
                        <input type="hidden" name="key" value="<?= e((string) ($_REQUEST['key'] ?? '')) ?>">
                        <input type="hidden" name="action" value="run">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small mb-0">Run all steps up to</label>
                                <select name="to" class="form-select form-select-sm">
                                    <?php for ($i = 38; $i >= ($next ?? 1); $i--): ?>
                                        <option value="<?= $i ?>" <?= $i === 38 ? 'selected' : '' ?>>step <?= $i ?></option>
                                    <?php endfor; ?>
                                    <?php if (ckpt_done($p, 'verify')): ?>
                                        <option value="39">step 39 (ACTIVATION)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-0">Backup confirmation (type exactly)</label>
                                <input type="text" name="backup_phrase" class="form-control form-control-sm"
                                       placeholder="<?= e(UPG_BACKUP_PHRASE) ?>" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-play-fill me-1"></i>Run guarded steps</button>
                            </div>
                        </div>
                        <div class="form-text small">
                            DDL is auto-commit: each step is individually guarded + checkpointed. A failure stops the run;
                            fix the cause and re-run — completed steps are skipped after live re-verification.
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (ckpt_done($p, 'verify') && upg_raw_setting($p, 'schema_version') !== '2'): ?>
                <form method="post" class="card mt-3 border-danger">
                    <div class="card-body">
                        <?= csrf_field() ?>
                        <input type="hidden" name="key" value="<?= e((string) ($_REQUEST['key'] ?? '')) ?>">
                        <input type="hidden" name="action" value="run">
                        <input type="hidden" name="to" value="39">
                        <div class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-octagon me-1"></i>Step 39 — ACTIVATION (enables all new features)</div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small mb-0">Type "Yes" to confirm activation</label>
                                <input type="text" name="activation_confirm" class="form-control form-control-sm" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-0">Reason (mandatory, audited)</label>
                                <input type="text" name="activation_reason" class="form-control form-control-sm" maxlength="255">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-0">Backup confirmation</label>
                                <input type="text" name="backup_phrase" class="form-control form-control-sm" placeholder="<?= e(UPG_BACKUP_PHRASE) ?>" autocomplete="off">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-danger btn-sm w-100"><i class="bi bi-power me-1"></i>Activate</button>
                            </div>
                        </div>
                        <div class="form-text small">One transaction: 9 feat_* flags → ON, schema_version → 2, audit row written. This is the ONLY step that enables new features.</div>
                    </div>
                </form>
            <?php endif; ?>

        <?php endif; ?>

    </div>
    <div class="card-footer text-muted small">
        QuizArena Phase A — guarded migration (39 checkpointed steps). Staging first; production only after the
        real MySQL/MariaDB staging drill has passed.
    </div>
</div>
<script src="../assets/vendor/bootstrap.bundle.min.js"></script>
</body>
</html>
