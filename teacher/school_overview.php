<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   VERIFY HEADTEACHER
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT teacher_id, assignment_type
     FROM teachers
     WHERE user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$me || $me['assignment_type'] !== 'headteacher') {
    http_response_code(403);
    die('Access denied. Headteacher only.');
}


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

function scalar(mysqli $conn, string $sql, int $default = 0): int
{
    $res = @mysqli_query($conn, $sql);
    if (!$res) return $default;
    $row = mysqli_fetch_row($res);
    return (int) ($row[0] ?? $default);
}


/* =========================================================================
   ACTIVE ACADEMIC YEAR
   ========================================================================= */

$active_year = null;

if (tableExists($conn, 'academic_years')) {
    $res = mysqli_query(
        $conn,
        "SELECT academic_year_id, year
         FROM academic_years
         WHERE status = 'active'
         ORDER BY year DESC
         LIMIT 1"
    );
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $active_year = $row;
    }
}

$active_year_id = $active_year ? (int) $active_year['academic_year_id'] : 0;
$active_year_n  = $active_year ? (int) $active_year['year']                : 0;


/* =========================================================================
   STATS
   ========================================================================= */

/* Students */
$total_students   = 0;
$active_students  = 0;
$male_students    = 0;
$female_students  = 0;

if (tableExists($conn, 'students')) {
    $total_students  = scalar($conn, "SELECT COUNT(*) FROM students");
    $active_students = scalar($conn, "SELECT COUNT(*) FROM students WHERE status = 'active'");
    $male_students   = scalar($conn, "SELECT COUNT(*) FROM students WHERE gender = 'Male'");
    $female_students = scalar($conn, "SELECT COUNT(*) FROM students WHERE gender = 'Female'");
}

/* Teachers */
$total_teachers  = 0;
$total_academic  = 0;
$total_heads     = 0;

if (tableExists($conn, 'teachers')) {
    $total_teachers = scalar($conn, "SELECT COUNT(*) FROM teachers");
    $total_academic = scalar($conn, "SELECT COUNT(*) FROM teachers WHERE assignment_type = 'academic'");
    $total_heads    = scalar($conn, "SELECT COUNT(*) FROM teachers WHERE assignment_type = 'headteacher'");
}

/* Classes */
$total_classes = 0;
if (tableExists($conn, 'classes')) {
    $total_classes = scalar($conn, "SELECT COUNT(*) FROM classes WHERE status = 'active'");
}

/* Subjects */
$total_subjects = 0;
if (tableExists($conn, 'subjects')) {
    $total_subjects = scalar($conn, "SELECT COUNT(*) FROM subjects WHERE status = 'active'");
}

/* Assignments */
$total_assignments = 0;
$unassigned_teachers = 0;

if (tableExists($conn, 'teacher_assignments')) {
    if ($active_year_id > 0) {
        $total_assignments = scalar(
            $conn,
            "SELECT COUNT(*) FROM teacher_assignments
             WHERE status = 'active' AND academic_year_id = " . $active_year_id
        );
    } else {
        $total_assignments = scalar(
            $conn,
            "SELECT COUNT(*) FROM teacher_assignments WHERE status = 'active'"
        );
    }

    /* Teachers with no assignment this year */
    if ($active_year_id > 0) {
        $unassigned_teachers = scalar(
            $conn,
            "SELECT COUNT(*) FROM teachers t
             LEFT JOIN teacher_assignments ta
                ON ta.teacher_id = t.teacher_id
               AND ta.status = 'active'
               AND ta.academic_year_id = " . $active_year_id . "
             WHERE t.assignment_type <> 'headteacher'
               AND ta.assignment_id IS NULL"
        );
    }
}

/* Parents */
$total_parents = 0;
if (tableExists($conn, 'parents')) {
    $total_parents = scalar($conn, "SELECT COUNT(*) FROM parents");
}


/* =========================================================================
   CLASS-LEVEL BREAKDOWN
   ========================================================================= */

$classes_breakdown = [];

if (tableExists($conn, 'classes') && tableExists($conn, 'students')) {

    $res = mysqli_query(
        $conn,
        "SELECT
            c.class_id,
            c.class_name,
            c.stream,
            c.class_level,
            COUNT(s.student_id) AS student_count,
            SUM(s.gender = 'Male')   AS male_count,
            SUM(s.gender = 'Female') AS female_count
         FROM classes c
         LEFT JOIN students s
            ON s.class_id = c.class_id
           AND s.status = 'active'
         WHERE c.status = 'active'
         GROUP BY c.class_id
         ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC"
    );

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $row['label'] = $row['class_name']
                . ($row['stream'] ? ' — ' . $row['stream'] : '');
            $classes_breakdown[] = $row;
        }
    }
}


/* =========================================================================
   GENDER SPLIT PERCENTAGES
   ========================================================================= */

$total_gender = $male_students + $female_students;
$male_pct     = $total_gender > 0 ? round(($male_students   / $total_gender) * 100) : 0;
$female_pct   = $total_gender > 0 ? round(($female_students / $total_gender) * 100) : 0;

$max_class_count = 1;
foreach ($classes_breakdown as $c) {
    if ((int)$c['student_count'] > $max_class_count) {
        $max_class_count = (int)$c['student_count'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>School Overview | PSRMS</title>

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

        /* HEADER */
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
            font-size: 12.5px;
            margin-top: 5px;
        }

        .year-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 15px;
            background: var(--green-bg);
            border: 1px solid #cfe5d7;
            border-radius: 20px;
            color: var(--green);
            font-size: 11.5px;
            font-weight: 750;
            letter-spacing: .4px;
        }

        .year-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 3px rgba(62,118,85,.18);
        }

        .year-badge.missing {
            background: var(--orange-bg);
            border-color: #ecd9a8;
            color: var(--orange);
        }
        .year-badge.missing::before { background: var(--orange); box-shadow: 0 0 0 3px rgba(154,116,34,.18); }

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
            padding: 18px 20px;
            transition: .2s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 10px 25px rgba(23,35,60,.06);
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
            font-size: 26px;
            font-weight: 750;
            margin-top: 6px;
            line-height: 1.1;
        }

        .stat-card .hint {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 5px;
        }

        .stat-icon {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
        }

        .stat-icon.blue   { background: var(--blue-bg);   color: var(--blue); }
        .stat-icon.green  { background: var(--green-bg);  color: var(--green); }
        .stat-icon.gold   { background: var(--gold-light);color: var(--navy); }
        .stat-icon.purple { background: var(--purple-bg); color: var(--purple); }
        .stat-icon.orange { background: var(--orange-bg); color: var(--orange); }
        .stat-icon.pink   { background: var(--pink-bg);   color: var(--pink); }

        /* GRID */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        /* SECTION BLOCK */
        .section-block {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
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

        /* CLASS LIST */
        .class-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .class-row:last-child { border-bottom: none; }

        .class-row-info {
            flex: 1;
            min-width: 0;
        }

        .class-row-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .class-row-meta {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 2px;
        }

        .class-bar {
            width: 90px;
            height: 6px;
            border-radius: 4px;
            background: #eef0f3;
            overflow: hidden;
            flex-shrink: 0;
        }

        .class-bar-fill {
            height: 100%;
            background: var(--gold);
            border-radius: 4px;
            transition: width .4s ease;
        }

        .class-row-count {
            min-width: 60px;
            text-align: right;
            color: var(--navy);
            font-size: 13px;
            font-weight: 750;
        }

        /* GENDER BAR */
        .gender-block {
            margin-bottom: 14px;
        }

        .gender-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .gender-label {
            color: var(--muted);
            font-weight: 650;
        }

        .gender-count {
            color: var(--navy);
            font-weight: 750;
        }

        .gender-bar {
            height: 8px;
            border-radius: 4px;
            background: #eef0f3;
            overflow: hidden;
        }

        .gender-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width .4s ease;
        }

        .gender-bar-fill.male   { background: var(--blue); }
        .gender-bar-fill.female { background: var(--pink); }

        /* QUICK LINKS */
        .quick-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid #f0f1f3;
            text-decoration: none;
            color: inherit;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .quick-link:last-child { border-bottom: none; }
        .quick-link:hover .quick-link-title { color: var(--gold); }

        .quick-link-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .quick-link-icon.green  { background: var(--green-bg);  color: var(--green); }
        .quick-link-icon.gold   { background: var(--gold-light);color: var(--navy); }
        .quick-link-icon.purple { background: var(--purple-bg); color: var(--purple); }
        .quick-link-icon.orange { background: var(--orange-bg); color: var(--orange); }

        .quick-link-info { flex: 1; min-width: 0; }

        .quick-link-title {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 700;
            transition: .15s ease;
        }

        .quick-link-desc {
            color: var(--muted);
            font-size: 11.5px;
            margin-top: 2px;
        }

        .quick-link-arrow {
            color: var(--muted);
            font-size: 16px;
            font-weight: 700;
            flex-shrink: 0;
        }

        /* ALERT BANNER */
        .warn-banner {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            background: var(--orange-bg);
            border: 1px solid #ecd9a8;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 12.5px;
            color: var(--orange);
            font-weight: 600;
            line-height: 1.5;
        }

        .warn-banner .icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--orange);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .warn-banner a { color: inherit; font-weight: 800; text-decoration: underline; }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .grid-3 { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2     { grid-template-columns: 1fr; }
        }

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
            }

            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }
            .stat-card .hint  { font-size: 10px; }

            .stat-icon { width: 32px; height: 32px; font-size: 13px; top: 12px; right: 12px; }

            .section-header { padding: 14px 18px; }
            .section-header h2 { font-size: 13px; }
            .section-body { padding: 18px; }

            .class-bar { width: 70px; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 18px; }

            .section-header { padding: 12px 14px; }
            .section-body { padding: 14px; }

            .class-bar { display: none; }
            .class-row-count { min-width: auto; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card .hint { display: none; }
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
$topbar_title    = 'School Overview';
$topbar_subtitle = 'Whole school statistics';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>School Overview</h1>
            <p>A complete overview of students, staff, and academics.</p>
        </div>

        <?php if ($active_year): ?>
            <span class="year-badge">Active year: <?php echo e($active_year_n); ?></span>
        <?php else: ?>
            <span class="year-badge missing">No active academic year</span>
        <?php endif; ?>
    </div>

    <!-- WARN: NO ACTIVE YEAR -->
    <?php if (!$active_year): ?>
        <div class="warn-banner">
            <div class="icon">!</div>
            <div>
                There is <strong>no active academic year</strong>. Some counts may be incomplete.
                Please activate one from
                <a href="../admin/academic_years.php">Academic Years</a>.
            </div>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-icon blue">S</div>
            <div class="label">Total Students</div>
            <div class="value"><?php echo number_format($total_students); ?></div>
            <div class="hint"><?php echo number_format($active_students); ?> active</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">T</div>
            <div class="label">Teaching Staff</div>
            <div class="value"><?php echo number_format($total_teachers); ?></div>
            <div class="hint">
                <?php echo number_format($total_academic); ?> academic ·
                <?php echo number_format($total_heads); ?> head
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon gold">C</div>
            <div class="label">Classes</div>
            <div class="value"><?php echo number_format($total_classes); ?></div>
            <div class="hint"><?php echo number_format($total_subjects); ?> active subjects</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple">A</div>
            <div class="label">Assignments</div>
            <div class="value"><?php echo number_format($total_assignments); ?></div>
            <div class="hint">
                <?php echo $active_year_n ? 'For year ' . e($active_year_n) : 'All years'; ?>
            </div>
        </div>

    </div>

    <!-- SECONDARY STATS -->
    <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">

        <div class="stat-card">
            <div class="stat-icon pink">P</div>
            <div class="label">Parents</div>
            <div class="value"><?php echo number_format($total_parents); ?></div>
            <div class="hint">Registered parent accounts</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">U</div>
            <div class="label">Unassigned Teachers</div>
            <div class="value"><?php echo number_format($unassigned_teachers); ?></div>
            <div class="hint">No assignments this year</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon blue">G</div>
            <div class="label">Gender Split</div>
            <div class="value" style="font-size:16px;">
                <span style="color:var(--blue);"><?php echo $male_pct; ?>%</span>
                ·
                <span style="color:var(--pink);"><?php echo $female_pct; ?>%</span>
            </div>
            <div class="hint">M / F</div>
        </div>

    </div>

    <!-- CLASS BREAKDOWN + GENDER + QUICK LINKS -->
    <div class="grid-3">

        <!-- CLASS BREAKDOWN -->
        <div class="section-block">
            <div class="section-header">
                <h2>Students per Class</h2>
                <span><?php echo count($classes_breakdown); ?> classes</span>
            </div>

            <div class="section-body" style="padding: 8px 22px;">

                <?php if (empty($classes_breakdown)): ?>

                    <div class="empty">No classes registered yet.</div>

                <?php else: ?>

                    <?php foreach ($classes_breakdown as $c):
                        $cnt = (int)$c['student_count'];
                        $pct = $max_class_count > 0 ? round(($cnt / $max_class_count) * 100) : 0;
                    ?>
                        <div class="class-row">
                            <div class="class-row-info">
                                <div class="class-row-name"><?php echo e($c['label']); ?></div>
                                <div class="class-row-meta">
                                    Level <?php echo (int)$c['class_level']; ?> ·
                                    <?php echo (int)$c['male_count']; ?> M ·
                                    <?php echo (int)$c['female_count']; ?> F
                                </div>
                            </div>

                            <div class="class-bar">
                                <div class="class-bar-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>

                            <div class="class-row-count"><?php echo $cnt; ?></div>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>

        <!-- GENDER + QUICK LINKS -->
        <div>

            <!-- GENDER -->
            <div class="section-block" style="margin-bottom: 20px;">
                <div class="section-header">
                    <h2>Gender Distribution</h2>
                    <span><?php echo number_format($total_gender); ?> students</span>
                </div>

                <div class="section-body">

                    <div class="gender-block">
                        <div class="gender-row">
                            <span class="gender-label">Male</span>
                            <span class="gender-count">
                                <?php echo number_format($male_students); ?>
                                (<?php echo $male_pct; ?>%)
                            </span>
                        </div>
                        <div class="gender-bar">
                            <div class="gender-bar-fill male" style="width: <?php echo $male_pct; ?>%;"></div>
                        </div>
                    </div>

                    <div class="gender-block" style="margin-bottom:0;">
                        <div class="gender-row">
                            <span class="gender-label">Female</span>
                            <span class="gender-count">
                                <?php echo number_format($female_students); ?>
                                (<?php echo $female_pct; ?>%)
                            </span>
                        </div>
                        <div class="gender-bar">
                            <div class="gender-bar-fill female" style="width: <?php echo $female_pct; ?>%;"></div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- QUICK LINKS -->
            <div class="section-block">
                <div class="section-header">
                    <h2>Quick Links</h2>
                    <span>Headteacher tools</span>
                </div>

                <div class="section-body" style="padding: 6px 22px;">

                    <a href="manage_roles.php" class="quick-link">
                        <div class="quick-link-icon gold">R</div>
                        <div class="quick-link-info">
                            <div class="quick-link-title">Assign Roles</div>
                            <div class="quick-link-desc">Academic Master assignments</div>
                        </div>
                        <div class="quick-link-arrow">→</div>
                    </a>

                    <a href="all_teachers.php" class="quick-link">
                        <div class="quick-link-icon green">T</div>
                        <div class="quick-link-info">
                            <div class="quick-link-title">All Teachers</div>
                            <div class="quick-link-desc">Browse teaching staff</div>
                        </div>
                        <div class="quick-link-arrow">→</div>
                    </a>

                    <a href="manage_assignments.php" class="quick-link">
                        <div class="quick-link-icon purple">A</div>
                        <div class="quick-link-info">
                            <div class="quick-link-title">Manage Assignments</div>
                            <div class="quick-link-desc">Class & subject assignments</div>
                        </div>
                        <div class="quick-link-arrow">→</div>
                    </a>

                    <a href="announcements.php" class="quick-link">
                        <div class="quick-link-icon orange">N</div>
                        <div class="quick-link-info">
                            <div class="quick-link-title">Announcements</div>
                            <div class="quick-link-desc">School-wide notices</div>
                        </div>
                        <div class="quick-link-arrow">→</div>
                    </a>

                </div>
            </div>

        </div>

    </div>

</main>

</body>
</html>