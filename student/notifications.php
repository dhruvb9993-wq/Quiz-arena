<?php
/** QuizArena — Teacher: notifications */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');

if (is_post() && post('mark_all') == '1') {
    csrf_check();
    dbq("UPDATE qa_notifications SET is_read = 1 WHERE user_id = ?", [$user['id']]);
    flash('success', 'All notifications marked as read.');
    redirect('student/notifications.php');
}
$page = (int) get('p', 1);
$total = (int) dbval("SELECT COUNT(*) FROM qa_notifications WHERE user_id = ?", [$user['id']]);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'student/notifications.php');
$rows = dball("SELECT * FROM qa_notifications WHERE user_id = ? ORDER BY id DESC LIMIT $per OFFSET $off", [$user['id']]);

$title = 'Notifications';
$active = 'profile';
require __DIR__ . '/../app/layouts/dash_header.php';
?>
<div class="dash-card">
    <div class="dash-card-header">
        <h5 class="dash-card-title">Notifications</h5>
        <?php if ($rows): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="mark_all" value="1"><button class="btn btn-sm btn-light"><i class="bi bi-check2-all"></i> Mark all read</button></form>
        <?php endif; ?>
    </div>
    <div class="dash-card-body p-0">
        <ul class="list-group list-group-flush">
            <?php foreach ($rows as $n): ?>
                <li class="list-group-item d-flex gap-3 py-3 <?= !$n['is_read'] ? 'bg-primary-subtle' : '' ?>">
                    <div class="mt-1"><i class="bi bi-bell <?= $n['is_read'] ? 'text-muted' : 'text-primary' ?>"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= e($n['title']) ?></div>
                        <div class="small text-muted"><?= e($n['message']) ?></div>
                        <div class="fs-8 text-muted mt-1"><?= nice_date($n['created_at'], true) ?></div>
                    </div>
                    <?php if ($n['link']): ?><a class="btn btn-sm btn-light align-self-center" href="<?= url($n['link']) ?>">Open</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (!$rows): ?><li class="list-group-item text-center text-muted py-4">No notifications yet</li><?php endif; ?>
        </ul>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
