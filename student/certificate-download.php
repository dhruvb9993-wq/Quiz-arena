<?php
/** QuizArena — Student: download own certificate PDF */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('student');

$cert_id = trim((string) get('id', ''));
$cert = dbrow("SELECT * FROM qa_certificates WHERE certificate_id = ? AND user_id = ?", [$cert_id, $user['id']]);
if (!$cert || !$cert['file_path']) { flash('danger', 'Certificate not found.'); redirect('student/certificates.php'); }

$file = __DIR__ . '/../assets/' . ltrim($cert['file_path'], '/');
if (!file_exists($file)) { flash('danger', 'Certificate file missing. Please contact your administrator.'); redirect('student/certificates.php'); }

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="QuizArena-Certificate-' . $cert['certificate_id'] . '.pdf"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
