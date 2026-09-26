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

function tableExists(mysqli $conn, string $t): bool {
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}


/* =========================================================================
   LOAD SUPER ADMIN
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT user_id, first_name, middle_name, last_name, email, profile_pic
     FROM users WHERE user_id = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$me) die('User not found.');

$my_name = trim($me['first_name'] . ' ' . $me['last_name']);


/* =========================================================================
   SYSTEM STATS
   ========================================================================= */

$stats = [
    'total_users'     => 0,
    'active_users'    => 0,
    'suspended_users' => 0,
    'admins'          => 0,
    'teachers'        => 0,
    'parents'         => 0,
    'students'        => 0,
    'classes'         => 0,
    'subjects'        => 0,
];

$r = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')    AS active,
        SUM(status = 'suspended') AS suspended,
        SUM(role = 'admin')       AS admin_count,
        SUM(role = 'teacher')     AS teacher_count,
        SUM(role = 'parent')      AS parent_count,
        SUM(role = 'super_admin') AS super_count
     FROM users"
);
if ($r && $row = mysqli_fetch_assoc($r)) {
    $stats['total_users']     = (int)$row['total'];
    $stats['active_users']    = (int)$row['active'];
    $stats['suspended_users'] = (int)$row['suspended'];
    $stats['admins']          = (int)$row['admin_count'];
    $stats['teachers']        = (int)$row['teacher_count'];
    $stats['parents']         = (int)$row['parent_count'];
}

if (tableExists($conn, 'students')) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM students");
    if ($r) $stats['students'] = (int)(mysqli_fetch_assoc($r)['c'] ?? 0);
}
if (tableExists($conn, 'classes')) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM classes WHERE status = 'active'");
    if ($r) $stats['classes'] = (int)(mysqli_fetch_assoc($r)['c'] ?? 0);
}
if (tableExists($conn, 'subjects')) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM subjects WHERE status = 'active'");
    if ($r) $stats['subjects'] = (int)(mysqli_fetch_assoc($r)['c'] ?? 0);
}


/* =========================================================================
   RECENT USERS
   ========================================================================= */

$recent_users = [];
$r = mysqli_query(
    $conn,
    "SELECT user_id, first_name, last_name, email, role, status, profile_pic, created_at
     FROM users
     ORDER BY user_id DESC
     LIMIT 8"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $recent_users[] = $row;
    }
}


/* =========================================================================
   ROLE BREAKDOWN
   ========================================================================= */

$role_breakdown = [
    ['label' => 'Admins',   'count' => $stats['admins'],   'icon' => 'fa-user-tie',       'color' => 'blue'],
    ['label' => 'Teachers', 'count' => $stats['teachers'], 'icon' => 'fa-chalkboard-user', 'color' => 'green'],
    ['label' => 'Parents',  'count' => $stats['parents'],  'icon' => 'fa-user-group',     'color' => 'orange'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Super Admin Dashboard | PSRMS</title>

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

        .super-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: linear-gradient(90deg, #c9a227 0%, #e2c65a 100%);
            color: #10182b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: 6px 12px;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(201,162,39,.25);
            white-space: nowrap;
        }

        /* STATS */
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
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: .15s ease;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 6px 18px rgba(23,35,60,.05);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .stat-icon.gold   { background: var(--gold-light); color: var(--navy); }
        .stat-icon.green  { background: var(--green-bg);   color: var(--green); }
        .stat-icon.orange { background: var(--orange-bg);  color: var(--orange); }
        .stat-icon.purple { background: var(--purple-bg);  color: var(--purple); }

        .stat-body { min-width: 0; }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 20px;
            font-weight: 750;
            margin-top: 3px;
            line-height: 1.15;
        }

        .stat-card .sub {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 2px;
        }

        /* 2-COLUMN */
        .grid-2col {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
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

        .panel-header .link {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--navy);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .panel-header .link:hover { color: var(--gold); }

        /* QUICK ACTIONS */
        .actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 18px;
        }

        .action-tile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            background: #fcfcfd;
            border: 1px solid var(--border);
            border-radius: 10px;
            text-decoration: none;
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 700;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .action-tile:hover {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 6px 14px rgba(23,35,60,.05);
            transform: translateY(-1px);
        }

        .action-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--gold-light);
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .action-icon.blue   { background: var(--blue-bg); color: var(--blue); }
        .action-icon.green  { background: var(--green-bg); color: var(--green); }
        .action-icon.orange { background: var(--orange-bg); color: var(--orange); }

        /* RECENT USERS */
        .user-list { padding: 0; }

        .user-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 22px;
            border-bottom: 1px solid #f0f1f3;
        }

        .user-row:last-child { border-bottom: none; }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-body { flex: 1; min-width: 0; }

        .user-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 2px;
            overflow-wrap: anywhere;
        }

        .user-email {
            color: var(--muted);
            font-size: 11px;
            overflow-wrap: anywhere;
        }

        .user-role-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            white-space: nowrap;
        }

        .role-super_admin { background: var(--gold); color: var(--navy); }
        .role-admin       { background: var(--blue-bg); color: var(--blue); }
        .role-teacher     { background: var(--green-bg); color: var(--green); }
        .role-parent      { background: var(--orange-bg); color: var(--orange); }

        /* ROLE BREAKDOWN */
        .breakdown-list { padding: 0; }

        .breakdown-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 22px;
            border-bottom: 1px solid #f0f1f3;
        }

        .breakdown-row:last-child { border-bottom: none; }

        .breakdown-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .breakdown-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .breakdown-icon.blue   { background: var(--blue-bg);   color: var(--blue); }
        .breakdown-icon.green  { background: var(--green-bg);  color: var(--green); }
        .breakdown-icon.orange { background: var(--orange-bg); color: var(--orange); }

        .breakdown-label {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
        }

        .breakdown-count {
            color: var(--navy);
            font-size: 16px;
            font-weight: 800;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        /* EMPTY */
        .empty-inline {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 12.5px;
        }

        /* =========================================================
           RESPONSIVE — TABLET (≤1100px)
        ========================================================= */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2col  { grid-template-columns: 1fr; }
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

            .super-badge { align-self: flex-start; }

            .stats-grid { gap: 10px; }
            .stat-card  { padding: 12px; gap: 10px; }
            .stat-icon  { width: 36px; height: 36px; font-size: 14px; }
            .stat-card .value { font-size: 17px; }
            .stat-card .label { font-size: 9px; }
            .stat-card .sub   { font-size: 10px; }

            .actions-grid {
                grid-template-columns: 1fr;
                padding: 14px;
                gap: 8px;
            }

            .panel-header { padding: 14px 16px; }
            .panel-header h2 { font-size: 13px; }

            .user-row,
            .breakdown-row { padding: 12px 16px; }
        }

        /* =========================================================
           RESPONSIVE — SMALL MOBILE (≤550px)
        ========================================================= */
        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .super-badge { font-size: 10px; padding: 5px 10px; }

            .stats-grid { gap: 8px; }
            .stat-card  {
                padding: 11px;
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            .stat-icon { width: 32px; height: 32px; font-size: 13px; }
            .stat-card .value { font-size: 16px; }

            .user-avatar { width: 34px; height: 34px; font-size: 12px; }
            .user-name   { font-size: 12.5px; }
            .user-email  { font-size: 10.5px; }

            .breakdown-icon { width: 34px; height: 34px; font-size: 13px; }
            .breakdown-label { font-size: 12.5px; }
            .breakdown-count { font-size: 15px; }

            .action-tile {
                padding: 12px;
                font-size: 12px;
                gap: 10px;
            }
            .action-icon { width: 34px; height: 34px; font-size: 14px; }
        }

        /* =========================================================
           SAFE AREA (notched phones)
        ========================================================= */
        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
            }
        }

        /* =========================================================
           REDUCED MOTION
        ========================================================= */
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
$topbar_title    = 'Super Admin';
$topbar_subtitle = 'System Control';
include '../includes/topbar.php';
?>

<?php include 'superadmin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-shield-halved"></i> Super Admin Dashboard</h1>
            <p>Welcome back, <?php echo e($my_name); ?>. Here's the system overview.</p>
        </div>
        <div>
            <span class="super-badge">
                <i class="fa-solid fa-crown"></i>
                Full System Access
            </span>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="stat-body">
                <div class="label">Total Users</div>
                <div class="value"><?php echo number_format($stats['total_users']); ?></div>
                <div class="sub"><?php echo number_format($stats['active_users']); ?> active</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
            <div class="stat-body">
                <div class="label">Students</div>
                <div class="value"><?php echo number_format($stats['students']); ?></div>
                <div class="sub">Enrolled</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold">
                <i class="fa-solid fa-chalkboard-user"></i>
            </div>
            <div class="stat-body">
                <div class="label">Teachers</div>
                <div class="value"><?php echo number_format($stats['teachers']); ?></div>
                <div class="sub"><?php echo number_format($stats['admins']); ?> admins</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fa-solid fa-school"></i>
            </div>
            <div class="stat-body">
                <div class="label">Classes / Subjects</div>
                <div class="value"><?php echo $stats['classes']; ?> / <?php echo $stats['subjects']; ?></div>
                <div class="sub">Active</div>
            </div>
        </div>
    </div>

    <!-- 2-COLUMN -->
    <div class="grid-2col">

        <!-- QUICK ACTIONS -->
        <div class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2>
            </div>
            <div class="actions-grid">

                <a href="users.php" class="action-tile">
                    <div class="action-icon blue">
                        <i class="fa-solid fa-users-gear"></i>
                    </div>
                    Manage Users
                </a>

                <a href="users.php?action=new" class="action-tile">
                    <div class="action-icon green">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    Add New User
                </a>

                <a href="audit_log.php" class="action-tile">
                    <div class="action-icon orange">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>
                    View Audit Log
                </a>

                <a href="backup.php" class="action-tile">
                    <div class="action-icon">
                        <i class="fa-solid fa-database"></i>
                    </div>
                    Backup Database
                </a>

                <a href="settings.php" class="action-tile">
                    <div class="action-icon blue">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    System Settings
                </a>

                <a href="../admin/dashboard.php" class="action-tile">
                    <div class="action-icon green">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </div>
                    Switch to Admin
                </a>

            </div>
        </div>

        <!-- ROLE BREAKDOWN -->
        <div class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-chart-pie"></i> Users by Role</h2>
            </div>
            <div class="breakdown-list">

                <?php foreach ($role_breakdown as $rb): ?>
                    <div class="breakdown-row">
                        <div class="breakdown-left">
                            <div class="breakdown-icon <?php echo e($rb['color']); ?>">
                                <i class="fa-solid <?php echo e($rb['icon']); ?>"></i>
                            </div>
                            <div class="breakdown-label"><?php echo e($rb['label']); ?></div>
                        </div>
                        <div class="breakdown-count"><?php echo number_format($rb['count']); ?></div>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>

    </div>

    <!-- RECENT USERS -->
    <div class="panel">
        <div class="panel-header">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Users</h2>
            <a href="users.php" class="link">
                View all <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($recent_users)): ?>

            <div class="empty-inline">No users yet.</div>

        <?php else: ?>

            <div class="user-list">
                <?php foreach ($recent_users as $u):
                    $initials = strtoupper(
                        mb_substr($u['first_name'], 0, 1) .
                        mb_substr($u['last_name'], 0, 1)
                    );
                    $rclass = 'role-' . strtolower($u['role']);
                ?>
                    <div class="user-row">
                        <div class="user-avatar">
                            <?php if (!empty($u['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $u['profile_pic'])): ?>
                                <img src="../uploads/users/<?php echo e($u['profile_pic']); ?>" alt="">
                            <?php else: ?>
                                <?php echo e($initials); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-body">
                            <div class="user-name">
                                <?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>
                            </div>
                            <div class="user-email"><?php echo e($u['email']); ?></div>
                        </div>
                        <span class="user-role-badge <?php echo e($rclass); ?>">
                            <?php echo e(str_replace('_', ' ', $u['role'])); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

</main>

<script>
/* =========================================================================
   MOBILE SIDEBAR — handled by includes/topbar.php
   Nothing else to add here.
   ========================================================================= */
</script>

</body>
</html>