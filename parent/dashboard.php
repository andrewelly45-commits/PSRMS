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

$parent = null;

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.phone,
        u.gender,
        u.profile_pic,
        p.parent_id,
        p.occupation,
        p.address
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
   LOAD CHILDREN
   ========================================================================= */

$children = [];

if (tableExists($conn, 'student_parents')) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            s.student_id,
            s.admission_no,
            s.full_name,
            s.gender,
            s.date_of_birth,
            s.photo,
            s.status AS student_status,
            c.class_id,
            c.class_name,
            c.stream,
            c.class_level,
            sp.relationship,
            sp.is_primary_guardian
         FROM student_parents sp
         INNER JOIN students s ON s.student_id = sp.student_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         WHERE sp.parent_id = ?
         ORDER BY s.full_name ASC"
    );

    mysqli_stmt_bind_param($stmt, 'i', $parent_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $children[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   ATTENDANCE SUMMARY (optional)
   ========================================================================= */

$attendance_summary = [];

if (tableExists($conn, 'attendance') && !empty($children)) {

    $child_ids = array_column($children, 'student_id');
    $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
    $types = str_repeat('i', count($child_ids));

    $sql = "
        SELECT
            student_id,
            COUNT(*) AS total_days,
            SUM(status = 'present') AS present_days,
            SUM(status = 'absent')  AS absent_days,
            SUM(status = 'late')    AS late_days
        FROM attendance
        WHERE student_id IN ($placeholders)
        GROUP BY student_id
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$child_ids);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $attendance_summary[(int)$row['student_id']] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   RECENT RESULTS (optional)
   ========================================================================= */

$recent_results = [];

if (tableExists($conn, 'results') && !empty($children)) {

    $child_ids = array_column($children, 'student_id');
    $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
    $types = str_repeat('i', count($child_ids));

    $sql = "
        SELECT
            r.student_id,
            r.marks,
            r.grade,
            r.exam_name,
            r.created_at,
            s.subject_name
        FROM results r
        LEFT JOIN subjects s ON s.subject_id = r.subject_id
        WHERE r.student_id IN ($placeholders)
        ORDER BY r.created_at DESC
        LIMIT 6
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$child_ids);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $recent_results[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   UPCOMING EVENTS (optional, from public site table)
   ========================================================================= */

$upcoming_events = [];

if (tableExists($conn, 'events')) {
    $res = mysqli_query(
        $conn,
        "SELECT id, title, event_date, event_time, location
         FROM events
         WHERE event_date >= CURDATE()
         ORDER BY event_date ASC
         LIMIT 4"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $upcoming_events[] = $row;
        }
    }
}


/* =========================================================================
   RECENT NEWS (optional)
   ========================================================================= */

$recent_news = [];

if (tableExists($conn, 'news')) {
    $res = mysqli_query(
        $conn,
        "SELECT id, title, category, news_date, excerpt
         FROM news
         ORDER BY news_date DESC
         LIMIT 3"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $recent_news[] = $row;
        }
    }
}


/* =========================================================================
   STATS
   ========================================================================= */

$total_children     = count($children);
$primary_children   = 0;
foreach ($children as $c) {
    if (!empty($c['is_primary_guardian'])) $primary_children++;
}

$active_children = 0;
foreach ($children as $c) {
    if (strtolower($c['student_status'] ?? '') === 'active') $active_children++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Parent Dashboard | PSRMS</title>

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

        /* LAYOUT */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* WELCOME */
        .welcome {
            margin-bottom: 25px;
        }

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

        /* STATS */
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
            transition: .2s ease;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 8px 20px rgba(23,35,60,.05);
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

        /* SECTION */
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
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
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

        .section-body { padding: 22px; }

        .section-body.tight { padding: 12px 22px; }

        /* =========================================================
           CHILDREN CARDS
        ========================================================= */
        .children-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
        }

        .child-card {
            background: #fcfcfd;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            transition: .2s ease;
        }

        .child-card:hover {
            border-color: rgba(201,162,39,.5);
            box-shadow: 0 10px 25px rgba(23,35,60,.06);
            background: var(--white);
        }

        .child-top {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 14px;
            margin-bottom: 14px;
            border-bottom: 1px solid #f0f1f3;
        }

        .child-avatar,
        .child-avatar img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
            border: 2px solid var(--border);
        }

        .child-avatar {
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
        }

        .child-info { min-width: 0; flex: 1; }

        .child-name {
            color: var(--navy);
            font-size: 15px;
            font-weight: 750;
            margin-bottom: 3px;
            overflow-wrap: anywhere;
        }

        .child-adm {
            color: var(--muted);
            font-size: 11px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .child-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
        }

        .meta-item .k {
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
            overflow-wrap: anywhere;
        }

        /* STATUS PILL */
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
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        .status-active      { color: var(--green); background: var(--green-bg); }
        .status-active::before { background: var(--green); }

        .status-inactive    { color: var(--orange); background: var(--orange-bg); }
        .status-inactive::before { background: var(--orange); }

        .status-graduated   { color: var(--navy);   background: #eef0f5; }
        .status-graduated::before { background: var(--navy); }

        .status-transferred { color: var(--red);    background: var(--red-bg); }
        .status-transferred::before { background: var(--red); }

        /* =========================================================
           EMPTY
        ========================================================= */
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-size: 12.5px;
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
            font-size: 22px;
            font-weight: 800;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 14px;
            margin-bottom: 4px;
        }

        /* =========================================================
           LIST ROWS (events / news / results)
        ========================================================= */
        .list-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .list-row:last-child { border-bottom: none; }

        .list-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .list-icon.green  { background: var(--green-bg);  color: var(--green); }
        .list-icon.orange { background: var(--orange-bg); color: var(--orange); }
        .list-icon.gold   { background: var(--gold-light);color: var(--navy); }
        .list-icon.red    { background: var(--red-bg);    color: var(--red); }

        .list-body { flex: 1; min-width: 0; }

        .list-title {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 3px;
            overflow-wrap: anywhere;
        }

        .list-meta {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .list-sub {
            color: var(--muted);
            font-size: 11.5px;
            margin-top: 4px;
            line-height: 1.55;
            overflow-wrap: anywhere;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* 2-column layout for lower panels */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .welcome h1 { font-size: 21px; }
            .welcome p  { font-size: 12px; line-height: 1.45; }

            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card { padding: 14px; }
            .stat-card .value { font-size: 19px; }
            .stat-card .label { font-size: 9px; }

            .section-header { padding: 14px 18px; }
            .section-header h2 { font-size: 13px; }
            .section-body { padding: 18px; }
            .section-body.tight { padding: 8px 18px; }

            .children-grid { grid-template-columns: 1fr; gap: 12px; }

            .child-card { padding: 16px; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .welcome h1 { font-size: 19px; }
            .welcome p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 18px; }

            .section-header { padding: 12px 14px; }
            .section-body { padding: 14px; }
            .section-body.tight { padding: 6px 14px; }

            .child-meta { grid-template-columns: 1fr; gap: 8px; }

            .child-avatar,
            .child-avatar img { width: 48px; height: 48px; font-size: 18px; }
        }

        @media (max-width: 400px) {

            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stats-grid .stat-card:nth-child(3) { grid-column: 1 / -1; }
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

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Parent Dashboard';
$topbar_subtitle = 'My Children';
include '../includes/topbar.php';
?>

<?php include 'parent_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- WELCOME -->
    <section class="welcome">
        <h1>Welcome back, <?php echo e($parent['first_name']); ?>.</h1>
        <p>Here's an overview of your children and school updates.</p>
    </section>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Children</div>
            <div class="value"><?php echo number_format($total_children); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo number_format($active_children); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Primary Guardian</div>
            <div class="value"><?php echo number_format($primary_children); ?></div>
        </div>
    </div>

    <!-- CHILDREN -->
    <section class="section-block">
        <div class="section-header">
            <h2>My Children</h2>
            <span>
                <?php echo $total_children === 1 ? '1 child' : $total_children . ' children'; ?>
            </span>
        </div>

        <div class="section-body">

            <?php if (empty($children)): ?>

                <div class="empty">
                    <div class="empty-icon">👧</div>
                    <h3>No children linked yet</h3>
                    <p>Please contact the school administration to link your children to your account.</p>
                </div>

            <?php else: ?>

                <div class="children-grid">
                    <?php foreach ($children as $c):
                        $initial = strtoupper(mb_substr($c['full_name'], 0, 1));
                        $status  = strtolower($c['student_status'] ?? 'inactive');

                        $class_label = $c['class_name']
                            ? $c['class_name'] . ($c['stream'] ? ' — ' . $c['stream'] : '')
                            : 'Not Assigned';

                        $att = $attendance_summary[(int)$c['student_id']] ?? null;
                        $att_pct = null;
                        if ($att && (int)$att['total_days'] > 0) {
                            $att_pct = round(((int)$att['present_days'] / (int)$att['total_days']) * 100);
                        }
                    ?>
                        <div class="child-card">

                            <div class="child-top">
                                <?php if (!empty($c['photo'])): ?>
                                    <img src="../uploads/students/<?php echo e($c['photo']); ?>"
                                         alt="<?php echo e($c['full_name']); ?>"
                                         class="child-avatar">
                                <?php else: ?>
                                    <div class="child-avatar"><?php echo e($initial); ?></div>
                                <?php endif; ?>

                                <div class="child-info">
                                    <div class="child-name"><?php echo e($c['full_name']); ?></div>
                                    <div class="child-adm"><?php echo e($c['admission_no']); ?></div>
                                </div>
                            </div>

                            <div class="child-meta">

                                <div class="meta-item">
                                    <div class="k">Status</div>
                                    <span class="status-pill status-<?php echo e($status); ?>">
                                        <?php echo e($status); ?>
                                    </span>
                                </div>

                                <div class="meta-item">
                                    <div class="k">Class</div>
                                    <div class="v"><?php echo e($class_label); ?></div>
                                </div>

                                <div class="meta-item">
                                    <div class="k">Gender</div>
                                    <div class="v"><?php echo e($c['gender'] ?: '—'); ?></div>
                                </div>

                                <div class="meta-item">
                                    <div class="k">Relationship</div>
                                    <div class="v">
                                        <?php echo e($c['relationship'] ?: 'Guardian'); ?>
                                        <?php if (!empty($c['is_primary_guardian'])): ?>
                                            ★
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($att_pct !== null): ?>
                                    <div class="meta-item">
                                        <div class="k">Attendance</div>
                                        <div class="v"><?php echo (int)$att_pct; ?>%</div>
                                    </div>

                                    <div class="meta-item">
                                        <div class="k">Days Present</div>
                                        <div class="v"><?php echo (int)$att['present_days']; ?> / <?php echo (int)$att['total_days']; ?></div>
                                    </div>
                                <?php endif; ?>

                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </div>
    </section>

    <!-- RECENT RESULTS + ATTENDANCE -->
    <?php if (!empty($recent_results)): ?>
        <section class="section-block">
            <div class="section-header">
                <h2>Recent Results</h2>
                <span>Latest 6</span>
            </div>

            <div class="section-body tight">
                <?php foreach ($recent_results as $r):
                    $student = null;
                    foreach ($children as $c) {
                        if ((int)$c['student_id'] === (int)$r['student_id']) {
                            $student = $c;
                            break;
                        }
                    }
                    $student_name = $student ? $student['full_name'] : 'Student';
                ?>
                    <div class="list-row">
                        <div class="list-icon gold"><?php echo e($r['grade'] ?: '—'); ?></div>
                        <div class="list-body">
                            <div class="list-title">
                                <?php echo e($r['subject_name'] ?: 'Subject'); ?>
                                — <?php echo e($r['marks']); ?> marks
                            </div>
                            <div class="list-meta">
                                <?php echo e($student_name); ?>
                                <?php if (!empty($r['exam_name'])): ?>
                                    · <?php echo e($r['exam_name']); ?>
                                <?php endif; ?>
                                <?php if (!empty($r['created_at'])): ?>
                                    · <?php echo e(date('M j, Y', strtotime($r['created_at']))); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- 2-COLUMN: EVENTS + NEWS -->
    <div class="grid-2">

        <!-- EVENTS -->
        <section class="section-block" style="margin-bottom:0;">
            <div class="section-header">
                <h2>Upcoming Events</h2>
                <span>School calendar</span>
            </div>

            <div class="section-body tight">
                <?php if (empty($upcoming_events)): ?>
                    <div class="empty" style="padding:26px 12px;">
                        <p>No upcoming events at the moment.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_events as $ev):
                        $ts = strtotime($ev['event_date']);
                    ?>
                        <div class="list-row">
                            <div class="list-icon">
                                <?php echo $ts ? date('d', $ts) : '—'; ?>
                            </div>
                            <div class="list-body">
                                <div class="list-title"><?php echo e($ev['title']); ?></div>
                                <div class="list-meta">
                                    <?php echo $ts ? date('l, F j, Y', $ts) : e($ev['event_date']); ?>
                                    <?php if (!empty($ev['event_time'])): ?>
                                        · <?php echo e(date('g:i A', strtotime($ev['event_time']))); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['location'])): ?>
                                        · <?php echo e($ev['location']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- NEWS -->
        <section class="section-block" style="margin-bottom:0;">
            <div class="section-header">
                <h2>Recent News</h2>
                <span>School updates</span>
            </div>

            <div class="section-body tight">
                <?php if (empty($recent_news)): ?>
                    <div class="empty" style="padding:26px 12px;">
                        <p>No news posts yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_news as $n):
                        $ts = strtotime($n['news_date']);
                    ?>
                        <div class="list-row">
                            <div class="list-icon green">📰</div>
                            <div class="list-body">
                                <div class="list-title"><?php echo e($n['title']); ?></div>
                                <div class="list-meta">
                                    <?php echo ucfirst(e($n['category'])); ?>
                                    <?php if ($ts): ?>
                                        · <?php echo e(date('M j, Y', $ts)); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($n['excerpt'])): ?>
                                    <div class="list-sub"><?php echo e($n['excerpt']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    </div>

</main>

</body>
</html>