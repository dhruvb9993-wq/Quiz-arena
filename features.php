<?php
/** QuizArena — Features */
require __DIR__ . '/app/bootstrap.php';
$title = 'Features';
$active = 'features';
require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container">
        <span class="eyebrow">Features</span>
        <h1 class="display-6 fw-bold mb-0">Everything you need to run <span class="text-gradient">brilliant quizzes</span></h1>
    </div>
</section>
<section class="section">
    <div class="container">
        <?php
        $groups = [
            ['For Teachers', 'bi-person-video3', [
                ['Quiz Builder', 'Create MCQs with 4 options, correct answers and explanations. Save as draft or publish instantly.'],
                ['Smart Timing', 'Set durations, start/end dates, negative marking, marks per question and attempt limits.'],
                ['Class Assignments', 'Assign quizzes to specific classes and sections — students see only what is meant for them.'],
                ['Deep Analytics', 'Attempt-wise and question-wise insights, average scores and pass rates per quiz.'],
            ]],
            ['For Students', 'bi-mortarboard', [
                ['Quiz Coins Wallet', 'Earn coins for completing quizzes, passing and ranking. Send coins to friends by username.'],
                ['Exam Experience', 'Countdown timer, autosave, question palette, progress bar and mobile-friendly interface.'],
                ['Instant Results', 'Full analysis: correct, wrong, unanswered, time taken, rank and points — immediately after submission.'],
                ['Certificates & Rank', 'Auto-generated PDF certificates with QR codes plus your live leaderboard position.'],
            ]],
            ['For Schools & Admins', 'bi-building', [
                ['Multi-School SaaS', 'Each school fully isolated — students, teachers, quizzes, results and wallets stay private.'],
                ['Subscription Plans', 'Per-quiz, monthly and yearly plans with usage tracking and invoices.'],
                ['Bulk Import', 'Import hundreds of students from a CSV file in seconds.'],
                ['Reports & Exports', 'School, class, student, teacher and quiz reports with CSV/Excel export and printing.'],
            ]],
        ];
        foreach ($groups as $gi => $g): ?>
            <div class="mb-5">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="icon-tile tile-indigo"><i class="bi <?= $g[1] ?>"></i></span>
                    <h3 class="section-title h4 mb-0"><?= $g[0] ?></h3>
                </div>
                <div class="row g-4">
                    <?php foreach ($g[2] as $f): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="qa-card qa-card-hover h-100 p-4">
                                <h6 class="fw-bold"><?= $f[0] ?></h6>
                                <p class="text-muted small mb-0"><?= $f[1] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="qa-card p-4 border-primary" style="background:#eef2ff">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <h5 class="fw-bold mb-1"><i class="bi bi-shield-check text-primary"></i> Security built in</h5>
                    <p class="text-muted small mb-0">Hashed passwords, role-based access, CSRF protection, prepared SQL statements, school-level data isolation, secure wallet transactions and audit logs — out of the box.</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a class="btn btn-primary rounded-pill px-4" href="<?= url('pricing.php') ?>">See Pricing</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
