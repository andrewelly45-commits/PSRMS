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


/* =========================================================================
   AUTH — Only Academic Master or Headteacher
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
$my_name = trim($me['first_name'] . ' ' . $me['last_name']);

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
    <p>Only the <strong>Academic Master</strong> or <strong>Headteacher</strong> can view the whole-school results.</p>
    <a href="dashboard.php">Back to Dashboard</a>
    </div></body></html>
    <?php
    exit;
}


/* =========================================================================
   ACTIVE ACADEMIC YEAR + TERM
   ========================================================================= */

$active_year    = null;
$active_year_id = 0;

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

$active_term      = null;
$active_term_id   = 0;
$active_term_name = '';

if ($active_year_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT term_id, term_name
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
        $active_term_id   = (int) $active_term['term_id'];
        $active_term_name = $active_term['term_name'];
    }
}


/* =========================================================================
   LOAD FILTER OPTIONS
   ========================================================================= */

/* Academic years */
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

/* Classes */
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
        $classes[(int)$row['class_id']] = $row;
        $classes[(int)$row['class_id']]['label'] =
            $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
    }
}

/* Subjects */
$subjects = [];
$res = mysqli_query(
    $conn,
    "SELECT subject_id, subject_name
     FROM subjects
     WHERE status = 'active'
     ORDER BY subject_name ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $subjects[(int)$row['subject_id']] = $row;
    }
}

/* Teachers */
$teachers = [];
$res = mysqli_query(
    $conn,
    "SELECT t.teacher_id, u.first_name, u.middle_name, u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE u.status = 'active'
     ORDER BY u.first_name ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $full = trim(
            $row['first_name'] . ' ' .
            (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );
        $teachers[(int)$row['teacher_id']] = $full;
    }
}


/* =========================================================================
   FILTERS
   ========================================================================= */

$filter_year    = (int) ($_GET['year_id']    ?? $active_year_id);
$filter_term    = trim($_GET['term']          ?? ($active_term_name ?: 'Term 1'));
$filter_class   = (int) ($_GET['class_id']    ?? 0);
$filter_subject = (int) ($_GET['subject_id']  ?? 0);
$filter_teacher = (int) ($_GET['teacher_id']  ?? 0);

$valid_terms = ['Term 1', 'Term 2', 'Term 3'];
if (!in_array($filter_term, $valid_terms, true)) {
    $filter_term = 'Term 1';
}

if ($filter_class > 0 && !isset($classes[$filter_class]))    $filter_class = 0;
if ($filter_subject > 0 && !isset($subjects[$filter_subject])) $filter_subject = 0;
if ($filter_teacher > 0 && !isset($teachers[$filter_teacher])) $filter_teacher = 0;

$drill_class = (int) ($_GET['view_class'] ?? 0);
if ($drill_class > 0 && !isset($classes[$drill_class])) $drill_class = 0;


/* =========================================================================
   BUILD OVERVIEW QUERY — Aggregated per class
   ========================================================================= */

$overview = [];  // [class_id => [...stats...]]

if ($filter_year > 0) {

    $where = "r.academic_year_id = ? AND r.term = ?";
    $params = [$filter_year, $filter_term];
    $types  = 'is';

    if ($filter_class > 0) {
        $where .= " AND r.class_id = ?";
        $params[] = $filter_class;
        $types .= 'i';
    }
    if ($filter_subject > 0) {
        $where .= " AND r.subject_id = ?";
        $params[] = $filter_subject;
        $types .= 'i';
    }
    if ($filter_teacher > 0) {
        $where .= " AND r.teacher_id = ?";
        $params[] = $filter_teacher;
        $types .= 'i';
    }

    $sql = "
        SELECT
            r.class_id,
            COUNT(*) AS total_results,
            COUNT(DISTINCT r.student_id) AS total_students,
            COUNT(DISTINCT r.subject_id) AS total_subjects,
            AVG(r.marks) AS avg_marks,
            MIN(r.marks) AS min_marks,
            MAX(r.marks) AS max_marks,
            SUM(r.marks >= 40) AS pass_count,
            SUM(r.marks <  40) AS fail_count,
            SUM(r.marks >= 80) AS a_count,
            SUM(r.marks >= 70 AND r.marks < 80) AS b_count,
            SUM(r.marks >= 60 AND r.marks < 70) AS c_count,
            SUM(r.marks >= 50 AND r.marks < 60) AS d_count,
            SUM(r.marks >= 40 AND r.marks < 50) AS e_count,
            SUM(r.marks <  40) AS f_count
        FROM results r
        WHERE $where
        GROUP BY r.class_id
        ORDER BY r.class_id ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $cid = (int)$row['class_id'];
            $total = (int)$row['total_results'];
            $pass  = (int)$row['pass_count'];

            $overview[$cid] = [
                'class_id'       => $cid,
                'total_results'  => $total,
                'total_students' => (int)$row['total_students'],
                'total_subjects' => (int)$row['total_subjects'],
                'avg_marks'      => $total > 0 ? round((float)$row['avg_marks'], 1) : 0,
                'min_marks'      => $total > 0 ? round((float)$row['min_marks'], 1) : 0,
                'max_marks'      => $total > 0 ? round((float)$row['max_marks'], 1) : 0,
                'pass_count'     => $pass,
                'fail_count'     => (int)$row['fail_count'],
                'pass_rate'      => $total > 0 ? round(($pass / $total) * 100, 1) : 0,
                'a_count'        => (int)$row['a_count'],
                'b_count'        => (int)$row['b_count'],
                'c_count'        => (int)$row['c_count'],
                'd_count'        => (int)$row['d_count'],
                'e_count'        => (int)$row['e_count'],
                'f_count'        => (int)$row['f_count'],
            ];
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   SCHOOL-WIDE STATS
   ========================================================================= */

$school = [
    'total_results'  => 0,
    'total_students' => 0,
    'total_classes'  => count($overview),
    'avg_marks'      => 0,
    'pass_rate'      => 0,
    'best_class'     => null,
    'worst_class'    => null,
];

$total_for_avg = 0;
$total_weight  = 0;

foreach ($overview as $cid => $row) {
    $school['total_results']  += $row['total_results'];
    $school['total_students'] += $row['total_students'];

    $total_for_avg += $row['avg_marks'] * $row['total_results'];
    $total_weight  += $row['total_results'];

    if ($school['best_class'] === null || $row['avg_marks'] > $school['best_class']['avg_marks']) {
        $school['best_class'] = $row;
    }
    if ($school['worst_class'] === null || $row['avg_marks'] < $school['worst_class']['avg_marks']) {
        $school['worst_class'] = $row;
    }
}

if ($total_weight > 0) {
    $school['avg_marks'] = round($total_for_avg / $total_weight, 1);
}

$total_pass = 0;
$total_fail = 0;
foreach ($overview as $row) {
    $total_pass += $row['pass_count'];
    $total_fail += $row['fail_count'];
}
$total_all = $total_pass + $total_fail;
$school['pass_rate'] = $total_all > 0 ? round(($total_pass / $total_all) * 100, 1) : 0;


/* =========================================================================
   DRILL-DOWN — Student rankings for a specific class
   ========================================================================= */

$drill_rows     = [];
$drill_summary  = null;

if ($drill_class > 0 && $filter_year > 0) {

    $where = "r.academic_year_id = ? AND r.term = ? AND r.class_id = ?";
    $params = [$filter_year, $filter_term, $drill_class];
    $types  = 'isi';

    if ($filter_subject > 0) {
        $where .= " AND r.subject_id = ?";
        $params[] = $filter_subject;
        $types .= 'i';
    }
    if ($filter_teacher > 0) {
        $where .= " AND r.teacher_id = ?";
        $params[] = $filter_teacher;
        $types .= 'i';
    }

    $sql = "
        SELECT
            s.student_id,
            s.full_name,
            s.admission_no,
            s.photo,
            COUNT(r.result_id) AS subjects_count,
            AVG(r.marks) AS avg_marks,
            SUM(r.marks) AS total_marks,
            MIN(r.marks) AS min_marks,
            MAX(r.marks) AS max_marks
        FROM students s
        LEFT JOIN results r ON r.student_id = s.student_id AND $where
        WHERE s.class_id = ? AND s.status = 'active'
        GROUP BY s.student_id
        ORDER BY avg_marks DESC, s.full_name ASC
    ";

    $params[] = $drill_class;
    $types .= 'i';

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['avg_marks'] = $row['avg_marks'] !== null ? round((float)$row['avg_marks'], 1) : null;
            $drill_rows[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    /* Rank rows */
    $rank = 0;
    foreach ($drill_rows as &$dr) {
        if ($dr['avg_marks'] !== null) {
            $rank++;
            $dr['rank'] = $rank;
            $dr['grade'] = gradeFromMarks($dr['avg_marks']);
        } else {
            $dr['rank'] = null;
            $dr['grade'] = null;
        }
    }
    unset($dr);

    /* Class summary */
    if (!empty($drill_rows)) {
        $drill_summary = $overview[$drill_class] ?? null;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>School Results | PSRMS</title>

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
            grid-template-columns: repeat(3, 1fr) auto;
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

        .filter-actions {
            display: flex;
            gap: 8px;
            grid-column: 1 / -1;
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

        /* SECTION */
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

        /* CLASS OVERVIEW CARDS */
        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            padding: 20px;
        }

        .class-card {
            background: #fcfcfd;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            transition: .18s ease;
            cursor: pointer;
            text-decoration: none;
            display: block;
            color: inherit;
        }

        .class-card:hover {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 10px 24px rgba(23,35,60,.06);
            transform: translateY(-2px);
        }

        .class-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px dashed #eef0f3;
        }

        .class-name {
            color: var(--navy);
            font-size: 14.5px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .class-badge {
            background: var(--navy);
            color: var(--gold-light);
            font-size: 10px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .class-avg {
            text-align: center;
            padding: 8px 0 14px;
        }

        .class-avg .num {
            color: var(--navy);
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .class-avg .lbl {
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-top: 6px;
            font-weight: 700;
        }

        .class-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding-top: 12px;
            border-top: 1px dashed #eef0f3;
        }

        .class-stat {
            font-size: 11.5px;
            color: var(--muted);
        }

        .class-stat strong {
            color: var(--navy);
            font-weight: 750;
        }

        /* PASS RATE BAR */
        .pass-bar {
            margin-top: 12px;
        }

        .pass-bar-label {
            display: flex;
            justify-content: space-between;
            font-size: 10.5px;
            color: var(--muted);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .pass-bar-track {
            height: 6px;
            background: #eef0f3;
            border-radius: 6px;
            overflow: hidden;
        }

        .pass-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--green) 0%, #5ba677 100%);
            border-radius: 6px;
            transition: width .4s ease;
        }

        .pass-bar-fill.warn { background: linear-gradient(90deg, var(--orange) 0%, #c9a227 100%); }
        .pass-bar-fill.bad  { background: linear-gradient(90deg, var(--red) 0%, #c45a5a 100%); }

        /* GRADE DISTRIBUTION MINI */
        .grade-mini {
            display: flex;
            gap: 4px;
            margin-top: 12px;
        }

        .grade-mini-pill {
            flex: 1;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10.5px;
            font-weight: 800;
        }

        .grade-mini-pill.a { background: var(--green-bg); color: var(--green); }
        .grade-mini-pill.b { background: var(--blue-bg);  color: var(--blue); }
        .grade-mini-pill.c { background: #fdf5dd;         color: var(--orange); }
        .grade-mini-pill.d { background: var(--orange-bg);color: var(--orange); }
        .grade-mini-pill.f { background: var(--red-bg);   color: var(--red); }

        /* DRILL TABLE */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.drill-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        table.drill-table thead th {
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

        table.drill-table tbody td {
            padding: 11px 14px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
        }

        table.drill-table tbody tr:last-child td { border-bottom: none; }
        table.drill-table tbody tr:hover { background: #fbfbf8; }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #eef0f5;
            color: var(--navy);
            font-size: 12px;
            font-weight: 800;
        }

        .rank-badge.top1 { background: var(--gold); color: var(--navy); }
        .rank-badge.top2 { background: #c7cdd6; color: var(--navy); }
        .rank-badge.top3 { background: #e0b47e; color: var(--navy); }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 11px;
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

        .student-name {
            color: var(--navy);
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .student-adm {
            color: var(--muted);
            font-size: 10.5px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .marks-cell {
            font-size: 13.5px;
            font-weight: 750;
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

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

        .grade-a { background: var(--green-bg);  color: var(--green);  }
        .grade-b { background: var(--blue-bg);   color: var(--blue);   }
        .grade-c { background: #fdf5dd;          color: var(--orange); }
        .grade-d { background: var(--orange-bg); color: var(--orange); }
        .grade-f { background: var(--red-bg);    color: var(--red);    }
        .grade-na{ background: #eef0f5;          color: var(--muted);  }

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

        /* BREADCRUMB */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--navy);
            text-decoration: none;
            font-weight: 700;
        }

        .breadcrumb a:hover { color: var(--gold); }

        /* =========================================================
           RESPONSIVE — TABLET (≤1100px)
        ========================================================= */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
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

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
                min-height: 46px;
            }

            .section-header { padding: 14px 16px; }
            .section-header h2 { font-size: 13px; }

            .class-grid {
                grid-template-columns: 1fr;
                padding: 16px;
                gap: 12px;
            }

            /* Table → Cards for drill-down */
            .table-wrapper { display: none; }
            .drill-card-list { display: block; }
        }

        /* DRILL CARD LIST (mobile) */
        .drill-card-list { display: none; }

        .drill-card {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .drill-card:last-child { border-bottom: none; }

        .drill-card-rank {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eef0f5;
            color: var(--navy);
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .drill-card-rank.top1 { background: var(--gold); color: var(--navy); }
        .drill-card-rank.top2 { background: #c7cdd6; color: var(--navy); }
        .drill-card-rank.top3 { background: #e0b47e; color: var(--navy); }

        .drill-card-body { flex: 1; min-width: 0; }

        .drill-card-name {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 3px;
            overflow-wrap: anywhere;
        }

        .drill-card-meta {
            color: var(--muted);
            font-size: 11px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .drill-card-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .drill-card-grade {
            flex-shrink: 0;
            text-align: center;
        }

        .drill-card-avg {
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
            .stat-card  { padding: 11px; flex-direction: column; align-items: flex-start; gap: 6px; }
            .stat-icon  { width: 32px; height: 32px; font-size: 13px; }
            .stat-card .value { font-size: 16px; }

            .class-card { padding: 14px; }
            .class-avg .num { font-size: 26px; }
            .class-badge { font-size: 9px; padding: 3px 8px; }

            .grade-mini-pill { height: 24px; font-size: 10px; }

            .drill-card { padding: 12px 14px; gap: 10px; }
            .drill-card-rank { width: 34px; height: 34px; font-size: 12px; }
            .drill-card-avg { font-size: 14px; }
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
$topbar_title    = 'School Results';
$topbar_subtitle = 'Academic overview';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-chart-column"></i> School Results</h1>
            <p>Overall academic performance across all classes and subjects.</p>
        </div>
    </div>

    <?php if ($filter_year <= 0): ?>

        <div class="empty">
            <div class="empty-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
            <h3>No active academic year</h3>
            <p>Set an active academic year before viewing results.</p>
        </div>

    <?php else: ?>

        <!-- SCHOOL-WIDE STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-school"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Classes</div>
                    <div class="value"><?php echo number_format($school['total_classes']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Students</div>
                    <div class="value"><?php echo number_format($school['total_students']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold">
                    <i class="fa-solid fa-percent"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Average</div>
                    <div class="value"><?php echo number_format($school['avg_marks'], 1); ?>%</div>
                    <div class="sub"><?php echo number_format($school['total_results']); ?> results</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fa-solid fa-thumbs-up"></i>
                </div>
                <div class="stat-body">
                    <div class="label">Pass Rate</div>
                    <div class="value"><?php echo number_format($school['pass_rate'], 1); ?>%</div>
                    <div class="sub"><?php echo number_format($total_pass); ?> pass · <?php echo number_format($total_fail); ?> fail</div>
                </div>
            </div>
        </div>

        <!-- FILTERS -->
        <form method="GET" action="school_results.php" class="filter-panel">
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
                    <label>Teacher (optional)</label>
                    <select name="teacher_id" class="filter-control" onchange="this.form.submit()">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $tid => $tname): ?>
                            <option value="<?php echo $tid; ?>"
                                <?php echo $filter_teacher === $tid ? 'selected' : ''; ?>>
                                <?php echo e($tname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-filter"></i> Apply Filters
                    </button>
                    <a href="school_results.php" class="btn btn-ghost">
                        <i class="fa-solid fa-rotate-left"></i> Clear
                    </a>
                </div>

            </div>
        </form>

        <?php if ($drill_class > 0): ?>

            <!-- =========================================================
                 DRILL-DOWN — Class view
            ========================================================= -->
            <div class="breadcrumb">
                <a href="school_results.php?year_id=<?php echo $filter_year; ?>&term=<?php echo e($filter_term); ?>">
                    <i class="fa-solid fa-arrow-left"></i> All Classes
                </a>
                <span>/</span>
                <span><?php echo e($classes[$drill_class]['label'] ?? ''); ?></span>
            </div>

            <?php if ($drill_summary): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <div class="stat-body">
                            <div class="label">Students</div>
                            <div class="value"><?php echo number_format($drill_summary['total_students']); ?></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gold">
                            <i class="fa-solid fa-percent"></i>
                        </div>
                        <div class="stat-body">
                            <div class="label">Class Average</div>
                            <div class="value"><?php echo number_format($drill_summary['avg_marks'], 1); ?>%</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fa-solid fa-thumbs-up"></i>
                        </div>
                        <div class="stat-body">
                            <div class="label">Pass Rate</div>
                            <div class="value"><?php echo number_format($drill_summary['pass_rate'], 1); ?>%</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                        <div class="stat-body">
                            <div class="label">Best Score</div>
                            <div class="value"><?php echo number_format($drill_summary['max_marks'], 1); ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <section class="section-block">
                <div class="section-header">
                    <h2>
                        <i class="fa-solid fa-ranking-star"></i>
                        Student Rankings
                    </h2>
                    <span class="meta">
                        <?php echo count($drill_rows); ?> student<?php echo count($drill_rows) === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <?php if (empty($drill_rows)): ?>

                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-users-slash"></i></div>
                        <h3>No students in this class</h3>
                        <p>Or no results recorded yet for this term.</p>
                    </div>

                <?php else: ?>

                    <!-- DESKTOP TABLE -->
                    <div class="table-wrapper">
                        <table class="drill-table">
                            <thead>
                                <tr>
                                    <th style="width:70px;">Rank</th>
                                    <th>Student</th>
                                    <th style="width:110px;">Average</th>
                                    <th style="width:90px;">Grade</th>
                                    <th style="width:110px;">Subjects</th>
                                    <th style="width:100px;">Best</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drill_rows as $dr):
                                    $rank       = $dr['rank'] ?? null;
                                    $avg        = $dr['avg_marks'];
                                    $grade      = $dr['grade'] ?? null;
                                    $initial    = strtoupper(mb_substr($dr['full_name'], 0, 1));
                                    $rankClass  = '';
                                    if ($rank === 1) $rankClass = 'top1';
                                    elseif ($rank === 2) $rankClass = 'top2';
                                    elseif ($rank === 3) $rankClass = 'top3';
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($rank): ?>
                                                <span class="rank-badge <?php echo $rankClass; ?>">
                                                    <?php echo $rank; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="rank-badge">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="student-cell">
                                                <?php if (!empty($dr['photo']) && is_file(__DIR__ . '/../uploads/students/' . $dr['photo'])): ?>
                                                    <div class="student-avatar">
                                                        <img src="../uploads/students/<?php echo e($dr['photo']); ?>" alt="" loading="lazy">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="student-avatar"><?php echo e($initial); ?></div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="student-name"><?php echo e($dr['full_name']); ?></div>
                                                    <div class="student-adm"><?php echo e($dr['admission_no']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($avg !== null): ?>
                                                <span class="marks-cell"><?php echo number_format($avg, 1); ?></span>
                                            <?php else: ?>
                                                <span style="color:#b0b6c1;font-style:italic;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($grade): ?>
                                                <span class="grade-badge <?php echo e(gradeClass($grade)); ?>">
                                                    <?php echo e($grade); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="grade-badge grade-na">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="marks-cell"><?php echo (int)$dr['subjects_count']; ?></span></td>
                                        <td>
                                            <?php if ($dr['max_marks'] !== null): ?>
                                                <span class="marks-cell"><?php echo number_format((float)$dr['max_marks'], 1); ?></span>
                                            <?php else: ?>
                                                <span style="color:#b0b6c1;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- MOBILE CARDS -->
                    <div class="drill-card-list">
                        <?php foreach ($drill_rows as $dr):
                            $rank      = $dr['rank'] ?? null;
                            $avg       = $dr['avg_marks'];
                            $grade     = $dr['grade'] ?? null;
                            $rankClass = '';
                            if ($rank === 1) $rankClass = 'top1';
                            elseif ($rank === 2) $rankClass = 'top2';
                            elseif ($rank === 3) $rankClass = 'top3';
                        ?>
                            <div class="drill-card">

                                <div class="drill-card-rank <?php echo $rankClass; ?>">
                                    <?php echo $rank ?: '—'; ?>
                                </div>

                                <div class="drill-card-body">
                                    <div class="drill-card-name"><?php echo e($dr['full_name']); ?></div>
                                    <div class="drill-card-meta">
                                        <span>
                                            <i class="fa-solid fa-hashtag"></i>
                                            <?php echo e($dr['admission_no']); ?>
                                        </span>
                                        <span>
                                            <i class="fa-solid fa-book"></i>
                                            <?php echo (int)$dr['subjects_count']; ?> subject<?php echo (int)$dr['subjects_count'] === 1 ? '' : 's'; ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="drill-card-grade">
                                    <div class="drill-card-avg">
                                        <?php echo $avg !== null ? number_format($avg, 1) : '—'; ?>
                                    </div>
                                    <?php if ($grade): ?>
                                        <span class="grade-badge <?php echo e(gradeClass($grade)); ?>">
                                            <?php echo e($grade); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="grade-badge grade-na">—</span>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </section>

        <?php else: ?>

            <!-- =========================================================
                 OVERVIEW — All classes
            ========================================================= -->
            <section class="section-block">
                <div class="section-header">
                    <h2>
                        <i class="fa-solid fa-school"></i>
                        All Classes
                    </h2>
                    <span class="meta">
                        <?php echo count($overview); ?> class<?php echo count($overview) === 1 ? '' : 'es'; ?>
                        · <?php echo e($filter_term); ?>
                    </span>
                </div>

                <?php if (empty($overview)): ?>

                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                        <h3>No results recorded</h3>
                        <p>There are no exam results for the selected filters.</p>
                    </div>

                <?php else: ?>

                    <div class="class-grid">
                        <?php foreach ($overview as $cid => $row):
                            $class = $classes[$cid] ?? null;
                            if (!$class) continue;

                            $avg = $row['avg_marks'];
                            $passRate = $row['pass_rate'];
                            $barClass = '';
                            if ($passRate < 50) $barClass = 'bad';
                            elseif ($passRate < 75) $barClass = 'warn';

                            /* Grade distribution mini (A..F) */
                            $total = max(1, $row['total_results']);
                            $aPct = round(($row['a_count'] / $total) * 100);
                            $bPct = round(($row['b_count'] / $total) * 100);
                            $cPct = round(($row['c_count'] / $total) * 100);
                            $dPct = round(($row['d_count'] / $total) * 100);
                            $fPct = round(($row['f_count'] / $total) * 100);
                        ?>
                            <a class="class-card"
                               href="school_results.php?year_id=<?php echo $filter_year; ?>&term=<?php echo e($filter_term); ?>&teacher_id=<?php echo $filter_teacher; ?>&view_class=<?php echo $cid; ?>">

                                <div class="class-card-top">
                                    <div class="class-name"><?php echo e($class['label']); ?></div>
                                    <span class="class-badge"><?php echo (int)$row['total_students']; ?> students</span>
                                </div>

                                <div class="class-avg">
                                    <div class="num"><?php echo number_format($avg, 1); ?></div>
                                    <div class="lbl">Average</div>
                                </div>

                                <div class="pass-bar">
                                    <div class="pass-bar-label">
                                        <span>Pass Rate</span>
                                        <span><strong><?php echo number_format($passRate, 1); ?>%</strong></span>
                                    </div>
                                    <div class="pass-bar-track">
                                        <div class="pass-bar-fill <?php echo $barClass; ?>"
                                             style="width:<?php echo min(100, $passRate); ?>%;"></div>
                                    </div>
                                </div>

                                <div class="grade-mini">
                                    <?php if ($aPct > 0): ?>
                                        <div class="grade-mini-pill a" title="A: <?php echo $row['a_count']; ?>">
                                            A <?php echo $row['a_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($bPct > 0): ?>
                                        <div class="grade-mini-pill b" title="B: <?php echo $row['b_count']; ?>">
                                            B <?php echo $row['b_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($cPct > 0): ?>
                                        <div class="grade-mini-pill c" title="C: <?php echo $row['c_count']; ?>">
                                            C <?php echo $row['c_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($dPct > 0): ?>
                                        <div class="grade-mini-pill d" title="D: <?php echo $row['d_count']; ?>">
                                            D <?php echo $row['d_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($fPct > 0): ?>
                                        <div class="grade-mini-pill f" title="F: <?php echo $row['f_count']; ?>">
                                            F <?php echo $row['f_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="class-stats">
                                    <div class="class-stat">
                                        <i class="fa-solid fa-book"></i>
                                        <strong><?php echo (int)$row['total_subjects']; ?></strong> subjects
                                    </div>
                                    <div class="class-stat">
                                        <i class="fa-solid fa-arrow-up"></i>
                                        Best <strong><?php echo number_format($row['max_marks'], 1); ?></strong>
                                    </div>
                                </div>

                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </section>

        <?php endif; ?>

    <?php endif; ?>

</main>


<script>
/* =========================================================================
   MOBILE SIDEBAR — handled inside includes/topbar.php
   Nothing to add here — just making sure nothing conflicts.
   ========================================================================= */

/* Auto-submit on select change is handled inline with onchange="this.form.submit()" */
</script>

</body>
</html>