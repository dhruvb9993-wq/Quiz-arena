<?php
/** QuizArena — Student: wallet overview */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');
$sid = (int) $user['school_id'];
require_school($sid);

$balance = (int) ($user['wallet_balance'] ?? 0);
$stats = [
    'total_earned' => (int) dbval("SELECT COALESCE(SUM(amount),0) FROM qa_wallet_transactions WHERE user_id = ? AND type = 'credit'", [$user['id']]),
    'total_spent' => (int) dbval("SELECT COALESCE(SUM(amount),0) FROM qa_wallet_transactions WHERE user_id = ? AND type = 'debit'", [$user['id']]),
    'sent' => (int) dbval("SELECT COALESCE(SUM(amount),0) FROM qa_wallet_transactions WHERE user_id = ? AND category = 'transfer_sent'", [$user['id']]),
    'received' => (int) dbval("SELECT COALESCE(SUM(amount),0) FROM qa_wallet_transactions WHERE user_id = ? AND category = 'transfer_received'", [$user['id']]),
    'rewards' => (int) dbval("SELECT COALESCE(SUM(amount),0) FROM qa_wallet_transactions WHERE user_id = ? AND category IN ('completion_bonus','passing_reward','rank_reward','quiz_reward')", [$user['id']]),
];

$recent = dball(
    "SELECT * FROM qa_wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 8", [$user['id']]
);

$title = 'My Wallet';
$active = 'wallet';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="wallet-hero mb-3">
            <div class="fs-7 text-white-50 text-uppercase fw-bold mb-1"><i class="bi bi-wallet2"></i> <?= e(coin_name()) ?> Balance</div>
            <div class="wallet-balance"><?= fmt_coin($balance) ?></div>
            <div class="fs-8 text-white-50 mt-1">Earn coins by playing quizzes, winning ranks and passing.</div>
        </div>
        <div class="d-flex gap-2 mb-4">
            <a class="btn btn-primary rounded-pill px-4" href="<?= url('student/send-coins.php') ?>"><i class="bi bi-send"></i> Send Coins</a>
            <a class="btn btn-outline-primary rounded-pill px-4" href="<?= url('student/transactions.php') ?>"><i class="bi bi-arrow-left-right"></i> All Transactions</a>
        </div>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Lifetime Summary</h5></div>
            <div class="dash-card-body">
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Total earned</span><b class="small text-success">+<?= fmt_coin($stats['total_earned']) ?></b></div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Quiz rewards</span><b class="small"><?= fmt_coin($stats['rewards']) ?></b></div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Received from friends</span><b class="small"><?= fmt_coin($stats['received']) ?></b></div>
                <div class="d-flex justify-content-between mb-2"><span class="small text-muted">Sent to friends</span><b class="small text-danger">−<?= fmt_coin($stats['sent']) ?></b></div>
                <div class="d-flex justify-content-between"><span class="small text-muted">Total spent</span><b class="small text-danger">−<?= fmt_coin($stats['total_spent']) ?></b></div>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-body small text-muted">
                <i class="bi bi-info-circle text-primary"></i> <b>Note:</b> Quiz Coins are a virtual reward inside QuizArena. They have no monetary value and cannot be withdrawn or exchanged for cash.
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header">
                <h5 class="dash-card-title">Recent Transactions</h5>
                <a class="small" href="<?= url('student/transactions.php') ?>">View all →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-dash">
                    <thead><tr><th>Type</th><th>Description</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $t): ?>
                        <tr>
                            <td><span class="badge <?= $t['type'] === 'credit' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= $t['type'] === 'credit' ? 'Credit' : 'Debit' ?></span></td>
                            <td class="small"><?= e($t['description'] ?? e($t['category'])) ?></td>
                            <td class="small text-muted"><?= nice_date($t['created_at'], true) ?></td>
                            <td class="text-end <?= $t['type'] === 'credit' ? 'text-success' : 'text-danger' ?> fw-semibold"><?= $t['type'] === 'credit' ? '+' : '−' ?><?= number_format((int) $t['amount']) ?></td>
                            <td class="text-end small text-muted"><?= number_format((int) $t['balance_after']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><tr><td colspan="5" class="text-center text-muted py-4">No transactions yet. Play a quiz to earn your first coins!</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
