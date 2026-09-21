<?php
/** QuizArena — Privacy Policy */
require __DIR__ . '/app/bootstrap.php';
$title = 'Privacy Policy';
$active = '';
require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container"><h1 class="display-6 fw-bold mb-0">Privacy Policy</h1></div>
</section>
<section class="section">
    <div class="container" style="max-width:820px">
        <div class="qa-card p-4 p-lg-5">
            <p class="text-muted small"><strong>Last updated:</strong> <?= date('d F Y') ?></p>
            <h5 class="fw-bold mt-4">1. Who we are</h5>
            <p class="text-muted"><?= e(setting('site_name', 'QuizArena')) ?> ("we", "us") operates an online quiz platform for schools. This policy explains what data we collect and how we use it.</p>
            <h5 class="fw-bold mt-4">2. Information we collect</h5>
            <ul class="text-muted">
                <li>Account details: name, username, email, mobile number, school, class and section.</li>
                <li>Quiz activity: attempts, answers, scores, ranks, points and certificates.</li>
                <li>Wallet activity: Quiz Coin balances and transaction records.</li>
                <li>Technical data: IP address, browser type and pages visited.</li>
            </ul>
            <h5 class="fw-bold mt-4">3. How we use your information</h5>
            <p class="text-muted">To provide the platform (quizzes, results, certificates, wallets, leaderboards), to secure accounts, to communicate important updates, and to comply with legal obligations.</p>
            <h5 class="fw-bold mt-4">4. Data sharing</h5>
            <p class="text-muted">We do not sell personal data. Data is shared only with your school (the account holder) and with service providers that help us operate the platform under confidentiality obligations.</p>
            <h5 class="fw-bold mt-4">5. Data retention</h5>
            <p class="text-muted">Account and quiz data are kept while the account is active and as required by law. You may request deletion by contacting your school administrator.</p>
            <h5 class="fw-bold mt-4">6. Security</h5>
            <p class="text-muted">We use hashed passwords, encrypted sessions, role-based access control and school-level data isolation. No system is 100% secure, but we work hard to protect your data.</p>
            <h5 class="fw-bold mt-4">7. Children's privacy</h5>
            <p class="text-muted">The platform is intended for use by schools with parental/guardian consent. Schools are responsible for obtaining consent from parents or guardians for students under the applicable age of consent.</p>
            <h5 class="fw-bold mt-4">8. Contact</h5>
            <p class="text-muted mb-0">Questions about this policy? Contact us at <?= e(setting('site_email', 'support@example.com')) ?>.</p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
