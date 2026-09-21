<?php

require_once '../auth/auth_check.php';

requireRole('admin');

require_once '../includes/db.php';

$user = currentUser();

/*
|--------------------------------------------------------------------------
| BUILD FULL NAME (first + middle + last)
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


/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$total_students = 0;
$total_teachers = 0;
$total_classes  = 0;
$total_parents  = 0;
$active_year    = 'Not Set';


/* Students */
$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM students WHERE status = 'active'"
);

if ($result) {
    $total_students = (int) mysqli_fetch_assoc($result)['total'];
}


/* Teachers (from users table where role = 'teacher') */
$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'teacher' AND status = 'active'"
);

if ($result) {
    $total_teachers = (int) mysqli_fetch_assoc($result)['total'];
}


/* Classes */
$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM classes WHERE status = 'active'"
);

if ($result) {
    $total_classes = (int) mysqli_fetch_assoc($result)['total'];
}


/* Parents */
$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'parent' AND status = 'active'"
);

if ($result) {
    $total_parents = (int) mysqli_fetch_assoc($result)['total'];
}


/* Active Academic Year */
$result = mysqli_query(
    $conn,
    "SELECT year FROM academic_years WHERE status = 'active' ORDER BY year DESC LIMIT 1"
);

if ($result && mysqli_num_rows($result) > 0) {
    $active_year = mysqli_fetch_assoc($result)['year'];
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

       

        /* =================================
           MAIN CONTENT
        ================================= */
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
        }

        .welcome p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        /* =================================
           STAT CARDS
        ================================= */
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

        /* =================================
           DASHBOARD GRID
        ================================= */
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

        /* =================================
           CLASS LEVELS
        ================================= */
        .level-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .level-row:last-child { border-bottom: none; }

        .level-name {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .level-bar {
            width: 90px;
            height: 5px;
            background: #eef0f3;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .level-fill {
            height: 100%;
            background: var(--gold);
            border-radius: 10px;
            transition: width .4s ease;
        }

        .level-count {
            color: var(--navy);
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
            min-width: 70px;
            text-align: right;
        }

        /* =================================
           QUICK ACTIONS
        ================================= */
        .quick-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .quick-action:last-child { border-bottom: none; }

        .action-info {
            min-width: 0;
            flex: 1;
        }

        .action-info strong {
            display: block;
            color: var(--navy);
            font-size: 12.5px;
        }

        .action-info span {
            display: block;
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 3px;
        }

        .action-link {
            color: var(--gold);
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
            padding: 6px 4px;
            -webkit-tap-highlight-color: transparent;
        }

        .action-link:hover { color: var(--navy); }

        /* =================================
           TABLET
        ================================= */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        /* =================================
           MOBILE — drawer sidebar
        ================================= */
        @media (max-width: 800px) {
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
                box-shadow: 5px 0 25px rgba(0,0,0,.25);
            }

            body.sidebar-mobile-open .sidebar {
                transform: translateX(0);
            }

            .topbar { left: 0; padding: 0 16px; }

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 22px) 16px 25px;
            }

            /* Cancel desktop collapsed state on mobile */
            body.sidebar-collapsed .sidebar { width: 280px; }
            body.sidebar-collapsed .topbar { left: 0; }
            body.sidebar-collapsed .main-content { margin-left: 0; }
            body.sidebar-collapsed .brand-text,
            body.sidebar-collapsed .nav-section,
            body.sidebar-collapsed .nav-item span:not(.nav-icon),
            body.sidebar-collapsed .logout-item span:not(.nav-icon) {
                display: block;
            }
        }

        /* =================================
           SMALL PHONES
        ================================= */
        @media (max-width: 550px) {
            :root { --topbar-h: 66px; }

            .page-title { font-size: 14px; }
            .page-subtitle { font-size: 9.5px; }

            .user-info { display: none; }

            .profile-image,
            .profile-placeholder { width: 36px; height: 36px; font-size: 12px; }

            .welcome h1 { font-size: 20px; }
            .welcome p { font-size: 12px; }

            .stat-card { padding: 18px; }
            .stat-value { font-size: 26px; }

            .panel-header { padding: 15px 16px; }
            .panel-body { padding: 16px; }

            .level-bar { display: none; }
            .level-count { min-width: auto; }

            .stats-grid { gap: 12px; }
            .dashboard-grid { gap: 14px; }
        }

        /* =================================
           SAFE AREA (iPhone notch)
        ================================= */
        @supports (padding: max(0px)) {
            .topbar {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
            }

            .main-content {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
            }

            .sidebar-nav {
                padding-bottom: max(20px, env(safe-area-inset-bottom));
            }
        }
    </style>
</head>
<body>

<!-- SIDEBAR OVERLAY (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'admin_sidebar.php'; ?>

<?php include 'admin_header.php'; ?>

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
            <div class="stat-value"><?php echo htmlspecialchars($active_year); ?></div>
            <div class="stat-description">Current active year</div>
            <div class="stat-accent"></div>
        </div>

    </section>

    <!-- LOWER DASHBOARD -->
    <section class="dashboard-grid">

        <!-- SCHOOL LEVELS -->
        <div class="panel">
            <div class="panel-header">
                <h2>School Classes</h2>
                <span>Kindergarten → Standard 7</span>
            </div>

            <div class="panel-body">
                <?php
                $levels = [
                    'Kindergarten 1',
                    'Kindergarten 2',
                    'Standard 1',
                    'Standard 2',
                    'Standard 3',
                    'Standard 4',
                    'Standard 5',
                    'Standard 6',
                    'Standard 7',
                ];

                /* First pass — collect counts so we can scale bars */
                $counts = [];
                $max    = 1;

                foreach ($levels as $level) {
                    $safe = mysqli_real_escape_string($conn, $level);

                    $q = "
                        SELECT COUNT(*) AS total
                        FROM students s
                        INNER JOIN classes c ON s.class_id = c.class_id
                        WHERE c.class_name = '$safe'
                        AND s.status = 'active'
                    ";

                    $r = mysqli_query($conn, $q);

                    $c = $r ? (int) mysqli_fetch_assoc($r)['total'] : 0;

                    $counts[$level] = $c;

                    if ($c > $max) { $max = $c; }
                }

                /* Second pass — render rows with proportional bars */
                foreach ($levels as $level):
                    $count = $counts[$level];
                    $pct   = $max > 0 ? round(($count / $max) * 100) : 0;
                ?>
                    <div class="level-row">
                        <span class="level-name"><?php echo htmlspecialchars($level); ?></span>
                        <div class="level-bar">
                            <div class="level-fill" style="width: <?php echo $pct; ?>%;"></div>
                        </div>
                        <span class="level-count">
                            <?php echo $count; ?> student<?php echo $count === 1 ? '' : 's'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="panel">
            <div class="panel-header">
                <h2>Quick Actions</h2>
                <span>Administration</span>
            </div>

            <div class="panel-body">

                <div class="quick-action">
                    <div class="action-info">
                        <strong>Add Teacher</strong>
                        <span>Register teaching staff</span>
                    </div>
                    <a href="teachers.php" class="action-link">Open →</a>
                </div>

                <div class="quick-action">
                    <div class="action-info">
                        <strong>Manage Classes</strong>
                        <span>Classes &amp; class teachers</span>
                    </div>
                    <a href="classes.php" class="action-link">Open →</a>
                </div>

                <div class="quick-action">
                    <div class="action-info">
                        <strong>Manage Subjects</strong>
                        <span>School curriculum</span>
                    </div>
                    <a href="subjects.php" class="action-link">Open →</a>
                </div>

                <div class="quick-action">
                    <div class="action-info">
                        <strong>Academic Year</strong>
                        <span>Years &amp; terms</span>
                    </div>
                    <a href="academic_years.php" class="action-link">Open →</a>
                </div>

                <div class="quick-action">
                    <div class="action-info">
                        <strong>Parents</strong>
                        <span>Registered parent accounts</span>
                    </div>
                    <a href="parents.php" class="action-link">Open →</a>
                </div>

            </div>
        </div>

    </section>

</main>

<script>
    const body       = document.body;
    const overlay    = document.getElementById('sidebarOverlay');
    const toggleBtn  = document.getElementById('sidebarToggle');

    function isMobile() {
        return window.matchMedia('(max-width: 800px)').matches;
    }

    function toggleSidebar() {
        if (isMobile()) {
            body.classList.toggle('sidebar-mobile-open');
        } else {
            body.classList.toggle('sidebar-collapsed');
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            body.classList.remove('sidebar-mobile-open');
        });
    }

    // Close drawer when a nav link is tapped
    document.querySelectorAll('.sidebar .nav-item, .sidebar .logout-item').forEach(link => {
        link.addEventListener('click', () => {
            if (isMobile()) {
                body.classList.remove('sidebar-mobile-open');
            }
        });
    });

    // Close drawer on Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            body.classList.remove('sidebar-mobile-open');
        }
    });

    // Clean state when resizing
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            body.classList.remove('sidebar-mobile-open');
        }
    });
</script>

</body>
</html>