<?php
/** QuizArena — Login */
require __DIR__ . '/app/bootstrap.php';

// Already logged in?
if (current_user()) redirect(dash_path(current_user()['role']));

$error = null;
$username = '';

if (is_post()) {
    csrf_check();
    $username = trim((string) post('username', ''));
    $password = (string) post('password', '');
    $remember = (bool) post('remember');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $clean = clean_username($username);
        $mins = login_blocked_remaining($clean);
        if ($mins > 0) {
            $error = 'Too many failed attempts. Please wait ' . $mins . ' minute(s) before trying again.';
        } else {
            $user = dbrow("SELECT * FROM qa_users WHERE username = ? OR email = ?", [$clean, $username]);
            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    record_login_attempt($clean, false);
                    $error = 'Your account is ' . e($user['status']) . '. Please contact your school administrator.';
                } else {
                    record_login_attempt($clean, true);
                    login_user($user, $remember);
                    ensure_wallet($user['id']);
                    $intended = $_SESSION['intended'] ?? null;
                    unset($_SESSION['intended']);
                    redirect($intended ?: dash_path($user['role']));
                }
            } else {
                record_login_attempt($clean, false);
                $error = 'Invalid username or password.';
            }
        }
    }
}

$title = 'Sign In';
$subtitle = 'Welcome back! Log in to continue.';
require __DIR__ . '/app/layouts/auth_header.php';
?>
<form method="post" class="auth-card" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
    <div class="mb-3">
        <label class="form-label small fw-semibold">Username or Email</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input class="form-control" name="username" value="<?= e($username) ?>" placeholder="@username or email" required autofocus>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label small fw-semibold">Password</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input class="form-control" type="password" id="pw" name="password" placeholder="••••••••" required>
            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="pw"><i class="bi bi-eye"></i></button>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember">
            <label class="form-check-label small" for="remember">Remember me</label>
        </div>
        <a class="small" href="<?= url('forgot-password.php') ?>">Forgot password?</a>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
    <p class="text-center small text-muted mt-3 mb-0">
        New student? <a href="<?= url('register.php') ?>">Create an account</a>
    </p>
</form>
<?php require __DIR__ . '/app/layouts/auth_footer.php'; ?>
