<?php
/** QuizArena — Super Admin: add/edit/suspend/activate/delete user + assign roles */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$id = (int) get('id', 0);
$action = get('action', '');
$preset_school = (int) get('school_id', 0);
$preset_role = get('role', '');

/* Quick actions */
if ($action && $id) {
    csrf_check();
    $target = dbrow("SELECT * FROM qa_users WHERE id = ?", [$id]);
    if (!$target) { flash('danger', 'User not found.'); redirect('admin/users.php'); }
    if ($target['role'] === 'superadmin') { flash('danger', 'The Super Admin account cannot be modified this way.'); redirect('admin/users.php'); }
    if ($action === 'suspend') { dbq("UPDATE qa_users SET status='suspended' WHERE id=?", [$id]); audit('user_suspend', 'Suspended @' . $target['username']); flash('success', 'User suspended.'); }
    elseif ($action === 'activate') { dbq("UPDATE qa_users SET status='active' WHERE id=?", [$id]); audit('user_activate', 'Activated @' . $target['username']); flash('success', 'User activated.'); }
    elseif ($action === 'delete') {
        db_begin();
        try {
            foreach ([
                'qa_user_answers' => "DELETE FROM qa_user_answers WHERE attempt_id IN (SELECT id FROM qa_quiz_attempts WHERE user_id = ?)",
                'qa_quiz_attempts' => "DELETE FROM qa_quiz_attempts WHERE user_id = ?",
                'qa_certificates' => "DELETE FROM qa_certificates WHERE user_id = ?",
                'qa_leaderboard_stats' => "DELETE FROM qa_leaderboard_stats WHERE user_id = ?",
                'qa_user_rank_history' => "DELETE FROM qa_user_rank_history WHERE user_id = ?",
                'qa_wallet_transactions' => "DELETE FROM qa_wallet_transactions WHERE user_id = ? OR sender_user_id = ? OR receiver_user_id = ?",
                'qa_wallets' => "DELETE FROM qa_wallets WHERE user_id = ?",
                'qa_notifications' => "DELETE FROM qa_notifications WHERE user_id = ?",
                'qa_quizzes' => "DELETE FROM qa_quizzes WHERE created_by = ?",
                'qa_password_resets' => "DELETE FROM qa_password_resets WHERE user_id = ?",
            ] as $sql) { dbq($sql, [$id, $id, $id]); }
            dbq("DELETE FROM qa_users WHERE id = ?", [$id]);
            db_commit();
            delete_upload($target['profile_photo'] ?? null);
            audit('user_delete', 'Deleted @' . $target['username'] . ' with all data');
            flash('success', 'User and related data deleted.');
        } catch (Throwable $e) { db_rollback(); flash('danger', 'Delete failed: ' . $e->getMessage()); }
        redirect('admin/users.php');
    }
    redirect('admin/users.php');
}

$target = $id ? dbrow("SELECT * FROM qa_users WHERE id = ?", [$id]) : null;
if ($id && !$target) { flash('danger', 'User not found.'); redirect('admin/users.php'); }

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");
$classes = [];

$error = null;
if (is_post()) {
    csrf_check();
    $d = [
        'full_name' => trim((string) post('full_name', '')),
        'username' => clean_username(post('username', '')),
        'email' => trim((string) post('email', '')),
        'mobile' => trim((string) post('mobile', '')),
        'role' => in_array(post('role', 'student'), ['school_admin', 'teacher', 'student'], true) ? post('role', 'student') : 'student',
        'school_id' => (int) post('school_id', 0),
        'class_id' => (int) post('class_id', 0) ?: null,
        'section_id' => (int) post('section_id', 0) ?: null,
        'status' => post('status', 'active') === 'suspended' ? 'suspended' : 'active',
        'password' => (string) post('password', ''),
    ];
    if (mb_strlen($d['full_name']) < 3) $error = 'Full name is required.';
    elseif (!username_valid($d['username'])) $error = 'Username must be 3–30 characters (letters, numbers, dots, underscores).';
    elseif (!is_valid_email($d['email'])) $error = 'Enter a valid email.';
    elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE username = ? AND id != ?", [$d['username'], $id])) $error = 'Username already taken.';
    elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE email = ? AND id != ?", [$d['email'], $id])) $error = 'Email already registered.';
    elseif ($d['role'] !== 'superadmin' && !$d['school_id']) $error = 'Please select the school for this user.';
    elseif (!$id && strlen($d['password']) < 8) $error = 'Password must be at least 8 characters for a new account.';

    if (!$error) {
        if ($id) {
            dbq("UPDATE qa_users SET full_name=?, username=?, email=?, mobile=?, role=?, school_id=?, class_id=?, section_id=?, status=?, updated_at=NOW() WHERE id=?",
                [$d['full_name'], $d['username'], $d['email'], $d['mobile'], $d['role'], $d['school_id'], $d['class_id'], $d['section_id'], $d['status'], $id]);
            if ($d['password'] !== '') dbq("UPDATE qa_users SET password_hash=? WHERE id=?", [password_hash($d['password'], PASSWORD_DEFAULT), $id]);
            if ($d['role'] !== 'student') dbq("UPDATE qa_users SET class_id = NULL, section_id = NULL WHERE id = ?", [$id]);
            ensure_wallet($id, $d['school_id']);
            audit('user_update', 'Updated @' . $d['username']);
            flash('success', 'User updated.');
        } else {
            dbq("INSERT INTO qa_users (school_id, role, full_name, username, email, password_hash, mobile, class_id, section_id, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())",
                [$d['role'] === 'superadmin' ? null : $d['school_id'], $d['role'], $d['full_name'], $d['username'], $d['email'],
                 password_hash($d['password'], PASSWORD_DEFAULT), $d['mobile'],
                 $d['role'] === 'student' ? $d['class_id'] : null, $d['role'] === 'student' ? $d['section_id'] : null]);
            $new_uid = db_id();
            ensure_wallet($new_uid, $d['role'] === 'superadmin' ? 0 : $d['school_id']);
            grant_signup_bonus($new_uid);
            audit('user_create', 'Created ' . $d['role'] . ' @' . $d['username'] . ' for school #' . $d['school_id']);
            flash('success', 'User created. Password has been set.');
            redirect('admin/users.php');
        }
        redirect('admin/users.php');
    }
}

if ($target) {
    $preset_school = (int) $target['school_id'];
    $preset_role = $target['role'];
    $classes = $target['school_id'] ? dball("SELECT * FROM qa_classes WHERE school_id = ? ORDER BY name", [$target['school_id']]) : [];
}

$title = $id ? 'Edit User' : 'Add User';
$active = 'users';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title"><?= $id ? 'Edit User — @' . e($target['username']) : 'Add New User' ?></h5></div>
            <div class="dash-card-body">
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name *</label>
                            <input class="form-control" name="full_name" value="<?= e($target['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username *</label>
                            <input class="form-control" data-username name="username" value="<?= e(($target['username'] ?? '') ? '@' . $target['username'] : '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email *</label>
                            <input class="form-control" type="email" name="email" value="<?= e($target['email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile</label>
                            <input class="form-control" name="mobile" value="<?= e($target['mobile'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Role</label>
                            <select class="form-select" name="role" id="role">
                                <?php if (!$id): ?><option value="student" <?= $preset_role === 'student' ? 'selected' : '' ?>>Student</option><?php endif; ?>
                                <option value="teacher" <?= $preset_role === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                                <option value="school_admin" <?= $preset_role === 'school_admin' ? 'selected' : '' ?>>School Admin</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">School</label>
                            <select class="form-select" name="school_id" id="school_id" required>
                                <option value="0">— Select school —</option>
                                <?php foreach ($schools as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= $preset_school === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= ($target['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= ($target['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="classWrap">
                            <label class="form-label small fw-semibold">Class</label>
                            <select class="form-select" name="class_id" id="class_id"><option value="0">— Select —</option></select>
                        </div>
                        <div class="col-md-6 d-none" id="sectionWrap">
                            <label class="form-label small fw-semibold">Section</label>
                            <select class="form-select" name="section_id" id="section_id"><option value="0">— Select —</option></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold"><?= $id ? 'New Password (leave blank to keep current)' : 'Password * (min 8 chars)' ?></label>
                            <input class="form-control" type="password" name="password" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary px-4"><?= $id ? 'Save Changes' : 'Create User' ?></button>
                            <a class="btn btn-light" href="<?= url('admin/users.php') ?>">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    const roleSel = document.getElementById('role');
    const schoolSel = document.getElementById('school_id');
    const classSel = document.getElementById('class_id');
    const sectionSel = document.getElementById('section_id');
    const cw = document.getElementById('classWrap'), sw = document.getElementById('sectionWrap');
    function toggleClass() {
        const student = roleSel.value === 'student';
        cw.classList.toggle('d-none', !student);
        sw.classList.toggle('d-none', !student);
        if (!student) { classSel.disabled = true; sectionSel.disabled = true; }
        else loadClasses();
    }
    function loadClasses() {
        const sid = schoolSel.value;
        if (!sid) { classSel.innerHTML = '<option value="0">— Select —</option>'; sectionSel.innerHTML = ''; classSel.disabled = true; return; }
        fetch('<?= url('api/school_api.php?action=classes&code=__X__') ?>'.replace('__X__', 'DUMMY') + '&school_id=' + sid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
        fetch('<?= url('admin/ajax-classes.php?school_id=') ?>' + sid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(d => {
                classSel.disabled = false;
                classSel.innerHTML = '<option value="0">— Select —</option>' + d.classes.map(c => '<option value="' + c.id + '" ' + (c.id == '<?= (int)($target['class_id'] ?? 0) ?>' ? 'selected' : '') + '>' + c.name + '</option>').join('');
                loadSections();
            });
    }
    function loadSections() {
        const cid = classSel.value;
        if (!cid) { sectionSel.innerHTML = '<option value="0">— Select —</option>'; sectionSel.disabled = true; return; }
        fetch('<?= url('admin/ajax-classes.php?class_id=') ?>' + cid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(d => {
                sectionSel.disabled = false;
                sectionSel.innerHTML = '<option value="0">— Select —</option>' + d.sections.map(s => '<option value="' + s.id + '" ' + (s.id == '<?= (int)($target['section_id'] ?? 0) ?>' ? 'selected' : '') + '>' + s.name + '</option>').join('');
            });
    }
    roleSel.addEventListener('change', toggleClass);
    schoolSel.addEventListener('change', loadClasses);
    classSel.addEventListener('change', loadSections);
    toggleClass();
</script>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
