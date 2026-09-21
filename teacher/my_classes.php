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
   CLASSES AS CLASS TEACHER
   ========================================================================= */

$class_teacher_classes = [];

if (tableExists($conn, 'class_teachers')) {

    $sql = "
        SELECT
            ct.class_teacher_id,
            ct.assigned_at,
            ct.status AS ct_status,
            c.class_id,
            c.class_name,
            c.stream,
            c.class_level,
            c.status AS class_status,
            (SELECT COUNT(*) FROM students s
             WHERE s.class_id = c.class_id AND s.status = 'active') AS student_count
        FROM class_teachers ct
        INNER JOIN classes c ON c.class_id = ct.class_id
        WHERE ct.teacher_id = ?
          AND ct.status = 'active'
    ";

    $params = [$teacher_id];
    $types  = 'i';

    if ($active_year_id > 0) {
        $sql .= " AND ct.academic_year_id = ?";
        $params[] = $active_year_id;
        $types   .= 'i';
    }

    $sql .= " ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['label'] = $row['class_name']
                . ($row['stream'] ? ' - ' . $row['stream'] : '');
            $class_teacher_classes[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   SUBJECTS I TEACH
   ========================================================================= */

$subject_assignments = [];

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
            s.subject_type
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

    $sql .= " ORDER BY c.class_level ASC, c.class_name ASC, s.subject_name ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['class_label'] = $row['class_name']
                . ($row['stream'] ? ' - ' . $row['stream'] : '');
            $subject_assignments[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   GROUP SUBJECTS BY CLASS
   ========================================================================= */

$classes_with_subjects = [];

foreach ($subject_assignments as $a) {
    $cid = (int) $a['class_id'];
    if (!isset($classes_with_subjects[$cid])) {
        $classes_with_subjects[$cid] = [
            'class_id'    => $cid,
            'class_name'  => $a['class_name'],
            'stream'      => $a['stream'],
            'class_level' => $a['class_level'],
            'label'       => $a['class_label'],
            'subjects'    => [],
        ];
    }
    $classes_with_subjects[$cid]['subjects'][] = $a;
}

$student_counts = [];

if (!empty($classes_with_subjects) && tableExists($conn, 'students')) {
    $cids = array_keys($classes_with_subjects);
    $placeholders = implode(',', array_fill(0, count($cids), '?'));
    $types = str_repeat('i', count($cids));

    $stmt = mysqli_prepare(
        $conn,
        "SELECT class_id, COUNT(*) AS c
         FROM students
         WHERE status = 'active' AND class_id IN ($placeholders)
         GROUP BY class_id"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$cids);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $student_counts[(int)$row['class_id']] = (int)$row['c'];
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   STATS
   ========================================================================= */

$total_class_teacher    = count($class_teacher_classes);
$total_teaching_classes = count($classes_with_subjects);
$total_subjects         = count($subject_assignments);
$total_unique_subjects  = count(array_unique(array_column($subject_assignments, 'subject_id')));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>My Classes | PSRMS</title>

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

        /* SECTION */
        .section-block {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 22px;
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

        /* CLASS GRID */
        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .class-card {
            background: #fcfcfd;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: .2s ease;
        }

        .class-card:hover {
            border-color: rgba(201,162,39,.5);
            box-shadow: 0 10px 25px rgba(23,35,60,.06);
            background: var(--white);
        }

        .class-card-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .class-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .class-info { flex: 1; min-width: 0; }

        .class-name {
            color: var(--navy);
            font-size: 14.5px;
            font-weight: 750;
            margin-bottom: 3px;
            overflow-wrap: anywhere;
        }

        .class-meta {
            color: var(--muted);
            font-size: 11px;
        }

        .class-card-body {
            padding: 14px 18px 18px;
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

        .subject-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }

        .subject-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: 20px;
            background: var(--blue-bg);
            color: var(--blue);
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            overflow-wrap: anywhere;
        }

        .subject-pill .dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .subject-pill.science    { background: var(--purple-bg); color: var(--purple); }
        .subject-pill.language   { background: var(--green-bg);  color: var(--green); }
        .subject-pill.business   { background: var(--orange-bg); color: var(--orange); }
        .subject-pill.arts       { background: var(--pink-bg);   color: var(--pink); }
        .subject-pill.technical  { background: var(--blue-bg);   color: var(--blue); }
        .subject-pill.religious  { background: var(--purple-bg); color: var(--purple); }
        .subject-pill.vocational { background: var(--green-bg);  color: var(--green); }
        .subject-pill.competency { background: var(--orange-bg); color: var(--orange); }
        .subject-pill.academic   { background: var(--blue-bg);   color: var(--blue); }
        .subject-pill.other      { background: #f0efec;          color: #7a7a72; }

        .class-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
            gap: 10px;
            flex-wrap: wrap;
        }

        .student-count {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 700;
        }

        .student-count-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--green-bg);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
        }

        .class-actions { display: flex; gap: 6px; }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 32px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
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

        /* CLASS TEACHER PANEL */
        .ct-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid #f0f1f3;
        }

        .ct-row:last-child { border-bottom: none; }

        .ct-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            background: var(--gold-light);
            color: var(--navy);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .ct-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .ct-info { flex: 1; min-width: 0; }

        .ct-name {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 750;
            margin-bottom: 3px;
        }

        .ct-meta {
            color: var(--muted);
            font-size: 11px;
        }

        .ct-count {
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 750;
            flex-shrink: 0;
        }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 12px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
        }

        .empty h3 { color: var(--navy); font-size: 14px; margin-bottom: 4px; }


        /* =========================================================
           RESPONSIVE — TABLET
        ========================================================= */
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

            /* Stats — 2x2 grid, hint hidden, more compact */
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }
            .stat-card .hint  { font-size: 10px; }

            .stat-icon { width: 32px; height: 32px; font-size: 13px; top: 12px; right: 12px; }

            /* Sticky section headers on mobile */
            .section-header {
                position: sticky;
                top: var(--topbar-h);
                z-index: 5;
                padding: 14px 16px;
                background: var(--white);
                border-bottom: 1px solid var(--border);
                box-shadow: 0 4px 12px rgba(16,24,43,.04);
            }
            .section-header h2 { font-size: 13.5px; }
            .section-header span { font-size: 10.5px; }

            .section-body { padding: 16px; }

            /* Class teacher rows — clean wrapping */
            .ct-row {
                display: grid;
                grid-template-columns: 44px 1fr auto;
                grid-template-areas:
                    "icon info badge"
                    "icon count count";
                align-items: center;
                gap: 8px 12px;
                padding: 14px 0;
            }
            .ct-icon  { grid-area: icon; }
            .ct-info  { grid-area: info; }
            .ct-badge { grid-area: badge; align-self: start; }
            .ct-count {
                grid-area: count;
                text-align: left;
                font-size: 12px;
                color: var(--muted);
            }

            /* Class cards — 1 per row, comfortable spacing */
            .class-grid {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .class-card-head { padding: 14px 16px 12px; }
            .class-card-body { padding: 12px 16px 16px; }

            /* Class actions — full-width stacked buttons */
            .class-footer {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .student-count {
                justify-content: center;
                padding: 8px 0;
                background: #f7faf8;
                border-radius: 8px;
                font-size: 12.5px;
            }

            .class-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .class-actions .icon-btn {
                width: 100%;
                min-height: 44px;
                font-size: 12.5px;
                border-radius: 8px;
            }

            .subject-pill {
                font-size: 11px;
                padding: 6px 12px;
                min-height: 30px;
            }
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

            .section-header { padding: 12px 14px; }
            .section-header h2 { font-size: 13px; }

            .section-body { padding: 12px; }

            /* Compact class icon + name */
            .class-icon {
                width: 40px;
                height: 40px;
                font-size: 13px;
                border-radius: 8px;
            }

            .class-name { font-size: 13.5px; }
            .class-meta { font-size: 10.5px; }

            /* Subject pills — allow 2 rows on phone */
            .subject-pills {
                max-height: 78px;
                overflow: hidden;
                transition: max-height .3s ease;
            }
            .subject-pills.expanded { max-height: 600px; }

            .subject-pill { font-size: 10.5px; padding: 5px 10px; }

            /* "Show more" toggle */
            .pills-more-btn {
                display: inline-block !important;
                background: none;
                border: none;
                color: var(--gold);
                font-family: inherit;
                font-size: 11px;
                font-weight: 700;
                padding: 4px 0;
                cursor: pointer;
                text-decoration: underline;
                margin-bottom: 12px;
            }

            /* Class teacher row — even more compact */
            .ct-row {
                grid-template-columns: 40px 1fr;
                grid-template-areas:
                    "icon info"
                    "icon badge"
                    "count count";
                gap: 6px 10px;
            }

            .ct-icon { width: 40px; height: 40px; font-size: 13px; border-radius: 8px; }
            .ct-name { font-size: 13px; }
            .ct-meta { font-size: 10.5px; }

            .ct-badge { justify-self: start; font-size: 9.5px; padding: 4px 10px; }

            .ct-count { justify-self: start; }
        }

        /* =========================================================
           RESPONSIVE — VERY SMALL PHONES
        ========================================================= */
        @media (max-width: 400px) {

            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card .value { font-size: 17px; }

            .class-actions { grid-template-columns: 1fr; }
        }

        /* =========================================================
           LANDSCAPE PHONES
        ========================================================= */
        @media (max-height: 500px) and (max-width: 900px) {
            .section-header { position: static; }
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

        /* Show-more button only visible on small phones */
        .pills-more-btn { display: none; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'My Classes';
$topbar_subtitle = 'Classes & subjects I teach';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>My Classes</h1>
            <p>Classes I manage as class teacher, and subjects I teach.</p>
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
            <div class="stat-icon gold">C</div>
            <div class="label">Class Teacher Of</div>
            <div class="value"><?php echo number_format($total_class_teacher); ?></div>
            <div class="hint">Classes you manage</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">T</div>
            <div class="label">Teaching Classes</div>
            <div class="value"><?php echo number_format($total_teaching_classes); ?></div>
            <div class="hint">Classes you teach in</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">S</div>
            <div class="label">Subjects Taught</div>
            <div class="value"><?php echo number_format($total_subjects); ?></div>
            <div class="hint">Total assignments</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">U</div>
            <div class="label">Unique Subjects</div>
            <div class="value"><?php echo number_format($total_unique_subjects); ?></div>
            <div class="hint">Distinct subjects</div>
        </div>
    </div>

    <!-- CLASS TEACHER OF -->
    <?php if (!empty($class_teacher_classes)): ?>
        <section class="section-block">
            <div class="section-header">
                <h2>Class Teacher Of</h2>
                <span>
                    <?php echo $total_class_teacher; ?> class<?php echo $total_class_teacher === 1 ? '' : 'es'; ?>
                </span>
            </div>

            <div class="section-body" style="padding: 8px 22px;">

                <?php foreach ($class_teacher_classes as $c): ?>
                    <div class="ct-row">
                        <div class="ct-icon">
                            <?php echo (int) $c['class_level']; ?>
                        </div>

                        <div class="ct-info">
                            <div class="ct-name"><?php echo e($c['label']); ?></div>
                            <div class="ct-meta">
                                Level <?php echo (int) $c['class_level']; ?>
                                <?php if (!empty($c['assigned_at'])): ?>
                                    · Assigned <?php echo e(date('M j, Y', strtotime($c['assigned_at']))); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <span class="ct-badge">Class Teacher</span>

                        <div class="ct-count">
                            <?php echo (int) $c['student_count']; ?> student<?php echo (int) $c['student_count'] === 1 ? '' : 's'; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>
        </section>
    <?php endif; ?>

    <!-- SUBJECTS I TEACH -->
    <section class="section-block">
        <div class="section-header">
            <h2>Subjects I Teach</h2>
            <span>
                <?php echo $total_teaching_classes; ?> class<?php echo $total_teaching_classes === 1 ? '' : 'es'; ?>
            </span>
        </div>

        <div class="section-body">

            <?php if (empty($classes_with_subjects)): ?>

                <div class="empty">
                    <div class="empty-icon">📚</div>
                    <h3>No subject assignments yet</h3>
                    <p>Your headteacher hasn't assigned you any subjects yet.</p>
                </div>

            <?php else: ?>

                <div class="class-grid">
                    <?php foreach ($classes_with_subjects as $c):
                        $c_label  = $c['label'];
                        $s_count  = count($c['subjects']);
                        $st_count = $student_counts[$c['class_id']] ?? 0;
                        $pills_id  = 'pills_' . (int)$c['class_id'];
                    ?>
                        <div class="class-card">

                            <div class="class-card-head">
                                <div class="class-icon">
                                    <?php echo (int) $c['class_level']; ?>
                                </div>
                                <div class="class-info">
                                    <div class="class-name"><?php echo e($c_label); ?></div>
                                    <div class="class-meta">
                                        Level <?php echo (int) $c['class_level']; ?>
                                        · <?php echo $s_count; ?> subject<?php echo $s_count === 1 ? '' : 's'; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="class-card-body">

                                <div class="section-label-small">Subjects</div>
                                <div class="subject-pills" id="<?php echo $pills_id; ?>">
                                    <?php foreach ($c['subjects'] as $s):
                                        $type = strtolower($s['subject_type'] ?? 'academic');
                                    ?>
                                        <span class="subject-pill <?php echo e($type); ?>">
                                            <span class="dot"></span>
                                            <?php echo e($s['subject_name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($s_count > 4): ?>
                                    <button type="button"
                                            class="pills-more-btn"
                                            data-target="<?php echo $pills_id; ?>"
                                            onclick="togglePills(this)">
                                        Show all <?php echo $s_count; ?> subjects
                                    </button>
                                <?php endif; ?>

                                <div class="class-footer">
                                    <div class="student-count">
                                        <div class="student-count-icon">S</div>
                                        <?php echo $st_count; ?> student<?php echo $st_count === 1 ? '' : 's'; ?>
                                    </div>

                                    <div class="class-actions">
                                        <a href="students.php?class_id=<?php echo (int)$c['class_id']; ?>"
                                           class="icon-btn">Students</a>

                                        <a href="attendance.php?class_id=<?php echo (int)$c['class_id']; ?>"
                                           class="icon-btn">Attendance</a>
                                    </div>
                                </div>

                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </div>
    </section>

</main>


<script>
/* =========================================================
   EXPAND / COLLAPSE SUBJECT PILLS
========================================================= */
function togglePills(btn) {
    const target = document.getElementById(btn.dataset.target);
    if (!target) return;

    const expanded = target.classList.toggle('expanded');

    btn.textContent = expanded
        ? 'Show fewer'
        : 'Show all ' + target.querySelectorAll('.subject-pill').length + ' subjects';
}
</script>

</body>
</html>