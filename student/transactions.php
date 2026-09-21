<?php
/** QuizArena — Student: full transaction history with search & filters */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$q = trim((string) get('q', ''));
$type = get('type', '');
$category = get('category', '');
$from = get('from', '');
$to = get('to', '');
$page = (int) get('p', 1);

$where = "WHERE t.user_id = ?";
$params = [$user['id']];
if ($q !== '') { $where .= " AND (t.transaction_id LIKE ? OR t.sender_username LIKE ? OR t.receiver_username LIKE ? OR t.description LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($type !== '') { $where .= " AND t.type = ?"; $params[] = $type; }
if ($category !== '') { $where .= " AND t.category = ?"; $params[] = $category; }
if ($from) { $where .= " AND DATE(t.created_at) >= ?"; $params[] = $from; }
if ($to) { $where .= " AND DATE(t.created_at) <= ?"; $params[] = $to; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_wallet_transactions t $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 20, $page, 'student/transactions.php', ['q' => $q, 'type' => $type, 'category' => $category, 'from' => $from, 'to' => $to]);

$rows = dball("SELECT * FROM qa_wallet_transactions t $where ORDER BY t.id DESC LIMIT $per OFFSET $off", $params);

$categories = [
    'signup_bonus' => 'Sign-up bonus', 'admin_add' => 'Added by admin', 'admin_deduct' => 'Deducted by admin',
    'entry_fee' => 'Quiz entry fee', 'refund' => 'Refund', 'completion_bonus' => 'Completion bonus',
    'passing_reward' => 'Passing reward', 'rank_reward' => 'Rank reward', 'transfer_sent' => 'Sent to friend',
    'transfer_received' => 'Received from friend', 'quiz_reward' => 'Quiz reward',
];

$title = 'Transaction History';
$active = 'transactions';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Search</label>
            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Transaction ID, username, description…">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Type</label>
            <select class="form-select form-select-sm" name="type">
                <option value="">All</option>
                <option value="credit" <?= $type === 'credit' ? 'selected' : '' ?>>Credit</option>
                <option value="debit" <?= $type === 'debit' ? 'selected' : '' ?>>Debit</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Category</label>
            <select class="form-select form-select-sm" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $k => $v): ?><option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
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
        <div class="col-12 col-md-2">
            <button class="btn btn-sm btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Transaction ID</th><th>Type</th><th>Category</th><th>Details</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td class="small text-muted"><?= e($t['transaction_id']) ?></td>
                    <td><span class="badge <?= $t['type'] === 'credit' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= ucfirst(e($t['type'])) ?></span></td>
                    <td class="small"><?= e($categories[$t['category']] ?? $t['category']) ?></td>
                    <td class="small">
                        <?= e($t['description'] ?? '—') ?>
                        <?php if ($t['sender_username']): ?><div class="fs-8 text-muted">from @<?= e($t['sender_username']) ?></div><?php endif; ?>
                        <?php if ($t['receiver_username']): ?><div class="fs-8 text-muted">to @<?= e($t['receiver_username']) ?></div><?php endif; ?>
                    </td>
                    <td class="small text-muted text-nowrap"><?= nice_date($t['created_at'], true) ?></td>
                    <td class="text-end fw-semibold <?= $t['type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= $t['type'] === 'credit' ? '+' : '−' ?><?= number_format((int) $t['amount']) ?></td>
                    <td class="text-end small text-muted"><?= number_format((int) $t['balance_after']) ?></td>
                    <td><span class="badge <?= $t['status'] === 'completed' ? 'badge-soft-success' : 'badge-soft-warning' ?>"><?= ucfirst(e($t['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No transactions found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
