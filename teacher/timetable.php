<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

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

function hhmm(?string $time): string {
    if (!$time) return '';
    return substr($time, 0, 5);
}


/* =========================================================================
   LOAD TEACHER
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT t.teacher_id, t.assignment_type,
            u.first_name, u.middle_name, u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$me) die('Teacher profile not found.');

$teacher_id   = (int)$me['teacher_id'];
$teacher_role = $me['assignment_type'] ?? 'teacher';
$teacher_name = trim(
    $me['first_name'] . ' ' .
    (!empty($me['middle_name']) ? $me['middle_name'] . ' ' : '') .
    $me['last_name']
);


/* =========================================================================
   ACTIVE ACADEMIC YEAR + TERM
   ========================================================================= */

$active_year    = null;
$active_year_id = 0;

$res = mysqli_query(
    $conn,
    "SELECT academic_year_id, year FROM academic_years
     WHERE status = 'active' ORDER BY year DESC LIMIT 1"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $active_year    = $row;
    $active_year_id = (int)$row['academic_year_id'];
}

$active_term      = null;
$active_term_id   = 0;
$active_term_name = '';

if ($active_year_id > 0 && tableExists($conn, 'terms')) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT term_id, term_name, start_date, end_date
         FROM terms
         WHERE academic_year_id = ? AND status = 'active'
         ORDER BY FIELD(term_name,'Term 1','Term 2','Term 3') ASC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $active_year_id);
    mysqli_stmt_execute($stmt);
    $active_term = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($active_term) {
        $active_term_id   = (int)$active_term['term_id'];
        $active_term_name = $active_term['term_name'];
    }
}


/* =========================================================================
   LOAD CLASSES THIS TEACHER BELONGS TO
   -------------------------------------------------------------------------
   Priority:
     1. Class(es) where they are the ACTIVE class teacher (class_teachers table)
     2. Any class where they are assigned to teach subjects (teacher_assignments)
   ========================================================================= */

$my_classes         = [];   // [class_id => row]
$is_class_teacher   = false;
$primary_class_id   = 0;    // the class-teacher class (if any)

/* ---------- 1) Class-teacher classes ---------- */
if ($active_year_id > 0 && tableExists($conn, 'class_teachers')) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT c.class_id, c.class_name, c.stream, c.class_level
         FROM class_teachers ct
         INNER JOIN classes c ON c.class_id = ct.class_id
         WHERE ct.teacher_id = ?
           AND ct.academic_year_id = ?
           AND ct.status = 'active'
           AND c.status = 'active'
         ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['label'] = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $row['role']  = 'Class Teacher';
        $my_classes[(int)$row['class_id']] = $row;
        $is_class_teacher = true;
        if ($primary_class_id === 0) $primary_class_id = (int)$row['class_id'];
    }
    mysqli_stmt_close($stmt);
}

/* ---------- 2) Classes where this teacher teaches a subject ---------- */
if ($active_year_id > 0 && tableExists($conn, 'teacher_assignments')) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT c.class_id, c.class_name, c.stream, c.class_level
         FROM teacher_assignments ta
         INNER JOIN classes c ON c.class_id = ta.class_id
         WHERE ta.teacher_id = ?
           AND ta.academic_year_id = ?
           AND ta.status = 'active'
           AND c.status = 'active'
         ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $cid = (int)$row['class_id'];
        if (!isset($my_classes[$cid])) {
            $row['label'] = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
            $row['role']  = 'Subject Teacher';
            $my_classes[$cid] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

ksort($my_classes);
$my_classes_list = array_values($my_classes);


/* =========================================================================
   SELECTED CLASS
   ========================================================================= */

$sel_class_id = (int)($_GET['class_id'] ?? 0);

/* If no valid class selected, pick the primary (class-teacher) or first */
if ($sel_class_id === 0 || !isset($my_classes[$sel_class_id])) {
    if ($primary_class_id > 0 && isset($my_classes[$primary_class_id])) {
        $sel_class_id = $primary_class_id;
    } elseif (!empty($my_classes_list)) {
        $sel_class_id = (int)$my_classes_list[0]['class_id'];
    }
}

$sel_class = $sel_class_id > 0 ? ($my_classes[$sel_class_id] ?? null) : null;


/* =========================================================================
   LOAD SUBJECTS + TEACHER NAMES FOR DISPLAY
   ========================================================================= */

$subjects       = [];
$teacher_names  = [];

/* Subjects */
$res = mysqli_query(
    $conn,
    "SELECT subject_id, subject_name FROM subjects WHERE status = 'active'"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $subjects[(int)$row['subject_id']] = $row['subject_name'];
    }
}

/* Teachers */
$res = mysqli_query(
    $conn,
    "SELECT t.teacher_id,
            u.first_name, u.middle_name, u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $teacher_names[(int)$row['teacher_id']] = trim(
            $row['first_name'] . ' ' .
            (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );
    }
}


/* =========================================================================
   LOAD TIMETABLE FOR SELECTED CLASS
   ========================================================================= */

$timetable_by_day = [];   // [day][period_no] => row
$period_meta      = [];   // [period_no] => ['start'=>, 'end'=>, 'break'=>, 'label'=>]
$max_period       = 0;

if ($sel_class_id > 0 && $active_year_id > 0 && $active_term_id > 0) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT day_of_week, period_no, subject_id, teacher_id,
                start_time, end_time, is_break, break_label
         FROM timetable
         WHERE class_id = ? AND academic_year_id = ? AND term_id = ?
         ORDER BY FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat') ASC,
                  period_no ASC"
    );
    mysqli_stmt_bind_param(
        $stmt, 'iii',
        $sel_class_id, $active_year_id, $active_term_id
    );
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {

        $day = $row['day_of_week'];
        $per = (int)$row['period_no'];

        $timetable_by_day[$day][$per] = $row;

        if ($per > $max_period) $max_period = $per;

        /* Period meta from the first day it's seen */
        if (!isset($period_meta[$per])) {
            $period_meta[$per] = [
                'start' => hhmm($row['start_time']),
                'end'   => hhmm($row['end_time']),
                'break' => (int)$row['is_break'] === 1,
                'label' => $row['break_label'] ?? '',
            ];
        }
    }
    mysqli_stmt_close($stmt);
}

$days_full = ['Mon','Tue','Wed','Thu','Fri'];
$days_full_map = [
    'Mon' => 'Monday',
    'Tue' => 'Tuesday',
    'Wed' => 'Wednesday',
    'Thu' => 'Thursday',
    'Fri' => 'Friday',
    'Sat' => 'Saturday',
];

$has_timetable = !empty($timetable_by_day);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>My Timetable | PSRMS</title>

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

        /* HEADER */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 { color: var(--navy); font-size: 25px; font-weight: 700; }
        .page-title p  { color: var(--muted); font-size: 12.5px; margin-top: 5px; }

        .badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 15px; border-radius: 20px;
            font-size: 11.5px; font-weight: 750;
            background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green);
        }
        .badge.gold { background: var(--gold); border-color: var(--gold-light); color: var(--navy); }
        .badge.missing { background: var(--orange-bg); border-color: #ecd9a8; color: var(--orange); }

        /* CLASS PICKER */
        .class-tabs {
            display: flex; gap: 8px; flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .class-tab {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px;
            background: var(--white); border: 1px solid var(--border);
            color: var(--navy); text-decoration: none;
            font-size: 12.5px; font-weight: 700;
            transition: .15s ease;
        }
        .class-tab:hover { border-color: var(--gold); }
        .class-tab.active {
            background: var(--navy); color: var(--white);
            border-color: var(--navy);
        }
        .class-tab .role {
            font-size: 9px; font-weight: 800;
            letter-spacing: .4px; text-transform: uppercase;
            padding: 2px 7px; border-radius: 20px;
            background: var(--gold); color: var(--navy);
        }
        .class-tab.active .role {
            background: var(--gold-light); color: var(--navy);
        }

        /* EMPTY STATE */
        .empty {
            padding: 60px 20px; text-align: center; color: var(--muted);
            font-size: 13px;
            background: var(--white); border: 1px solid var(--border);
            border-radius: 10px;
        }
        .empty .icon {
            width: 60px; height: 60px; margin: 0 auto 14px;
            border-radius: 50%; background: #f3f2ed; color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
        }
        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }
        .empty p  { max-width: 420px; margin: 0 auto; line-height: 1.6; }

        /* TIMETABLE CARD */
        .tt-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 22px;
        }

        .tt-card-head {
            background: var(--navy);
            color: #fff;
            padding: 16px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .tt-card-head h2 {
            font-size: 16px;
            font-weight: 750;
            letter-spacing: .3px;
            display: flex; align-items: center; gap: 10px;
        }
        .tt-card-head h2 .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--gold-light);
        }
        .tt-card-head .meta {
            font-size: 11.5px; opacity: .9; font-weight: 600;
        }

        /* GRID */
        .tt-grid-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .tt-grid {
            width: 100%;
            min-width: 900px;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
        }
        .tt-grid th, .tt-grid td {
            padding: 10px 12px;
            border-right: 1px solid #f0f1f3;
            border-bottom: 1px solid #f0f1f3;
            font-size: 12.5px;
            vertical-align: top;
        }
        .tt-grid th:last-child, .tt-grid td:last-child { border-right: none; }
        .tt-grid thead th {
            background: #fafaf8;
            color: var(--muted);
            font-size: 10px;
            font-weight: 750;
            letter-spacing: .6px;
            text-transform: uppercase;
            text-align: center;
        }
        .tt-grid .row-head {
            background: #fafaf8;
            color: var(--navy);
            font-weight: 750;
            text-align: center;
            font-size: 11.5px;
            white-space: nowrap;
            width: 130px;
        }
        .tt-grid .row-head small {
            display: block;
            font-size: 9.5px;
            color: var(--muted);
            font-weight: 600;
            margin-top: 3px;
        }

        /* CELLS */
        .tt-cell {
            padding: 8px 10px;
            border-radius: 8px;
            background: #f5f7fb;
            border-left: 3px solid var(--blue);
            min-height: 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
        }
        .tt-cell .subj {
            font-size: 12px;
            font-weight: 750;
            color: var(--navy);
            line-height: 1.25;
        }
        .tt-cell .teach {
            font-size: 10.5px;
            color: var(--muted);
            font-weight: 600;
            line-height: 1.25;
        }

        .tt-cell.is-break {
            background: var(--orange-bg);
            border-left-color: var(--orange);
            text-align: center;
            color: var(--orange);
            font-weight: 750;
            font-size: 11.5px;
        }
        .tt-cell.is-empty {
            background: #fafbfd;
            border-left-color: #d5d9e0;
            color: #b0b6c1;
            text-align: center;
            font-style: italic;
            font-size: 11px;
        }

        /* HIGHLIGHT: cells taught by the current teacher */
        .tt-cell.mine {
            background: #fffdf3;
            border-left-color: var(--gold);
        }
        .tt-cell.mine .subj {
            color: #7a5a00;
        }

        /* LEGEND */
        .legend {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            padding: 14px 22px;
            border-top: 1px solid var(--border);
            background: #fcfcfa;
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
        }
        .legend .key {
            display: inline-flex; align-items: center; gap: 7px;
        }
        .legend .swatch {
            width: 14px; height: 14px; border-radius: 4px;
            border-left: 3px solid var(--blue);
            background: #f5f7fb;
        }
        .legend .swatch.gold   { border-left-color: var(--gold);  background: #fffdf3; }
        .legend .swatch.orange { border-left-color: var(--orange);background: var(--orange-bg); }
        .legend .swatch.empty  { border-left-color: #d5d9e0;      background: #fafbfd; }

        /* RESPONSIVE */
        @media (max-width: 800px) {
            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }
            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .tt-card-head { padding: 14px 16px; }
            .tt-card-head h2 { font-size: 14px; }

            .tt-grid th, .tt-grid td { padding: 8px; font-size: 11.5px; }
            .tt-grid .row-head { width: 100px; }
        }

        @media (max-width: 500px) {
            .class-tab { padding: 8px 12px; font-size: 12px; }
            .tt-card-head { flex-direction: column; align-items: flex-start; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(30px, env(safe-area-inset-bottom));
                }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'My Timetable';
$topbar_subtitle = 'Class schedule';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>My Timetable</h1>
            <p>View the timetable for your assigned class.</p>
        </div>

        <div class="badges">
            <?php if ($active_year): ?>
                <span class="badge">Year: <?php echo e($active_year['year']); ?></span>
            <?php else: ?>
                <span class="badge missing">No active year</span>
            <?php endif; ?>

            <?php if ($active_term): ?>
                <span class="badge gold">Term: <?php echo e($active_term_name); ?></span>
            <?php else: ?>
                <span class="badge missing">No active term</span>
            <?php endif; ?>
        </div>
    </div>


    <?php if (!$active_year || !$active_term): ?>

        <div class="empty">
            <div class="icon">📅</div>
            <h3>Timetable not available</h3>
            <p>The school has not set an active academic year and term yet.</p>
        </div>

    <?php elseif (empty($my_classes_list)): ?>

        <div class="empty">
            <div class="icon">🎓</div>
            <h3>No class assigned to you</h3>
            <p>
                You are not assigned as a class teacher, and you do not teach any subject
                in any active class for this academic year.
                Please contact the academic master or headteacher.
            </p>
        </div>

    <?php else: ?>

        <!-- CLASS PICKER -->
        <div class="class-tabs">
            <?php foreach ($my_classes_list as $c): ?>
                <a class="class-tab <?php echo $sel_class_id === (int)$c['class_id'] ? 'active' : ''; ?>"
                   href="timetable.php?class_id=<?php echo (int)$c['class_id']; ?>">
                    <?php echo e($c['label']); ?>
                    <?php if (!empty($c['role'])): ?>
                        <span class="role"><?php echo e($c['role']); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>


        <?php if (!$sel_class): ?>

            <div class="empty">
                <div class="icon">📭</div>
                <h3>Pick a class</h3>
                <p>Select a class above to view its timetable.</p>
            </div>

        <?php elseif (!$has_timetable): ?>

            <div class="empty">
                <div class="icon">⏳</div>
                <h3>No timetable yet</h3>
                <p>
                    The timetable for <strong><?php echo e($sel_class['label']); ?></strong>
                    has not been published for <strong><?php echo e($active_term_name); ?></strong>.
                    Please check back later.
                </p>
            </div>

        <?php else: ?>

            <!-- TIMETABLE CARD -->
            <div class="tt-card">

                <div class="tt-card-head">
                    <h2>
                        <span class="dot"></span>
                        <?php echo e($sel_class['label']); ?>
                    </h2>
                    <span class="meta">
                        <?php echo e($active_term_name); ?>
                        · <?php echo e($active_year['year']); ?>
                        · <?php echo count($timetable_by_day); ?> day<?php echo count($timetable_by_day) === 1 ? '' : 's'; ?>
                        · <?php echo $max_period; ?> periods
                    </span>
                </div>

                <div class="tt-grid-wrap">
                    <table class="tt-grid">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <?php foreach ($days_full as $d): ?>
                                    <th><?php echo e($days_full_map[$d]); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($p = 1; $p <= $max_period; $p++): ?>
                                <tr>
                                    <td class="row-head">
                                        Period <?php echo $p; ?>
                                        <?php if (isset($period_meta[$p])): ?>
                                            <small>
                                                <?php echo e($period_meta[$p]['start']); ?>
                                                –
                                                <?php echo e($period_meta[$p]['end']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>

                                    <?php foreach ($days_full as $d):
                                        $row = $timetable_by_day[$d][$p] ?? null;
                                    ?>
                                        <td>
                                            <?php if (!$row): ?>

                                                <div class="tt-cell is-empty">—</div>

                                            <?php elseif ((int)$row['is_break'] === 1): ?>

                                                <div class="tt-cell is-break">
                                                    <?php echo e($row['break_label'] ?: 'Break'); ?>
                                                </div>

                                            <?php elseif (empty($row['subject_id']) || (int)$row['subject_id'] === 0): ?>

                                                <div class="tt-cell is-empty">Free</div>

                                            <?php else:
                                                $subj_id   = (int)$row['subject_id'];
                                                $subj_name = $subjects[$subj_id] ?? 'Subject';
                                                $tid       = (int)($row['teacher_id'] ?? 0);
                                                $t_name    = $teacher_names[$tid] ?? '';
                                                $is_mine   = $tid === $teacher_id;
                                            ?>
                                                <div class="tt-cell <?php echo $is_mine ? 'mine' : ''; ?>">
                                                    <div class="subj">
                                                        <?php echo e($subj_name); ?>
                                                        <?php if ($is_mine): ?>
                                                            <span style="color:var(--gold);font-size:10px;">●</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($t_name !== ''): ?>
                                                        <div class="teach"><?php echo e($t_name); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <!-- LEGEND -->
                <div class="legend">
                    <span class="key"><span class="swatch"></span> Subject lesson</span>
                    <span class="key"><span class="swatch gold"></span> Yours (you teach this)</span>
                    <span class="key"><span class="swatch orange"></span> Break / Lunch</span>
                    <span class="key"><span class="swatch empty"></span> Free / No lesson</span>
                </div>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</main>


<script>
/* Mobile sidebar */
(function () {
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const hamburgerBtn   = document.getElementById('hamburgerBtn');
    if (sidebarOverlay && hamburgerBtn) {
        hamburgerBtn.addEventListener('click', function () {
            document.body.classList.toggle('no-scroll');
            sidebarOverlay.classList.toggle('open');
            const sb = document.querySelector('.teacher-sidebar, .admin-sidebar, #sidebar, .sidebar');
            if (sb) sb.classList.toggle('open');
            hamburgerBtn.classList.toggle('active');
        });
        sidebarOverlay.addEventListener('click', function () {
            sidebarOverlay.classList.remove('open');
            document.body.classList.remove('no-scroll');
            const sb = document.querySelector('.teacher-sidebar, .admin-sidebar, #sidebar, .sidebar');
            if (sb) sb.classList.remove('open');
            hamburgerBtn.classList.remove('active');
        });
    }
})();
</script>

</body>
</html>