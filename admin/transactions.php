<?php
/** QuizArena — Super Admin: all wallet transactions */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$school_id = (int) get('school_id', 0);
$type = get('type', '');
$category = get('category', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND (t.transaction_id LIKE ? OR t.sender_username LIKE ? OR t.receiver_username LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($school_id) { $where .= " AND t.school_id = ?"; $params[] = $school_id; }
if ($type !== '') { $where .= " AND t.type = ?"; $params[] = $type; }
if ($category !== '') { $where .= " AND t.category = ?"; $params[] = $category; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_wallet_transactions t JOIN qa_users u ON u.id = t.user_id $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 20, $page, 'admin/transactions.php', ['q' => $q, 'school_id' => $school_id, 'type' => $type, 'category' => $category]);

$rows = dball(
    "SELECT t.*, u.username AS user_username, u.full_name AS user_name, s.name AS school_name
       FROM qa_wallet_transactions t
       JOIN qa_users u ON u.id = t.user_id
       LEFT JOIN qa_schools s ON s.id = t.school_id
       $where ORDER BY t.id DESC LIMIT $per OFFSET $off", $params
);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");
$cats = [
    'signup_bonus', 'admin_add', 'admin_deduct', 'entry_fee', 'refund',
    'completion_bonus', 'passing_reward', 'rank_reward', 'transfer_sent', 'transfer_received', 'quiz_reward',
];

if (get('export') === 'csv') {
    $all = dball(
        "SELECT t.*, u.username AS user_username, u.full_name AS user_name, s.name AS school_name
           FROM qa_wallet_transactions t
           JOIN qa_users u ON u.id = t.user_id
           LEFT JOIN qa_schools s ON s.id = t.school_id
           $where ORDER BY t.id DESC", $params
    );
    export_csv('transactions.csv',
        ['Transaction ID', 'User', 'School', 'Type', 'Category', 'Amount', 'Balance After', 'Sender', 'Receiver', 'Status', 'Description', 'Date'],
        array_map(fn($r) => [$r['transaction_id'], '@' . $r['user_username'], $r['school_name'] ?? '', $r['type'], $r['category'], $r['amount'], $r['balance_after'], $r['sender_username'], $r['receiver_username'], $r['status'], $r['description'], $r['created_at']], $all));
}

$title = 'Wallet Transactions';
$active = 'transactions';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:200px" name="q" placeholder="Search user / txn id…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:170px" name="school_id">
            <option value="0">All schools</option>
            <?php foreach ($schools as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 24)) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:110px" name="type">
            <option value="">All types</option>
            <option value="credit" <?= $type === 'credit' ? 'selected' : '' ?>>Credit</option>
            <option value="debit" <?= $type === 'debit' ? 'selected' : '' ?>>Debit</option>
        </select>
        <select class="form-select form-select-sm" style="width:170px" name="category">
            <option value="">All categories</option>
            <?php foreach ($cats as $c): ?><option value="<?= $c ?>" <?= $category === $c ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $c)) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Filter</button>
    </form>
    <a class="btn btn-sm btn-outline-success" href="?export=csv&q=<?= urlencode($q) ?>&school_id=<?= $school_id ?>&type=<?= urlencode($type) ?>&category=<?= urlencode($category) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Transaction</th><th>User</th><th>School</th><th>Type</th><th>Category</th><th>Sender → Receiver</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td class="small text-muted"><?= e($t['transaction_id']) ?></td>
                    <td class="small"><?= e($t['user_name']) ?> <span class="text-muted fs-8">@<?= e($t['user_username']) ?></span></td>
                    <td class="small"><?= e(mb_substr($t['school_name'] ?? '—', 0, 20)) ?></td>
                    <td><span class="badge <?= $t['type'] === 'credit' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= ucfirst(e($t['type'])) ?></span></td>
                    <td class="small"><?= ucwords(str_replace('_', ' ', e($t['category']))) ?></td>
                    <td class="small text-muted">@<?= e($t['sender_username'] ?? '—') ?> → @<?= e($t['receiver_username'] ?? '—') ?></td>
                    <td class="text-end fw-semibold <?= $t['type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= $t['type'] === 'credit' ? '+' : '−' ?><?= number_format((int) $t['amount']) ?></td>
                    <td class="text-end small text-muted"><?= number_format((int) $t['balance_after']) ?></td>
                    <td><span class="badge badge-soft-success"><?= ucfirst(e($t['status'])) ?></span></td>
                    <td class="small text-muted text-nowrap"><?= nice_date($t['created_at'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No transactions found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
