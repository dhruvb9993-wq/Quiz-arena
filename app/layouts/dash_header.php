<?php
/**
 * Dashboard layout — dark sidebar, topbar, content area.
 * Expects: $user (current user row), $title, $active (nav key)
 */
if (!defined('QA_RUNNING')) exit('Direct access denied');

$user = $user ?? current_user();
$active = $active ?? '';
$site_name = setting('site_name', 'QuizArena');
$role = $user['role'] ?? '';

$nav = [];
if ($role === 'superadmin') {
    $nav = [
        'dashboard'  => ['label' => 'Dashboard',        'icon' => 'bi-speedometer2',        'url' => 'admin/dashboard.php'],
        'schools'    => ['label' => 'Schools',          'icon' => 'bi-building',            'url' => 'admin/schools.php'],
        'plans'      => ['label' => 'Plans & Subscriptions', 'icon' => 'bi-card-checklist', 'url' => 'admin/plans.php'],
        'users'      => ['label' => 'Users',            'icon' => 'bi-people',              'url' => 'admin/users.php'],
        'quizzes'    => ['label' => 'Quizzes',          'icon' => 'bi-patch-question',      'url' => 'admin/quizzes.php'],
        'results'    => ['label' => 'Results',          'icon' => 'bi-clipboard-data',      'url' => 'admin/results.php'],
        'certificates'=> ['label' => 'Certificates',    'icon' => 'bi-award',               'url' => 'admin/certificates.php'],
        'wallets'    => ['label' => 'Wallets',          'icon' => 'bi-wallet2',             'url' => 'admin/wallets.php'],
        'transactions'=> ['label' => 'Transactions',    'icon' => 'bi-arrow-left-right',    'url' => 'admin/transactions.php'],
        'reports'    => ['label' => 'Reports',          'icon' => 'bi-graph-up',            'url' => 'admin/reports.php'],
        'settings'   => ['label' => 'Settings',         'icon' => 'bi-gear',                'url' => 'admin/settings.php'],
        'logs'       => ['label' => 'Audit Logs',       'icon' => 'bi-journal-text',        'url' => 'admin/logs.php'],
    ];
} elseif ($role === 'school_admin') {
    $nav = [
        'dashboard'  => ['label' => 'Dashboard',        'icon' => 'bi-speedometer2',  'url' => 'school/dashboard.php'],
        'profile'    => ['label' => 'School Profile',   'icon' => 'bi-building',      'url' => 'school/profile.php'],
        'classes'    => ['label' => 'Classes & Sections','icon' => 'bi-diagram-3',    'url' => 'school/classes.php'],
        'teachers'   => ['label' => 'Teachers',         'icon' => 'bi-person-video3', 'url' => 'school/teachers.php'],
        'students'   => ['label' => 'Students',         'icon' => 'bi-people',        'url' => 'school/students.php'],
        'quizzes'    => ['label' => 'School Quizzes',   'icon' => 'bi-patch-question','url' => 'school/quizzes.php'],
        'results'    => ['label' => 'Results',          'icon' => 'bi-clipboard-data','url' => 'school/results.php'],
        'leaderboard'=> ['label' => 'Leaderboard',      'icon' => 'bi-trophy',        'url' => 'school/leaderboard.php'],
        'reports'    => ['label' => 'Reports',          'icon' => 'bi-graph-up',      'url' => 'school/reports.php'],
        'subscription'=> ['label' => 'Subscription',    'icon' => 'bi-card-checklist','url' => 'school/subscription.php'],
    ];
} elseif ($role === 'teacher') {
    $nav = [
        'dashboard'  => ['label' => 'Dashboard',    'icon' => 'bi-speedometer2',    'url' => 'teacher/dashboard.php'],
        'quizzes'    => ['label' => 'My Quizzes',   'icon' => 'bi-patch-question',  'url' => 'teacher/quizzes.php'],
        'analytics'  => ['label' => 'Analytics',    'icon' => 'bi-graph-up',        'url' => 'teacher/analytics.php'],
        'profile'    => ['label' => 'My Profile',   'icon' => 'bi-person',          'url' => 'teacher/profile.php'],
    ];
} elseif ($role === 'student') {
    $nav = [
        'dashboard'  => ['label' => 'Dashboard',      'icon' => 'bi-speedometer2',     'url' => 'student/dashboard.php'],
        'quizzes'    => ['label' => 'Available Quizzes','icon' => 'bi-patch-question', 'url' => 'student/quizzes.php'],
        'history'    => ['label' => 'Quiz History',   'icon' => 'bi-clock-history',    'url' => 'student/quiz-history.php'],
        'certificates'=> ['label' => 'Certificates',  'icon' => 'bi-award',            'url' => 'student/certificates.php'],
        'leaderboard'=> ['label' => 'Leaderboard',    'icon' => 'bi-trophy',           'url' => 'student/leaderboard.php'],
        'wallet'     => ['label' => 'My Wallet',      'icon' => 'bi-wallet2',          'url' => 'student/wallet.php'],
        'send'       => ['label' => 'Send Coins',     'icon' => 'bi-send',             'url' => 'student/send-coins.php'],
        'transactions'=> ['label' => 'Transactions',  'icon' => 'bi-arrow-left-right', 'url' => 'student/transactions.php'],
        'profile'    => ['label' => 'My Profile',     'icon' => 'bi-person',           'url' => 'student/profile.php'],
    ];
}
$unread = $role === 'student' || $role === 'teacher' ? unread_count($user['id']) : 0;
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
    <link rel="stylesheet" href="<?= url('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="dash-body">
<div class="dash-layout">

    <!-- Sidebar -->
    <aside class="dash-sidebar" id="dashSidebar">
        <div class="dash-brand">
            <a class="d-flex align-items-center gap-2 text-white text-decoration-none" href="<?= url(dash_path($role)) ?>">
                <span class="brand-mark brand-mark-light"><i class="bi bi-lightning-charge-fill"></i></span>
                <span class="fw-bold"><?= e($site_name) ?></span>
            </a>
        </div>
        <nav class="dash-nav">
            <?php foreach ($nav as $key => $item): ?>
                <a class="dash-nav-item <?= $active === $key ? 'active' : '' ?>" href="<?= url($item['url']) ?>">
                    <i class="bi <?= $item['icon'] ?>"></i><span><?= e($item['label']) ?></span>
                    <?php if ($key === 'wallet' && $role === 'student' && ($user['wallet_balance'] ?? 0) > 0): ?>
                        <span class="dash-nav-badge"><?= fmt_coin($user['wallet_balance']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="dash-sidebar-foot">
            <a class="dash-nav-item" href="<?= url('index.php') ?>"><i class="bi bi-globe2"></i><span>View Website</span></a>
            <a class="dash-nav-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </aside>
    <div class="dash-sidebar-backdrop" onclick="document.getElementById('dashSidebar').classList.remove('open')"></div>

    <!-- Main -->
    <div class="dash-main">
        <header class="dash-topbar">
            <button class="btn btn-light btn-sm d-lg-none" onclick="document.getElementById('dashSidebar').classList.toggle('open')"><i class="bi bi-list fs-5"></i></button>
            <div class="dash-topbar-title">
                <h1 class="h5 mb-0 fw-bold"><?= e($title) ?></h1>
                <span class="text-muted small d-none d-md-inline"><?= e(role_label($role)) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php if ($role === 'student'): ?>
                    <a class="topbar-coin" href="<?= url('student/wallet.php') ?>">
                        <i class="bi bi-coin text-warning"></i> <b><?= fmt_coin($user['wallet_balance'] ?? 0) ?></b>
                        <span class="d-none d-sm-inline"><?= e(coin_name()) ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($unread > 0 && in_array($role, ['student', 'teacher'])): ?>
                    <a class="btn btn-light btn-sm position-relative" href="<?= url($role === 'student' ? 'student/notifications.php' : 'teacher/notifications.php') ?>">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span>
                    </a>
                <?php endif; ?>
                <a class="d-flex align-items-center gap-2 text-decoration-none text-dark" href="<?= url($role === 'student' ? 'student/profile.php' : ($role === 'teacher' ? 'teacher/profile.php' : dash_path($role))) ?>">
                    <img class="rounded-circle object-fit-cover" width="34" height="34" src="<?= e(avatar_url($user)) ?>" alt="">
                    <span class="d-none d-md-inline small fw-semibold"><?= e($user['full_name']) ?></span>
                </a>
            </div>
        </header>

        <div class="dash-content">
            <?php foreach (get_flash() as $f): ?>
                <div class="alert alert-<?= e($f['t']) ?> alert-dismissible fade show py-2">
                    <?= $f['m'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
