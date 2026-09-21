<?php
/**
 * Auth pages layout (login / register / forgot / reset) — split screen.
 * Expects: $title, $subtitle
 */
if (!defined('QA_RUNNING')) exit('Direct access denied');
$site_name = setting('site_name', 'QuizArena');
$subtitle = $subtitle ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e($site_name) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/img/favicon.png') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="auth-body">
<div class="auth-wrap">
    <div class="auth-aside d-none d-lg-flex">
        <div class="auth-aside-inner">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2 text-white mb-4" href="<?= url('index.php') ?>">
                <span class="brand-mark brand-mark-light"><i class="bi bi-lightning-charge-fill"></i></span> <?= e($site_name) ?>
            </a>
            <h2 class="text-white fw-bold mb-3">Where learning turns<br>into friendly competition.</h2>
            <p class="text-white-50 mb-4">Run quizzes, earn Quiz Coins, climb the leaderboard and collect certificates — all in one place for your school.</p>
            <div class="auth-stats d-flex gap-4">
                <div><div class="auth-stat-num">Multi-School</div><div class="text-white-50 small">SaaS platform</div></div>
                <div><div class="auth-stat-num">Quiz Coins</div><div class="text-white-50 small">Virtual rewards</div></div>
                <div><div class="auth-stat-num">Certificates</div><div class="text-white-50 small">Auto-generated</div></div>
            </div>
        </div>
    </div>
    <div class="auth-main">
        <div class="auth-card-wrap">
            <div class="d-lg-none mb-4 text-center">
                <a class="navbar-brand fw-bold d-inline-flex align-items-center gap-2" href="<?= url('index.php') ?>">
                    <span class="brand-mark"><i class="bi bi-lightning-charge-fill"></i></span> <?= e($site_name) ?>
                </a>
            </div>
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h4 class="fw-bold mb-0"><?= e($title) ?></h4>
                <a class="text-muted small" href="<?= url('index.php') ?>"><i class="bi bi-arrow-left"></i> Back to site</a>
            </div>
            <?php if ($subtitle): ?><p class="text-muted small mb-4"><?= e($subtitle) ?></p><?php endif; ?>
            <?php foreach (get_flash() as $f): ?>
                <div class="alert alert-<?= e($f['t']) ?> py-2 small"><?= $f['m'] ?></div>
            <?php endforeach; ?>
