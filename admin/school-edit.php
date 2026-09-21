<?php
/** QuizArena — Super Admin: add/edit/activate/suspend/delete school */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$id = (int) get('id', 0);
$action = get('action', '');

/* Quick actions (suspend / activate / delete) */
if ($action && $id) {
    csrf_check();
    $school = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$id]);
    if (!$school) { flash('danger', 'School not found.'); redirect('admin/schools.php'); }

    if ($action === 'suspend') {
        dbq("UPDATE qa_schools SET status = 'suspended' WHERE id = ?", [$id]);
        dbq("UPDATE qa_users SET status = 'suspended' WHERE school_id = ? AND role IN ('school_admin','teacher','student')", [$id]);
        audit('school_suspend', 'Suspended school #' . $id . ' (' . $school['name'] . ')');
        flash('success', 'School suspended. All its users are now blocked.');
    } elseif ($action === 'activate') {
        dbq("UPDATE qa_schools SET status = 'active' WHERE id = ?", [$id]);
        dbq("UPDATE qa_users SET status = 'active' WHERE school_id = ? AND role IN ('school_admin','teacher','student') AND status = 'suspended'", [$id]);
        audit('school_activate', 'Activated school #' . $id . ' (' . $school['name'] . ')');
        flash('success', 'School activated.');
    } elseif ($action === 'delete') {
        db_begin();
        try {
            foreach ([
                'qa_user_answers' => "DELETE FROM qa_user_answers WHERE attempt_id IN (SELECT id FROM qa_quiz_attempts WHERE school_id = ?)",
                'qa_quiz_attempts' => "DELETE FROM qa_quiz_attempts WHERE school_id = ?",
                'qa_quiz_rewards' => "DELETE FROM qa_quiz_rewards WHERE school_id = ?",
                'qa_certificates' => "DELETE FROM qa_certificates WHERE school_id = ?",
                'qa_leaderboard_stats' => "DELETE FROM qa_leaderboard_stats WHERE school_id = ?",
                'qa_wallet_transactions' => "DELETE FROM qa_wallet_transactions WHERE school_id = ?",
                'qa_wallets' => "DELETE FROM qa_wallets WHERE school_id = ?",
                'qa_quiz_assignments' => "DELETE FROM qa_quiz_assignments WHERE school_id = ?",
                'qa_questions' => "DELETE FROM qa_questions WHERE school_id = ?",
                'qa_answer_options' => "DELETE FROM qa_answer_options WHERE question_id NOT IN (SELECT id FROM qa_questions)",
                'qa_quizzes' => "DELETE FROM qa_quizzes WHERE school_id = ?",
                'qa_sections' => "DELETE FROM qa_sections WHERE school_id = ?",
                'qa_classes' => "DELETE FROM qa_classes WHERE school_id = ?",
                'qa_notifications' => "DELETE FROM qa_notifications WHERE school_id = ?",
                'qa_school_subscriptions' => "DELETE FROM qa_school_subscriptions WHERE school_id = ?",
                'qa_invoices' => "DELETE FROM qa_invoices WHERE school_id = ?",
                'qa_users' => "DELETE FROM qa_users WHERE school_id = ?",
            ] as $sql) { dbq($sql, [$id]); }
            dbq("DELETE FROM qa_schools WHERE id = ?", [$id]);
            db_commit();
            delete_upload($school['logo']);
            audit('school_delete', 'Deleted school #' . $id . ' (' . $school['name'] . ') with all data');
            flash('success', 'School and all related data deleted.');
        } catch (Throwable $e) {
            db_rollback();
            flash('danger', 'Could not delete school: ' . $e->getMessage());
        }
        redirect('admin/schools.php');
    }
    redirect('admin/schools.php');
}

/* Add / edit */
$school = $id ? dbrow("SELECT * FROM qa_schools WHERE id = ?", [$id]) : null;
if ($id && !$school) { flash('danger', 'School not found.'); redirect('admin/schools.php'); }

$plans = dball("SELECT * FROM qa_subscription_plans WHERE status = 'active' ORDER BY price");

$error = null;
if (is_post()) {
    csrf_check();
    $data = [
        'name' => trim((string) post('name', '')),
        'code' => strtoupper(trim((string) post('code', ''))),
        'address' => trim((string) post('address', '')),
        'contact_person' => trim((string) post('contact_person', '')),
        'mobile' => trim((string) post('mobile', '')),
        'email' => trim((string) post('email', '')),
        'plan_id' => (int) post('plan_id', 0) ?: null,
        'subscription_start' => post('subscription_start', '') ?: null,
        'subscription_expiry' => post('subscription_expiry', '') ?: null,
        'status' => in_array(post('status', 'active'), ['active', 'suspended', 'expired'], true) ? post('status', 'active') : 'active',
    ];

    if (mb_strlen($data['name']) < 3) $error = 'School name is required (min 3 characters).';
    elseif (!preg_match('/^[A-Z0-9_-]{4,20}$/', $data['code'])) $error = 'School code must be 4–20 characters (A-Z, 0-9, - or _).';
    elseif ($data['email'] !== '' && !is_valid_email($data['email'])) $error = 'Please enter a valid email.';
    elseif (dbval("SELECT COUNT(*) FROM qa_schools WHERE code = ? AND id != ?", [$data['code'], $id])) $error = 'That school code is already in use.';

    if (!$error) {
        if ($id) {
            dbq("UPDATE qa_schools SET name=?, code=?, address=?, contact_person=?, mobile=?, email=?, plan_id=?, subscription_start=?, subscription_expiry=?, status=?, updated_at=NOW() WHERE id=?",
                [$data['name'], $data['code'], $data['address'], $data['contact_person'], $data['mobile'], $data['email'], $data['plan_id'], $data['subscription_start'], $data['subscription_expiry'], $data['status'], $id]);
            audit('school_update', 'Updated school #' . $id);
            flash('success', 'School updated.');
        } else {
            dbq("INSERT INTO qa_schools (name, code, address, contact_person, mobile, email, plan_id, subscription_start, subscription_expiry, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                [$data['name'], $data['code'], $data['address'], $data['contact_person'], $data['mobile'], $data['email'], $data['plan_id'], $data['subscription_start'], $data['subscription_expiry'], $data['status']]);
            $new_id = db_id();
            audit('school_create', 'Created school #' . $new_id . ' (' . $data['name'] . ', code ' . $data['code'] . ')');
            flash('success', 'School created! Now add a School Admin account from the Users page.');
            redirect('admin/user-edit.php?school_id=' . $new_id . '&role=school_admin');
        }
        redirect('admin/schools.php');
    }
}

// Logo upload (separate POST)
if (is_post() && post('upload_logo', '') === '1') {
    csrf_check();
    $res = handle_upload($_FILES['logo'] ?? [], 'logos', ['jpg', 'jpeg', 'png', 'webp'], 1024);
    if ($res['ok']) {
        delete_upload($school['logo'] ?? null);
        dbq("UPDATE qa_schools SET logo = ? WHERE id = ?", [$res['path'], $id]);
        audit('school_logo', 'Uploaded logo for school #' . $id);
        flash('success', 'Logo uploaded.');
    } else {
        flash('danger', $res['error']);
    }
    redirect('admin/school-edit.php?id=' . $id);
}

$title = $id ? 'Edit School' : 'Add School';
$active = 'schools';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title"><?= $id ? 'Edit School' : 'Add New School' ?></h5></div>
            <div class="dash-card-body">
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">School Name *</label>
                            <input class="form-control" name="name" value="<?= e($school['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">School Code *</label>
                            <input class="form-control text-uppercase" name="code" value="<?= e($school['code'] ?? '') ?>" placeholder="ABCD1234" required>
                            <div class="form-text">Students join using this code.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Address</label>
                            <textarea class="form-control" name="address" rows="2"><?= e($school['address'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Person</label>
                            <input class="form-control" name="contact_person" value="<?= e($school['contact_person'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Number</label>
                            <input class="form-control" name="mobile" value="<?= e($school['mobile'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Email</label>
                            <input class="form-control" type="email" name="email" value="<?= e($school['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Subscription Plan</label>
                            <select class="form-select" name="plan_id">
                                <option value="0">— No plan —</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>" <?= ($school['plan_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Subscription Start</label>
                            <input class="form-control" type="date" name="subscription_start" value="<?= e($school['subscription_start'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Subscription Expiry</label>
                            <input class="form-control" type="date" name="subscription_expiry" value="<?= e($school['subscription_expiry'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'expired' => 'Expired'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($school['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary px-4"><?= $id ? 'Save Changes' : 'Create School' ?></button>
                            <a class="btn btn-light" href="<?= url('admin/schools.php') ?>">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($id): ?>
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">School Logo</h5></div>
                <div class="dash-card-body text-center">
                    <?php if ($logo = school_logo_url($school['logo'])): ?>
                        <img src="<?= e($logo) ?>" class="img-thumbnail mb-3" style="max-height:120px" alt="">
                    <?php else: ?>
                        <div class="text-muted py-3"><i class="bi bi-image display-5"></i><p class="small mt-2">No logo uploaded</p></div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="upload_logo" value="1">
                        <input class="form-control form-control-sm mb-2" type="file" name="logo" accept="image/*" required>
                        <button class="btn btn-sm btn-outline-primary w-100">Upload Logo</button>
                    </form>
                </div>
            </div>
            <div class="dash-card">
                <div class="dash-card-body">
                    <h6 class="fw-bold"><i class="bi bi-share"></i> Student join code</h6>
                    <p class="small text-muted">Share this code with students so they can register under this school.</p>
                    <div class="input-group">
                        <input class="form-control" value="<?= e($school['code']) ?>" readonly>
                        <button class="btn btn-outline-primary" data-copy="<?= e($school['code']) ?>"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="dash-card">
                <div class="dash-card-body">
                    <h6 class="fw-bold"><i class="bi bi-info-circle"></i> Next steps</h6>
                    <ol class="small text-muted mb-0">
                        <li>Create the school with a unique code.</li>
                        <li>Add a <b>School Admin</b> account for the school.</li>
                        <li>School Admin adds classes, teachers and students.</li>
                        <li>Teachers create and publish quizzes.</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
