<?php
/**
 * QuizArena — Mailer (PHP mail()). Works out of the box on Hostinger.
 * Structure is ready for SMTP later (e.g. PHPMailer) without touching other code.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/**
 * Send an HTML email using PHP's mail() function.
 * @return bool true if accepted by the mail transport
 */
function send_mail($to, $subject, $html_body, $alt_body = '') {
    $site = setting('site_name', 'QuizArena');
    $from_email = setting('site_email', 'noreply@' . (parse_url(BASE_URL ?? '', PHP_URL_HOST) ?: 'localhost'));
    $from_name = $site;

    $boundary = 'qa-' . md5(uniqid('', true));

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    $headers[] = 'Reply-To: ' . $from_email;
    $headers[] = 'X-Mailer: QuizArena';

    $plain = $alt_body !== '' ? $alt_body : trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</h1>', '</h2>', '</li>'], "\n", $html_body))));

    $body  = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n\r\n";
    $body .= $plain . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n\r\n";
    $body .= $html_body . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

    // Debug log (helps during local testing without an SMTP server)
    @file_put_contents(
        __DIR__ . '/logs/mail.log',
        '[' . date('Y-m-d H:i:s') . '] ' . ($ok ? 'SENT' : 'FAILED') . " to=$to subj=$subject\n",
        FILE_APPEND
    );
    return $ok;
}
