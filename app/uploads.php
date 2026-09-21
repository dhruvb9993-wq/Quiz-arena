<?php
/**
 * QuizArena — Secure file upload handling.
 * Validates extension, MIME (finfo), size; renames to random; blocks script files.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

const UPLOAD_BASE = __DIR__ . '/../assets/uploads/';

/**
 * Handle a single upload field.
 *
 * @param array  $file        $_FILES entry
 * @param string $subdir      subdirectory under assets/uploads (e.g. 'logos')
 * @param array  $allowed     allowed extensions (lowercase, no dot)
 * @param int    $max_kb      max size in KB
 * @return array{ok:bool, path:?string, error:?string} path is relative to assets/ e.g. "uploads/logos/abc.png"
 */
function handle_upload($file, $subdir, $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'], $max_kb = 2048) {
    if (empty($file) || !isset($file['error'])) return ['ok' => false, 'path' => null, 'error' => 'No file was uploaded.'];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'path' => null, 'error' => 'No file was uploaded.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'path' => null, 'error' => 'Upload failed with error code ' . $file['error']];

    if (!is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'path' => null, 'error' => 'Invalid upload source.'];

    $size = (int) $file['size'];
    if ($size <= 0 || $size > $max_kb * 1024) return ['ok' => false, 'path' => null, 'error' => 'File is too large (max ' . $max_kb . ' KB).'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'path' => null, 'error' => 'File type .' . $ext . ' is not allowed. Allowed: ' . implode(', ', $allowed)];

    // Double-check real MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $mime_map = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    ];
    $allowed_mimes = $mime_map[$ext] ?? [];
    if ($allowed_mimes && !in_array($mime, $allowed_mimes, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'File content does not match its extension (detected ' . $mime . ').'];
    }

    $dir = UPLOAD_BASE . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Upload directory is not writable. Please check folder permissions.'];
    }

    $name = date('Ymd') . '_' . random_hex(12) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'path' => null, 'error' => 'Could not save the uploaded file. Check folder permissions.'];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $name, 'error' => null];
}

/** Validate + read a CSV upload, returning array of rows */
function read_csv_upload($file) {
    $res = handle_upload($file, 'temp', ['csv'], 4096);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'rows' => []];
    $path = __DIR__ . '/../assets/' . $res['path'];
    $rows = [];
    if (($h = fopen($path, 'r')) !== false) {
        while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($row)) === 0) continue;
            $rows[] = array_map('trim', $row);
        }
        fclose($h);
    }
    @unlink($path);
    return ['ok' => true, 'error' => null, 'rows' => $rows];
}

/** Remove an uploaded file by its stored relative path (uploads/...) */
function delete_upload($rel_path) {
    if (!$rel_path) return;
    $full = __DIR__ . '/../assets/' . ltrim($rel_path, '/');
    if (is_file($full)) @unlink($full);
}
