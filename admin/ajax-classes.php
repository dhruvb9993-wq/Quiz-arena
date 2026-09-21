<?php
/** QuizArena — Super Admin AJAX: classes/sections for a school */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');
header('Content-Type: application/json; charset=utf-8');

$school_id = (int) get('school_id', 0);
$class_id = (int) get('class_id', 0);

if ($class_id) {
    $sections = dball("SELECT id, name FROM qa_sections WHERE class_id = ? ORDER BY name", [$class_id]);
    json_out(['ok' => true, 'sections' => $sections]);
}
if ($school_id) {
    $classes = dball("SELECT id, name FROM qa_classes WHERE school_id = ? ORDER BY name", [$school_id]);
    json_out(['ok' => true, 'classes' => $classes]);
}
json_out(['ok' => false, 'error' => 'Missing parameter.'], 400);
