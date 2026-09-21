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
    $_SESSION['subjects_flash'] = ['type' => $type, 'message' => $message];
    header('Location: subjects.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


/* Valid enum values — MATCH your DB */
$valid_types = [
    'academic',
    'competency',
    'science',
    'business',
    'arts',
    'language',
    'technical',
    'religious',
    'vocational',
    'other',
];


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_type = $_POST['subject_type'] ?? 'academic';
        $status       = $_POST['status'] ?? 'active';

        $errors = [];

        if ($subject_name === '') {
            $errors[] = 'Subject name is required.';
        } elseif (strlen($subject_name) > 150) {
            $errors[] = 'Subject name cannot exceed 150 characters.';
        }

        if (!in_array($subject_type, $valid_types, true)) {
            $subject_type = 'academic';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        /* Duplicate check: subject_name */
        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT subject_id FROM subjects WHERE subject_name = ? LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $subject_name);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors[] = 'A subject with this name already exists.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            $_SESSION['subjects_flash'] = [
                'type' => 'error',
                'message' => implode(' ', $errors),
            ];
            header('Location: subjects.php');
            exit;
        }

        /* Insert */
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO subjects (subject_name, subject_type, status)
             VALUES (?, ?, ?)"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sss',
                $subject_name,
                $subject_type,
                $status
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject created successfully.');
            }

            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Could not create subject: ' . $err);
        }

        redirect_with_flash('error', 'Could not prepare query.');
    }


    /* -------------------------------------------------------------
       UPDATE
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $subject_id   = (int) ($_POST['subject_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_type = $_POST['subject_type'] ?? 'academic';
        $status       = $_POST['status'] ?? 'active';

        $errors = [];

        if ($subject_id <= 0) {
            $errors[] = 'Invalid subject.';
        }

        if ($subject_name === '') {
            $errors[] = 'Subject name is required.';
        } elseif (strlen($subject_name) > 150) {
            $errors[] = 'Subject name cannot exceed 150 characters.';
        }

        if (!in_array($subject_type, $valid_types, true)) {
            $subject_type = 'academic';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        /* Duplicate name check (excluding self) */
        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT subject_id FROM subjects
                 WHERE subject_name = ? AND subject_id <> ?
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $subject_name, $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors[] = 'Another subject already uses this name.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            $_SESSION['subjects_flash'] = [
                'type' => 'error',
                'message' => implode(' ', $errors),
            ];
            header('Location: subjects.php');
            exit;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE subjects
             SET subject_name = ?,
                 subject_type = ?,
                 status = ?
             WHERE subject_id = ?"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sssi',
                $subject_name,
                $subject_type,
                $status,
                $subject_id
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject updated successfully.');
            }

            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Could not update subject: ' . $err);
        }

        redirect_with_flash('error', 'Could not prepare query.');
    }


    /* -------------------------------------------------------------
       TOGGLE STATUS
    ------------------------------------------------------------- */
    if ($action === 'toggle') {

        $subject_id = (int) ($_POST['subject_id'] ?? 0);

        if ($subject_id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE subjects
                 SET status = IF(status = 'active', 'inactive', 'active')
                 WHERE subject_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject status updated.');
            }
        }

        redirect_with_flash('error', 'Could not update status.');
    }


    /* -------------------------------------------------------------
       DELETE
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $subject_id = (int) ($_POST['subject_id'] ?? 0);

        if ($subject_id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM subjects WHERE subject_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject deleted.');
            }
        }

        redirect_with_flash('error', 'Could not delete subject.');
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */

$flash = $_SESSION['subjects_flash'] ?? null;
unset($_SESSION['subjects_flash']);


/* =========================================================================
   FILTERS
   ========================================================================= */

$search        = trim($_GET['q'] ?? '');
$type_filter   = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "subject_name LIKE ?";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $types   .= 's';
}

if (in_array($type_filter, $valid_types, true)) {
    $where[]  = "subject_type = ?";
    $params[] = $type_filter;
    $types   .= 's';
}

if (in_array($status_filter, ['active', 'inactive'], true)) {
    $where[]  = "status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

$sql = "SELECT subject_id, subject_name, subject_type, status, created_at
        FROM subjects";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY subject_name ASC";

$subjects = [];

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $subjects[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   COUNTS
   ========================================================================= */

$total_subjects  = 0;
$active_count    = 0;
$inactive_count  = 0;

$count_res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')   AS active_total,
        SUM(status = 'inactive') AS inactive_total
     FROM subjects"
);

if ($count_res) {
    $c = mysqli_fetch_assoc($count_res);
    $total_subjects = (int)($c['total'] ?? 0);
    $active_count   = (int)($c['active_total'] ?? 0);
    $inactive_count = (int)($c['inactive_total'] ?? 0);
}

$has_filters = ($search !== '' || $type_filter !== '' || $status_filter !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Subjects | PSRMS Admin</title>

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
            --pink: #8f3a6a;
            --pink-bg: #fbeef5;
            --sky: #2a6b8f;
            --sky-bg: #eaf6fb;
            --violet: #6a4a9e;
            --violet-bg: #f4efff;
            --leaf: #467a3c;
            --leaf-bg: #f0f7ee;
            --grey: #7a7a72;
            --grey-bg: #f0efec;
            --sidebar-w: 250px;
            --topbar-h: 78px;
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

        /* =========================================================
           MOBILE TOPBAR
        ========================================================= */
        .mobile-topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 58px;
            background: var(--navy);
            color: var(--white);
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 1100;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }

        .mobile-topbar .brand { font-size: 14px; font-weight: 800; letter-spacing: .5px; }
        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px; height: 40px;
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
            width: 18px; height: 2px;
            background: var(--white);
            border-radius: 2px;
            transition: .2s ease;
        }

        .hamburger.active span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .hamburger.active span:nth-child(2) { opacity: 0; }
        .hamburger.active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050;
            opacity: 0;
            transition: opacity .25s ease;
        }

        .sidebar-overlay.open { display: block; opacity: 1; }

        /* =========================================================
           MAIN CONTENT
        ========================================================= */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* =========================================================
           PAGE HEADER
        ========================================================= */
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
            font-weight: 700;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 0 16px;
            min-height: 42px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-decoration: none;
            white-space: nowrap;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover { background: var(--navy-dark); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { border-color: var(--gold); }

        /* =========================================================
           STATS
        ========================================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px 20px;
        }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 24px;
            font-weight: 750;
            margin-top: 6px;
        }

        /* =========================================================
           ALERTS
        ========================================================= */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* =========================================================
           FILTERS
        ========================================================= */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.6fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .filter-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* =========================================================
           TABLE
        ========================================================= */
        .table-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        thead th {
            text-align: left;
            padding: 13px 16px;
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 12.5px;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfbf8; }

        .subject-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
        }

        .subject-id {
            color: var(--muted);
            font-size: 10px;
            margin-top: 2px;
        }

        /* =========================================================
           TYPE BADGES — 10 types
        ========================================================= */
        .badge {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 750;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }

        .badge-academic   { background: var(--blue-bg);   color: var(--blue); }
        .badge-competency { background: var(--green-bg);  color: var(--green); }
        .badge-science    { background: var(--purple-bg); color: var(--purple); }
        .badge-business   { background: var(--orange-bg); color: var(--orange); }
        .badge-arts       { background: var(--pink-bg);   color: var(--pink); }
        .badge-language   { background: var(--sky-bg);    color: var(--sky); }
        .badge-technical  { background: var(--blue-bg);   color: var(--blue); }
        .badge-religious  { background: var(--violet-bg); color: var(--violet); }
        .badge-vocational { background: var(--leaf-bg);   color: var(--leaf); }
        .badge-other      { background: var(--grey-bg);   color: var(--grey); }

        /* =========================================================
           STATUS
        ========================================================= */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status::before {
            content: "";
            width: 5px; height: 5px;
            border-radius: 50%;
        }

        .status-active   { color: var(--green);  background: var(--green-bg); }
        .status-active::before { background: var(--green); }

        .status-inactive { color: var(--orange); background: var(--orange-bg); }
        .status-inactive::before { background: var(--orange); }

        /* =========================================================
           ACTIONS
        ========================================================= */
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
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        .icon-btn.danger { color: var(--red); }
        .icon-btn.danger:hover { border-color: var(--red); background: var(--red-bg); }

        /* =========================================================
           EMPTY
        ========================================================= */
        .empty {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 14px;
            margin-bottom: 5px;
        }

        /* =========================================================
           MOBILE CARD LIST
        ========================================================= */
        .card-list { display: none; }

        .subject-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .subject-card:last-child { border-bottom: none; }

        .subject-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .subject-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
            margin-bottom: 12px;
        }

        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 3px;
        }

        .meta-item .v {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
        }

        .subject-card-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .subject-card-actions .icon-btn,
        .subject-card-actions form,
        .subject-card-actions form button {
            width: 100%;
            min-height: 40px;
            font-size: 11.5px;
        }

        /* =========================================================
           MODAL
        ========================================================= */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(16,24,43,.55);
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
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .required { color: var(--red); }

        .form-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13px;
            color: var(--text);
            outline: none;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
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

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .mobile-topbar { display: flex; }

            .main-content {
                margin-left: 0;
                padding: 78px 16px 30px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .page-title h1 { font-size: 21px; }

            .page-header .btn {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }

            .filter-form { grid-template-columns: 1fr; gap: 12px; }
            .filter-control,
            .btn { height: 46px; font-size: 13px; }

            /* Table → Cards */
            .table-wrapper { display: none; }
            .card-list { display: block; }
        }

        @media (max-width: 550px) {

            .main-content { padding: 74px 14px 24px; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card .value { font-size: 18px; }
            .stat-card .label { font-size: 9px; }

            .subject-card-meta { grid-template-columns: 1fr; gap: 8px; }
            .subject-card-actions { grid-template-columns: 1fr 1fr; }

            .form-grid { grid-template-columns: 1fr; }

            .modal-backdrop { padding: 12px; align-items: flex-start; }
            .modal { margin-top: 20px; border-radius: 12px; max-width: 100%; }
            .modal-body { padding: 18px; }
            .modal-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
            }
            .modal-footer .btn { width: 100%; }
        }

        @media (max-width: 380px) {
            .stats-grid { grid-template-columns: 1fr; }
            .subject-card-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
            }
        }
    </style>
</head>
<body>

<!-- MOBILE TOPBAR -->
<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Admin</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'admin_sidebar.php'; ?>
<?php
$topbar_title    = 'Subjects';
$topbar_subtitle = '';
include '../includes/topbar.php';
?>

<main class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Subjects</h1>
            <p>Manage all subjects taught in the school.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            + Add Subject
        </button>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Subjects</div>
            <div class="value"><?php echo number_format($total_subjects); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo number_format($active_count); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Inactive</div>
            <div class="value"><?php echo number_format($inactive_count); ?></div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" class="filter-panel">
        <div class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input
                    type="text"
                    name="q"
                    class="filter-control"
                    placeholder="Subject name…"
                    value="<?php echo e($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label>Type</label>
                <select name="type" class="filter-control">
                    <option value="">All Types</option>
                    <?php foreach ($valid_types as $t): ?>
                        <option value="<?php echo e($t); ?>" <?php echo $type_filter === $t ? 'selected' : ''; ?>>
                            <?php echo ucfirst(e($t)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Statuses</option>
                    <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="btn btn-ghost">Filter</button>

            <?php if ($has_filters): ?>
                <a href="subjects.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>

        </div>
    </form>

    <!-- TABLE / CARDS -->
    <div class="table-card">

        <?php if (empty($subjects)): ?>

            <div class="empty">
                <h3>No subjects found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        Try clearing the filters.
                    <?php else: ?>
                        Add your first subject to get started.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $n = 1; foreach ($subjects as $s): ?>
                        <tr>
                            <td><?php echo $n++; ?></td>

                            <td>
                                <div class="subject-name"><?php echo e($s['subject_name']); ?></div>
                                <div class="subject-id">ID #<?php echo (int)$s['subject_id']; ?></div>
                            </td>

                            <td>
                                <span class="badge badge-<?php echo e($s['subject_type']); ?>">
                                    <?php echo e($s['subject_type']); ?>
                                </span>
                            </td>

                            <td>
                                <span class="status status-<?php echo e($s['status']); ?>">
                                    <?php echo e(ucfirst($s['status'])); ?>
                                </span>
                            </td>

                            <td>
                                <?php echo e(date('M j, Y', strtotime($s['created_at']))); ?>
                            </td>

                            <td>
                                <div class="actions">
                                    <button type="button" class="icon-btn"
                                        onclick='openEditModal(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        Edit
                                    </button>

                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Toggle status for this subject?');">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                        <button type="submit" class="icon-btn">
                                            <?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>

                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete this subject permanently?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
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
            <div class="card-list">
                <?php foreach ($subjects as $s): ?>
                    <div class="subject-card">

                        <div class="subject-card-top">
                            <div style="min-width:0;flex:1;">
                                <div class="subject-name"><?php echo e($s['subject_name']); ?></div>
                                <div class="subject-id">ID #<?php echo (int)$s['subject_id']; ?></div>
                            </div>
                            <span class="status status-<?php echo e($s['status']); ?>">
                                <?php echo e(ucfirst($s['status'])); ?>
                            </span>
                        </div>

                        <div class="subject-card-meta">
                            <div class="meta-item">
                                <span class="k">Type</span>
                                <span class="v">
                                    <span class="badge badge-<?php echo e($s['subject_type']); ?>">
                                        <?php echo e($s['subject_type']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Created</span>
                                <span class="v">
                                    <?php echo e(date('M j, Y', strtotime($s['created_at']))); ?>
                                </span>
                            </div>
                        </div>

                        <div class="subject-card-actions">
                            <button type="button" class="icon-btn"
                                onclick='openEditModal(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Edit
                            </button>

                            <form method="POST"
                                  onsubmit="return confirm('Toggle status for this subject?');">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                <button type="submit" class="icon-btn">
                                    <?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>

                            <form method="POST"
                                  onsubmit="return confirm('Delete this subject permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
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
        <form method="POST" action="subjects.php">

            <div class="modal-header">
                <h2>Add Subject</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">×</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label>Subject Name <span class="required">*</span></label>
                        <input type="text" name="subject_name" class="form-control"
                               maxlength="150" placeholder="e.g. Mathematics" required>
                    </div>

                    <div class="form-group full">
                        <label>Type</label>
                        <select name="subject_type" class="form-control">
                            <?php foreach ($valid_types as $t): ?>
                                <option value="<?php echo e($t); ?>" <?php echo $t === 'academic' ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(e($t)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                    Create Subject
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
        <form method="POST" action="subjects.php">

            <div class="modal-header">
                <h2>Edit Subject</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label>Subject Name <span class="required">*</span></label>
                        <input type="text" name="subject_name" id="edit_subject_name"
                               class="form-control" maxlength="150" required>
                    </div>

                    <div class="form-group full">
                        <label>Type</label>
                        <select name="subject_type" id="edit_subject_type" class="form-control">
                            <?php foreach ($valid_types as $t): ?>
                                <option value="<?php echo e($t); ?>">
                                    <?php echo ucfirst(e($t)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
            <input type="hidden" name="subject_id" id="edit_subject_id">
        </form>
    </div>
</div>


<script>
/* =========================================================
   MODAL CONTROLS
   ========================================================= */
function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openEditModal(data) {
    document.getElementById('edit_subject_id').value   = data.subject_id    || '';
    document.getElementById('edit_subject_name').value = data.subject_name  || '';
    document.getElementById('edit_subject_type').value = data.subject_type  || 'academic';
    document.getElementById('edit_status').value       = data.status        || 'active';

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
        document.querySelectorAll('.modal-backdrop.open').forEach(bd => closeModal(bd.id));
        closeSidebar();
    }
});


/* =========================================================
   MOBILE DRAWER
   ========================================================= */
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');
    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar, #adminSidebar');
    if (sidebar) sidebar.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}

function closeSidebar() {
    sidebarOverlay.classList.remove('open');
    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar, #adminSidebar');
    if (sidebar) sidebar.classList.remove('open');
    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', () => {
        sidebarOverlay.classList.contains('open') ? closeSidebar() : openSidebar();
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', () => {
    if (window.innerWidth > 800) closeSidebar();
});
</script>

</body>
</html>