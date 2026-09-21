<?php
/** QuizArena — Super Admin: platform settings (general, rewards, leaderboard, security) */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$tab = in_array(get('tab', 'general'), ['general', 'rewards', 'leaderboard', 'security'], true) ? get('tab', 'general') : 'general';

if (is_post()) {
    csrf_check();
    $type = post('type', '');
    $fields = [];

    if ($type === 'general') {
        $fields = [
            'site_name' => trim((string) post('site_name', '')),
            'site_tagline' => trim((string) post('site_tagline', '')),
            'site_email' => trim((string) post('site_email', '')),
            'site_phone' => trim((string) post('site_phone', '')),
            'site_address' => trim((string) post('site_address', '')),
            'currency_symbol' => trim((string) post('currency_symbol', '₹')),
            'coin_name' => trim((string) post('coin_name', 'Quiz Coins')),
            'default_pass_percentage' => (float) post('default_pass_percentage', 40),
            'enable_certificates' => post('enable_certificates') ? '1' : '0',
            'timezone' => trim((string) post('timezone', 'Asia/Kolkata')),
        ];
        if ($fields['site_name'] === '') flash('danger', 'Site name is required.');
        elseif (!is_valid_email($fields['site_email'])) flash('danger', 'Enter a valid site email.');
        else {
            foreach ($fields as $k => $v) save_setting($k, $v);
            audit('settings_general', 'Updated general settings');
            flash('success', 'General settings saved.');
        }
    } elseif ($type === 'rewards') {
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
        flash('success', 'Reward defaults saved. These become the defaults for new quizzes.');
    } elseif ($type === 'leaderboard') {
        $fields = [
            'leaderboard_public' => post('leaderboard_public') ? '1' : '0',
            'leaderboard_school_wise' => post('leaderboard_school_wise') ? '1' : '0',
        ];
        foreach ($fields as $k => $v) save_setting($k, $v);
        audit('settings_leaderboard', 'Updated leaderboard settings');
        flash('success', 'Leaderboard settings saved.');
    } elseif ($type === 'security') {
        $fields = [
            'allow_registration' => post('allow_registration') ? '1' : '0',
            'session_lifetime_hours' => (int) post('session_lifetime_hours', 12),
        ];
        foreach ($fields as $k => $v) save_setting($k, $v);
        audit('settings_security', 'Updated security settings');
        flash('success', 'Security settings saved.');
    } elseif ($type === 'logo') {
        $res = handle_upload($_FILES['logo'] ?? [], 'logos', ['jpg', 'jpeg', 'png', 'webp'], 1024);
        if ($res['ok']) { save_setting('site_logo', $res['path']); flash('success', 'Site logo updated.'); }
        else flash('danger', $res['error']);
    }
    redirect('admin/settings.php?tab=' . $tab);
}

$title = 'Platform Settings';
$active = 'settings';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<ul class="nav nav-pills mb-3">
    <?php foreach (['general' => 'General', 'rewards' => 'Quiz Rewards', 'leaderboard' => 'Leaderboard', 'security' => 'Registration & Security'] as $k => $v): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><?= $v ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'general'): ?>
    <div class="dash-card">
        <div class="dash-card-header"><h5 class="dash-card-title">Website & General</h5></div>
        <div class="dash-card-body">
            <form method="post" enctype="multipart/form-data" class="mb-4">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="logo">
                <label class="form-label small fw-semibold">Site Logo</label>
                <div class="d-flex gap-3 align-items-center">
                    <img src="<?= e(site_logo_url()) ?>" width="48" height="48" class="rounded border" alt="">
                    <div class="d-flex gap-2">
                        <input class="form-control form-control-sm" type="file" name="logo" accept="image/*">
                        <button class="btn btn-sm btn-outline-primary">Upload</button>
                    </div>
                </div>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="general">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Site Name</label><input class="form-control" name="site_name" value="<?= e(setting('site_name', 'QuizArena')) ?>" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Tagline</label><input class="form-control" name="site_tagline" value="<?= e(setting('site_tagline', '')) ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Support Email</label><input class="form-control" type="email" name="site_email" value="<?= e(setting('site_email', '')) ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Support Phone</label><input class="form-control" name="site_phone" value="<?= e(setting('site_phone', '')) ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Currency Symbol</label><input class="form-control" name="currency_symbol" value="<?= e(setting('currency_symbol', '₹')) ?>"></div>
                    <div class="col-12"><label class="form-label small fw-semibold">Address</label><input class="form-control" name="site_address" value="<?= e(setting('site_address', '')) ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Coin Name</label><input class="form-control" name="coin_name" value="<?= e(setting('coin_name', 'Quiz Coins')) ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Default Pass %</label><input class="form-control" type="number" min="1" max="100" name="default_pass_percentage" value="<?= e(setting('default_pass_percentage', 40)) ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Timezone</label>
                        <select class="form-select" name="timezone">
                            <?php foreach (['Asia/Kolkata', 'Asia/Karachi', 'Asia/Dhaka', 'Asia/Kathmandu', 'Asia/Colombo', 'UTC'] as $tz): ?>
                                <option value="<?= $tz ?>" <?= setting('timezone', 'Asia/Kolkata') === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enable_certificates" id="ec" <?= setting('enable_certificates', '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="ec">Enable certificate generation platform-wide</label>
                        </div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary px-4">Save General Settings</button></div>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'rewards'): ?>
    <div class="dash-card">
        <div class="dash-card-header"><h5 class="dash-card-title">Quiz Reward Defaults (Quiz Coins)</h5></div>
        <div class="dash-card-body">
            <p class="small text-muted">These values are pre-filled when a teacher creates a new quiz. Individual quizzes can override them.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="rewards">
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
                <b>Example:</b> Quiz entry fee 20 coins · 1st rank 100 · 2nd rank 50 · 3rd rank 25. Rewards are credited to wallets automatically when results are finalised.
            </div>
        </div>
    </div>

<?php elseif ($tab === 'leaderboard'): ?>
    <div class="dash-card">
        <div class="dash-card-header"><h5 class="dash-card-title">Leaderboard Settings</h5></div>
        <div class="dash-card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="leaderboard">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="leaderboard_public" id="lp" <?= setting('leaderboard_public', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lp"><b>Public leaderboard</b> — visitors can view the leaderboard page without logging in.</label>
                </div>
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" name="leaderboard_school_wise" id="lsw" <?= setting('leaderboard_school_wise', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lsw"><b>School-wise boards</b> — each school sees its own students' rankings.</label>
                </div>
                <button class="btn btn-primary px-4">Save Leaderboard Settings</button>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'security'): ?>
    <div class="dash-card">
        <div class="dash-card-header"><h5 class="dash-card-title">Registration & Security</h5></div>
        <div class="dash-card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="security">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="allow_registration" id="ar" <?= setting('allow_registration', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ar"><b>Allow student self-registration</b> — students can create accounts with a school code.</label>
                </div>
                <div class="mb-4 col-md-4">
                    <label class="form-label small fw-semibold">Session Lifetime (hours)</label>
                    <input class="form-control" type="number" min="1" max="168" name="session_lifetime_hours" value="<?= e(setting('session_lifetime_hours', 12)) ?>">
                </div>
                <button class="btn btn-primary px-4">Save Security Settings</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
