<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Verify Headteacher
|--------------------------------------------------------------------------
*/
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


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['assign_flash'] = ['type' => $type, 'message' => $message];
    header('Location: manage_assignments.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


/*
|--------------------------------------------------------------------------
| Active academic year (needed early for some queries)
|--------------------------------------------------------------------------
*/
$active_year = null;
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
$active_year_id = $active_year ? (int) $active_year['academic_year_id'] : 0;


/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* ---------- Bulk assign ---------- */
    if ($action === 'assign') {

        $teacher_id  = (int) ($_POST['teacher_id'] ?? 0);
        $class_ids   = $_POST['class_ids']  ?? [];
        $subject_ids = $_POST['subject_ids'] ?? [];

        $class_ids   = array_values(array_unique(array_map('intval', (array) $class_ids)));
        $subject_ids = array_values(array_unique(array_map('intval', (array) $subject_ids)));

        if (!$active_year) {
            redirect_with_flash('error', 'There is no active academic year. Please activate one first.');
        }

        if ($teacher_id <= 0) {
            redirect_with_flash('error', 'Please choose a teacher.');
        }
        if (empty($class_ids) || empty($subject_ids)) {
            redirect_with_flash('error', 'Please select at least one class and one subject.');
        }

        /* Confirm teacher */
        $stmt = mysqli_prepare(
            $conn,
            "SELECT teacher_id, assignment_type FROM teachers WHERE teacher_id = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
        mysqli_stmt_execute($stmt);
        $t = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$t) {
            redirect_with_flash('error', 'Teacher not found.');
        }
        if ($t['assignment_type'] === 'headteacher') {
            redirect_with_flash('error', 'Cannot assign classes to the Headteacher.');
        }

        /* Insert class × subject combinations */
        $inserted = 0;
        $skipped  = 0;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO teacher_assignments
                (teacher_id, class_id, subject_id, academic_year_id, status)
             VALUES (?, ?, ?, ?, 'active')"
        );

        foreach ($class_ids as $cid) {
            foreach ($subject_ids as $sid) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'iiii',
                    $teacher_id,
                    $cid,
                    $sid,
                    $active_year_id
                );
                mysqli_stmt_execute($stmt);

                if (mysqli_stmt_affected_rows($stmt) > 0) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
        }
        mysqli_stmt_close($stmt);

        if ($inserted === 0 && $skipped > 0) {
            redirect_with_flash('error', 'All selected assignments already exist for this teacher.');
        }

        $msg = "Assigned $inserted new assignment" . ($inserted === 1 ? '' : 's') . '.';
        if ($skipped > 0) {
            $msg .= " ($skipped duplicate" . ($skipped === 1 ? '' : 's') . " skipped)";
        }

        redirect_with_flash('success', $msg);
    }

    /* ---------- Remove one ---------- */
    if ($action === 'remove') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        if ($assignment_id <= 0) {
            redirect_with_flash('error', 'Invalid assignment.');
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM teacher_assignments WHERE assignment_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $assignment_id);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($deleted > 0) {
            redirect_with_flash('success', 'Assignment removed.');
        }
        redirect_with_flash('error', 'Assignment not found.');
    }

    /* ---------- Toggle status ---------- */
    if ($action === 'toggle') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        if ($assignment_id <= 0) {
            redirect_with_flash('error', 'Invalid assignment.');
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE teacher_assignments
             SET status = IF(status = 'active', 'inactive', 'active')
             WHERE assignment_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $assignment_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        redirect_with_flash('success', 'Assignment status updated.');
    }

    /* ---------- Clear all for one teacher ---------- */
    if ($action === 'clear_teacher') {
        $teacher_id = (int) ($_POST['teacher_id'] ?? 0);

        if ($teacher_id <= 0) {
            redirect_with_flash('error', 'Invalid teacher.');
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM teacher_assignments WHERE teacher_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        redirect_with_flash('success', "Cleared $deleted assignment" . ($deleted === 1 ? '' : 's') . '.');
    }
}


/*
|--------------------------------------------------------------------------
| Load teachers (with specialization + current assignment count)
|--------------------------------------------------------------------------
*/

$teachers = [];
$res = mysqli_query(
    $conn,
    "SELECT t.teacher_id,
            t.employee_no,
            t.assignment_type,
            t.specialization,
            t.qualification,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.email
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.assignment_type <> 'headteacher'
       AND u.status = 'active'
     ORDER BY t.specialization ASC, u.first_name ASC, u.last_name ASC"
);

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {

        $row['full_name'] = trim(
            $row['first_name'] . ' ' .
            ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );

        $spec = trim((string) $row['specialization']);
        $row['display_name'] = $spec !== ''
            ? $row['full_name'] . ' · ' . $spec
            : $row['full_name'];

        $teachers[] = $row;
    }
}

/* Count active assignments per teacher for the active year */
$teacher_assignment_counts = [];

if ($active_year_id > 0) {
    $res = mysqli_query(
        $conn,
        "SELECT teacher_id, COUNT(*) AS c
         FROM teacher_assignments
         WHERE status = 'active'
           AND academic_year_id = $active_year_id
         GROUP BY teacher_id"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $teacher_assignment_counts[(int)$row['teacher_id']] = (int)$row['c'];
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load classes
|--------------------------------------------------------------------------
*/

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
        $row['label'] = $row['class_name']
            . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $classes[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| Load subjects
|--------------------------------------------------------------------------
*/

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
        $subjects[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| All academic years (for the filter dropdown)
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| Filter + Load assignments
|--------------------------------------------------------------------------
*/
$filter_teacher  = (int) ($_GET['teacher']    ?? 0);
$filter_class    = (int) ($_GET['class']      ?? 0);
$filter_year     = (int) ($_GET['year']       ?? 0);
$filter_status   = $_GET['status'] ?? '';

$where  = ["1=1"];
$params = [];
$types  = '';

if ($filter_teacher > 0) {
    $where[]  = "ta.teacher_id = ?";
    $params[] = $filter_teacher;
    $types   .= 'i';
}
if ($filter_class > 0) {
    $where[]  = "ta.class_id = ?";
    $params[] = $filter_class;
    $types   .= 'i';
}
if ($filter_year > 0) {
    $where[]  = "ta.academic_year_id = ?";
    $params[] = $filter_year;
    $types   .= 'i';
}
if (in_array($filter_status, ['active', 'inactive'], true)) {
    $where[]  = "ta.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}

$sql = "
    SELECT
        ta.assignment_id,
        ta.status,
        ta.assigned_at,

        t.teacher_id,
        t.employee_no,
        t.assignment_type,
        t.specialization,

        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,

        c.class_id,
        c.class_name,
        c.stream,

        s.subject_id,
        s.subject_name,
        s.subject_type,

        ay.academic_year_id,
        ay.year AS academic_year
    FROM teacher_assignments ta
    INNER JOIN teachers  t  ON t.teacher_id      = ta.teacher_id
    INNER JOIN users     u  ON u.user_id         = t.user_id
    INNER JOIN classes   c  ON c.class_id        = ta.class_id
    INNER JOIN subjects  s  ON s.subject_id      = ta.subject_id
    INNER JOIN academic_years ay ON ay.academic_year_id = ta.academic_year_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY u.first_name ASC, c.class_name ASC, s.subject_name ASC
";

$assignments = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['teacher_name'] = trim(
            $row['first_name'] . ' ' .
            ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );
        $row['class_label'] = $row['class_name']
            . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $assignments[] = $row;
    }
    mysqli_stmt_close($stmt);
}

/* Stats */
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'teachers' => 0];

$res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')   AS active_total,
        SUM(status = 'inactive') AS inactive_total,
        COUNT(DISTINCT teacher_id) AS teacher_total
     FROM teacher_assignments"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['total']    = (int) $row['total'];
    $stats['active']   = (int) $row['active_total'];
    $stats['inactive'] = (int) $row['inactive_total'];
    $stats['teachers'] = (int) $row['teacher_total'];
}

$flash = $_SESSION['assign_flash'] ?? null;
unset($_SESSION['assign_flash']);

$has_filters = ($filter_teacher > 0 || $filter_class > 0 || $filter_year > 0 || $filter_status !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Manage Assignments | PSRMS</title>

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

        /* PAGE HEADER */
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
            font-size: 12px;
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

        .btn[disabled] { opacity: .55; cursor: not-allowed; }

        /* ALERTS */
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
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px 20px;
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
            font-size: 24px;
            font-weight: 750;
            margin-top: 6px;
        }

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
            display: inline-block;
        }

        .filter-panel.collapsed .filter-toggle .chev { transform: rotate(-90deg); }

        .filter-form {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group { min-width: 0; }

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
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* TABLE */
        .table-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        thead th {
            text-align: left;
            padding: 13px 16px;
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 12.5px;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfbf8; }

        .teacher-name {
            color: var(--navy);
            font-weight: 700;
            font-size: 13px;
        }

        .teacher-meta {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 2px;
        }

        /* PILLS */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 750;
            padding: 5px 11px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }

        .pill-class    { background: var(--blue-bg);   color: var(--blue); }
        .pill-subject  { background: var(--green-bg);  color: var(--green); }
        .pill-year     { background: var(--orange-bg); color: var(--orange); }

        /* STATUS */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status::before {
            content: "";
            width: 5px; height: 5px;
            border-radius: 50%;
        }

        .status-active   { color: var(--green);  background: var(--green-bg); }
        .status-active::before { background: var(--green); }

        .status-inactive { color: var(--orange); background: var(--orange-bg); }
        .status-inactive::before { background: var(--orange); }

        /* ACTIONS */
        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 32px;
            padding: 0 11px;
            font-family: inherit;
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        .icon-btn.danger { color: var(--red); border-color: #efd2d2; }
        .icon-btn.danger:hover { background: var(--red-bg); border-color: var(--red); }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty h3 { color: var(--navy); font-size: 14px; margin-bottom: 5px; }

        /* MOBILE CARDS */
        .card-list { display: none; }

        .assignment-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .assignment-card:last-child { border-bottom: none; }

        .assignment-card-top {
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid #f0f1f3;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .assignment-card-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 12px;
        }

        .assignment-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
            margin-bottom: 12px;
        }

        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 3px;
        }

        .meta-item .v {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .assignment-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .assignment-card-actions .icon-btn,
        .assignment-card-actions form,
        .assignment-card-actions form button {
            width: 100%;
            min-height: 42px;
            font-size: 12px;
        }

        /* MODAL */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16, 24, 43, .55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .2s ease;
        }

        .modal-backdrop.open { display: flex; opacity: 1; }

        .modal {
            background: var(--white);
            border-radius: 14px;
            width: 100%;
            max-width: 720px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1;
        }

        .modal-header h2 { color: var(--navy); font-size: 15px; font-weight: 700; }

        .modal-close {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            border: none;
            border-radius: 8px;
            color: var(--muted);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            -webkit-tap-highlight-color: transparent;
        }

        .modal-close:hover { background: #e5e7eb; color: var(--navy); }

        .modal-body { padding: 22px; }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        /* FORM IN MODAL */
        .form-section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px dashed var(--border);
        }

        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 12px;
        }

        .form-section-title::before {
            content: "";
            width: 3px;
            height: 14px;
            background: var(--gold);
            border-radius: 2px;
        }

        .form-group { min-width: 0; margin-bottom: 14px; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-control {
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
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* Locked active year */
        .active-year-display {
            display: flex;
            align-items: center;
            gap: 10px;
            height: 44px;
            padding: 0 14px;
            background: var(--green-bg);
            border: 1px solid #cfe5d7;
            border-radius: 8px;
            color: var(--green);
            font-size: 13.5px;
            font-weight: 700;
            letter-spacing: .3px;
        }

        .year-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
            flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(62, 118, 85, .15);
        }

        .no-active-year {
            padding: 14px;
            background: var(--orange-bg);
            border: 1px solid #ecd9a8;
            border-radius: 8px;
            color: var(--orange);
            font-size: 12.5px;
            font-weight: 600;
            line-height: 1.5;
        }

        .no-active-year a {
            color: var(--orange);
            font-weight: 800;
            text-decoration: underline;
        }

        /* Chip multi-select */
        .chip-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            max-height: 240px;
            overflow-y: auto;
            padding: 4px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
        }

        .chip {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 8px;
            background: var(--white);
            border: 1px solid var(--border);
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text);
            transition: .12s ease;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .chip:hover { border-color: var(--gold); }

        .chip input { display: none; }

        .chip .box {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            border: 2px solid #cfd4dc;
            border-radius: 4px;
            transition: .15s ease;
            position: relative;
        }

        .chip.checked {
            background: var(--navy);
            border-color: var(--navy);
            color: var(--white);
        }

        .chip.checked .box {
            background: var(--gold);
            border-color: var(--gold);
        }

        .chip.checked .box::after {
            content: "✓";
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy);
            font-size: 11px;
            font-weight: 900;
        }

        .chip-empty {
            padding: 20px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        .chip-tools {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .chip-tools button {
            background: transparent;
            border: none;
            color: var(--navy);
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            padding: 4px 0;
            text-decoration: underline;
            -webkit-tap-highlight-color: transparent;
        }

        .chip-tools button:hover { color: var(--gold); }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr 1fr; }
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
            .page-title p  { font-size: 12px; line-height: 1.45; }

            .page-header .btn {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 13.5px; }

            .table-wrapper { display: none; }
            .card-list { display: block; }

            .assignment-card-meta { grid-template-columns: 1fr 1fr; }

            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal {
                max-width: 100%;
                max-height: 92vh;
                border-radius: 16px 16px 0 0;
            }
            .modal-header { padding: 16px 18px; }
            .modal-body   { padding: 18px; }
            .modal-footer { padding: 14px 18px; flex-direction: column-reverse; }
            .modal-footer .btn { width: 100%; }

            .chip-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }
            .stats-grid { gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .value { font-size: 19px; }
            .assignment-card-meta { grid-template-columns: 1fr; gap: 8px; }
            .assignment-card-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card .value { font-size: 18px; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .modal-footer { padding-bottom: max(14px, env(safe-area-inset-bottom)); }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Manage Assignments';
$topbar_subtitle = 'Assign subjects & classes to teachers';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Manage Assignments</h1>
            <p>Assign classes and subjects to each teacher in the school.</p>
        </div>

        <button type="button" class="btn btn-primary"
            <?php echo $active_year ? 'onclick="openAssignModal()"' : 'disabled title="No active academic year"'; ?>>
            + New Assignment
        </button>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- ACTIVE YEAR BANNER -->
    <?php if ($active_year): ?>
        <div class="alert success" style="display:flex;align-items:center;gap:10px;">
            <span class="year-dot"></span>
            <span>
                Active academic year: <strong><?php echo e($active_year['year']); ?></strong>
                &nbsp;— all new assignments will be added to this year.
            </span>
        </div>
    <?php else: ?>
        <div class="alert error">
            ⚠ There is no active academic year. 
            <a href="../admin/academic_years.php" style="color:inherit;font-weight:800;text-decoration:underline;">
                Activate one
            </a>
            to enable new assignments.
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total</div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo number_format($stats['active']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Inactive</div>
            <div class="value"><?php echo number_format($stats['inactive']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Teachers Assigned</div>
            <div class="value"><?php echo number_format($stats['teachers']); ?></div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="manage_assignments.php" class="filter-form">

            <div class="filter-group">
                <label>Teacher</label>
                <select name="teacher" class="filter-control">
                    <option value="">All teachers</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?php echo (int)$t['teacher_id']; ?>"
                            <?php echo $filter_teacher === (int)$t['teacher_id'] ? 'selected' : ''; ?>>
                            <?php echo e($t['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Class</label>
                <select name="class" class="filter-control">
                    <option value="">All classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int)$c['class_id']; ?>"
                            <?php echo $filter_class === (int)$c['class_id'] ? 'selected' : ''; ?>>
                            <?php echo e($c['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Academic Year</label>
                <select name="year" class="filter-control">
                    <option value="">All years</option>
                    <?php foreach ($academic_years as $y): ?>
                        <option value="<?php echo (int)$y['academic_year_id']; ?>"
                            <?php echo $filter_year === (int)$y['academic_year_id'] ? 'selected' : ''; ?>>
                            <?php echo e($y['year']); ?>
                            <?php echo $y['status'] === 'active' ? ' (active)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All statuses</option>
                    <option value="active"   <?php echo $filter_status === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="manage_assignments.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>

        </form>
    </section>

    <!-- TABLE / CARDS -->
    <div class="table-card">

        <?php if (empty($assignments)): ?>

            <div class="empty">
                <h3>No assignments found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        Try clearing the filters.
                    <?php else: ?>
                        Click <strong>New Assignment</strong> to assign subjects to a teacher.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Academic Year</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td>
                                <div class="teacher-name"><?php echo e($a['teacher_name']); ?></div>
                                <div class="teacher-meta">
                                    <?php echo e($a['email']); ?>
                                    <?php if (!empty($a['specialization'])): ?>
                                        · <?php echo e($a['specialization']); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="pill pill-class">
                                    <?php echo e($a['class_label']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="pill pill-subject">
                                    <?php echo e($a['subject_name']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="pill pill-year">
                                    <?php echo e($a['academic_year']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status status-<?php echo e($a['status']); ?>">
                                    <?php echo e(ucfirst($a['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">

                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Toggle this assignment status?');">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int)$a['assignment_id']; ?>">
                                        <button type="submit" class="icon-btn">
                                            <?php echo $a['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>

                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Remove this assignment?');">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="assignment_id" value="<?php echo (int)$a['assignment_id']; ?>">
                                        <button type="submit" class="icon-btn danger">Remove</button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($assignments as $a): ?>
                    <div class="assignment-card">

                        <div class="assignment-card-top">
                            <div style="min-width:0;flex:1;">
                                <div class="teacher-name"><?php echo e($a['teacher_name']); ?></div>
                                <div class="teacher-meta">
                                    <?php echo e($a['email']); ?>
                                    <?php if (!empty($a['specialization'])): ?>
                                        · <?php echo e($a['specialization']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="status status-<?php echo e($a['status']); ?>">
                                <?php echo e(ucfirst($a['status'])); ?>
                            </span>
                        </div>

                        <div class="assignment-card-pills">
                            <span class="pill pill-class"><?php echo e($a['class_label']); ?></span>
                            <span class="pill pill-subject"><?php echo e($a['subject_name']); ?></span>
                            <span class="pill pill-year"><?php echo e($a['academic_year']); ?></span>
                        </div>

                        <div class="assignment-card-meta">
                            <div class="meta-item">
                                <span class="k">Employee No.</span>
                                <span class="v"><?php echo e($a['employee_no'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Assigned</span>
                                <span class="v">
                                    <?php echo $a['assigned_at']
                                        ? e(date('M j, Y', strtotime($a['assigned_at'])))
                                        : '—'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="assignment-card-actions">
                            <form method="POST"
                                  onsubmit="return confirm('Toggle this assignment status?');">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="assignment_id" value="<?php echo (int)$a['assignment_id']; ?>">
                                <button type="submit" class="icon-btn">
                                    <?php echo $a['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>

                            <form method="POST"
                                  onsubmit="return confirm('Remove this assignment?');">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="assignment_id" value="<?php echo (int)$a['assignment_id']; ?>">
                                <button type="submit" class="icon-btn danger">Remove</button>
                            </form>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</main>


<!-- =========================================================
     ASSIGN MODAL
========================================================= -->
<div class="modal-backdrop" id="assignModal">
    <div class="modal">
        <form method="POST" action="manage_assignments.php" id="assignForm">

            <div class="modal-header">
                <h2>New Assignment</h2>
                <button type="button" class="modal-close" onclick="closeModal('assignModal')">✕</button>
            </div>

            <div class="modal-body">

                <!-- TEACHER + YEAR -->
                <div class="form-section">
                    <div class="form-section-title">Teacher &amp; Academic Year</div>

                    <div class="form-group">
                        <label>Teacher <span style="color:var(--red);">*</span></label>
                        <select name="teacher_id" class="form-control" required>
                            <option value="">— Select teacher —</option>
                            <?php foreach ($teachers as $t):
                                $count = $teacher_assignment_counts[(int)$t['teacher_id']] ?? 0;
                                $hint  = $count > 0
                                    ? ' · currently ' . $count . ' assignment' . ($count === 1 ? '' : 's')
                                    : '';
                            ?>
                                <option value="<?php echo (int)$t['teacher_id']; ?>">
                                    <?php echo e($t['display_name'] . $hint); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Academic year — LOCKED -->
                    <div class="form-group">
                        <label>Academic Year</label>

                        <?php if ($active_year): ?>
                            <div class="active-year-display">
                                <span class="year-dot"></span>
                                <span><?php echo e($active_year['year']); ?> — Active</span>
                            </div>
                        <?php else: ?>
                            <div class="no-active-year">
                                ⚠ No active academic year.
                                <a href="../admin/academic_years.php">Activate one</a>
                                before assigning.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CLASSES -->
                <div class="form-section">
                    <div class="form-section-title">Classes <span style="color:var(--red);">*</span></div>

                    <div class="chip-tools">
                        <button type="button" onclick="selectAll('class')">Select all</button>
                        <button type="button" onclick="clearAll('class')">Clear</button>
                    </div>

                    <?php if (empty($classes)): ?>
                        <div class="chip-empty">No active classes found.</div>
                    <?php else: ?>
                        <div class="chip-grid">
                            <?php foreach ($classes as $c): ?>
                                <label class="chip" data-group="class">
                                    <input type="checkbox" name="class_ids[]" value="<?php echo (int)$c['class_id']; ?>">
                                    <span class="box"></span>
                                    <span><?php echo e($c['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SUBJECTS -->
                <div class="form-section">
                    <div class="form-section-title">Subjects <span style="color:var(--red);">*</span></div>

                    <div class="chip-tools">
                        <button type="button" onclick="selectAll('subject')">Select all</button>
                        <button type="button" onclick="clearAll('subject')">Clear</button>
                    </div>

                    <?php if (empty($subjects)): ?>
                        <div class="chip-empty">No active subjects found.</div>
                    <?php else: ?>
                        <div class="chip-grid">
                            <?php foreach ($subjects as $s): ?>
                                <label class="chip">
                                    <input type="checkbox" name="subject_ids[]" value="<?php echo (int)$s['subject_id']; ?>">
                                    <span class="box"></span>
                                    <span><?php echo e($s['subject_name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('assignModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary"
                    <?php echo $active_year ? '' : 'disabled'; ?>>
                    Assign Selected
                </button>
            </div>

            <input type="hidden" name="action" value="assign">
        </form>
    </div>
</div>


<script>
/* =========================================================
   MODAL HELPERS
========================================================= */
function openAssignModal() {
    document.getElementById('assignModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) closeModal(bd.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open')
            .forEach(bd => closeModal(bd.id));
    }
});


/* =========================================================
   CHIP TOGGLES
========================================================= */
document.querySelectorAll('.chip').forEach(chip => {
    const input = chip.querySelector('input');
    const sync  = () => chip.classList.toggle('checked', input.checked);
    input.addEventListener('change', sync);
    sync();
});

function selectAll(kind) {
    const name = kind === 'class' ? 'class_ids[]' : 'subject_ids[]';
    document.querySelectorAll(`input[name="${name}"]`).forEach(cb => {
        cb.checked = true;
        cb.closest('.chip').classList.add('checked');
    });
}

function clearAll(kind) {
    const name = kind === 'class' ? 'class_ids[]' : 'subject_ids[]';
    document.querySelectorAll(`input[name="${name}"]`).forEach(cb => {
        cb.checked = false;
        cb.closest('.chip').classList.remove('checked');
    });
}


/* =========================================================
   FILTER PANEL COLLAPSE (mobile only)
========================================================= */
(function () {
    const filterPanel  = document.getElementById('filterPanel');
    const filterToggle = document.getElementById('filterToggle');
    if (!filterPanel || !filterToggle) return;

    const mq              = window.matchMedia('(max-width: 800px)');
    const hasActiveFilter = <?php echo $has_filters ? 'true' : 'false'; ?>;

    function syncFilterState() {
        if (mq.matches) {
            filterPanel.classList.toggle('collapsed', !hasActiveFilter);
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