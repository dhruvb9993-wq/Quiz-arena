<?php
/**
 * QuizArena — Phase A upgrade: runner engine.
 *
 * Manual, resumable, stop-on-failure. Each step is executed through its
 * guard (which re-verifies the live object and skips only when the
 * checkpoint AND the live definition are correct). DDL is treated as
 * auto-commit: a failure leaves earlier completed steps checkpointed and
 * the failed step simply re-runs on the next attempt.
 */

if (!defined('QA_RUNNING')) { exit('Direct access denied'); }

function upg_steps(): array {
    static $steps = null;
    if ($steps === null) {
        $steps = require __DIR__ . '/steps.php';
        // sanity: strict 1..39 ordering, unique ids
        if (count($steps) !== 39) {
            throw new RuntimeException('Step registry corrupted: expected 39 steps, found ' . count($steps));
        }
        $ids = [];
        foreach ($steps as $i => $s) {
            if ((int) $s['n'] !== $i + 1) {
                throw new RuntimeException("Step registry corrupted at position " . ($i + 1));
            }
            if (isset($ids[$s['id']])) {
                throw new RuntimeException("Duplicate step id: {$s['id']}");
            }
            $ids[$s['id']] = true;
        }
    }
    return $steps;
}

/**
 * Execute a single step through its guard.
 * @return array{action:string, log:string[]} action = 'skip'|'run'
 */
function upg_execute_step(PDO $p, array $step): array {
    $log = [];
    $ran = false;

    switch ($step['kind']) {
        case 'create':
            $ran = guard_create_table($p, $step['table'], $step['sql'], $step['cols']);
            break;

        case 'col':
            $ran = guard_add_column($p, $step['table'], $step['column'], $step['definition'],
                                    $step['expected_type'], $step['nullable'], $step['default']);
            break;

        case 'enum':
            $ran = guard_enum_append($p, $step['table'], $step['column'],
                                     $step['legacy'], $step['append'], $step['default'] ?? null);
            break;

        case 'index':
            $ran = guard_add_index($p, $step['table'], $step['name'], $step['cols'],
                                   (bool) ($step['unique'] ?? false));
            break;

        case 'handler':
            if (ckpt_done($p, $step['id'])) {
                $log[] = 'checkpoint present — skipped';
                break;
            }
            switch ($step['handler']) {
                case 'seed':
                    $log = upg_handler_seed($p);
                    break;
                case 'backfill':
                    $log = upg_handler_backfill($p);
                    break;
                case 'verify':
                    $checks = upg_handler_verify($p);
                    $log[] = count($checks) . '/' . count($checks) . ' verification checks passed';
                    break;
                case 'activate':
                    // Gated: runner callers must pass confirmation via upg_run_to().
                    if (empty($GLOBALS['UPG_ACTIVATE_CONFIRM']) || $GLOBALS['UPG_ACTIVATE_CONFIRM'] !== 'Yes') {
                        throw new UpgStepException(
                            'Activation step is locked. Re-run with the explicit activation confirmation and a reason.',
                            $step['id']);
                    }
                    if (trim((string) ($GLOBALS['UPG_ACTIVATE_REASON'] ?? '')) === '') {
                        throw new UpgStepException('Activation requires a written reason (audit).', $step['id']);
                    }
                    $actor = (int) ($GLOBALS['UPG_ACTIVATE_ACTOR'] ?? 0);
                    if ($actor <= 0) {
                        throw new UpgStepException('Activation requires an authenticated superadmin actor.', $step['id']);
                    }
                    $log = upg_handler_activate($p, $actor, (string) $GLOBALS['UPG_ACTIVATE_REASON']);
                    break;
                default:
                    throw new UpgStepException("Unknown handler: {$step['handler']}", $step['id']);
            }
            ckpt_set($p, $step['id']);
            $ran = true;
            break;

        default:
            throw new UpgStepException("Unknown step kind: {$step['kind']}", $step['id']);
    }

    return ['action' => $ran ? 'run' : 'skip', 'log' => $log];
}

/**
 * Run steps 1..$to sequentially, stopping at the first failure.
 * Guards skip completed steps automatically, so this is safe to re-run.
 *
 * @return array<int,array{n:int, id:string, action:string, log:string[]}>  Throws on failure.
 */
function upg_run_to(PDO $p, int $to): array {
    $out = [];
    foreach (upg_steps() as $step) {
        if ($step['n'] > $to) break;
        $res = upg_execute_step($p, $step);
        $out[] = [
            'n' => $step['n'], 'id' => $step['id'],
            'action' => $res['action'], 'log' => $res['log'],
        ];
    }
    return $out;
}

/** Per-step display state: 'done' when its checkpoint exists (execution always re-verifies live). */
function upg_states(PDO $p): array {
    $states = [];
    foreach (upg_steps() as $step) {
        $states[$step['n']] = ckpt_done($p, $step['id']) ? 'done' : 'pending';
    }
    return $states;
}
