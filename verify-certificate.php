<?php
/** QuizArena — Public certificate verification (by ID or QR scan) */
require __DIR__ . '/app/bootstrap.php';

$result = null;
$id = trim((string) get('id', ''));

if ($id !== '') {
    $result = verify_certificate($id);
}

$title = 'Verify Certificate';
$active = '';
require __DIR__ . '/app/layouts/site_header.php';
?>
<section class="page-head">
    <div class="container">
        <span class="eyebrow">Verification</span>
        <h1 class="display-6 fw-bold mb-0">Certificate Verification</h1>
        <p class="text-muted mb-0">Enter the certificate ID from a QuizArena certificate, or scan its QR code.</p>
    </div>
</section>
<section class="section">
    <div class="container" style="max-width:720px">
        <div class="qa-card p-4">
            <form method="get" class="d-flex gap-2">
                <input class="form-control" name="id" value="<?= e($id) ?>" placeholder="e.g. QA2026-AB12-CD34" required>
                <button class="btn btn-primary px-4">Verify</button>
            </form>
        </div>

        <?php if ($id !== '' && !$result): ?>
            <div class="qa-card p-4 mt-4 text-center border-danger">
                <div class="display-4 text-danger mb-2"><i class="bi bi-x-octagon-fill"></i></div>
                <h5 class="fw-bold text-danger">Certificate not found or invalid</h5>
                <p class="text-muted small mb-0">No valid certificate matches the ID "<b><?= e($id) ?></b>". Check the ID and try again.<br>Fake or altered certificates cannot be verified on this platform.</p>
            </div>
        <?php endif; ?>

        <?php if ($result): ?>
            <?php $ok = $result['status'] === 'valid'; ?>
            <div class="qa-card p-4 mt-4 text-center <?= $ok ? 'border-success' : 'border-danger' ?>">
                <div class="display-4 mb-2"><?= $ok ? '<i class="bi bi-patch-check-fill text-success"></i>' : '<i class="bi bi-x-octagon-fill text-danger"></i>' ?></div>
                <h4 class="fw-bold <?= $ok ? 'text-success' : 'text-danger' ?>"><?= $ok ? 'Certificate Verified ✔' : 'Certificate Invalid' ?></h4>
                <?php if ($ok): ?>
                    <p class="text-muted small">This certificate was issued by <?= e(setting('site_name', 'QuizArena')) ?> and is authentic.</p>
                    <div class="row text-start mt-4 g-3">
                        <div class="col-sm-6"><div class="stat-label">Student Name</div><div class="fw-semibold"><?= e($result['student_name']) ?></div></div>
                        <div class="col-sm-6"><div class="stat-label">Username</div><div class="fw-semibold">@<?= e($result['username']) ?></div></div>
                        <div class="col-sm-6"><div class="stat-label">Quiz</div><div class="fw-semibold"><?= e($result['quiz_title'] ?? '—') ?></div></div>
                        <div class="col-sm-6"><div class="stat-label">School</div><div class="fw-semibold"><?= e($result['school_name'] ?? '—') ?></div></div>
                        <div class="col-sm-6"><div class="stat-label">Score</div><div class="fw-semibold"><?= round((float) $result['score'], 1) ?> (<?= rtrim(rtrim(number_format((float) $result['percentage'], 1), '0'), '.') ?>%)</div></div>
                        <div class="col-sm-6"><div class="stat-label">Rank</div><div class="fw-semibold"><?= $result['rank'] ? ordinal((int) $result['rank']) : '—' ?></div></div>
                        <div class="col-sm-6"><div class="stat-label">Issue Date</div><div class="fw-semibold"><?= nice_date($result['issue_date']) ?></div></div>
                        <div class="col-sm-6"><div class="stat-label">Certificate ID</div><div class="fw-semibold"><?= e($result['certificate_id']) ?></div></div>
                    </div>
                    <div class="alert alert-success small mt-4 mb-0"><i class="bi bi-shield-check"></i> Official QuizArena verification record. This certificate ID is unique and cannot be duplicated.</div>
                <?php else: ?>
                    <p class="text-muted small">This certificate has been revoked by the platform and is no longer valid.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="qa-card p-4 mt-4">
            <h6 class="fw-bold"><i class="bi bi-qr-code"></i> How QR verification works</h6>
            <p class="text-muted small mb-0">Every QuizArena certificate carries a QR code that points to this verification page with its unique certificate ID. Scanning the QR code instantly shows whether the certificate is authentic — making fake certificates easy to spot.</p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/app/layouts/site_footer.php'; ?>
