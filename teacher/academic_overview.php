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

function gradeFromMarks(float $marks): string {
    if ($marks >= 80) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 60) return 'C';
    if ($marks >= 50) return 'D';
    if ($marks >= 40) return 'E';
    return 'F';
}

function gradeClass(string $g): string {
    switch (strtoupper(trim($g))) {
        case 'A': case 'A+': case 'A-': return 'grade-a';
        case 'B': case 'B+': case 'B-': return 'grade-b';
        case 'C': case 'C+': case 'C-': return 'grade-c';
        case 'D': case 'D+': case 'D-': return 'grade-d';
        case 'E': case 'F': return 'grade-f';
        default:  return 'grade-na';
    }
}

function pct(int $num, int $den): float {
    if ($den <= 0) return 0;
    return round(($num / $den) * 100, 1);
}


/* =========================================================================
   AUTH — Academic / Headteacher only
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT t.teacher_id, t.assignment_type, u.first_name, u.last_name
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

$my_role = strtolower($me['assignment_type'] ?? '');

if (!in_array($my_role, ['academic', 'headteacher'], true)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><title>Access Denied</title>
    <style>body{font-family:Arial;background:#f4f6f8;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;}
    .box{background:#fff;padding:40px;border-radius:14px;max-width:500px;text-align:center;box-shadow:0 12px 30px rgba(0,0,0,.08);}
    .box h2{color:#a12626;margin:0 0 12px;} .box p{color:#4b5563;margin:0 0 20px;}
    .box a{display:inline-block;padding:11px 20px;background:#17233c;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;}</style>
    </head><body><div class="box">
    <h2>Access Denied</h2>
    <p>Only the <strong>Academic Master</strong> or <strong>Headteacher</strong> can view the school academic overview.</p>
    <a href="dashboard.php">Back to Dashboard</a>
    </div></body></html>
    <?php
    exit;
}


/* =========================================================================
   ACTIVE ACADEMIC YEAR + TERM
   ========================================================================= */

$active_year_id = 0;
$active_year    = null;

$res = mysqli_query(
    $conn,
    "SELECT academic_year_id, year
     FROM academic_years
     WHERE status = 'active'
     ORDER BY year DESC LIMIT 1"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $active_year    = $row;
    $active_year_id = (int) $row['academic_year_id'];
}

$active_term_name = '';

if ($active_year_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT term_name
         FROM terms
         WHERE academic_year_id = ? AND status = 'active'
         ORDER BY FIELD(term_name,'Term 1','Term 2','Term 3') ASC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $active_year_id);
    mysqli_stmt_execute($stmt);
    $t = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($t) $active_term_name = $t['term_name'];
}


/* =========================================================================
   FILTER OPTIONS
   ========================================================================= */

$academic_years = [];
$res = mysqli_query(
    $conn,
    "SELECT academic_year_id, year, status
     FROM academic_years
     ORDER BY year DESC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $academic_years[] = $row;
    }
}

$classes = [];
$res = mysqli_query(
    $conn,
    "SELECT class_id, class_name, stream, class_level
     FROM classes
     WHERE status = 'active'
     ORDER BY class_level ASC, class_name ASC, stream ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $row['label'] = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $classes[(int)$row['class_id']] = $row;
    }
}

$subjects = [];
$res = mysqli_query(
    $conn,
    "SELECT subject_id, subject_name, subject_type
     FROM subjects
     WHERE status = 'active'
     ORDER BY subject_name ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $subjects[(int)$row['subject_id']] = $row;
    }
}


/* =========================================================================
   FILTERS
   ========================================================================= */

$filter_year    = (int) ($_GET['year_id']   ?? $active_year_id);
$filter_term    = trim($_GET['term']         ?? ($active_term_name ?: 'Term 1'));

$valid_terms = ['Term 1', 'Term 2', 'Term 3'];
if (!in_array($filter_term, $valid_terms, true)) {
    $filter_term = 'Term 1';
}


/* =========================================================================
   SCHOOL-WIDE STATS
   ========================================================================= */

$school = [
    'total_results'  => 0,
    'total_students' => 0,
    'total_classes'  => 0,
    'total_subjects' => 0,
    'avg_marks'      => 0,
    'pass_rate'      => 0,
    'pass_count'     => 0,
    'fail_count'     => 0,
    'grade_counts'   => ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'E'=>0,'F'=>0],
];

if ($filter_year > 0) {

    $sql = "
        SELECT
            COUNT(*) AS total_results,
            COUNT(DISTINCT student_id) AS total_students,
            COUNT(DISTINCT class_id) AS total_classes,
            COUNT(DISTINCT subject_id) AS total_subjects,
            AVG(marks) AS avg_marks,
            SUM(marks >= 40) AS pass_count,
            SUM(marks <  40) AS fail_count,
            SUM(marks >= 80) AS a_count,
            SUM(marks >= 70 AND marks < 80) AS b_count,
            SUM(marks >= 60 AND marks < 70) AS c_count,
            SUM(marks >= 50 AND marks < 60) AS d_count,
            SUM(marks >= 40 AND marks < 50) AS e_count,
            SUM(marks <  40) AS f_count
        FROM results
        WHERE academic_year_id = ? AND term = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $filter_year, $filter_term);
        mysqli_stmt_execute($stmt);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($r) {
            $school['total_results']  = (int)$r['total_results'];
            $school['total_students'] = (int)$r['total_students'];
            $school['total_classes']  = (int)$r['total_classes'];
            $school['total_subjects'] = (int)$r['total_subjects'];
            $school['avg_marks']      = $r['avg_marks'] !== null ? round((float)$r['avg_marks'], 1) : 0;
            $school['pass_count']     = (int)$r['pass_count'];
            $school['fail_count']     = (int)$r['fail_count'];
            $school['grade_counts']   = [
                'A' => (int)$r['a_count'],
                'B' => (int)$r['b_count'],
                'C' => (int)$r['c_count'],
                'D' => (int)$r['d_count'],
                'E' => (int)$r['e_count'],
                'F' => (int)$r['f_count'],
            ];
            $school['pass_rate'] = pct($school['pass_count'], $school['total_results']);
        }
    }
}


/* =========================================================================
   SUBJECT PERFORMANCE
   ========================================================================= */

$subject_stats = [];

if ($filter_year > 0) {

    $sql = "
        SELECT
            s.subject_id,
            s.subject_name,
            COUNT(r.result_id) AS total_results,
            AVG(r.marks) AS avg_marks,
            MIN(r.marks) AS min_marks,
            MAX(r.marks) AS max_marks,
            SUM(r.marks >= 40) AS pass_count
        FROM results r
        INNER JOIN subjects s ON s.subject_id = r.subject_id
        WHERE r.academic_year_id = ? AND r.term = ?
        GROUP BY s.subject_id
        ORDER BY avg_marks DESC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $filter_year, $filter_term);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $total = (int)$row['total_results'];
            $pass  = (int)$row['pass_count'];
            $subject_stats[] = [
                'subject_id'   => (int)$row['subject_id'],
                'subject_name' => $row['subject_name'],
                'total'        => $total,
                'avg'          => round((float)$row['avg_marks'], 1),
                'min'          => round((float)$row['min_marks'], 1),
                'max'          => round((float)$row['max_marks'], 1),
                'pass_rate'    => pct($pass, $total),
                'grade'        => gradeFromMarks((float)$row['avg_marks']),
            ];
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   CLASS PERFORMANCE
   ========================================================================= */

$class_stats = [];

if ($filter_year > 0) {

    $sql = "
        SELECT
            r.class_id,
            COUNT(r.result_id) AS total_results,
            COUNT(DISTINCT r.student_id) AS total_students,
            AVG(r.marks) AS avg_marks,
            SUM(r.marks >= 40) AS pass_count
        FROM results r
        WHERE r.academic_year_id = ? AND r.term = ?
        GROUP BY r.class_id
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $filter_year, $filter_term);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $cid = (int)$row['class_id'];
            if (!isset($classes[$cid])) continue;

            $total = (int)$row['total_results'];
            $pass  = (int)$row['pass_count'];

            $class_stats[$cid] = [
                'class_id'     => $cid,
                'label'        => $classes[$cid]['label'],
                'class_level'  => (int)$classes[$cid]['class_level'],
                'total'        => $total,
                'students'     => (int)$row['total_students'],
                'avg'          => round((float)$row['avg_marks'], 1),
                'pass_rate'    => pct($pass, $total),
                'grade'        => gradeFromMarks((float)$row['avg_marks']),
            ];
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   TOP PERFORMERS (Top 10 across school)
   ========================================================================= */

$top_students = [];

if ($filter_year > 0) {

    $sql = "
        SELECT
            s.student_id,
            s.full_name,
            s.admission_no,
            s.photo,
            c.class_name,
            c.stream,
            AVG(r.marks) AS avg_marks,
            COUNT(r.result_id) AS subjects_count
        FROM results r
        INNER JOIN students s ON s.student_id = r.student_id
        LEFT JOIN classes  c ON c.class_id   = s.class_id
        WHERE r.academic_year_id = ? AND r.term = ?
        GROUP BY s.student_id
        HAVING subjects_count >= 3
        ORDER BY avg_marks DESC
        LIMIT 10
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $filter_year, $filter_term);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['avg_marks'] = round((float)$row['avg_marks'], 1);
            $row['grade']     = gradeFromMarks($row['avg_marks']);
            $row['class_label'] = $row['class_name']
                ? $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '')
                : '—';
            $top_students[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   GRADE DISTRIBUTION TOTALS
   ========================================================================= */

$total_graded = array_sum($school['grade_counts']);
$grade_pct = [];
foreach ($school['grade_counts'] as $g => $count) {
    $grade_pct[$g] = pct($count, max(1, $total_graded));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Academic Overview | PSRMS</title>

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

        .badge-term {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--gold);
            color: var(--navy);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: 6px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }

        /* STATS GRID */
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

        .stat-icon.gold  { background: var(--gold-light); color: var(--navy); }
        .stat-icon.green { background: var(--green-bg);   color: var(--green); }
        .stat-icon.orange{ background: var(--orange-bg);  color: var(--orange); }
        .stat-icon.purple{ background: var(--purple-bg);  color: var(--purple); }

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

        /* FILTER PANEL */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .filter-group { min-width: 0; }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }

        .filter-control {
            width: 100%;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            transition: .15s ease;
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
            background-color: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 20px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
            white-space: nowrap;
        }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover { background: var(--navy-dark); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { border-color: var(--gold); }

        /* CARDS / SECTIONS */
        .section-block {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .section-header {
            padding: 16px 22px;
            background: #fcfcfa;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .section-header h2 {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .section-header h2 i { color: var(--gold); font-size: 14px; }

        .section-header .meta {
            font-size: 11.5px;
            color: var(--muted);
        }

        /* 2-COLUMN LAYOUT */
        .grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* GRADE DISTRIBUTION */
        .grade-dist {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            padding: 22px;
        }

        .grade-tile {
            text-align: center;
            padding: 16px 8px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fcfcfd;
            transition: .15s ease;
        }

        .grade-tile:hover { transform: translateY(-2px); }

        .grade-tile .letter {
            font-size: 22px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 8px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .grade-tile .count {
            font-size: 13px;
            font-weight: 750;
            color: var(--navy);
        }

        .grade-tile .pct {
            font-size: 10.5px;
            color: var(--muted);
            margin-top: 3px;
            font-weight: 600;
        }

        .grade-tile.a { background: var(--green-bg);  border-color: #cfe5d7; }
        .grade-tile.a .letter { color: var(--green); }

        .grade-tile.b { background: var(--blue-bg);   border-color: #cddeea; }
        .grade-tile.b .letter { color: var(--blue); }

        .grade-tile.c { background: #fdf5dd;          border-color: #ecd9a8; }
        .grade-tile.c .letter { color: var(--orange); }

        .grade-tile.d { background: var(--orange-bg); border-color: #ecd9a8; }
        .grade-tile.d .letter { color: var(--orange); }

        .grade-tile.e { background: var(--orange-bg); border-color: #ecd9a8; }
        .grade-tile.e .letter { color: var(--orange); }

        .grade-tile.f { background: var(--red-bg);    border-color: #efd2d2; }
        .grade-tile.f .letter { color: var(--red); }

        /* SUBJECT BARS */
        .subject-list {
            display: flex;
            flex-direction: column;
        }

        .subject-row {
            padding: 14px 22px;
            border-bottom: 1px solid #f0f1f3;
            display: grid;
            grid-template-columns: 1.4fr 60px 1fr 60px;
            gap: 14px;
            align-items: center;
        }

        .subject-row:last-child { border-bottom: none; }

        .subject-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .subject-avg {
            font-size: 13.5px;
            font-weight: 750;
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            text-align: right;
        }

        .subject-bar {
            height: 8px;
            background: #eef0f3;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .subject-bar-fill {
            height: 100%;
            border-radius: 6px;
            transition: width .4s ease;
        }

        .subject-bar-fill.good { background: linear-gradient(90deg, var(--green) 0%, #5ba677 100%); }
        .subject-bar-fill.mid  { background: linear-gradient(90deg, var(--orange) 0%, #c9a227 100%); }
        .subject-bar-fill.bad  { background: linear-gradient(90deg, var(--red) 0%, #c45a5a 100%); }

        .subject-grade {
            text-align: right;
        }

        /* CLASS TABLE */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 640px;
        }

        table.data-table thead th {
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        table.data-table tbody td {
            padding: 12px 14px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
        }

        table.data-table tbody tr:last-child td { border-bottom: none; }
        table.data-table tbody tr:hover { background: #fbfbf8; }

        .cell-strong {
            color: var(--navy);
            font-weight: 700;
            font-size: 13px;
        }

        .cell-num {
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-weight: 750;
            color: var(--navy);
            font-size: 13px;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 30px;
            padding: 0 9px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 800;
            letter-spacing: .4px;
        }

        .grade-a { background: var(--green-bg);  color: var(--green);  }
        .grade-b { background: var(--blue-bg);   color: var(--blue);   }
        .grade-c { background: #fdf5dd;          color: var(--orange); }
        .grade-d { background: var(--orange-bg); color: var(--orange); }
        .grade-f { background: var(--red-bg);    color: var(--red);    }
        .grade-na{ background: #eef0f5;          color: var(--muted);  }

        /* TOP STUDENTS */
        .top-list {
            display: flex;
            flex-direction: column;
        }

        .top-row {
            padding: 12px 22px;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .top-row:last-child { border-bottom: none; }

        .rank-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eef0f5;
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .rank-badge.top1 { background: var(--gold); color: var(--navy); }
        .rank-badge.top2 { background: #c7cdd6; color: var(--navy); }
        .rank-badge.top3 { background: #e0b47e; color: var(--navy); }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 11px;
            flex: 1;
            min-width: 0;
        }

        .student-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
            object-fit: cover;
            border: 1.5px solid var(--border);
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .student-info { min-width: 0; }

        .student-name {
            color: var(--navy);
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 2px;
            overflow-wrap: anywhere;
        }

        .student-meta {
            color: var(--muted);
            font-size: 10.5px;
        }

        .top-avg {
            text-align: right;
            flex-shrink: 0;
        }

        .top-avg .num {
            font-size: 15px;
            font-weight: 800;
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .top-avg .grade-badge {
            margin-top: 4px;
            min-width: 30px;
            height: 24px;
            font-size: 11px;
        }

        /* EMPTY */
        .empty {
            padding: 60px 24px;
            text-align: center;
            color: var(--muted);
        }

        .empty-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 15px;
            margin-bottom: 6px;
        }

        .empty p {
            font-size: 12.5px;
            line-height: 1.55;
        }

        /* =========================================================
           RESPONSIVE — TABLET (≤1100px)
        ========================================================= */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card { padding: 12px; gap: 10px; }
            .stat-icon { width: 36px; height: 36px; font-size: 14px; }
            .stat-card .value { font-size: 17px; }
            .stat-card .label { font-size: 9px; }
            .stat-card .sub { font-size: 10px; }

            .filter-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .filter-control { height: 46px; font-size: 14px; }
            .filter-form .btn { width: 100%; min-height: 46px; }

            .section-header { padding: 14px 16px; }
            .section-header h2 { font-size: 13px; }

            /* Grade distribution — 3 per row on mobile */
            .grade-dist {
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                padding: 16px;
            }

            .grade-tile { padding: 12px 6px; }
            .grade-tile .letter { font-size: 18px; }
            .grade-tile .count { font-size: 12px; }
            .grade-tile .pct { font-size: 10px; }

            /* Subject rows stack */
            .subject-row {
                grid-template-columns: 1fr auto;
                gap: 10px;
                padding: 12px 16px;
            }

            .subject-bar { grid-column: 1 / -1; }
            .subject-grade { text-align: left; }

            /* Data table → simple stacked rows */
            .table-wrapper { display: none; }
            .data-card-list { display: block; }

            /* Top students */
            .top-row { padding: 10px 16px; gap: 11px; }

            .top-avg .num { font-size: 14px; }
        }

        /* DATA CARD LIST (mobile version of data table) */
        .data-card-list { display: none; }

        .data-card {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .data-card:last-child { border-bottom: none; }

        .data-card-body { flex: 1; min-width: 0; }

        .data-card-title {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 3px;
            overflow-wrap: anywhere;
        }

        .data-card-meta {
            color: var(--muted);
            font-size: 11px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .data-card-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .data-card-right {
            text-align: right;
            flex-shrink: 0;
        }

        .data-card-avg {
            font-size: 15px;
            font-weight: 800;
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            margin-bottom: 4px;
        }

        /* =========================================================
           RESPONSIVE — SMALL MOBILE (≤550px)
        ========================================================= */
        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  {
                padding: 11px;
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            .stat-icon { width: 32px; height: 32px; font-size: 13px; }
            .stat-card .value { font-size: 16px; }

            .grade-dist { gap: 6px; padding: 12px; }
            .grade-tile { padding: 10px 4px; border-radius: 10px; }
            .grade-tile .letter { font-size: 16px; }
            .grade-tile .count { font-size: 11px; }
            .grade-tile .pct { font-size: 9px; }

            .subject-row {
                padding: 10px 14px;
                gap: 8px;
            }

            .subject-name { font-size: 12px; }
            .subject-avg  { font-size: 12.5px; }

            .top-row { padding: 10px 14px; gap: 10px; }
            .rank-badge { width: 32px; height: 32px; font-size: 12px; }
            .student-avatar { width: 32px; height: 32px; font-size: 12px; }
            .student-name { font-size: 12.5px; }
            .top-avg .num { font-size: 13px; }

            .data-card { padding: 11px 14px; }
        }

        /* SAFE AREA */
        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
            }
        }

        /* REDUCED MOTION */
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
$topbar_title    = 'Academic Overview';
$topbar_subtitle = 'School performance';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-chart-pie"></i> Academic Overview</h1>
            <p>School-wide performance analytics for the selected term.</p>
        </div>
        <div>
            <span class="badge-term">
                <i class="fa-solid fa-calendar-check"></i>
                <?php echo e($filter_term); ?>
                <?php if ($active_year): ?>
                    · <?php echo e($active_year['year']); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php if ($filter_year <= 0): ?>

        <div class="empty">
            <div class="empty-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
            <h3>No academic year selected</h3>
            <p>Please select an academic year to view analytics.</p>
        </div>

    <?php else: ?>

        <!-- SCHOOL STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Total Results</div>
                    <div class="value"><?php echo number_format($school['total_results']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Students Graded</div>
                    <div class="value"><?php echo number_format($school['total_students']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold">
                    <i class="fa-solid fa-percent"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Overall Average</div>
                    <div class="value"><?php echo number_format($school['avg_marks'], 1); ?>%</div>
                    <div class="sub">
                        <?php echo number_format($school['total_subjects']); ?> subjects
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fa-solid fa-thumbs-up"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Pass Rate</div>
                    <div class="value"><?php echo number_format($school['pass_rate'], 1); ?>%</div>
                    <div class="sub">
                        <?php echo number_format($school['pass_count']); ?> pass ·
                        <?php echo number_format($school['fail_count']); ?> fail
                    </div>
                </div>
            </div>
        </div>

        <!-- FILTERS -->
        <form method="GET" action="academic_overview.php" class="filter-panel">
            <div class="filter-form">

                <div class="filter-group">
                    <label>Academic Year</label>
                    <select name="year_id" class="filter-control" onchange="this.form.submit()">
                        <?php foreach ($academic_years as $y): ?>
                            <option value="<?php echo (int)$y['academic_year_id']; ?>"
                                <?php echo $filter_year === (int)$y['academic_year_id'] ? 'selected' : ''; ?>>
                                <?php echo e($y['year']); ?>
                                <?php echo strtolower($y['status'] ?? '') === 'active' ? ' (Active)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Term</label>
                    <select name="term" class="filter-control" onchange="this.form.submit()">
                        <?php foreach ($valid_terms as $t): ?>
                            <option value="<?php echo e($t); ?>"
                                <?php echo $filter_term === $t ? 'selected' : ''; ?>>
                                <?php echo e($t); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <a href="academic_overview.php" class="btn btn-ghost" style="width:100%;">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                </div>

            </div>
        </form>

        <!-- GRADE DISTRIBUTION -->
        <section class="section-block">
            <div class="section-header">
                <h2>
                    <i class="fa-solid fa-chart-simple"></i>
                    Grade Distribution
                </h2>
                <span class="meta">
                    <?php echo number_format($total_graded); ?> total grades
                </span>
            </div>

            <?php if ($total_graded <= 0): ?>

                <div class="empty">
                    <div class="empty-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <h3>No grades yet</h3>
                    <p>There are no results recorded for this term.</p>
                </div>

            <?php else: ?>

                <div class="grade-dist">
                    <?php foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $g):
                        $count = $school['grade_counts'][$g] ?? 0;
                        $pct   = $grade_pct[$g] ?? 0;
                    ?>
                        <div class="grade-tile <?php echo strtolower($g); ?>">
                            <div class="letter"><?php echo $g; ?></div>
                            <div class="count"><?php echo number_format($count); ?></div>
                            <div class="pct"><?php echo number_format($pct, 1); ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

        <!-- SUBJECT + CLASS 2-COLUMN -->
        <div class="grid-2col">

            <!-- SUBJECT PERFORMANCE -->
            <section class="section-block" style="margin-bottom:0;">
                <div class="section-header">
                    <h2>
                        <i class="fa-solid fa-book-open"></i>
                        Subject Performance
                    </h2>
                    <span class="meta">
                        <?php echo count($subject_stats); ?> subject<?php echo count($subject_stats) === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <?php if (empty($subject_stats)): ?>

                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-book"></i></div>
                        <h3>No subject data</h3>
                        <p>No results available for this term.</p>
                    </div>

                <?php else: ?>

                    <div class="subject-list">
                        <?php foreach ($subject_stats as $sub):
                            $barClass = 'good';
                            if ($sub['avg'] < 50) $barClass = 'bad';
                            elseif ($sub['avg'] < 65) $barClass = 'mid';
                        ?>
                            <div class="subject-row">
                                <div class="subject-name">
                                    <?php echo e($sub['subject_name']); ?>
                                </div>

                                <div class="subject-avg">
                                    <?php echo number_format($sub['avg'], 1); ?>
                                </div>

                                <div class="subject-bar">
                                    <div class="subject-bar-fill <?php echo $barClass; ?>"
                                         style="width:<?php echo min(100, $sub['avg']); ?>%;"></div>
                                </div>

                                <div class="subject-grade">
                                    <span class="grade-badge <?php echo e(gradeClass($sub['grade'])); ?>">
                                        <?php echo e($sub['grade']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </section>

            <!-- TOP STUDENTS -->
            <section class="section-block" style="margin-bottom:0;">
                <div class="section-header">
                    <h2>
                        <i class="fa-solid fa-trophy"></i>
                        Top Performers
                    </h2>
                    <span class="meta">
                        Top <?php echo count($top_students); ?> across school
                    </span>
                </div>

                <?php if (empty($top_students)): ?>

                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-user-graduate"></i></div>
                        <h3>No top performers</h3>
                        <p>Not enough results to rank students.</p>
                    </div>

                <?php else: ?>

                    <div class="top-list">
                        <?php foreach ($top_students as $i => $s):
                            $rank = $i + 1;
                            $rankClass = '';
                            if ($rank === 1) $rankClass = 'top1';
                            elseif ($rank === 2) $rankClass = 'top2';
                            elseif ($rank === 3) $rankClass = 'top3';
                            $initial = strtoupper(mb_substr($s['full_name'], 0, 1));
                        ?>
                            <div class="top-row">
                                <div class="rank-badge <?php echo $rankClass; ?>">
                                    <?php echo $rank; ?>
                                </div>

                                <div class="student-cell">
                                    <?php if (!empty($s['photo']) && is_file(__DIR__ . '/../uploads/students/' . $s['photo'])): ?>
                                        <div class="student-avatar">
                                            <img src="../uploads/students/<?php echo e($s['photo']); ?>" alt="" loading="lazy">
                                        </div>
                                    <?php else: ?>
                                        <div class="student-avatar"><?php echo e($initial); ?></div>
                                    <?php endif; ?>
                                    <div class="student-info">
                                        <div class="student-name"><?php echo e($s['full_name']); ?></div>
                                        <div class="student-meta">
                                            <?php echo e($s['class_label']); ?>
                                            · <?php echo (int)$s['subjects_count']; ?> subjects
                                        </div>
                                    </div>
                                </div>

                                <div class="top-avg">
                                    <div class="num"><?php echo number_format($s['avg_marks'], 1); ?></div>
                                    <span class="grade-badge <?php echo e(gradeClass($s['grade'])); ?>">
                                        <?php echo e($s['grade']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </section>

        </div>

        <!-- CLASS PERFORMANCE -->
        <section class="section-block">
            <div class="section-header">
                <h2>
                    <i class="fa-solid fa-school"></i>
                    Class Performance
                </h2>
                <span class="meta">
                    <?php echo count($class_stats); ?> class<?php echo count($class_stats) === 1 ? '' : 'es'; ?>
                </span>
            </div>

            <?php if (empty($class_stats)): ?>

                <div class="empty">
                    <div class="empty-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <h3>No class data</h3>
                    <p>No results recorded for this term.</p>
                </div>

            <?php else:
                /* Sort by average descending */
                uasort($class_stats, fn($a, $b) => $b['avg'] <=> $a['avg']);
            ?>

                <!-- DESKTOP TABLE -->
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th style="width:110px;">Students</th>
                                <th style="width:110px;">Results</th>
                                <th style="width:110px;">Average</th>
                                <th style="width:110px;">Pass Rate</th>
                                <th style="width:90px;">Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_stats as $cid => $row): ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?php echo e($row['label']); ?></span>
                                    </td>
                                    <td><span class="cell-num"><?php echo (int)$row['students']; ?></span></td>
                                    <td><span class="cell-num"><?php echo (int)$row['total']; ?></span></td>
                                    <td><span class="cell-num"><?php echo number_format($row['avg'], 1); ?></span></td>
                                    <td>
                                        <span class="cell-num"><?php echo number_format($row['pass_rate'], 1); ?>%</span>
                                    </td>
                                    <td>
                                        <span class="grade-badge <?php echo e(gradeClass($row['grade'])); ?>">
                                            <?php echo e($row['grade']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- MOBILE CARDS -->
                <div class="data-card-list">
                    <?php foreach ($class_stats as $cid => $row): ?>
                        <div class="data-card">
                            <div class="data-card-body">
                                <div class="data-card-title"><?php echo e($row['label']); ?></div>
                                <div class="data-card-meta">
                                    <span>
                                        <i class="fa-solid fa-user-graduate"></i>
                                        <?php echo (int)$row['students']; ?> students
                                    </span>
                                    <span>
                                        <i class="fa-solid fa-percent"></i>
                                        <?php echo number_format($row['pass_rate'], 1); ?>% pass
                                    </span>
                                </div>
                            </div>
                            <div class="data-card-right">
                                <div class="data-card-avg">
                                    <?php echo number_format($row['avg'], 1); ?>
                                </div>
                                <span class="grade-badge <?php echo e(gradeClass($row['grade'])); ?>">
                                    <?php echo e($row['grade']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>


<script>
/* =========================================================================
   MOBILE SIDEBAR — handled inside includes/topbar.php
   Nothing to add here.
   ========================================================================= */

/* Auto-submit handled by inline onchange="this.form.submit()" */
</script>

</body>
</html>