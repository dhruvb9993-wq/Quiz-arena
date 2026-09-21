<?php
if (!defined('QA_RUNNING')) exit('Direct access denied');
$site_name = setting('site_name', 'QuizArena');
?>
</main>
<footer class="site-footer mt-auto">
    <div class="container">
        <div class="row g-4 py-5">
            <div class="col-lg-4">
                <a class="navbar-brand fw-bold d-inline-flex align-items-center gap-2 mb-2" href="<?= url('index.php') ?>">
                    <span class="brand-mark"><i class="bi bi-lightning-charge-fill"></i></span> <?= e($site_name) ?>
                </a>
                <p class="text-muted small pe-4"><?= e(setting('site_tagline', 'The Smart Quiz Platform for Schools')) ?>. Quiz Coins, live leaderboards and digital certificates for every classroom.</p>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="fw-bold mb-3">Platform</h6>
                <ul class="list-unstyled footer-links">
                    <li><a href="<?= url('features.php') ?>">Features</a></li>
                    <li><a href="<?= url('pricing.php') ?>">Pricing</a></li>
                    <li><a href="<?= url('leaderboard.php') ?>">Leaderboard</a></li>
                    <li><a href="<?= url('verify-certificate.php') ?>">Verify Certificate</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="fw-bold mb-3">Company</h6>
                <ul class="list-unstyled footer-links">
                    <li><a href="<?= url('about.php') ?>">About Us</a></li>
                    <li><a href="<?= url('contact.php') ?>">Contact</a></li>
                    <li><a href="<?= url('privacy-policy.php') ?>">Privacy Policy</a></li>
                    <li><a href="<?= url('terms.php') ?>">Terms & Conditions</a></li>
                </ul>
            </div>
            <div class="col-lg-4">
                <h6 class="fw-bold mb-3">Get Started</h6>
                <p class="text-muted small">Create your school account in minutes and run your first quiz today.</p>
                <a href="<?= url('register.php') ?>" class="btn btn-primary rounded-pill px-4">Join as Student</a>
                <a href="<?= url('contact.php') ?>" class="btn btn-outline-primary rounded-pill px-4 ms-1">Talk to Sales</a>
            </div>
        </div>
        <div class="border-top py-3 d-flex flex-wrap justify-content-between gap-2">
            <span class="small text-muted">© <?= date('Y') ?> <?= e($site_name) ?>. All rights reserved.</span>
            <span class="small text-muted">Made with <i class="bi bi-heart-fill text-danger"></i> for schools</span>
        </div>
    </div>
</footer>
<script src="<?= url('assets/vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
