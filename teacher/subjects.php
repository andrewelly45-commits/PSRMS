<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   LOAD TEACHER
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        t.teacher_id,
        t.employee_no,
        t.assignment_type,
        u.first_name,
        u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$teacher) {
    die('Teacher profile not found.');
}

$teacher_id   = (int) $teacher['teacher_id'];
$teacher_name = trim($teacher['first_name'] . ' ' . $teacher['last_name']);


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

$subject_type_labels = [
    'academic'   => 'Academic',
    'competency' => 'Competency',
    'science'    => 'Science',
    'business'   => 'Business',
    'arts'       => 'Arts',
    'language'   => 'Language',
    'technical'  => 'Technical',
    'religious'  => 'Religious',
    'vocational' => 'Vocational',
    'other'      => 'Other',
];


/* =========================================================================
   ACTIVE ACADEMIC YEAR
   ========================================================================= */

$active_year    = null;
$active_year_id = 0;
$active_year_n  = 0;

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
        $active_year    = $row;
        $active_year_id = (int) $row['academic_year_id'];
        $active_year_n  = (int) $row['year'];
    }
}


/* =========================================================================
   LOAD SUBJECT ASSIGNMENTS FOR THIS TEACHER
   ========================================================================= */

$assignments = [];

if (tableExists($conn, 'teacher_assignments')) {

    $sql = "
        SELECT
            ta.assignment_id,
            ta.status AS ta_status,
            c.class_id,
            c.class_name,
            c.stream,
            c.class_level,
            s.subject_id,
            s.subject_name,
            s.subject_type,
            s.status AS subject_status
        FROM teacher_assignments ta
        INNER JOIN classes  c ON c.class_id  = ta.class_id
        INNER JOIN subjects s ON s.subject_id = ta.subject_id
        WHERE ta.teacher_id = ?
          AND ta.status = 'active'
    ";

    $params = [$teacher_id];
    $types  = 'i';

    if ($active_year_id > 0) {
        $sql .= " AND ta.academic_year_id = ?";
        $params[] = $active_year_id;
        $types   .= 'i';
    }

    $sql .= " ORDER BY s.subject_name ASC, c.class_level ASC, c.class_name ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['class_label'] = $row['class_name']
                . ($row['stream'] ? ' - ' . $row['stream'] : '');
            $assignments[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   GROUP BY SUBJECT
   ========================================================================= */

$subjects_grouped = [];

foreach ($assignments as $a) {
    $sid = (int) $a['subject_id'];

    if (!isset($subjects_grouped[$sid])) {
        $subjects_grouped[$sid] = [
            'subject_id'   => $sid,
            'subject_name' => $a['subject_name'],
            'subject_type' => $a['subject_type'] ?: 'academic',
            'classes'      => [],
        ];
    }

    $subjects_grouped[$sid]['classes'][] = [
        'class_id'    => (int) $a['class_id'],
        'class_label' => $a['class_label'],
        'class_level' => (int) $a['class_level'],
    ];
}


/* =========================================================================
   STUDENT COUNTS PER SUBJECT
   ========================================================================= */

$student_counts = []; // [subject_id][class_id] => count

if (!empty($assignments) && tableExists($conn, 'students')) {

    /* Build list of unique class ids */
    $class_ids = [];
    foreach ($assignments as $a) {
        $class_ids[(int)$a['class_id']] = true;
    }
    $class_ids = array_keys($class_ids);

    if (!empty($class_ids)) {
        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
        $types = str_repeat('i', count($class_ids));

        $stmt = mysqli_prepare(
            $conn,
            "SELECT class_id, COUNT(*) AS c
             FROM students
             WHERE status = 'active' AND class_id IN ($placeholders)
             GROUP BY class_id"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$class_ids);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $student_counts[(int)$row['class_id']] = (int)$row['c'];
            }
            mysqli_stmt_close($stmt);
        }
    }
}


/* =========================================================================
   STATS
   ========================================================================= */

$total_unique_subjects = count($subjects_grouped);
$total_assignments     = count($assignments);

$type_breakdown = [];
foreach ($subjects_grouped as $s) {
    $t = $s['subject_type'];
    $type_breakdown[$t] = ($type_breakdown[$t] ?? 0) + 1;
}

$total_classes_touched = 0;
$seen_classes = [];
foreach ($assignments as $a) {
    $cid = (int)$a['class_id'];
    if (!isset($seen_classes[$cid])) {
        $seen_classes[$cid] = true;
        $total_classes_touched++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>My Subjects | PSRMS</title>

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
            --leaf: #467a3c;
            --leaf-bg: #f0f7ee;
            --violet: #6a4a9e;
            --violet-bg: #f4efff;
            --sky: #2a6b8f;
            --sky-bg: #eaf6fb;
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
        .year-badge.missing::before {
            background: var(--orange);
            box-shadow: 0 0 0 3px rgba(154,116,34,.18);
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
            padding: 18px 20px;
            position: relative;
            overflow: hidden;
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
            font-size: 26px;
            font-weight: 750;
            margin-top: 6px;
            line-height: 1.1;
        }

        .stat-card .hint {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 4px;
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

        .stat-icon.gold   { background: var(--gold-light); color: var(--navy); }
        .stat-icon.blue   { background: var(--blue-bg);    color: var(--blue); }
        .stat-icon.green  { background: var(--green-bg);   color: var(--green); }
        .stat-icon.purple { background: var(--purple-bg);  color: var(--purple); }

        /* FILTER BAR */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
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
        }
        .filter-panel.collapsed .filter-toggle .chev {
            transform: rotate(-90deg);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
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

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 42px;
            padding: 0 16px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
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

        /* RESULTS BAR */
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

        /* =========================================================
           SUBJECTS GRID
        ========================================================= */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .subject-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: .2s ease;
        }

        .subject-card:hover {
            border-color: rgba(201,162,39,.5);
            box-shadow: 0 10px 25px rgba(23,35,60,.06);
        }

        .subject-card-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .subject-avatar {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .subject-avatar.science    { background: var(--purple-bg); color: var(--purple); }
        .subject-avatar.language   { background: var(--green-bg);  color: var(--green); }
        .subject-avatar.business   { background: var(--orange-bg); color: var(--orange); }
        .subject-avatar.arts       { background: var(--pink-bg);   color: var(--pink); }
        .subject-avatar.technical  { background: var(--sky-bg);    color: var(--sky); }
        .subject-avatar.religious  { background: var(--violet-bg); color: var(--violet); }
        .subject-avatar.vocational { background: var(--leaf-bg);   color: var(--leaf); }
        .subject-avatar.competency { background: var(--green-bg);  color: var(--green); }
        .subject-avatar.academic   { background: var(--blue-bg);   color: var(--blue); }
        .subject-avatar.other      { background: #f0efec;          color: #7a7a72; }

        .subject-info { flex: 1; min-width: 0; }

        .subject-name {
            color: var(--navy);
            font-size: 15px;
            font-weight: 750;
            margin-bottom: 4px;
            overflow-wrap: anywhere;
        }

        .subject-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .subject-type-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .4px;
            background: var(--blue-bg);
            color: var(--blue);
        }

        .subject-type-badge.science    { background: var(--purple-bg); color: var(--purple); }
        .subject-type-badge.language   { background: var(--green-bg);  color: var(--green); }
        .subject-type-badge.business   { background: var(--orange-bg); color: var(--orange); }
        .subject-type-badge.arts       { background: var(--pink-bg);   color: var(--pink); }
        .subject-type-badge.technical  { background: var(--sky-bg);    color: var(--sky); }
        .subject-type-badge.religious  { background: var(--violet-bg); color: var(--violet); }
        .subject-type-badge.vocational { background: var(--leaf-bg);   color: var(--leaf); }
        .subject-type-badge.competency { background: var(--green-bg);  color: var(--green); }
        .subject-type-badge.academic   { background: var(--blue-bg);   color: var(--blue); }
        .subject-type-badge.other      { background: #f0efec;          color: #7a7a72; }

        .subject-class-count {
            color: var(--muted);
            font-size: 10.5px;
            font-weight: 600;
        }

        .subject-card-body {
            padding: 14px 18px 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .section-label-small {
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 8px;
        }

        /* CLASS ROWS (inside subject card) */
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }

        .class-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            background: #fcfcfd;
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .class-item:hover {
            border-color: var(--gold);
            background: #fdfcf8;
        }

        .class-item-info {
            flex: 1;
            min-width: 0;
        }

        .class-item-name {
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 2px;
            overflow-wrap: anywhere;
        }

        .class-item-meta {
            color: var(--muted);
            font-size: 10.5px;
        }

        .class-item-arrow {
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .class-item:hover .class-item-arrow {
            color: var(--gold);
        }

        /* Actions */
        .subject-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 38px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 60px 20px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
        }

        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        /* =========================================================
           RESPONSIVE — TABLET
        ========================================================= */
        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr auto; }
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* =========================================================
           RESPONSIVE — MOBILE
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
            }

            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }
            .stat-card .hint  { font-size: 10px; }

            .stat-icon { width: 32px; height: 32px; font-size: 13px; top: 12px; right: 12px; }

            /* Collapsible filter */
            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 14px; }

            /* Subjects grid → single column */
            .subjects-grid { grid-template-columns: 1fr; gap: 12px; }

            .subject-card-head { padding: 14px 16px 12px; }
            .subject-card-body { padding: 12px 16px 16px; }

            .subject-avatar { width: 42px; height: 42px; font-size: 16px; }

            /* Actions full-width grid */
            .subject-card-actions .icon-btn { min-height: 44px; font-size: 12.5px; }

            /* Class items bigger tap targets */
            .class-item { padding: 12px 14px; }
        }

        /* =========================================================
           RESPONSIVE — SMALL PHONES
        ========================================================= */
        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 18px; }
            .stat-card .hint  { display: none; }

            .subject-avatar { width: 40px; height: 40px; font-size: 15px; }
            .subject-name { font-size: 14px; }

            .subject-card-head { padding: 12px 14px 10px; }
            .subject-card-body { padding: 10px 14px 14px; }

            .section-label-small { font-size: 9px; }

            .class-item { padding: 11px 12px; }
            .class-item-name { font-size: 12px; }
            .class-item-meta { font-size: 10px; }
        }

        /* =========================================================
           RESPONSIVE — VERY SMALL PHONES
        ========================================================= */
        @media (max-width: 400px) {

            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card .value { font-size: 17px; }

            .subject-card-actions { grid-template-columns: 1fr; }
        }

        /* =========================================================
           LANDSCAPE PHONES
        ========================================================= */
        @media (max-height: 500px) and (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
        }

        /* =========================================================
           SAFE AREA
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
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'My Subjects';
$topbar_subtitle = 'Subjects I teach';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>My Subjects</h1>
            <p>Subjects I teach across different classes.</p>
        </div>

        <?php if ($active_year): ?>
            <span class="year-badge">Active year: <?php echo e($active_year_n); ?></span>
        <?php else: ?>
            <span class="year-badge missing">No active academic year</span>
        <?php endif; ?>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon gold">S</div>
            <div class="label">Subjects I Teach</div>
            <div class="value"><?php echo number_format($total_unique_subjects); ?></div>
            <div class="hint">Unique subjects</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">C</div>
            <div class="label">Classes Touched</div>
            <div class="value"><?php echo number_format($total_classes_touched); ?></div>
            <div class="hint">Where I teach</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">A</div>
            <div class="label">Total Assignments</div>
            <div class="value"><?php echo number_format($total_assignments); ?></div>
            <div class="hint">Subject-class pairs</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">T</div>
            <div class="label">Subject Types</div>
            <div class="value"><?php echo number_format(count($type_breakdown)); ?></div>
            <div class="hint">Distinct categories</div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <?php if (!empty($subjects_grouped)): ?>
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="subjects.php" class="filter-form">

            <div class="filter-group">
                <label>Search Subject</label>
                <input type="text" name="q" id="searchInput" class="filter-control"
                       placeholder="Subject name…"
                       value="<?php echo e($_GET['q'] ?? ''); ?>">
            </div>

            <div class="filter-group">
                <label>Type</label>
                <select name="type" id="typeFilter" class="filter-control">
                    <option value="">All types</option>
                    <?php foreach ($subject_type_labels as $key => $label): ?>
                        <option value="<?php echo e($key); ?>"
                            <?php echo ($_GET['type'] ?? '') === $key ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

        </form>
    </section>
    <?php endif; ?>

    <!-- RESULTS -->
    <div class="results-bar">
        <h2>Subject Assignments</h2>
        <span class="results-count">
            <?php echo number_format($total_unique_subjects); ?> subject(s)
        </span>
    </div>

    <!-- SUBJECTS GRID -->
    <?php if (empty($subjects_grouped)): ?>

        <div class="empty">
            <div class="empty-icon">📚</div>
            <h3>No subjects assigned</h3>
            <p>Your headteacher hasn't assigned you any subjects yet.</p>
        </div>

    <?php else:

        /* Apply filters client-side for smooth UX */
        $search_q = strtolower(trim($_GET['q'] ?? ''));
        $type_q   = $_GET['type'] ?? '';

        $filtered = [];
        foreach ($subjects_grouped as $sub) {
            if ($search_q !== '' && strpos(strtolower($sub['subject_name']), $search_q) === false) {
                continue;
            }
            if ($type_q !== '' && $sub['subject_type'] !== $type_q) {
                continue;
            }
            $filtered[] = $sub;
        }

        if (empty($filtered)):
    ?>

        <div class="empty">
            <div class="empty-icon">🔍</div>
            <h3>No subjects match your filter</h3>
            <p>Try a different search term or clear the type filter.</p>
        </div>

        <?php else: ?>

        <div class="subjects-grid">
            <?php foreach ($filtered as $sub):
                $type = $sub['subject_type'];
                $type_label = $subject_type_labels[$type] ?? ucfirst($type);
                $initial = strtoupper(mb_substr($sub['subject_name'], 0, 1));
                $class_count = count($sub['classes']);
            ?>
                <div class="subject-card">

                    <div class="subject-card-head">
                        <div class="subject-avatar <?php echo e($type); ?>">
                            <?php echo e($initial); ?>
                        </div>

                        <div class="subject-info">
                            <div class="subject-name"><?php echo e($sub['subject_name']); ?></div>
                            <div class="subject-meta">
                                <span class="subject-type-badge <?php echo e($type); ?>">
                                    <?php echo e($type_label); ?>
                                </span>
                                <span class="subject-class-count">
                                    · <?php echo $class_count; ?> class<?php echo $class_count === 1 ? '' : 'es'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="subject-card-body">

                        <div class="section-label-small">Classes</div>
                        <div class="class-list">

                            <?php foreach ($sub['classes'] as $c):
                                $sc = $student_counts[$c['class_id']] ?? 0;
                            ?>
                                <a href="students.php?class_id=<?php echo (int)$c['class_id']; ?>&subject_id=<?php echo (int)$sub['subject_id']; ?>"
                                   class="class-item">
                                    <div class="class-item-info">
                                        <div class="class-item-name">
                                            <?php echo e($c['class_label']); ?>
                                        </div>
                                        <div class="class-item-meta">
                                            Level <?php echo (int)$c['class_level']; ?>
                                            <?php if ($sc > 0): ?>
                                                · <?php echo $sc; ?> student<?php echo $sc === 1 ? '' : 's'; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="class-item-arrow">→</span>
                                </a>
                            <?php endforeach; ?>

                        </div>

                        <div class="subject-card-actions">
                            <a href="results.php?subject_id=<?php echo (int)$sub['subject_id']; ?>"
                               class="icon-btn">Results</a>

                            <a href="attendance.php?subject_id=<?php echo (int)$sub['subject_id']; ?>"
                               class="icon-btn">Attendance</a>
                        </div>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    <?php endif; ?>

</main>


<script>
/* =========================================================
   FILTER PANEL COLLAPSE (mobile only)
========================================================= */
(function () {
    const filterPanel  = document.getElementById('filterPanel');
    const filterToggle = document.getElementById('filterToggle');
    if (!filterPanel || !filterToggle) return;

    const mq = window.matchMedia('(max-width: 800px)');
    const hasFilter = <?php echo (
        (trim($_GET['q'] ?? '') !== '') ||
        (($_GET['type'] ?? '') !== '')
    ) ? 'true' : 'false'; ?>;

    function syncFilterState() {
        if (mq.matches) {
            filterPanel.classList.toggle('collapsed', !hasFilter);
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