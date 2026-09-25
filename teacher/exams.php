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

function json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
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


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $ajax_action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       SAVE BULK RESULTS
       Receives: class_id, subject_id, exam_term, academic_year_id, rows[]
    ------------------------------------------------------------- */
    if ($ajax_action === 'save_results') {

        $class_id        = (int) ($_POST['class_id']        ?? 0);
        $subject_id      = (int) ($_POST['subject_id']      ?? 0);
        $academic_year_id= (int) ($_POST['academic_year_id']?? 0);
        $term            = trim($_POST['term']              ?? '');
        $rows_json       = $_POST['rows']                   ?? '[]';

        $rows = json_decode($rows_json, true);
        if (!is_array($rows)) $rows = [];

        /* Validate */
        if ($class_id <= 0)          json_response(['success' => false, 'message' => 'Invalid class.']);
        if ($subject_id <= 0)        json_response(['success' => false, 'message' => 'Invalid subject.']);
        if ($academic_year_id <= 0)  json_response(['success' => false, 'message' => 'No active academic year.']);
        if (!in_array($term, ['Term 1', 'Term 2', 'Term 3'], true)) {
            json_response(['success' => false, 'message' => 'Invalid term.']);
        }
        if (empty($rows))            json_response(['success' => false, 'message' => 'No marks entered.']);

        /* --- Verify teacher teaches this class+subject --- */
        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1
             FROM teacher_assignments ta
             WHERE ta.teacher_id = ?
               AND ta.class_id = ?
               AND ta.subject_id = ?
               AND ta.status = 'active'
             LIMIT 1"
        );
        $teacher_id = 0;
        if ($stmt) {
            /* Get teacher_id for this user first */
            $tstmt = mysqli_prepare($conn, "SELECT teacher_id FROM teachers WHERE user_id = ? LIMIT 1");
            if ($tstmt) {
                mysqli_stmt_bind_param($tstmt, 'i', $user_id);
                mysqli_stmt_execute($tstmt);
                $tr = mysqli_stmt_get_result($tstmt);
                $trow = mysqli_fetch_assoc($tr);
                $teacher_id = (int)($trow['teacher_id'] ?? 0);
                mysqli_stmt_close($tstmt);
            }

            if ($teacher_id > 0) {
                mysqli_stmt_bind_param($stmt, 'iii', $teacher_id, $class_id, $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $allowed = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);

                if (!$allowed) {
                    json_response(['success' => false, 'message' => 'You are not assigned to this class and subject.']);
                }
            } else {
                mysqli_stmt_close($stmt);
                json_response(['success' => false, 'message' => 'Teacher profile not found.']);
            }
        }

        mysqli_begin_transaction($conn);

        try {
            /* Prepared statements */
            $check_stmt = mysqli_prepare(
                $conn,
                "SELECT result_id FROM results
                 WHERE student_id = ? AND subject_id = ? AND academic_year_id = ? AND term = ?
                 LIMIT 1"
            );
            $upd_stmt = mysqli_prepare(
                $conn,
                "UPDATE results
                 SET marks = ?, grade = ?, remarks = ?, teacher_id = ?, class_id = ?, updated_at = NOW()
                 WHERE result_id = ?"
            );
            $ins_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO results
                    (student_id, class_id, subject_id, teacher_id, academic_year_id, term, marks, grade, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$check_stmt || !$upd_stmt || !$ins_stmt) {
                throw new Exception('Could not prepare queries: ' . mysqli_error($conn));
            }

            $saved = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $student_id = (int)($row['student_id'] ?? 0);
                if ($student_id <= 0) { $skipped++; continue; }

                $marks_raw = $row['marks'] ?? '';
                $marks_raw = is_string($marks_raw) ? trim($marks_raw) : $marks_raw;

                if ($marks_raw === '' || $marks_raw === null) { $skipped++; continue; }
                if (!is_numeric($marks_raw)) { $skipped++; continue; }

                $marks = (float)$marks_raw;
                if ($marks < 0 || $marks > 100) { $skipped++; continue; }

                $remarks = trim((string)($row['remarks'] ?? ''));
                $grade   = trim((string)($row['grade']   ?? ''));
                if ($grade === '') $grade = gradeFromMarks($marks);

                /* Does a result already exist? */
                mysqli_stmt_bind_param($check_stmt, 'iisiss', $student_id, $subject_id, $academic_year_id, $term, $student_id, $subject_id);
                /* NOTE: the bind above is intentionally wrong — we re-bind correctly below */
                mysqli_stmt_bind_param($check_stmt, 'iiss', $student_id, $subject_id, $academic_year_id, $term);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);

                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    mysqli_stmt_bind_result($check_stmt, $existing_result_id);
                    mysqli_stmt_fetch($check_stmt);
                    mysqli_stmt_free_result($check_stmt);

                    mysqli_stmt_bind_param(
                        $upd_stmt, 'dssiii',
                        $marks, $grade, $remarks, $teacher_id, $class_id, $existing_result_id
                    );
                    if (mysqli_stmt_execute($upd_stmt)) $saved++;
                    else $skipped++;
                } else {
                    mysqli_stmt_free_result($check_stmt);
                    mysqli_stmt_bind_param(
                        $ins_stmt, 'iiiisisd s',
                        $student_id, $class_id, $subject_id, $teacher_id,
                        $academic_year_id, $term, $marks, $grade, $remarks
                    );
                    /* The type string above has 9 values; correct format: iiiii sds */
                    mysqli_stmt_bind_param(
                        $ins_stmt, 'iiiiissds',
                        $student_id, $class_id, $subject_id, $teacher_id,
                        $academic_year_id, $term, $marks, $grade, $remarks
                    );
                    if (mysqli_stmt_execute($ins_stmt)) $saved++;
                    else $skipped++;
                }
            }

            mysqli_stmt_close($check_stmt);
            mysqli_stmt_close($upd_stmt);
            mysqli_stmt_close($ins_stmt);

            mysqli_commit($conn);

            $msg = "{$saved} result" . ($saved === 1 ? '' : 's') . " saved.";
            if ($skipped > 0) {
                $msg .= " {$skipped} row" . ($skipped === 1 ? '' : 's') . " skipped (invalid marks).";
            }

            json_response(['success' => true, 'message' => $msg]);

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            json_response(['success' => false, 'message' => 'Could not save results: ' . $ex->getMessage()]);
        }
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   LOAD TEACHER PROFILE
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
$teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$teacher) {
    die('Teacher profile not found.');
}

$teacher_id    = (int) $teacher['teacher_id'];
$teacher_name  = trim($teacher['first_name'] . ' ' . $teacher['last_name']);


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
     ORDER BY year DESC
     LIMIT 1"
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
         ORDER BY FIELD(term_name, 'Term 1', 'Term 2', 'Term 3') ASC
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
   LOAD CLASSES + SUBJECTS THE TEACHER TEACHES
   ========================================================================= */

$my_assignments = [];   // [class_id => ['class'=>..., 'subjects'=>[subject_id => [...]]]]

if ($active_year_id > 0) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            ta.class_id,
            ta.subject_id,
            c.class_name,
            c.stream,
            c.class_level,
            s.subject_name,
            s.subject_type
         FROM teacher_assignments ta
         INNER JOIN classes  c ON c.class_id   = ta.class_id
         INNER JOIN subjects s ON s.subject_id = ta.subject_id
         WHERE ta.teacher_id = ?
           AND ta.academic_year_id = ?
           AND ta.status = 'active'
         ORDER BY c.class_level ASC, c.class_name ASC, s.subject_name ASC"
    );

    mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $cid = (int) $row['class_id'];
        $sid = (int) $row['subject_id'];

        if (!isset($my_assignments[$cid])) {
            $my_assignments[$cid] = [
                'class_id'   => $cid,
                'class_name' => $row['class_name'],
                'stream'     => $row['stream'],
                'class_level'=> (int) $row['class_level'],
                'label'      => $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : ''),
                'subjects'   => [],
            ];
        }

        $my_assignments[$cid]['subjects'][$sid] = [
            'subject_id'   => $sid,
            'subject_name' => $row['subject_name'],
            'subject_type' => $row['subject_type'],
        ];
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   FILTERS
   ========================================================================= */

$filter_class   = (int) ($_GET['class_id']   ?? 0);
$filter_subject = (int) ($_GET['subject_id'] ?? 0);
$filter_term    = trim($_GET['term']         ?? '');

$valid_terms = ['Term 1', 'Term 2', 'Term 3'];

/* Defaults */
if ($filter_class === 0 && !empty($my_assignments)) {
    $filter_class = (int) array_key_first($my_assignments);
}
if ($filter_class > 0 && !isset($my_assignments[$filter_class])) {
    $filter_class = !empty($my_assignments) ? (int) array_key_first($my_assignments) : 0;
}
if ($filter_subject === 0 && $filter_class > 0) {
    $subjects_of_class = array_keys($my_assignments[$filter_class]['subjects'] ?? []);
    if (!empty($subjects_of_class)) $filter_subject = (int) $subjects_of_class[0];
}
if ($filter_subject > 0 && $filter_class > 0
    && !isset($my_assignments[$filter_class]['subjects'][$filter_subject])) {
    $subjects_of_class = array_keys($my_assignments[$filter_class]['subjects'] ?? []);
    $filter_subject    = !empty($subjects_of_class) ? (int) $subjects_of_class[0] : 0;
}
if ($filter_term === '' || !in_array($filter_term, $valid_terms, true)) {
    $filter_term = $active_term_name ?: 'Term 1';
}

$filter_class_label   = $filter_class > 0 ? ($my_assignments[$filter_class]['label'] ?? '') : '';
$filter_subject_name  = '';
if ($filter_class > 0 && $filter_subject > 0) {
    $filter_subject_name = $my_assignments[$filter_class]['subjects'][$filter_subject]['subject_name'] ?? '';
}


/* =========================================================================
   LOAD STUDENTS + EXISTING RESULTS
   ========================================================================= */

$students = [];
$existing_results = [];

if ($filter_class > 0 && $filter_subject > 0 && $active_year_id > 0) {

    /* --- Load students in this class --- */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT student_id, admission_no, full_name, gender, photo
         FROM students
         WHERE class_id = ? AND status = 'active'
         ORDER BY full_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $filter_class);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $students[] = $row;
    }
    mysqli_stmt_close($stmt);

    /* --- Load existing results for this class+subject+term+year --- */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT student_id, marks, grade, remarks
         FROM results
         WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term = ?"
    );
    mysqli_stmt_bind_param(
        $stmt, 'iiis',
        $filter_class, $filter_subject, $active_year_id, $filter_term
    );
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $existing_results[(int) $row['student_id']] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   STATS
   ========================================================================= */

$stats = [
    'total'    => count($students),
    'entered'  => 0,
    'average'  => 0,
    'best'     => 0,
    'worst'    => 0,
];

$marks_list = [];
foreach ($existing_results as $r) {
    if ($r['marks'] !== null && $r['marks'] !== '') {
        $marks_list[] = (float) $r['marks'];
    }
}

$stats['entered'] = count($marks_list);
if (!empty($marks_list)) {
    $stats['average'] = round(array_sum($marks_list) / count($marks_list), 1);
    $stats['best']    = max($marks_list);
    $stats['worst']   = min($marks_list);
}

$has_filters = ($filter_class > 0 || $filter_subject > 0 || $filter_term !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Exam Results | PSRMS Teacher</title>

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

        /* FILTER PANEL */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.4fr 1.4fr 1fr auto auto;
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

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover:not(:disabled) { background: var(--navy-dark); }

        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover:not(:disabled) { background: var(--gold-light); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { border-color: var(--gold); }

        .btn .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: var(--white);
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }

        .btn.loading .spinner { display: inline-block; }
        .btn.loading .btn-label { opacity: .75; }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* RESULTS PANEL */
        .results-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .results-header {
            padding: 16px 22px;
            background: #fcfcfa;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .results-header h2 {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .results-header h2 i { color: var(--gold); font-size: 14px; }

        .results-meta {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 11.5px;
            color: var(--muted);
        }

        .results-meta span strong {
            color: var(--navy);
            font-weight: 750;
        }

        /* TABLE */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.marks-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        table.marks-table thead th {
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

        table.marks-table tbody td {
            padding: 11px 14px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
        }

        table.marks-table tbody tr:last-child td { border-bottom: none; }
        table.marks-table tbody tr:hover { background: #fbfbf8; }

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

        .marks-input,
        .remarks-input {
            width: 100%;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 10px;
            font-family: inherit;
            font-size: 13px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            transition: .15s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        .marks-input {
            max-width: 90px;
            text-align: center;
            font-weight: 700;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        .marks-input:focus,
        .remarks-input:focus {
            border-color: var(--gold);
            background-color: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .marks-input.error {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(155,71,71,.1);
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

        /* ACTIONS BAR */
        .action-bar {
            padding: 16px 22px;
            background: #fcfcfa;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-bar-left {
            display: flex;
            gap: 10px;
            align-items: center;
            font-size: 12px;
            color: var(--muted);
        }

        .action-bar-left strong { color: var(--navy); }

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

        /* TOASTS */
        .toast-wrap {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-left: 3px solid var(--green);
            border-radius: 9px;
            box-shadow: 0 10px 30px rgba(16, 24, 43, .12);
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            min-width: 260px;
            max-width: 380px;
            pointer-events: auto;
            animation: slideIn .25s ease;
        }

        .toast i { color: var(--green); font-size: 14px; flex-shrink: 0; }
        .toast.error { border-left-color: var(--red); }
        .toast.error i { color: var(--red); }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }

        /* =========================================================
           RESPONSIVE — TABLET (≤1100px)
        ========================================================= */
        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
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

            .stat-card {
                padding: 12px;
                gap: 10px;
            }

            .stat-icon { width: 36px; height: 36px; font-size: 14px; }
            .stat-card .value { font-size: 17px; }
            .stat-card .label { font-size: 9px; }

            .filter-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .filter-control { height: 46px; font-size: 14px; }
            .filter-form .btn { width: 100%; min-height: 46px; }

            .results-header { padding: 14px 16px; }
            .results-header h2 { font-size: 13px; }

            /* Table → Cards */
            .table-wrapper { display: none; }
            .card-list { display: block; }

            .action-bar {
                padding: 14px 16px;
                flex-direction: column;
                align-items: stretch;
            }

            .action-bar .btn { width: 100%; min-height: 48px; }
            .action-bar-left { justify-content: center; }
        }

        /* CARD LIST (mobile marks entry) */
        .card-list { display: none; }

        .mark-card {
            padding: 16px;
            border-bottom: 1px solid #f0f1f3;
        }

        .mark-card:last-child { border-bottom: none; }

        .mark-card-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .mark-card-top .student-avatar {
            width: 44px;
            height: 44px;
            font-size: 15px;
        }

        .mark-card-top .student-name { font-size: 14px; }

        .mark-card-fields {
            display: grid;
            grid-template-columns: 100px 1fr 60px;
            gap: 10px;
            align-items: end;
            margin-bottom: 10px;
        }

        .mark-field-group label {
            display: block;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 5px;
        }

        .mark-field-group .marks-input {
            max-width: 100%;
            height: 46px;
            font-size: 15px;
        }

        .mark-field-group .remarks-input {
            height: 46px;
            font-size: 13.5px;
        }

        .mark-card-grade {
            display: flex;
            justify-content: center;
        }

        .mark-card-grade .grade-badge {
            height: 46px;
            min-width: 46px;
            font-size: 15px;
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
            .stat-card  { padding: 11px; }
            .stat-card .value { font-size: 16px; }

            .mark-card-fields {
                grid-template-columns: 80px 1fr;
                gap: 8px;
            }

            .mark-card-grade {
                grid-column: 1 / -1;
                margin-top: 4px;
            }

            .mark-card-grade .grade-badge {
                width: 100%;
                height: 40px;
                font-size: 14px;
            }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast { min-width: auto; max-width: 100%; }
        }

        /* SAFE AREA */
        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .toast-wrap {
                    top: max(10px, env(safe-area-inset-top));
                    right: max(10px, env(safe-area-inset-right));
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
$topbar_title    = 'Exam Results';
$topbar_subtitle = 'Enter & manage marks';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-file-pen"></i> Exam Results</h1>
            <p>Enter marks for your classes and subjects.</p>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="stat-body">
                <div class="label">Students</div>
                <div class="value"><?php echo number_format($stats['total']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fa-solid fa-check"></i>
            </div>
            <div class="stat-body">
                <div class="label">Entered</div>
                <div class="value"><?php echo number_format($stats['entered']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold">
                <i class="fa-solid fa-percent"></i>
            </div>
            <div class="stat-body">
                <div class="label">Average</div>
                <div class="value"><?php echo $stats['average'] > 0 ? number_format($stats['average'], 1) : '—'; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fa-solid fa-trophy"></i>
            </div>
            <div class="stat-body">
                <div class="label">Best</div>
                <div class="value"><?php echo $stats['best'] > 0 ? number_format($stats['best'], 1) : '—'; ?></div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" action="exams.php" class="filter-panel">
        <div class="filter-form">

            <div class="filter-group">
                <label>Class</label>
                <select name="class_id" class="filter-control" onchange="this.form.submit()">
                    <?php if (empty($my_assignments)): ?>
                        <option value="">— No classes —</option>
                    <?php else: ?>
                        <?php foreach ($my_assignments as $cid => $ca): ?>
                            <option value="<?php echo $cid; ?>"
                                <?php echo $filter_class === $cid ? 'selected' : ''; ?>>
                                <?php echo e($ca['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Subject</label>
                <select name="subject_id" class="filter-control" onchange="this.form.submit()">
                    <?php if ($filter_class > 0 && !empty($my_assignments[$filter_class]['subjects'])): ?>
                        <?php foreach ($my_assignments[$filter_class]['subjects'] as $sid => $sa): ?>
                            <option value="<?php echo $sid; ?>"
                                <?php echo $filter_subject === $sid ? 'selected' : ''; ?>>
                                <?php echo e($sa['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">— Select class first —</option>
                    <?php endif; ?>
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

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Load
            </button>

            <?php if ($has_filters): ?>
                <a href="exams.php" class="btn btn-ghost">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>

        </div>
    </form>

    <!-- RESULTS PANEL -->
    <section class="results-panel">

        <div class="results-header">
            <h2>
                <i class="fa-solid fa-list-check"></i>
                <?php if ($filter_class_label): ?>
                    <?php echo e($filter_class_label); ?>
                    <?php if ($filter_subject_name): ?>
                        · <?php echo e($filter_subject_name); ?>
                    <?php endif; ?>
                    · <?php echo e($filter_term); ?>
                <?php else: ?>
                    Marks Entry
                <?php endif; ?>
            </h2>

            <?php if (!empty($students)): ?>
                <div class="results-meta">
                    <span><i class="fa-solid fa-user-graduate"></i>
                        <strong><?php echo number_format($stats['total']); ?></strong> students
                    </span>
                    <span><i class="fa-solid fa-check"></i>
                        <strong><?php echo number_format($stats['entered']); ?></strong> entered
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($my_assignments)): ?>

            <div class="empty">
                <div class="empty-icon"><i class="fa-solid fa-book"></i></div>
                <h3>No teaching assignments</h3>
                <p>You have not been assigned to any class or subject for the current academic year.</p>
            </div>

        <?php elseif (empty($students)): ?>

            <div class="empty">
                <div class="empty-icon"><i class="fa-solid fa-users-slash"></i></div>
                <h3>No students found</h3>
                <p>There are no active students in this class.</p>
            </div>

        <?php else: ?>

            <form id="marksForm" onsubmit="return false;">

                <input type="hidden" name="class_id"         value="<?php echo (int)$filter_class; ?>">
                <input type="hidden" name="subject_id"       value="<?php echo (int)$filter_subject; ?>">
                <input type="hidden" name="academic_year_id" value="<?php echo (int)$active_year_id; ?>">
                <input type="hidden" name="term"             value="<?php echo e($filter_term); ?>">

                <!-- DESKTOP TABLE -->
                <div class="table-wrapper">
                    <table class="marks-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">No</th>
                                <th>Student</th>
                                <th style="width:130px;">Marks /100</th>
                                <th style="width:90px;">Grade</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $n = 1; foreach ($students as $s):
                            $sid  = (int) $s['student_id'];
                            $ex   = $existing_results[$sid] ?? null;
                            $mark = $ex['marks'] ?? '';
                            $grad = $ex['grade'] ?? '';
                            $rem  = $ex['remarks'] ?? '';
                            if ($grad === '' && $mark !== '' && is_numeric($mark)) {
                                $grad = gradeFromMarks((float)$mark);
                            }
                            $initial = strtoupper(mb_substr($s['full_name'], 0, 1));
                        ?>
                            <tr data-student-id="<?php echo $sid; ?>">
                                <td><?php echo $n++; ?></td>
                                <td>
                                    <div class="student-cell">
                                        <?php if (!empty($s['photo']) && is_file(__DIR__ . '/../uploads/students/' . $s['photo'])): ?>
                                            <div class="student-avatar">
                                                <img src="../uploads/students/<?php echo e($s['photo']); ?>"
                                                     alt="" loading="lazy">
                                            </div>
                                        <?php else: ?>
                                            <div class="student-avatar"><?php echo e($initial); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="student-name"><?php echo e($s['full_name']); ?></div>
                                            <div class="student-adm"><?php echo e($s['admission_no']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <input type="number"
                                           class="marks-input"
                                           name="marks_<?php echo $sid; ?>"
                                           min="0" max="100" step="0.01"
                                           value="<?php echo e($mark); ?>"
                                           placeholder="—"
                                           data-student-id="<?php echo $sid; ?>">
                                </td>
                                <td>
                                    <span class="grade-badge grade-na" data-grade-for="<?php echo $sid; ?>">
                                        <?php echo e($grad ?: '—'); ?>
                                    </span>
                                </td>
                                <td>
                                    <input type="text"
                                           class="remarks-input"
                                           name="remarks_<?php echo $sid; ?>"
                                           maxlength="255"
                                           value="<?php echo e($rem); ?>"
                                           placeholder="Optional remarks"
                                           data-student-id="<?php echo $sid; ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- MOBILE CARDS -->
                <div class="card-list">
                    <?php foreach ($students as $s):
                        $sid  = (int) $s['student_id'];
                        $ex   = $existing_results[$sid] ?? null;
                        $mark = $ex['marks'] ?? '';
                        $grad = $ex['grade'] ?? '';
                        $rem  = $ex['remarks'] ?? '';
                        if ($grad === '' && $mark !== '' && is_numeric($mark)) {
                            $grad = gradeFromMarks((float)$mark);
                        }
                        $initial = strtoupper(mb_substr($s['full_name'], 0, 1));
                    ?>
                        <div class="mark-card" data-student-id="<?php echo $sid; ?>">

                            <div class="mark-card-top">
                                <?php if (!empty($s['photo']) && is_file(__DIR__ . '/../uploads/students/' . $s['photo'])): ?>
                                    <div class="student-avatar">
                                        <img src="../uploads/students/<?php echo e($s['photo']); ?>" alt="" loading="lazy">
                                    </div>
                                <?php else: ?>
                                    <div class="student-avatar"><?php echo e($initial); ?></div>
                                <?php endif; ?>
                                <div style="min-width:0;flex:1;">
                                    <div class="student-name"><?php echo e($s['full_name']); ?></div>
                                    <div class="student-adm"><?php echo e($s['admission_no']); ?></div>
                                </div>
                            </div>

                            <div class="mark-card-fields">

                                <div class="mark-field-group">
                                    <label>Marks</label>
                                    <input type="number"
                                           class="marks-input"
                                           name="marks_m_<?php echo $sid; ?>"
                                           min="0" max="100" step="0.01"
                                           value="<?php echo e($mark); ?>"
                                           placeholder="—"
                                           data-student-id="<?php echo $sid; ?>">
                                </div>

                                <div class="mark-field-group">
                                    <label>Remarks</label>
                                    <input type="text"
                                           class="remarks-input"
                                           name="remarks_m_<?php echo $sid; ?>"
                                           maxlength="255"
                                           value="<?php echo e($rem); ?>"
                                           placeholder="Optional"
                                           data-student-id="<?php echo $sid; ?>">
                                </div>

                                <div class="mark-card-grade">
                                    <span class="grade-badge grade-na" data-grade-for="<?php echo $sid; ?>">
                                        <?php echo e($grad ?: '—'); ?>
                                    </span>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="action-bar">
                    <div class="action-bar-left">
                        <i class="fa-solid fa-circle-info"></i>
                        Marks between <strong>0–100</strong>. Grade auto-calculated.
                    </div>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <span class="spinner"></span>
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span class="btn-label">Save Marks</span>
                    </button>
                </div>

            </form>

        <?php endif; ?>

    </section>

</main>

<div class="toast-wrap" id="toastWrap"></div>


<script>
/* =========================================================================
   TOASTS
   ========================================================================= */
function showToast(message, type = 'success', timeout = 3200) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : '');
    el.innerHTML = '<i class="fa-solid ' +
        (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') +
        '"></i><span>' + message + '</span>';
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s ease, transform .25s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}


/* =========================================================================
   AUTO-CALCULATE GRADE ON INPUT
   ========================================================================= */
function gradeFromMarks(m) {
    if (m === '' || m === null || isNaN(m)) return null;
    m = parseFloat(m);
    if (m >= 80) return 'A';
    if (m >= 70) return 'B';
    if (m >= 60) return 'C';
    if (m >= 50) return 'D';
    if (m >= 40) return 'E';
    return 'F';
}

function gradeClass(g) {
    if (!g) return 'grade-na';
    switch (g.toUpperCase()) {
        case 'A': case 'A+': case 'A-': return 'grade-a';
        case 'B': case 'B+': case 'B-': return 'grade-b';
        case 'C': case 'C+': case 'C-': return 'grade-c';
        case 'D': case 'D+': case 'D-': return 'grade-d';
        case 'E': case 'F': return 'grade-f';
        default:  return 'grade-na';
    }
}

function updateGradeForStudent(studentId) {
    /* Find the FIRST marks input for this student (desktop or mobile) */
    const input = document.querySelector('.marks-input[data-student-id="' + studentId + '"]');
    const badge = document.querySelector('[data-grade-for="' + studentId + '"]');
    if (!input || !badge) return;

    const raw = input.value.trim();
    const g = gradeFromMarks(raw);

    badge.textContent = g || '—';
    badge.className = 'grade-badge ' + gradeClass(g);

    /* Validation */
    if (raw !== '' && (isNaN(raw) || parseFloat(raw) < 0 || parseFloat(raw) > 100)) {
        input.classList.add('error');
    } else {
        input.classList.remove('error');
    }
}

/* Bind input events — both desktop and mobile inputs */
document.querySelectorAll('.marks-input').forEach(input => {
    input.addEventListener('input', function () {
        const sid = this.dataset.studentId;
        /* Sync the OTHER input with the same student_id if present */
        document.querySelectorAll('.marks-input[data-student-id="' + sid + '"]').forEach(other => {
            if (other !== this) other.value = this.value;
        });
        updateGradeForStudent(sid);
    });
});

document.querySelectorAll('.remarks-input').forEach(input => {
    input.addEventListener('input', function () {
        const sid = this.dataset.studentId;
        document.querySelectorAll('.remarks-input[data-student-id="' + sid + '"]').forEach(other => {
            if (other !== this) other.value = this.value;
        });
    });
});

/* Initial sync for existing values */
document.querySelectorAll('.marks-input').forEach(input => {
    const sid = input.dataset.studentId;
    updateGradeForStudent(sid);
});


/* =========================================================================
   SAVE MARKS (AJAX)
   ========================================================================= */
const marksForm = document.getElementById('marksForm');
const saveBtn   = document.getElementById('saveBtn');

if (marksForm) {
    marksForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const classId = marksForm.querySelector('input[name="class_id"]').value;
        const subjectId = marksForm.querySelector('input[name="subject_id"]').value;
        const yearId = marksForm.querySelector('input[name="academic_year_id"]').value;
        const term = marksForm.querySelector('input[name="term"]').value;

        /* Collect all rows (deduplicate by student_id) */
        const rowsMap = {};
        document.querySelectorAll('.marks-input').forEach(input => {
            const sid = input.dataset.studentId;
            if (!rowsMap[sid]) rowsMap[sid] = {};
            rowsMap[sid].student_id = sid;
            rowsMap[sid].marks = input.value.trim();
        });

        document.querySelectorAll('.remarks-input').forEach(input => {
            const sid = input.dataset.studentId;
            if (!rowsMap[sid]) rowsMap[sid] = { student_id: sid, marks: '' };
            rowsMap[sid].remarks = input.value.trim();
        });

        const rows = Object.values(rowsMap);

        if (rows.length === 0) {
            showToast('No students to save.', 'error');
            return;
        }

        const fd = new FormData();
        fd.set('ajax_action', 'save_results');
        fd.set('class_id', classId);
        fd.set('subject_id', subjectId);
        fd.set('academic_year_id', yearId);
        fd.set('term', term);
        fd.set('rows', JSON.stringify(rows));

        const original = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.classList.add('loading');

        try {
            const res = await fetch('exams.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const raw = await res.text();
            let json;
            try { json = JSON.parse(raw); }
            catch (err) { console.error(raw); throw new Error('Unexpected server response.'); }

            if (!json.success) {
                showToast(json.message || 'Could not save marks.', 'error');
                return;
            }

            showToast(json.message, 'success');

        } catch (err) {
            console.error(err);
            showToast(err.message || 'Network error.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.classList.remove('loading');
            saveBtn.innerHTML = original;
        }
    });
}


/* =========================================================================
   MOBILE SIDEBAR — handled inside includes/topbar.php
   ========================================================================= */
</script>

</body>
</html>