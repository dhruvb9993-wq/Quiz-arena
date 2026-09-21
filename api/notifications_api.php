<?php
/** QuizArena — Notifications API (mark read) */
require __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$action = get('action', '');

if ($action === 'mark_read') {
    csrf_check();
    $id = (int) post('id', 0);
    if ($id) {
        dbq("UPDATE qa_notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    } else {
        dbq("UPDATE qa_notifications SET is_read = 1 WHERE user_id = ?", [$user['id']]);
    }
    json_out(['ok' => true, 'unread' => unread_count($user['id'])]);
}

if ($action === 'unread') {
    json_out(['ok' => true, 'unread' => unread_count($user['id'])]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
