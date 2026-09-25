<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('parent');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $t): bool
{
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}


/* =========================================================================
   LOAD PARENT
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id,
        u.first_name,
        u.middle_name,
        u.last_name,
        p.parent_id
     FROM users u
     INNER JOIN parents p ON p.user_id = u.user_id
     WHERE u.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$parent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$parent) {
    die('Parent profile not found.');
}

$parent_id   = (int) $parent['parent_id'];
$parent_name = trim(
    $parent['first_name'] . ' ' .
    ($parent['middle_name'] ? $parent['middle_name'] . ' ' : '') .
    $parent['last_name']
);


/* =========================================================================
   LOAD CHILDREN (from parent_children)
   ========================================================================= */

$children = [];

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        s.student_id,
        s.admission_no,
        s.full_name,
        s.gender,
        s.photo,
        s.status AS student_status,
        c.class_id,
        c.class_name,
        c.stream,
        c.class_level
     FROM parent_children pc
     INNER JOIN students s ON s.student_id = pc.student_id
     LEFT JOIN classes  c  ON c.class_id   = s.class_id
     WHERE pc.parent_id = ?
     ORDER BY c.class_level ASC, s.full_name ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $parent_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $children[] = $row;
}
mysqli_stmt_close($stmt);

$children_ids = array_map(fn($c) => (int)$c['student_id'], $children);


/* =========================================================================
   FILTERS
   ========================================================================= */

$selected_student_id = (int) ($_GET['student_id'] ?? 0);
$range               = $_GET['range'] ?? 'month';   // week | month | term | all

/* Validate the selected student belongs to this parent */
if ($selected_student_id > 0 && !in_array($selected_student_id, $children_ids, true)) {
    $selected_student_id = 0;
}

/* Auto-pick first child */
if ($selected_student_id === 0 && !empty($children)) {
    $selected_student_id = (int) $children[0]['student_id'];
}

/* Compute date range */
$from_date = null;
$to_date   = date('Y-m-d');

switch ($range) {
    case 'week':
        $from_date = date('Y-m-d', strtotime('monday this week'));
        break;
    case 'month':
        $from_date = date('Y-m-01');
        break;
    case 'term':
        $from_date = date('Y-m-d', strtotime('-3 months'));
        break;
    case 'all':
    default:
        $from_date = null;
        $range = 'all';
        break;
}


/* =========================================================================
   LOAD ATTENDANCE FOR SELECTED CHILD
   ========================================================================= */

$rows      = [];
$summary   = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
$total     = 0;
$by_month  = [];
$has_table = tableExists($conn, 'attendance');

if ($has_table && $selected_student_id > 0) {

    $sql = "
        SELECT attendance_date, status, remarks
        FROM attendance
        WHERE student_id = ?
    ";

    $params = [$selected_student_id];
    $types  = 'i';

    if ($from_date !== null) {
        $sql .= " AND attendance_date >= ? AND attendance_date <= ?";
        $params[] = $from_date;
        $params[] = $to_date;
        $types   .= 'ss';
    }

    $sql .= " ORDER BY attendance_date DESC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
            $st = $row['status'];

            if (isset($summary[$st])) {
                $summary[$st]++;
                $total++;
            }

            /* Group by month */
            $mkey = date('Y-m', strtotime($row['attendance_date']));
            if (!isset($by_month[$mkey])) {
                $by_month[$mkey] = [
                    'present' => 0, 'absent' => 0,
                    'late' => 0, 'excused' => 0,
                    'total' => 0,
                ];
            }
            if (isset($by_month[$mkey][$st])) {
                $by_month[$mkey][$st]++;
                $by_month[$mkey]['total']++;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

/* Attendance rate */
$attendance_rate = $total > 0
    ? round((($summary['present'] + $summary['late']) / $total) * 100)
    : 0;


/* =========================================================================
   SELECTED CHILD
   ========================================================================= */

$selected_child = null;
foreach ($children as $c) {
    if ((int)$c['student_id'] === $selected_student_id) {
        $selected_child = $c;
        break;
    }
}

$range_labels = [
    'week'  => 'This Week',
    'month' => 'This Month',
    'term'  => 'Last 3 Months',
    'all'   => 'All Time',
];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta name="theme-color" content="#17233c">

    <title>Attendance | PSRMS</title>

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

        /* =========================================================
           MOBILE TOPBAR
        ========================================================== */
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

        .mobile-topbar .brand {
            font-size: 14px; font-weight: 800; letter-spacing: .5px;
        }
        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px; height: 40px;
            border: none;
            background: rgba(255,255,255,.08);
            border-radius: 6px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 4px; cursor: pointer; padding: 0;
        }
        .hamburger span {
            display: block; width: 18px; height: 2px;
            background: var(--white); border-radius: 2px;
            transition: .2s ease;
        }
        .hamburger.active span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .hamburger.active span:nth-child(2) { opacity: 0; }
        .hamburger.active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050; opacity: 0;
            transition: opacity .25s ease;
        }
        .sidebar-overlay.open { display: block; opacity: 1; }

        /* =========================================================
           PAGE HEADER
        ========================================================== */
        .page-header { margin-bottom: 22px; }

        .page-header h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            line-height: 1.25;
        }

        .page-header p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        /* =========================================================
           FILTERS
        ========================================================== */
        .filters {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
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
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .btn-filter {
            height: 42px;
            padding: 0 22px;
            background: var(--navy);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-filter:hover { background: var(--navy-dark); }

        /* =========================================================
           CHILD PROFILE
        ========================================================== */
        .child-banner {
            background: var(--navy);
            color: var(--white);
            border-radius: 12px;
            padding: 20px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .child-banner-avatar {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: var(--gold);
            color: var(--navy);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
        }

        .child-banner-avatar img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }

        .child-banner-info {
            flex: 1;
            min-width: 0;
        }

        .child-banner-info h2 {
            font-size: 17px;
            font-weight: 750;
            margin-bottom: 4px;
            overflow-wrap: anywhere;
        }

        .child-banner-info p {
            font-size: 12.5px;
            opacity: .85;
            overflow-wrap: anywhere;
        }

        .child-banner-rate {
            text-align: right;
            flex-shrink: 0;
        }

        .child-banner-rate .num {
            font-size: 26px;
            font-weight: 800;
            color: var(--gold-light);
            line-height: 1;
        }

        .child-banner-rate .lbl {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            opacity: .7;
            margin-top: 4px;
        }

        /* =========================================================
           STATS
        ========================================================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 14px;
            text-align: center;
            border-top: 3px solid var(--muted);
        }

        .stat-card.present { border-top-color: var(--green); }
        .stat-card.absent  { border-top-color: var(--red); }
        .stat-card.late    { border-top-color: var(--orange); }
        .stat-card.excused { border-top-color: var(--blue); }

        .stat-card .num {
            font-size: 24px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-card.present .num { color: var(--green); }
        .stat-card.absent  .num { color: var(--red); }
        .stat-card.late    .num { color: var(--orange); }
        .stat-card.excused .num { color: var(--blue); }

        .stat-card .lbl {
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
        }

        /* =========================================================
           SECTION
        ========================================================== */
        .section-block {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            flex-wrap: wrap;
        }

        .section-header h2 {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 750;
        }

        .section-header span {
            color: var(--muted);
            font-size: 10.5px;
        }

        .section-body { padding: 8px 20px 16px; }

        /* =========================================================
           MONTHLY BREAKDOWN
        ========================================================== */
        .month-row {
            display: grid;
            grid-template-columns: 110px 1fr 60px;
            gap: 14px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .month-row:last-child { border-bottom: none; }

        .month-label {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--navy);
        }

        .month-bar {
            display: flex;
            height: 10px;
            border-radius: 20px;
            overflow: hidden;
            background: #eef1f5;
        }

        .month-bar .seg { height: 100%; transition: .2s ease; }
        .month-bar .seg.present { background: var(--green); }
        .month-bar .seg.late    { background: var(--orange); }
        .month-bar .seg.absent  { background: var(--red); }
        .month-bar .seg.excused { background: var(--blue); }

        .month-rate {
            font-size: 12.5px;
            font-weight: 750;
            color: var(--navy);
            text-align: right;
        }

        .month-meta {
            grid-column: 1 / -1;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 10.5px;
            color: var(--muted);
            margin-top: -4px;
        }

        .month-meta .dot {
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            margin-right: 4px;
            vertical-align: middle;
        }

        .month-meta .dot.present { background: var(--green); }
        .month-meta .dot.absent  { background: var(--red); }
        .month-meta .dot.late    { background: var(--orange); }
        .month-meta .dot.excused { background: var(--blue); }

        /* =========================================================
           RECENT RECORDS
        ========================================================== */
        .record-row {
            display: grid;
            grid-template-columns: 90px 1fr auto;
            gap: 14px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .record-row:last-child { border-bottom: none; }

        .record-date {
            font-size: 12px;
            font-weight: 700;
            color: var(--navy);
        }

        .record-date small {
            display: block;
            color: var(--muted);
            font-size: 10.5px;
            font-weight: 500;
            margin-top: 2px;
        }

        .record-remarks {
            color: var(--muted);
            font-size: 11.5px;
            overflow-wrap: anywhere;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .status-pill::before {
            content: "";
            width: 5px; height: 5px;
            border-radius: 50%;
        }

        .status-pill.present { background: var(--green-bg);  color: var(--green); }
        .status-pill.present::before { background: var(--green); }

        .status-pill.absent  { background: var(--red-bg);    color: var(--red); }
        .status-pill.absent::before { background: var(--red); }

        .status-pill.late    { background: var(--orange-bg); color: var(--orange); }
        .status-pill.late::before { background: var(--orange); }

        .status-pill.excused { background: var(--blue-bg);   color: var(--blue); }
        .status-pill.excused::before { background: var(--blue); }

        /* =========================================================
           EMPTY / INFO
        ========================================================== */
        .empty {
            text-align: center;
            padding: 50px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty-icon {
            width: 58px; height: 58px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 14px;
            margin-bottom: 5px;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================== */
        @media (max-width: 900px) {
            .mobile-topbar { display: flex; }

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 40px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 8px; }
            .stat-card { padding: 12px 8px; }
            .stat-card .num { font-size: 19px; }
            .stat-card .lbl { font-size: 9px; }
        }

        @media (max-width: 700px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 40px; }

            .page-header h1 { font-size: 21px; }
            .page-header p  { font-size: 12px; }

            .filters {
                grid-template-columns: 1fr;
                padding: 14px;
                gap: 10px;
            }

            .filter-control,
            .btn-filter {
                min-height: 46px;
                font-size: 15px;
            }

            .child-banner {
                flex-direction: column;
                align-items: flex-start;
                padding: 18px;
            }

            .child-banner-avatar { width: 54px; height: 54px; font-size: 21px; }

            .child-banner-rate {
                text-align: left;
                width: 100%;
                padding-top: 12px;
                border-top: 1px solid rgba(255,255,255,.12);
            }

            .child-banner-rate .num { font-size: 24px; }

            .stats-grid { grid-template-columns: repeat(2, 1fr); }

            .month-row {
                grid-template-columns: 90px 1fr 50px;
                gap: 10px;
            }

            .record-row {
                grid-template-columns: 1fr;
                gap: 6px;
                padding: 14px 0;
            }

            .record-date {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
            }

            .record-date small { margin-top: 0; }

            .record-status {
                display: flex;
                justify-content: flex-start;
            }
        }

        @media (max-width: 400px) {

            .page-header h1 { font-size: 19px; }
            .child-banner-avatar { width: 48px; height: 48px; font-size: 19px; }

            .month-row {
                grid-template-columns: 80px 1fr 46px;
                gap: 8px;
            }

            .month-label { font-size: 11.5px; }
            .month-rate  { font-size: 11.5px; }
        }

        @media (max-width: 900px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(40px, env(safe-area-inset-bottom));
                }
            }
        }

    </style>

</head>

<body>

<!-- =========================================================
     MOBILE TOPBAR
========================================================== -->
<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Parent</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Attendance';
$topbar_subtitle = 'My Children';
include '../includes/topbar.php';
?>

<?php include 'parent_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-header">
        <h1>Attendance</h1>
        <p>Track your child's attendance at school.</p>
    </div>

    <?php if (empty($children)): ?>

        <div class="empty">
            <div class="empty-icon">📅</div>
            <h3>No children linked yet</h3>
            <p>Please contact the school administration to link your children to your account.</p>
        </div>

    <?php elseif (!$has_table): ?>

        <div class="empty">
            <div class="empty-icon">📅</div>
            <h3>Attendance not yet recorded</h3>
            <p>The school has not started recording attendance.</p>
        </div>

    <?php else: ?>

        <!-- FILTERS -->
        <form method="GET" action="" class="filters">

            <div class="filter-group">
                <label for="student_id">Child</label>
                <select
                    name="student_id"
                    id="student_id"
                    class="filter-control"
                    onchange="this.form.submit()"
                >
                    <?php foreach ($children as $c): ?>
                        <option
                            value="<?php echo (int)$c['student_id']; ?>"
                            <?php echo $selected_student_id === (int)$c['student_id'] ? 'selected' : ''; ?>
                        >
                            <?php echo e($c['full_name']); ?>
                            <?php if (!empty($c['class_name'])): ?>
                                — <?php echo e($c['class_name']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="range">Period</label>
                <select name="range" id="range" class="filter-control">
                    <?php foreach ($range_labels as $key => $label): ?>
                        <option
                            value="<?php echo e($key); ?>"
                            <?php echo $range === $key ? 'selected' : ''; ?>
                        >
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-filter">Apply</button>

        </form>


        <!-- CHILD BANNER -->
        <?php if ($selected_child): ?>
            <?php
                $initial = strtoupper(mb_substr($selected_child['full_name'], 0, 1));
            ?>
            <div class="child-banner">

                <div class="child-banner-avatar">
                    <?php if (!empty($selected_child['photo'])): ?>
                        <img
                            src="../uploads/students/<?php echo e($selected_child['photo']); ?>"
                            alt="<?php echo e($selected_child['full_name']); ?>"
                        >
                    <?php else: ?>
                        <?php echo e($initial); ?>
                    <?php endif; ?>
                </div>

                <div class="child-banner-info">
                    <h2><?php echo e($selected_child['full_name']); ?></h2>
                    <p>
                        <?php echo e($selected_child['admission_no']); ?>

                        <?php if (!empty($selected_child['class_name'])): ?>
                            · <?php echo e($selected_child['class_name']); ?>
                            <?php if (!empty($selected_child['stream'])): ?>
                                — <?php echo e($selected_child['stream']); ?>
                            <?php endif; ?>
                        <?php endif; ?>

                        · <?php echo e($range_labels[$range]); ?>
                    </p>
                </div>

                <div class="child-banner-rate">
                    <div class="num"><?php echo (int)$attendance_rate; ?>%</div>
                    <div class="lbl">Attendance</div>
                </div>

            </div>
        <?php endif; ?>


        <!-- STATS -->
        <div class="stats-grid">

            <div class="stat-card present">
                <div class="num"><?php echo $summary['present']; ?></div>
                <div class="lbl">Present</div>
            </div>

            <div class="stat-card absent">
                <div class="num"><?php echo $summary['absent']; ?></div>
                <div class="lbl">Absent</div>
            </div>

            <div class="stat-card late">
                <div class="num"><?php echo $summary['late']; ?></div>
                <div class="lbl">Late</div>
            </div>

            <div class="stat-card excused">
                <div class="num"><?php echo $summary['excused']; ?></div>
                <div class="lbl">Excused</div>
            </div>

        </div>


        <!-- MONTHLY BREAKDOWN -->
        <?php if (!empty($by_month)): ?>

            <section class="section-block">

                <div class="section-header">
                    <h2>Monthly Breakdown</h2>
                    <span><?php echo count($by_month); ?> month<?php echo count($by_month) === 1 ? '' : 's'; ?></span>
                </div>

                <div class="section-body">
                    <?php
                        /* Sort newest first */
                        krsort($by_month);

                        foreach ($by_month as $ym => $m):
                            $m_total = max(1, $m['total']);
                            $m_rate  = round((($m['present'] + $m['late']) / $m_total) * 100);
                    ?>
                        <div class="month-row">

                            <div class="month-label">
                                <?php echo e(date('M Y', strtotime($ym . '-01'))); ?>
                            </div>

                            <div class="month-bar">
                                <?php if ($m['present'] > 0): ?>
                                    <div class="seg present" style="width: <?php echo ($m['present'] / $m_total) * 100; ?>%"></div>
                                <?php endif; ?>
                                <?php if ($m['late'] > 0): ?>
                                    <div class="seg late" style="width: <?php echo ($m['late'] / $m_total) * 100; ?>%"></div>
                                <?php endif; ?>
                                <?php if ($m['excused'] > 0): ?>
                                    <div class="seg excused" style="width: <?php echo ($m['excused'] / $m_total) * 100; ?>%"></div>
                                <?php endif; ?>
                                <?php if ($m['absent'] > 0): ?>
                                    <div class="seg absent" style="width: <?php echo ($m['absent'] / $m_total) * 100; ?>%"></div>
                                <?php endif; ?>
                            </div>

                            <div class="month-rate">
                                <?php echo (int)$m_rate; ?>%
                            </div>

                            <div class="month-meta">
                                <span><span class="dot present"></span>Present <?php echo $m['present']; ?></span>
                                <span><span class="dot late"></span>Late <?php echo $m['late']; ?></span>
                                <span><span class="dot excused"></span>Excused <?php echo $m['excused']; ?></span>
                                <span><span class="dot absent"></span>Absent <?php echo $m['absent']; ?></span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            </section>

        <?php endif; ?>


        <!-- RECENT RECORDS -->
        <?php if (!empty($rows)): ?>

            <section class="section-block">

                <div class="section-header">
                    <h2>Recent Records</h2>
                    <span>Last <?php echo min(15, count($rows)); ?> of <?php echo count($rows); ?></span>
                </div>

                <div class="section-body">
                    <?php foreach (array_slice($rows, 0, 15) as $r):
                        $ts = strtotime($r['attendance_date']);
                        $st = $r['status'];
                    ?>
                        <div class="record-row">

                            <div class="record-date">
                                <?php echo e(date('M j, Y', $ts)); ?>
                                <small><?php echo e(date('D', $ts)); ?></small>
                            </div>

                            <div class="record-remarks">
                                <?php if (!empty($r['remarks'])): ?>
                                    <?php echo e($r['remarks']); ?>
                                <?php else: ?>
                                    <span style="opacity:.6;">—</span>
                                <?php endif; ?>
                            </div>

                            <div class="record-status">
                                <span class="status-pill <?php echo e($st); ?>">
                                    <?php echo e($st); ?>
                                </span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            </section>

        <?php else: ?>

            <div class="empty">
                <div class="empty-icon">📭</div>
                <h3>No records for this period</h3>
                <p>Try a different period from the filter above.</p>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</main>


<script>
/* =========================================================================
   MOBILE SIDEBAR
   ========================================================================= */

const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');
    const s = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (s) s.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}
function closeSidebar() {
    sidebarOverlay.classList.remove('open');
    const s = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (s) s.classList.remove('open');
    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
    document.body.classList.remove('no-scroll');
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', () => {
        if (sidebarOverlay.classList.contains('open')) closeSidebar();
        else openSidebar();
    });
}
if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });
</script>

</body>

</html>
