<?php
/** QuizArena — Terms & Conditions */
require __DIR__ . '/app/bootstrap.php';
$title = 'Terms & Conditions';
$active = '';
require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container"><h1 class="display-6 fw-bold mb-0">Terms &amp; Conditions</h1></div>
</section>
<section class="section">
    <div class="container" style="max-width:820px">
        <div class="qa-card p-4 p-lg-5">
            <p class="text-muted small"><strong>Last updated:</strong> <?= date('d F Y') ?></p>
            <h5 class="fw-bold mt-4">1. Acceptance of terms</h5>
            <p class="text-muted">By accessing or using <?= e(setting('site_name', 'QuizArena')) ?> you agree to these Terms. If you are using the platform on behalf of a school, you represent that you have authority to bind that school.</p>
            <h5 class="fw-bold mt-4">2. Accounts</h5>
            <p class="text-muted">You are responsible for safeguarding your credentials and for all activity under your account. Usernames must be unique and must not impersonate others.</p>
            <h5 class="fw-bold mt-4">3. School subscriptions</h5>
            <p class="text-muted">Schools subscribe on per-quiz, per-student, monthly or yearly plans. Payments are currently processed manually with approval by the platform operator. Unpaid or expired subscriptions may limit platform access.</p>
            <h5 class="fw-bold mt-4">4. Quiz Coins</h5>
            <p class="text-muted">Quiz Coins are a virtual, in-platform reward with no monetary value. They cannot be redeemed for cash and may be adjusted by the platform operator to correct errors or prevent abuse.</p>
            <h5 class="fw-bold mt-4">5. Acceptable use</h5>
            <p class="text-muted">You agree not to misuse the platform: no cheating, automated scraping, attempts to access other schools' data, introducing malicious code, or interfering with the service.</p>
            <h5 class="fw-bold mt-4">6. Content & certificates</h5>
            <p class="text-muted">Quiz content is provided by teachers and schools. Certificates are issued automatically based on quiz results and may be revoked if issued in error or through abuse.</p>
            <h5 class="fw-bold mt-4">7. Availability</h5>
            <p class="text-muted">We aim for high availability but do not guarantee uninterrupted service. We may suspend accounts that violate these terms.</p>
            <h5 class="fw-bold mt-4">8. Limitation of liability</h5>
            <p class="text-muted">To the maximum extent permitted by law, <?= e(setting('site_name', 'QuizArena')) ?> is not liable for indirect or consequential losses arising from use of the platform.</p>
            <h5 class="fw-bold mt-4">9. Changes</h5>
            <p class="text-muted mb-0">We may update these terms from time to time. Continued use after changes means you accept the updated terms.</p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
