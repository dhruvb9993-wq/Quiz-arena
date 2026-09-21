<?php
/** QuizArena — Contact (stores messages + email) */
require __DIR__ . '/app/bootstrap.php';
$title = 'Contact';
$active = 'contact';

$done = null;
$error = null;
if (is_post()) {
    csrf_check();
    $name = trim((string) post('name', ''));
    $email = trim((string) post('email', ''));
    $subject = trim((string) post('subject', ''));
    $message = trim((string) post('message', ''));
    if ($name === '' || !is_valid_email($email) || $message === '') {
        $error = 'Please fill in your name, a valid email and a message.';
    } else {
        dbq("INSERT INTO qa_contact_messages (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$name, $email, $subject, $message]);
        send_mail(setting('site_email', 'admin@example.com'), 'New contact message: ' . $subject,
            '<p><b>From:</b> ' . e($name) . ' (' . e($email) . ')</p><p>' . nl2br(e($message)) . '</p>');
        $done = 'Thank you, ' . e($name) . '! Your message has been sent. We will get back to you shortly.';
    }
}

require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container">
        <span class="eyebrow">Contact</span>
        <h1 class="display-6 fw-bold mb-0">We'd love to hear from you</h1>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-5">
                <h5 class="fw-bold mb-3">Get in touch</h5>
                <div class="d-flex align-items-start gap-3 mb-3">
                    <span class="icon-tile tile-indigo"><i class="bi bi-envelope"></i></span>
                    <div><b>Email</b><br><span class="text-muted small"><?= e(setting('site_email', 'support@example.com')) ?></span></div>
                </div>
                <div class="d-flex align-items-start gap-3 mb-3">
                    <span class="icon-tile tile-violet"><i class="bi bi-telephone"></i></span>
                    <div><b>Phone</b><br><span class="text-muted small"><?= e(setting('site_phone', '+91 00000 00000')) ?></span></div>
                </div>
                <div class="d-flex align-items-start gap-3">
                    <span class="icon-tile tile-emerald"><i class="bi bi-geo-alt"></i></span>
                    <div><b>Address</b><br><span class="text-muted small"><?= e(setting('site_address', 'India')) ?></span></div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="qa-card p-4">
                    <?php if ($done): ?><div class="alert alert-success py-2"><?= e($done) ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Your Name</label>
                                <input class="form-control" name="name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Email</label>
                                <input class="form-control" type="email" name="email" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Subject</label>
                                <input class="form-control" name="subject" placeholder="School plan enquiry">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Message</label>
                                <textarea class="form-control" name="message" rows="5" required></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary rounded-pill px-4" type="submit">Send Message</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
