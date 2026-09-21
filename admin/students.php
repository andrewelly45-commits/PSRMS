<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

/*
|--------------------------------------------------------------------------
| Search & Filters
|--------------------------------------------------------------------------
*/

$search   = trim($_GET['search'] ?? '');
$class_id = $_GET['class_id'] ?? '';
$status   = $_GET['status'] ?? '';

/*
|--------------------------------------------------------------------------
| Get Classes
|--------------------------------------------------------------------------
*/

$classes = [];

$class_result = mysqli_query(
    $conn,
    "SELECT class_id, class_name, stream, class_level
     FROM classes
     ORDER BY class_level ASC, class_name ASC, stream ASC"
);

if ($class_result) {
    while ($row = mysqli_fetch_assoc($class_result)) {
        $row['label'] = $row['class_name']
            . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $classes[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Build Student Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.student_id,
        s.admission_no,
        s.full_name,
        s.gender,
        s.date_of_birth,
        s.class_id,
        s.admission_date,
        s.photo,
        s.status,
        s.created_at,
        c.class_name,
        c.stream
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE 1=1
";

$params = [];
$types  = '';

if ($search !== '') {
    $sql .= " AND (s.full_name LIKE ? OR s.admission_no LIKE ?) ";
    $val      = '%' . $search . '%';
    $params[] = $val;
    $params[] = $val;
    $types   .= 'ss';
}

if ($class_id !== '') {
    $sql .= " AND s.class_id = ? ";
    $params[] = (int) $class_id;
    $types   .= 'i';
}

if ($status !== '') {
    $sql .= " AND s.status = ? ";
    $params[] = $status;
    $types   .= 's';
}

$sql .= " ORDER BY s.full_name ASC";

$stmt     = mysqli_prepare($conn, $sql);
$students = [];

if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_students     = 0;
$active_students    = 0;
$inactive_students  = 0;
$graduated_students = 0;

$result = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')    AS active_total,
        SUM(status = 'inactive')  AS inactive_total,
        SUM(status = 'graduated') AS graduated_total
     FROM students"
);

if ($result) {
    $stats              = mysqli_fetch_assoc($result);
    $total_students     = (int) ($stats['total'] ?? 0);
    $active_students    = (int) ($stats['active_total'] ?? 0);
    $inactive_students  = (int) ($stats['inactive_total'] ?? 0);
    $graduated_students = (int) ($stats['graduated_total'] ?? 0);
}

$has_filters = ($search !== '' || $class_id !== '' || $status !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Students | PSRMS</title>

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
            --green: #3e7655;
            --red: #9b4747;
            --orange: #9a7422;
            --blue: #2f5d8f;
            --sidebar-w: 250px;
            --topbar-h: 64px;
        }

        html, body { -webkit-text-size-adjust: 100%; overflow-x: hidden; }

        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh;
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

        /* =================================================
           PAGE HEADER
        ================================================= */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-heading p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        .add-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 17px;
            background: var(--navy);
            color: var(--white);
            border-radius: 8px;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 650;
            transition: .2s ease;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .add-button:hover { background: var(--navy-dark); transform: translateY(-1px); }
        .add-button:active { transform: scale(.98); }

        .add-icon { color: var(--gold-light); font-size: 16px; line-height: 1; }

        /* =================================================
           STATS
        ================================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 19px;
            transition: .25s ease;
            min-width: 0;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 8px 20px rgba(23,35,60,.05);
        }

        .stat-label {
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .stat-value {
            color: var(--navy);
            font-size: 26px;
            font-weight: 700;
            margin-top: 7px;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }

        .stat-line {
            width: 23px;
            height: 2px;
            background: var(--gold);
            margin-top: 12px;
        }

        /* =================================================
           FILTER
        ================================================= */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .filter-toggle {
            display: none;
            width: 100%;
            background: none;
            border: none;
            padding: 0 0 14px;
            margin-bottom: 4px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            color: var(--navy);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            text-align: left;
            justify-content: space-between;
            align-items: center;
            -webkit-tap-highlight-color: transparent;
        }

        .filter-toggle .chev {
            color: var(--gold);
            font-size: 12px;
            transition: transform .25s ease;
            display: inline-block;
        }

        .filter-panel.collapsed .filter-toggle .chev {
            transform: rotate(-90deg);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group { min-width: 0; }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .filter-control {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: .2s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        select.filter-control,
        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        .filter-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
            background-color: var(--white);
        }

        .filter-button {
            height: 42px;
            padding: 0 22px;
            border: none;
            border-radius: 8px;
            background: var(--navy);
            color: var(--white);
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 650;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .filter-button:hover { background: var(--navy-dark); }

        .reset-button {
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: .2s ease;
        }

        .reset-button:hover { color: var(--navy); border-color: #c8ccd3; }

        /* =================================================
           RESULTS BAR
        ================================================= */
        .results-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .results-bar h2 { color: var(--navy); font-size: 15px; }
        .results-count { color: var(--muted); font-size: 11.5px; }

        /* =================================================
           TABLE
        ================================================= */
        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        thead { background: #fafaf8; }

        th {
            padding: 13px 15px;
            text-align: left;
            color: #737c8c;
            font-size: 9.5px;
            font-weight: 750;
            letter-spacing: .7px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        td {
            padding: 13px 15px;
            border-bottom: 1px solid #f0f1f3;
            color: var(--text);
            font-size: 12.5px;
            vertical-align: middle;
        }

        tbody tr:hover { background: #fdfcf8; }
        tbody tr:last-child td { border-bottom: none; }

        /* =================================================
           STUDENT CELL
        ================================================= */
        .student-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .student-photo,
        .student-placeholder {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
        }

        .student-photo { border: 1px solid #e5e1d2; }

        .student-placeholder {
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        .student-name {
            font-weight: 650;
            color: var(--navy);
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .student-id { color: var(--muted); font-size: 10px; margin-top: 2px; }

        .admission    { color: var(--navy); font-weight: 600; }
        .muted-value  { color: var(--muted); }
        .class-name   { color: var(--text); font-weight: 600; }
        .stream       { color: var(--muted); font-size: 10px; margin-left: 3px; }

        /* =================================================
           STATUS
        ================================================= */
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
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        .status-active      { color: var(--green);  background: #eef6f0; }
        .status-active::before { background: var(--green); }
        .status-inactive    { color: var(--orange); background: #faf5e8; }
        .status-inactive::before { background: var(--orange); }
        .status-graduated   { color: var(--navy);   background: #eef0f5; }
        .status-graduated::before { background: var(--navy); }
        .status-transferred { color: var(--red);    background: #faf0f0; }
        .status-transferred::before { background: var(--red); }

        /* =================================================
           ACTIONS
        ================================================= */
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .action-btn {
            min-height: 32px;
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--white);
            color: var(--navy);
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .action-btn:hover { border-color: var(--gold); color: var(--gold); }
        .action-btn:active { transform: scale(.95); }

        .action-btn.transfer {
            color: var(--orange);
            border-color: #ecd9a8;
        }
        .action-btn.transfer:hover {
            background: #faf5e8;
            border-color: var(--orange);
        }

        /* =================================================
           MOBILE CARDS
        ================================================= */
        .card-list { display: none; }

        .student-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .student-card:last-child { margin-bottom: 0; }

        .student-card-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f0f1f3;
        }

        .student-card-top .student-name { font-size: 14px; }
        .student-card-top .student-id { font-size: 10.5px; }

        .student-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
            margin-bottom: 14px;
        }

        .meta-item { min-width: 0; }

        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .meta-item .v {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .student-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px solid #f0f1f3;
        }

        .student-card-actions .action-btn {
            min-height: 40px;
            font-size: 11.5px;
            justify-content: center;
            padding: 0 8px;
        }

        /* =================================================
           EMPTY
        ================================================= */
        .empty-state { padding: 55px 20px; text-align: center; }

        .empty-icon {
            width: 54px; height: 54px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 15px;
        }

        .empty-state h3 { color: var(--navy); font-size: 14.5px; margin-bottom: 5px; }
        .empty-state p  { color: var(--muted); font-size: 12px; }

        /* =================================================
           MODAL (shared)
        ================================================= */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16, 24, 43, .55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .2s ease;
        }

        .modal-backdrop.open { display: flex; opacity: 1; }

        .modal {
            background: var(--white);
            border-radius: 14px;
            width: 100%;
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
            animation: pop .18s ease-out;
        }

        @keyframes pop {
            from { transform: scale(.96); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            position: sticky; top: 0; background: var(--white);
            z-index: 1;
        }

        .modal-header h2 { color: var(--navy); font-size: 15px; font-weight: 700; }

        .modal-close {
            width: 34px; height: 34px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f3f4f6;
            border: none;
            border-radius: 8px;
            color: var(--muted);
            cursor: pointer;
            font-size: 18px; line-height: 1;
            -webkit-tap-highlight-color: transparent;
        }
        .modal-close:hover { background: #e5e7eb; color: var(--navy); }

        .modal-body { padding: 22px; }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            position: sticky; bottom: 0; background: var(--white);
        }

        /* =================================================
           MODAL CONTENT — VIEW
        ================================================= */
        .view-hero {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-bottom: 18px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }

        .view-photo {
            width: 76px; height: 76px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
            border: 2px solid var(--border);
        }

        .view-photo-placeholder {
            width: 76px; height: 76px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--navy);
            color: var(--gold-light);
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; font-weight: 800;
        }

        .view-hero-info { flex: 1; min-width: 0; }
        .view-hero-info h3 {
            color: var(--navy);
            font-size: 17px;
            font-weight: 750;
            margin-bottom: 4px;
            overflow-wrap: anywhere;
        }
        .view-hero-info .adm {
            color: var(--muted);
            font-size: 11.5px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            margin-bottom: 8px;
        }

        .view-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 20px;
        }

        .view-item { min-width: 0; }
        .view-item .k {
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 4px;
        }
        .view-item .v {
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .view-item.full { grid-column: 1 / -1; }

        /* =================================================
           MODAL FORM (EDIT / TRANSFER)
        ================================================= */
        .form-group { margin-bottom: 16px; }

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
            transition: .2s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-grid .full { grid-column: 1 / -1; }

        /* =================================================
           BUTTONS
        ================================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 44px;
            padding: 0 22px;
            border: none;
            border-radius: 9px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover:not(:disabled) { background: var(--navy-dark); }

        .btn-ghost {
            background: var(--white);
            color: var(--muted);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { color: var(--navy); border-color: #c8ccd3; }

        .btn-warning { background: var(--orange); color: var(--white); }
        .btn-warning:hover:not(:disabled) { background: #866018; }

        .btn .spinner {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: var(--white);
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }

        .btn.loading .spinner { display: inline-block; }
        .btn.loading .btn-label { opacity: .7; }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* =================================================
           TOASTS
        ================================================= */
        .toast-wrap {
            position: fixed;
            top: 20px; right: 20px;
            z-index: 3000;
            display: flex; flex-direction: column; gap: 10px;
            pointer-events: none;
        }

        .toast {
            padding: 13px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-left: 3px solid var(--green);
            border-radius: 9px;
            box-shadow: 0 10px 30px rgba(16,24,43,.12);
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            min-width: 240px;
            max-width: 360px;
            pointer-events: auto;
            animation: slideIn .25s ease;
        }
        .toast.error { border-left-color: var(--red); }
        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }

        /* =================================================
           RESPONSIVE
        ================================================= */
        @media (max-width: 900px) {
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header {
                align-items: stretch;
                flex-direction: column;
                gap: 14px;
                margin-bottom: 18px;
            }

            .page-heading h1 { font-size: 21px; }
            .page-heading p  { font-size: 12px; line-height: 1.45; }

            .add-button {
                justify-content: center;
                min-height: 46px;
                width: 100%;
                font-size: 13px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card { padding: 15px; }
            .stat-value { font-size: 22px; }
            .stat-label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .filter-button,
            .reset-button { height: 46px; font-size: 14px; border-radius: 9px; width: 100%; }

            .results-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
                margin-bottom: 12px;
            }
            .results-bar h2 { font-size: 14px; }
            .results-count { font-size: 11px; }

            .table-panel .table-wrapper { display: none; }
            .card-list { display: block; padding: 12px; }

            .student-card {
                padding: 14px;
                margin-bottom: 10px;
                border-radius: 12px;
            }

            .student-card-meta { grid-template-columns: 1fr 1fr; }

            .student-card-actions .action-btn {
                min-height: 42px;
                font-size: 12px;
            }

            .student-card .student-photo,
            .student-card .student-placeholder {
                width: 42px; height: 42px; font-size: 13px;
            }

            /* Modal — bottom sheet */
            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal {
                max-width: 100%;
                max-height: 92vh;
                border-radius: 16px 16px 0 0;
            }
            .modal-header { padding: 16px 18px; }
            .modal-body { padding: 18px; }
            .modal-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
            }
            .modal-footer .btn { width: 100%; }

            .view-grid { grid-template-columns: 1fr; gap: 12px; }
            .form-grid { grid-template-columns: 1fr; gap: 12px; }
            .form-grid .full { grid-column: auto; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-heading h1 { font-size: 19px; }
            .page-heading p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card { padding: 13px; }
            .stat-value { font-size: 20px; }
            .stat-label { font-size: 8.5px; }

            .filter-panel { padding: 12px; }
            .student-card-meta { grid-template-columns: 1fr; gap: 8px; }
            .student-card-actions { grid-template-columns: 1fr 1fr; }
            .student-card-actions .action-btn:last-child {
                grid-column: 1 / -1;
            }

            .view-photo, .view-photo-placeholder {
                width: 60px; height: 60px; font-size: 20px;
            }
            .view-hero-info h3 { font-size: 15px; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr; gap: 8px; }
            .stat-card {
                display: flex; align-items: center; justify-content: space-between;
                padding: 12px 14px;
            }
            .stat-label { order: 1; margin: 0; }
            .stat-value { order: 2; margin: 0; font-size: 20px; }
            .stat-line { display: none; }
            .page-heading h1 { font-size: 18px; }
            .student-card { padding: 12px; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .modal-footer { padding-bottom: max(14px, env(safe-area-inset-bottom)); }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Students';
$topbar_subtitle = 'Manage student records';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-heading">
            <h1>Students</h1>
            <p>Manage student records from Kindergarten through Standard Seven.</p>
        </div>

        <a href="add_student.php" class="add-button">
            <span class="add-icon">+</span>
            Add Student
        </a>
    </div>

    <!-- STATS -->
    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Students</div>
            <div class="stat-value"><?php echo number_format($total_students); ?></div>
            <div class="stat-line"></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active</div>
            <div class="stat-value"><?php echo number_format($active_students); ?></div>
            <div class="stat-line"></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Inactive</div>
            <div class="stat-value"><?php echo number_format($inactive_students); ?></div>
            <div class="stat-line"></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Graduated</div>
            <div class="stat-value"><?php echo number_format($graduated_students); ?></div>
            <div class="stat-line"></div>
        </div>
    </section>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="students.php" class="filter-form">

            <div class="filter-group">
                <label>Search Student</label>
                <input type="text" name="search" class="filter-control"
                       placeholder="Name or admission no..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-group">
                <label>Class</label>
                <select name="class_id" class="filter-control">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int)$class['class_id']; ?>"
                            <?php echo ((string)$class_id === (string)$class['class_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Statuses</option>
                    <option value="active"      <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive"    <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="graduated"   <?php echo $status === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                    <option value="transferred" <?php echo $status === 'transferred' ? 'selected' : ''; ?>>Transferred</option>
                </select>
            </div>

            <button type="submit" class="filter-button">Search</button>
            <a href="students.php" class="reset-button">Reset</a>

        </form>
    </section>

    <!-- RESULTS -->
    <div class="results-bar">
        <h2>Student Records</h2>
        <span class="results-count">
            <?php echo number_format(count($students)); ?> record(s)
        </span>
    </div>

    <section class="table-panel">

        <?php if (!empty($students)): ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Student</th>
                            <th>Admission No.</th>
                            <th>Gender</th>
                            <th>Date of Birth</th>
                            <th>Class</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $number = 1;
                    foreach ($students as $student):
                        $name         = $student['full_name'];
                        $initial      = strtoupper(mb_substr($name, 0, 1));
                        $status_lower = strtolower($student['status'] ?? '');
                        $sid          = (int) $student['student_id'];
                    ?>
                        <tr data-student-id="<?php echo $sid; ?>">
                            <td><?php echo $number++; ?></td>

                            <td>
                                <div class="student-cell">
                                    <?php if (!empty($student['photo'])): ?>
                                        <img src="../uploads/students/<?php echo htmlspecialchars($student['photo']); ?>"
                                             alt="" class="student-photo" loading="lazy">
                                    <?php else: ?>
                                        <div class="student-placeholder"><?php echo $initial; ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="student-name"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="student-id">ID: <?php echo $sid; ?></div>
                                    </div>
                                </div>
                            </td>

                            <td><span class="admission"><?php echo htmlspecialchars($student['admission_no']); ?></span></td>
                            <td><?php echo htmlspecialchars($student['gender']); ?></td>

                            <td>
                                <span class="muted-value">
                                    <?php echo !empty($student['date_of_birth'])
                                        ? date('d M Y', strtotime($student['date_of_birth'])) : '—'; ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($student['class_name'])): ?>
                                    <span class="class-name"><?php echo htmlspecialchars($student['class_name']); ?></span>
                                    <?php if (!empty($student['stream'])): ?>
                                        <span class="stream">- <?php echo htmlspecialchars($student['stream']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted-value">Not Assigned</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="status status-<?php echo htmlspecialchars($status_lower); ?>"
                                      data-status>
                                    <?php echo ucfirst(htmlspecialchars($status_lower)); ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button type="button" class="action-btn"
                                            onclick="viewStudent(<?php echo $sid; ?>)">View</button>

                                    <button type="button" class="action-btn"
                                            onclick="editStudent(<?php echo $sid; ?>)">Edit</button>

                                    <button type="button" class="action-btn transfer"
                                            onclick="transferStudent(<?php echo $sid; ?>)">Transfer</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($students as $student):
                    $name         = $student['full_name'];
                    $initial      = strtoupper(mb_substr($name, 0, 1));
                    $status_lower = strtolower($student['status'] ?? '');
                    $sid          = (int) $student['student_id'];
                ?>
                    <div class="student-card" data-student-id="<?php echo $sid; ?>">

                        <div class="student-card-top">
                            <?php if (!empty($student['photo'])): ?>
                                <img src="../uploads/students/<?php echo htmlspecialchars($student['photo']); ?>"
                                     alt="" class="student-photo" loading="lazy">
                            <?php else: ?>
                                <div class="student-placeholder"><?php echo $initial; ?></div>
                            <?php endif; ?>
                            <div style="min-width:0;flex:1;">
                                <div class="student-name"><?php echo htmlspecialchars($name); ?></div>
                                <div class="student-id">ID: <?php echo $sid; ?></div>
                            </div>
                            <span class="status status-<?php echo htmlspecialchars($status_lower); ?>" data-status>
                                <?php echo ucfirst(htmlspecialchars($status_lower)); ?>
                            </span>
                        </div>

                        <div class="student-card-meta">
                            <div class="meta-item">
                                <span class="k">Admission No.</span>
                                <span class="v"><?php echo htmlspecialchars($student['admission_no']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Gender</span>
                                <span class="v"><?php echo htmlspecialchars($student['gender']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Date of Birth</span>
                                <span class="v">
                                    <?php echo !empty($student['date_of_birth'])
                                        ? date('d M Y', strtotime($student['date_of_birth'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Class</span>
                                <span class="v" data-class-label>
                                    <?php
                                    if (!empty($student['class_name'])) {
                                        echo htmlspecialchars($student['class_name']);
                                        if (!empty($student['stream'])) {
                                            echo ' - ' . htmlspecialchars($student['stream']);
                                        }
                                    } else {
                                        echo 'Not Assigned';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>

                        <div class="student-card-actions">
                            <button type="button" class="action-btn"
                                    onclick="viewStudent(<?php echo $sid; ?>)">View</button>
                            <button type="button" class="action-btn"
                                    onclick="editStudent(<?php echo $sid; ?>)">Edit</button>
                            <button type="button" class="action-btn transfer"
                                    onclick="transferStudent(<?php echo $sid; ?>)">Transfer</button>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="empty-icon">S</div>
                <h3>No students found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        No records match your search. Try clearing the filters.
                    <?php else: ?>
                        There are no student records yet. Add your first student to get started.
                    <?php endif; ?>
                </p>
            </div>

        <?php endif; ?>

    </section>

</main>


<!-- =========================================================
     VIEW MODAL
========================================================= -->
<div class="modal-backdrop" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <h2>Student Details</h2>
            <button type="button" class="modal-close" onclick="closeModal('viewModal')">✕</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div style="text-align:center;padding:40px;color:var(--muted);">Loading…</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('viewModal')">
                Close
            </button>
        </div>
    </div>
</div>


<!-- =========================================================
     EDIT MODAL
========================================================= -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <form id="editForm" autocomplete="off">
            <input type="hidden" name="student_id" id="edit_student_id">

            <div class="modal-header">
                <h2>Edit Student</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label>Admission No. <span class="req">*</span></label>
                        <input type="text" name="admission_no" id="edit_admission_no"
                               class="form-control" maxlength="50" required>
                    </div>

                    <div class="form-group full">
                        <label>Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" id="edit_full_name"
                               class="form-control" maxlength="150" required>
                    </div>

                    <div class="form-group">
                        <label>Gender <span class="req">*</span></label>
                        <select name="gender" id="edit_gender" class="form-control" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" id="edit_date_of_birth"
                               class="form-control" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Class</label>
                        <select name="class_id" id="edit_class_id" class="form-control">
                            <option value="">— Not assigned —</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['class_id']; ?>">
                                    <?php echo htmlspecialchars($c['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Admission Date</label>
                        <input type="date" name="admission_date" id="edit_admission_date"
                               class="form-control" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group full">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="graduated">Graduated</option>
                            <option value="transferred">Transferred</option>
                        </select>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="editSaveBtn">
                    <span class="spinner"></span>
                    <span class="btn-label">Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>


<!-- =========================================================
     TRANSFER MODAL
========================================================= -->
<div class="modal-backdrop" id="transferModal">
    <div class="modal">
        <form id="transferForm" autocomplete="off">
            <input type="hidden" name="student_id" id="transfer_student_id">

            <div class="modal-header">
                <h2>Transfer Student</h2>
                <button type="button" class="modal-close" onclick="closeModal('transferModal')">✕</button>
            </div>

            <div class="modal-body">

                <div style="padding:12px 14px;background:var(--cream);border-radius:8px;margin-bottom:16px;">
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;font-weight:700;">Student</div>
                    <div id="transfer_student_name" style="color:var(--navy);font-size:14px;font-weight:700;margin-top:3px;">—</div>
                    <div id="transfer_current_class" style="font-size:12px;color:var(--muted);margin-top:3px;">—</div>
                </div>

                <div class="form-group">
                    <label>New Class <span class="req">*</span></label>
                    <select name="class_id" id="transfer_class_id" class="form-control" required>
                        <option value="">— Select class —</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int)$c['class_id']; ?>">
                                <?php echo htmlspecialchars($c['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                        <input type="checkbox" name="mark_transferred" id="transfer_mark_status" value="1"
                               style="width:16px;height:16px;accent-color:var(--navy);">
                        Also mark status as <strong>Transferred</strong>
                    </label>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('transferModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-warning" id="transferSaveBtn">
                    <span class="spinner"></span>
                    <span class="btn-label">Transfer</span>
                </button>
            </div>
        </form>
    </div>
</div>


<div class="toast-wrap" id="toastWrap"></div>


<script>
/* =========================================================
   CLASSES map (for label building)
========================================================= */
const CLASSES = <?php echo json_encode(array_map(
    fn($c) => ['id' => (int)$c['class_id'], 'label' => $c['label']],
    $classes
)); ?>;

function classLabel(id) {
    const c = CLASSES.find(x => x.id == id);
    return c ? c.label : 'Not Assigned';
}


/* =========================================================
   MODAL HELPERS
========================================================= */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.classList.add('no-scroll');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}
document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) closeModal(bd.id);
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(bd => closeModal(bd.id));
    }
});


/* =========================================================
   TOASTS
========================================================= */
function showToast(message, type = 'success', timeout = 3200) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = message;
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s ease, transform .25s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}


/* =========================================================
   HELPERS
========================================================= */
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    if (isNaN(dt)) return '—';
    return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}


/* =========================================================
   VIEW
========================================================= */
function viewStudent(id) {
    const body = document.getElementById('viewModalBody');
    body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);">Loading…</div>';
    openModal('viewModal');

    fetch('get_student.php?id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed');
            const s = data.student;

            const photo = s.photo_url
                ? `<img src="${escHtml(s.photo_url)}" alt="" class="view-photo"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                   <div class="view-photo-placeholder" style="display:none;">${escHtml(s.full_name.charAt(0).toUpperCase())}</div>`
                : `<div class="view-photo-placeholder">${escHtml(s.full_name.charAt(0).toUpperCase())}</div>`;

            const statusCls = 'status status-' + escHtml((s.status || '').toLowerCase());

            body.innerHTML = `
                <div class="view-hero">
                    ${photo}
                    <div class="view-hero-info">
                        <h3>${escHtml(s.full_name)}</h3>
                        <div class="adm">${escHtml(s.admission_no)}</div>
                        <span class="${statusCls}">${escHtml(s.status.charAt(0).toUpperCase() + s.status.slice(1))}</span>
                    </div>
                </div>

                <div class="view-grid">
                    <div class="view-item">
                        <div class="k">Gender</div>
                        <div class="v">${escHtml(s.gender)}</div>
                    </div>
                    <div class="view-item">
                        <div class="k">Date of Birth</div>
                        <div class="v">${escHtml(s.date_of_birth_fmt || '—')}</div>
                    </div>
                    <div class="view-item full">
                        <div class="k">Class</div>
                        <div class="v">${escHtml(s.class_label)}</div>
                    </div>
                    <div class="view-item">
                        <div class="k">Admission Date</div>
                        <div class="v">${escHtml(s.admission_date_fmt || '—')}</div>
                    </div>
                    <div class="view-item">
                        <div class="k">Registered</div>
                        <div class="v">${escHtml(s.created_at_fmt || '—')}</div>
                    </div>
                </div>
            `;
        })
        .catch(err => {
            body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--red);">' + escHtml(err.message) + '</div>';
        });
}


/* =========================================================
   EDIT
========================================================= */
function editStudent(id) {
    /* Reset form, then fetch and fill */
    const form = document.getElementById('editForm');
    form.reset();
    document.getElementById('edit_student_id').value = id;

    openModal('editModal');

    fetch('get_student.php?id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed');
            const s = data.student;

            document.getElementById('edit_admission_no').value   = s.admission_no || '';
            document.getElementById('edit_full_name').value      = s.full_name    || '';
            document.getElementById('edit_gender').value         = s.gender       || 'Male';
            document.getElementById('edit_date_of_birth').value  = s.date_of_birth || '';
            document.getElementById('edit_class_id').value       = s.class_id     || '';
            document.getElementById('edit_admission_date').value = s.admission_date || '';
            document.getElementById('edit_status').value         = s.status       || 'active';
        })
        .catch(err => {
            closeModal('editModal');
            showToast(err.message, 'error');
        });
}

document.getElementById('editForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const btn = document.getElementById('editSaveBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    const fd = new FormData(this);

    fetch('update_student.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Update failed');

        updateRow(data.student);
        updateCard(data.student);
        showToast('Student updated successfully.', 'success');
        closeModal('editModal');
    })
    .catch(err => showToast(err.message, 'error'))
    .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
    });
});


/* =========================================================
   TRANSFER
========================================================= */
function transferStudent(id) {
    const form = document.getElementById('transferForm');
    form.reset();
    document.getElementById('transfer_student_id').value = id;

    /* Get current data from the table/card */
    const row  = document.querySelector(`tr[data-student-id="${id}"]`);
    const card = document.querySelector(`.student-card[data-student-id="${id}"]`);

    const name  = (row || card)?.querySelector('.student-name')?.textContent || '—';
    const klass = (row || card)?.querySelector('[data-class-label], .class-name')?.textContent?.trim() || '—';

    document.getElementById('transfer_student_name').textContent  = name;
    document.getElementById('transfer_current_class').textContent = 'Current class: ' + klass;

    openModal('transferModal');
}

document.getElementById('transferForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const btn = document.getElementById('transferSaveBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    const fd = new FormData(this);
    /* Checkbox: only send if checked */
    if (!document.getElementById('transfer_mark_status').checked) {
        fd.delete('mark_transferred');
    } else {
        fd.set('mark_transferred', '1');
    }

    fetch('transfer_student.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Transfer failed');

        updateRow(data.student);
        updateCard(data.student);
        showToast('Student transferred successfully.', 'success');
        closeModal('transferModal');
    })
    .catch(err => showToast(err.message, 'error'))
    .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
    });
});


/* =========================================================
   UPDATE ROW / CARD IN PLACE
========================================================= */
function updateRow(s) {
    const tr = document.querySelector(`tr[data-student-id="${s.student_id}"]`);
    if (!tr) return;

    /* Admission */
    const admCell = tr.querySelector('.admission');
    if (admCell) admCell.textContent = s.admission_no || '';

    /* Name */
    const nameCell = tr.querySelector('.student-name');
    if (nameCell) nameCell.textContent = s.full_name || '';

    /* Gender */
    const tds = tr.querySelectorAll('td');
    if (tds[3]) tds[3].textContent = s.gender || '';

    /* Date of birth */
    if (tds[4]) tds[4].querySelector('.muted-value').textContent = fmtDate(s.date_of_birth);

    /* Class */
    if (tds[5]) {
        const cName = s.class_name
            ? `${escHtml(s.class_name)}${s.stream ? `<span class="stream">- ${escHtml(s.stream)}</span>` : ''}`
            : '<span class="muted-value">Not Assigned</span>';
        tds[5].innerHTML = cName;
    }

    /* Status */
    const statusEl = tr.querySelector('[data-status]');
    if (statusEl) {
        statusEl.className = 'status status-' + (s.status || '').toLowerCase();
        statusEl.textContent = (s.status || '').charAt(0).toUpperCase() + (s.status || '').slice(1);
    }
}

function updateCard(s) {
    const card = document.querySelector(`.student-card[data-student-id="${s.student_id}"]`);
    if (!card) return;

    card.querySelector('.student-name').textContent = s.full_name || '';

    const statusEl = card.querySelector('[data-status]');
    if (statusEl) {
        statusEl.className = 'status status-' + (s.status || '').toLowerCase();
        statusEl.textContent = (s.status || '').charAt(0).toUpperCase() + (s.status || '').slice(1);
    }

    const metaItems = card.querySelectorAll('.meta-item .v');
    if (metaItems[0]) metaItems[0].textContent = s.admission_no || '';
    if (metaItems[1]) metaItems[1].textContent = s.gender || '';
    if (metaItems[2]) metaItems[2].textContent = fmtDate(s.date_of_birth);

    const classV = card.querySelector('[data-class-label]');
    if (classV) {
        classV.textContent = s.class_name
            ? s.class_name + (s.stream ? ' - ' + s.stream : '')
            : 'Not Assigned';
    }
}


/* =========================================================
   FILTER PANEL COLLAPSE
========================================================= */
(function () {
    const filterPanel  = document.getElementById('filterPanel');
    const filterToggle = document.getElementById('filterToggle');
    if (!filterPanel || !filterToggle) return;

    const mq              = window.matchMedia('(max-width: 800px)');
    const hasActiveFilter = <?php echo $has_filters ? 'true' : 'false'; ?>;

    function syncFilterState() {
        if (mq.matches) {
            filterPanel.classList.toggle('collapsed', !hasActiveFilter);
        } else {
            filterPanel.classList.remove('collapsed');
        }
    }
    syncFilterState();
    mq.addEventListener
        ? mq.addEventListener('change', syncFilterState)
        : mq.addListener(syncFilterState);

    filterToggle.addEventListener('click', () => {
        if (!mq.matches) return;
        filterPanel.classList.toggle('collapsed');
    });
})();
</script>

</body>
</html>