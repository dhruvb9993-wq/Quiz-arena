<?php
/** QuizArena — School Admin: school profile + logo */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$school = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$sid]);

$error = null;
if (is_post()) {
    csrf_check();
    $action = post('action', '');
    if ($action === 'profile') {
        $d = [
            'name' => trim((string) post('name', '')),
            'address' => trim((string) post('address', '')),
            'contact_person' => trim((string) post('contact_person', '')),
            'mobile' => trim((string) post('mobile', '')),
            'email' => trim((string) post('email', '')),
        ];
        if (mb_strlen($d['name']) < 3) $error = 'School name is required.';
        elseif ($d['email'] !== '' && !is_valid_email($d['email'])) $error = 'Enter a valid email.';
        else {
            dbq("UPDATE qa_schools SET name=?, address=?, contact_person=?, mobile=?, email=? WHERE id=?", [$d['name'], $d['address'], $d['contact_person'], $d['mobile'], $d['email'], $sid]);
            audit('school_profile_update', 'Updated own school profile #' . $sid);
            flash('success', 'School profile updated.');
            redirect('school/profile.php');
        }
    } elseif ($action === 'logo') {
        $res = handle_upload($_FILES['logo'] ?? [], 'logos', ['jpg', 'jpeg', 'png', 'webp'], 1024);
        if ($res['ok']) {
            delete_upload($school['logo'] ?? null);
            dbq("UPDATE qa_schools SET logo = ? WHERE id = ?", [$res['path'], $sid]);
            audit('school_logo', 'Uploaded school logo #' . $sid);
            flash('success', 'School logo updated.');
        } else flash('danger', $res['error']);
        redirect('school/profile.php');
    }
}

$title = 'School Profile';
$active = 'profile';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="dash-card">
            <div class="dash-card-body text-center">
                <?php if ($logo = school_logo_url($school['logo'])): ?>
                    <img src="<?= e($logo) ?>" class="img-thumbnail mb-3" style="max-height:130px" alt="">
                <?php else: ?>
                    <div class="text-muted py-3"><i class="bi bi-building display-5"></i><p class="small mt-2">No logo uploaded</p></div>
                <?php endif; ?>
                <h5 class="fw-bold mb-0"><?= e($school['name']) ?></h5>
                <div class="text-muted small">Code: <b><?= e($school['code']) ?></b></div>
                <form method="post" enctype="multipart/form-data" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="logo">
                    <input class="form-control form-control-sm mb-2" type="file" name="logo" accept="image/*" required>
                    <button class="btn btn-sm btn-outline-primary w-100">Upload Logo</button>
                </form>
                <div class="input-group mt-3">
                    <input class="form-control form-control-sm" value="<?= e($school['code']) ?>" readonly>
                    <button class="btn btn-sm btn-outline-primary" data-copy="<?= e($school['code']) ?>"><i class="bi bi-clipboard"></i></button>
                </div>
                <div class="fs-8 text-muted mt-2">Share this code so students can join your school.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">School Information</h5></div>
            <div class="dash-card-body">
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">School Name *</label>
                            <input class="form-control" name="name" value="<?= e($school['name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">School Code</label>
                            <input class="form-control" value="<?= e($school['code']) ?>" readonly>
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
                            <label class="form-label small fw-semibold">Mobile</label>
                            <input class="form-control" name="mobile" value="<?= e($school['mobile'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Email</label>
                            <input class="form-control" type="email" name="email" value="<?= e($school['email'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary px-4">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
