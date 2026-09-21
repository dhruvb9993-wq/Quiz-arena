<?php
/** QuizArena — School Admin: reports (class/student/teacher/quiz-wise + exports) */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$tab = in_array(get('tab', 'class'), ['class', 'student', 'teacher', 'quiz', 'wallet'], true) ? get('tab', 'class') : 'class';
$quiz_id = (int) get('quiz', 0);
$class_id = (int) get('class', 0);
$from = get('from', '');
$to = get('to', '');
$page = (int) get('p', 1);

$quizzes = dball("SELECT id, title FROM qa_quizzes WHERE school_id = ? ORDER BY id DESC", [$sid]);
$classes = dball("SELECT id, name FROM qa_classes WHERE school_id = ? ORDER BY name", [$sid]);

$data = []; $headers = [];
switch ($tab) {
    case 'class':
        $data = report_class_performance($sid, $quiz_id ?: null);
        $headers = ['Class', 'Students', 'Attempts', 'Total Score', 'Avg %', 'Passes', 'Total Points'];
        break;
    case 'student':
        $data = report_student_performance($sid, $class_id ?: null, $quiz_id ?: null);
        $headers = ['Student', 'Username', 'Class', 'Section', 'Quizzes', 'Total Score', 'Avg %', 'Best %', 'Passes', 'Points', 'Coins'];
        break;
    case 'teacher':
        $data = report_teacher_performance($sid);
        $headers = ['Teacher', 'Username', 'Email', 'Quizzes Created', 'Attempts', 'Total Score', 'Avg %'];
        break;
    case 'quiz':
        $data = report_quiz_performance($sid);
        $headers = ['Quiz', 'Status', 'Questions', 'Attempts', 'Avg %', 'Total Score', 'Passes', 'Coins Awarded'];
        break;
    case 'wallet':
        $data = report_wallet_transactions($sid, null, null, null);
        $headers = ['Transaction', 'User', 'Type', 'Category', 'Amount', 'Balance After', 'Sender', 'Receiver', 'Status', 'Description', 'Date'];
        break;
}

if (get('export') === 'csv') {
    export_csv("$tab-report.csv", $headers, array_map(fn($r) => array_values(array_map(fn($v) => is_scalar($v) ? $v : '', $r)), $data));
}
if (get('export') === 'xlsx') {
    export_xlsx("$tab-report.xlsx", $headers, array_map(fn($r) => array_values(array_map(fn($v) => is_scalar($v) ? $v : '', $r)), $data));
}

$title = 'School Reports';
$active = 'reports';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Report</label>
            <select class="form-select form-select-sm" name="tab" onchange="this.form.submit()">
                <option value="class" <?= $tab === 'class' ? 'selected' : '' ?>>Class-wise</option>
                <option value="student" <?= $tab === 'student' ? 'selected' : '' ?>>Student-wise</option>
                <option value="teacher" <?= $tab === 'teacher' ? 'selected' : '' ?>>Teacher-wise</option>
                <option value="quiz" <?= $tab === 'quiz' ? 'selected' : '' ?>>Quiz-wise</option>
                <option value="wallet" <?= $tab === 'wallet' ? 'selected' : '' ?>>Wallet Transactions</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Quiz</label>
            <select class="form-select form-select-sm" name="quiz">
                <option value="0">All quizzes</option>
                <?php foreach ($quizzes as $qz): ?><option value="<?= (int) $qz['id'] ?>" <?= $quiz_id === (int) $qz['id'] ? 'selected' : '' ?>><?= e(mb_substr($qz['title'], 0, 26)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Class</label>
            <select class="form-select form-select-sm" name="class">
                <option value="0">All classes</option>
                <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $class_id === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
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
            <a class="btn btn-sm btn-outline-success text-nowrap" href="?tab=<?= $tab ?>&export=csv&quiz=<?= $quiz_id ?>&class=<?= $class_id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="bi bi-file-earmark-spreadsheet"></i></a>
            <a class="btn btn-sm btn-outline-success text-nowrap" href="?tab=<?= $tab ?>&export=xlsx&quiz=<?= $quiz_id ?>&class=<?= $class_id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="bi bi-file-earmark-excel"></i></a>
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
