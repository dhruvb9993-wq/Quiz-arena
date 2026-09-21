<?php
/** QuizArena — Super Admin: all users */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$role = get('role', '');
$school_id = (int) get('school_id', 0);
$status = get('status', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($role !== '') { $where .= " AND u.role = ?"; $params[] = $role; }
if ($school_id) { $where .= " AND u.school_id = ?"; $params[] = $school_id; }
if ($status !== '') { $where .= " AND u.status = ?"; $params[] = $status; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_users u $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 20, $page, 'admin/users.php', ['q' => $q, 'role' => $role, 'school_id' => $school_id, 'status' => $status]);

$rows = dball(
    "SELECT u.*, s.name AS school_name, c.name AS class_name, w.balance AS wallet_balance
       FROM qa_users u
       LEFT JOIN qa_schools s ON s.id = u.school_id
       LEFT JOIN qa_classes c ON c.id = u.class_id
       LEFT JOIN qa_wallets w ON w.user_id = u.id
       $where
      ORDER BY u.id DESC LIMIT $per OFFSET $off", $params
);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");

if (get('export') === 'csv') {
    $h = ['ID', 'Name', 'Username', 'Email', 'Mobile', 'Role', 'School', 'Class', 'Status', 'Wallet Balance', 'Created'];
    $d = array_map(fn($r) => [$r['id'], $r['full_name'], '@' . $r['username'], $r['email'], $r['mobile'], $r['role'], $r['school_name'] ?? '', $r['class_name'] ?? '', $r['status'], $r['wallet_balance'] ?? 0, $r['created_at']], $rows);
    export_csv('users.csv', $h, $d);
}

$title = 'All Users';
$active = 'users';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:220px" name="q" placeholder="Search name, username, email…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:140px" name="role">
            <option value="">All roles</option>
            <?php foreach (['superadmin' => 'Super Admin', 'school_admin' => 'School Admin', 'teacher' => 'Teacher', 'student' => 'Student'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $role === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:180px" name="school_id">
            <option value="0">All schools</option>
            <?php foreach ($schools as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 26)) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:130px" name="status">
            <option value="">All statuses</option>
            <?php foreach (['active', 'suspended', 'pending'] as $st): ?><option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&role=<?= urlencode($role) ?>&school_id=<?= $school_id ?>&status=<?= urlencode($status) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        <a class="btn btn-sm btn-primary" href="<?= url('admin/user-edit.php') ?>"><i class="bi bi-plus-lg"></i> Add User</a>
    </div>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead>
            <tr><th>User</th><th>Role</th><th>School</th><th>Class</th><th class="text-end">Wallet</th><th>Status</th><th>Last Login</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="34" height="34" src="<?= e(avatar_url($r)) ?>" alt="">
                            <div>
                                <div class="fw-semibold"><?= e($r['full_name']) ?></div>
                                <div class="fs-8 text-muted">@<?= e($r['username']) ?> · <?= e($r['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge badge-soft-primary"><?= e(role_label($r['role'])) ?></span></td>
                    <td class="small"><?= e($r['school_name'] ?? '—') ?></td>
                    <td class="small"><?= e($r['class_name'] ?? '—') ?></td>
                    <td class="text-end"><span class="badge badge-soft-warning"><i class="bi bi-coin"></i> <?= fmt_coin($r['wallet_balance'] ?? 0) ?></span></td>
                    <td><?php $b = ['active' => 'badge-soft-success', 'suspended' => 'badge-soft-danger', 'pending' => 'badge-soft-warning']; ?><span class="badge <?= $b[$r['status']] ?? 'badge-soft-secondary' ?>"><?= ucfirst(e($r['status'])) ?></span></td>
                    <td class="small text-muted"><?= $r['last_login'] ? time_ago($r['last_login']) : '—' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= url('admin/user-edit.php?id=' . $r['id']) ?>"><i class="bi bi-pencil"></i></a>
                        <a class="btn btn-sm btn-light text-warning" href="<?= url('admin/wallets.php?user_id=' . $r['id']) ?>" title="Adjust coins"><i class="bi bi-coin"></i></a>
                        <?php if ($r['role'] !== 'superadmin'): ?>
                            <?php if ($r['status'] === 'active'): ?>
                                <a class="btn btn-sm btn-light text-warning" data-confirm="Suspend this user?" href="<?= url('admin/user-edit.php?action=suspend&id=' . $r['id']) ?>"><i class="bi bi-pause-circle"></i></a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-light text-success" href="<?= url('admin/user-edit.php?action=activate&id=' . $r['id']) ?>"><i class="bi bi-play-circle"></i></a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-light text-danger" data-confirm="Delete this user and all their data?" href="<?= url('admin/user-edit.php?action=delete&id=' . $r['id']) ?>"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No users found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
