<?php
/**
 * Public site header (home, about, features, pricing, contact, leaderboard...)
 * Expects: $title (string), optional $active (nav key)
 */
if (!defined('QA_RUNNING')) exit('Direct access denied');

$user = current_user();
$active = $active ?? '';
$site_name = setting('site_name', 'QuizArena');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e(setting('site_tagline', 'The Smart Quiz Platform for Schools')) ?>">
    <title><?= e($title ?? $site_name) ?> · <?= e($site_name) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/img/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="site-body">

<nav class="navbar navbar-expand-lg site-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= url('index.php') ?>">
            <span class="brand-mark"><i class="bi bi-lightning-charge-fill"></i></span>
            <?= e($site_name) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="siteNav">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item"><a class="nav-link <?= $active === 'home' ? 'active' : '' ?>" href="<?= url('index.php') ?>">Home</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'features' ? 'active' : '' ?>" href="<?= url('features.php') ?>">Features</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'pricing' ? 'active' : '' ?>" href="<?= url('pricing.php') ?>">Pricing</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'leaderboard' ? 'active' : '' ?>" href="<?= url('leaderboard.php') ?>">Leaderboard</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'about' ? 'active' : '' ?>" href="<?= url('about.php') ?>">About</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'contact' ? 'active' : '' ?>" href="<?= url('contact.php') ?>">Contact</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($user): ?>
                    <a class="btn btn-sm btn-outline-primary rounded-pill px-3" href="<?= url(dash_path($user['role'])) ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="btn btn-sm btn-light rounded-pill px-3" href="<?= url('logout.php') ?>">Logout</a>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-primary rounded-pill px-3" href="<?= url('login.php') ?>">Login</a>
                    <a class="btn btn-sm btn-primary rounded-pill px-3" href="<?= url('register.php') ?>">Sign Up Free</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<main>
