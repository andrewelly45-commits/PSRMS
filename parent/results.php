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

function gradeColor(string $grade): string
{
    $g = strtoupper(trim($grade));
    if (in_array($g, ['A', 'A+', 'A-'], true))              return 'grade-a';
    if (in_array($g, ['B', 'B+', 'B-'], true))              return 'grade-b';
    if (in_array($g, ['C', 'C+', 'C-'], true))              return 'grade-c';
    if (in_array($g, ['D', 'D+', 'D-'], true))              return 'grade-d';
    if (in_array($g, ['E', 'F'], true))                     return 'grade-f';
    return 'grade-na';
}

function gradeFromMarks(float $marks): string
{
    if ($marks >= 80) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 60) return 'C';
    if ($marks >= 50) return 'D';
    if ($marks >= 40) return 'E';
    return 'F';
}

function gradeRemark(string $grade): string
{
    switch (strtoupper(trim($grade))) {
        case 'A': case 'A+': case 'A-': return 'Excellent';
        case 'B': case 'B+': case 'B-': return 'Very Good';
        case 'C': case 'C+': case 'C-': return 'Good';
        case 'D': case 'D+': case 'D-': return 'Satisfactory';
        case 'E': return 'Pass';
        case 'F': return 'Fail';
        default:  return '—';
    }
}


/* =========================================================================
   LOAD PARENT
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id, u.first_name, u.middle_name, u.last_name,
        u.email, u.phone, u.profile_pic,
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

$parent_id = (int) $parent['parent_id'];


/* =========================================================================
   LOAD CHILDREN
   ========================================================================= */

$children = [];

$sql = "
    SELECT
        s.student_id,
        s.admission_no,
        s.full_name,
        s.photo,
        s.gender,
        s.status AS student_status,
        c.class_id,
        c.class_name,
        c.stream,
        c.class_level
    FROM parent_children pc
    INNER JOIN students s ON s.student_id = pc.student_id
    LEFT JOIN classes  c  ON c.class_id   = s.class_id
    WHERE pc.parent_id = ?
    ORDER BY c.class_level ASC, s.full_name ASC
";

$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $parent_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $children[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$children_by_id = [];
foreach ($children as $c) {
    $children_by_id[(int)$c['student_id']] = $c;
}


/* =========================================================================
   FILTERS
   ========================================================================= */

$filter_student = (int) ($_GET['student_id'] ?? 0);
$filter_term    = trim($_GET['term'] ?? '');
$filter_year    = (int) ($_GET['year_id'] ?? 0);

if ($filter_student > 0 && !isset($children_by_id[$filter_student])) {
    $filter_student = 0;
}

/* If parent has only one child, auto-select */
if ($filter_student === 0 && count($children) === 1) {
    $filter_student = (int) $children[0]['student_id'];
}


/* =========================================================================
   LOAD ACADEMIC YEARS (for filter)
   ========================================================================= */

$academic_years = [];
if (tableExists($conn, 'academic_years')) {
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
}

$valid_terms = ['Term 1', 'Term 2', 'Term 3'];


/* =========================================================================
   LOAD RESULTS
   ========================================================================= */

$results = [];

if (!empty($children) && tableExists($conn, 'results')) {

    $child_ids    = array_column($children, 'student_id');
    $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
    $types        = str_repeat('i', count($child_ids));

    $sql = "
        SELECT
            r.result_id,
            r.student_id,
            r.class_id,
            r.subject_id,
            r.teacher_id,
            r.academic_year_id,
            r.term,
            r.marks,
            r.grade,
            r.remarks,
            r.created_at,
            s.subject_name,
            s.subject_type,
            c.class_name,
            c.stream,
            ay.year AS academic_year
        FROM results r
        LEFT JOIN subjects       s  ON s.subject_id       = r.subject_id
        LEFT JOIN classes        c  ON c.class_id         = r.class_id
        LEFT JOIN academic_years ay ON ay.academic_year_id = r.academic_year_id
        WHERE r.student_id IN ($placeholders)
    ";

    $params = $child_ids;

    if ($filter_student > 0) {
        $sql .= " AND r.student_id = ? ";
        $params[] = $filter_student;
        $types   .= 'i';
    }

    if ($filter_term !== '' && in_array($filter_term, $valid_terms, true)) {
        $sql .= " AND r.term = ? ";
        $params[] = $filter_term;
        $types   .= 's';
    }

    if ($filter_year > 0) {
        $sql .= " AND r.academic_year_id = ? ";
        $params[] = $filter_year;
        $types   .= 'i';
    }

    $sql .= "
        ORDER BY
            r.academic_year_id DESC,
            FIELD(r.term, 'Term 3', 'Term 2', 'Term 1') DESC,
            c.class_level ASC,
            s.subject_name ASC,
            r.marks DESC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $results[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   GROUP RESULTS:  by student → year → term
   ========================================================================= */

$grouped = [];

foreach ($results as $r) {
    $sid  = (int) $r['student_id'];
    $year = $r['academic_year'] ?: 'Unknown Year';
    $term = $r['term']          ?: 'Unknown Term';
    $yid  = (int) $r['academic_year_id'];

    $grouped[$sid][$yid]['year'] = $year;
    $grouped[$sid][$yid]['terms'][$term][] = $r;
}


/* =========================================================================
   COMPUTE STATS PER TERM (average, best, worst)
   ========================================================================= */

function term_stats(array $rows): array
{
    $marks = array_map(fn($r) => (float) $r['marks'], $rows);
    if (empty($marks)) {
        return ['avg' => 0, 'best' => 0, 'worst' => 0, 'count' => 0];
    }
    return [
        'avg'   => round(array_sum($marks) / count($marks), 2),
        'best'  => max($marks),
        'worst' => min($marks),
        'count' => count($marks),
    ];
}


/* =========================================================================
   OVERALL STATS
   ========================================================================= */

$total_results = count($results);

$all_marks = array_map(fn($r) => (float) $r['marks'], $results);
$overall_avg = !empty($all_marks) ? round(array_sum($all_marks) / count($all_marks), 2) : 0;

$unique_subjects = [];
foreach ($results as $r) {
    if (!empty($r['subject_id'])) $unique_subjects[(int)$r['subject_id']] = true;
}
$subject_count = count($unique_subjects);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Results | PSRMS Parent</title>

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
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title h1 i {
            color: var(--gold);
            font-size: 22px;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 6px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 18px;
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

        .btn-gold {
            background: var(--gold);
            color: var(--navy);
        }
        .btn-gold:hover { background: var(--gold-light); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { border-color: var(--gold); }

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
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-icon.gold  { background: var(--gold-light); color: var(--navy); }
        .stat-icon.green { background: var(--green-bg);   color: var(--green); }

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
            font-size: 22px;
            font-weight: 750;
            margin-top: 3px;
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
            grid-template-columns: 1.4fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: end;
        }

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

        /* CHILD TABS */
        .child-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .child-tab {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 9px 16px;
            border-radius: 30px;
            background: var(--white);
            border: 1px solid var(--border);
            font-size: 12.5px;
            font-weight: 700;
            color: var(--navy);
            text-decoration: none;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .child-tab:hover {
            border-color: var(--gold);
        }

        .child-tab.active {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }

        .child-tab-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--gold);
            color: var(--navy);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10.5px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .child-tab.active .child-tab-avatar {
            background: var(--gold-light);
        }

        /* RESULT CARDS */
        .results-wrapper {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .year-group {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .year-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 22px;
            background: linear-gradient(90deg, var(--navy) 0%, var(--navy-dark) 100%);
            color: var(--white);
            flex-wrap: wrap;
        }

        .year-header h2 {
            font-size: 15px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .year-header h2 i {
            color: var(--gold-light);
            font-size: 14px;
        }

        .year-header .year-count {
            background: rgba(255,255,255,.15);
            color: var(--gold-light);
            font-size: 11px;
            font-weight: 750;
            padding: 4px 12px;
            border-radius: 20px;
        }

        /* TERM SECTION */
        .term-section {
            border-bottom: 1px solid var(--border);
        }

        .term-section:last-child { border-bottom: none; }

        .term-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 22px;
            background: #fcfcfa;
            flex-wrap: wrap;
        }

        .term-header h3 {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .term-header h3 i {
            color: var(--gold);
            font-size: 13px;
        }

        .term-stats {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 11px;
            color: var(--muted);
        }

        .term-stats span strong {
            color: var(--navy);
            font-weight: 750;
        }

        /* TABLE */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.results-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        table.results-table thead th {
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            padding: 11px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        table.results-table tbody td {
            padding: 13px 16px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
        }

        table.results-table tbody tr:last-child td { border-bottom: none; }
        table.results-table tbody tr:hover { background: #fbfbf8; }

        .subject-cell {
            color: var(--navy);
            font-weight: 700;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .subject-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .subject-icon.gold  { background: var(--gold-light); color: var(--navy); }
        .subject-icon.green { background: var(--green-bg);   color: var(--green); }

        .marks-cell {
            font-size: 14px;
            font-weight: 750;
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .marks-cell small {
            color: var(--muted);
            font-size: 10px;
            font-weight: 600;
            font-family: inherit;
            margin-left: 3px;
        }

        /* GRADE BADGE */
        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 32px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .4px;
        }

        .grade-a  { background: var(--green-bg);  color: var(--green);  }
        .grade-b { background: var(--blue-bg);   color: var(--blue);   }
        .grade-c { background: #fdf5dd;          color: var(--orange); }
        .grade-d { background: var(--orange-bg); color: var(--orange); }
        .grade-f { background: var(--red-bg);    color: var(--red);    }
        .grade-na{ background: #eef0f5;          color: var(--muted);  }

        .remark-text {
            color: var(--muted);
            font-size: 11.5px;
            line-height: 1.5;
            max-width: 220px;
        }

        /* MOBILE CARD LIST */
        .card-list { display: none; }

        .result-card {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
        }

        .result-card:last-child { border-bottom: none; }

        .result-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .result-card-subject {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 750;
            margin-bottom: 2px;
            overflow-wrap: anywhere;
        }

        .result-card-meta {
            color: var(--muted);
            font-size: 11px;
        }

        .result-card-marks {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 12px;
            background: #fafbfd;
            border-radius: 8px;
            margin-bottom: 6px;
        }

        .result-card-marks .m-val {
            font-size: 16px;
            font-weight: 750;
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .result-card-remarks {
            font-size: 11.5px;
            color: var(--muted);
            line-height: 1.5;
            padding-left: 4px;
        }

        /* EMPTY */
        .empty {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 60px 24px;
            text-align: center;
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
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1.55;
        }

        /* RESPONSIVE */
        @media (max-width: 1000px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 14px; }
            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .header-actions .btn {
                flex: 1;
                min-height: 46px;
                font-size: 13.5px;
            }

            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }

            .stat-card {
                flex-direction: column;
                align-items: flex-start;
                padding: 12px;
                gap: 8px;
            }
            .stat-icon { width: 36px; height: 36px; font-size: 14px; }
            .stat-card .value { font-size: 17px; }

            .filter-form { grid-template-columns: 1fr; gap: 12px; }
            .filter-control { height: 46px; font-size: 14px; }
            .filter-form .btn { width: 100%; min-height: 46px; }

            .year-header { padding: 13px 16px; }
            .year-header h2 { font-size: 14px; }

            .term-header { padding: 12px 16px; }
            .term-header h3 { font-size: 13px; }
            .term-stats { font-size: 10.5px; gap: 10px; }

            /* Table → cards */
            .table-wrapper { display: none; }
            .card-list { display: block; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stats-grid .stat-card:nth-child(3) { grid-column: 1 / -1; }

            .stat-card { padding: 11px; }
            .stat-card .value { font-size: 16px; }
            .stat-card .label { font-size: 9px; }

            .child-tab { padding: 8px 12px; font-size: 12px; }
            .child-tab-avatar { width: 22px; height: 22px; font-size: 10px; }
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

        /* PRINT */
        @media print {
            .main-content { margin-left: 0; padding: 0; }
            .page-header .header-actions,
            .filter-panel,
            .child-tabs,
            .year-header .year-count { display: none !important; }
            .year-group { border: 1px solid #ddd; break-inside: avoid; }
            .year-header { background: #17233c !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Results';
$topbar_subtitle = 'My Children';
include '../includes/topbar.php';
?>

<?php include 'parent_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-chart-line"></i> Exam Results</h1>
            <p>Academic performance records for your children, term by term.</p>
        </div>

        <div class="header-actions">
            <a href="dashboard.php" class="btn btn-ghost">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
            <button type="button" class="btn btn-gold" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-file-lines"></i>
            </div>
            <div class="stat-body">
                <div class="label">Total Results</div>
                <div class="value"><?php echo number_format($total_results); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold">
                <i class="fa-solid fa-percent"></i>
            </div>
            <div class="stat-body">
                <div class="label">Overall Average</div>
                <div class="value"><?php echo number_format($overall_avg, 1); ?>%</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fa-solid fa-book"></i>
            </div>
            <div class="stat-body">
                <div class="label">Subjects Graded</div>
                <div class="value"><?php echo number_format($subject_count); ?></div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" action="results.php" class="filter-panel">
        <div class="filter-form">

            <div class="filter-group">
                <label>Student</label>
                <select name="student_id" class="filter-control">
                    <option value="">All Children</option>
                    <?php foreach ($children as $c): ?>
                        <option value="<?php echo (int)$c['student_id']; ?>"
                            <?php echo $filter_student === (int)$c['student_id'] ? 'selected' : ''; ?>>
                            <?php echo e($c['full_name']); ?>
                            <?php if (!empty($c['class_name'])): ?>
                                — <?php echo e($c['class_name']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Term</label>
                <select name="term" class="filter-control">
                    <option value="">All Terms</option>
                    <?php foreach ($valid_terms as $t): ?>
                        <option value="<?php echo e($t); ?>"
                            <?php echo $filter_term === $t ? 'selected' : ''; ?>>
                            <?php echo e($t); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Academic Year</label>
                <select name="year_id" class="filter-control">
                    <option value="">All Years</option>
                    <?php foreach ($academic_years as $y): ?>
                        <option value="<?php echo (int)$y['academic_year_id']; ?>"
                            <?php echo $filter_year === (int)$y['academic_year_id'] ? 'selected' : ''; ?>>
                            <?php echo e($y['year']); ?>
                            <?php echo strtolower($y['status'] ?? '') === 'active' ? ' (Active)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>

            <?php if ($filter_student || $filter_term || $filter_year): ?>
                <a href="results.php" class="btn btn-ghost">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>

        </div>
    </form>

    <!-- CHILD QUICK TABS -->
    <?php if (count($children) > 1): ?>
        <div class="child-tabs">
            <a href="results.php?<?php echo http_build_query(array_filter([
                'term'    => $filter_term,
                'year_id' => $filter_year ?: null,
            ])); ?>" class="child-tab <?php echo $filter_student === 0 ? 'active' : ''; ?>">
                <i class="fa-solid fa-users"></i> All
            </a>

            <?php foreach ($children as $c):
                $initial = strtoupper(mb_substr($c['full_name'], 0, 1));
                $is_active = $filter_student === (int)$c['student_id'];
            ?>
                <a href="results.php?<?php echo http_build_query(array_filter([
                    'student_id' => (int)$c['student_id'],
                    'term'       => $filter_term,
                    'year_id'    => $filter_year ?: null,
                ])); ?>"
                   class="child-tab <?php echo $is_active ? 'active' : ''; ?>">
                    <span class="child-tab-avatar"><?php echo e($initial); ?></span>
                    <?php echo e($c['full_name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- RESULTS -->
    <?php if (empty($results)): ?>

        <div class="empty">
            <div class="empty-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
            <h3>No results found</h3>
            <p>
                <?php if ($filter_student || $filter_term || $filter_year): ?>
                    No results match the selected filters. Try adjusting or clearing them.
                <?php else: ?>
                    Your children's exam results will appear here once teachers record them.
                <?php endif; ?>
            </p>
        </div>

    <?php else: ?>

        <div class="results-wrapper">

            <?php foreach ($grouped as $sid => $years):

                $child = $children_by_id[$sid] ?? null;
                if (!$child) continue;

                $child_initial = strtoupper(mb_substr($child['full_name'], 0, 1));
                $class_label   = $child['class_name']
                    ? $child['class_name'] . ($child['stream'] ? ' — ' . $child['stream'] : '')
                    : 'No Class';
            ?>

                <div class="year-group">

                    <!-- CHILD HEADER -->
                    <div class="year-header">
                        <h2>
                            <span class="child-tab-avatar" style="width:30px;height:30px;font-size:12px;">
                                <?php echo e($child_initial); ?>
                            </span>
                            <?php echo e($child['full_name']); ?>
                            <span style="opacity:.75;font-size:11.5px;font-weight:600;margin-left:6px;">
                                · <?php echo e($class_label); ?>
                            </span>
                        </h2>
                        <span class="year-count">
                            <i class="fa-solid fa-file-lines"></i>
                            <?php
                            $total = 0;
                            foreach ($years as $y) {
                                foreach ($y['terms'] as $t) {
                                    $total += count($t);
                                }
                            }
                            echo $total;
                            ?>
                        </span>
                    </div>

                    <!-- YEARS -->
                    <?php foreach ($years as $yid => $yearData):
                        $year_label = $yearData['year'];
                        $terms      = $yearData['terms'];
                    ?>

                        <?php foreach ($terms as $term => $rows):

                            $stats = term_stats($rows);
                        ?>
                            <div class="term-section">

                                <!-- TERM HEADER -->
                                <div class="term-header">
                                    <h3>
                                        <i class="fa-solid fa-calendar-check"></i>
                                        <?php echo e($term); ?>
                                        <span style="font-size:11px;color:var(--muted);font-weight:600;">
                                            · <?php echo e($year_label); ?>
                                        </span>
                                    </h3>

                                    <div class="term-stats">
                                        <span>
                                            <i class="fa-solid fa-hashtag"></i>
                                            <strong><?php echo (int)$stats['count']; ?></strong> subjects
                                        </span>
                                        <span>
                                            <i class="fa-solid fa-percent"></i>
                                            Avg <strong><?php echo number_format($stats['avg'], 1); ?>%</strong>
                                        </span>
                                        <span>
                                            <i class="fa-solid fa-arrow-up"></i>
                                            Best <strong><?php echo number_format($stats['best'], 1); ?></strong>
                                        </span>
                                    </div>
                                </div>

                                <!-- DESKTOP TABLE -->
                                <div class="table-wrapper">
                                    <table class="results-table">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th style="width:120px;">Marks</th>
                                                <th style="width:90px;">Grade</th>
                                                <th style="width:130px;">Remark</th>
                                                <th>Teacher's Comment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $r):
                                                $mark_val  = (float) $r['marks'];
                                                $grade_val = $r['grade'] ?: gradeFromMarks($mark_val);
                                                $grade_cls = gradeColor($grade_val);
                                                $remark    = gradeRemark($grade_val);
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="subject-cell">
                                                            <div class="subject-icon">
                                                                <i class="fa-solid fa-book-open"></i>
                                                            </div>
                                                            <?php echo e($r['subject_name'] ?: 'Unknown Subject'); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="marks-cell">
                                                            <?php echo number_format($mark_val, 1); ?><small>/100</small>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="grade-badge <?php echo e($grade_cls); ?>">
                                                            <?php echo e($grade_val); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-weight:700;color:var(--navy);font-size:12px;">
                                                            <?php echo e($remark); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($r['remarks'])): ?>
                                                            <div class="remark-text"><?php echo e($r['remarks']); ?></div>
                                                        <?php else: ?>
                                                            <span style="color:#b0b6c1;font-style:italic;font-size:11.5px;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- MOBILE CARDS -->
                                <div class="card-list">
                                    <?php foreach ($rows as $r):
                                        $mark_val  = (float) $r['marks'];
                                        $grade_val = $r['grade'] ?: gradeFromMarks($mark_val);
                                        $grade_cls = gradeColor($grade_val);
                                        $remark    = gradeRemark($grade_val);
                                    ?>
                                        <div class="result-card">
                                            <div class="result-card-top">
                                                <div style="min-width:0;flex:1;">
                                                    <div class="result-card-subject">
                                                        <?php echo e($r['subject_name'] ?: 'Unknown Subject'); ?>
                                                    </div>
                                                    <div class="result-card-meta">
                                                        <i class="fa-solid fa-calendar"></i>
                                                        <?php echo e($term); ?> · <?php echo e($year_label); ?>
                                                    </div>
                                                </div>
                                                <span class="grade-badge <?php echo e($grade_cls); ?>">
                                                    <?php echo e($grade_val); ?>
                                                </span>
                                            </div>

                                            <div class="result-card-marks">
                                                <span style="color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                                                    Marks
                                                </span>
                                                <span class="m-val">
                                                    <?php echo number_format($mark_val, 1); ?>
                                                    <small style="font-size:11px;color:var(--muted);font-weight:600;">/100</small>
                                                </span>
                                            </div>

                                            <div class="result-card-remarks">
                                                <strong style="color:var(--navy);"><?php echo e($remark); ?></strong>
                                                <?php if (!empty($r['remarks'])): ?>
                                                    — <?php echo e($r['remarks']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            </div>
                        <?php endforeach; ?>

                    <?php endforeach; ?>

                </div>

            <?php endforeach; ?>

        </div>

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