<?php
/** QuizArena — Super Admin: all certificates */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('superadmin');

$q = trim((string) get('q', ''));
$school_id = (int) get('school_id', 0);
$status = get('status', '');
$page = (int) get('p', 1);

$where = "WHERE 1=1";
$params = [];
if ($q !== '') { $where .= " AND (c.certificate_id LIKE ? OR c.student_name LIKE ? OR c.username LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($school_id) { $where .= " AND c.school_id = ?"; $params[] = $school_id; }
if ($status !== '') { $where .= " AND c.status = ?"; $params[] = $status; }

$total = (int) dbval("SELECT COUNT(*) FROM qa_certificates c $where", $params);
[$off, $per, $page, $pages, $pager] = paginate($total, 15, $page, 'admin/certificates.php', ['q' => $q, 'school_id' => $school_id, 'status' => $status]);

$rows = dball(
    "SELECT c.*, q.title AS quiz_title, s.name AS school_name
       FROM qa_certificates c
       LEFT JOIN qa_quizzes q ON q.id = c.quiz_id
       LEFT JOIN qa_schools s ON s.id = c.school_id
       $where ORDER BY c.id DESC LIMIT $per OFFSET $off", $params
);

$schools = dball("SELECT id, name FROM qa_schools ORDER BY name");

if (is_post() && post('action') === 'revoke') {
    csrf_check();
    $cid = (int) post('id', 0);
    dbq("UPDATE qa_certificates SET status = 'revoked' WHERE id = ?", [$cid]);
    audit('certificate_revoke', 'Revoked certificate #' . $cid);
    flash('success', 'Certificate revoked. It will no longer verify as valid.');
    redirect('admin/certificates.php');
}

$title = 'All Certificates';
$active = 'certificates';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control form-control-sm" style="width:220px" name="q" placeholder="Certificate ID, student…" value="<?= e($q) ?>">
        <select class="form-select form-select-sm" style="width:180px" name="school_id">
            <option value="0">All schools</option>
            <?php foreach ($schools as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $school_id === (int) $s['id'] ? 'selected' : '' ?>><?= e(mb_substr($s['name'], 0, 26)) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" style="width:130px" name="status">
            <option value="">All statuses</option>
            <option value="valid" <?= $status === 'valid' ? 'selected' : '' ?>>Valid</option>
            <option value="revoked" <?= $status === 'revoked' ? 'selected' : '' ?>>Revoked</option>
        </select>
        <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dash">
            <thead><tr><th>Certificate ID</th><th>Student</th><th>Quiz</th><th>School</th><th class="text-end">Score</th><th>%</th><th>Status</th><th>Issued</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td class="small fw-semibold"><?= e($c['certificate_id']) ?></td>
                    <td class="small"><?= e($c['student_name']) ?> <span class="text-muted fs-8">@<?= e($c['username']) ?></span></td>
                    <td class="small"><?= e(mb_substr($c['quiz_title'] ?? '—', 0, 28)) ?></td>
                    <td class="small"><?= e(mb_substr($c['school_name'] ?? '—', 0, 22)) ?></td>
                    <td class="text-end"><?= round((float) $c['score'], 1) ?></td>
                    <td><?= pct_badge($c['percentage']) ?></td>
                    <td><span class="badge <?= $c['status'] === 'valid' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= ucfirst(e($c['status'])) ?></span></td>
                    <td class="small text-muted"><?= nice_date($c['issue_date']) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= url('verify-certificate.php?id=' . urlencode($c['certificate_id'])) ?>" target="_blank"><i class="bi bi-shield-check"></i></a>
                        <?php if ($c['status'] === 'valid'): ?>
                            <form method="post" class="d-inline" data-confirm="Revoke this certificate? It will no longer verify as valid."><?= csrf_field() ?>
                                <input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button class="btn btn-sm btn-light text-danger"><i class="bi bi-x-circle"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No certificates found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager) echo '<div class="p-2">' . $pager . '</div>'; ?>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
