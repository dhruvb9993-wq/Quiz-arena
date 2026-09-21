<?php
/** QuizArena — School Admin: manage teachers */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$error = null;
if (is_post()) {
    csrf_check();
    $action = post('action', '');
    if ($action === 'add') {
        $d = [
            'full_name' => trim((string) post('full_name', '')),
            'username' => clean_username(post('username', '')),
            'email' => trim((string) post('email', '')),
            'mobile' => trim((string) post('mobile', '')),
            'password' => (string) post('password', ''),
        ];
        if (mb_strlen($d['full_name']) < 3) $error = 'Full name is required.';
        elseif (!username_valid($d['username'])) $error = 'Username must be 3–30 characters (letters, numbers, dots, underscores).';
        elseif (!is_valid_email($d['email'])) $error = 'Enter a valid email.';
        elseif (strlen($d['password']) < 8) $error = 'Password must be at least 8 characters.';
        elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE username = ?", [$d['username']])) $error = 'Username already taken.';
        elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE email = ?", [$d['email']])) $error = 'Email already registered.';
        if (!$error) {
            dbq("INSERT INTO qa_users (school_id, role, full_name, username, email, password_hash, mobile, status, created_at, updated_at)
                 VALUES (?, 'teacher', ?, ?, ?, ?, ?, 'active', NOW(), NOW())",
                [$sid, $d['full_name'], $d['username'], $d['email'], password_hash($d['password'], PASSWORD_DEFAULT), $d['mobile']]);
            $tid = db_id();
            ensure_wallet($tid, $sid);
            audit('teacher_add', 'Added teacher @' . $d['username'] . ' to school #' . $sid);
            send_mail($d['email'], 'Your QuizArena teacher account',
                '<p>Hello ' . e($d['full_name']) . ',</p><p>Your teacher account has been created on QuizArena.</p>'
                . '<p><b>Username:</b> @' . e($d['username']) . '<br><b>Password:</b> ' . e($d['password']) . '</p>'
                . '<p>Login at: <a href="' . e(url('login.php')) . '">' . e(url('login.php')) . '</a></p><p>Please change your password after first login.</p>');
            flash('success', 'Teacher added. Login credentials sent to their email.');
            redirect('school/teachers.php');
        }
    } elseif ($action === 'reset_password') {
        $tid = (int) post('id', 0);
        $pw = (string) post('password', '');
        if (strlen($pw) < 8) $error = 'Password must be at least 8 characters.';
        else {
            dbq("UPDATE qa_users SET password_hash = ? WHERE id = ? AND school_id = ?", [password_hash($pw, PASSWORD_DEFAULT), $tid, $sid]);
            audit('teacher_password_reset', 'Reset password for teacher #' . $tid);
            flash('success', 'Password reset.');
            redirect('school/teachers.php');
        }
    } elseif ($action === 'toggle') {
        $tid = (int) post('id', 0);
        $t = dbrow("SELECT * FROM qa_users WHERE id = ? AND school_id = ?", [$tid, $sid]);
        if ($t) {
            $new = $t['status'] === 'active' ? 'suspended' : 'active';
            dbq("UPDATE qa_users SET status = ? WHERE id = ?", [$new, $tid]);
            flash('success', 'Teacher ' . ($new === 'active' ? 'activated' : 'suspended') . '.');
        }
        redirect('school/teachers.php');
    } elseif ($action === 'delete') {
        $tid = (int) post('id', 0);
        if (dbval("SELECT COUNT(*) FROM qa_quizzes WHERE created_by = ?", [$tid])) {
            flash('danger', 'This teacher has created quizzes. Delete their quizzes first.');
        } else {
            dbq("DELETE FROM qa_users WHERE id = ? AND school_id = ? AND role = 'teacher'", [$tid, $sid]);
            audit('teacher_delete', 'Deleted teacher #' . $tid);
            flash('success', 'Teacher deleted.');
        }
        redirect('school/teachers.php');
    }
}

$q = trim((string) get('q', ''));
$where = "WHERE u.school_id = ? AND u.role = 'teacher'";
$where_count = "WHERE school_id = ? AND role = 'teacher'";
$params = [$sid];
if ($q !== '') { $where .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; }

$teachers = dball(
    "SELECT u.*, (SELECT COUNT(*) FROM qa_quizzes q WHERE q.created_by = u.id) AS quizzes_created,
            (SELECT COUNT(*) FROM qa_quiz_attempts a JOIN qa_quizzes q ON q.id = a.quiz_id WHERE q.created_by = u.id AND a.status='submitted') AS attempts
       FROM qa_users u $where ORDER BY u.id DESC", $params
);

$title = 'Teachers';
$active = 'teachers';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2" method="get">
        <input class="form-control form-control-sm" style="width:220px" name="q" placeholder="Search teachers…" value="<?= e($q) ?>">
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTeacher"><i class="bi bi-plus-lg"></i> Add Teacher</button>
</div>

<?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Teacher</th><th>Mobile</th><th class="text-center">Quizzes</th><th class="text-center">Attempts</th><th>Status</th><th>Joined</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="34" height="34" src="<?= e(avatar_url($t)) ?>" alt="">
                            <div>
                                <div class="fw-semibold"><?= e($t['full_name']) ?></div>
                                <div class="fs-8 text-muted">@<?= e($t['username']) ?> · <?= e($t['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="small"><?= e($t['mobile'] ?? '—') ?></td>
                    <td class="text-center"><?= (int) $t['quizzes_created'] ?></td>
                    <td class="text-center"><?= (int) $t['attempts'] ?></td>
                    <td><span class="badge <?= $t['status'] === 'active' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= ucfirst(e($t['status'])) ?></span></td>
                    <td class="small text-muted"><?= nice_date($t['created_at']) ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#resetPw<?= $t['id'] ?>"><i class="bi bi-key"></i></button>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-sm btn-light <?= $t['status'] === 'active' ? 'text-warning' : 'text-success' ?>"><i class="bi bi-<?= $t['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i></button>
                        </form>
                        <form method="post" class="d-inline" data-confirm="Delete this teacher?"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <div class="modal fade" id="resetPw<?= $t['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <form class="modal-content" method="post"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <div class="modal-header py-2"><h6 class="modal-title">Reset password — <?= e($t['full_name']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <input class="form-control" type="password" name="password" placeholder="New password (min 8 chars)" required>
                            </div>
                            <div class="modal-footer py-2"><button class="btn btn-sm btn-primary">Reset</button></div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$teachers): ?><tr><td colspan="7" class="text-center text-muted py-4">No teachers yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addTeacher" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h5 class="modal-title">Add Teacher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Full Name</label>
                        <input class="form-control" name="full_name" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Username</label>
                        <input class="form-control" data-username name="username" placeholder="@teacher_name" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Mobile</label>
                        <input class="form-control" name="mobile">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Email</label>
                        <input class="form-control" type="email" name="email" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Temporary Password</label>
                        <input class="form-control" type="password" name="password" placeholder="Min 8 characters" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Add Teacher</button></div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
