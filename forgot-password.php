<?php
/** QuizArena — Forgot password (sends reset link by email) */
require __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect(dash_path(current_user()['role']));

$done = null;
$error = null;
if (is_post()) {
    csrf_check();
    $email = trim((string) post('email', ''));
    if (!is_valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $res = request_password_reset($email);
        $done = $res['msg'];
    }
}

$title = 'Forgot Password';
$subtitle = 'Enter your registered email and we will send you a reset link.';
require __DIR__ . '/app/layouts/auth_header.php';
?>
<div class="auth-card">
    <?php if ($done): ?>
        <div class="text-center py-3">
            <i class="bi bi-envelope-check display-4 text-success"></i>
            <p class="mt-3 small text-muted"><?= $done ?></p>
            <a class="btn btn-outline-primary btn-sm mt-2" href="<?= url('login.php') ?>">Back to Login</a>
        </div>
    <?php else: ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Registered Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input class="form-control" type="email" name="email" placeholder="you@example.com" required autofocus>
                </div>
            </div>
            <button class="btn btn-primary w-100 py-2" type="submit">Send Reset Link</button>
            <p class="text-center small text-muted mt-3 mb-0"><a href="<?= url('login.php') ?>">← Back to login</a></p>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/app/layouts/auth_footer.php'; ?>
