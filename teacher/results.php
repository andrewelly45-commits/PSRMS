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

function redirect_with_flash(string $type, string $message): void {
    $_SESSION['results_flash'] = ['type' => $type, 'message' => $message];
    header('Location: results.php');
    exit;
}

function grade_for(float $marks): string {
    if ($marks >= 80) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 60) return 'C';
    if ($marks >= 50) return 'D';
    if ($marks >= 40) return 'E';
    return 'F';
}

function fetch_class_students(mysqli $conn, int $class_id): array {
    $out = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT student_id, admission_no, full_name
         FROM students
         WHERE class_id = ? AND status = 'active'
         ORDER BY full_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $class_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
    mysqli_stmt_close($stmt);
    return $out;
}

function filename_safe(string $v): string {
    $v = preg_replace('/[^A-Za-z0-9]+/', '-', $v);
    return trim($v, '-');
}


/* =========================================================================
   AUTH: LOAD TEACHER
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

$teacher_id   = (int) $me['teacher_id'];
$teacher_name = trim(
    $me['first_name'] . ' ' .
    (!empty($me['middle_name']) ? $me['middle_name'] . ' ' : '') .
    $me['last_name']
);


/* =========================================================================
   ACTIVE ACADEMIC YEAR + ACTIVE TERM
   ========================================================================= */

$active_year    = null;
$active_year_id = 0;

if (tableExists($conn, 'academic_years')) {
    $res = mysqli_query(
        $conn,
        "SELECT academic_year_id, year
         FROM academic_years
         WHERE status = 'active'
         ORDER BY year DESC LIMIT 1"
    );
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $active_year    = $row;
        $active_year_id = (int)$row['academic_year_id'];
    }
}

$active_term      = null;
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

    if ($active_term) $active_term_name = $active_term['term_name'];
}


/* =========================================================================
   AUTO-CREATE results TABLE
   ========================================================================= */

if (!tableExists($conn, 'results')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS results (
            result_id        INT(11) NOT NULL AUTO_INCREMENT,
            student_id       INT(11) NOT NULL,
            class_id         INT(11) NOT NULL,
            subject_id       INT(11) NOT NULL,
            teacher_id       INT(11) NOT NULL,
            academic_year_id INT(11) NOT NULL,
            term             ENUM('Term 1','Term 2','Term 3') NOT NULL,
            marks            DECIMAL(5,2) NOT NULL,
            grade            VARCHAR(5) DEFAULT NULL,
            remarks          VARCHAR(255) DEFAULT NULL,
            created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (result_id),
            UNIQUE KEY uniq_result (student_id, subject_id, term, academic_year_id),
            KEY idx_class_subject (class_id, subject_id),
            KEY idx_teacher (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


/* =========================================================================
   LOAD CLASSES THE TEACHER TEACHES
   ========================================================================= */

$my_classes = [];

if ($active_year_id > 0) {
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
        $row['label'] = $row['class_name']
            . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $my_classes[(int)$row['class_id']] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   LOAD SUBJECTS THE TEACHER TEACHES IN EACH CLASS
   ========================================================================= */

$subjects_by_class = [];

if (!empty($my_classes) && $active_year_id > 0) {

    $class_ids    = array_keys($my_classes);
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $types        = str_repeat('i', count($class_ids)) . 'ii';

    $params   = $class_ids;
    $params[] = $teacher_id;
    $params[] = $active_year_id;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT ta.class_id,
                s.subject_id, s.subject_name, s.subject_type
         FROM teacher_assignments ta
         INNER JOIN subjects s ON s.subject_id = ta.subject_id
         WHERE ta.class_id IN ($placeholders)
           AND ta.teacher_id = ?
           AND ta.academic_year_id = ?
           AND ta.status = 'active'
           AND s.status = 'active'
         ORDER BY s.subject_name ASC"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $subjects_by_class[(int)$row['class_id']][] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   CURRENT SELECTION
   ========================================================================= */

$sel_class_id   = (int) ($_REQUEST['class_id']   ?? 0);
$sel_subject_id = (int) ($_REQUEST['subject_id'] ?? 0);

if ($sel_class_id === 0 && !empty($my_classes)) {
    $sel_class_id = (int) array_key_first($my_classes);
}

if ($sel_subject_id === 0 && isset($subjects_by_class[$sel_class_id][0])) {
    $sel_subject_id = (int) $subjects_by_class[$sel_class_id][0]['subject_id'];
}

if ($sel_class_id > 0 && !isset($my_classes[$sel_class_id])) {
    $sel_class_id = 0;
}

$valid_subject_ids = [];
if ($sel_class_id && !empty($subjects_by_class[$sel_class_id])) {
    $valid_subject_ids = array_map('intval',
        array_column($subjects_by_class[$sel_class_id], 'subject_id'));
}
if ($sel_subject_id > 0 && !in_array($sel_subject_id, $valid_subject_ids, true)) {
    $sel_subject_id = $valid_subject_ids[0] ?? 0;
}

$ready = (
    $active_year &&
    $active_term &&
    $sel_class_id > 0 &&
    $sel_subject_id > 0 &&
    in_array($sel_subject_id, $valid_subject_ids, true)
);

$sel_class_label   = $my_classes[$sel_class_id]['label'] ?? '';
$sel_subject_label = '';
foreach ($subjects_by_class[$sel_class_id] ?? [] as $srow) {
    if ((int)$srow['subject_id'] === $sel_subject_id) {
        $sel_subject_label = $srow['subject_name'];
        break;
    }
}


/* =========================================================================
   DOWNLOAD TEMPLATE (CSV — only #, Student ID, Admission No, Full Name, Term, Marks)
   ========================================================================= */

if (isset($_GET['download']) && $_GET['download'] === '1') {

    if (!$ready) {
        redirect_with_flash('error', 'No active year, no active term, or class/subject missing.');
    }

    $students = fetch_class_students($conn, $sel_class_id);

    if (empty($students)) {
        redirect_with_flash('error', 'There are no active students in this class.');
    }

    /* Filename: Marks__Standard-Two__HISTORY__Term-2-2025.csv */
    $filename =
        'Marks__' .
        filename_safe($sel_class_label) .
        '__' . filename_safe(strtoupper($sel_subject_label)) .
        '__' . filename_safe($active_term_name) . '-' . (int) $active_year['year'] .
        '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); /* UTF-8 BOM */

    /* Header row (no Grade column) */
    fputcsv($out, ['#', 'Student ID', 'Admission No', 'Full Name', 'Term', 'Marks']);

    $i = 1;
    foreach ($students as $s) {
        fputcsv($out, [
            $i++,
            $s['student_id'],
            $s['admission_no'],
            $s['full_name'],
            $active_term_name,
            '',   /* teacher fills this */
        ]);
    }

    fclose($out);
    exit;
}


/* =========================================================================
   HANDLE UPLOAD
   ========================================================================= */

$flash = $_SESSION['results_flash'] ?? null;
unset($_SESSION['results_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {

    if (!$ready) {
        redirect_with_flash('error', 'System is not ready: need active year, active term, class, and subject.');
    }

    $post_class   = (int) ($_POST['class_id']   ?? 0);
    $post_subject = (int) ($_POST['subject_id'] ?? 0);

    if ($post_class !== $sel_class_id || $post_subject !== $sel_subject_id) {
        redirect_with_flash('error', 'Class/subject mismatch. Please reload the page.');
    }

    if (
        !isset($_FILES['marks_file']) ||
        $_FILES['marks_file']['error'] !== UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES['marks_file']['tmp_name'])
    ) {
        redirect_with_flash('error', 'Please choose a CSV file to upload.');
    }

    $original_name = $_FILES['marks_file']['name'] ?? '';
    $tmp           = $_FILES['marks_file']['tmp_name'];

    /* --------------------------------------------------------------
       Filename sanity check (loose — the real check is the rows)
       -------------------------------------------------------------- */
    $base  = pathinfo($original_name, PATHINFO_FILENAME);
    $parts = explode('__', $base);

    if (count($parts) !== 4 || $parts[0] !== 'Marks') {
        redirect_with_flash(
            'error',
            'Invalid file name. Please download a fresh template from this page and do not rename it.'
        );
    }

    /* Optional: match class + subject + term-year against the current selection */
    $file_class   = $parts[1];
    $file_subject = $parts[2];
    $file_term_yr = $parts[3];

    $expected_class   = filename_safe($sel_class_label);
    $expected_subject = filename_safe(strtoupper($sel_subject_label));
    $expected_term_yr = filename_safe($active_term_name) . '-' . (int) $active_year['year'];

    if ($file_class !== $expected_class
        || $file_subject !== $expected_subject
        || $file_term_yr !== $expected_term_yr) {
        redirect_with_flash(
            'error',
            'This file does not match the current class, subject, or term. Please download a fresh template.'
        );
    }

    /* Fresh DB snapshot */
    $students_db = fetch_class_students($conn, $sel_class_id);

    if (empty($students_db)) {
        redirect_with_flash('error', 'No active students in this class.');
    }

    /* --------------------------------------------------------------
       Read and validate the CSV
       -------------------------------------------------------------- */
    $fh = fopen($tmp, 'r');
    if (!$fh) {
        redirect_with_flash('error', 'Could not read the uploaded file.');
    }

    $BOM_STRIP   = "\xEF\xBB\xBF";
    $header_seen = false;
    $rows        = [];
    $line_no     = 0;

    while (($raw = fgetcsv($fh)) !== false) {

        $line_no++;

        if ($line_no === 1 && isset($raw[0])) {
            $raw[0] = preg_replace('/^' . preg_quote($BOM_STRIP, '/') . '/', '', $raw[0]);
        }

        if (count($raw) === 1 && trim((string)$raw[0]) === '') continue;

        if (!$header_seen) {
            $header_seen = true;
            continue;
        }

        /* Now only 6 columns: #, Student ID, Admission No, Full Name, Term, Marks */
        if (count($raw) < 6) {
            fclose($fh);
            redirect_with_flash('error', "Row {$line_no}: expected 6 columns.");
        }

        $rows[] = [
            'line'         => $line_no,
            'student_id'   => trim((string)$raw[1]),
            'admission_no' => trim((string)$raw[2]),
            'full_name'    => trim((string)$raw[3]),
            'term'         => trim((string)$raw[4]),
            'marks'        => trim((string)$raw[5]),
        ];
    }
    fclose($fh);

    if (!$header_seen) {
        redirect_with_flash('error', 'The file is empty or missing a header row.');
    }
    if (empty($rows)) {
        redirect_with_flash('error', 'The file contains no student rows.');
    }
    if (count($rows) !== count($students_db)) {
        redirect_with_flash(
            'error',
            'The number of rows does not match the class list. ' .
            'Do not add or remove rows. Please download a fresh template.'
        );
    }

    /* Lookups */
    $db_by_id = [];
    foreach ($students_db as $s) $db_by_id[(int)$s['student_id']] = $s;

    $by_student_id = [];
    foreach ($rows as $r) {
        $sid = (int) $r['student_id'];
        if (isset($by_student_id[$sid])) {
            redirect_with_flash('error', "Duplicate student ID {$sid} in the file.");
        }
        $by_student_id[$sid] = $r;
    }

    /* Row-by-row integrity */
    foreach ($db_by_id as $sid => $db_row) {

        if (!isset($by_student_id[$sid])) {
            redirect_with_flash(
                'error',
                "Student ID {$sid} ({$db_row['admission_no']}) is missing from the file."
            );
        }

        $r = $by_student_id[$sid];

        if ($r['admission_no'] !== (string)$db_row['admission_no']) {
            redirect_with_flash(
                'error',
                "Admission No mismatch for student ID {$sid}. Do not edit Admission No."
            );
        }
        if ($r['full_name'] !== (string)$db_row['full_name']) {
            redirect_with_flash(
                'error',
                "Full Name mismatch for student ID {$sid}. Do not edit the Full Name."
            );
        }
        if ($r['term'] !== $active_term_name) {
            redirect_with_flash(
                'error',
                "Term mismatch for student ID {$sid}. Expected '{$active_term_name}'."
            );
        }

        $m = $r['marks'];
        if ($m !== '') {
            if (!is_numeric($m)) {
                redirect_with_flash('error', "Row {$r['line']}: marks must be a number.");
            }
            $m_f = (float)$m;
            if ($m_f < 0 || $m_f > 100) {
                redirect_with_flash(
                    'error',
                    "Row {$r['line']}: marks must be between 0 and 100."
                );
            }
        }
    }

    /* Reject students not in this class */
    foreach ($by_student_id as $sid => $r) {
        if (!isset($db_by_id[$sid])) {
            redirect_with_flash(
                'error',
                "Row {$r['line']}: student ID {$sid} does not belong to this class."
            );
        }
    }

    /* --------------------------------------------------------------
       SAVE — grade is computed server-side
       -------------------------------------------------------------- */
    mysqli_begin_transaction($conn);

    try {

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO results
                (student_id, class_id, subject_id, teacher_id,
                 academic_year_id, term, marks, grade)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                marks      = VALUES(marks),
                grade      = VALUES(grade),
                teacher_id = VALUES(teacher_id),
                class_id   = VALUES(class_id)"
        );

        foreach ($rows as $r) {

            $sid   = (int) $r['student_id'];
            $m_raw = $r['marks'];

            if ($m_raw === '') { $skipped++; continue; }

            $marks     = (float)$m_raw;
            $grade     = grade_for($marks);      /* computed here */
            $marks_str = sprintf('%.2f', $marks);

            mysqli_stmt_bind_param(
                $stmt, 'iiiissss',
                $sid,
                $sel_class_id,
                $sel_subject_id,
                $teacher_id,
                $active_year_id,
                $active_term_name,
                $marks_str,
                $grade
            );

            if (mysqli_stmt_execute($stmt)) {
                if (mysqli_stmt_affected_rows($stmt) === 1) $inserted++;
                else $updated++;
            }
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);

        redirect_with_flash(
            'success',
            "Upload OK. New: {$inserted}, updated: {$updated}, skipped (blank): {$skipped}."
        );

    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        redirect_with_flash('error', 'Save failed: ' . $ex->getMessage());
    }
}


/* =========================================================================
   STATS FOR DISPLAY
   ========================================================================= */

$total_students = 0;
$filled_count   = 0;
$class_avg      = null;

if ($ready) {

    $students_db    = fetch_class_students($conn, $sel_class_id);
    $total_students = count($students_db);

    if ($total_students > 0) {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT student_id, marks
             FROM results
             WHERE subject_id = ?
               AND term = ?
               AND academic_year_id = ?
               AND class_id = ?"
        );
        mysqli_stmt_bind_param(
            $stmt, 'isii',
            $sel_subject_id, $active_term_name, $active_year_id, $sel_class_id
        );
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $sum = 0; $n = 0;
        while ($row = mysqli_fetch_assoc($res)) {
            $filled_count++;
            $sum += (float)$row['marks'];
            $n++;
        }
        mysqli_stmt_close($stmt);

        if ($n > 0) $class_avg = round($sum / $n, 1);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Results Upload | PSRMS</title>

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
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 3px rgba(62,118,85,.18);
        }
        .year-badge.term {
            background: var(--gold);
            border-color: var(--gold-light);
            color: var(--navy);
        }
        .year-badge.term::before { background: var(--navy); box-shadow: 0 0 0 3px rgba(23,35,60,.15); }
        .year-badge.missing {
            background: var(--orange-bg);
            border-color: #ecd9a8;
            color: var(--orange);
        }
        .year-badge.missing::before { background: var(--orange); }

        /* FLASH */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12.5px;
            font-weight: 600;
            line-height: 1.55;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* SELECTOR */
        .selector-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .selector-grid {
            display: grid;
            grid-template-columns: 1.4fr 1.4fr auto;
            gap: 12px;
            align-items: end;
        }

        .sel-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 7px;
        }

        .sel-control {
            width: 100%;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13.5px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }

        select.sel-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        .sel-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .term-box {
            height: 44px;
            border: 1px solid #cfe5d7;
            background: var(--green-bg);
            color: var(--green);
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 14px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 750;
            white-space: nowrap;
        }
        .term-box .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--green);
        }

        /* SUMMARY */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .summary-pill {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            border-top: 3px solid var(--muted);
        }
        .summary-pill.students { border-top-color: var(--blue); }
        .summary-pill.filled   { border-top-color: var(--green); }
        .summary-pill.avg      { border-top-color: var(--gold); }

        .summary-pill .num {
            font-size: 22px;
            font-weight: 800;
            color: var(--navy);
            line-height: 1;
            margin-bottom: 5px;
        }
        .summary-pill .lbl {
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
        }

        /* WORKFLOW CARDS */
        .workflow {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 20px;
        }

        .step-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
        }

        .step-card .step-number {
            position: absolute;
            top: -12px;
            left: 20px;
            background: var(--navy);
            color: var(--gold-light);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .5px;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .step-card h3 {
            font-size: 15px;
            color: var(--navy);
            font-weight: 750;
            margin-top: 4px;
        }

        .step-card p {
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.6;
        }

        .step-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 12.5px;
            color: var(--text);
        }
        .step-card ul li {
            padding: 5px 0 5px 20px;
            position: relative;
            line-height: 1.55;
        }
        .step-card ul li::before {
            content: "✓";
            color: var(--green);
            font-weight: 800;
            position: absolute;
            left: 0;
        }
        .step-card ul li.warn::before {
            content: "✕";
            color: var(--red);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 46px;
            padding: 0 20px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover { background: var(--navy-dark); }

        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover { background: var(--gold-light); }

        /* Upload form */
        .file-input-wrap {
            position: relative;
            display: block;
        }
        .file-input-wrap input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
        }
        .file-display {
            height: 46px;
            border: 1px dashed #cbd1db;
            border-radius: 8px;
            background: #fafbfd;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 0 14px;
            font-size: 13px;
            color: var(--muted);
            pointer-events: none;
        }
        .file-display span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 80%;
        }
        .file-display .choose {
            color: var(--navy);
            font-weight: 750;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* Empty state */
        .empty {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        .empty-icon {
            width: 60px; height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
        }
        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        /* RESPONSIVE */
        @media (max-width: 1000px) {
            .workflow { grid-template-columns: 1fr; }
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

            .selector-panel { padding: 14px; }
            .selector-grid { grid-template-columns: 1fr; gap: 12px; }

            .sel-control,
            .btn { min-height: 46px; font-size: 14px; }

            .summary-row { grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
            .summary-pill { padding: 12px 10px; }
            .summary-pill .num { font-size: 18px; }
        }

        @media (max-width: 500px) {
            .summary-row { grid-template-columns: 1fr 1fr; }
            .summary-pill:last-child { grid-column: 1 / -1; }
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
$topbar_title    = 'Results Upload';
$topbar_subtitle = 'Download template → fill marks → upload';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Results Upload</h1>
            <p>Download the marks template, fill in marks, and upload it back.</p>
        </div>

        <div class="badges">
            <?php if ($active_year): ?>
                <span class="year-badge">Year: <?php echo e($active_year['year']); ?></span>
            <?php else: ?>
                <span class="year-badge missing">No active year</span>
            <?php endif; ?>

            <?php if ($active_term): ?>
                <span class="year-badge term">Term: <?php echo e($active_term_name); ?></span>
            <?php else: ?>
                <span class="year-badge missing">No active term</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (!$active_year): ?>

        <div class="alert error">
            There is no active academic year. Please ask the admin to activate one.
        </div>

    <?php elseif (!$active_term): ?>

        <div class="alert error">
            There is no active term for the current academic year. Ask the admin to set the current term in <em>Academic Years → Terms</em>.
        </div>

    <?php elseif (empty($my_classes)): ?>

        <div class="empty">
            <div class="empty-icon">📚</div>
            <h3>No classes assigned to you</h3>
            <p>You are not assigned to teach any class this academic year.</p>
        </div>

    <?php else: ?>

        <!-- SELECTOR -->
        <section class="selector-panel">
            <form method="GET" action="results.php" class="selector-grid">

                <div class="sel-group">
                    <label for="class_id">Class</label>
                    <select name="class_id" id="class_id" class="sel-control" required
                            onchange="this.form.submit()">
                        <?php foreach ($my_classes as $cid => $c): ?>
                            <option value="<?php echo $cid; ?>"
                                <?php echo $sel_class_id === $cid ? 'selected' : ''; ?>>
                                <?php echo e($c['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sel-group">
                    <label for="subject_id">Subject</label>
                    <select name="subject_id" id="subject_id" class="sel-control" required
                            <?php echo empty($subjects_by_class[$sel_class_id]) ? 'disabled' : ''; ?>>
                        <?php if (empty($subjects_by_class[$sel_class_id])): ?>
                            <option value="">— No subjects —</option>
                        <?php else: ?>
                            <?php foreach ($subjects_by_class[$sel_class_id] as $srow): ?>
                                <option value="<?php echo (int)$srow['subject_id']; ?>"
                                    <?php echo $sel_subject_id === (int)$srow['subject_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($srow['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="sel-group">
                    <label>Term (auto)</label>
                    <div class="term-box">
                        <span class="dot"></span>
                        <?php echo e($active_term_name); ?>
                    </div>
                </div>

            </form>
        </section>


        <!-- SUMMARY -->
        <?php if ($ready): ?>

            <div class="summary-row">
                <div class="summary-pill students">
                    <div class="num"><?php echo number_format($total_students); ?></div>
                    <div class="lbl">Students in Class</div>
                </div>
                <div class="summary-pill filled">
                    <div class="num"><?php echo number_format($filled_count); ?></div>
                    <div class="lbl">Marks Uploaded</div>
                </div>
                <div class="summary-pill avg">
                    <div class="num"><?php echo $class_avg !== null ? e($class_avg) : '—'; ?></div>
                    <div class="lbl">Class Average</div>
                </div>
            </div>

        <?php endif; ?>


        <!-- WORKFLOW -->
        <?php if ($ready): ?>

            <?php if ($total_students === 0): ?>

                <div class="empty">
                    <div class="empty-icon">👥</div>
                    <h3>No students</h3>
                    <p>There are no active students in <?php echo e($sel_class_label); ?>.</p>
                </div>

            <?php else: ?>

                <div class="workflow">

                    <!-- STEP 1: DOWNLOAD -->
                    <div class="step-card">
                        <span class="step-number">STEP 1</span>
                        <h3>Download Marks Template</h3>
                        <p>
                            Get a CSV with the class list for
                            <strong><?php echo e($sel_class_label); ?></strong>
                            · <strong><?php echo e($sel_subject_label); ?></strong>
                            · <strong><?php echo e($active_term_name); ?></strong>.
                        </p>

                        <ul>
                            <li>Admission No and Full Name are locked</li>
                            <li>Term is locked to the active term</li>
                            <li>Only the <strong>Marks</strong> column should be filled</li>
                            <li class="warn">Do not add, remove, or rename rows</li>
                        </ul>

                        <a class="btn btn-gold"
                           href="results.php?download=1&amp;class_id=<?php echo $sel_class_id; ?>&amp;subject_id=<?php echo $sel_subject_id; ?>">
                            ⬇ Download Template
                        </a>
                    </div>

                    <!-- STEP 2: UPLOAD -->
                    <div class="step-card">
                        <span class="step-number">STEP 2</span>
                        <h3>Upload Filled Template</h3>
                        <p>
                            Upload the same file back with marks filled in.
                            Any modification to Admission No or Full Name
                            will cause the whole file to be rejected.
                        </p>

                        <ul>
                            <li>Marks must be between <strong>0 and 100</strong></li>
                            <li>Blank mark = skipped for that student</li>
                            <li class="warn">Any changed name/admission no = entire file rejected</li>
                            <li class="warn">Any row missing or added = entire file rejected</li>
                        </ul>

                        <form method="POST" action="results.php"
                              enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="class_id" value="<?php echo $sel_class_id; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo $sel_subject_id; ?>">

                            <label class="file-input-wrap">
                                <input type="file" name="marks_file" accept=".csv,text/csv" required
                                       onchange="showFileName(this)">
                                <div class="file-display">
                                    <span id="fileName">No file selected</span>
                                    <span class="choose">Choose file</span>
                                </div>
                            </label>

                            <div style="margin-top:14px;">
                                <button type="submit" class="btn btn-primary"
                                        style="width:100%;"
                                        onclick="return confirm('Upload this file? All rows will be verified before saving.');">
                                    ⬆ Upload Results
                                </button>
                            </div>
                        </form>
                    </div>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</main>


<script>
function showFileName(input) {
    const label = document.getElementById('fileName');
    if (!label) return;
    label.textContent = input.files && input.files[0]
        ? input.files[0].name
        : 'No file selected';
}
</script>

</body>
</html>