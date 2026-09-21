<?php
/** QuizArena — Student: profile, photo & password */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$school = dbrow("SELECT name FROM qa_schools WHERE id = ?", [$sid]);
$class = $user['class_id'] ? dbrow("SELECT name AS class_name FROM qa_classes WHERE id = ?", [$user['class_id']]) : null;
$section = $user['section_id'] ? dbrow("SELECT name AS section_name FROM qa_sections WHERE id = ?", [$user['section_id']]) : null;
$stats = dbrow("SELECT * FROM qa_leaderboard_stats WHERE user_id = ?", [$user['id']]);

$error = null;
if (is_post()) {
    csrf_check();
    $action = post('action', '');
    if ($action === 'profile') {
        $full_name = trim((string) post('full_name', ''));
        $mobile = trim((string) post('mobile', ''));
        if (mb_strlen($full_name) < 3) $error = 'Full name is required.';
        else {
            dbq("UPDATE qa_users SET full_name = ?, mobile = ?, updated_at = NOW() WHERE id = ?", [$full_name, $mobile, $user['id']]);
            audit('profile_update', 'Updated own student profile');
            flash('success', 'Profile updated.');
            redirect('student/profile.php');
        }
    } elseif ($action === 'photo') {
        $res = handle_upload($_FILES['photo'] ?? [], 'photos', ['jpg', 'jpeg', 'png', 'webp'], 2048);
        if ($res['ok']) {
            delete_upload($user['profile_photo'] ?? null);
            dbq("UPDATE qa_users SET profile_photo = ?, updated_at = NOW() WHERE id = ?", [$res['path'], $user['id']]);
            flash('success', 'Profile photo updated.');
        } else flash('danger', $res['error']);
        redirect('student/profile.php');
    } elseif ($action === 'password') {
        $cur = (string) post('current', '');
        $p1 = (string) post('password', '');
        $p2 = (string) post('password2', '');
        if (!password_verify($cur, $user['password_hash'])) $error = 'Current password is incorrect.';
        elseif (strlen($p1) < 8) $error = 'New password must be at least 8 characters.';
        elseif ($p1 !== $p2) $error = 'New passwords do not match.';
        else {
            dbq("UPDATE qa_users SET password_hash = ?, updated_at = NOW() WHERE id = ?", [password_hash($p1, PASSWORD_DEFAULT), $user['id']]);
            audit('password_change', 'Changed own password');
            flash('success', 'Password changed successfully.');
            redirect('student/profile.php');
        }
    }
}

$title = 'My Profile';
$active = 'profile';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="dash-card">
            <div class="dash-card-body text-center">
                <img class="rounded-circle object-fit-cover mb-3" width="110" height="110" src="<?= e(avatar_url($user)) ?>" alt="">
                <h5 class="fw-bold mb-0"><?= e($user['full_name']) ?></h5>
                <div class="text-muted small">@<?= e($user['username']) ?></div>
                <div class="text-muted small mb-3"><?= e($school['name'] ?? '') ?> · <?= e($class['class_name'] ?? '') ?><?= $section ? ' · ' . e($section['section_name']) : '' ?></div>
                <div class="d-flex justify-content-center gap-3 mb-3 fs-7">
                    <div><div class="fw-bold"><?= (int) ($stats['total_quizzes'] ?? 0) ?></div><span class="text-muted">Quizzes</span></div>
                    <div><div class="fw-bold"><?= number_format((int) ($stats['total_points'] ?? 0)) ?></div><span class="text-muted">Points</span></div>
                    <div><div class="fw-bold text-warning"><?= fmt_coin($user['wallet_balance'] ?? 0) ?></div><span class="text-muted">Coins</span></div>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="photo">
                    <input class="form-control form-control-sm mb-2" type="file" name="photo" accept="image/*" required>
                    <button class="btn btn-sm btn-outline-primary w-100">Upload Photo</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Edit Profile</h5></div>
            <div class="dash-card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name</label>
                            <input class="form-control" name="full_name" value="<?= e($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile</label>
                            <input class="form-control" name="mobile" value="<?= e($user['mobile'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username (read-only)</label>
                            <input class="form-control" value="@<?= e($user['username']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email (read-only)</label>
                            <input class="form-control" value="<?= e($user['email']) ?>" readonly>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary px-4">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Change Password</h5></div>
            <div class="dash-card-body">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Current Password</label>
                            <input class="form-control" type="password" name="current" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">New Password</label>
                            <input class="form-control" type="password" name="password" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Confirm New Password</label>
                            <input class="form-control" type="password" name="password2" required>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-outline-primary px-4">Update Password</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
