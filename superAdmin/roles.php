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


/* =========================================================================
   AUTO-CREATE role_permissions TABLE
   ========================================================================= */

if (!tableExists($conn, 'role_permissions')) {
    mysqli_query(
        $conn,
        "CREATE TABLE role_permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            role VARCHAR(50) NOT NULL,
            permission VARCHAR(100) NOT NULL,
            granted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_role_perm (role, permission),
            KEY idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}


/* =========================================================================
   MASTER DEFINITIONS
   ========================================================================= */

$roles = [
    'super_admin' => [
        'label'  => 'Super Admin',
        'desc'   => 'Full system access. Can manage users, roles, settings and data.',
        'color'  => 'gold',
        'icon'   => 'fa-crown',
        'locked' => true,
    ],
    'admin' => [
        'label'  => 'Admin',
        'desc'   => 'School administration: students, teachers, classes, subjects, timetables.',
        'color'  => 'blue',
        'icon'   => 'fa-user-tie',
        'locked' => false,
    ],
    'academic' => [
        'label'  => 'Academic Master',
        'desc'   => 'Academic oversight: results, timetables, school-wide performance.',
        'color'  => 'purple',
        'icon'   => 'fa-graduation-cap',
        'locked' => false,
    ],
    'teacher' => [
        'label'  => 'Teacher',
        'desc'   => 'Teaching staff: enter marks, view their classes and students.',
        'color'  => 'green',
        'icon'   => 'fa-chalkboard-user',
        'locked' => false,
    ],
    'parent' => [
        'label'  => 'Parent',
        'desc'   => 'View their children\'s results, attendance, and school updates.',
        'color'  => 'orange',
        'icon'   => 'fa-user-group',
        'locked' => false,
    ],
];

$permission_groups = [
    'Users & Access' => [
        'users.view'    => 'View users',
        'users.create'  => 'Create users',
        'users.edit'    => 'Edit users',
        'users.delete'  => 'Delete users',
        'roles.manage'  => 'Manage roles & permissions',
    ],
    'Academic' => [
        'students.view'    => 'View students',
        'students.manage'  => 'Manage students',
        'teachers.view'    => 'View teachers',
        'teachers.manage'  => 'Manage teachers',
        'classes.manage'   => 'Manage classes',
        'subjects.manage'  => 'Manage subjects',
        'timetable.manage' => 'Manage timetables',
    ],
    'Exams & Results' => [
        'exams.enter'    => 'Enter exam marks',
        'exams.view'     => 'View exam results',
        'exams.overview' => 'View school-wide results',
        'reports.view'   => 'View reports',
    ],
    'Communication' => [
        'news.manage'   => 'Manage news',
        'events.manage' => 'Manage events',
        'announcements' => 'Send announcements',
    ],
    'System' => [
        'settings.manage' => 'Manage system settings',
        'backup.manage'   => 'Backup & restore data',
        'audit.view'      => 'View audit logs',
    ],
];


/* =========================================================================
   SEED DEFAULT PERMISSIONS (first ever run)
   ========================================================================= */

$seed_defaults = [
    'super_admin' => 'all',
    'admin' => [
        'users.view', 'users.create', 'users.edit',
        'students.view', 'students.manage',
        'teachers.view', 'teachers.manage',
        'classes.manage', 'subjects.manage',
        'timetable.manage',
        'exams.view', 'reports.view',
        'news.manage', 'events.manage', 'announcements',
    ],
    'academic' => [
        'students.view', 'teachers.view',
        'exams.enter', 'exams.view', 'exams.overview',
        'reports.view', 'timetable.manage',
        'announcements',
    ],
    'teacher' => [
        'students.view',
        'exams.enter', 'exams.view',
    ],
    'parent' => [
        'exams.view',
    ],
];

$all_perms = [];
foreach ($permission_groups as $g => $perms) {
    foreach ($perms as $key => $label) {
        $all_perms[] = $key;
    }
}

$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM role_permissions");
$row = $r ? mysqli_fetch_assoc($r) : null;
$is_first_run = ($row === null || (int)$row['c'] === 0);

if ($is_first_run) {
    $stmt = mysqli_prepare(
        $conn,
        "INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES (?, ?, ?)"
    );

    if ($stmt) {
        foreach ($roles as $rkey => $rmeta) {
            $granted_set = ($rkey === 'super_admin')
                ? $all_perms
                : ($seed_defaults[$rkey] ?? []);

            foreach ($all_perms as $perm) {
                $granted = in_array($perm, $granted_set, true) ? 1 : 0;
                mysqli_stmt_bind_param($stmt, 'ssi', $rkey, $perm, $granted);
                mysqli_stmt_execute($stmt);
            }
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       TOGGLE PERMISSION
    ------------------------------------------------------------- */
    if ($action === 'toggle_permission') {

        $role       = trim($_POST['role']       ?? '');
        $permission = trim($_POST['permission'] ?? '');
        $granted    = (int)($_POST['granted']   ?? 0) ? 1 : 0;

        if ($role === 'super_admin') {
            json_response([
                'success' => false,
                'message' => 'Super Admin permissions cannot be changed.',
            ]);
        }

        if (!isset($roles[$role])) {
            json_response(['success' => false, 'message' => 'Invalid role.']);
        }

        $valid = in_array($permission, $all_perms, true);
        if (!$valid) {
            json_response(['success' => false, 'message' => 'Unknown permission.']);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO role_permissions (role, permission, granted)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE granted = VALUES(granted)"
        );
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'Could not prepare.']);
        }

        mysqli_stmt_bind_param($stmt, 'ssi', $role, $permission, $granted);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        json_response([
            'success' => $ok,
            'message' => $ok
                ? ($granted ? 'Permission granted.' : 'Permission revoked.')
                : 'Could not save.',
            'granted' => $granted,
        ]);
    }

    /* -------------------------------------------------------------
       RESET ROLE TO DEFAULTS
    ------------------------------------------------------------- */
    if ($action === 'reset_role') {

        $role = trim($_POST['role'] ?? '');

        if (!isset($roles[$role]) || $role === 'super_admin') {
            json_response(['success' => false, 'message' => 'Cannot reset this role.']);
        }

        $defaults = $seed_defaults[$role] ?? [];

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare($conn, "DELETE FROM role_permissions WHERE role = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $role);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO role_permissions (role, permission, granted) VALUES (?, ?, ?)"
            );
            if (!$ins) throw new Exception('Prepare failed.');

            foreach ($all_perms as $perm) {
                $granted = in_array($perm, $defaults, true) ? 1 : 0;
                mysqli_stmt_bind_param($ins, 'ssi', $role, $perm, $granted);
                mysqli_stmt_execute($ins);
            }
            mysqli_stmt_close($ins);

            mysqli_commit($conn);
            json_response([
                'success' => true,
                'message' => 'Role reset to defaults.',
            ]);

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            json_response(['success' => false, 'message' => $ex->getMessage()]);
        }
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   LOAD CURRENT PERMISSIONS
   ========================================================================= */

$current_permissions = [];

$res = mysqli_query($conn, "SELECT role, permission, granted FROM role_permissions");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $current_permissions[$row['role']][$row['permission']] = (int)$row['granted'];
    }
}

/* Super admin always has everything */
foreach ($all_perms as $perm) {
    $current_permissions['super_admin'][$perm] = 1;
}


/* =========================================================================
   USER COUNTS PER ROLE
   ========================================================================= */

$user_counts = [];
$res = mysqli_query(
    $conn,
    "SELECT role, COUNT(*) AS c, SUM(status = 'active') AS active_c
     FROM users
     GROUP BY role"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $user_counts[$row['role']] = [
            'total'  => (int)$row['c'],
            'active' => (int)$row['active_c'],
        ];
    }
}


/* =========================================================================
   SELECTED ROLE
   ========================================================================= */

$sel_role = trim($_GET['role'] ?? '');
if (!isset($roles[$sel_role])) {
    $sel_role = 'admin';
}

$r_meta    = $roles[$sel_role];
$is_locked = !empty($r_meta['locked']);
$uc        = $user_counts[$sel_role] ?? ['total' => 0, 'active' => 0];

$granted_count = 0;
$total_count   = 0;
foreach ($permission_groups as $g => $perms) {
    foreach ($perms as $key => $label) {
        $total_count++;
        if (!empty($current_permissions[$sel_role][$key])) $granted_count++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Roles &amp; Permissions | Super Admin</title>

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

        /* LAYOUT */
        .roles-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* ROLE LIST */
        .role-list {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            position: sticky;
            top: calc(var(--topbar-h) + 20px);
        }

        .role-list-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
        }

        .role-list-header h2 {
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .role-list-header h2 i { color: var(--gold); }

        .role-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid #f0f1f3;
            text-decoration: none;
            color: inherit;
            transition: .15s ease;
            cursor: pointer;
        }

        .role-item:last-child { border-bottom: none; }

        .role-item:hover { background: #fbfbf8; }

        .role-item.active {
            background: #fbf8ee;
            border-left: 3px solid var(--gold);
            padding-left: 15px;
        }

        .role-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .role-icon.gold   { background: var(--gold-light); color: var(--navy); }
        .role-icon.blue   { background: var(--blue-bg);    color: var(--blue); }
        .role-icon.purple { background: var(--purple-bg);  color: var(--purple); }
        .role-icon.green  { background: var(--green-bg);   color: var(--green); }
        .role-icon.orange { background: var(--orange-bg);  color: var(--orange); }

        .role-item-body { flex: 1; min-width: 0; }

        .role-item-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 750;
            margin-bottom: 2px;
        }

        .role-item-users {
            color: var(--muted);
            font-size: 10.5px;
        }

        .role-item-lock {
            color: var(--muted);
            font-size: 11px;
        }

        /* PERMISSION PANEL */
        .perm-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .perm-header {
            padding: 18px 22px;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .perm-header-info { min-width: 0; }

        .perm-header-title {
            font-size: 16px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .perm-header-title i { color: var(--gold-light); }

        .perm-header-sub {
            font-size: 11.5px;
            opacity: .8;
            margin-top: 4px;
            color: #cfd4dc;
        }

        .perm-header-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .perm-header-badge {
            background: rgba(255,255,255,.12);
            color: var(--gold-light);
            font-size: 10.5px;
            font-weight: 800;
            padding: 5px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .5px;
            white-space: nowrap;
        }

        .perm-header-badge.locked {
            background: var(--gold);
            color: var(--navy);
        }

        /* LOCKED NOTICE */
        .locked-notice {
            padding: 12px 22px;
            background: #fdf5dd;
            border-bottom: 1px solid #ecd9a8;
            font-size: 12px;
            color: var(--orange);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            line-height: 1.55;
        }

        .locked-notice i { font-size: 14px; flex-shrink: 0; }

        /* PERMISSION GROUPS */
        .perm-group {
            border-bottom: 1px solid #f0f1f3;
        }

        .perm-group:last-child { border-bottom: none; }

        .perm-group-header {
            padding: 14px 22px;
            background: #fafaf8;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .perm-group-header h3 {
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .6px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .perm-group-header h3::before {
            content: "";
            width: 3px;
            height: 14px;
            background: var(--gold);
            border-radius: 2px;
            flex-shrink: 0;
        }

        .perm-group-count {
            font-size: 11px;
            color: var(--muted);
            font-weight: 700;
        }

        .perm-list { padding: 6px 0; }

        .perm-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 22px;
            transition: .15s ease;
        }

        .perm-item:hover { background: #fbfbf8; }

        /* CUSTOM SWITCH */
        .perm-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .perm-switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .perm-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #d5d9e0;
            border-radius: 24px;
            transition: .2s ease;
        }

        .perm-slider::before {
            content: "";
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: .2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,.15);
        }

        .perm-switch input:checked + .perm-slider {
            background: var(--green);
        }

        .perm-switch input:checked + .perm-slider::before {
            transform: translateX(20px);
        }

        .perm-switch input:disabled + .perm-slider {
            cursor: not-allowed;
            opacity: .55;
        }

        .perm-switch.saving .perm-slider {
            opacity: .6;
        }

        .perm-item-body { flex: 1; min-width: 0; }

        .perm-item-label {
            color: var(--navy);
            font-size: 13px;
            font-weight: 650;
            margin-bottom: 2px;
        }

        .perm-item-key {
            color: var(--muted);
            font-size: 10.5px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        /* FOOTER */
        .perm-footer {
            padding: 16px 22px;
            background: #fafaf8;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .perm-footer-left {
            font-size: 12px;
            color: var(--muted);
        }

        .perm-footer-left strong { color: var(--navy); }

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
            -webkit-tap-highlight-color: transparent;
            white-space: nowrap;
        }

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover:not(:disabled) { background: var(--navy-dark); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { border-color: var(--gold); }

        .btn-danger { background: var(--red); color: var(--white); }
        .btn-danger:hover:not(:disabled) { background: #863a3a; }

        .btn .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: var(--white);
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }

        .btn.loading .spinner { display: inline-block; }

        @keyframes spin { to { transform: rotate(360deg); } }

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

        /* =========================================================
           RESPONSIVE — TABLET (≤1000px)
        ========================================================= */
        @media (max-width: 1000px) {
            .roles-layout { grid-template-columns: 1fr; }

            /* Role list becomes a horizontal scroller */
            .role-list {
                position: static;
                display: flex;
                overflow-x: auto;
                padding: 8px;
                gap: 8px;
                scrollbar-width: none;
                border-radius: 12px;
                -webkit-overflow-scrolling: touch;
            }
            .role-list::-webkit-scrollbar { display: none; }
            .role-list-header { display: none; }

            .role-item {
                flex: 0 0 auto;
                border: 1px solid var(--border);
                border-radius: 10px;
                padding: 10px 14px;
                gap: 10px;
                min-width: 170px;
                background: var(--white);
            }
            .role-item.active {
                border-color: var(--gold);
                border-left-width: 1px;
                background: #fbf8ee;
                padding-left: 14px;
            }
        }

        /* =========================================================
           RESPONSIVE — MOBILE (≤800px)
        ========================================================= */
        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                margin-bottom: 18px;
            }

            .page-title h1 { font-size: 21px; }
            .page-title h1 i { font-size: 18px; }
            .page-title p  { font-size: 12px; }

            .role-icon { width: 34px; height: 34px; font-size: 13px; }
            .role-item-name { font-size: 12.5px; }

            .perm-header { padding: 14px 18px; }
            .perm-header-title { font-size: 14px; }
            .perm-header-sub { font-size: 11px; }

            .perm-group-header { padding: 12px 18px; }
            .perm-item { padding: 12px 18px; gap: 12px; }

            .perm-item-label { font-size: 12.5px; }

            .perm-footer {
                padding: 14px 18px;
                flex-direction: column;
                align-items: stretch;
            }

            .perm-footer .btn { width: 100%; }
        }

        /* =========================================================
           RESPONSIVE — SMALL MOBILE (≤550px)
        ========================================================= */
        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .role-item { min-width: 140px; padding: 9px 12px; }
            .role-icon { width: 30px; height: 30px; font-size: 12px; }
            .role-item-name { font-size: 12px; }
            .role-item-users { font-size: 10px; }

            .perm-item { padding: 11px 14px; gap: 10px; }
            .perm-item-label { font-size: 12px; }
            .perm-item-key { font-size: 10px; }

            .perm-switch { width: 40px; height: 22px; }
            .perm-slider::before {
                height: 16px; width: 16px;
                left: 3px; bottom: 3px;
            }
            .perm-switch input:checked + .perm-slider::before {
                transform: translateX(18px);
            }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast { min-width: auto; max-width: 100%; }
        }

        /* SAFE AREA */
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

        /* REDUCED MOTION */
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
$topbar_title    = 'Roles & Permissions';
$topbar_subtitle = 'Super Admin';
include '../includes/topbar.php';
?>

<?php include 'superadmin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-user-shield"></i> Roles &amp; Permissions</h1>
            <p>Control what each role can see and do in the system.</p>
        </div>
    </div>

    <div class="roles-layout">

        <!-- LEFT: ROLE LIST -->
        <aside class="role-list">
            <div class="role-list-header">
                <h2><i class="fa-solid fa-users"></i> Roles</h2>
            </div>

            <?php foreach ($roles as $rkey => $rmeta):
                $active = $sel_role === $rkey ? ' active' : '';
                $uc_row = $user_counts[$rkey] ?? ['total' => 0, 'active' => 0];
            ?>
                <a href="roles.php?role=<?php echo urlencode($rkey); ?>"
                   class="role-item<?php echo $active; ?>">
                    <div class="role-icon <?php echo e($rmeta['color']); ?>">
                        <i class="fa-solid <?php echo e($rmeta['icon']); ?>"></i>
                    </div>
                    <div class="role-item-body">
                        <div class="role-item-name"><?php echo e($rmeta['label']); ?></div>
                        <div class="role-item-users">
                            <?php echo (int)$uc_row['total']; ?>
                            user<?php echo (int)$uc_row['total'] === 1 ? '' : 's'; ?>
                        </div>
                    </div>
                    <?php if (!empty($rmeta['locked'])): ?>
                        <i class="fa-solid fa-lock role-item-lock"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </aside>


        <!-- RIGHT: PERMISSION PANEL -->
        <section class="perm-panel">

            <div class="perm-header">
                <div class="perm-header-info">
                    <div class="perm-header-title">
                        <i class="fa-solid <?php echo e($r_meta['icon']); ?>"></i>
                        <?php echo e($r_meta['label']); ?>
                    </div>
                    <div class="perm-header-sub">
                        <?php echo e($r_meta['desc']); ?>
                    </div>
                </div>

                <div class="perm-header-meta">
                    <span class="perm-header-badge">
                        <?php echo $granted_count; ?> / <?php echo $total_count; ?> granted
                    </span>
                    <span class="perm-header-badge">
                        <?php echo (int)$uc['total']; ?> user<?php echo (int)$uc['total'] === 1 ? '' : 's'; ?>
                    </span>
                    <?php if ($is_locked): ?>
                        <span class="perm-header-badge locked">
                            <i class="fa-solid fa-lock"></i> Locked
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_locked): ?>
                <div class="locked-notice">
                    <i class="fa-solid fa-lock"></i>
                    <div>
                        Super Admin always has full access to every feature in the system.
                        These permissions cannot be changed.
                    </div>
                </div>
            <?php endif; ?>


            <?php foreach ($permission_groups as $group_name => $perms):
                $g_granted = 0;
                foreach ($perms as $key => $label) {
                    if (!empty($current_permissions[$sel_role][$key])) $g_granted++;
                }
            ?>
                <div class="perm-group">

                    <div class="perm-group-header">
                        <h3><?php echo e($group_name); ?></h3>
                        <span class="perm-group-count">
                            <?php echo $g_granted; ?> / <?php echo count($perms); ?>
                        </span>
                    </div>

                    <div class="perm-list">
                        <?php foreach ($perms as $perm_key => $perm_label):
                            $is_granted = !empty($current_permissions[$sel_role][$perm_key]);
                            $disabled   = $is_locked ? 'disabled' : '';
                        ?>
                            <div class="perm-item">
                                <label class="perm-switch">
                                    <input type="checkbox"
                                           class="perm-toggle"
                                           data-role="<?php echo e($sel_role); ?>"
                                           data-permission="<?php echo e($perm_key); ?>"
                                           <?php echo $is_granted ? 'checked' : ''; ?>
                                           <?php echo $disabled; ?>>
                                    <span class="perm-slider"></span>
                                </label>

                                <div class="perm-item-body">
                                    <div class="perm-item-label">
                                        <?php echo e($perm_label); ?>
                                    </div>
                                    <div class="perm-item-key">
                                        <?php echo e($perm_key); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            <?php endforeach; ?>


            <div class="perm-footer">
                <div class="perm-footer-left">
                    <strong><?php echo $granted_count; ?></strong>
                    of <strong><?php echo $total_count; ?></strong> permissions granted
                </div>

                <?php if (!$is_locked): ?>
                    <button type="button"
                            class="btn btn-ghost"
                            id="resetBtn"
                            data-role="<?php echo e($sel_role); ?>"
                            data-role-label="<?php echo e($r_meta['label']); ?>">
                        <i class="fa-solid fa-rotate-left"></i> Reset to Defaults
                    </button>
                <?php endif; ?>
            </div>

        </section>

    </div>

</main>

<div class="toast-wrap" id="toastWrap"></div>


<script>
/* =========================================================================
   TOASTS
   ========================================================================= */
function showToast(message, type = 'success', timeout = 3000) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : '');
    el.innerHTML = '<i class="fa-solid ' +
        (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') +
        '"></i><span>' + message + '</span>';
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s, transform .25s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}


/* =========================================================================
   TOGGLE PERMISSION (AJAX)
   ========================================================================= */
document.querySelectorAll('.perm-toggle').forEach(input => {
    input.addEventListener('change', async function () {
        const role       = this.dataset.role;
        const permission = this.dataset.permission;
        const granted    = this.checked ? 1 : 0;
        const wasChecked = !this.checked; // original state before toggle

        const switchWrap = this.closest('.perm-switch');
        if (switchWrap) switchWrap.classList.add('saving');
        this.disabled = true;

        const fd = new FormData();
        fd.set('ajax_action', 'toggle_permission');
        fd.set('role', role);
        fd.set('permission', permission);
        fd.set('granted', granted);

        try {
            const res = await fetch('roles.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const json = await res.json();

            if (!json.success) {
                // Revert
                this.checked = wasChecked;
                showToast(json.message || 'Could not save.', 'error');
                return;
            }

            showToast(json.message, 'success');

            /* Update the header counter */
            updateGrantedCount();

        } catch (err) {
            console.error(err);
            this.checked = wasChecked;
            showToast('Network error.', 'error');
        } finally {
            if (switchWrap) switchWrap.classList.remove('saving');
            this.disabled = false;
        }
    });
});


/* =========================================================================
   UPDATE GRANTED COUNTER
   ========================================================================= */
function updateGrantedCount() {
    const all = document.querySelectorAll('.perm-toggle');
    const granted = document.querySelectorAll('.perm-toggle:checked').length;

    /* Top header badge */
    const badges = document.querySelectorAll('.perm-header-badge');
    if (badges.length > 0) {
        badges[0].textContent = granted + ' / ' + all.length + ' granted';
    }

    /* Footer */
    const footerStrong = document.querySelectorAll('.perm-footer-left strong');
    if (footerStrong.length >= 2) {
        footerStrong[0].textContent = granted;
        footerStrong[1].textContent = all.length;
    }

    /* Per-group counters */
    document.querySelectorAll('.perm-group').forEach(group => {
        const total   = group.querySelectorAll('.perm-toggle').length;
        const checked = group.querySelectorAll('.perm-toggle:checked').length;
        const countEl = group.querySelector('.perm-group-count');
        if (countEl) countEl.textContent = checked + ' / ' + total;
    });
}


/* =========================================================================
   RESET ROLE TO DEFAULTS
   ========================================================================= */
const resetBtn = document.getElementById('resetBtn');

if (resetBtn) {
    resetBtn.addEventListener('click', async function () {
        const role = this.dataset.role;
        const label = this.dataset.roleLabel || role;

        if (!confirm('Reset all permissions for "' + label + '" to their defaults?')) {
            return;
        }

        const original = this.innerHTML;
        this.disabled = true;
        this.classList.add('loading');
        this.innerHTML = '<span class="spinner"></span> Resetting...';

        const fd = new FormData();
        fd.set('ajax_action', 'reset_role');
        fd.set('role', role);

        try {
            const res = await fetch('roles.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const json = await res.json();

            if (!json.success) {
                showToast(json.message || 'Could not reset.', 'error');
                return;
            }

            showToast(json.message, 'success');
            setTimeout(() => window.location.reload(), 600);

        } catch (err) {
            console.error(err);
            showToast('Network error.', 'error');
        } finally {
            this.disabled = false;
            this.classList.remove('loading');
            this.innerHTML = original;
        }
    });
}


/* =========================================================================
   MOBILE SIDEBAR — handled by includes/topbar.php
   ========================================================================= */
</script>

</body>
</html>