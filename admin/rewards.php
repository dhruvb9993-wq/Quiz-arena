<?php
/** QuizArena — Super Admin: quiz reward configuration (defaults) */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

if (is_post()) {
    csrf_check();
    $fields = [
        'signup_bonus' => (int) post('signup_bonus', 0),
        'default_entry_fee' => (int) post('default_entry_fee', 0),
        'default_completion_bonus' => (int) post('default_completion_bonus', 0),
        'default_passing_reward' => (int) post('default_passing_reward', 0),
        'default_rank1_coins' => (int) post('default_rank1_coins', 0),
        'default_rank2_coins' => (int) post('default_rank2_coins', 0),
        'default_rank3_coins' => (int) post('default_rank3_coins', 0),
    ];
    foreach ($fields as $k => $v) save_setting($k, max(0, $v));
    audit('settings_rewards', 'Updated reward defaults');
    flash('success', 'Quiz reward defaults saved.');
    redirect('admin/rewards.php');
}

$title = 'Quiz Rewards';
$active = 'settings';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="dash-card">
    <div class="dash-card-header"><h5 class="dash-card-title">Quiz Reward Configuration (Quiz Coins)</h5></div>
    <div class="dash-card-body">
        <p class="small text-muted">These global defaults are pre-filled when a teacher creates a new quiz. Teachers and Super Admins can override them per quiz.</p>
        <form method="post">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label small fw-semibold">Sign-up Bonus</label><input class="form-control" type="number" min="0" name="signup_bonus" value="<?= e(setting('signup_bonus', 50)) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Default Quiz Entry Fee</label><input class="form-control" type="number" min="0" name="default_entry_fee" value="<?= e(setting('default_entry_fee', 20)) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Default Completion Bonus</label><input class="form-control" type="number" min="0" name="default_completion_bonus" value="<?= e(setting('default_completion_bonus', 5)) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Default Passing Reward</label><input class="form-control" type="number" min="0" name="default_passing_reward" value="<?= e(setting('default_passing_reward', 10)) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">1st Rank Coins</label><input class="form-control" type="number" min="0" name="default_rank1_coins" value="<?= e(setting('default_rank1_coins', 100)) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">2nd Rank Coins</label><input class="form-control" type="number" min="0" name="default_rank2_coins" value="<?= e(setting('default_rank2_coins', 50)) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">3rd Rank Coins</label><input class="form-control" type="number" min="0" name="default_rank3_coins" value="<?= e(setting('default_rank3_coins', 25)) ?>"></div>
                <div class="col-12"><button class="btn btn-primary px-4">Save Reward Defaults</button></div>
            </div>
        </form>
        <div class="alert alert-light border small mt-4 mb-0">
            <b>How rewards work:</b> When a student finishes a quiz, the system automatically credits the completion bonus, the passing reward (if passed) and any rank reward (1st/2nd/3rd). The quiz entry fee is deducted securely when the quiz starts.
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
