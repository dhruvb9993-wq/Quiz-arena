<?php
/** QuizArena — Logout */
require __DIR__ . '/app/bootstrap.php';
if (current_user()) logout_user();
flash('info', 'You have been signed out.');
redirect('login.php');
