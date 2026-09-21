<?php
/**
 * QuizArena — Wallet API.
 * lookup: GET  → returns receiver profile by username (for the Send Coins flow).
 * transfer: POST → secure, transactional, idempotent coin transfer.
 */
require __DIR__ . '/../app/bootstrap.php';

$user = require_login('student');
$action = get('action', '');

/* ---------- Lookup receiver by username ---------- */
if ($action === 'lookup') {
    $username = clean_username(get('username', ''));
    if ($username === '') json_out(['ok' => false, 'error' => 'Enter a username.']);
    $target = dbrow(
        "SELECT u.id, u.full_name, u.username, u.profile_photo, u.class_id, u.school_id,
                s.name AS school_name, c.name AS class_name
           FROM qa_users u
           LEFT JOIN qa_schools s ON s.id = u.school_id
           LEFT JOIN qa_classes c ON c.id = u.class_id
          WHERE u.username = ? AND u.status = 'active'", [$username]
    );
    if (!$target) json_out(['ok' => false, 'error' => 'No active user found with that username.']);
    if ((int) $target['id'] === (int) $user['id']) json_out(['ok' => false, 'error' => 'That is your own username — you cannot send coins to yourself.']);
    json_out([
        'ok' => true,
        'user' => [
            'id' => (int) $target['id'],
            'name' => $target['full_name'],
            'username' => $target['username'],
            'avatar' => avatar_url($target),
            'school' => $target['school_name'] ?? '—',
            'class' => $target['class_name'] ?? '—',
        ],
    ]);
}

/* ---------- Transfer coins ---------- */
if ($action === 'transfer') {
    csrf_check();
    $to_username = clean_username(post('to_username', ''));
    $amount = (int) post('amount', 0);

    if ($amount <= 0) json_out(['ok' => false, 'error' => 'Amount must be greater than zero.']);
    if ($amount > 100000) json_out(['ok' => false, 'error' => 'Amount is too large.']);

    $receiver = dbrow("SELECT * FROM qa_users WHERE username = ? AND status = 'active'", [$to_username]);
    if (!$receiver) json_out(['ok' => false, 'error' => 'No active user found with that username.']);
    if ((int) $receiver['id'] === (int) $user['id']) json_out(['ok' => false, 'error' => 'You cannot send Quiz Coins to yourself.']);

    $balance = wallet_balance($user['id']);
    if ($balance < $amount) json_out(['ok' => false, 'error' => 'Insufficient balance. You have ' . $balance . ' ' . coin_name() . '.']);

    $res = transfer_coins($user['id'], $receiver['id'], $amount);
    if (!$res['ok']) json_out(['ok' => false, 'error' => $res['msg']]);
    json_out(['ok' => true, 'msg' => $res['msg'], 'new_balance' => wallet_balance($user['id']), 'txn' => $res['txn'] ?? null]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
