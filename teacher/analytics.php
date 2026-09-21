<?php
/** QuizArena — Teacher: quiz analytics */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('teacher');

$quiz_id = (int) get('quiz_id', 0);
$my_quizzes = dball("SELECT id, title, status, created_at FROM qa_quizzes WHERE created_by = ? ORDER BY id DESC", [$user['id']]);

$quiz = null;
$summary = [];
$per_day = [];
$question_perf = [];

if ($quiz_id) {
    $quiz = dbrow("SELECT * FROM qa_quizzes WHERE id = ? AND created_by = ?", [$quiz_id, $user['id']]);
    if ($quiz) {
        $summary = [
            'attempts' => (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE quiz_id = ? AND status = 'submitted'", [$quiz_id]),
            'avg_pct' => (float) dbval("SELECT COALESCE(AVG(percentage),0) FROM qa_quiz_attempts WHERE quiz_id = ? AND status='submitted'", [$quiz_id]),
            'passes' => (int) dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE quiz_id = ? AND pass_status='pass'", [$quiz_id]),
            'avg_time' => (int) dbval("SELECT COALESCE(AVG(duration_seconds),0) FROM qa_quiz_attempts WHERE quiz_id = ? AND status='submitted'", [$quiz_id]),
            'coins_awarded' => (int) dbval("SELECT COALESCE(SUM(coins_earned),0) FROM qa_quiz_attempts WHERE quiz_id = ? AND status='submitted'", [$quiz_id]),
            'unique_students' => (int) dbval("SELECT COUNT(DISTINCT user_id) FROM qa_quiz_attempts WHERE quiz_id = ?", [$quiz_id]),
        ];
        $per_day = dball(
            "SELECT DATE(submitted_at) d, COUNT(*) c FROM qa_quiz_attempts
              WHERE quiz_id = ? AND status='submitted' GROUP BY DATE(submitted_at) ORDER BY d", [$quiz_id]
        );
        // question-level performance
        $qperf = dball(
            "SELECT q.id, q.question_text, q.marks,
                    COUNT(ua.id) AS answered,
                    COALESCE(SUM(CASE WHEN ua.is_correct = 1 THEN 1 ELSE 0 END), 0) AS correct
               FROM qa_questions q
               LEFT JOIN qa_user_answers ua ON ua.question_id = q.id
               LEFT JOIN qa_quiz_attempts a ON a.id = ua.attempt_id AND a.status = 'submitted'
              WHERE q.quiz_id = ?
              GROUP BY q.id, q.question_text, q.marks
              ORDER BY q.order_index, q.id", [$quiz_id]
        );
        $question_perf = $qperf;
    }
}

$title = 'Quiz Analytics';
$active = 'analytics';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6 col-lg-4">
            <label class="form-label small mb-1">Select Quiz</label>
            <select class="form-select form-select-sm" name="quiz_id" onchange="this.form.submit()">
                <option value="0">— Choose a quiz —</option>
                <?php foreach ($my_quizzes as $mz): ?>
                    <option value="<?= (int) $mz['id'] ?>" <?= $quiz_id === (int) $mz['id'] ? 'selected' : '' ?>><?= e(mb_substr($mz['title'], 0, 46)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$quiz): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-bar-chart display-4 d-block mb-3"></i>
        Select a quiz above to see its analytics.
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="fw-bold mb-0"><?= e($quiz['title']) ?></h5>
            <span class="small text-muted"><?= nice_date($quiz['created_at']) ?></span>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('teacher/attempts.php?quiz_id=' . $quiz_id) ?>"><i class="bi bi-list-ul"></i> All Attempts</a>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['Attempts', $summary['attempts'], 'bi-lightning', 'tile-indigo'],
            ['Unique Students', $summary['unique_students'], 'bi-people', 'tile-violet'],
            ['Average Score %', round($summary['avg_pct'], 1) . '%', 'bi-percent', 'tile-cyan'],
            ['Pass Rate', $summary['attempts'] ? round($summary['passes'] / $summary['attempts'] * 100, 1) . '%' : '—', 'bi-trophy', 'tile-emerald'],
            ['Avg Time Taken', $summary['avg_time'] ? gmdate('i:s', $summary['avg_time']) : '—', 'bi-stopwatch', 'tile-amber'],
            ['Coins Awarded', fmt_coin($summary['coins_awarded']), 'bi-coin', 'tile-rose'],
        ];
        foreach ($cards as $c): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="stat-card">
                    <span class="stat-icon <?= $c[3] ?>"><i class="bi <?= $c[2] ?>"></i></span>
                    <div><div class="stat-value"><?= $c[1] ?></div><div class="stat-label"><?= $c[0] ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Attempts per Day</h5></div>
                <div class="dash-card-body"><canvas id="dailyChart" height="130"></canvas></div>
            </div>
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Question-wise Accuracy</h5></div>
                <div class="table-responsive">
                    <table class="table table-dash">
                        <thead><tr><th>#</th><th>Question</th><th class="text-end">Marks</th><th class="text-end">Answered</th><th class="text-end">Correct</th><th class="text-end">Accuracy</th></tr></thead>
                        <tbody>
                        <?php foreach ($question_perf as $qi => $qp): ?>
                            <tr>
                                <td><?= $qi + 1 ?></td>
                                <td class="small"><?= e(mb_substr($qp['question_text'], 0, 60)) ?></td>
                                <td class="text-end"><?= (float) $qp['marks'] ?></td>
                                <td class="text-end"><?= (int) $qp['answered'] ?></td>
                                <td class="text-end"><?= (int) $qp['correct'] ?></td>
                                <td class="text-end">
                                    <?= $qp['answered'] ? round((int) $qp['correct'] / max(1, (int) $qp['answered']) * 100, 0) : 0 ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Score Distribution</h5></div>
                <div class="dash-card-body"><canvas id="distChart" height="200"></canvas></div>
            </div>
        </div>
    </div>

    <script>
        new Chart(document.getElementById('dailyChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_map(fn($d) => date('d M', strtotime($d['d'])), $per_day)) ?>, datasets: [{ label: 'Attempts', data: <?= json_encode(array_map(fn($d) => (int) $d['c'], $per_day)) ?>, backgroundColor: '#4f46e5', borderRadius: 6 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
        <?php
        $buckets = ['0-25' => 0, '26-50' => 0, '51-75' => 0, '76-100' => 0];
        foreach (dball("SELECT percentage FROM qa_quiz_attempts WHERE quiz_id = ? AND status = 'submitted'", [$quiz_id]) as $p) {
            $p = (float) $p['percentage'];
            if ($p <= 25) $buckets['0-25']++;
            elseif ($p <= 50) $buckets['26-50']++;
            elseif ($p <= 75) $buckets['51-75']++;
            else $buckets['76-100']++;
        }
        ?>
        new Chart(document.getElementById('distChart'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_keys($buckets)) ?>, datasets: [{ data: <?= json_encode(array_values($buckets)) ?>, backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6', '#10b981'] }] },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
