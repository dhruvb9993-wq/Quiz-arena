<?php
/**
 * Minimal error page (403/404). Expects $title, $msg.
 */
if (!defined('QA_RUNNING')) exit('Direct access denied');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Error') ?> · <?= e(setting('site_name', 'QuizArena')) ?></title>
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="site-body">
<div class="container py-5 my-5 text-center">
    <div class="display-1 fw-bold text-primary mb-3"><?= e($title ?? 'Error') ?></div>
    <p class="text-muted fs-5"><?= e($msg ?? 'Something went wrong.') ?></p>
    <a class="btn btn-primary rounded-pill px-4 mt-2" href="<?= url('index.php') ?>"><i class="bi bi-house-door"></i> Go Home</a>
    <?php if (current_user()): ?>
        <a class="btn btn-outline-primary rounded-pill px-4 mt-2 ms-1" href="<?= url(dash_path(current_user()['role'])) ?>">Back to Dashboard</a>
    <?php endif; ?>
</div>
</body>
</html>
