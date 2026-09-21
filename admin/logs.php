<?php
/** QuizArena — Super Admin: audit logs */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$action = get('action', '');
$from = get('from', '');
$to = get('to', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND (l.details LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($action !== '') { $where .= " AND l.action = ?"; $params[] = $action; }
if ($from) { $where .= " AND DATE(l.created_at) >= ?"; $params[] = $from; }
if ($to) { $where .= " AND DATE(l.created_at) <= ?"; $params[] = $to; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_admin_logs l LEFT JOIN qa_users u ON u.id = l.user_id $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 25, $page, 'admin/logs.php', ['q' => $q, 'action' => $action, 'from' => $from, 'to' => $to]);

$rows = dball(
    "SELECT l.*, u.username, u.full_name
       FROM qa_admin_logs l
       LEFT JOIN qa_users u ON u.id = l.user_id
       $where ORDER BY l.id DESC LIMIT $per OFFSET $off", $params
);

$distinct_actions = dball("SELECT DISTINCT action FROM qa_admin_logs ORDER BY action");

if (get('export') === 'csv') {
    $all = dball(
        "SELECT l.*, u.username FROM qa_admin_logs l LEFT JOIN qa_users u ON u.id = l.user_id $where ORDER BY l.id DESC", $params
    );
    export_csv('audit-logs.csv', ['ID', 'User', 'Action', 'Details', 'IP', 'Date'],
        array_map(fn($r) => [$r['id'], '@' . ($r['username'] ?? 'system'), $r['action'], $r['details'], $r['ip_address'], $r['created_at']], $all));
}

$title = 'Audit Logs';
$active = 'logs';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search details or user…">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Action</label>
            <select class="form-select form-select-sm" name="action">
                <option value="">All actions</option>
                <?php foreach ($distinct_actions as $a): ?><option value="<?= e($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">From</label>
            <input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">To</label>
            <input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<div class="dash-card">
    <div class="dash-card-header">
        <h5 class="dash-card-title">Admin Activity Log</h5>
        <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&action=<?= urlencode($action) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
    </div>
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>#</th><th>User</th><th>Action</th><th>Details</th><th>IP</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $l): ?>
                <tr>
                    <td class="small text-muted"><?= (int) $l['id'] ?></td>
                    <td class="small"><?= $l['username'] ? '@' . e($l['username']) : '<span class="text-muted">system</span>' ?></td>
                    <td><span class="badge badge-soft-primary"><?= e($l['action']) ?></span></td>
                    <td class="small"><?= e($l['details'] ?? '—') ?></td>
                    <td class="small text-muted"><?= e($l['ip_address'] ?? '—') ?></td>
                    <td class="small text-muted text-nowrap"><?= nice_date($l['created_at'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No log entries found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
