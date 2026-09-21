<?php
/** QuizArena — School Admin: manage students */
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
            'class_id' => (int) post('class_id', 0) ?: null,
            'section_id' => (int) post('section_id', 0) ?: null,
            'password' => (string) post('password', ''),
        ];
        if (mb_strlen($d['full_name']) < 3) $error = 'Full name is required.';
        elseif (!username_valid($d['username'])) $error = 'Username must be 3–30 characters.';
        elseif ($d['email'] !== '' && !is_valid_email($d['email'])) $error = 'Enter a valid email.';
        elseif ($d['email'] !== '' && dbval("SELECT COUNT(*) FROM qa_users WHERE email = ?", [$d['email']])) $error = 'Email already registered.';
        elseif (dbval("SELECT COUNT(*) FROM qa_users WHERE username = ?", [$d['username']])) $error = 'Username already taken.';
        elseif (strlen($d['password']) < 8) $error = 'Password must be at least 8 characters.';
        if (!$error) {
            dbq("INSERT INTO qa_users (school_id, role, full_name, username, email, password_hash, mobile, class_id, section_id, status, created_at, updated_at)
                 VALUES (?, 'student', ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?,0), NULLIF(?,0), 'active', NOW(), NOW())",
                [$sid, $d['full_name'], $d['username'], $d['email'], password_hash($d['password'], PASSWORD_DEFAULT), $d['mobile'], $d['class_id'], $d['section_id']]);
            $stuid = db_id();
            ensure_wallet($stuid, $sid);
            grant_signup_bonus($stuid);
            audit('student_add', 'Added student @' . $d['username'] . ' to school #' . $sid);
            flash('success', 'Student added.');
            redirect('school/students.php');
        }
    } elseif ($action === 'edit') {
        $stid = (int) post('id', 0);
        $d = [
            'full_name' => trim((string) post('full_name', '')),
            'mobile' => trim((string) post('mobile', '')),
            'email' => trim((string) post('email', '')),
            'class_id' => (int) post('class_id', 0) ?: null,
            'section_id' => (int) post('section_id', 0) ?: null,
        ];
        if (mb_strlen($d['full_name']) < 3) $error = 'Full name is required.';
        elseif ($d['email'] !== '' && dbval("SELECT COUNT(*) FROM qa_users WHERE email = ? AND id != ?", [$d['email'], $stid])) $error = 'Email already registered.';
        if (!$error) {
            dbq("UPDATE qa_users SET full_name=?, mobile=?, email=?, class_id=?, section_id=?, updated_at=NOW() WHERE id=? AND school_id=?",
                [$d['full_name'], $d['mobile'], $d['email'], $d['class_id'], $d['section_id'], $stid, $sid]);
            audit('student_update', 'Updated student #' . $stid);
            flash('success', 'Student updated.');
            redirect('school/students.php');
        }
    } elseif ($action === 'toggle') {
        $stid = (int) post('id', 0);
        $s = dbrow("SELECT * FROM qa_users WHERE id = ? AND school_id = ?", [$stid, $sid]);
        if ($s) { $new = $s['status'] === 'active' ? 'suspended' : 'active'; dbq("UPDATE qa_users SET status=? WHERE id=?", [$new, $stid]); flash('success', 'Student ' . ($new === 'active' ? 'activated' : 'suspended') . '.'); }
        redirect('school/students.php');
    } elseif ($action === 'delete') {
        $stid = (int) post('id', 0);
        db_begin();
        try {
            foreach ([
                'qa_user_answers' => "DELETE FROM qa_user_answers WHERE attempt_id IN (SELECT id FROM qa_quiz_attempts WHERE user_id = ?)",
                'qa_quiz_attempts' => "DELETE FROM qa_quiz_attempts WHERE user_id = ?",
                'qa_certificates' => "DELETE FROM qa_certificates WHERE user_id = ?",
                'qa_leaderboard_stats' => "DELETE FROM qa_leaderboard_stats WHERE user_id = ?",
                'qa_user_rank_history' => "DELETE FROM qa_user_rank_history WHERE user_id = ?",
                'qa_wallet_transactions' => "DELETE FROM qa_wallet_transactions WHERE user_id = ? OR receiver_user_id = ? OR sender_user_id = ?",
                'qa_wallets' => "DELETE FROM qa_wallets WHERE user_id = ?",
                'qa_notifications' => "DELETE FROM qa_notifications WHERE user_id = ?",
            ] as $sql) { dbq($sql, [$stid, $stid, $stid]); }
            dbq("DELETE FROM qa_users WHERE id = ? AND school_id = ?", [$stid, $sid]);
            db_commit();
            audit('student_delete', 'Deleted student #' . $stid);
            flash('success', 'Student deleted.');
        } catch (Throwable $e) { db_rollback(); flash('danger', 'Delete failed.'); }
        redirect('school/students.php');
    }
}

$q = trim((string) get('q', ''));
$class_id = (int) get('class_id', 0);
$page = (int) get('p', 1);

$where = "WHERE u.school_id = ? AND u.role = 'student'";
$where_count = "WHERE school_id = ? AND role = 'student'";
$params = [$sid];
if ($q !== '') { $where .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ? OR mobile LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($class_id) { $where .= " AND class_id = ?"; $params[] = $class_id; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_users $where_count", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'school/students.php', ['q' => $q, 'class_id' => $class_id]);

$students = dball(
    "SELECT u.*, c.name AS class_name, sec.name AS section_name, w.balance AS wallet_balance
       FROM qa_users u
       LEFT JOIN qa_classes c ON c.id = u.class_id
       LEFT JOIN qa_sections sec ON sec.id = u.section_id
       LEFT JOIN qa_wallets w ON w.user_id = u.id
       $where ORDER BY u.id DESC LIMIT $per OFFSET $off", $params
);

$classes = dball("SELECT * FROM qa_classes WHERE school_id = ? ORDER BY name", [$sid]);
$sections = dball("SELECT * FROM qa_sections WHERE school_id = ? ORDER BY class_id, name", [$sid]);

if (get('export') === 'csv') {
    export_csv('students.csv',
        ['Name', 'Username', 'Email', 'Mobile', 'Class', 'Section', 'Status', 'Wallet Coins', 'Joined'],
        array_map(fn($s) => [$s['full_name'], '@' . $s['username'], $s['email'], $s['mobile'], $s['class_name'] ?? '', $s['section_name'] ?? '', $s['status'], $s['wallet_balance'] ?? 0, $s['created_at']], $students));
}

$title = 'Students';
$active = 'students';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:220px" name="q" placeholder="Search students…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:150px" name="class_id">
            <option value="0">All classes</option>
            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $class_id === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&class_id=<?= $class_id ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('school/import-students.php') ?>"><i class="bi bi-upload"></i> Import CSV</a>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addStudent"><i class="bi bi-plus-lg"></i> Add Student</button>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Student</th><th>Class / Section</th><th>Mobile</th><th class="text-end">Wallet</th><th>Status</th><th>Joined</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="34" height="34" src="<?= e(avatar_url($s)) ?>" alt="">
                            <div>
                                <div class="fw-semibold"><?= e($s['full_name']) ?></div>
                                <div class="fs-8 text-muted">@<?= e($s['username']) ?> · <?= e($s['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="small"><?= e($s['class_name'] ?? '—') ?><?= $s['section_name'] ? ' / ' . e($s['section_name']) : '' ?></td>
                    <td class="small"><?= e($s['mobile'] ?? '—') ?></td>
                    <td class="text-end"><span class="badge badge-soft-warning"><i class="bi bi-coin"></i> <?= fmt_coin($s['wallet_balance'] ?? 0) ?></span></td>
                    <td><span class="badge <?= $s['status'] === 'active' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= ucfirst(e($s['status'])) ?></span></td>
                    <td class="small text-muted"><?= nice_date($s['created_at']) ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#editStudent<?= $s['id'] ?>"><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button class="btn btn-sm btn-light <?= $s['status'] === 'active' ? 'text-warning' : 'text-success' ?>"><i class="bi bi-<?= $s['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i></button>
                        </form>
                        <form method="post" class="d-inline" data-confirm="Delete this student and ALL their data?"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <div class="modal fade" id="editStudent<?= $s['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form class="modal-content" method="post"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <div class="modal-header py-2"><h6 class="modal-title">Edit — <?= e($s['full_name']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-12"><label class="form-label small">Full Name</label><input class="form-control" name="full_name" value="<?= e($s['full_name']) ?>" required></div>
                                    <div class="col-6"><label class="form-label small">Mobile</label><input class="form-control" name="mobile" value="<?= e($s['mobile'] ?? '') ?>"></div>
                                    <div class="col-6"><label class="form-label small">Email</label><input class="form-control" type="email" name="email" value="<?= e($s['email']) ?>"></div>
                                    <div class="col-6">
                                        <label class="form-label small">Class</label>
                                        <select class="form-select" name="class_id" onchange="editSecs(this)">
                                            <option value="0">— None —</option>
                                            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $s['class_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Section</label>
                                        <select class="form-select" name="section_id">
                                            <option value="0">— None —</option>
                                            <?php foreach ($sections as $sec): if ((int) $sec['class_id'] !== (int) $s['class_id']) continue; ?>
                                                <option value="<?= (int) $sec['id'] ?>" <?= (int) $s['section_id'] === (int) $sec['id'] ? 'selected' : '' ?>><?= e($sec['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer py-2"><button class="btn btn-sm btn-primary">Save</button></div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$students): ?><tr><td colspan="7" class="text-center text-muted py-4">No students found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>

<div class="modal fade" id="addStudent" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h5 class="modal-title">Add Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label small fw-semibold">Full Name</label><input class="form-control" name="full_name" required></div>
                    <div class="col-6"><label class="form-label small fw-semibold">Username</label><input class="form-control" data-username name="username" placeholder="@student" required></div>
                    <div class="col-6"><label class="form-label small fw-semibold">Mobile</label><input class="form-control" name="mobile"></div>
                    <div class="col-12"><label class="form-label small fw-semibold">Email</label><input class="form-control" type="email" name="email"></div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Class</label>
                        <select class="form-select" name="class_id" onchange="newSecs(this)">
                            <option value="0">— None —</option>
                            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Section</label>
                        <select class="form-select" name="section_id" id="newSection"><option value="0">— None —</option></select>
                    </div>
                    <div class="col-12"><label class="form-label small fw-semibold">Temporary Password</label><input class="form-control" type="password" name="password" placeholder="Min 8 characters" required></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Add Student</button></div>
        </form>
    </div>
</div>

<script>
    const ALL_SECTIONS = <?= json_encode(array_map(fn($s) => ['id' => (int) $s['id'], 'class_id' => (int) $s['class_id'], 'name' => $s['name']], $sections)) ?>;
    function fillSecs(sel, target) {
        const cid = sel.value;
        const opts = '<option value="0">— None —</option>' + ALL_SECTIONS.filter(s => s.class_id == cid).map(s => '<option value="' + s.id + '">' + s.name + '</option>').join('');
        target.innerHTML = opts;
    }
    function newSecs(sel) { fillSecs(sel, document.getElementById('newSection')); }
    function editSecs(sel) { fillSecs(sel, sel.closest('.modal-body').querySelector('select[name="section_id"]')); }
</script>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
