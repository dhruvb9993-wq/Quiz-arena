<?php
/**
 * QuizArena — Core helper functions
 * All global helpers used across the application.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

/* ------------------------------------------------------------------ *
 *  Output helpers
 * ------------------------------------------------------------------ */

/** Escape output safely */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Absolute app URL from BASE_URL (or relative if not defined) */
function url($path = '') {
    $b = defined('BASE_URL') ? BASE_URL : '';
    return $b . '/' . ltrim($path, '/');
}

/** Redirect and stop */
function redirect($path) {
    header('Location: ' . url($path));
    exit;
}

/** HTTP 403 page */
function http403($msg = 'You are not authorised to access this page.') {
    http_response_code(403);
    $title = '403 — Access Denied';
    require __DIR__ . '/layouts/error_page.php';
    exit;
}

/** HTTP 404 page */
function http404($msg = 'The page you are looking for could not be found.') {
    http_response_code(404);
    $title = '404 — Page Not Found';
    require __DIR__ . '/layouts/error_page.php';
    exit;
}

/* ------------------------------------------------------------------ *
 *  Request helpers
 * ------------------------------------------------------------------ */

function is_post() { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function is_ajax()  { return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (($_SERVER['HTTP_ACCEPT'] ?? '') && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false); }

function post($k, $d = null) { return $_POST[$k] ?? $d; }
function get($k, $d = null)  { return $_GET[$k] ?? $d; }
function req_ip()            { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/* ------------------------------------------------------------------ *
 *  Database helpers (PDO wrapper)
 * ------------------------------------------------------------------ */

function pdo() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = $GLOBALS['QA_CONFIG'];
    $port = isset($c['db_port']) && $c['db_port'] ? $c['db_port'] : '3306';
    $dsn = "mysql:host={$c['db_host']};port={$port};dbname={$c['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Keep MySQL timestamps in sync with PHP's timezone so NOW()/started_at/submitted_at agree.
    try {
        $tz = new DateTimeZone(date_default_timezone_get());
        $off = $tz->getOffset(new DateTime('now', $tz));
        $sign = $off >= 0 ? '+' : '-';
        $abs = abs($off);
        $pdo->exec("SET time_zone = '" . $sign
            . str_pad((string) intdiv($abs, 3600), 2, '0', STR_PAD_LEFT) . ':'
            . str_pad((string) intdiv($abs % 3600, 60), 2, '0', STR_PAD_LEFT) . "'");
    } catch (Throwable $e) {
        // non-fatal; fall back to the server default timezone
    }
    return $pdo;
}

/** Prepare + execute a query (always prepared statements) */
function dbq($sql, $params = []) {
    $st = pdo()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Fetch single row */
function dbrow($sql, $params = []) { return dbq($sql, $params)->fetch(); }

/** Fetch all rows */
function dball($sql, $params = []) { return dbq($sql, $params)->fetchAll(); }

/** Fetch single scalar value */
function dbval($sql, $params = []) { $v = dbq($sql, $params)->fetchColumn(); return $v === false ? null : $v; }

/** Count rows with optional WHERE */
function dbcount($table, $where = '', $params = []) {
    return (int) dbval("SELECT COUNT(*) FROM `" . $table . "`" . ($where ? " WHERE $where" : ''), $params);
}

/** Last insert id */
function db_id() { return (int) pdo()->lastInsertId(); }

/** Begin / commit / rollback */
function db_begin()  { pdo()->beginTransaction(); }
function db_commit() { pdo()->commit(); }
function db_rollback(){ if (pdo()->inTransaction()) pdo()->rollBack(); }

/* ------------------------------------------------------------------ *
 *  Settings (cached)
 * ------------------------------------------------------------------ */

function settings() {
    static $s = null;
    if ($s === null) {
        $s = [];
        try {
            foreach (dbq("SELECT setting_key, setting_value FROM qa_settings")->fetchAll() as $r) {
                $s[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { $s = []; }
    }
    return $s;
}

function setting($k, $d = null) {
    $s = settings();
    return array_key_exists($k, $s) ? $s[$k] : $d;
}

function save_setting($k, $v) {
    dbq("INSERT INTO qa_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()", [$k, $v]);
}

/* ------------------------------------------------------------------ *
 *  Session / auth
 * ------------------------------------------------------------------ */

function current_user() {
    if (empty($_SESSION['user_id'])) return null;
    static $u = null;
    if ($u === null) {
        $u = dbrow(
            "SELECT u.*, w.balance AS wallet_balance
               FROM qa_users u
               LEFT JOIN qa_wallets w ON w.user_id = u.id
              WHERE u.id = ? AND u.status = 'active'", [$_SESSION['user_id']]
        );
        if (!$u) {
            unset($_SESSION['user_id']);
            return null;
        }
    }
    return $u;
}

function me() { return current_user(); }

function role_label($role) {
    return [
        'superadmin'   => 'Super Admin',
        'school_admin' => 'School Admin',
        'teacher'      => 'Teacher',
        'student'      => 'Student',
    ][$role] ?? ucfirst($role);
}

function dash_path($role) {
    return ['superadmin' => 'admin/dashboard.php', 'school_admin' => 'school/dashboard.php', 'teacher' => 'teacher/dashboard.php', 'student' => 'student/dashboard.php'][$role] ?? 'login.php';
}

/* ------------------------------------------------------------------ *
 *  CSRF protection
 * ------------------------------------------------------------------ */

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check($die = true) {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $ok = is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
    if (!$ok && $die) {
        http_response_code(403);
        die('Invalid security token. Please go back, refresh the page and try again.');
    }
    return $ok;
}

/* ------------------------------------------------------------------ *
 *  Flash messages
 * ------------------------------------------------------------------ */

function flash($type, $msg) {
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function get_flash() {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ *
 *  Notifications & audit log
 * ------------------------------------------------------------------ */

function notify($uid, $title, $msg, $link = null, $school_id = null) {
    if (!$uid) return;
    dbq("INSERT INTO qa_notifications (user_id, school_id, title, message, link, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, 0, NOW())", [$uid, $school_id, $title, $msg, $link]);
}

function unread_count($uid = null) {
    $uid = $uid ?: ($_SESSION['user_id'] ?? 0);
    return (int) dbval("SELECT COUNT(*) FROM qa_notifications WHERE user_id = ? AND is_read = 0", [$uid]);
}

function audit($action, $details = '') {
    $uid = $_SESSION['user_id'] ?? null;
    dbq("INSERT INTO qa_admin_logs (user_id, action, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, NOW())", [$uid, $action, $details, req_ip()]);
}

/* ------------------------------------------------------------------ *
 *  Formatting helpers
 * ------------------------------------------------------------------ */

function coin_name() { return setting('coin_name', 'Quiz Coins'); }
function currency_symbol() { return setting('currency_symbol', '₹'); }

function fmt_coin($n) { return number_format((int)$n); }
function fmt_currency($n) { return currency_symbol() . number_format((float)$n, 2); }

function time_ago($dt) {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('d M Y', $ts);
}

function nice_date($dt, $with_time = false) {
    if (!$dt) return '—';
    return date($with_time ? 'd M Y, h:i A' : 'd M Y', strtotime($dt));
}

function rank_badge($rank) {
    if ($rank === null) return '—';
    if ($rank == 1) return '🥇 <b>1st</b>';
    if ($rank == 2) return '🥈 <b>2nd</b>';
    if ($rank == 3) return '🥉 <b>3rd</b>';
    return '#' . (int)$rank;
}

function pct_badge($pct) {
    $pct = (float)$pct;
    $cls = $pct >= 75 ? 'success' : ($pct >= 40 ? 'primary' : 'danger');
    return '<span class="badge bg-' . $cls . '-subtle text-' . $cls . '">' . e(round($pct, 1)) . '%</span>';
}

function avatar_url($user) {
    if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../assets/' . ltrim($user['profile_photo'], '/'))) {
        return url('assets/' . ltrim($user['profile_photo'], '/'));
    }
    return url('assets/img/avatar-default.png');
}

function school_logo_url($logo) {
    if (!empty($logo) && file_exists(__DIR__ . '/../assets/' . ltrim($logo, '/'))) {
        return url('assets/' . ltrim($logo, '/'));
    }
    return null;
}

function site_logo_url() {
    $logo = setting('site_logo');
    if (!empty($logo) && file_exists(__DIR__ . '/../assets/' . ltrim($logo, '/'))) {
        return url('assets/' . ltrim($logo, '/'));
    }
    return url('assets/img/logo.png');
}

/* ------------------------------------------------------------------ *
 *  Pagination
 * ------------------------------------------------------------------ */

/**
 * Returns [offset, per_page, page, pages, html]
 */
function paginate($total, $per = 20, $page = 1, $base = '', $params = []) {
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($pages, (int) $page));
    $off = ($page - 1) * $per;

    if ($pages <= 1) return [$off, $per, $page, $pages, ''];

    $qs = http_build_query(array_merge($params, ['p' => '__P__']));
    $mk = function ($p) use ($base, $qs) {
        return url($base . (strpos($base, '?') !== false ? '&' : '?') . str_replace('__P__', $p, $qs));
    };

    $html = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">';
    if ($page > 1) $html .= '<li class="page-item"><a class="page-link" href="' . e($mk($page - 1)) . '">Prev</a></li>';
    $start = max(1, $page - 2); $end = min($pages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . e($mk($i)) . '">' . $i . '</a></li>';
    }
    if ($page < $pages) $html .= '<li class="page-item"><a class="page-link" href="' . e($mk($page + 1)) . '">Next</a></li>';
    $html .= '</ul></nav>';
    return [$off, $per, $page, $pages, $html];
}

/* ------------------------------------------------------------------ *
 *  CSV / Excel export
 * ------------------------------------------------------------------ */

function export_csv($filename, $headers, $rows) {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '\\');
    fclose($out);
    exit;
}

/** Minimal pure-PHP .xlsx writer (falls back to CSV when ZipArchive is unavailable) */
function export_xlsx($filename, $headers, $rows) {
    if (!class_exists('ZipArchive')) {
        export_csv(str_replace('.xlsx', '.csv', $filename), $headers, $rows);
    }
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $esc = function ($v) { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string)$v); };

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';
    $sheet .= '<row r="1">';
    foreach ($headers as $i => $h) {
        $ref = 'A' . ($i + 1);
        $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($h) . '</t></is></c>';
    }
    $sheet .= '</row>';
    $r = 2;
    foreach ($rows as $row) {
        $sheet .= '<row r="' . $r . '">';
        $c = 1;
        foreach ($row as $cell) {
            $ref = chr(64 + $c) . $r;
            if (is_numeric($cell) && $cell === (string)(float)$cell) {
                $sheet .= '<c r="' . $ref . '"><v>' . (float)$cell . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($cell) . '</t></is></c>';
            }
            $c++;
        }
        $sheet .= '</row>';
        $r++;
    }
    $sheet .= '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'qa');
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** Shared report export endpoint handler (reads ?export= format) */
function handle_export($filename_base, $headers, $rows) {
    $fmt = get('export', '');
    if ($fmt === 'csv') export_csv($filename_base . '.csv', $headers, $rows);
    if ($fmt === 'xlsx') export_xlsx($filename_base . '.xlsx', $headers, $rows);
}

/* ------------------------------------------------------------------ *
 *  Misc
 * ------------------------------------------------------------------ */

function random_hex($len = 8) { return bin2hex(random_bytes($len)); }

function txn_id() { return 'WC' . date('ymdHis') . strtoupper(random_hex(4)); }

function cert_id() { return 'QA' . date('Y') . '-' . strtoupper(random_hex(4)) . '-' . strtoupper(random_hex(4)); }

function username_valid($u) {
    return (bool) preg_match('/^[A-Za-z0-9_.]{3,30}$/', $u);
}

/** Normalise a username entered with or without the leading @ */
function clean_username($u) {
    $u = trim($u);
    if ($u !== '' && $u[0] === '@') $u = substr($u, 1);
    return $u;
}

function is_valid_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dd($v) { echo '<pre>'; var_dump($v); echo '</pre>'; exit; }
