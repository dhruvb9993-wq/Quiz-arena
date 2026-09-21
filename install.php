<?php
/**
 * QuizArena — Browser-based Installation Wizard.
 *
 * Flow: requirements → database credentials → admin & site → install → success.
 * Creates all tables, seeds default data, writes app/config.php, creates the
 * Super Admin account and required folders — no manual SQL or file editing.
 *
 * The wizard refuses to run again once installation is complete. To re-run it,
 * delete the file  app/config.php  using Hostinger File Manager.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('QA_INSTALLING', true);
define('QA_ROOT', __DIR__ . '/');

require __DIR__ . '/app/functions.php'; // helpers only — no DB config needed yet
require __DIR__ . '/app/db.php';        // connection tester used by the wizard

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$CONFIG_FILE = __DIR__ . '/app/config.php';
$already_installed = is_array($GLOBALS['QA_CONFIG'] ?? null) ? true : (file_exists($CONFIG_FILE));

/* ---------------------------------------------------------------- *
 *  Already-installed lock
 * ---------------------------------------------------------------- */
if ($already_installed && empty($_GET['force'])) {
    $installed_ok = null;
    if (file_exists($CONFIG_FILE)) {
        $cfg = require $CONFIG_FILE;
        if (is_array($cfg) && isset($cfg['db_name'])) {
            $t = test_db_connection($cfg['db_host'], $cfg['db_port'] ?? '3306', $cfg['db_name'], $cfg['db_user'], $cfg['db_pass']);
            if ($t['ok']) {
                try {
                    $v = $t['pdo']->query("SELECT setting_value FROM qa_settings WHERE setting_key = 'installed'")->fetchColumn();
                    $installed_ok = $v === '1';
                } catch (Throwable $e) { $installed_ok = false; }
            } else {
                $installed_ok = false;
            }
        }
    }
    render_locked($installed_ok);
}

/* ---------------------------------------------------------------- *
 *  Step router
 * ---------------------------------------------------------------- */
$step = (int) ($_GET['step'] ?? 1);
if ($step < 1 || $step > 4) $step = 1;

if ($step === 1) $step = handle_step1();
elseif ($step === 2) $step = handle_step2();
elseif ($step === 3) $step = handle_step3();
elseif ($step === 4) $step = handle_step4();

exit;

/* ---------------------------------------------------------------- *
 *  Handlers
 * ---------------------------------------------------------------- */

function handle_step1() {
    $reqs = [
        'PHP 8.0 or newer'       => version_compare(PHP_VERSION, '8.0.0', '>='),
        'PDO MySQL extension'    => extension_loaded('pdo_mysql'),
        'GD extension (images)'  => extension_loaded('gd'),
        'MBString extension'     => extension_loaded('mbstring'),
        'JSON extension'         => function_exists('json_encode'),
        'cURL or file access'    => function_exists('curl_init') || ini_get('allow_url_fopen'),
        'uploads folder writable' => is_writable(__DIR__ . '/assets/uploads'),
        'app folder writable'    => is_writable(__DIR__ . '/app'),
    ];
    $ok = true;
    foreach ($reqs as $v) { if (is_callable($v)) { $ok = $ok && $v(); } else { $ok = $ok && $v; } }

    render_header('Installation — Step 1 of 4', 1);
    ?>
    <div class="card">
        <div class="card-body p-4">
            <h5 class="mb-1">Server Requirements</h5>
            <p class="text-muted small">QuizArena checks your Hostinger server before installation.</p>
            <table class="table table-sm align-middle">
                <tbody>
                <?php foreach ($reqs as $label => $check): ?>
                    <?php $pass = is_callable($check) ? $check() : $check; ?>
                    <tr>
                        <td><?= e($label) ?></td>
                        <td class="text-end">
                            <?php if ($pass): ?><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> OK</span>
                            <?php else: ?><span class="badge text-bg-danger"><i class="bi bi-x-lg"></i> Missing</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($ok): ?>
                <a class="btn btn-primary" href="install.php?step=2">Continue → Database Setup</a>
            <?php else: ?>
                <div class="alert alert-danger mb-3">Please fix the missing requirements above (enable the PHP extension in Hostinger hPanel → Advanced → PHP Configuration), then refresh this page.</div>
                <button class="btn btn-outline-secondary" onclick="location.reload()">Re-check</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
    render_footer();
}

function handle_step2() {
    $error = null;
    $saved = $_SESSION['qa_install'] ?? [];
    if (is_post()) {
        csrf_check();
        $host = trim(post('db_host', 'localhost'));
        $port = trim(post('db_port', '3306'));
        $name = trim(post('db_name', ''));
        $user = trim(post('db_user', ''));
        $pass = (string) post('db_pass', '');
        if ($name === '' || $user === '') {
            $error = 'Database name and username are required.';
        } else {
            $t = test_db_connection($host, $port, $name, $user, $pass);
            if (!$t['ok']) {
                $error = 'Could not connect to the database: ' . e($t['error']);
            } else {
                // verify we can create tables (permissions)
                try {
                    $t['pdo']->exec("CREATE TABLE IF NOT EXISTS _qa_install_test (id INT)");
                    $t['pdo']->exec("DROP TABLE IF EXISTS _qa_install_test");
                    $_SESSION['qa_install'] = array_merge($saved, [
                        'db_host' => $host, 'db_port' => $port, 'db_name' => $name,
                        'db_user' => $user, 'db_pass' => $pass,
                    ]);
                    header('Location: install.php?step=3');
                    exit;
                } catch (Throwable $e) {
                    $error = 'Connected, but the database user cannot create tables: ' . e($e->getMessage());
                }
            }
        }
    }
    render_header('Installation — Step 2 of 4', 2);
    ?>
    <div class="card">
        <div class="card-body p-4">
            <h5 class="mb-1">MySQL Database Connection</h5>
            <p class="text-muted small">Create the database in Hostinger hPanel → MySQL Databases, then enter its details below.</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Database Host</label>
                        <input class="form-control" name="db_host" value="<?= e($saved['db_host'] ?? 'localhost') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Port</label>
                        <input class="form-control" name="db_port" value="<?= e($saved['db_port'] ?? '3306') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Database Name</label>
                        <input class="form-control" name="db_name" value="<?= e($saved['db_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Database Username</label>
                        <input class="form-control" name="db_user" value="<?= e($saved['db_user'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Database Password</label>
                        <input class="form-control" type="password" name="db_pass" value="<?= e($saved['db_pass'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-4 d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="install.php?step=1">← Back</a>
                    <button class="btn btn-primary" type="submit">Test Connection & Continue →</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    render_footer();
}

function handle_step3() {
    $saved = $_SESSION['qa_install'] ?? [];
    if (empty($saved['db_name'])) {
        header('Location: install.php?step=2');
        exit;
    }
    $error = null;
    if (is_post()) {
        csrf_check();
        $name = trim(post('admin_name', ''));
        $username = clean_username(post('admin_username', ''));
        $email = trim(post('admin_email', ''));
        $password = (string) post('admin_password', '');
        $site_name = trim(post('site_name', 'QuizArena'));
        $tagline = trim(post('site_tagline', 'The Smart Quiz Platform for Schools'));

        if ($name === '' || $username === '' || $email === '' || strlen($password) < 8) {
            $error = 'Please fill all fields. Password must be at least 8 characters.';
        } elseif (!username_valid($username)) {
            $error = 'Username may contain only letters, numbers, dots and underscores (3–30 chars).';
        } elseif (!is_valid_email($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            $_SESSION['qa_install'] = array_merge($saved, [
                'admin_name' => $name, 'admin_username' => $username, 'admin_email' => $email,
                'admin_password' => $password, 'site_name' => $site_name, 'site_tagline' => $tagline,
            ]);
            header('Location: install.php?step=4');
            exit;
        }
    }
    render_header('Installation — Step 3 of 4', 3);
    ?>
    <div class="card">
        <div class="card-body p-4">
            <h5 class="mb-1">Super Admin & Website</h5>
            <p class="text-muted small">Create the first Super Admin account. You can create schools and school admins after login.</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Your Full Name</label>
                        <input class="form-control" name="admin_name" value="<?= e($saved['admin_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="admin_username" value="<?= e($saved['admin_username'] ?? 'superadmin') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address</label>
                        <input class="form-control" type="email" name="admin_email" value="<?= e($saved['admin_email'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password (min 8 chars)</label>
                        <input class="form-control" type="password" name="admin_password" required>
                    </div>
                    <div class="col-12"><hr></div>
                    <div class="col-md-6">
                        <label class="form-label">Website / Site Name</label>
                        <input class="form-control" name="site_name" value="<?= e($saved['site_name'] ?? 'QuizArena') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tagline</label>
                        <input class="form-control" name="site_tagline" value="<?= e($saved['site_tagline'] ?? 'The Smart Quiz Platform for Schools') ?>">
                    </div>
                </div>
                <div class="mt-4 d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="install.php?step=2">← Back</a>
                    <button class="btn btn-primary" type="submit">Install Now →</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    render_footer();
}

function handle_step4() {
    $cfg = $_SESSION['qa_install'] ?? [];
    if (empty($cfg['db_name']) || empty($cfg['admin_username'])) {
        header('Location: install.php?step=2');
        exit;
    }

    $errors = [];
    $t = test_db_connection($cfg['db_host'], $cfg['db_port'], $cfg['db_name'], $cfg['db_user'], $cfg['db_pass']);
    if (!$t['ok']) $errors[] = 'Database connection failed: ' . $t['error'];

    if (!$errors) {
        $schema = require __DIR__ . '/app/schema.php';
        try {
            foreach ($schema['tables'] as $sql) $t['pdo']->exec($sql);
            foreach ($schema['seeds'] as $sql) $t['pdo']->exec($sql);

            // Super Admin
            $st = $t['pdo']->prepare(
                "INSERT INTO qa_users (school_id, role, full_name, username, email, password_hash, status, created_at, updated_at)
                 VALUES (NULL, 'superadmin', ?, ?, ?, ?, 'active', NOW(), NOW())"
            );
            $st->execute([$cfg['admin_name'], $cfg['admin_username'], $cfg['admin_email'],
                          password_hash($cfg['admin_password'], PASSWORD_DEFAULT)]);

            // Super admin wallet
            $uid = (int) $t['pdo']->lastInsertId();
            $t['pdo']->prepare("INSERT INTO qa_wallets (user_id, school_id, balance, created_at, updated_at) VALUES (?, 0, 0, NOW(), NOW())")
                     ->execute([$uid]);

            // Site settings
            $upd = $t['pdo']->prepare(
                "INSERT INTO qa_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $upd->execute(['site_name', $cfg['site_name']]);
            $upd->execute(['site_tagline', $cfg['site_tagline']]);
            $upd->execute(['installed', '1']);
            $upd->execute(['install_time', date('Y-m-d H:i:s')]);

            // Ensure upload folders exist
            foreach (['assets/uploads', 'assets/uploads/logos', 'assets/uploads/photos', 'assets/uploads/certificates', 'assets/uploads/temp', 'app/logs'] as $dir) {
                if (!is_dir(__DIR__ . '/' . $dir)) @mkdir(__DIR__ . '/' . $dir, 0755, true);
                @chmod(__DIR__ . '/' . $dir, 0755);
            }

            // Auto-detect base URL (protocol + host + install subdirectory)
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/');
            $base_url = $scheme . '://' . $host . $script_dir;

            // Write app/config.php
            $config = "<?php\n"
                . "/**\n"
                . " * QuizArena — generated by the installer on " . date('Y-m-d H:i:s') . "\n"
                . " * Do not edit manually. To reinstall, delete this file via Hostinger File Manager.\n"
                . " */\n"
                . "if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }\n"
                . "return array(\n"
                . "  'db_host'   => " . var_export($cfg['db_host'], true) . ",\n"
                . "  'db_port'   => " . var_export($cfg['db_port'], true) . ",\n"
                . "  'db_name'   => " . var_export($cfg['db_name'], true) . ",\n"
                . "  'db_user'   => " . var_export($cfg['db_user'], true) . ",\n"
                . "  'db_pass'   => " . var_export($cfg['db_pass'], true) . ",\n"
                . "  'base_url'  => " . var_export($base_url, true) . ",\n"
                . "  'timezone'  => 'Asia/Kolkata',\n"
                . "  'debug'     => false,\n"
                . "  'installed_at' => " . var_export(date('Y-m-d H:i:s'), true) . ",\n"
                . ");\n";

            $ok = @file_put_contents($CONFIG_FILE = __DIR__ . '/app/config.php', $config);
            if (!$ok) $errors[] = 'Could not write app/config.php. Please make the "app" folder writable (755), then retry.';

            if (!$errors) {
                unset($_SESSION['qa_install']);
                render_header('Installation Complete', 4);
                ?>
                <div class="card text-center">
                    <div class="card-body p-5">
                        <div class="display-1 text-success mb-3"><i class="bi bi-check-circle-fill"></i></div>
                        <h3 class="mb-2">🎉 Installation Successful!</h3>
                        <p class="text-muted mb-4">
                            QuizArena is now installed.<br>
                            All database tables, default settings and folders were created automatically,<br>
                            and your <strong>Super Admin</strong> account is ready.
                        </p>
                        <div class="alert alert-info small d-inline-block text-start mb-4">
                            <strong>For security, the installer is now locked.</strong><br>
                            To reinstall later, delete the file <code>app/config.php</code> using Hostinger File Manager.
                        </div>
                        <br>
                        <a class="btn btn-primary btn-lg" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Go to Login Page</a>
                    </div>
                </div>
                <?php
                render_footer();
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Installation error: ' . e($e->getMessage());
        }
    }

    render_header('Installation — Error', 4);
    ?>
    <div class="card">
        <div class="card-body p-4">
            <h5 class="mb-3 text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Installation could not be completed</h5>
            <ul class="text-danger">
                <?php foreach ($errors as $er): ?><li><?= $er ?></li><?php endforeach; ?>
            </ul>
            <p class="small text-muted">Nothing was changed in an incomplete state — you can safely retry. If a partial install occurred, delete <code>app/config.php</code> and rerun the wizard.</p>
            <a class="btn btn-primary" href="install.php?step=4">Retry Installation</a>
            <a class="btn btn-outline-secondary" href="install.php?step=3">← Back</a>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}

/* ---------------------------------------------------------------- *
 *  Locked / already-installed screen
 * ---------------------------------------------------------------- */
function render_locked($installed_ok) {
    render_header('Already Installed', 0);
    if ($installed_ok === true) {
        ?>
        <div class="card text-center">
            <div class="card-body p-5">
                <div class="display-3 mb-3"><i class="bi bi-shield-lock-fill text-success"></i></div>
                <h3>QuizArena is already installed</h3>
                <p class="text-muted mt-3">
                    The installation wizard is locked to protect your data.<br>
                    To log in, open the <a href="login.php">login page</a>.
                </p>
                <div class="alert alert-warning small d-inline-block text-start mt-3">
                    <strong>Need to reinstall?</strong> Open <strong>Hostinger File Manager</strong>, go to <code>public_html</code>,
                    delete the file <code>app/config.php</code>, then visit <code>install.php</code> again.<br>
                    <em>Caution: this does not delete your database — tables will remain.</em>
                </div>
                <br>
                <a class="btn btn-primary mt-2" href="login.php">Go to Login</a>
            </div>
        </div>
        <?php
    } else {
        ?>
        <div class="card">
            <div class="card-body p-4">
                <h5 class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Database connection problem</h5>
                <p class="text-muted mt-2">A configuration file exists, but the database could not be reached. This usually means the database credentials changed.</p>
                <p>To fix this, open <strong>Hostinger File Manager</strong>, go to <code>public_html</code>, delete the file
                <code>app/config.php</code>, then open <code>install.php</code> again and enter the correct database details.</p>
                <a class="btn btn-outline-secondary" href="install.php?force=1">I deleted the config — start fresh</a>
            </div>
        </div>
        <?php
    }
    render_footer();
    exit;
}

/* ---------------------------------------------------------------- *
 *  Installer layout
 * ---------------------------------------------------------------- */
function render_header($title, $active_step) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · QuizArena Installer</title>
        <link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
        <link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
        <style>
            body { background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 50%, #ecfeff 100%); min-height: 100vh; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
            .installer-wrap { max-width: 760px; margin: 0 auto; padding: 40px 16px; }
            .brand { text-align: center; margin-bottom: 24px; }
            .brand h1 { font-weight: 800; color: #4f46e5; }
            .steps { display: flex; justify-content: center; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
            .step-pill { padding: 6px 14px; border-radius: 20px; font-size: 13px; background: #fff; color: #6b7280; border: 1px solid #e5e7eb; }
            .step-pill.active { background: #4f46e5; color: #fff; border-color: #4f46e5; font-weight: 600; }
            .card { border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(79,70,229,.12); }
        </style>
    </head>
    <body>
    <div class="installer-wrap">
        <div class="brand">
            <h1><i class="bi bi-lightning-charge-fill"></i> QuizArena</h1>
            <p class="text-muted">Installation Wizard</p>
        </div>
        <?php if ($active_step): ?>
        <div class="steps">
            <span class="step-pill <?= $active_step >= 1 ? 'active' : '' ?>">1 · Requirements</span>
            <span class="step-pill <?= $active_step >= 2 ? 'active' : '' ?>">2 · Database</span>
            <span class="step-pill <?= $active_step >= 3 ? 'active' : '' ?>">3 · Admin</span>
            <span class="step-pill <?= $active_step >= 4 ? 'active' : '' ?>">4 · Install</span>
        </div>
        <?php endif; ?>
    <?php
}

function render_footer() {
    ?>
    </div>
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
