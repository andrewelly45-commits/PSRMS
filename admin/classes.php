<?php

session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';


/* =========================================================================
   HELPERS
   ========================================================================= */

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['classes_flash'] = ['type' => $type, 'message' => $message];
    header('Location: classes.php');
    exit;
}

/**
 * Build a teacher's full display name from users.first_name,
 * users.middle_name, users.last_name.
 */
function build_teacher_name(array $row): string
{
    $parts = [
        $row['teacher_first_name']  ?? '',
        $row['teacher_middle_name'] ?? '',
        $row['teacher_last_name']   ?? '',
    ];

    $name = trim(implode(' ', array_filter($parts, fn($p) => trim((string)$p) !== '')));
    return preg_replace('/\s+/', ' ', $name) ?? '';
}


/* =========================================================================
   HANDLE FORM SUBMISSIONS
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $class_name  = trim($_POST['class_name'] ?? '');
        $class_level = $_POST['class_level'] !== '' ? (int) $_POST['class_level'] : null;
        $stream      = trim($_POST['stream'] ?? '');
        $status      = $_POST['status'] ?? 'active';

        $errors = [];

        if ($class_name === '') {
            $errors[] = 'Class name is required.';
        } elseif (strlen($class_name) > 100) {
            $errors[] = 'Class name cannot exceed 100 characters.';
        }

        if ($class_level !== null && ($class_level < 1 || $class_level > 20)) {
            $errors[] = 'Class level must be between 1 and 20.';
        }

        if ($stream !== '' && strlen($stream) > 50) {
            $errors[] = 'Stream cannot exceed 50 characters.';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT class_id FROM classes
                 WHERE class_name = ?
                   AND (stream <=> ?)
                 LIMIT 1"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $class_name, $stream);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors[] = 'A class with this name and stream already exists.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            $_SESSION['classes_flash'] = [
                'type'    => 'error',
                'message' => implode(' ', $errors),
            ];
            header('Location: classes.php');
            exit;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO classes
             (class_name, class_level, stream, status)
             VALUES (?, ?, ?, ?)"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'siss',
                $class_name,
                $class_level,
                $stream,
                $status
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Class created successfully.');
            }

            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Could not create class: ' . $err);
        }

        redirect_with_flash('error', 'Could not prepare query.');
    }


    /* -------------------------------------------------------------
       UPDATE
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $class_id    = (int) ($_POST['class_id'] ?? 0);
        $class_name  = trim($_POST['class_name'] ?? '');
        $class_level = $_POST['class_level'] !== '' ? (int) $_POST['class_level'] : null;
        $stream      = trim($_POST['stream'] ?? '');
        $status      = $_POST['status'] ?? 'active';

        $errors = [];

        if ($class_id <= 0) {
            $errors[] = 'Invalid class.';
        }

        if ($class_name === '') {
            $errors[] = 'Class name is required.';
        } elseif (strlen($class_name) > 100) {
            $errors[] = 'Class name cannot exceed 100 characters.';
        }

        if ($class_level !== null && ($class_level < 1 || $class_level > 20)) {
            $errors[] = 'Class level must be between 1 and 20.';
        }

        if ($stream !== '' && strlen($stream) > 50) {
            $errors[] = 'Stream cannot exceed 50 characters.';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT class_id FROM classes
                 WHERE class_name = ?
                   AND (stream <=> ?)
                   AND class_id <> ?
                 LIMIT 1"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssi', $class_name, $stream, $class_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors[] = 'Another class already uses this name and stream.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            $_SESSION['classes_flash'] = [
                'type'    => 'error',
                'message' => implode(' ', $errors),
            ];
            header('Location: classes.php');
            exit;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE classes
             SET class_name = ?,
                 class_level = ?,
                 stream = ?,
                 status = ?,
                 updated_at = NOW()
             WHERE class_id = ?"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sissi',
                $class_name,
                $class_level,
                $stream,
                $status,
                $class_id
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Class updated successfully.');
            }

            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Could not update class: ' . $err);
        }

        redirect_with_flash('error', 'Could not prepare query.');
    }


    /* -------------------------------------------------------------
       TOGGLE STATUS
    ------------------------------------------------------------- */
    if ($action === 'toggle') {

        $class_id = (int) ($_POST['class_id'] ?? 0);

        if ($class_id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE classes
                 SET status = IF(status = 'active', 'inactive', 'active'),
                     updated_at = NOW()
                 WHERE class_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $class_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Class status updated.');
            }
        }

        redirect_with_flash('error', 'Could not update status.');
    }


    /* -------------------------------------------------------------
       DELETE
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $class_id = (int) ($_POST['class_id'] ?? 0);

        if ($class_id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM classes WHERE class_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $class_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Class deleted.');
            }
        }

        redirect_with_flash('error', 'Could not delete class.');
    }
}


/* =========================================================================
   LOAD FLASH
   ========================================================================= */

$flash = $_SESSION['classes_flash'] ?? null;
unset($_SESSION['classes_flash']);


/* =========================================================================
   FILTERS + SEARCH
   ========================================================================= */

$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    /* Search class name, stream AND teacher full name parts */
    $where[]  = "(c.class_name LIKE ?
                  OR c.stream LIKE ?
                  OR u.first_name LIKE ?
                  OR u.middle_name LIKE ?
                  OR u.last_name LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sssss';
}

if (in_array($status_filter, ['active', 'inactive'], true)) {
    $where[]  = "c.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

/* -------------------------------------------------------------------------
   Join path:
     classes (c)
       -> class_teachers (ct)  : most recent active assignment for this class
       -> teachers (t)         : teacher_id -> user_id
       -> users (u)            : actual first_name / middle_name / last_name
------------------------------------------------------------------------- */

$sql = "SELECT
            c.class_id,
            c.class_name,
            c.class_level,
            c.stream,
            c.status,
            c.created_at,
            u.first_name  AS teacher_first_name,
            u.middle_name AS teacher_middle_name,
            u.last_name   AS teacher_last_name
        FROM classes c
        LEFT JOIN class_teachers ct
               ON ct.class_id = c.class_id
              AND ct.status = 'active'
              AND ct.assigned_at = (
                    SELECT MAX(ct2.assigned_at)
                    FROM class_teachers ct2
                    WHERE ct2.class_id = c.class_id
                      AND ct2.status = 'active'
              )
        LEFT JOIN teachers t
               ON t.teacher_id = ct.teacher_id
        LEFT JOIN users u
               ON u.user_id = t.user_id";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC";

$classes = [];

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $classes[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   COUNTS
   ========================================================================= */

$total_classes  = count($classes);
$active_count   = 0;
$inactive_count = 0;

$count_res = mysqli_query(
    $conn,
    "SELECT status, COUNT(*) AS c FROM classes GROUP BY status"
);

if ($count_res) {
    while ($row = mysqli_fetch_assoc($count_res)) {
        if ($row['status'] === 'active')   $active_count   = (int)$row['c'];
        if ($row['status'] === 'inactive') $inactive_count = (int)$row['c'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes | PSRMS Admin</title>

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
            --border: #e1e4e9;
            --red: #a04b4b;
            --red-bg: #fbefef;
            --green: #397154;
            --green-bg: #edf6f0;
            --blue: #2f5d8f;
            --blue-bg: #eaf1fa;
        }

        html, body { overflow-x: hidden; }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
        }

        /* =========================================================
           LAYOUT
        ========================================================== */

        .main-content {
            margin-left: 255px;
            padding: 105px 30px 40px;
            transition: margin-left .25s ease;
        }

        /* =========================================================
           MOBILE TOP BAR (hamburger)
        ========================================================== */

        .mobile-topbar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 58px;
            background: var(--navy);
            color: var(--white);
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 1100;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }

        .mobile-topbar .brand {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .mobile-topbar .brand span {
            color: var(--gold-light);
        }

        .hamburger {
            width: 40px;
            height: 40px;
            border: none;
            background: rgba(255,255,255,.08);
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            padding: 0;
        }

        .hamburger span {
            display: block;
            width: 18px;
            height: 2px;
            background: var(--white);
            border-radius: 2px;
            transition: .2s ease;
        }

        .hamburger.active span:nth-child(1) {
            transform: translateY(6px) rotate(45deg);
        }
        .hamburger.active span:nth-child(2) {
            opacity: 0;
        }
        .hamburger.active span:nth-child(3) {
            transform: translateY(-6px) rotate(-45deg);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050;
            opacity: 0;
            transition: opacity .25s ease;
        }

        .sidebar-overlay.open {
            display: block;
            opacity: 1;
        }

        /* =========================================================
           HEADER
        ========================================================== */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 750;
            line-height: 1.2;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        /* =========================================================
           BUTTONS
        ========================================================== */

        .btn {
            border: none;
            border-radius: 6px;
            padding: 0 16px;
            min-height: 40px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-decoration: none;
            white-space: nowrap;
            transition: .15s ease;
        }

        .btn-primary {
            background: var(--navy);
            color: var(--white);
        }
        .btn-primary:hover { background: var(--navy-dark); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { border-color: var(--gold); }

        /* =========================================================
           STATS
        ========================================================== */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 18px 20px;
        }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 22px;
            font-weight: 750;
            margin-top: 6px;
        }

        /* =========================================================
           FLASH
        ========================================================== */

        .alert {
            border-radius: 7px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.5;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* =========================================================
           FILTER BAR
        ========================================================== */

        .filter-bar {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 14px 16px;
            margin-bottom: 18px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-bar input[type="text"],
        .filter-bar select {
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            outline: none;
            background: #fdfdfd;
        }

        .filter-bar input[type="text"] {
            flex: 1;
            min-width: 160px;
        }

        .filter-bar input[type="text"]:focus,
        .filter-bar select:focus {
            border-color: var(--gold);
            background: #fff;
        }

        /* =========================================================
           TABLE CARD
        ========================================================== */

        .table-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow: hidden;
        }

        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }

        thead th {
            text-align: left;
            padding: 13px 16px;
            background: #fafaf8;
            color: var(--muted);
            font-size: 10px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfbf8; }

        .class-name {
            color: var(--navy);
            font-size: 12px;
            font-weight: 750;
        }

        .class-sub {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .teacher-name {
            color: var(--text);
            font-weight: 600;
        }

        .teacher-none {
            color: var(--muted);
            font-style: italic;
        }

        .pill {
            display: inline-block;
            font-size: 9px;
            font-weight: 750;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }
        .pill.active   { background: var(--green-bg); color: var(--green); }
        .pill.inactive { background: #f0efec;         color: #7a7a72; }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 32px;
            padding: 0 11px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
        }

        .icon-btn:hover { border-color: var(--gold); }

        .icon-btn.danger { color: var(--red); }
        .icon-btn.danger:hover { border-color: var(--red); background: var(--red-bg); }

        .empty {
            text-align: center;
            padding: 45px 20px;
            color: var(--muted);
            font-size: 12px;
        }

        /* =========================================================
           MOBILE CARD LIST
        ========================================================== */

        .class-cards { display: none; }

        .class-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .class-card:last-child { border-bottom: none; }

        .class-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .class-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
            font-size: 10.5px;
            margin-bottom: 12px;
        }

        .class-card-meta .k {
            color: var(--muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
            font-size: 9px;
            margin-bottom: 2px;
        }

        .class-card-meta .v {
            color: var(--text);
            font-weight: 600;
        }

        .class-card-meta .full { grid-column: 1 / -1; }

        .class-card-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }

        .class-card-actions .icon-btn,
        .class-card-actions form { width: 100%; }

        .class-card-actions form button { width: 100%; }

        /* =========================================================
           MODAL
        ========================================================== */

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(16, 24, 43, .55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-backdrop.open { display: flex; }

        .modal {
            background: var(--white);
            border-radius: 10px;
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            animation: pop .2s ease-out;
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
        }

        @keyframes pop {
            from { transform: scale(.96); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .modal-header h2 {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
        }

        .modal-close {
            border: none;
            background: transparent;
            font-size: 22px;
            color: var(--muted);
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }

        .modal-body {
            padding: 22px;
            overflow-y: auto;
            flex: 1;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 16px;
        }

        .form-grid .full { grid-column: 1 / -1; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .required { color: var(--red); }

        .form-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fdfdfd;
            color: var(--text);
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            outline: none;
            transition: .2s ease;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.08);
        }

        .modal-footer {
            padding: 15px 22px;
            background: #fafaf8;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        body.no-scroll { overflow: hidden; }

        /* =========================================================
           BREAKPOINTS
        ========================================================== */

        @media (max-width: 900px) {
            .mobile-topbar { display: flex; }

            .main-content {
                margin-left: 0;
                padding: 80px 20px 30px;
            }
        }

        @media (max-width: 650px) {

            .main-content { padding: 78px 14px 28px; }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .page-header .btn {
                width: 100%;
                justify-content: center;
            }

            .page-title h1 { font-size: 20px; }
            .page-title p  { font-size: 11px; }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card { padding: 14px 16px; }
            .stat-card .value { font-size: 18px; }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
                padding: 12px;
            }

            .filter-bar input[type="text"],
            .filter-bar select,
            .filter-bar .btn {
                width: 100%;
            }

            .table-wrap { display: none; }
            .class-cards { display: block; }

            .form-grid { grid-template-columns: 1fr; }

            .modal-backdrop { padding: 12px; align-items: flex-start; }

            .modal {
                max-width: 100%;
                margin-top: 20px;
                border-radius: 12px;
            }

            .modal-body { padding: 18px; }

            .modal-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
            }

            .modal-footer .btn { width: 100%; }
        }

        @media (max-width: 380px) {
            .stats-grid { grid-template-columns: 1fr; }
            .class-card-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>

<!-- =========================================================
     MOBILE TOP BAR
========================================================== -->
<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Admin</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>


<?php include 'admin_sidebar.php'; ?>
<?php
$topbar_title    = 'Classes';
$topbar_subtitle = '';
include '../includes/topbar.php';
?>


<main class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Classes</h1>
            <p>Manage all classes and streams in the school.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            + Add Class
        </button>
    </div>


    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>


    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Classes</div>
            <div class="value"><?php echo $total_classes; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo $active_count; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Inactive</div>
            <div class="value"><?php echo $inactive_count; ?></div>
        </div>
    </div>


    <!-- FILTERS -->
    <form method="GET" class="filter-bar">
        <input
            type="text"
            name="q"
            placeholder="Search by class, stream, or teacher…"
            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
        >

        <select name="status">
            <option value="">All statuses</option>
            <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>

        <button type="submit" class="btn btn-ghost">Filter</button>

        <?php if ($search !== '' || $status_filter !== ''): ?>
            <a href="classes.php" class="btn btn-ghost">Clear</a>
        <?php endif; ?>
    </form>


    <!-- TABLE / CARDS -->
    <div class="table-card">

        <?php if (empty($classes)): ?>

            <div class="empty">
                No classes found.
                <?php if ($search !== '' || $status_filter !== ''): ?>
                    Try clearing the filters.
                <?php endif; ?>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Level</th>
                            <th>Stream</th>
                            <th>Class Teacher</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                            <?php $teacher_name = build_teacher_name($c); ?>
                            <tr>
                                <td>
                                    <div class="class-name">
                                        <?php echo htmlspecialchars($c['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php echo $c['class_level'] !== null
                                        ? htmlspecialchars($c['class_level'], ENT_QUOTES, 'UTF-8')
                                        : '—'; ?>
                                </td>

                                <td>
                                    <?php echo $c['stream'] !== null && $c['stream'] !== ''
                                        ? htmlspecialchars($c['stream'], ENT_QUOTES, 'UTF-8')
                                        : '—'; ?>
                                </td>

                                <td>
                                    <?php if ($teacher_name !== ''): ?>
                                        <span class="teacher-name">
                                            <?php echo htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="teacher-none">Not assigned</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="pill <?php echo htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars(
                                        date('M j, Y', strtotime($c['created_at'])),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </td>

                                <td>
                                    <div class="actions">

                                        <button type="button"
                                            class="icon-btn"
                                            onclick='openEditModal(<?php echo json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                            Edit
                                        </button>

                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Toggle status for this class?');">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="class_id" value="<?php echo (int)$c['class_id']; ?>">
                                            <button type="submit" class="icon-btn">
                                                <?php echo $c['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>

                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Delete this class permanently?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="class_id" value="<?php echo (int)$c['class_id']; ?>">
                                            <button type="submit" class="icon-btn danger">Delete</button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>


            <!-- MOBILE CARDS -->
            <div class="class-cards">
                <?php foreach ($classes as $c): ?>
                    <?php $teacher_name = build_teacher_name($c); ?>
                    <div class="class-card">

                        <div class="class-card-top">
                            <div>
                                <div class="class-name">
                                    <?php echo htmlspecialchars($c['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>

                            <span class="pill <?php echo htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>

                        <div class="class-card-meta">
                            <div>
                                <div class="k">Level</div>
                                <div class="v">
                                    <?php echo $c['class_level'] !== null
                                        ? htmlspecialchars($c['class_level'], ENT_QUOTES, 'UTF-8')
                                        : '—'; ?>
                                </div>
                            </div>

                            <div>
                                <div class="k">Stream</div>
                                <div class="v">
                                    <?php echo $c['stream'] !== null && $c['stream'] !== ''
                                        ? htmlspecialchars($c['stream'], ENT_QUOTES, 'UTF-8')
                                        : '—'; ?>
                                </div>
                            </div>

                            <div class="full">
                                <div class="k">Class Teacher</div>
                                <div class="v">
                                    <?php if ($teacher_name !== ''): ?>
                                        <?php echo htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <span class="teacher-none">Not assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <div class="k">Created</div>
                                <div class="v">
                                    <?php echo htmlspecialchars(
                                        date('M j, Y', strtotime($c['created_at'])),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </div>
                            </div>
                        </div>

                        <div class="class-card-actions">

                            <button type="button"
                                class="icon-btn"
                                onclick='openEditModal(<?php echo json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Edit
                            </button>

                            <form method="POST"
                                  onsubmit="return confirm('Toggle status for this class?');">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="class_id" value="<?php echo (int)$c['class_id']; ?>">
                                <button type="submit" class="icon-btn">
                                    <?php echo $c['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>

                            <form method="POST"
                                  onsubmit="return confirm('Delete this class permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="class_id" value="<?php echo (int)$c['class_id']; ?>">
                                <button type="submit" class="icon-btn danger">Delete</button>
                            </form>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</main>


<!-- =========================================================
     CREATE MODAL
========================================================= -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST" action="classes.php">

            <div class="modal-header">
                <h2>Add Class</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">×</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label>Class Name <span class="required">*</span></label>
                        <input type="text" name="class_name" class="form-control"
                               maxlength="100" placeholder="e.g. Form One" required>
                    </div>

                    <div class="form-group">
                        <label>Class Level</label>
                        <input type="number" name="class_level" class="form-control"
                               min="1" max="20" placeholder="e.g. 1">
                    </div>

                    <div class="form-group">
                        <label>Stream</label>
                        <input type="text" name="stream" class="form-control"
                               maxlength="50" placeholder="e.g. A">
                    </div>

                    <div class="form-group full">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Create Class
                </button>
            </div>

            <input type="hidden" name="action" value="create">
        </form>
    </div>
</div>


<!-- =========================================================
     EDIT MODAL
========================================================= -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <form method="POST" action="classes.php">

            <div class="modal-header">
                <h2>Edit Class</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label>Class Name <span class="required">*</span></label>
                        <input type="text" name="class_name" id="edit_class_name"
                               class="form-control" maxlength="100" required>
                    </div>

                    <div class="form-group">
                        <label>Class Level</label>
                        <input type="number" name="class_level" id="edit_class_level"
                               class="form-control" min="1" max="20">
                    </div>

                    <div class="form-group">
                        <label>Stream</label>
                        <input type="text" name="stream" id="edit_stream"
                               class="form-control" maxlength="50">
                    </div>

                    <div class="form-group full">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Save Changes
                </button>
            </div>

            <input type="hidden" name="action" value="update">
            <input type="hidden" name="class_id" id="edit_class_id">
        </form>
    </div>
</div>


<script>
/* =========================================================================
   MODAL CONTROLS
   ========================================================================= */

function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openEditModal(data) {
    document.getElementById('edit_class_id').value    = data.class_id    || '';
    document.getElementById('edit_class_name').value  = data.class_name  || '';
    document.getElementById('edit_class_level').value = data.class_level ?? '';
    document.getElementById('edit_stream').value      = data.stream      || '';
    document.getElementById('edit_status').value      = data.status      || 'active';

    document.getElementById('editModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');

    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-backdrop').forEach(function (bd) {
    bd.addEventListener('click', function (e) {
        if (e.target === bd) closeModal(bd.id);
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open')
            .forEach(function (bd) { closeModal(bd.id); });
        closeSidebar();
    }
});


/* =========================================================================
   MOBILE SIDEBAR CONTROLS
   ========================================================================= */

const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');

    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar');
    if (sidebar) sidebar.classList.add('open');

    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}

function closeSidebar() {
    sidebarOverlay.classList.remove('open');

    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar');
    if (sidebar) sidebar.classList.remove('open');

    if (hamburgerBtn) hamburgerBtn.classList.remove('active');

    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', function () {
        if (sidebarOverlay.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', function () {
    if (window.innerWidth > 900) closeSidebar();
});
</script>

</body>
</html>