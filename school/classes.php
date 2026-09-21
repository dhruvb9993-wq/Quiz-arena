<?php
/** QuizArena — School Admin: classes & sections management */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

if (is_post()) {
    csrf_check();
    $action = post('action', '');
    if ($action === 'add_class') {
        $name = trim((string) post('name', ''));
        if ($name !== '' && !dbval("SELECT COUNT(*) FROM qa_classes WHERE school_id = ? AND name = ?", [$sid, $name])) {
            dbq("INSERT INTO qa_classes (school_id, name, created_at) VALUES (?, ?, NOW())", [$sid, $name]);
            audit('class_add', 'Added class ' . $name . ' to school #' . $sid);
            flash('success', 'Class added.');
        } else flash('danger', 'Class name is required and must be unique.');
    } elseif ($action === 'delete_class') {
        $cid = (int) post('id', 0);
        if (dbval("SELECT COUNT(*) FROM qa_users WHERE school_id = ? AND class_id = ?", [$sid, $cid])) {
            flash('danger', 'This class has students. Move or delete the students first.');
        } else {
            dbq("DELETE FROM qa_sections WHERE class_id = ? AND school_id = ?", [$cid, $sid]);
            dbq("DELETE FROM qa_classes WHERE id = ? AND school_id = ?", [$cid, $sid]);
            audit('class_delete', 'Deleted class #' . $cid . ' in school #' . $sid);
            flash('success', 'Class deleted.');
        }
    } elseif ($action === 'add_section') {
        $cid = (int) post('class_id', 0);
        $name = trim((string) post('name', ''));
        if ($cid && $name !== '' && !dbval("SELECT COUNT(*) FROM qa_sections WHERE school_id = ? AND class_id = ? AND name = ?", [$sid, $cid, $name])) {
            dbq("INSERT INTO qa_sections (school_id, class_id, name, created_at) VALUES (?, ?, ?, NOW())", [$sid, $cid, $name]);
            audit('section_add', 'Added section ' . $name . ' to class #' . $cid);
            flash('success', 'Section added.');
        } else flash('danger', 'Section name is required and must be unique within the class.');
    } elseif ($action === 'delete_section') {
        $seid = (int) post('id', 0);
        if (dbval("SELECT COUNT(*) FROM qa_users WHERE school_id = ? AND section_id = ?", [$sid, $seid])) {
            flash('danger', 'This section has students. Move or delete the students first.');
        } else {
            dbq("DELETE FROM qa_sections WHERE id = ? AND school_id = ?", [$seid, $sid]);
            audit('section_delete', 'Deleted section #' . $seid);
            flash('success', 'Section deleted.');
        }
    }
    redirect('school/classes.php');
}

$classes = dball("SELECT * FROM qa_classes WHERE school_id = ? ORDER BY name", [$sid]);
$sections = dball("SELECT s.*, c.name AS class_name FROM qa_sections s JOIN qa_classes c ON c.id = s.class_id WHERE s.school_id = ? ORDER BY c.name, s.name", [$sid]);
$class_counts = [];
foreach (dball("SELECT class_id, COUNT(*) c FROM qa_users WHERE school_id = ? AND role = 'student' GROUP BY class_id", [$sid]) as $cc) {
    $class_counts[(int) $cc['class_id']] = (int) $cc['c'];
}

$title = 'Classes & Sections';
$active = 'classes';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Classes</h5></div>
            <div class="dash-card-body">
                <form method="post" class="d-flex gap-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_class">
                    <input class="form-control" name="name" placeholder="e.g. Class 6" required>
                    <button class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> Add</button>
                </form>
                <ul class="list-group list-group-flush">
                    <?php foreach ($classes as $c): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <div>
                                <span class="fw-semibold small"><?= e($c['name']) ?></span>
                                <span class="badge bg-light text-muted ms-2"><?= (int) ($class_counts[$c['id']] ?? 0) ?> students</span>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="text-muted fs-8"><?= (int) dbval("SELECT COUNT(*) FROM qa_sections WHERE class_id = ?", [$c['id']]) ?> sections</span>
                                <form method="post" data-confirm="Delete this class and its sections?"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_class">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-light text-danger p-1 px-2"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$classes): ?><li class="list-group-item text-muted small text-center py-3">No classes yet — add your first class above.</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Sections</h5></div>
            <div class="dash-card-body">
                <form method="post" class="row g-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_section">
                    <div class="col-md-5">
                        <select class="form-select" name="class_id" required>
                            <option value="0">Select class…</option>
                            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input class="form-control" name="name" placeholder="e.g. Section A" required>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-dash">
                        <thead><tr><th>Section</th><th>Class</th><th class="text-end">Students</th><th class="text-end"></th></tr></thead>
                        <tbody>
                        <?php foreach ($sections as $s): ?>
                            <tr>
                                <td class="fw-semibold small"><?= e($s['name']) ?></td>
                                <td class="small"><?= e($s['class_name']) ?></td>
                                <td class="text-end small"><?= (int) dbval("SELECT COUNT(*) FROM qa_users WHERE section_id = ? AND school_id = ?", [$s['id'], $sid]) ?></td>
                                <td class="text-end">
                                    <form method="post" data-confirm="Delete this section?"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_section">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <button class="btn btn-sm btn-light text-danger p-1 px-2"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$sections): ?><tr><td colspan="4" class="text-center text-muted py-3">No sections yet</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
