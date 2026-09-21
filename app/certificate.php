<?php
/**
 * QuizArena — Certificate engine.
 * Generates a professional PDF certificate (with QR code) when a student
 * passes an eligible quiz, and powers public verification.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

const CERT_DIR = __DIR__ . '/../assets/uploads/certificates/';

/**
 * Create the certificate record + PDF for a passed attempt.
 * Idempotent: one certificate per attempt (unique key on attempt_id).
 *
 * @return array{ok:bool, certificate_id:?string, error:?string}
 */
function generate_certificate($attempt) {
    // Already issued for this attempt?
    $existing = dbrow("SELECT * FROM qa_certificates WHERE attempt_id = ?", [$attempt['id']]);
    if ($existing) return ['ok' => true, 'certificate_id' => $existing['certificate_id'], 'error' => null];

    $user  = dbrow("SELECT * FROM qa_users WHERE id = ?", [$attempt['user_id']]);
    $quiz  = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$attempt['quiz_id']]);
    $school = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$attempt['school_id']]);
    if (!$user || !$quiz) return ['ok' => false, 'certificate_id' => null, 'error' => 'Missing user or quiz data.'];

    $cert_id = cert_id();
    while (dbval("SELECT COUNT(*) FROM qa_certificates WHERE certificate_id = ?", [$cert_id])) {
        $cert_id = cert_id();
    }

    $issue_date = date('Y-m-d H:i:s');
    dbq(
        "INSERT INTO qa_certificates
            (certificate_id, attempt_id, user_id, quiz_id, school_id, student_name, username,
             score, percentage, rank, issue_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'valid')",
        [$cert_id, $attempt['id'], $user['id'], $quiz['id'], $quiz['school_id'],
         $user['full_name'], $user['username'], $attempt['score'], $attempt['percentage'],
         $attempt['rank'], $issue_date]
    );
    $cert_id_db = db_id();

    // Build PDF
    $filename = 'cert_' . $cert_id . '.pdf';
    $ok = build_certificate_pdf($cert_id, $filename);
    if ($ok) {
        dbq("UPDATE qa_certificates SET file_path = ? WHERE id = ?", ['uploads/certificates/' . $filename, $cert_id_db]);
    }

    return ['ok' => true, 'certificate_id' => $cert_id, 'error' => null];
}

/**
 * Render the certificate PDF to disk.
 * @return bool
 */
function build_certificate_pdf($cert_id, $filename) {
    if (!is_dir(CERT_DIR) && !@mkdir(CERT_DIR, 0755, true)) return false;

    $cert = dbrow("SELECT * FROM qa_certificates WHERE certificate_id = ?", [$cert_id]);
    if (!$cert) return false;
    $quiz    = dbrow("SELECT * FROM qa_quizzes WHERE id = ?", [$cert['quiz_id']]);
    $school  = dbrow("SELECT * FROM qa_schools WHERE id = ?", [$cert['school_id']]);
    $user    = dbrow("SELECT * FROM qa_users WHERE id = ?", [$cert['user_id']]);
    $attempt = dbrow("SELECT * FROM qa_quiz_attempts WHERE id = ?", [$cert['attempt_id']]);

    $site_name = setting('site_name', 'QuizArena');
    $verify_url = url('verify-certificate.php?id=' . urlencode($cert['certificate_id']));

    require_once __DIR__ . '/libs/tcpdf/tcpdf.php';

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('QuizArena');
    $pdf->SetTitle('Certificate of Achievement — ' . $cert['student_name']);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    $w = 297; $h = 210;
    $mx = 12; $my = 12;

    // Outer decorative frame
    $pdf->SetLineWidth(1.4);
    $pdf->SetDrawColor(79, 70, 229);
    $pdf->Rect($mx, $my, $w - 2 * $mx, $h - 2 * $my);
    $pdf->SetLineWidth(0.4);
    $pdf->SetDrawColor(124, 58, 237);
    $pdf->Rect($mx + 3, $my + 3, $w - 2 * $mx - 6, $h - 2 * $my - 6);

    // Corner accents
    $pdf->SetFillColor(79, 70, 229);
    $pdf->Rect($mx + 3, $my + 3, 16, 1.6, 'F');
    $pdf->Rect($w - $mx - 19, $my + 3, 16, 1.6, 'F');
    $pdf->Rect($mx + 3, $h - $my - 4.6, 16, 1.6, 'F');
    $pdf->Rect($w - $mx - 19, $h - $my - 4.6, 16, 1.6, 'F');

    // School logo (top-left)
    $logo_rel = $school['logo'] ?? null;
    if ($logo_rel && file_exists(__DIR__ . '/../assets/' . $logo_rel)) {
        $pdf->Image(__DIR__ . '/../assets/' . $logo_rel, 24, 22, 26, 26, '', '', 'T');
    } else {
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(79, 70, 229);
        $pdf->SetXY(24, 28);
        $pdf->Cell(26, 26, $school['name'] ? mb_substr($school['name'], 0, 2) : 'QA', 0, 1, 'C');
    }

    // Site header (top-right)
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(79, 70, 229);
    $pdf->SetXY(0, 22);
    $pdf->Cell($w - 26, 8, $site_name, 0, 1, 'R');
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell($w - 26, 5, 'Online Quiz Platform', 0, 1, 'R');

    // Center content
    $pdf->SetY(48);
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->SetTextColor(120, 120, 130);
    $pdf->Cell(0, 8, 'C E R T I F I C A T E   O F   A C H I E V E M E N T', 0, 1, 'C');

    $pdf->SetFont('dejavusans', '', 12);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->SetY(60);
    $pdf->Cell(0, 8, 'This certificate is proudly presented to', 0, 1, 'C');

    // Student name
    $pdf->SetFont('dejavusans', 'B', 34);
    $pdf->SetTextColor(30, 30, 40);
    $pdf->SetY(72);
    $pdf->Cell(0, 16, $cert['student_name'], 0, 1, 'C');

    // Underline rule
    $name_w = $pdf->GetStringWidth($cert['student_name']);
    $pdf->SetDrawColor(124, 58, 237);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(($w - $name_w) / 2, 90, ($w + $name_w) / 2, 90);

    $pdf->SetFont('dejavusans', '', 12);
    $pdf->SetTextColor(90, 90, 100);
    $pdf->SetY(96);
    $pdf->Cell(0, 8, 'for successfully completing the online quiz', 0, 1, 'C');

    // Quiz name
    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(0, 10, $quiz['title'] ?? 'Quiz', 0, 1, 'C');

    // Details
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->SetTextColor(70, 70, 80);
    $pdf->Cell(0, 7, 'Score: ' . rtrim(rtrim(number_format((float) $cert['score'], 1), '0'), '.') . ' / ' . rtrim(rtrim(number_format((float) ($attempt['total_marks'] ?? $cert['score']), 1), '0'), '.')
        . '     |     Percentage: ' . rtrim(rtrim(number_format((float) $cert['percentage'], 1), '0'), '.') . '%'
        . ($cert['rank'] ? '     |     Rank: ' . ordinal($cert['rank']) : ''), 0, 1, 'C');

    $pdf->Cell(0, 7, 'School: ' . ($school['name'] ?? '—'), 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetTextColor(120, 120, 130);
    $pdf->Cell(0, 6, 'Username: @' . $cert['username'], 0, 1, 'C');

    // Issue date + signature lines
    $pdf->SetY(150);
    $pdf->SetDrawColor(160, 160, 170);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(30, 160, 110, 160);
    $pdf->Line(190, 160, 267, 160);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(60, 60, 70);
    $pdf->SetXY(30, 163); $pdf->Cell(80, 5, 'Issue Date', 0, 0, 'C');
    $pdf->SetXY(190, 163); $pdf->Cell(77, 5, 'Authorised Signatory', 0, 0, 'C');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(110, 110, 120);
    $pdf->SetXY(30, 168); $pdf->Cell(80, 5, date('d F Y', strtotime($cert['issue_date'])), 0, 0, 'C');
    $pdf->SetXY(190, 168); $pdf->Cell(77, 5, $site_name, 0, 0, 'C');

    // Certificate ID + QR (bottom-right)
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(79, 70, 229);
    $pdf->SetXY(0, 176);
    $pdf->Cell($w - 26, 6, 'Certificate ID: ' . $cert['certificate_id'], 0, 1, 'R');
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->SetTextColor(130, 130, 140);
    $pdf->SetXY(0, 182);
    $pdf->Cell($w - 26, 5, 'Verify at: ' . $verify_url, 0, 1, 'R');

    $pdf->write2DBarcode($verify_url, 'QRCODE,H', 232, 150, 34, 34, ['border' => 0, 'padding' => 1]);

    $dest = CERT_DIR . $filename;
    $pdf->Output($dest, 'F');
    @chmod($dest, 0644);
    return file_exists($dest);
}

function ordinal($n) {
    $n = (int) $n;
    $suffix = 'th';
    if ($n % 100 < 11 || $n % 100 > 13) {
        $suffix = ['st', 'nd', 'rd'][$n % 10 - 1] ?? 'th';
    }
    return $n . $suffix;
}

/**
 * Look up a certificate by ID for public verification.
 * @return array|null cert row joined with quiz + school
 */
function verify_certificate($certificate_id) {
    $row = dbrow(
        "SELECT c.*, q.title AS quiz_title, q.pass_percentage, s.name AS school_name
           FROM qa_certificates c
           LEFT JOIN qa_quizzes q ON q.id = c.quiz_id
           LEFT JOIN qa_schools s ON s.id = c.school_id
          WHERE c.certificate_id = ?",
        [$certificate_id]
    );
    return $row;
}
