<?php
/** QuizArena — Pricing (reads live subscription plans from the database) */
require __DIR__ . '/app/bootstrap.php';
$title = 'Pricing';
$active = 'pricing';

$plans = dball("SELECT * FROM qa_subscription_plans WHERE status = 'active' ORDER BY price ASC");
$type_labels = [
    'per_quiz' => 'Per Quiz', 'per_student' => 'Per Student',
    'monthly' => 'Monthly', 'yearly' => 'Yearly', 'custom' => 'Custom',
];
$currency = setting('currency_symbol', '₹');

require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container text-center">
        <span class="eyebrow">Pricing</span>
        <h1 class="display-6 fw-bold mb-2">Simple, honest pricing for schools</h1>
        <p class="text-muted mx-auto" style="max-width:620px">Start small, grow big. Every plan includes the complete quiz engine — no hidden fees, cancel anytime.</p>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="row g-4 justify-content-center">
            <?php foreach ($plans as $i => $p): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="qa-card pricing-card h-100 p-4 <?= $i === 2 ? 'popular' : '' ?>">
                        <?php if ($i === 2): ?><span class="popular-tag">Most Popular</span><?php endif; ?>
                        <h5 class="fw-bold mb-1"><?= e($p['name']) ?></h5>
                        <span class="badge bg-secondary-subtle text-secondary mb-3"><?= e($type_labels[$p['type']] ?? ucfirst($p['type'])) ?></span>
                        <div class="price-big mb-1"><?= e($currency) ?><?= rtrim(rtrim(number_format((float) $p['price'], 2), '0'), '.') ?></div>
                        <?php if ($p['type'] === 'monthly'): ?><div class="text-muted small mb-3">per month</div>
                        <?php elseif ($p['type'] === 'yearly'): ?><div class="text-muted small mb-3">per year</div>
                        <?php elseif ($p['type'] === 'per_quiz'): ?><div class="text-muted small mb-3">per student per quiz</div>
                        <?php else: ?><div class="text-muted small mb-3">per plan</div><?php endif; ?>
                        <ul class="list-unstyled small text-muted mb-4">
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success"></i> Up to <?= $p['student_limit'] ? e(number_format($p['student_limit'])) . ' students' : 'unlimited students' ?></li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success"></i> Up to <?= $p['quiz_limit'] ? e(number_format($p['quiz_limit'])) . ' quizzes' : 'unlimited quizzes' ?></li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success"></i> Quiz Coins & leaderboard</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success"></i> Auto certificates</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> CSV student import</li>
                        </ul>
                        <a class="btn <?= $i === 2 ? 'btn-primary' : 'btn-outline-primary' ?> w-100 rounded-pill" href="<?= url('contact.php') ?>">Get Started</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$plans): ?>
            <div class="text-center text-muted py-4">Pricing plans will appear here once the platform is configured.</div>
        <?php endif; ?>

        <div class="qa-card mt-5 p-4">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <h5 class="fw-bold mb-1"><i class="bi bi-chat-square-text"></i> Need a custom plan for your school?</h5>
                    <p class="text-muted small mb-0">We tailor plans to school size, exam calendars and budgets. Talk to our team — we usually reply within one working day.</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a class="btn btn-primary rounded-pill px-4" href="<?= url('contact.php') ?>">Contact Sales</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
