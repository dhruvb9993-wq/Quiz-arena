<?php
/** QuizArena — Public school lookup API (classes/sections by school code) */
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$action = get('action', '');

if ($action === 'classes') {
    $code = strtoupper(trim((string) get('code', '')));
    $school = $code ? dbrow("SELECT id, name FROM qa_schools WHERE code = ? AND status = 'active'", [$code]) : null;
    if (!$school) json_out(['ok' => false, 'error' => 'No active school found with that code.']);
    $classes = dball("SELECT id, name FROM qa_classes WHERE school_id = ? ORDER BY name", [$school['id']]);
    json_out(['ok' => true, 'school' => $school['name'], 'classes' => $classes]);
}

if ($action === 'sections') {
    $class_id = (int) get('class_id', 0);
    $sections = $class_id ? dball("SELECT id, name FROM qa_sections WHERE class_id = ? ORDER BY name", [$class_id]) : [];
    json_out(['ok' => true, 'sections' => $sections]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
