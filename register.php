<?php
/** QuizArena — Student self-registration */
require __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect(dash_path(current_user()['role']));
if (setting('allow_registration', '1') !== '1') {
    $title = 'Registration Closed';
    require __DIR__ . '/app/layouts/auth_header.php';
    echo '<div class="auth-card text-center"><i class="bi bi-person-lock display-4 text-warning"></i><p class="mt-3 mb-0">New registrations are currently disabled. Please contact your school administrator.</p></div>';
    require __DIR__ . '/app/layouts/auth_footer.php';
    exit;
}

$error = null;
$old = ['full_name' => '', 'username' => '', 'email' => '', 'mobile' => '', 'school_code' => '', 'class_id' => '', 'section_id' => ''];

if (is_post()) {
    csrf_check();
    $old = [
        'full_name'   => trim((string) post('full_name', '')),
        'username'    => clean_username(post('username', '')),
        'email'       => trim((string) post('email', '')),
        'mobile'      => trim((string) post('mobile', '')),
        'school_code' => strtoupper(trim((string) post('school_code', ''))),
        'class_id'    => (int) post('class_id', 0),
        'section_id'  => (int) post('section_id', 0),
    ];
    $password = (string) post('password', '');
    $confirm  = (string) post('password2', '');

    if (mb_strlen($old['full_name']) < 3) $error = 'Please enter your full name.';
    elseif (!username_valid($old['username'])) $error = 'Username must be 3–30 characters (letters, numbers, dots, underscores).';
    elseif (!is_valid_email($old['email'])) $error = 'Please enter a valid email address.';
    elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters.';
    elseif ($password !== $confirm) $error = 'Passwords do not match.';
    elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE username = ?", [$old['username']])) $error = 'That username is already taken. Please choose another.';
    elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE email = ?", [$old['email']])) $error = 'An account with that email already exists.';
    elseif ($old['school_code'] === '') $error = 'Please enter your school code.';

    if (!$error) {
        $school = dbrow("SELECT * FROM qa_schools WHERE code = ? AND status = 'active'", [$old['school_code']]);
        if (!$school) {
            $error = 'No active school found with that code. Please check with your school administrator.';
        } elseif ($old['class_id'] && !dbval("SELECT COUNT(*) FROM qa_classes WHERE id = ? AND school_id = ?", [$old['class_id'], $school['id']])) {
            $error = 'Invalid class selected.';
        } elseif ($old['section_id'] && !dbval("SELECT COUNT(*) FROM qa_sections WHERE id = ? AND class_id = ? AND school_id = ?", [$old['section_id'], $old['class_id'], $school['id']])) {
            $error = 'Invalid section selected.';
        }
    }

    if (!$error) {
        db_begin();
        try {
            dbq(
                "INSERT INTO qa_users (school_id, role, full_name, username, email, password_hash, mobile, class_id, section_id, status, created_at, updated_at)
                 VALUES (?, 'student', ?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0), 'active', NOW(), NOW())",
                [$school['id'], $old['full_name'], $old['username'], $old['email'],
                 password_hash($password, PASSWORD_DEFAULT), $old['mobile'], $old['class_id'], $old['section_id']]
            );
            $uid = db_id();
            ensure_wallet($uid, $school['id']);
            grant_signup_bonus($uid);
            db_commit();

            notify($uid, 'Welcome to QuizArena! 🎉',
                'Your account is ready. Check your wallet — a sign-up bonus of ' . fmt_coin(setting('signup_bonus', 50)) . ' ' . coin_name() . ' has been added.',
                'student/dashboard.php', $school['id']);

            flash('success', 'Account created successfully! Please sign in.');
            redirect('login.php');
        } catch (Throwable $e) {
            db_rollback();
            error_log('register error: ' . $e->getMessage());
            $error = 'Registration failed due to a server error. Please try again.';
        }
    }
}

$schools = dball("SELECT id, name FROM qa_schools WHERE status = 'active' ORDER BY name");
$classes = [];
if (!empty($old['school_code'])) {
    $sch = dbrow("SELECT * FROM qa_schools WHERE code = ? AND status = 'active'", [$old['school_code']]);
    if ($sch) $classes = dball("SELECT * FROM qa_classes WHERE school_id = ? ORDER BY name", [$sch['id']]);
}

$title = 'Create Student Account';
$subtitle = 'Join your school on QuizArena. It only takes a minute.';
require __DIR__ . '/app/layouts/auth_header.php';
?>
<form method="post" class="auth-card" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
    <div class="mb-3">
        <label class="form-label small fw-semibold">Full Name</label>
        <input class="form-control" name="full_name" value="<?= e($old['full_name']) ?>" required>
    </div>
    <div class="row g-3">
        <div class="col-md-6 mb-3">
            <label class="form-label small fw-semibold">Username</label>
            <input class="form-control" data-username name="username" value="<?= e($old['username'] ? '@' . $old['username'] : '') ?>" placeholder="@dhruv_123" required>
            <div class="form-text">Unique username — your friends can send you Quiz Coins using it.</div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label small fw-semibold">Mobile Number</label>
            <input class="form-control" name="mobile" value="<?= e($old['mobile']) ?>" placeholder="98XXXXXX00">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label small fw-semibold">Email Address</label>
        <input class="form-control" type="email" name="email" value="<?= e($old['email']) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label small fw-semibold">School Code</label>
        <input class="form-control text-uppercase" name="school_code" id="school_code" value="<?= e($old['school_code']) ?>" placeholder="e.g. ABCD1234" required>
        <div class="form-text">Ask your school administrator for your school code.</div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6">
            <label class="form-label small fw-semibold">Class</label>
            <select class="form-select" name="class_id" id="class_id" disabled>
                <option value="0">Select class</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $old['class_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label small fw-semibold">Section</label>
            <select class="form-select" name="section_id" id="section_id" disabled>
                <option value="0">Select section</option>
            </select>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6">
            <label class="form-label small fw-semibold">Password</label>
            <input class="form-control" type="password" name="password" placeholder="Min 8 characters" required>
        </div>
        <div class="col-6">
            <label class="form-label small fw-semibold">Confirm Password</label>
            <input class="form-control" type="password" name="password2" required>
        </div>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-person-plus"></i> Create Account</button>
    <p class="text-center small text-muted mt-3 mb-0">Already have an account? <a href="<?= url('login.php') ?>">Sign in</a></p>
</form>
<script>
    // load classes/sections when school code changes
    const codeInput = document.getElementById('school_code');
    const classSel = document.getElementById('class_id');
    const sectionSel = document.getElementById('section_id');
    let loadedCode = '<?= e($old['school_code']) ?>';
    function loadClasses() {
        const code = codeInput.value.trim().toUpperCase();
        if (!code || code === loadedCode) return;
        loadedCode = code;
        fetch('api/school_api.php?action=classes&code=' + encodeURIComponent(code), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { classSel.innerHTML = '<option value="0">No classes found</option>'; classSel.disabled = true; sectionSel.disabled = true; return; }
                classSel.disabled = false;
                classSel.innerHTML = '<option value="0">Select class</option>' + d.classes.map(c => '<option value="' + c.id + '">' + c.name + '</option>').join('');
            });
    }
    codeInput.addEventListener('blur', loadClasses);
    classSel.addEventListener('change', function () {
        fetch('api/school_api.php?action=sections&class_id=' + this.value, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { sectionSel.innerHTML = ''; sectionSel.disabled = true; return; }
                sectionSel.disabled = false;
                sectionSel.innerHTML = '<option value="0">Select section</option>' + d.sections.map(s => '<option value="' + s.id + '">' + s.name + '</option>').join('');
            });
    });
    // re-enable if preloaded
    if (loadedCode) { classSel.disabled = false; }
</script>
<?php require __DIR__ . '/app/layouts/auth_footer.php'; ?>
