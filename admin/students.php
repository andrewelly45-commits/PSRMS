<?php

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
    "SELECT class_id, class_name, stream
     FROM classes
     ORDER BY class_name ASC, stream ASC"
);

if ($class_result) {
    while ($row = mysqli_fetch_assoc($class_result)) {
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
        s.registration_no,
        s.admission_no,
        s.full_name,
        s.gender,
        s.date_of_birth,
        s.class_id,
        s.admission_date,
        s.photo,
        s.status,
        c.class_name,
        c.stream
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE 1=1
";

$params = [];
$types  = '';

if ($search !== '') {
    $sql .= "
        AND (
            s.full_name LIKE ?
            OR s.registration_no LIKE ?
            OR s.admission_no LIKE ?
        )
    ";
    $val      = '%' . $search . '%';
    $params[] = $val;
    $params[] = $val;
    $params[] = $val;
    $types   .= 'sss';
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
            --sidebar-w: 255px;
            --sidebar-collapsed: 78px;
            --topbar-h: 78px;
        }

        html, body { -webkit-text-size-adjust: 100%; }

        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
        }

        /* =================================================
           MAIN CONTENT
        ================================================= */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
            overflow-x: hidden;
        }

        /* Desktop collapsed state */
        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content {
                margin-left: var(--sidebar-collapsed);
            }
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

        .add-button:hover {
            background: var(--navy-dark);
            transform: translateY(-1px);
        }

        .add-button:active { transform: scale(.98); }

        .add-icon {
            color: var(--gold-light);
            font-size: 16px;
            line-height: 1;
        }

        /* =================================================
           STATISTICS
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
           FILTER PANEL
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

        .filter-group {
            min-width: 0;
        }

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

        select.filter-control {
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
        .filter-button:active { transform: scale(.98); }

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

        .reset-button:hover {
            color: var(--navy);
            border-color: #c8ccd3;
        }

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

        .results-bar h2 {
            color: var(--navy);
            font-size: 15px;
        }

        .results-count {
            color: var(--muted);
            font-size: 11.5px;
        }

        /* =================================================
           TABLE (desktop)
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

        .student-id {
            color: var(--muted);
            font-size: 10px;
            margin-top: 2px;
        }

        .registration {
            color: var(--navy);
            font-weight: 600;
        }

        .admission { color: var(--muted); }

        .class-name {
            color: var(--text);
            font-weight: 600;
        }

        .stream {
            color: var(--muted);
            font-size: 10px;
            margin-left: 3px;
        }

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

        .status-active {
            color: var(--green);
            background: #eef6f0;
        }
        .status-active::before { background: var(--green); }

        .status-inactive {
            color: var(--orange);
            background: #faf5e8;
        }
        .status-inactive::before { background: var(--orange); }

        .status-graduated {
            color: var(--navy);
            background: #eef0f5;
        }
        .status-graduated::before { background: var(--navy); }

        .status-transferred {
            color: var(--red);
            background: #faf0f0;
        }
        .status-transferred::before { background: var(--red); }

        /* =================================================
           ACTIONS
        ================================================= */
        .actions {
            display: flex;
            gap: 6px;
        }

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
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .action-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .action-btn:active { transform: scale(.95); }

        /* =================================================
           MOBILE CARD LIST
        ================================================= */
        .card-list { display: none; }

        .student-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            transition: .2s ease;
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

        .meta-item {
            min-width: 0;
        }

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
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px solid #f0f1f3;
        }

        .student-card-actions .action-btn {
            min-height: 40px;
            font-size: 12.5px;
            justify-content: center;
        }

        /* =================================================
           EMPTY STATE
        ================================================= */
        .empty-state {
            padding: 55px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
        }

        .empty-state h3 {
            color: var(--navy);
            font-size: 14.5px;
            margin-bottom: 5px;
        }

        .empty-state p {
            color: var(--muted);
            font-size: 12px;
        }

        /* =================================================
           RESPONSIVE — TABLET / LAPTOP
        ================================================= */
        @media (max-width: 1050px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

            .filter-button,
            .reset-button {
                width: auto;
            }
        }

        /* =================================================
           RESPONSIVE — MOBILE DRAWER MODE
        ================================================= */
        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }
            body.sidebar-collapsed .topbar        { left: 0; }

            /* Page header */
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

            /* Stats */
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card { padding: 15px; }
            .stat-value { font-size: 22px; }
            .stat-label { font-size: 9px; }

            /* Filters */
            .filter-toggle { display: flex; }

            .filter-panel {
                padding: 14px;
                margin-bottom: 14px;
            }

            .filter-panel.collapsed .filter-form {
                display: none;
            }

            .filter-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .filter-group label {
                font-size: 11px;
                margin-bottom: 6px;
            }

            .filter-control {
                height: 46px;
                font-size: 14px;
                border-radius: 9px;
            }

            .filter-button,
            .reset-button {
                width: 100%;
                height: 46px;
                font-size: 13.5px;
                border-radius: 9px;
            }

            /* Results bar */
            .results-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
                margin-bottom: 12px;
            }

            .results-bar h2 { font-size: 14px; }
            .results-count { font-size: 11px; }

            /* Table → Cards */
            .table-panel .table-wrapper { display: none; }

            .card-list {
                display: block;
                padding: 12px;
            }

            .student-card {
                padding: 14px;
                margin-bottom: 10px;
                border-radius: 12px;
            }

            .student-card:active {
                background: #fdfcf8;
            }

            .student-card-top {
                gap: 11px;
                padding-bottom: 12px;
                margin-bottom: 12px;
            }

            .student-card-top .student-name { font-size: 13.5px; }
            .student-card-top .student-id   { font-size: 10px; }

            .student-card-meta {
                grid-template-columns: 1fr 1fr;
                gap: 10px 12px;
                margin-bottom: 12px;
            }

            .meta-item .k { font-size: 9px; }
            .meta-item .v { font-size: 12.5px; }

            .student-card-actions {
                gap: 8px;
                padding-top: 12px;
            }

            .student-card-actions .action-btn {
                min-height: 42px;
                font-size: 12.5px;
            }

            /* Bigger avatars in cards */
            .student-card .student-photo,
            .student-card .student-placeholder {
                width: 42px;
                height: 42px;
                font-size: 13px;
            }
        }

        /* =================================================
           RESPONSIVE — SMALL PHONES
        ================================================= */
        @media (max-width: 550px) {

            :root { --topbar-h: 66px; }

            .main-content {
                padding: calc(var(--topbar-h) + 16px) 14px 24px;
            }

            .page-heading h1 { font-size: 19px; }
            .page-heading p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }

            .stat-card {
                padding: 13px;
                border-radius: 9px;
            }

            .stat-value { font-size: 20px; }
            .stat-label { font-size: 8.5px; letter-spacing: .7px; }
            .stat-line  { margin-top: 9px; }

            .filter-panel { padding: 12px; }

            .student-card-meta {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .student-card-actions {
                grid-template-columns: 1fr 1fr;
            }

            .student-card-actions .action-btn {
                min-height: 40px;
                font-size: 12px;
            }
        }

        /* =================================================
           RESPONSIVE — VERY SMALL PHONES
        ================================================= */
        @media (max-width: 400px) {

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .stat-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 14px;
            }

            .stat-label { order: 1; margin: 0; }
            .stat-value { order: 2; margin: 0; font-size: 20px; }
            .stat-line  { display: none; }

            .page-heading h1 { font-size: 18px; }
            .add-button      { font-size: 12.5px; }

            .student-card { padding: 12px; }
        }

        /* =================================================
           SAFE AREA (iPhone notch) — MOBILE ONLY
        ================================================= */
        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
            }
        }

        /* =================================================
           LANDSCAPE PHONES
        ================================================= */
        @media (max-height: 500px) and (max-width: 900px) {
            .main-content {
                padding-top: calc(var(--topbar-h) + 12px);
            }

            .stats-grid { margin-bottom: 12px; }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<main class="main-content">

    <!-- PAGE HEADER -->
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

    <!-- STATISTICS -->
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
                <input
                    type="text"
                    name="search"
                    class="filter-control"
                    placeholder="Name, reg. or admission no..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label>Class</label>
                <select name="class_id" class="filter-control">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option
                            value="<?php echo (int) $class['class_id']; ?>"
                            <?php echo ((string) $class_id === (string) $class['class_id']) ? 'selected' : ''; ?>
                        >
                            <?php
                            echo htmlspecialchars($class['class_name']);
                            if (!empty($class['stream'])) {
                                echo ' - ' . htmlspecialchars($class['stream']);
                            }
                            ?>
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
                            <th>Registration No.</th>
                            <th>Admission No.</th>
                            <th>Gender</th>
                            <th>Class</th>
                            <th>Admission Date</th>
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
                    ?>
                        <tr>
                            <td><?php echo $number++; ?></td>

                            <td>
                                <div class="student-cell">
                                    <?php if (!empty($student['photo'])): ?>
                                        <img
                                            src="../uploads/students/<?php echo htmlspecialchars($student['photo']); ?>"
                                            alt="<?php echo htmlspecialchars($name); ?>"
                                            class="student-photo"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="student-placeholder"><?php echo $initial; ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="student-name"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="student-id">ID: <?php echo (int) $student['student_id']; ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="registration">
                                    <?php echo htmlspecialchars($student['registration_no'] ?: '—'); ?>
                                </span>
                            </td>

                            <td>
                                <span class="admission">
                                    <?php echo htmlspecialchars($student['admission_no']); ?>
                                </span>
                            </td>

                            <td><?php echo htmlspecialchars($student['gender']); ?></td>

                            <td>
                                <?php if (!empty($student['class_name'])): ?>
                                    <span class="class-name"><?php echo htmlspecialchars($student['class_name']); ?></span>
                                    <?php if (!empty($student['stream'])): ?>
                                        <span class="stream">- <?php echo htmlspecialchars($student['stream']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="admission">Not Assigned</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                if (!empty($student['admission_date'])) {
                                    echo date('d M Y', strtotime($student['admission_date']));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>

                            <td>
                                <span class="status status-<?php echo htmlspecialchars($status_lower); ?>">
                                    <?php echo ucfirst(htmlspecialchars($status_lower)); ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <a href="view_student.php?id=<?php echo (int) $student['student_id']; ?>"
                                       class="action-btn">View</a>
                                    <a href="edit_student.php?id=<?php echo (int) $student['student_id']; ?>"
                                       class="action-btn">Edit</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php
                $n = 1;
                foreach ($students as $student):
                    $name         = $student['full_name'];
                    $initial      = strtoupper(mb_substr($name, 0, 1));
                    $status_lower = strtolower($student['status'] ?? '');
                ?>
                    <div class="student-card">

                        <div class="student-card-top">
                            <?php if (!empty($student['photo'])): ?>
                                <img
                                    src="../uploads/students/<?php echo htmlspecialchars($student['photo']); ?>"
                                    alt="<?php echo htmlspecialchars($name); ?>"
                                    class="student-photo"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="student-placeholder"><?php echo $initial; ?></div>
                            <?php endif; ?>
                            <div style="min-width:0;flex:1;">
                                <div class="student-name"><?php echo htmlspecialchars($name); ?></div>
                                <div class="student-id">ID: <?php echo (int) $student['student_id']; ?></div>
                            </div>
                            <span class="status status-<?php echo htmlspecialchars($status_lower); ?>">
                                <?php echo ucfirst(htmlspecialchars($status_lower)); ?>
                            </span>
                        </div>

                        <div class="student-card-meta">
                            <div class="meta-item">
                                <span class="k">Reg. No.</span>
                                <span class="v"><?php echo htmlspecialchars($student['registration_no'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Admission No.</span>
                                <span class="v"><?php echo htmlspecialchars($student['admission_no']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Gender</span>
                                <span class="v"><?php echo htmlspecialchars($student['gender']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Class</span>
                                <span class="v">
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
                            <div class="meta-item">
                                <span class="k">Admission Date</span>
                                <span class="v">
                                    <?php
                                    echo !empty($student['admission_date'])
                                        ? date('d M Y', strtotime($student['admission_date']))
                                        : '—';
                                    ?>
                                </span>
                            </div>
                        </div>

                        <div class="student-card-actions">
                            <a href="view_student.php?id=<?php echo (int) $student['student_id']; ?>"
                               class="action-btn">View</a>
                            <a href="edit_student.php?id=<?php echo (int) $student['student_id']; ?>"
                               class="action-btn">Edit</a>
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

<script>
    /* =========================================================
       MOBILE FILTER PANEL — collapse/expand with breakpoint sync
    ========================================================= */
    (function () {
        const filterPanel  = document.getElementById('filterPanel');
        const filterToggle = document.getElementById('filterToggle');

        if (!filterPanel || !filterToggle) return;

        const mq              = window.matchMedia('(max-width: 800px)');
        const hasActiveFilter = <?php echo $has_filters ? 'true' : 'false'; ?>;

        function syncFilterState() {
            if (mq.matches) {
                // Mobile: collapse unless user is actively filtering
                filterPanel.classList.toggle('collapsed', !hasActiveFilter);
            } else {
                // Desktop: always expanded
                filterPanel.classList.remove('collapsed');
            }
        }

        // Initial state
        syncFilterState();

        // React to breakpoint changes
        if (mq.addEventListener) {
            mq.addEventListener('change', syncFilterState);
        } else {
            // Older Safari fallback
            mq.addListener(syncFilterState);
        }

        // Toggle on tap (mobile only)
        filterToggle.addEventListener('click', () => {
            if (!mq.matches) return;
            filterPanel.classList.toggle('collapsed');
        });
    })();
</script>

</body>
</html>