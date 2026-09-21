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

$head_teacher_id = (int) $me['teacher_id'];


/* =========================================================================
   HELPERS
   ========================================================================= */

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['ct_flash'] = ['type' => $type, 'message' => $message];
    header('Location: manage_class_teachers.php');
    exit;
}

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
   AUTO-CREATE class_teachers TABLE
   ========================================================================= */

if (!tableExists($conn, 'class_teachers')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS class_teachers (
            class_teacher_id  INT(11) NOT NULL AUTO_INCREMENT,
            class_id          INT(11) NOT NULL,
            teacher_id        INT(11) NOT NULL,
            academic_year_id  INT(11) NOT NULL,
            status            ENUM('active','inactive') DEFAULT 'active',
            assigned_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (class_teacher_id),
            KEY idx_class      (class_id),
            KEY idx_teacher    (teacher_id),
            KEY idx_year       (academic_year_id),
            KEY idx_status     (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
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
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       ASSIGN
    ------------------------------------------------------------- */
    if ($action === 'assign') {

        $class_id   = (int) ($_POST['class_id']   ?? 0);
        $teacher_id = (int) ($_POST['teacher_id'] ?? 0);

        if (!$active_year) {
            redirect_with_flash('error', 'No active academic year. Please activate one first.');
        }

        if ($class_id <= 0 || $teacher_id <= 0) {
            redirect_with_flash('error', 'Please select both a class and a teacher.');
        }

        /* Confirm class exists */
        $stmt = mysqli_prepare($conn, "SELECT class_id FROM classes WHERE class_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $class_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) === 0) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Selected class does not exist.');
        }
        mysqli_stmt_close($stmt);

        /* Confirm teacher exists and is not headteacher */
        $stmt = mysqli_prepare(
            $conn,
            "SELECT teacher_id, assignment_type FROM teachers WHERE teacher_id = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
        mysqli_stmt_execute($stmt);
        $t = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$t) {
            redirect_with_flash('error', 'Selected teacher does not exist.');
        }
        if ($t['assignment_type'] === 'headteacher') {
            redirect_with_flash('error', 'The headteacher cannot be assigned as a class teacher.');
        }

        /* Begin transaction: demote existing + insert new */
        mysqli_begin_transaction($conn);

        try {

            /* Deactivate any existing active class teacher for this class+year */
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE class_teachers
                 SET status = 'inactive'
                 WHERE class_id = ?
                   AND academic_year_id = ?
                   AND status = 'active'"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $class_id, $active_year_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            /* Insert new assignment */
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO class_teachers
                    (class_id, teacher_id, academic_year_id, status)
                 VALUES (?, ?, ?, 'active')"
            );
            mysqli_stmt_bind_param($stmt, 'iii', $class_id, $teacher_id, $active_year_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            redirect_with_flash('success', 'Class teacher assigned successfully.');

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not assign: ' . $ex->getMessage());
        }
    }

    /* -------------------------------------------------------------
       UNASSIGN (deactivate)
    ------------------------------------------------------------- */
    if ($action === 'unassign') {

        $class_teacher_id = (int) ($_POST['class_teacher_id'] ?? 0);

        if ($class_teacher_id <= 0) {
            redirect_with_flash('error', 'Invalid assignment.');
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE class_teachers SET status = 'inactive' WHERE class_teacher_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $class_teacher_id);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected > 0) {
            redirect_with_flash('success', 'Class teacher removed.');
        }
        redirect_with_flash('error', 'Assignment not found.');
    }

    /* -------------------------------------------------------------
       REACTIVATE (re-assign someone who was previously removed)
    ------------------------------------------------------------- */
    if ($action === 'reactivate') {

        $class_teacher_id = (int) ($_POST['class_teacher_id'] ?? 0);

        if ($class_teacher_id <= 0) {
            redirect_with_flash('error', 'Invalid assignment.');
        }

        /* Get the row first to know its class */
        $stmt = mysqli_prepare(
            $conn,
            "SELECT class_id, academic_year_id FROM class_teachers WHERE class_teacher_id = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $class_teacher_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            redirect_with_flash('error', 'Assignment not found.');
        }

        mysqli_begin_transaction($conn);

        try {
            /* Deactivate any other active for same class+year */
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE class_teachers
                 SET status = 'inactive'
                 WHERE class_id = ?
                   AND academic_year_id = ?
                   AND status = 'active'
                   AND class_teacher_id <> ?"
            );
            mysqli_stmt_bind_param($stmt, 'iii', $row['class_id'], $row['academic_year_id'], $class_teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            /* Reactivate this one */
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE class_teachers
                 SET status = 'active', assigned_at = NOW()
                 WHERE class_teacher_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'i', $class_teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            redirect_with_flash('success', 'Class teacher reassigned.');

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not reassign: ' . $ex->getMessage());
        }
    }

    /* -------------------------------------------------------------
       DELETE PERMANENTLY
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $class_teacher_id = (int) ($_POST['class_teacher_id'] ?? 0);

        if ($class_teacher_id <= 0) {
            redirect_with_flash('error', 'Invalid assignment.');
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM class_teachers WHERE class_teacher_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $class_teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        redirect_with_flash('success', 'Assignment deleted.');
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */

$flash = $_SESSION['ct_flash'] ?? null;
unset($_SESSION['ct_flash']);


/* =========================================================================
   LOAD CLASSES (with current class teacher)
   ========================================================================= */

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
        $row['current_teacher'] = null;
        $row['current_class_teacher_id'] = null;
        $row['student_count'] = 0;
        $classes[] = $row;
    }
}

/* Load existing active class teachers for the current year */
if ($active_year_id > 0 && !empty($classes)) {

    $class_ids = array_column($classes, 'class_id');
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $types = str_repeat('i', count($class_ids)) . 'i';

    $params = $class_ids;
    $params[] = $active_year_id;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            ct.class_teacher_id,
            ct.class_id,
            ct.teacher_id,
            u.first_name,
            u.middle_name,
            u.last_name,
            t.employee_no
         FROM class_teachers ct
         INNER JOIN teachers t ON t.teacher_id = ct.teacher_id
         INNER JOIN users u ON u.user_id = t.user_id
         WHERE ct.class_id IN ($placeholders)
           AND ct.academic_year_id = ?
           AND ct.status = 'active'"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $by_class = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $row['full_name'] = trim(
                $row['first_name'] . ' ' .
                ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
                $row['last_name']
            );
            $by_class[(int)$row['class_id']] = $row;
        }
        mysqli_stmt_close($stmt);

        foreach ($classes as &$c) {
            $cid = (int)$c['class_id'];
            if (isset($by_class[$cid])) {
                $c['current_teacher'] = $by_class[$cid]['full_name'];
                $c['current_class_teacher_id'] = (int)$by_class[$cid]['class_teacher_id'];
            }
        }
        unset($c);
    }
}

/* Student counts per class */
if (!empty($classes)) {
    $class_ids = array_column($classes, 'class_id');
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
        $counts = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $counts[(int)$row['class_id']] = (int)$row['c'];
        }
        mysqli_stmt_close($stmt);

        foreach ($classes as &$c) {
            $c['student_count'] = $counts[(int)$c['class_id']] ?? 0;
        }
        unset($c);
    }
}


/* =========================================================================
   LOAD AVAILABLE TEACHERS
   ========================================================================= */

$teachers = [];
$res = mysqli_query(
    $conn,
    "SELECT
        t.teacher_id,
        t.employee_no,
        t.assignment_type,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.status AS user_status
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.assignment_type <> 'headteacher'
       AND u.status = 'active'
     ORDER BY u.first_name ASC, u.last_name ASC"
);

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $row['full_name'] = trim(
            $row['first_name'] . ' ' .
            ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );
        $teachers[] = $row;
    }
}

/* Count how many classes each teacher already manages */
$teacher_class_counts = [];
if ($active_year_id > 0 && !empty($teachers)) {
    $res = mysqli_query(
        $conn,
        "SELECT teacher_id, COUNT(*) AS c
         FROM class_teachers
         WHERE academic_year_id = $active_year_id
           AND status = 'active'
         GROUP BY teacher_id"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $teacher_class_counts[(int)$row['teacher_id']] = (int)$row['c'];
        }
    }
}


/* =========================================================================
   STATS
   ========================================================================= */

$total_classes        = count($classes);
$classes_with_teacher = 0;
foreach ($classes as $c) {
    if ($c['current_teacher']) $classes_with_teacher++;
}

$classes_without_teacher = $total_classes - $classes_with_teacher;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Class Teachers | PSRMS</title>

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
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 { color: var(--navy); font-size: 25px; font-weight: 700; }
        .page-title p  { color: var(--muted); font-size: 12.5px; margin-top: 5px; }

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

        /* FLASH */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

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
            position: relative;
            overflow: hidden;
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
        .stat-icon.blue   { background: var(--blue-bg);  color: var(--blue); }
        .stat-icon.green  { background: var(--green-bg); color: var(--green); }
        .stat-icon.orange { background: var(--orange-bg); color: var(--orange); }

        /* FILTER */
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
        .filter-panel.collapsed .filter-toggle .chev { transform: rotate(-90deg); }

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

        /* BUTTONS */
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
        .btn-primary:active { transform: scale(.98); }

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
           CLASS CARDS
        ========================================================= */
        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }

        .class-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: .2s ease;
        }

        .class-card:hover {
            border-color: rgba(201,162,39,.45);
            box-shadow: 0 10px 25px rgba(23,35,60,.06);
        }

        .class-card.assigned {
            border-left: 4px solid var(--green);
        }

        .class-card.unassigned {
            border-left: 4px solid var(--orange);
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

        /* Current teacher block */
        .current-teacher {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 14px;
            background: var(--green-bg);
            border: 1px solid #cfe5d7;
        }

        .current-teacher.empty {
            background: var(--orange-bg);
            border-color: #ecd9a8;
        }

        .ct-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--green);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .current-teacher.empty .ct-avatar {
            background: var(--orange);
        }

        .ct-info { min-width: 0; flex: 1; }

        .ct-role-label {
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--green);
            margin-bottom: 3px;
        }

        .current-teacher.empty .ct-role-label { color: var(--orange); }

        .ct-name {
            font-size: 13.5px;
            font-weight: 750;
            color: var(--navy);
            overflow-wrap: anywhere;
        }

        .current-teacher.empty .ct-name {
            color: var(--orange);
            font-style: italic;
            font-weight: 600;
        }

        /* Actions on the current teacher block */
        .ct-actions {
            display: flex;
            gap: 6px;
            margin-top: 12px;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 36px;
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
            flex: 1;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); color: var(--gold); }

        .icon-btn.danger { color: var(--red); border-color: #efd2d2; }
        .icon-btn.danger:hover { background: var(--red-bg); border-color: var(--red); }

        /* Assign form */
        .assign-form {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
        }

        .assign-select {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 34px 0 12px;
            font-family: inherit;
            font-size: 12.5px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
        }

        .assign-select:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .assign-btn {
            height: 42px;
            padding: 0 18px;
            background: var(--navy);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
        }
        .assign-btn:hover { background: var(--navy-dark); }
        .assign-btn:disabled { opacity: .5; cursor: not-allowed; }

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
            width: 60px; height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
        }
        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr auto; }
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

            .stats-grid { grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }
            .stat-card .hint  { font-size: 10px; }
            .stat-icon  { width: 32px; height: 32px; font-size: 13px; top: 12px; right: 12px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 14px; }

            .class-grid { grid-template-columns: 1fr; gap: 12px; }

            .class-card-head { padding: 14px 16px 12px; }
            .class-card-body { padding: 12px 16px 16px; }

            .class-icon { width: 42px; height: 42px; font-size: 14px; }

            .ct-actions { flex-direction: column; }
            .ct-actions .icon-btn { min-height: 42px; font-size: 12.5px; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stats-grid .stat-card:last-child { grid-column: 1 / -1; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 18px; }
            .stat-card .hint { display: none; }

            .class-icon { width: 38px; height: 38px; font-size: 13px; }
            .class-name { font-size: 13.5px; }

            .ct-avatar { width: 38px; height: 38px; font-size: 13px; }
            .ct-name   { font-size: 13px; }

            .assign-form { grid-template-columns: 1fr; }
            .assign-select, .assign-btn { width: 100%; }
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
$topbar_title    = 'Class Teachers';
$topbar_subtitle = 'Assign class teachers to each class';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Class Teachers</h1>
            <p>Assign one class teacher to each class for the active academic year.</p>
        </div>

        <?php if ($active_year): ?>
            <span class="year-badge">Active year: <?php echo e($active_year_n); ?></span>
        <?php else: ?>
            <span class="year-badge missing">No active academic year</span>
        <?php endif; ?>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- WARN: NO ACTIVE YEAR -->
    <?php if (!$active_year): ?>
        <div class="alert error">
            There is no active academic year. Please activate one from
            <a href="../admin/academic_years.php" style="color:inherit;font-weight:800;text-decoration:underline;">
                Academic Years
            </a>
            before assigning class teachers.
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">C</div>
            <div class="label">Total Classes</div>
            <div class="value"><?php echo number_format($total_classes); ?></div>
            <div class="hint">Active classes</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">A</div>
            <div class="label">Assigned</div>
            <div class="value"><?php echo number_format($classes_with_teacher); ?></div>
            <div class="hint">Have a class teacher</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">U</div>
            <div class="label">Unassigned</div>
            <div class="value"><?php echo number_format($classes_without_teacher); ?></div>
            <div class="hint">Still need a class teacher</div>
        </div>
    </div>

    <!-- FILTER -->
    <?php if (!empty($classes)): ?>
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="manage_class_teachers.php" class="filter-form">

            <div class="filter-group">
                <label>Search Class</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="Class name or stream…"
                       value="<?php echo e($_GET['q'] ?? ''); ?>">
            </div>

            <div class="filter-group">
                <label>Show</label>
                <select name="show" class="filter-control">
                    <option value="">All classes</option>
                    <option value="assigned"   <?php echo ($_GET['show'] ?? '') === 'assigned'   ? 'selected' : ''; ?>>Assigned only</option>
                    <option value="unassigned" <?php echo ($_GET['show'] ?? '') === 'unassigned' ? 'selected' : ''; ?>>Unassigned only</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

        </form>
    </section>
    <?php endif; ?>

    <!-- RESULTS -->
    <div class="results-bar">
        <h2>Classes</h2>
        <span class="results-count">
            <?php echo number_format($total_classes); ?> class<?php echo $total_classes === 1 ? '' : 'es'; ?>
        </span>
    </div>

    <!-- CLASS GRID -->
    <?php
    /* Apply client-side filter */
    $search_q = strtolower(trim($_GET['q'] ?? ''));
    $show_q   = $_GET['show'] ?? '';

    $filtered_classes = array_filter($classes, function ($c) use ($search_q, $show_q) {
        if ($search_q !== '' && strpos(strtolower($c['label']), $search_q) === false) {
            return false;
        }
        if ($show_q === 'assigned'   && !$c['current_teacher']) return false;
        if ($show_q === 'unassigned' &&  $c['current_teacher']) return false;
        return true;
    });

    if (empty($filtered_classes)):
    ?>

        <div class="empty">
            <div class="empty-icon">🏫</div>
            <h3>No classes match</h3>
            <p>Try a different search or clear the filter.</p>
        </div>

    <?php else: ?>

        <div class="class-grid">
            <?php foreach ($filtered_classes as $c):
                $has_teacher = !empty($c['current_teacher']);
                $initial = $has_teacher
                    ? strtoupper(mb_substr($c['current_teacher'], 0, 1))
                    : '?';
                $current_ct_id = $c['current_class_teacher_id'] ?? 0;
            ?>
                <div class="class-card <?php echo $has_teacher ? 'assigned' : 'unassigned'; ?>">

                    <div class="class-card-head">
                        <div class="class-icon">
                            <?php echo (int)$c['class_level']; ?>
                        </div>
                        <div class="class-info">
                            <div class="class-name"><?php echo e($c['label']); ?></div>
                            <div class="class-meta">
                                Level <?php echo (int)$c['class_level']; ?>
                                · <?php echo (int)$c['student_count']; ?> student<?php echo (int)$c['student_count'] === 1 ? '' : 's'; ?>
                            </div>
                        </div>
                    </div>

                    <div class="class-card-body">

                        <!-- CURRENT TEACHER -->
                        <div class="current-teacher <?php echo $has_teacher ? '' : 'empty'; ?>">
                            <div class="ct-avatar">
                                <?php echo e($initial); ?>
                            </div>
                            <div class="ct-info">
                                <div class="ct-role-label">
                                    <?php echo $has_teacher ? 'Current Class Teacher' : 'No Class Teacher'; ?>
                                </div>
                                <div class="ct-name">
                                    <?php echo $has_teacher
                                        ? e($c['current_teacher'])
                                        : 'Not yet assigned'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ACTIONS ON CURRENT TEACHER -->
                        <?php if ($has_teacher && $current_ct_id > 0): ?>
                            <div class="ct-actions">
                                <form method="POST" style="display:contents;"
                                      onsubmit="return confirm('Remove this teacher from being class teacher?');">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="class_teacher_id" value="<?php echo $current_ct_id; ?>">
                                    <button type="submit" class="icon-btn danger">Remove</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- ASSIGN / CHANGE -->
                        <form method="POST" action="manage_class_teachers.php" class="assign-form">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="class_id" value="<?php echo (int)$c['class_id']; ?>">

                            <select name="teacher_id" class="assign-select" required
                                <?php echo $active_year ? '' : 'disabled'; ?>>
                                <option value="">
                                    <?php echo $has_teacher ? '— Change to another teacher —' : '— Select teacher —'; ?>
                                </option>
                                <?php foreach ($teachers as $t):
                                    $already = $teacher_class_counts[(int)$t['teacher_id']] ?? 0;
                                    $hint = $already > 0 ? " · currently {$already} class" . ($already === 1 ? '' : 'es') : '';
                                ?>
                                    <option value="<?php echo (int)$t['teacher_id']; ?>">
                                        <?php echo e($t['full_name']); ?><?php echo e($hint); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" class="assign-btn"
                                <?php echo $active_year ? '' : 'disabled'; ?>>
                                <?php echo $has_teacher ? 'Change' : 'Assign'; ?>
                            </button>
                        </form>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>

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
        (trim($_GET['q'] ?? '') !== '') || (($_GET['show'] ?? '') !== '')
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