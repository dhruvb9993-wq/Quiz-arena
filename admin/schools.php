<?php
/** QuizArena — Super Admin: manage schools */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$status = get('status', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND (s.name LIKE ? OR s.code LIKE ? OR s.email LIKE ? OR s.contact_person LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($status !== '') { $where .= " AND s.status = ?"; $params[] = $status; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_schools s $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'admin/schools.php', ['q' => $q, 'status' => $status]);

$rows = dball(
    "SELECT s.*, pl.name AS plan_name, pl.type AS plan_type,
            (SELECT COUNT(*) FROM qa_users u WHERE u.school_id = s.id AND u.role = 'student') AS students,
            (SELECT COUNT(*) FROM qa_users u WHERE u.school_id = s.id AND u.role = 'teacher') AS teachers,
            (SELECT COUNT(*) FROM qa_quizzes z WHERE z.school_id = s.id) AS quizzes
       FROM qa_schools s
       LEFT JOIN qa_subscription_plans pl ON pl.id = s.plan_id
       $where
      ORDER BY s.id DESC LIMIT $per OFFSET $off", $params
);

if (get('export') === 'csv') {
    $h = ['ID', 'School Name', 'Code', 'Contact Person', 'Mobile', 'Email', 'Plan', 'Subscription Start', 'Subscription Expiry', 'Status', 'Students', 'Teachers', 'Quizzes', 'Created'];
    $data = array_map(fn($r) => [$r['id'], $r['name'], $r['code'], $r['contact_person'], $r['mobile'], $r['email'], $r['plan_name'], $r['subscription_start'], $r['subscription_expiry'], $r['status'], $r['students'], $r['teachers'], $r['quizzes'], $r['created_at']], $rows);
    export_csv('schools.csv', $h, $data);
}
if (get('export') === 'xlsx') {
    $h = ['ID', 'School Name', 'Code', 'Contact Person', 'Mobile', 'Email', 'Plan', 'Subscription Start', 'Subscription Expiry', 'Status', 'Students', 'Teachers', 'Quizzes', 'Created'];
    $data = array_map(fn($r) => [$r['id'], $r['name'], $r['code'], $r['contact_person'], $r['mobile'], $r['email'], $r['plan_name'], $r['subscription_start'], $r['subscription_expiry'], $r['status'], $r['students'], $r['teachers'], $r['quizzes'], $r['created_at']], $rows);
    export_xlsx('schools.xlsx', $h, $data);
}

$title = 'Schools';
$active = 'schools';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:220px" name="q" placeholder="Search name, code, email…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:150px" name="status">
            <option value="">All statuses</option>
            <?php foreach (['active', 'suspended', 'expired'] as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        <a class="btn btn-sm btn-outline-success" href="?export=xlsx&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a class="btn btn-sm btn-primary" href="<?= url('admin/school-edit.php') ?>"><i class="bi bi-plus-lg"></i> Add School</a>
    </div>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead>
            <tr>
                <th>School</th><th>Contact</th><th>Plan</th><th>Subscription</th><th class="text-center">Users</th><th class="text-center">Quizzes</th><th>Status</th><th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($logo = school_logo_url($r['logo'])): ?>
                                <img src="<?= e($logo) ?>" width="36" height="36" class="rounded object-fit-cover border" alt="">
                            <?php else: ?><span class="icon-tile tile-indigo" style="width:36px;height:36px;font-size:.9rem"><i class="bi bi-building"></i></span><?php endif; ?>
                            <div>
                                <div class="fw-semibold"><?= e($r['name']) ?></div>
                                <div class="fs-8 text-muted"><b><?= e($r['code']) ?></b> · #<?= (int) $r['id'] ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="small">
                        <div><?= e($r['contact_person'] ?? '—') ?></div>
                        <div class="text-muted fs-8"><?= e($r['mobile'] ?? '') ?><?= $r['email'] ? ' · ' . e($r['email']) : '' ?></div>
                    </td>
                    <td class="small"><?= e($r['plan_name'] ?? '—') ?></td>
                    <td class="small">
                        <?php if ($r['subscription_expiry']): ?>
                            <div>to <?= nice_date($r['subscription_expiry']) ?></div>
                            <div class="fs-8 <?= strtotime($r['subscription_expiry']) < time() ? 'text-danger' : 'text-muted' ?>">
                                <?= strtotime($r['subscription_expiry']) < time() ? 'Expired' : 'Valid' ?>
                            </div>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center small"><?= (int) $r['students'] ?> S / <?= (int) $r['teachers'] ?> T</td>
                    <td class="text-center small"><?= (int) $r['quizzes'] ?></td>
                    <td>
                        <?php
                        $badges = ['active' => 'badge-soft-success', 'suspended' => 'badge-soft-warning', 'expired' => 'badge-soft-danger'];
                        ?><span class="badge <?= $badges[$r['status']] ?? 'badge-soft-secondary' ?>"><?= ucfirst(e($r['status'])) ?></span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= url('admin/school-edit.php?id=' . $r['id']) ?>"><i class="bi bi-pencil"></i></a>
                        <a class="btn btn-sm btn-light" href="<?= url('admin/user-edit.php?school_id=' . $r['id']) ?>" title="Manage users"><i class="bi bi-people"></i></a>
                        <?php if ($r['status'] === 'active'): ?>
                            <a class="btn btn-sm btn-light text-warning" data-confirm="Suspend this school? All its users will lose access." href="<?= url('admin/school-edit.php?action=suspend&id=' . $r['id']) ?>"><i class="bi bi-pause-circle"></i></a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-light text-success" data-confirm="Activate this school?" href="<?= url('admin/school-edit.php?action=activate&id=' . $r['id']) ?>"><i class="bi bi-play-circle"></i></a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-light text-danger" data-confirm="Delete this school and ALL its data? This cannot be undone." href="<?= url('admin/school-edit.php?action=delete&id=' . $r['id']) ?>"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No schools found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
