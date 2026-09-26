<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('super_admin');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function tableExists(mysqli $conn, string $t): bool {
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safe_t = mysqli_real_escape_string($conn, $table);
    $safe_c = mysqli_real_escape_string($conn, $column);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_t` LIKE '$safe_c'");
    return $r && mysqli_num_rows($r) > 0;
}

function logAuditLocal(
    mysqli $conn,
    int $user_id,
    string $role,
    string $action,
    ?string $description = null
): void {
    if (!tableExists($conn, 'audit_log')) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO audit_log
            (user_id, role, action, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'isssss',
        $user_id, $role, $action, $description, $ip, $ua);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   ENSURE system_settings TABLE
   ========================================================================= */

if (!tableExists($conn, 'system_settings')) {
    mysqli_query(
        $conn,
        "CREATE TABLE system_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Seed defaults */
    $defaults = [
        'school_name'         => 'Primary School',
        'school_motto'        => 'Knowledge · Integrity · Service',
        'school_email'        => '',
        'school_phone'        => '',
        'school_address'      => '',
        'school_logo'         => '',
        'active_year'         => date('Y'),
        'active_term'         => 'Term 1',
        'term_start'          => '',
        'term_end'            => '',
        'system_status'       => 'open',     // open|closed
        'closure_message'     => 'The system is currently closed for maintenance. Please check back soon.',
        'closure_allow_login' => '1',        // 1 = admins can still log in
        'maintenance_note'    => '',
    ];

    $stmt = mysqli_prepare(
        $conn,
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)"
    );
    if ($stmt) {
        foreach ($defaults as $k => $v) {
            mysqli_stmt_bind_param($stmt, 'ss', $k, $v);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   LOAD SETTINGS
   ========================================================================= */

$settings = [];
$r = mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

/* Ensure all keys exist even if table was just created */
$defaults_all = [
    'school_name', 'school_motto', 'school_email', 'school_phone', 'school_address',
    'school_logo', 'active_year', 'active_term', 'term_start', 'term_end',
    'system_status', 'closure_message', 'closure_allow_login', 'maintenance_note',
];
foreach ($defaults_all as $k) {
    if (!isset($settings[$k])) $settings[$k] = '';
}


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       SAVE GENERAL SETTINGS
    ------------------------------------------------------------- */
    if ($action === 'save_general') {

        $fields = [
            'school_name'    => trim($_POST['school_name']    ?? ''),
            'school_motto'   => trim($_POST['school_motto']   ?? ''),
            'school_email'   => trim($_POST['school_email']   ?? ''),
            'school_phone'   => trim($_POST['school_phone']   ?? ''),
            'school_address' => trim($_POST['school_address'] ?? ''),
            'active_year'    => trim($_POST['active_year']    ?? ''),
            'active_term'    => trim($_POST['active_term']    ?? ''),
            'term_start'     => trim($_POST['term_start']     ?? ''),
            'term_end'       => trim($_POST['term_end']       ?? ''),
        ];

        $errors = [];

        if ($fields['school_name'] === '') {
            $errors['school_name'] = 'School name is required.';
        }

        if ($fields['school_email'] !== '' && !filter_var($fields['school_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['school_email'] = 'Enter a valid email.';
        }

        if ($fields['active_term'] !== '' && !in_array($fields['active_term'], ['Term 1','Term 2','Term 3'], true)) {
            $errors['active_term'] = 'Invalid term.';
        }

        if (!empty($errors)) {
            json_response([
                'success' => false,
                'message' => 'Please fix the highlighted fields.',
                'errors'  => $errors,
            ]);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'Prepare failed.']);
        }

        foreach ($fields as $k => $v) {
            mysqli_stmt_bind_param($stmt, 'ss', $k, $v);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);

        logAuditLocal($conn, $user_id, $_SESSION['role'] ?? 'super_admin',
                      'settings.update', 'Updated general settings');

        json_response(['success' => true, 'message' => 'Settings saved.']);
    }

    /* -------------------------------------------------------------
       UPLOAD SCHOOL LOGO
    ------------------------------------------------------------- */
    if ($action === 'upload_logo') {

        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'No file uploaded.']);
        }

        $file = $_FILES['logo'];

        if ($file['size'] > 2 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'File must be under 2 MB.']);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        if (!isset($allowed[$mime])) {
            json_response(['success' => false, 'message' => 'Only JPG, PNG, WEBP, or SVG allowed.']);
        }

        $ext = $allowed[$mime];

        $upload_dir = __DIR__ . '/../uploads/school/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

        if (!is_writable($upload_dir)) {
            json_response(['success' => false, 'message' => 'Upload folder not writable.']);
        }

        $new_name = 'logo_' . time() . '.' . $ext;
        $dest     = $upload_dir . $new_name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_response(['success' => false, 'message' => 'Could not save file.']);
        }

        /* Delete old logo */
        $old = $settings['school_logo'] ?? '';
        if ($old && is_file($upload_dir . $old)) @unlink($upload_dir . $old);

        /* Save new path */
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES ('school_logo', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        mysqli_stmt_bind_param($stmt, 's', $new_name);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        logAuditLocal($conn, $user_id, $_SESSION['role'] ?? 'super_admin',
                      'settings.logo', "Uploaded school logo: $new_name");

        json_response([
            'success'   => true,
            'message'   => 'Logo updated.',
            'logo_url'  => '../uploads/school/' . $new_name,
        ]);
    }

    /* -------------------------------------------------------------
       SYSTEM STATUS (OPEN / CLOSED)
    ------------------------------------------------------------- */
    if ($action === 'save_status') {

        $status      = trim($_POST['system_status']       ?? 'open');
        $message     = trim($_POST['closure_message']     ?? '');
        $allow_login = (int)($_POST['closure_allow_login'] ?? 1) ? 1 : 0;
        $maint_note  = trim($_POST['maintenance_note']    ?? '');

        if (!in_array($status, ['open', 'closed'], true)) {
            $status = 'open';
        }

        if ($status === 'closed' && $message === '') {
            $message = 'The system is currently closed for maintenance. Please check back soon.';
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'Prepare failed.']);
        }

        $pairs = [
            'system_status'       => $status,
            'closure_message'     => $message,
            'closure_allow_login' => (string)$allow_login,
            'maintenance_note'    => $maint_note,
        ];

        foreach ($pairs as $k => $v) {
            mysqli_stmt_bind_param($stmt, 'ss', $k, $v);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);

        logAuditLocal($conn, $user_id, $_SESSION['role'] ?? 'super_admin',
                      'settings.status',
                      "System status set to {$status}" . ($allow_login ? " (admin access allowed)" : ""));

        json_response([
            'success' => true,
            'message' => $status === 'closed'
                ? 'System closed. Users will be blocked from logging in.'
                : 'System is now open.',
            'status'  => $status,
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   DETECT SCHEMA
   ========================================================================= */

$has_year_table = tableExists($conn, 'academic_years');
$has_term_table = tableExists($conn, 'terms');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>System Settings | Super Admin</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
          crossorigin="anonymous" referrerpolicy="no-referrer">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy: #17233c;
            --navy-dark: #10182b;
            --gold: #c9a227;
            --gold-light: #e2c65a;
            --cream: #f7f5ef;
            --white: #ffffff;
            --text: #263044;
            --muted: #747d8e;
            --border: #e3e6eb;
            --red: #9b4747;
            --red-bg: #fbefef;
            --green: #3e7655;
            --green-bg: #eef6f0;
            --orange: #9a7422;
            --orange-bg: #faf5e8;
            --blue: #2f5d8f;
            --blue-bg: #eaf1fa;
            --purple: #5a4a8f;
            --purple-bg: #f0eefa;
            --sidebar-w: 250px;
            --topbar-h: 64px;
        }

        html, body { overflow-x: hidden; }

        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
            -webkit-text-size-adjust: 100%;
        }

        body.no-scroll { overflow: hidden; }

        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* HEADER */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            line-height: 1.2;
        }

        .page-title h1 i { color: var(--gold); font-size: 22px; flex-shrink: 0; }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 6px;
        }

        /* STATUS PILL (header) */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            white-space: nowrap;
        }

        .status-pill.open   { background: var(--green-bg); color: var(--green); }
        .status-pill.closed { background: var(--red-bg);   color: var(--red); }

        .status-pill::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 0 3px currentColor;
            animation: pulse 1.8s infinite;
        }

        .status-pill.open::before { animation: none; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: .35; }
        }

        /* GRID 2COL */
        .grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            align-items: start;
        }

        /* PANEL */
        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .panel-header {
            padding: 16px 22px;
            background: #fcfcfa;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-header h2 {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .panel-header h2 i { color: var(--gold); font-size: 14px; }

        .panel-header .meta {
            font-size: 11.5px;
            color: var(--muted);
        }

        .panel-body { padding: 22px; }

        /* FORM */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-grid .full { grid-column: 1 / -1; }

        .form-group { min-width: 0; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-group label .req { color: var(--red); margin-left: 2px; }

        .form-control {
            width: 100%;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13.5px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            transition: .15s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 90px;
            padding: 12px;
            resize: vertical;
            line-height: 1.55;
        }

        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        input[type="file"].form-control {
            padding: 10px 12px;
            height: auto;
            line-height: 1.4;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .form-control.error {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(155,71,71,.1);
        }

        .field-error {
            display: none;
            color: var(--red);
            font-size: 11px;
            margin-top: 5px;
        }
        .field-error.show { display: block; }

        .form-help {
            color: var(--muted);
            font-size: 11px;
            margin-top: 6px;
            line-height: 1.5;
        }

        /* LOGO UPLOAD */
        .logo-row {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 18px;
            padding-bottom: 18px;
            border-bottom: 1px dashed var(--border);
        }

        .logo-preview {
            width: 86px;
            height: 86px;
            border-radius: 12px;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid var(--border);
        }

        .logo-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: var(--white);
            padding: 8px;
        }

        .logo-details { flex: 1; min-width: 0; }

        .logo-details h4 {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 750;
            margin-bottom: 4px;
        }

        .logo-details p {
            color: var(--muted);
            font-size: 11.5px;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        /* BTNS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 20px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: .15s ease;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover:not(:disabled) { background: var(--navy-dark); }

        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover:not(:disabled) { background: var(--gold-light); }

        .btn-danger { background: var(--red); color: var(--white); }
        .btn-danger:hover:not(:disabled) { background: #863a3a; }

        .btn-green { background: var(--green); color: var(--white); }
        .btn-green:hover:not(:disabled) { background: #2f5c42; }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { border-color: var(--gold); }

        .btn-sm { min-height: 36px; padding: 0 14px; font-size: 12px; }

        .btn .spinner {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }
        .btn.loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 18px;
            margin-top: 20px;
            border-top: 1px solid var(--border);
        }

        /* STATUS CONTROL */
        .status-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 18px;
        }

        .status-option {
            position: relative;
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            cursor: pointer;
            transition: .15s ease;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .status-option:hover { border-color: var(--gold); }

        .status-option input { display: none; }

        .status-option.open.active   { border-color: var(--green); background: var(--green-bg); }
        .status-option.closed.active { border-color: var(--red);   background: var(--red-bg); }

        .status-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #eef0f5;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .status-option.open.active .status-icon {
            background: var(--white); color: var(--green);
        }
        .status-option.closed.active .status-icon {
            background: var(--white); color: var(--red);
        }

        .status-body { min-width: 0; }

        .status-title {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            margin-bottom: 3px;
        }

        .status-desc {
            color: var(--muted);
            font-size: 11.5px;
            line-height: 1.5;
        }

        /* CLOSURE PANEL (visible when closed) */
        .closure-panel {
            background: var(--red-bg);
            border: 1px solid #efd2d2;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .closure-panel-title {
            color: var(--red);
            font-size: 12.5px;
            font-weight: 750;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* WARNING BANNER */
        .warning-banner {
            background: linear-gradient(135deg, var(--red-bg) 0%, #f7dcdc 100%);
            border: 1px solid #efd2d2;
            border-left: 4px solid var(--red);
            border-radius: 10px;
            padding: 16px 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 20px;
        }

        .warning-banner .ico {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--white);
            color: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .warning-banner .txt {
            flex: 1;
            min-width: 0;
        }

        .warning-banner h3 {
            color: var(--red);
            font-size: 14px;
            font-weight: 750;
            margin-bottom: 4px;
        }

        .warning-banner p {
            color: var(--text);
            font-size: 12.5px;
            line-height: 1.55;
        }

        /* CHECKBOX LINE */
        .checkbox-line {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            user-select: none;
            font-size: 12.5px;
            color: var(--navy);
            font-weight: 600;
            line-height: 1.5;
        }

        .checkbox-line:hover { border-color: var(--gold); }

        .checkbox-line input {
            width: 16px;
            height: 16px;
            accent-color: var(--gold);
            cursor: pointer;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* TOASTS */
        .toast-wrap {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-left: 3px solid var(--green);
            border-radius: 9px;
            box-shadow: 0 10px 30px rgba(16,24,43,.12);
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            min-width: 260px;
            max-width: 380px;
            pointer-events: auto;
            animation: slideIn .25s ease;
        }

        .toast i { color: var(--green); font-size: 14px; flex-shrink: 0; }
        .toast.error { border-left-color: var(--red); }
        .toast.error i { color: var(--red); }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }

        /* RESPONSIVE */
        @media (max-width: 1000px) {
            .grid-2col { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-title h1 i { font-size: 18px; }
            .page-title p  { font-size: 12px; }

            .status-pill { align-self: flex-start; }

            .panel-header { padding: 14px 16px; }
            .panel-header h2 { font-size: 13px; }

            .panel-body { padding: 18px; }

            .form-grid { grid-template-columns: 1fr; gap: 14px; }

            .form-control { height: 46px; font-size: 14px; }

            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; }

            .status-switch { grid-template-columns: 1fr; gap: 10px; }

            .status-option { padding: 14px; }

            .logo-row { flex-direction: column; align-items: flex-start; gap: 14px; }

            .warning-banner { padding: 14px 16px; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .status-pill { font-size: 11px; padding: 7px 13px; }

            .status-icon { width: 40px; height: 40px; font-size: 17px; }
            .status-title { font-size: 13px; }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast { min-width: auto; max-width: 100%; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .toast-wrap {
                    top: max(10px, env(safe-area-inset-top));
                    right: max(10px, env(safe-area-inset-right));
                }
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'System Settings';
$topbar_subtitle = 'Super Admin';
include '../includes/topbar.php';
?>

<?php include 'superadmin_sidebar.php'; ?>

<?php
$is_closed = (strtolower($settings['system_status'] ?? 'open') === 'closed');
?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-sliders"></i> System Settings</h1>
            <p>Configure school information, terms, and system availability.</p>
        </div>
        <div>
            <span class="status-pill <?php echo $is_closed ? 'closed' : 'open'; ?>" id="statusPill">
                <?php echo $is_closed ? 'System Closed' : 'System Open'; ?>
            </span>
        </div>
    </div>

    <!-- CLOSED WARNING BANNER -->
    <?php if ($is_closed): ?>
        <div class="warning-banner" id="closureBanner">
            <div class="ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="txt">
                <h3>System is currently closed</h3>
                <p>
                    Regular users cannot log in. <?php echo !empty($settings['closure_allow_login']) ? 'Only super admins can sign in.' : 'Nobody can sign in.'; ?>
                    You can reopen the system from the panel below.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- 2-COLUMN -->
    <div class="grid-2col">

        <!-- LEFT: SCHOOL INFORMATION -->
        <section class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-school"></i> School Information</h2>
                <span class="meta">General settings</span>
            </div>

            <div class="panel-body">

                <!-- LOGO -->
                <div class="logo-row">
                    <div class="logo-preview" id="logoPreview">
                        <?php if (!empty($settings['school_logo']) && is_file(__DIR__ . '/../uploads/school/' . $settings['school_logo'])): ?>
                            <img src="../uploads/school/<?php echo e($settings['school_logo']); ?>?v=<?php echo time(); ?>" alt="">
                        <?php else: ?>
                            PS
                        <?php endif; ?>
                    </div>

                    <div class="logo-details">
                        <h4>School Logo</h4>
                        <p>Square image · JPG, PNG, WEBP or SVG · Max 2 MB</p>
                        <input type="file" id="logoInput" accept="image/*" style="display:none;">
                        <label for="logoInput" class="btn btn-ghost btn-sm" style="cursor:pointer;">
                            <i class="fa-solid fa-upload"></i> Change Logo
                        </label>
                    </div>
                </div>

                <form id="generalForm" autocomplete="off">
                    <div class="form-grid">

                        <div class="form-group full">
                            <label>School Name <span class="req">*</span></label>
                            <input type="text" name="school_name" class="form-control"
                                   value="<?php echo e($settings['school_name']); ?>"
                                   maxlength="150" required>
                            <div class="field-error" data-error-for="school_name"></div>
                        </div>

                        <div class="form-group full">
                            <label>Motto / Tagline</label>
                            <input type="text" name="school_motto" class="form-control"
                                   value="<?php echo e($settings['school_motto']); ?>"
                                   maxlength="200">
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="school_email" class="form-control"
                                   value="<?php echo e($settings['school_email']); ?>"
                                   maxlength="150">
                            <div class="field-error" data-error-for="school_email"></div>
                        </div>

                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" name="school_phone" class="form-control"
                                   value="<?php echo e($settings['school_phone']); ?>"
                                   maxlength="30">
                        </div>

                        <div class="form-group full">
                            <label>Address</label>
                            <textarea name="school_address" class="form-control" rows="2"
                                      maxlength="300"><?php echo e($settings['school_address']); ?></textarea>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="saveGeneralBtn">
                            <span class="spinner"></span>
                            <i class="fa-solid fa-floppy-disk"></i> Save Information
                        </button>
                    </div>
                </form>

            </div>
        </section>

        <!-- RIGHT: TERM + STATUS -->
        <div>

            <!-- TERM SETTINGS -->
            <section class="panel" style="margin-bottom:20px;">
                <div class="panel-header">
                    <h2><i class="fa-solid fa-calendar-check"></i> Academic Term</h2>
                </div>

                <div class="panel-body">
                    <form id="termForm" autocomplete="off">
                        <div class="form-grid">

                            <div class="form-group">
                                <label>Active Year</label>
                                <input type="text" name="active_year" class="form-control"
                                       value="<?php echo e($settings['active_year']); ?>"
                                       maxlength="20" placeholder="e.g. 2025">
                            </div>

                            <div class="form-group">
                                <label>Active Term</label>
                                <select name="active_term" class="form-control">
                                    <?php foreach (['Term 1','Term 2','Term 3'] as $t): ?>
                                        <option value="<?php echo $t; ?>" <?php echo $settings['active_term'] === $t ? 'selected' : ''; ?>>
                                            <?php echo $t; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Term Start</label>
                                <input type="date" name="term_start" class="form-control"
                                       value="<?php echo e($settings['term_start']); ?>">
                            </div>

                            <div class="form-group">
                                <label>Term End</label>
                                <input type="date" name="term_end" class="form-control"
                                       value="<?php echo e($settings['term_end']); ?>">
                            </div>

                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="saveTermBtn">
                                <span class="spinner"></span>
                                <i class="fa-solid fa-floppy-disk"></i> Save Term
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- SYSTEM STATUS -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fa-solid fa-power-off"></i> System Status</h2>
                    <span class="meta">Close to block all logins</span>
                </div>

                <div class="panel-body">

                    <div class="status-switch">

                        <label class="status-option open <?php echo !$is_closed ? 'active' : ''; ?>">
                            <input type="radio" name="system_status_radio" value="open"
                                <?php echo !$is_closed ? 'checked' : ''; ?>>
                            <div class="status-icon">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <div class="status-body">
                                <div class="status-title">Open</div>
                                <div class="status-desc">System is running normally. Everyone can log in.</div>
                            </div>
                        </label>

                        <label class="status-option closed <?php echo $is_closed ? 'active' : ''; ?>">
                            <input type="radio" name="system_status_radio" value="closed"
                                <?php echo $is_closed ? 'checked' : ''; ?>>
                            <div class="status-icon">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </div>
                            <div class="status-body">
                                <div class="status-title">Closed</div>
                                <div class="status-desc">Block all regular users from logging in.</div>
                            </div>
                        </label>

                    </div>

                    <form id="statusForm" autocomplete="off">
                        <input type="hidden" name="system_status" id="statusInput"
                               value="<?php echo $is_closed ? 'closed' : 'open'; ?>">

                        <div class="closure-panel" id="closurePanel"
                             style="<?php echo $is_closed ? '' : 'display:none;'; ?>">

                            <div class="closure-panel-title">
                                <i class="fa-solid fa-lock"></i>
                                Closure settings
                            </div>

                            <div class="form-group" style="margin-bottom:14px;">
                                <label>Message shown to blocked users</label>
                                <textarea name="closure_message" class="form-control" rows="3"
                                          maxlength="500"><?php echo e($settings['closure_message']); ?></textarea>
                                <div class="form-help">
                                    Appears on the login page when the system is closed.
                                </div>
                            </div>

                            <label class="checkbox-line">
                                <input type="checkbox" name="closure_allow_login"
                                    <?php echo !empty($settings['closure_allow_login']) ? 'checked' : ''; ?>>
                                <span>Allow <strong>super admins</strong> to still log in while closed</span>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-gold" id="saveStatusBtn">
                                <span class="spinner"></span>
                                <i class="fa-solid fa-check"></i> Apply Status
                            </button>
                        </div>
                    </form>

                </div>
            </section>

        </div>

    </div>

</main>

<div class="toast-wrap" id="toastWrap"></div>

<script>
/* =========================================================================
   TOASTS
   ========================================================================= */
function showToast(msg, type = 'success', timeout = 3200) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : '');
    el.innerHTML = '<i class="fa-solid ' +
        (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') +
        '"></i><span>' + msg + '</span>';
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s, transform .25s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}

/* =========================================================================
   FORM ERROR HELPERS
   ========================================================================= */
function clearErrors(form) {
    form.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));
    form.querySelectorAll('.field-error').forEach(el => {
        el.textContent = '';
        el.classList.remove('show');
    });
}

function showErrors(form, errors) {
    Object.entries(errors || {}).forEach(([field, msg]) => {
        const inp = form.querySelector('[name="' + field + '"]');
        if (inp) inp.classList.add('error');
        const err = form.querySelector('[data-error-for="' + field + '"]');
        if (err) { err.textContent = msg; err.classList.add('show'); }
    });
}

/* =========================================================================
   GENERAL SETTINGS
   ========================================================================= */
const generalForm = document.getElementById('generalForm');
const saveGeneralBtn = document.getElementById('saveGeneralBtn');

generalForm.addEventListener('submit', async e => {
    e.preventDefault();
    clearErrors(generalForm);

    const btn = saveGeneralBtn;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    const fd = new FormData(generalForm);
    fd.set('ajax_action', 'save_general');

    try {
        const res = await fetch('settings.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            if (json.errors) showErrors(generalForm, json.errors);
            showToast(json.message || 'Could not save.', 'error');
            return;
        }
        showToast(json.message, 'success');

    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});

/* =========================================================================
   TERM SETTINGS — same form, same endpoint, reuse save_general
   ========================================================================= */
const termForm = document.getElementById('termForm');
const saveTermBtn = document.getElementById('saveTermBtn');

termForm.addEventListener('submit', async e => {
    e.preventDefault();

    /* Merge term fields with existing general fields so we don't wipe them */
    const fd = new FormData(generalForm);
    const termFd = new FormData(termForm);
    for (const [k, v] of termFd.entries()) fd.set(k, v);
    fd.set('ajax_action', 'save_general');

    const btn = saveTermBtn;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    try {
        const res = await fetch('settings.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Could not save.', 'error');
            return;
        }
        showToast('Term updated.', 'success');

    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});

/* =========================================================================
   LOGO UPLOAD
   ========================================================================= */
document.getElementById('logoInput').addEventListener('change', async function () {
    if (!this.files || !this.files[0]) return;
    const file = this.files[0];

    if (file.size > 2 * 1024 * 1024) {
        showToast('File must be under 2 MB.', 'error');
        this.value = '';
        return;
    }

    /* Preview */
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('logoPreview').innerHTML =
            '<img src="' + e.target.result + '" alt="Preview">';
    };
    reader.readAsDataURL(file);

    /* Upload */
    const fd = new FormData();
    fd.set('ajax_action', 'upload_logo');
    fd.set('logo', file);

    try {
        const res = await fetch('settings.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Upload failed.', 'error');
            return;
        }
        showToast('Logo updated.', 'success');
        if (json.logo_url) {
            const img = document.querySelector('#logoPreview img');
            if (img) img.src = json.logo_url + '?v=' + Date.now();
        }
    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        this.value = '';
    }
});

/* =========================================================================
   STATUS TOGGLE
   ========================================================================= */
document.querySelectorAll('.status-option').forEach(opt => {
    opt.addEventListener('click', () => {
        const value = opt.querySelector('input').value;

        document.querySelectorAll('.status-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');

        document.getElementById('statusInput').value = value;

        /* Show/hide closure panel */
        document.getElementById('closurePanel').style.display =
            value === 'closed' ? 'block' : 'none';
    });
});

/* =========================================================================
   SAVE STATUS
   ========================================================================= */
const statusForm = document.getElementById('statusForm');
const saveStatusBtn = document.getElementById('saveStatusBtn');

statusForm.addEventListener('submit', async e => {
    e.preventDefault();

    const status = document.getElementById('statusInput').value;

    if (status === 'closed') {
        const ok = confirm('Close the system? Regular users will not be able to log in.');
        if (!ok) return;
    }

    const btn = saveStatusBtn;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    const fd = new FormData(statusForm);
    fd.set('ajax_action', 'save_status');

    try {
        const res = await fetch('settings.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Could not save status.', 'error');
            return;
        }

        showToast(json.message, 'success');

        /* Update status pill + banner */
        const pill = document.getElementById('statusPill');
        pill.textContent = json.status === 'closed' ? 'System Closed' : 'System Open';
        pill.classList.remove('open', 'closed');
        pill.classList.add(json.status === 'closed' ? 'closed' : 'open');

        /* Reload to reflect the closure banner */
        setTimeout(() => window.location.reload(), 800);

    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});

/* =========================================================================
   MOBILE SIDEBAR — handled by includes/topbar.php
   ========================================================================= */
</script>

</body>
</html>