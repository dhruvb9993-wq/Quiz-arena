<?php
/** QuizArena — About */
require __DIR__ . '/app/bootstrap.php';
$title = 'About Us';
$active = 'about';
require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container">
        <span class="eyebrow">About Us</span>
        <h1 class="display-6 fw-bold mb-0">Making classroom quizzes <span class="text-gradient">delightful</span></h1>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <h2 class="section-title mb-3">Our story</h2>
                <p class="text-muted">QuizArena was born from a simple idea: every student deserves to love learning. We started by asking teachers what they actually needed — and they told us about paper quiz sheets, late-night result checking and unmotivated revision.</p>
                <p class="text-muted">So we built a platform where quizzes feel like a game. Students earn Quiz Coins, race the clock, climb the leaderboard and collect certificates. Teachers get instant analytics. Schools get one secure, multi-school platform.</p>
                <p class="text-muted mb-0">Today QuizArena powers quizzes for schools across India, on any device — no apps, no installations, no technical skills required.</p>
            </div>
            <div class="col-lg-6">
                <div class="qa-card p-4">
                    <h6 class="fw-bold mb-3">Our mission</h6>
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <span class="icon-tile tile-indigo"><i class="bi bi-emoji-smile"></i></span>
                        <div><b>Make learning fun</b><br><span class="text-muted small">Gamified quizzes that students actually want to play.</span></div>
                    </div>
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <span class="icon-tile tile-violet"><i class="bi bi-person-check"></i></span>
                        <div><b>Save teachers time</b><br><span class="text-muted small">Auto-scoring, auto-ranking and auto-certificates.</span></div>
                    </div>
                    <div class="d-flex align-items-start gap-3">
                        <span class="icon-tile tile-emerald"><i class="bi bi-bank"></i></span>
                        <div><b>Serve every school</b><br><span class="text-muted small">Affordable plans from ₹100 per student per quiz.</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
