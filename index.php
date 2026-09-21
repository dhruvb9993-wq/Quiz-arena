<?php
/** QuizArena — Home page */
require __DIR__ . '/app/bootstrap.php';

$user = current_user();
$total_schools = dbval("SELECT COUNT(*) FROM qa_schools WHERE status = 'active'") ?: 0;
$total_students = dbval("SELECT COUNT(*) FROM qa_users WHERE role = 'student'") ?: 0;
$total_quizzes = dbval("SELECT COUNT(*) FROM qa_quizzes WHERE status = 'published'") ?: 0;
$total_attempts = dbval("SELECT COUNT(*) FROM qa_quiz_attempts WHERE status = 'submitted'") ?: 0;
$site_name = setting('site_name', 'QuizArena');

$title = 'Home';
$active = 'home';
require __DIR__ . '/app/layouts/site_header.php';
?>

<!-- Hero -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge rounded-pill bg-white text-primary border px-3 py-2 mb-3"><i class="bi bi-stars"></i> India's friendly classroom quiz platform</span>
                <h1 class="display-4 mb-3">Quizzes that make <span class="text-gradient">learning fun</span> for every classroom</h1>
                <p class="lead text-muted mb-4">QuizArena lets schools run timed online quizzes with Quiz Coins, live leaderboards and auto-generated certificates — on any phone, tablet or computer.</p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="<?= url('register.php') ?>" class="btn btn-primary btn-lg rounded-pill px-4"><i class="bi bi-rocket-takeoff"></i> Join Free</a>
                    <a href="<?= url('features.php') ?>" class="btn btn-light btn-lg rounded-pill px-4 border">Explore Features</a>
                </div>
                <div class="d-flex gap-4 small">
                    <div><i class="bi bi-check-circle-fill text-success"></i> Multi-school SaaS</div>
                    <div><i class="bi bi-check-circle-fill text-success"></i> No app install</div>
                    <div><i class="bi bi-check-circle-fill text-success"></i> Works on any device</div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="hero-img-wrap">
                    <img src="<?= url('assets/img/hero-dashboard.png') ?>" alt="QuizArena dashboard preview" class="img-fluid w-100" onerror="this.style.display='none'">
                    <div class="hero-float-card" style="top:8%;left:-6%">
                        <div class="d-flex align-items-center gap-2">
                            <span class="display-6">🥇</span>
                            <div><b>Rahul S.</b><br><span class="text-muted">Rank #1 · 1,240 pts</span></div>
                        </div>
                    </div>
                    <div class="hero-float-card" style="bottom:6%;right:-4%">
                        <div class="d-flex align-items-center gap-2">
                            <span class="display-6">🪙</span>
                            <div><b>+100 Coins</b><br><span class="text-muted">Quiz rank reward</span></div>
                        </div>
                    </div>
                    <div class="hero-float-card" style="top:45%;right:-2%">
                        <i class="bi bi-award-fill text-warning fs-4"></i> Certificate issued
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Stats strip -->
<section class="py-4 bg-white border-bottom">
    <div class="container">
        <div class="row text-center g-3">
            <div class="col-6 col-lg-3"><div class="stat-value"><?= number_format($total_schools) ?></div><div class="stat-label">Active Schools</div></div>
            <div class="col-6 col-lg-3"><div class="stat-value"><?= number_format($total_students) ?></div><div class="stat-label">Students Learning</div></div>
            <div class="col-6 col-lg-3"><div class="stat-value"><?= number_format($total_quizzes) ?></div><div class="stat-label">Quizzes Published</div></div>
            <div class="col-6 col-lg-3"><div class="stat-value"><?= number_format($total_attempts) ?></div><div class="stat-label">Quizzes Played</div></div>
        </div>
    </div>
</section>

<!-- Features -->
<section class="section">
    <div class="container">
        <div class="text-center mb-5">
            <span class="eyebrow">Why QuizArena</span>
            <h2 class="section-title display-6 mb-2">Everything a school needs to run great quizzes</h2>
            <p class="text-muted mx-auto" style="max-width:640px">Built for teachers, loved by students, and trusted by school administrators.</p>
        </div>
        <div class="row g-4">
            <?php
            $feats = [
                ['bi-patch-question', 'tile-indigo', 'Powerful Quiz Builder', 'MCQs with 4 options, explanations, negative marking, timers and scheduling. Save drafts, publish anytime.'],
                ['bi-stopwatch', 'tile-violet', 'Live Exam Experience', 'Countdown timer, autosave, question palette and automatic submission when time runs out.'],
                ['bi-coin', 'tile-amber', 'Quiz Coin Wallet', 'Virtual coins for entry fees, completion bonuses and rank rewards. Send coins to friends securely.'],
                ['bi-trophy', 'tile-cyan', 'Live Leaderboards', 'School-wise, class-wise and quiz-wise rankings that update automatically after every attempt.'],
                ['bi-award', 'tile-emerald', 'Auto Certificates', 'Pass an eligible quiz and get a professional PDF certificate with a unique ID and QR code.'],
                ['bi-building', 'tile-rose', 'Multi-School Ready', 'Each school gets its own isolated data — students, teachers, quizzes, results and wallets.'],
            ];
            foreach ($feats as $f): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="qa-card qa-card-hover h-100 p-4">
                        <span class="icon-tile <?= $f[1] ?> mb-3"><i class="bi <?= $f[0] ?>"></i></span>
                        <h5 class="fw-bold"><?= $f[2] ?></h5>
                        <p class="text-muted small mb-0"><?= $f[3] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- How it works -->
<section class="section bg-white border-top">
    <div class="container">
        <div class="text-center mb-5">
            <span class="eyebrow">How it works</span>
            <h2 class="section-title display-6 mb-2">Live in four simple steps</h2>
        </div>
        <div class="row g-4 text-center">
            <?php
            $steps = [
                ['1', 'School signs up', 'The school admin registers and receives a unique school code for students.'],
                ['2', 'Teacher creates quiz', 'Add questions, set the timer, entry fee and rewards, then assign to classes.'],
                ['3', 'Students play', 'Students take the quiz on any device, earning Quiz Coins as they go.'],
                ['4', 'Results & certificates', 'Results, ranks and certificates are generated automatically.'],
            ];
            foreach ($steps as $i => $s): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="qa-card h-100 p-4">
                        <div class="display-5 fw-bold text-gradient mb-2"><?= $s[0] ?></div>
                        <h6 class="fw-bold"><?= $s[1] ?></h6>
                        <p class="text-muted small mb-0"><?= $s[2] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Pricing teaser -->
<section class="section">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="eyebrow">Simple pricing</span>
                <h2 class="section-title display-6 mb-3">Flexible plans for schools of every size</h2>
                <p class="text-muted mb-4">From ₹100 per student per quiz to yearly plans for the whole school. Every plan includes the full quiz engine, wallet, leaderboard and certificates.</p>
                <a href="<?= url('pricing.php') ?>" class="btn btn-primary rounded-pill px-4">View Pricing <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="col-lg-6">
                <div class="qa-card p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="icon-tile tile-amber"><i class="bi bi-coin"></i></span>
                        <div>
                            <div class="fw-bold">Quiz Coins for motivation</div>
                            <div class="text-muted small">Entry fees, rewards and peer-to-peer coin transfers</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="icon-tile tile-cyan"><i class="bi bi-qr-code"></i></span>
                        <div>
                            <div class="fw-bold">Verifiable certificates</div>
                            <div class="text-muted small">Every certificate has a unique ID + QR code</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="icon-tile tile-emerald"><i class="bi bi-shield-check"></i></span>
                        <div>
                            <div class="fw-bold">Secure & isolated</div>
                            <div class="text-muted small">School data isolation with role-based access</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="section pt-0">
    <div class="container">
        <div class="qa-card text-center p-5" style="background:linear-gradient(135deg,#1e1b4b,#4f46e5 60%,#7c3aed);border:none">
            <h2 class="text-white display-6 fw-bold mb-2">Ready to make learning fun?</h2>
            <p class="text-white-50 mb-4 mx-auto" style="max-width:520px">Join hundreds of classrooms. Set up your school in under five minutes — no installation, no technical skills needed.</p>
            <a href="<?= url('register.php') ?>" class="btn btn-light btn-lg rounded-pill px-5 fw-bold">Get Started Free</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
