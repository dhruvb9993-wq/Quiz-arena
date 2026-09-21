<?php
/** QuizArena — Super Admin: platform reports */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$tab = in_array(get('tab', 'school'), ['school', 'student', 'teacher', 'quiz', 'attempt', 'wallet', 'reward', 'subscription'], true) ? get('tab', 'school') : 'school';
$school_id = (int) get('school', 0);
$from = get('from', '');
$to = get('to', '');
$page = (int) get('p', 1);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");

$data = []; $headers = [];
switch ($tab) {
    case 'school':
        $data = report_school_performance($school_id ?: null, $from ?: null, $to ?: null);
        $headers = ['School', 'Code', 'Status', 'Students', 'Teachers', 'Quizzes', 'Attempts', 'Total Score', 'Certificates', 'Expiry'];
        break;
    case 'student':
        $data = report_student_performance($school_id ?: null);
        $headers = ['Student', 'Username', 'School', 'Class', 'Quizzes', 'Total Score', 'Avg %', 'Best %', 'Passes', 'Points', 'Coins'];
        break;
    case 'teacher':
        $data = report_teacher_performance($school_id ?: null);
        $headers = ['Teacher', 'Username', 'School', 'Quizzes Created', 'Attempts', 'Total Score', 'Avg %'];
        break;
    case 'quiz':
        $data = report_quiz_performance($school_id ?: null);
        $headers = ['Quiz', 'School', 'Status', 'Questions', 'Attempts', 'Avg %', 'Total Score', 'Passes', 'Coins Awarded'];
        break;
    case 'attempt':
        $data = report_wallet_transactions($school_id ?: null); // placeholder to keep structure; real below
        $where = "WHERE 1=1"; $params = [];
        if ($school_id) { $where .= " AND a.school_id = ?"; $params[] = $school_id; }
        if ($from) { $where .= " AND DATE(a.submitted_at) >= ?"; $params[] = $from; }
        if ($to) { $where .= " AND DATE(a.submitted_at) <= ?"; $params[] = $to; }
        $data = dball(
            "SELECT u.full_name, u.username, s.name AS school_name, q.title AS quiz_title,
                    a.score, a.total_marks, a.percentage, a.correct_count, a.wrong_count, a.unanswered_count, a.pass_status, a.submitted_at
               FROM qa_quiz_attempts a
               JOIN qa_users u ON u.id = a.user_id
               JOIN qa_schools s ON s.id = a.school_id
               JOIN qa_quizzes q ON q.id = a.quiz_id
               $where ORDER BY a.id DESC LIMIT 500", $params
        );
        $headers = ['Student', 'Username', 'School', 'Quiz', 'Score', 'Total', 'Percentage', 'Correct', 'Wrong', 'Unanswered', 'Result', 'Submitted'];
        break;
    case 'wallet':
        $data = report_wallet_transactions($school_id ?: null);
        $headers = ['Transaction', 'User', 'Type', 'Category', 'Amount', 'Balance', 'Sender', 'Receiver', 'Status', 'Date'];
        break;
    case 'reward':
        $data = report_rewards();
        $headers = ['Category', 'Transactions', 'Total Coins'];
        break;
    case 'subscription':
        $data = report_subscriptions();
        $headers = ['School', 'Code', 'Plan', 'Type', 'Price', 'Start', 'Expiry', 'Status', 'Students', 'Quizzes'];
        break;
}

if (get('export') === 'csv') {
    export_csv("platform-$tab.csv", $headers, array_map(fn($r) => array_values(array_map(fn($v) => is_scalar($v) ? $v : '', $r)), $data));
}
if (get('export') === 'xlsx') {
    export_xlsx("platform-$tab.xlsx", $headers, array_map(fn($r) => array_values(array_map(fn($v) => is_scalar($v) ? $v : '', $r)), $data));
}

$title = 'Platform Reports';
$active = 'reports';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Report</label>
            <select class="form-select form-select-sm" name="tab" onchange="this.form.submit()">
                <option value="school" <?= $tab === 'school' ? 'selected' : '' ?>>School-wise</option>
                <option value="student" <?= $tab === 'student' ? 'selected' : '' ?>>Student-wise</option>
                <option value="teacher" <?= $tab === 'teacher' ? 'selected' : '' ?>>Teacher-wise</option>
                <option value="quiz" <?= $tab === 'quiz' ? 'selected' : '' ?>>Quiz-wise</option>
                <option value="attempt" <?= $tab === 'attempt' ? 'selected' : '' ?>>Quiz Attempts</option>
                <option value="wallet" <?= $tab === 'wallet' ? 'selected' : '' ?>>Wallet Transactions</option>
                <option value="reward" <?= $tab === 'reward' ? 'selected' : '' ?>>Coin Rewards</option>
                <option value="subscription" <?= $tab === 'subscription' ? 'selected' : '' ?>>Subscriptions</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">School</label>
            <select class="form-select form-select-sm" name="school">
                <option value="0">All schools</option>
                <?php foreach ($schools as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 26)) ?></option><?php endforeach; ?>
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
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-primary w-100">Filter</button>
            <a class="btn btn-sm btn-outline-success text-nowrap" href="?tab=<?= $tab ?>&export=csv&school=<?= $school_id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="bi bi-file-earmark-spreadsheet"></i></a>
            <a class="btn btn-sm btn-outline-success text-nowrap" href="?tab=<?= $tab ?>&export=xlsx&school=<?= $school_id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="bi bi-file-earmark-excel"></i></a>
        </div>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($data as $row): ?>
                <tr>
                    <?php foreach ($row as $v): ?>
                        <td class="small">
                            <?php
                            if (is_float($v) || (is_string($v) && is_numeric($v) && strpos($v, '.') !== false)) echo number_format((float) $v, 1);
                            elseif (is_numeric($v)) echo number_format((float) $v);
                            else echo e((string) $v);
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data): ?><tr><td colspan="<?= count($headers) ?>" class="text-center text-muted py-4">No data for this report</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
