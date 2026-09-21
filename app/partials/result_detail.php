<?php
/**
 * QuizArena — Shared result detail partial.
 * Expects: $attempt (row), $detail_user (user row), $detail_quiz (quiz row), $allow_download (bool)
 * Renders the score summary card + per-question analysis.
 */
if (!defined('QA_RUNNING')) exit('Direct access denied');

$questions = attempt_questions($attempt, true);
$answers = attempt_answers_map($attempt['id']);
$letter = ['A', 'B', 'C', 'D', 'E', 'F'];
$show_correct = (bool) ($detail_quiz['show_answers'] ?? 0) || $allow_download;
?>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="dash-card h-100">
            <div class="dash-card-body text-center">
                <div class="fs-7 text-muted text-uppercase fw-bold mb-2">Result</div>
                <?php $pct = (float) $attempt['percentage']; ?>
                <div class="position-relative d-inline-block">
                    <svg width="130" height="130" viewBox="0 0 130 130">
                        <circle cx="65" cy="65" r="55" fill="none" stroke="#eef2ff" stroke-width="12"/>
                        <circle cx="65" cy="65" r="55" fill="none"
                                stroke="<?= $attempt['pass_status'] === 'pass' ? '#10b981' : '#ef4444' ?>" stroke-width="12"
                                stroke-linecap="round" stroke-dasharray="<?= (2 * 3.14159 * 55) ?>" stroke-dashoffset="<?= (2 * 3.14159 * 55) * (1 - $pct / 100) ?>"
                                transform="rotate(-90 65 65)"/>
                        <text x="65" y="70" text-anchor="middle" font-size="24" font-weight="bold" fill="#0f172a"><?= round($pct, 1) ?>%</text>
                    </svg>
                </div>
                <h4 class="fw-bold mt-2 mb-0">
                    <?= round((float) $attempt['score'], 1) ?> / <?= round((float) $attempt['total_marks'], 1) ?>
                </h4>
                <span class="badge <?= $attempt['pass_status'] === 'pass' ? 'badge-soft-success' : 'badge-soft-danger' ?> fs-6 mt-1">
                    <?= strtoupper(e($attempt['pass_status'])) ?>
                </span>
                <div class="mt-3">
                    <?php if ($attempt['rank']): ?><div class="fs-6 fw-bold">🏆 Rank: <?= ordinal((int) $attempt['rank']) ?></div><?php endif; ?>
                    <?php if ($attempt['points_earned'] > 0): ?><div class="fs-7 text-muted">+<?= (int) $attempt['points_earned'] ?> points</div><?php endif; ?>
                    <?php if ($attempt['coins_earned'] > 0): ?><div class="fs-7 text-warning fw-semibold"><i class="bi bi-coin"></i> +<?= fmt_coin($attempt['coins_earned']) ?> <?= e(coin_name()) ?></div><?php endif; ?>
                    <?php if ((int) $attempt['entry_fee'] > 0): ?><div class="fs-8 text-muted">Entry fee paid: <?= fmt_coin($attempt['entry_fee']) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="dash-card h-100">
            <div class="dash-card-header"><h5 class="dash-card-title">Performance Summary</h5></div>
            <div class="dash-card-body">
                <div class="row text-center g-3">
                    <div class="col-4">
                        <div class="stat-value text-success"><?= (int) $attempt['correct_count'] ?></div>
                        <div class="stat-label">Correct</div>
                    </div>
                    <div class="col-4">
                        <div class="stat-value text-danger"><?= (int) $attempt['wrong_count'] ?></div>
                        <div class="stat-label">Wrong</div>
                    </div>
                    <div class="col-4">
                        <div class="stat-value text-muted"><?= (int) $attempt['unanswered_count'] ?></div>
                        <div class="stat-label">Unanswered</div>
                    </div>
                </div>
                <hr>
                <div class="row g-2 fs-7">
                    <div class="col-sm-6"><span class="text-muted">Quiz:</span> <b><?= e($detail_quiz['title']) ?></b></div>
                    <div class="col-sm-6"><span class="text-muted">Student:</span> <b><?= e($detail_user['full_name']) ?></b> (@<?= e($detail_user['username']) ?>)</div>
                    <div class="col-sm-6"><span class="text-muted">Started:</span> <?= nice_date($attempt['started_at'], true) ?></div>
                    <div class="col-sm-6"><span class="text-muted">Submitted:</span> <?= nice_date($attempt['submitted_at'], true) ?></div>
                    <div class="col-sm-6"><span class="text-muted">Time taken:</span>
                        <?php if ($attempt['duration_seconds']): ?>
                            <?= floor((int) $attempt['duration_seconds'] / 60) ?> min <?= (int) $attempt['duration_seconds'] % 60 ?> sec
                        <?php else: ?>—<?php endif; ?>
                    </div>
                    <div class="col-sm-6"><span class="text-muted">Attempt #:</span> <?= (int) $attempt['attempt_number'] ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$show_correct): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> The teacher has chosen to hide correct answers for this quiz. You can still see your selected answers.</div>
<?php endif; ?>

<div class="dash-card">
    <div class="dash-card-header"><h5 class="dash-card-title">Question-wise Analysis</h5></div>
    <div class="dash-card-body p-0">
        <?php foreach ($questions as $qi => $q): ?>
            <?php
            $sel = $answers[$q['id']] ?? null;
            $is_correct = ($sel !== null && $sel !== 0 && $sel === (int) $q['correct_option_id']);
            $is_wrong = ($sel !== null && $sel !== 0 && !$is_correct);
            $is_unanswered = ($sel === null || $sel === 0);
            $badge = $is_correct ? 'badge-soft-success' : ($is_wrong ? 'badge-soft-danger' : 'badge-soft-secondary');
            $icon = $is_correct ? '<i class="bi bi-check-circle-fill text-success"></i>' : ($is_wrong ? '<i class="bi bi-x-circle-fill text-danger"></i>' : '<i class="bi bi-dash-circle-fill text-muted"></i>');
            ?>
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between gap-2 mb-2">
                    <div class="fw-semibold small">Q<?= $qi + 1 ?>. <?= e($q['question_text']) ?></div>
                    <span class="badge <?= $badge ?> text-nowrap"><?= $icon ?> <?= $is_correct ? 'Correct' : ($is_wrong ? 'Wrong' : 'Unanswered') ?> · <?= e($q['marks']) ?> mark<?= $q['marks'] == 1 ? '' : 's' ?></span>
                </div>
                <div class="row g-2">
                    <?php foreach ($q['options'] as $oi => $o): ?>
                        <?php
                        $opt_state = '';
                        if ((int) $o['id'] === (int) $q['correct_option_id'] && $show_correct) $opt_state = 'list-group-item-success';
                        elseif ((int) $o['id'] === $sel) $opt_state = 'list-group-item-danger';
                        ?>
                        <div class="col-md-6">
                            <div class="list-group-item py-2 small <?= $opt_state ?> <?= (int) $o['id'] === $sel ? 'fw-semibold' : '' ?>">
                                <span class="badge bg-light text-dark me-1"><?= $letter[$oi] ?? '' ?></span>
                                <?= e($o['option_text']) ?>
                                <?php if ((int) $o['id'] === $sel && $show_correct && (int) $o['id'] !== (int) $q['correct_option_id']): ?>
                                    <span class="text-danger fs-8">(your answer)</span>
                                <?php endif; ?>
                                <?php if ((int) $o['id'] === (int) $q['correct_option_id'] && $show_correct): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($q['explanation']): ?>
                    <div class="small text-muted mt-2"><i class="bi bi-lightbulb text-warning"></i> <b>Explanation:</b> <?= e($q['explanation']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
