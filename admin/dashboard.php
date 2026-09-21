<?php

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

$user = currentUser();

/*
|--------------------------------------------------------------------------
| USER NAME / INITIALS
|--------------------------------------------------------------------------
*/

$first_name  = $user['first_name']  ?? '';
$middle_name = $user['middle_name'] ?? '';
$last_name   = $user['last_name']   ?? '';

$full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
if ($full_name === '') {
    $full_name = 'Administrator';
}

$initials = strtoupper(
    mb_substr($first_name, 0, 1) . mb_substr($last_name, 0, 1)
);
if ($initials === '') {
    $initials = 'AD';
}

$profile_pic = $user['profile_pic'] ?? '';
$user_role   = $user['role'] ?? 'admin';


/* =========================================================================
   HELPERS
   ========================================================================= */

function tableExists(mysqli $conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $res  = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $res && mysqli_num_rows($res) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $safe_t = mysqli_real_escape_string($conn, $table);
    $safe_c = mysqli_real_escape_string($conn, $column);

    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_t` LIKE '$safe_c'");
    return $res && mysqli_num_rows($res) > 0;
}

function scalar(mysqli $conn, string $sql): int
{
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_row($res);
    return (int) ($row[0] ?? 0);
}


/* =========================================================================
   STATISTICS
   ========================================================================= */

/* -------- Active Students -------- */
$total_students = 0;
if (tableExists($conn, 'students')) {
    $has_status = columnExists($conn, 'students', 'status');
    $total_students = $has_status
        ? scalar($conn, "SELECT COUNT(*) FROM students WHERE status = 'active'")
        : scalar($conn, "SELECT COUNT(*) FROM students");
}

/* -------- Active Teachers -------- */
$total_teachers = 0;
if (tableExists($conn, 'teachers') && tableExists($conn, 'users')) {
    $has_emp_status = columnExists($conn, 'teachers', 'employment_status');
    $total_teachers = $has_emp_status
        ? scalar($conn, "SELECT COUNT(*) FROM teachers WHERE employment_status = 'active'")
        : scalar($conn, "SELECT COUNT(*) FROM teachers");
} elseif (tableExists($conn, 'users')) {
    $total_teachers = scalar($conn, "SELECT COUNT(*) FROM users WHERE role = 'teacher'");
}

/* -------- Active Classes -------- */
$total_classes = 0;
if (tableExists($conn, 'classes')) {
    $has_status = columnExists($conn, 'classes', 'status');
    $total_classes = $has_status
        ? scalar($conn, "SELECT COUNT(*) FROM classes WHERE status = 'active'")
        : scalar($conn, "SELECT COUNT(*) FROM classes");
}

/* -------- Parents -------- */
$total_parents = 0;
if (tableExists($conn, 'users')) {
    $total_parents = scalar($conn, "SELECT COUNT(*) FROM users WHERE role = 'parent'");
}

/* -------- Active Academic Year -------- */
$active_year = 'Not Set';
if (tableExists($conn, 'academic_years')) {
    $has_status = columnExists($conn, 'academic_years', 'status');
    $has_year   = columnExists($conn, 'academic_years', 'year');

    if ($has_year && $has_status) {
        $res = mysqli_query(
            $conn,
            "SELECT year FROM academic_years
             WHERE status = 'active'
             ORDER BY year DESC
             LIMIT 1"
        );
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $active_year = $row['year'];
        }
    } elseif ($has_year) {
        $res = mysqli_query(
            $conn,
            "SELECT year FROM academic_years ORDER BY year DESC LIMIT 1"
        );
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $active_year = $row['year'];
        }
    }
}


/* =========================================================================
   CLASSES — pulled directly from `classes` table
   ========================================================================= */

$classes_list = [];
$max_class_count = 1;

if (tableExists($conn, 'classes')) {

    $has_students = tableExists($conn, 'students');
    $has_c_status = columnExists($conn, 'classes', 'status');
    $has_s_status = $has_students && columnExists($conn, 'students', 'status');

    $status_where = $has_c_status ? "WHERE c.status = 'active'" : "";

    if ($has_students) {

        /* Join students to count active students per class */
        $student_status = $has_s_status ? "AND s.status = 'active'" : "";

        $q = "
            SELECT
                c.class_id,
                c.class_name,
                c.class_level,
                c.stream,
                c.status,
                COUNT(s.student_id) AS student_count
            FROM classes c
            LEFT JOIN students s
                ON s.class_id = c.class_id
                $student_status
            $status_where
            GROUP BY c.class_id
            ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC
        ";

    } else {

        /* No students table — just list classes */
        $q = "
            SELECT
                class_id,
                class_name,
                class_level,
                stream,
                status,
                0 AS student_count
            FROM classes c
            $status_where
            ORDER BY class_level ASC, class_name ASC, stream ASC
        ";
    }

    $res = mysqli_query($conn, $q);

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $classes_list[] = $row;

            if ((int)$row['student_count'] > $max_class_count) {
                $max_class_count = (int)$row['student_count'];
            }
        }
    }
}


/* =========================================================================
   RECENT STUDENTS — last 5 added
   ========================================================================= */

$recent_students = [];

if (tableExists($conn, 'students')) {

    $cols = [];
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM students");
    if ($col_check) {
        while ($c = mysqli_fetch_assoc($col_check)) {
            $cols[] = $c['Field'];
        }
    }

    $name_col = in_array('full_name', $cols, true)   ? 'full_name'
              : (in_array('first_name', $cols, true) ? 'first_name'
              : 'student_id');

    $date_col = in_array('created_at', $cols, true)      ? 'created_at'
              : (in_array('admission_date', $cols, true) ? 'admission_date'
              : null);

    $order_by = $date_col ? "ORDER BY $date_col DESC" : "";

    $q = "SELECT student_id, $name_col AS student_name" .
         ($date_col ? ", $date_col AS created_date" : "") .
         " FROM students $order_by LIMIT 5";

    $res = mysqli_query($conn, $q);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $recent_students[] = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Admin Dashboard | PSRMS</title>

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
            --border: #e4e6eb;
            --success: #3e7655;
            --warning: #9a7422;
            --sidebar-w: 250px;
            --sidebar-collapsed: 78px;
            --topbar-h: 78px;
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

        .mobile-topbar .brand {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .mobile-topbar .brand span { color: var(--gold-light); }

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
            padding: calc(var(--topbar-h) + 30px) 30px 35px;
            transition: margin-left .25s ease;
        }

        .welcome { margin-bottom: 27px; }

        .welcome h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .welcome p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        /* =========================================================
           STAT CARDS
        ========================================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 22px;
            position: relative;
            overflow: hidden;
            transition: .25s ease;
            min-width: 0;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 10px 25px rgba(23,35,60,.06);
        }

        .stat-card::after {
            content: "";
            position: absolute;
            right: -25px;
            top: -25px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 1px solid rgba(201,162,39,.12);
            pointer-events: none;
        }

        .stat-label {
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        .stat-value {
            color: var(--navy);
            font-size: 30px;
            font-weight: 750;
            margin-top: 7px;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }

        .stat-description {
            color: #9299a6;
            font-size: 10.5px;
            margin-top: 4px;
        }

        .stat-accent {
            width: 25px;
            height: 2px;
            background: var(--gold);
            margin-top: 14px;
        }

        /* =========================================================
           DASHBOARD GRID
        ========================================================= */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1.35fr .65fr;
            gap: 18px;
        }

        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 21px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .panel-header h2 {
            color: var(--navy);
            font-size: 14px;
        }

        .panel-header span {
            color: var(--muted);
            font-size: 10px;
        }

        .panel-body { padding: 20px; }

        /* =========================================================
           CLASS ROWS
        ========================================================= */
        .class-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .class-row:last-child { border-bottom: none; }

        .class-name-wrap {
            flex: 1;
            min-width: 0;
        }

        .class-name {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 650;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .class-meta {
            color: var(--muted);
            font-size: 10px;
            margin-top: 2px;
        }

        .class-bar {
            width: 90px;
            height: 5px;
            background: #eef0f3;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .class-fill {
            height: 100%;
            background: var(--gold);
            border-radius: 10px;
            transition: width .4s ease;
        }

        .class-count {
            color: var(--navy);
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
            min-width: 60px;
            text-align: right;
        }

        /* =========================================================
           RECENT STUDENTS
        ========================================================= */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .activity-item:last-child { border-bottom: none; }

        .activity-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .activity-info { flex: 1; min-width: 0; }

        .activity-name {
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 650;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .activity-meta {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 2px;
        }

        .empty-note {
            text-align: center;
            padding: 22px 10px;
            color: var(--muted);
            font-size: 11.5px;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content {
                margin-left: var(--sidebar-collapsed);
            }
        }

        /* Tablet */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        /* Mobile */
        @media (max-width: 800px) {

            .mobile-topbar { display: flex; }

            .main-content {
                margin-left: 0;
                padding: 78px 16px 30px;
            }

            .welcome { margin-bottom: 20px; }
            .welcome h1 { font-size: 21px; }
            .welcome p  { font-size: 12px; line-height: 1.45; }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card { padding: 16px; }
            .stat-value { font-size: 22px; }
            .stat-label { font-size: 9px; letter-spacing: .7px; }
            .stat-description { font-size: 10px; }
            .stat-accent { margin-top: 10px; }

            .panel-header { padding: 14px 16px; }
            .panel-body { padding: 16px; }

            .class-row { padding: 12px 0; }
            .class-name { font-size: 12px; }
            .class-count { font-size: 11.5px; }
        }

        /* Small phone */
        @media (max-width: 550px) {

            .main-content { padding: 74px 14px 24px; }

            .welcome h1 { font-size: 19px; }
            .welcome p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }

            .stat-card {
                padding: 14px;
                border-radius: 9px;
            }

            .stat-value { font-size: 20px; }
            .stat-description { font-size: 9.5px; }

            /* Hide bars on small phones */
            .class-bar { display: none; }
            .class-count { min-width: auto; }
        }

        /* Very small phone */
        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr; }

            .stat-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 14px;
            }

            .stat-label { order: 1; margin: 0; font-size: 10px; }
            .stat-value { order: 2; margin: 0; font-size: 20px; }
            .stat-description { display: none; }
            .stat-accent { display: none; }

            .class-count { font-size: 11px; min-width: auto; }
        }

        /* Safe area */
        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
            }
        }

        /* Landscape phones */
        @media (max-height: 500px) and (max-width: 900px) {
            .main-content { padding-top: 74px; }
            .stats-grid { margin-bottom: 12px; }
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
<?php include '../includes/topbar.php'; ?>

<main class="main-content">

    <!-- WELCOME -->
    <section class="welcome">
        <h1>Good day, <?php echo htmlspecialchars($first_name ?: $full_name); ?>.</h1>
        <p>Here's an overview of your school's current records.</p>
    </section>

    <!-- STATISTICS -->
    <section class="stats-grid">

        <div class="stat-card">
            <div class="stat-label">Active Students</div>
            <div class="stat-value"><?php echo number_format($total_students); ?></div>
            <div class="stat-description">Currently enrolled students</div>
            <div class="stat-accent"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Active Teachers</div>
            <div class="stat-value"><?php echo number_format($total_teachers); ?></div>
            <div class="stat-description">Teaching staff</div>
            <div class="stat-accent"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Classes</div>
            <div class="stat-value"><?php echo number_format($total_classes); ?></div>
            <div class="stat-description">Active school classes</div>
            <div class="stat-accent"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Academic Year</div>
            <div class="stat-value" style="font-size:22px;">
                <?php echo htmlspecialchars($active_year); ?>
            </div>
            <div class="stat-description">Current active year</div>
            <div class="stat-accent"></div>
        </div>

    </section>

    <!-- CLASSES + RECENT STUDENTS -->
    <section class="dashboard-grid">

        <!-- CLASSES PANEL — real data from `classes` table -->
        <div class="panel">
            <div class="panel-header">
                <h2>Classes</h2>
                <span>
                    <?php
                    echo count($classes_list);
                    echo count($classes_list) === 1 ? ' class' : ' classes';
                    ?>
                </span>
            </div>

            <div class="panel-body">

                <?php if (empty($classes_list)): ?>

                    <div class="empty-note">No classes found.</div>

                <?php else: ?>

                    <?php foreach ($classes_list as $class):
                        $count = (int) $class['student_count'];
                        $pct   = $max_class_count > 0
                                 ? round(($count / $max_class_count) * 100)
                                 : 0;
                    ?>
                        <div class="class-row">
                            <div class="class-name-wrap">
                                <div class="class-name">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                    <?php if (!empty($class['stream'])): ?>
                                        — <?php echo htmlspecialchars($class['stream']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="class-meta">
                                    <?php
                                    echo 'Level ' . htmlspecialchars($class['class_level'] ?? '—');
                                    ?>
                                </div>
                            </div>

                            <div class="class-bar">
                                <div class="class-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>

                            <span class="class-count">
                                <?php echo $count; ?>
                                student<?php echo $count === 1 ? '' : 's'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>

        <!-- RECENT STUDENTS -->
        <div class="panel">
            <div class="panel-header">
                <h2>Recent Students</h2>
                <span>Last 5 added</span>
            </div>

            <div class="panel-body">

                <?php if (empty($recent_students)): ?>

                    <div class="empty-note">No recent students to display.</div>

                <?php else: ?>

                    <?php foreach ($recent_students as $s):
                        $name    = $s['student_name'] ?? '—';
                        $initial = strtoupper(mb_substr($name, 0, 1));
                    ?>
                        <div class="activity-item">
                            <div class="activity-avatar">
                                <?php echo htmlspecialchars($initial); ?>
                            </div>
                            <div class="activity-info">
                                <div class="activity-name">
                                    <?php echo htmlspecialchars($name); ?>
                                </div>
                                <div class="activity-meta">
                                    ID #<?php echo (int) $s['student_id']; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>

    </section>

</main>


<script>
/* =========================================================================
   MOBILE DRAWER SIDEBAR
   ========================================================================= */

const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    document.body.classList.add('sidebar-mobile-open');
    sidebarOverlay.classList.add('open');

    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar, #adminSidebar');
    if (sidebar) sidebar.classList.add('open');

    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}

function closeSidebar() {
    document.body.classList.remove('sidebar-mobile-open');
    sidebarOverlay.classList.remove('open');

    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar, #adminSidebar');
    if (sidebar) sidebar.classList.remove('open');

    if (hamburgerBtn) hamburgerBtn.classList.remove('active');

    document.body.classList.remove('no-scroll');
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

/* Escape closes drawer */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
});

/* Auto-close on resize to desktop */
window.addEventListener('resize', function () {
    if (window.innerWidth > 800) closeSidebar();
});

/* Close drawer when a sidebar link is tapped */
document.querySelectorAll('.sidebar .nav-item, .sidebar .logout-item').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 800) closeSidebar();
    });
});
</script>

</body>
</html>