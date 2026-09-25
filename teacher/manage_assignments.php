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

function full_name(array $row): string
{
    return trim(
        $row['first_name'] . ' ' .
        (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') .
        $row['last_name']
    );
}


/*
|--------------------------------------------------------------------------
| Active academic year
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

    /* ----------------------------------------------------------------
       ASSIGN — with conflict detection for subject/class
    ---------------------------------------------------------------- */
    if ($action === 'assign') {

        $teacher_id  = (int) ($_POST['teacher_id'] ?? 0);
        $class_ids   = $_POST['class_ids']  ?? [];
        $subject_ids = $_POST['subject_ids'] ?? [];
        $conflict_ok = ($_POST['conflict_ok'] ?? '') === '1';

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

        /* ------------------------------------------------------------
           Conflict check: for each (class, subject), find whether
           ANOTHER active teacher already teaches that subject in
           that class. If yes → block and return the conflict info.
        ------------------------------------------------------------ */
        $conflicts = [];

        if (!$conflict_ok) {

            $check = mysqli_prepare(
                $conn,
                "SELECT ta.assignment_id,
                        ta.teacher_id,
                        u.first_name, u.middle_name, u.last_name
                 FROM teacher_assignments ta
                 INNER JOIN teachers t ON t.teacher_id = ta.teacher_id
                 INNER JOIN users u   ON u.user_id    = t.user_id
                 WHERE ta.class_id = ?
                   AND ta.subject_id = ?
                   AND ta.academic_year_id = ?
                   AND ta.status = 'active'
                   AND ta.teacher_id <> ?
                 LIMIT 1"
            );

            foreach ($class_ids as $cid) {
                foreach ($subject_ids as $sid) {

                    mysqli_stmt_bind_param($check, 'iiii', $cid, $sid, $active_year_id, $teacher_id);
                    mysqli_stmt_execute($check);
                    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

                    if ($c) {
                        $conflicts[] = [
                            'class_id'   => $cid,
                            'subject_id' => $sid,
                            'teacher_id' => (int) $c['teacher_id'],
                            'teacher'    => full_name($c),
                        ];
                    }
                }
            }
            mysqli_stmt_close($check);

            if (!empty($conflicts)) {

                /* Resolve names for display */
                $class_names   = [];
                $subject_names = [];

                if (!empty($class_ids)) {
                    $ph = implode(',', array_fill(0, count($class_ids), '?'));
                    $s  = mysqli_prepare($conn, "SELECT class_id, class_name, stream FROM classes WHERE class_id IN ($ph)");
                    mysqli_stmt_bind_param($s, str_repeat('i', count($class_ids)), ...$class_ids);
                    mysqli_stmt_execute($s);
                    $r = mysqli_stmt_get_result($s);
                    while ($row = mysqli_fetch_assoc($r)) {
                        $class_names[(int)$row['class_id']] = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
                    }
                    mysqli_stmt_close($s);
                }

                if (!empty($subject_ids)) {
                    $ph = implode(',', array_fill(0, count($subject_ids), '?'));
                    $s  = mysqli_prepare($conn, "SELECT subject_id, subject_name FROM subjects WHERE subject_id IN ($ph)");
                    mysqli_stmt_bind_param($s, str_repeat('i', count($subject_ids)), ...$subject_ids);
                    mysqli_stmt_execute($s);
                    $r = mysqli_stmt_get_result($s);
                    while ($row = mysqli_fetch_assoc($r)) {
                        $subject_names[(int)$row['subject_id']] = $row['subject_name'];
                    }
                    mysqli_stmt_close($s);
                }

                foreach ($conflicts as &$cf) {
                    $cf['class_name']   = $class_names[$cf['class_id']]     ?? 'Class';
                    $cf['subject_name'] = $subject_names[$cf['subject_id']] ?? 'Subject';
                }
                unset($cf);

                /* Store conflict data so the modal can show it on reload */
                $_SESSION['assign_conflict'] = [
                    'teacher_id'  => $teacher_id,
                    'class_ids'   => $class_ids,
                    'subject_ids' => $subject_ids,
                    'conflicts'   => $conflicts,
                ];

                redirect_with_flash('error', 'Some subjects are already assigned to another teacher in the same class. Please review the warning below.');
            }
        }

        /* ------------------------------------------------------------
           If conflict_ok was sent (admin confirmed Replace), we delete
           the existing conflicting assignment before inserting the new
           one so ownership transfers cleanly.
        ------------------------------------------------------------ */
        if ($conflict_ok) {
            $del = mysqli_prepare(
                $conn,
                "DELETE FROM teacher_assignments
                 WHERE class_id = ?
                   AND subject_id = ?
                   AND academic_year_id = ?
                   AND status = 'active'
                   AND teacher_id <> ?"
            );

            foreach ($class_ids as $cid) {
                foreach ($subject_ids as $sid) {
                    mysqli_stmt_bind_param($del, 'iiii', $cid, $sid, $active_year_id, $teacher_id);
                    mysqli_stmt_execute($del);
                }
            }
            mysqli_stmt_close($del);
        }

        /* ------------------------------------------------------------
           Insert
        ------------------------------------------------------------ */
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
                mysqli_stmt_bind_param($stmt, 'iiii', $teacher_id, $cid, $sid, $active_year_id);
                mysqli_stmt_execute($stmt);

                if (mysqli_stmt_affected_rows($stmt) > 0) $inserted++;
                else $skipped++;
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

    /* ----------------------------------------------------------------
       REMOVE
    ---------------------------------------------------------------- */
    if ($action === 'remove') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        if ($assignment_id <= 0) redirect_with_flash('error', 'Invalid assignment.');

        $stmt = mysqli_prepare($conn, "DELETE FROM teacher_assignments WHERE assignment_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $assignment_id);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($deleted > 0) redirect_with_flash('success', 'Assignment removed.');
        redirect_with_flash('error', 'Assignment not found.');
    }

    /* ----------------------------------------------------------------
       TOGGLE
    ---------------------------------------------------------------- */
    if ($action === 'toggle') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        if ($assignment_id <= 0) redirect_with_flash('error', 'Invalid assignment.');

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

    /* ----------------------------------------------------------------
       CLEAR TEACHER
    ---------------------------------------------------------------- */
    if ($action === 'clear_teacher') {
        $teacher_id = (int) ($_POST['teacher_id'] ?? 0);

        if ($teacher_id <= 0) redirect_with_flash('error', 'Invalid teacher.');

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
| Load teachers
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
        $row['full_name'] = full_name($row);

        $spec = trim((string) $row['specialization']);
        $row['display_name'] = $spec !== ''
            ? $row['full_name'] . ' · ' . $spec
            : $row['full_name'];

        $teachers[] = $row;
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
| All academic years (filter dropdown)
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
| Filters
|--------------------------------------------------------------------------
*/
$filter_teacher  = (int) ($_GET['teacher'] ?? 0);
$filter_class    = (int) ($_GET['class']   ?? 0);
$filter_year     = (int) ($_GET['year']    ?? 0);
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
        c.class_level,

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
    ORDER BY c.class_level ASC, c.class_name ASC, c.stream ASC,
             u.first_name ASC, s.subject_name ASC
";

$assignments = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['teacher_name'] = full_name($row);
        $row['class_label']  = $row['class_name']
            . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $assignments[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| Group by class  →  { class_id: { info, teachers: { teacher_id: {...} } } }
|--------------------------------------------------------------------------
*/
$grouped = [];

foreach ($assignments as $a) {
    $cid = (int) $a['class_id'];
    $tid = (int) $a['teacher_id'];

    if (!isset($grouped[$cid])) {
        $grouped[$cid] = [
            'class_id'    => $cid,
            'class_label' => $a['class_label'],
            'class_name'  => $a['class_name'],
            'stream'      => $a['stream'],
            'class_level' => (int) $a['class_level'],
            'teachers'    => [],
        ];
    }

    if (!isset($grouped[$cid]['teachers'][$tid])) {
        $grouped[$cid]['teachers'][$tid] = [
            'teacher_id' => $tid,
            'name'       => $a['teacher_name'],
            'email'      => $a['email'],
            'employee_no'=> $a['employee_no'],
            'specialization' => $a['specialization'],
            'subjects'   => [],
        ];
    }

    $grouped[$cid]['teachers'][$tid]['subjects'][] = [
        'assignment_id' => (int) $a['assignment_id'],
        'subject_id'    => (int) $a['subject_id'],
        'subject_name'  => $a['subject_name'],
        'subject_type'  => $a['subject_type'],
        'status'        => $a['status'],
        'academic_year' => $a['academic_year'],
        'assigned_at'   => $a['assigned_at'],
    ];
}

$class_groups = array_values($grouped);


/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'teachers' => 0, 'classes' => 0];

$res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')   AS active_total,
        SUM(status = 'inactive') AS inactive_total,
        COUNT(DISTINCT teacher_id) AS teacher_total,
        COUNT(DISTINCT class_id)   AS class_total
     FROM teacher_assignments"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['total']    = (int) $row['total'];
    $stats['active']   = (int) $row['active_total'];
    $stats['inactive'] = (int) $row['inactive_total'];
    $stats['teachers'] = (int) $row['teacher_total'];
    $stats['classes']  = (int) $row['class_total'];
}

$flash            = $_SESSION['assign_flash']    ?? null;  unset($_SESSION['assign_flash']);
$assign_conflict  = $_SESSION['assign_conflict'] ?? null;  unset($_SESSION['assign_conflict']);

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
            line-height: 1.5;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
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
            font-size: 23px;
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

        /* =========================================================
           CLASS CARDS
        ========================================================= */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .class-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(23,35,60,.03);
        }

        .class-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            background: var(--navy);
            color: var(--white);
        }

        .class-card-header h3 {
            font-size: 15px;
            font-weight: 750;
            letter-spacing: .2px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .class-card-header .class-icon {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: var(--gold);
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 900;
        }

        .class-card-header .teacher-count {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .4px;
            text-transform: uppercase;
            background: rgba(255,255,255,.12);
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .class-card-body {
            padding: 4px 0 0;
        }

        /* TEACHER BLOCK */
        .teacher-block {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f1f3;
        }

        .teacher-block:last-child { border-bottom: none; }

        .teacher-block-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .teacher-avatar {
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
        }

        .teacher-block-info { flex: 1; min-width: 0; }

        .teacher-block-info .name {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 2px;
            overflow-wrap: anywhere;
        }

        .teacher-block-info .meta {
            color: var(--muted);
            font-size: 10.5px;
            overflow-wrap: anywhere;
        }

        /* SUBJECT LIST */
        .subject-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-left: 48px;
        }

        .subject-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px 5px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid #cfe5d7;
            line-height: 1.2;
            max-width: 100%;
        }

        .subject-chip.inactive {
            background: #f1f2f4;
            color: #666;
            border-color: #e0e2e6;
        }

        .subject-chip .subject-name {
            overflow-wrap: anywhere;
        }

        .subject-chip .chip-actions {
            display: inline-flex;
            gap: 3px;
            margin-left: 2px;
        }

        .subject-chip .chip-btn {
            border: none;
            background: rgba(0,0,0,.06);
            border-radius: 20px;
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            color: inherit;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }

        .subject-chip .chip-btn:hover { background: rgba(0,0,0,.14); }

        .subject-chip .chip-btn.danger:hover {
            background: var(--red);
            color: #fff;
        }

        /* EMPTY CLASS */
        .class-empty {
            padding: 26px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        .class-empty strong { color: var(--navy); }

        /* EMPTY WHOLE PAGE */
        .empty {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty h3 { color: var(--navy); font-size: 14px; margin-bottom: 5px; }

        /* =========================================================
           CONFLICT BANNER
        ========================================================= */
        .conflict-banner {
            background: #fff8e5;
            border: 1px solid #f0e0a8;
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 20px;
        }

        .conflict-banner h4 {
            color: #7a5a00;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .conflict-banner .icon {
            width: 22px; height: 22px;
            background: var(--gold);
            color: var(--navy);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 13px;
        }

        .conflict-list {
            list-style: none;
            margin: 0 0 14px;
            padding: 0;
        }

        .conflict-list li {
            padding: 8px 12px;
            background: #fff;
            border: 1px solid #efe2ba;
            border-radius: 7px;
            font-size: 12px;
            color: #7a5a00;
            line-height: 1.55;
            margin-bottom: 6px;
        }

        .conflict-list li strong { color: var(--navy); }

        .conflict-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* =========================================================
           MODAL
        ========================================================= */
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

        /* FORM */
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

        .no-active-year a { color: var(--orange); font-weight: 800; text-decoration: underline; }

        /* CHIPS */
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
        }

        .chip-tools button:hover { color: var(--gold); }

        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .classes-grid { grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); }
        }

        @media (max-width: 1100px) {
            .stats-grid  { grid-template-columns: repeat(3, 1fr); }
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

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 13.5px; }

            .classes-grid { grid-template-columns: 1fr; gap: 14px; }

            .class-card-header { padding: 14px 16px; }
            .class-card-header h3 { font-size: 14px; }
            .teacher-block { padding: 14px 16px; }
            .subject-list { margin-left: 0; }

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
            .stats-grid { gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .value { font-size: 19px; }
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
            <p>See who teaches what, in each class.</p>
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


    <!-- =========================================================
         CONFLICT BANNER
    ========================================================= -->
    <?php if ($assign_conflict): ?>

        <div class="conflict-banner">
            <h4>
                <span class="icon">!</span>
                Subject already assigned
            </h4>

            <p style="font-size:12.5px;color:#7a5a00;margin-bottom:12px;line-height:1.55;">
                The following subject(s) are already taught by another teacher
                in the selected class for the active academic year. Choose
                <strong>Replace</strong> to transfer ownership to the new teacher,
                or <strong>Cancel</strong> to keep the current assignment.
            </p>

            <ul class="conflict-list">
                <?php foreach ($assign_conflict['conflicts'] as $cf): ?>
                    <li>
                        <strong><?php echo e($cf['subject_name']); ?></strong>
                        in <strong><?php echo e($cf['class_name']); ?></strong>
                        is already taught by
                        <strong><?php echo e($cf['teacher']); ?></strong>.
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="POST" action="manage_assignments.php" class="conflict-actions">
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="teacher_id" value="<?php echo (int)$assign_conflict['teacher_id']; ?>">
                <input type="hidden" name="conflict_ok" value="1">
                <?php foreach ($assign_conflict['class_ids'] as $cid): ?>
                    <input type="hidden" name="class_ids[]" value="<?php echo (int)$cid; ?>">
                <?php endforeach; ?>
                <?php foreach ($assign_conflict['subject_ids'] as $sid): ?>
                    <input type="hidden" name="subject_ids[]" value="<?php echo (int)$sid; ?>">
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary">
                    Replace &amp; Assign Anyway
                </button>
                <a href="manage_assignments.php" class="btn btn-ghost">Cancel</a>
            </form>
        </div>

    <?php endif; ?>


    <!-- ACTIVE YEAR BANNER -->
    <?php if ($active_year): ?>
        <div class="alert success" style="display:flex;align-items:center;gap:10px;">
            <span class="year-dot"></span>
            <span>
                Active academic year:
                <strong><?php echo e($active_year['year']); ?></strong>
                — new assignments go to this year.
            </span>
        </div>
    <?php else: ?>
        <div class="alert error">
            ⚠ No active academic year. 
            <a href="../admin/academic_years.php"
               style="color:inherit;font-weight:800;text-decoration:underline;">
                Activate one
            </a>
            to enable assignments.
        </div>
    <?php endif; ?>


    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Classes</div>
            <div class="value"><?php echo number_format($stats['classes']); ?></div>
        </div>
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
            <div class="label">Teachers</div>
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


    <!-- =========================================================
         CLASS CARDS
    ========================================================= -->
    <?php if (empty($class_groups)): ?>

        <div class="empty">
            <h3>No assignments yet</h3>
            <p>
                <?php if ($has_filters): ?>
                    Try clearing the filters.
                <?php else: ?>
                    Click <strong>New Assignment</strong> to assign subjects to a teacher.
                <?php endif; ?>
            </p>
        </div>

    <?php else: ?>

        <div class="classes-grid">
            <?php foreach ($class_groups as $group):
                $teacher_count = count($group['teachers']);
            ?>
                <div class="class-card">

                    <div class="class-card-header">
                        <h3>
                            <span class="class-icon"><?php echo e(mb_substr($group['class_name'], 0, 1)); ?></span>
                            <?php echo e($group['class_label']); ?>
                        </h3>
                        <span class="teacher-count">
                            <?php echo $teacher_count; ?> teacher<?php echo $teacher_count === 1 ? '' : 's'; ?>
                        </span>
                    </div>

                    <div class="class-card-body">

                        <?php if ($teacher_count === 0): ?>
                            <div class="class-empty">
                                No teachers assigned to this class yet.
                            </div>
                        <?php else: ?>

                            <?php foreach ($group['teachers'] as $tdata): ?>

                                <div class="teacher-block">

                                    <div class="teacher-block-top">

                                        <div class="teacher-avatar">
                                            <?php echo e(strtoupper(mb_substr($tdata['name'], 0, 1))); ?>
                                        </div>

                                        <div class="teacher-block-info">
                                            <div class="name"><?php echo e($tdata['name']); ?></div>
                                            <div class="meta">
                                                <?php echo e($tdata['email'] ?: '—'); ?>
                                                <?php if (!empty($tdata['specialization'])): ?>
                                                    · <?php echo e($tdata['specialization']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                    </div>

                                    <div class="subject-list">
                                        <?php foreach ($tdata['subjects'] as $sub): ?>

                                            <span class="subject-chip <?php echo $sub['status'] === 'active' ? '' : 'inactive'; ?>">

                                                <span class="subject-name">
                                                    <?php echo e($sub['subject_name']); ?>
                                                </span>

                                                <span class="chip-actions">

                                                    <form method="POST" style="display:inline;margin:0;"
                                                          onsubmit="return confirm('Toggle status of this subject?');">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="assignment_id" value="<?php echo $sub['assignment_id']; ?>">
                                                        <button type="submit"
                                                                class="chip-btn"
                                                                title="<?php echo $sub['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                            <?php echo $sub['status'] === 'active' ? '⏸' : '▶'; ?>
                                                        </button>
                                                    </form>

                                                    <form method="POST" style="display:inline;margin:0;"
                                                          onsubmit="return confirm('Remove this subject assignment?');">
                                                        <input type="hidden" name="action" value="remove">
                                                        <input type="hidden" name="assignment_id" value="<?php echo $sub['assignment_id']; ?>">
                                                        <button type="submit"
                                                                class="chip-btn danger"
                                                                title="Remove">✕</button>
                                                    </form>

                                                </span>

                                            </span>

                                        <?php endforeach; ?>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

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
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo (int)$t['teacher_id']; ?>">
                                    <?php echo e($t['display_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

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
                                <label class="chip">
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
   MODAL
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
   FILTER PANEL COLLAPSE (mobile)
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

    syncState();

    function syncState() {
        if (mq.matches) {
            filterPanel.classList.toggle('collapsed', !hasActiveFilter);
        } else {
            filterPanel.classList.remove('collapsed');
        }
    }

    mq.addEventListener
        ? mq.addEventListener('change', syncState)
        : mq.addListener(syncState);

    filterToggle.addEventListener('click', () => {
        if (!mq.matches) return;
        filterPanel.classList.toggle('collapsed');
    });
})();
</script>

</body>
</html>