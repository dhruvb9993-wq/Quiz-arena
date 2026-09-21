<?php
/** QuizArena — Reset password (token from email link) */
require __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect(dash_path(current_user()['role']));

$token = (string) get('token', '');
$error = null;
$done = null;

if ($token === '') {
    $error = 'Missing reset token. Please use the link from your email.';
} elseif (is_post()) {
    csrf_check();
    $p1 = (string) post('password', '');
    $p2 = (string) post('password2', '');
    if (strlen($p1) < 8) $error = 'Password must be at least 8 characters.';
    elseif ($p1 !== $p2) $error = 'Passwords do not match.';
    else {
        $res = consume_reset_token($token, $p1);
        if ($res['ok']) {
            $done = $res['msg'];
        } else {
            $error = $res['msg'];
        }
    }
}

$title = 'Reset Password';
require __DIR__ . '/app/layouts/auth_header.php';
?>
<div class="auth-card">
    <?php if ($done): ?>
        <div class="text-center py-3">
            <i class="bi bi-check-circle display-4 text-success"></i>
            <p class="mt-3"><?= e($done) ?></p>
            <a class="btn btn-primary w-100 py-2" href="<?= url('login.php') ?>">Go to Login</a>
        </div>
    <?php elseif ($error && !is_post()): ?>
        <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
        <a class="btn btn-outline-primary w-100" href="<?= url('forgot-password.php') ?>">Request a new link</a>
    <?php else: ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold">New Password</label>
                <input class="form-control" type="password" name="password" placeholder="Min 8 characters" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Confirm New Password</label>
                <input class="form-control" type="password" name="password2" required>
            </div>
            <button class="btn btn-primary w-100 py-2" type="submit">Update Password</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/app/layouts/auth_footer.php'; ?>
