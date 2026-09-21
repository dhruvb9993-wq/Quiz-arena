<?php
/** QuizArena — School Admin: bulk import students from CSV */
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('school_admin');
$sid = (int) $user['school_id'];
require_school($sid);

$classes = dball("SELECT * FROM qa_classes WHERE school_id = ? ORDER BY name", [$sid]);
$result = null;

if (is_post()) {
    csrf_check();
    $default_class = (int) post('default_class', 0) ?: null;
    $password = (string) post('default_password', '');
    if (strlen($password) < 8) {
        flash('danger', 'Set a default password of at least 8 characters.');
    } else {
        $csv = read_csv_upload($_FILES['csv'] ?? []);
        if (!$csv['ok']) {
            flash('danger', $csv['error']);
        } else {
            $rows = $csv['rows'];
            // Detect header row
            $header = array_map('strtolower', $rows[0] ?? []);
            $has_header = count(array_intersect($header, ['name', 'full_name', 'fullname', 'student name', 'username', 'email'])) > 0;
            if ($has_header) array_shift($rows);

            $imported = 0; $skipped = 0; $errors = [];
            $class_map = [];
            foreach ($classes as $c) $class_map[strtolower($c['name'])] = (int) $c['id'];

            db_begin();
            try {
                foreach ($rows as $ri => $r) {
                    $line = $ri + 2;
                    $name = trim((string) ($r[0] ?? ''));
                    $username = clean_username($r[1] ?? '');
                    $email = strtolower(trim((string) ($r[2] ?? '')));
                    $mobile = trim((string) ($r[3] ?? ''));
                    $class_name = trim((string) ($r[4] ?? ''));
                    $section_name = trim((string) ($r[5] ?? ''));

                    if ($name === '' && $username === '') { $skipped++; continue; }
                    if ($name === '') { $errors[] = "Line $line: missing name"; $skipped++; continue; }

                    if ($username === '') {
                        // generate a username from the name
                        $base = strtolower(preg_replace('/[^A-Za-z0-9_.]/', '', str_replace(' ', '_', $name)));
                        $username = $base;
                        $i = 1;
                        while (dbval("SELECT COUNT(*) FROM qa_users WHERE username = ?", [$username])) { $username = $base . '_' . ($i++); }
                    } elseif (!username_valid($username)) { $errors[] = "Line $line: invalid username"; $skipped++; continue; }

                    if (dbval("SELECT COUNT(*) FROM qa_users WHERE username = ?", [$username])) { $errors[] = "Line $line: duplicate username @$username"; $skipped++; continue; }
                    if ($email !== '' && dbval("SELECT COUNT(*) FROM qa_users WHERE email = ?", [$email])) { $errors[] = "Line $line: duplicate email $email"; $skipped++; continue; }

                    $class_id = null; $section_id = null;
                    if ($class_name !== '') {
                        $class_id = $class_map[strtolower($class_name)] ?? null;
                        if (!$class_id) { $errors[] = "Line $line: unknown class \"$class_name\""; }
                    }
                    if (!$class_id) $class_id = $default_class;
                    if ($class_id && $section_name !== '') {
                        $section_id = (int) dbval("SELECT id FROM qa_sections WHERE school_id = ? AND class_id = ? AND LOWER(name) = LOWER(?)", [$sid, $class_id, $section_name]);
                        if (!$section_id) $errors[] = "Line $line: unknown section \"$section_name\" in class";
                    }

                    dbq("INSERT INTO qa_users (school_id, role, full_name, username, email, password_hash, mobile, class_id, section_id, status, created_at, updated_at)
                         VALUES (?, 'student', ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?, 'active', NOW(), NOW())",
                        [$sid, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $mobile, $class_id, $section_id]);
                    $uid = db_id();
                    ensure_wallet($uid, $sid);
                    grant_signup_bonus($uid);
                    $imported++;
                }
                db_commit();
                audit('students_import', "Imported $imported students via CSV for school #$sid");
                $result = ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 30)];
            } catch (Throwable $e) {
                db_rollback();
                $result = ['imported' => 0, 'skipped' => 0, 'errors' => ['Import failed: ' . $e->getMessage()]];
            }
        }
    }
}

$title = 'Import Students';
$active = 'students';
require __DIR__ . '/../app/layouts/dash_header.php';
?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Upload CSV File</h5></div>
            <div class="dash-card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">CSV File</label>
                        <input class="form-control" type="file" name="csv" accept=".csv" required>
                        <div class="form-text">Columns: <b>Name, Username, Email, Mobile, Class, Section</b> (optional header row allowed).</div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Default Class (used when a class is not listed)</label>
                            <select class="form-select" name="default_class">
                                <option value="0">— None —</option>
                                <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Default Password for all imported students</label>
                            <input class="form-control" type="text" name="default_password" value="Student@123" required>
                        </div>
                    </div>
                    <button class="btn btn-primary px-4"><i class="bi bi-upload"></i> Import Students</button>
                </form>

                <hr>
                <h6 class="fw-bold"><i class="bi bi-filetype-csv"></i> Sample CSV</h6>
                <pre class="bg-light rounded p-3 small mb-0">Name,Username,Email,Mobile,Class,Section
Aarav Sharma,aarav_07,aarav@example.com,9876543210,Class 6,Section A
Diya Patel,diya_p,diya@example.com,9876543211,Class 6,Section A</pre>
                <button class="btn btn-sm btn-outline-primary mt-2" data-copy="Name,Username,Email,Mobile,Class,Section&#10;Aarav Sharma,aarav_07,aarav@example.com,9876543210,Class 6,Section A&#10;Diya Patel,diya_p,diya@example.com,9876543211,Class 6,Section A"><i class="bi bi-clipboard"></i> Copy sample</button>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <?php if ($result): ?>
            <div class="dash-card">
                <div class="dash-card-header"><h5 class="dash-card-title">Import Result</h5></div>
                <div class="dash-card-body">
                    <div class="row text-center g-3 mb-3">
                        <div class="col-4"><div class="stat-value text-success"><?= $result['imported'] ?></div><div class="stat-label">Imported</div></div>
                        <div class="col-4"><div class="stat-value text-warning"><?= $result['skipped'] ?></div><div class="stat-label">Skipped</div></div>
                        <div class="col-4"><div class="stat-value text-danger"><?= count($result['errors']) ?></div><div class="stat-label">Issues</div></div>
                    </div>
                    <?php if ($result['errors']): ?>
                        <ul class="small text-danger mb-0" style="max-height:220px;overflow-y:auto">
                            <?php foreach ($result['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-primary mt-3" href="<?= url('school/students.php') ?>">View Students</a>
                </div>
            </div>
        <?php endif; ?>
        <div class="dash-card">
            <div class="dash-card-header"><h5 class="dash-card-title">Tips</h5></div>
            <div class="dash-card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li>Missing usernames are generated automatically from the name.</li>
                    <li>Every student gets a wallet and the sign-up bonus automatically.</li>
                    <li>Rows with duplicate usernames or emails are skipped with a note.</li>
                    <li>Class and Section columns must match names already created in your school.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../app/layouts/dash_footer.php'; ?>
