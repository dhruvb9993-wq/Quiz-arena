<?php
/** QuizArena — Super Admin: all wallets + add/deduct coins */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

/* Adjust coins */
if (is_post() && post('action', '') === 'adjust') {
    csrf_check();
    $target_uid = (int) post('user_id', 0);
    $delta = (int) post('amount', 0);
    $reason = trim((string) post('reason', ''));
    $target = dbrow("SELECT * FROM qa_users WHERE id = ?", [$target_uid]);
    if (!$target) flash('danger', 'User not found.');
    elseif ($delta === 0) flash('danger', 'Amount must not be zero.');
    else {
        $res = admin_adjust_coins($target_uid, $delta, $reason);
        flash($res['ok'] ? 'success' : 'danger', $res['msg']);
    }
    redirect('admin/wallets.php?user_id=' . $target_uid);
}

$q = trim((string) get('q', ''));
$school_id = (int) get('school_id', 0);
$page = (int) get('p', 1);

$where = "WHERE u.role = 'student'";
$params = [];
if ($q !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($school_id) { $where .= " AND u.school_id = ?"; $params[] = $school_id; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_users u $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'admin/wallets.php', ['q' => $q, 'school_id' => $school_id]);

$rows = dball(
    "SELECT u.id, u.full_name, u.username, u.email, u.school_id, s.name AS school_name,
            COALESCE(w.balance, 0) AS balance, w.updated_at AS wallet_updated,
            (SELECT COUNT(*) FROM qa_wallet_transactions t WHERE t.user_id = u.id) AS txns
       FROM qa_users u
       LEFT JOIN qa_schools s ON s.id = u.school_id
       LEFT JOIN qa_wallets w ON w.user_id = u.id
       $where ORDER BY balance DESC LIMIT $per OFFSET $off", $params
);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");
$focus_uid = (int) get('user_id', 0);
$focus = $focus_uid ? dbrow("SELECT * FROM qa_users WHERE id = ?", [$focus_uid]) : null;

if (get('export') === 'csv') {
    export_csv('wallets.csv', ['User', 'Username', 'Email', 'School', 'Balance', 'Transactions'],
        array_map(fn($r) => [$r['full_name'], '@' . $r['username'], $r['email'], $r['school_name'] ?? '', $r['balance'], $r['txns']], $rows));
}

$title = 'Wallets';
$active = 'wallets';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:220px" name="q" placeholder="Search by name, username…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:180px" name="school_id">
            <option value="0">All schools</option>
            <?php foreach ($schools as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 26)) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
    <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&school_id=<?= $school_id ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
</div>

<?php if ($focus): ?>
    <div class="dash-card mb-3">
        <div class="dash-card-header">
            <h5 class="dash-card-title">Adjust Coins — <?= e($focus['full_name']) ?> (@<?= e($focus['username']) ?>)</h5>
            <span class="badge badge-soft-warning fs-6"><i class="bi bi-coin"></i> Current balance: <?= fmt_coin(wallet_balance($focus['id'])) ?></span>
        </div>
        <div class="dash-card-body">
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="user_id" value="<?= (int) $focus['id'] ?>">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Amount (+ add / − deduct)</label>
                    <input class="form-control" type="number" name="amount" placeholder="e.g. 100 or -50" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1">Reason</label>
                    <input class="form-control" name="reason" placeholder="e.g. Correction, prize, penalty">
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100"><i class="bi bi-coin"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>User</th><th>School</th><th class="text-end">Balance</th><th class="text-end">Transactions</th><th class="text-end">Last Updated</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="rounded-circle object-fit-cover" width="32" height="32" src="<?= e(avatar_url($r)) ?>" alt="">
                            <div><div class="fw-semibold small"><?= e($r['full_name']) ?></div><div class="fs-8 text-muted">@<?= e($r['username']) ?> · <?= e($r['email']) ?></div></div>
                        </div>
                    </td>
                    <td class="small"><?= e(mb_substr($r['school_name'] ?? '—', 0, 22)) ?></td>
                    <td class="text-end"><span class="badge badge-soft-warning fs-6"><i class="bi bi-coin"></i> <?= fmt_coin($r['balance']) ?></span></td>
                    <td class="text-end"><?= (int) $r['txns'] ?></td>
                    <td class="small text-muted"><?= $r['wallet_updated'] ? time_ago($r['wallet_updated']) : '—' ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light" href="?user_id=<?= (int) $r['id'] ?>"><i class="bi bi-coin"></i> Adjust</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No wallets found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
