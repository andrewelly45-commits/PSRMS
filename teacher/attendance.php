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
$teacher_role = $teacher['assignment_type'];
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
   AUTO-CREATE attendance TABLE
   ========================================================================= */

if (!tableExists($conn, 'attendance')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS attendance (
            attendance_id   INT(11) NOT NULL AUTO_INCREMENT,
            student_id      INT(11) NOT NULL,
            class_id        INT(11) NOT NULL,
            attendance_date DATE NOT NULL,
            status          ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
            remarks         VARCHAR(255) DEFAULT NULL,
            marked_by       INT(11) DEFAULT NULL,
            created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (attendance_id),
            UNIQUE KEY uniq_student_date (student_id, attendance_date),
            KEY idx_class_date (class_id, attendance_date),
            KEY idx_marked_by (marked_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


/* =========================================================================
   ACTIVE ACADEMIC YEAR
   ========================================================================= */

$active_year_n  = 0;
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
        $active_year_n  = (int) $row['year'];
        $active_year_id = (int) $row['academic_year_id'];
    }
}


/* =========================================================================
   BUILD ALLOWED CLASSES — ONLY CLASS TEACHER CLASSES
   ---------------------------------------------------------------------------
   NO exceptions for headteacher or academic master.
   The teacher MUST be an active class teacher for the class.
   ========================================================================= */

$allowed_classes = [];   /* [class_id => row] */

if (tableExists($conn, 'class_teachers')) {

    $sql = "
        SELECT c.class_id, c.class_name, c.stream, c.class_level
        FROM class_teachers ct
        INNER JOIN classes c ON c.class_id = ct.class_id
        WHERE ct.teacher_id = ?
          AND ct.status = 'active'
          AND c.status = 'active'
    ";

    $params = [$teacher_id];
    $types  = 'i';

    if ($active_year_id > 0) {
        $sql .= " AND ct.academic_year_id = ?";
        $params[] = $active_year_id;
        $types   .= 'i';
    }

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['label'] = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
            $row['role']  = 'class_teacher';
            $allowed_classes[(int)$row['class_id']] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

ksort($allowed_classes);
$allowed_classes_list = array_values($allowed_classes);

/* Fast lookup set for permission check */
$allowed_class_ids = array_column($allowed_classes_list, 'class_id');
$allowed_class_ids = array_map('intval', $allowed_class_ids);


/* =========================================================================
   SAVE ATTENDANCE — CLASS TEACHERS ONLY
   ========================================================================= */

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $class_id = (int) ($_POST['class_id'] ?? 0);
    $att_date = $_POST['attendance_date'] ?? date('Y-m-d');
    $statuses = $_POST['status']  ?? [];
    $remarks  = $_POST['remarks'] ?? [];

    if ($class_id <= 0) {
        $flash = ['type' => 'error', 'message' => 'Please select a class.'];
    } elseif (!DateTime::createFromFormat('Y-m-d', $att_date)) {
        $flash = ['type' => 'error', 'message' => 'Invalid date.'];
    } elseif ($att_date > date('Y-m-d')) {
        $flash = ['type' => 'error', 'message' => 'Cannot mark attendance for a future date.'];
    } elseif (!in_array($class_id, $allowed_class_ids, true)) {
        /* Not the class teacher of this class → hard rejection */
        $flash = [
            'type'    => 'error',
            'message' => 'Only the assigned class teacher can mark attendance for this class.'
        ];
    } elseif (empty($statuses)) {
        $flash = ['type' => 'error', 'message' => 'No students to save.'];
    }

    if (!$flash) {

        $allowed_status = ['present', 'absent', 'late', 'excused'];

        mysqli_begin_transaction($conn);

        try {

            $inserted = 0;
            $updated  = 0;
            $skipped  = 0;

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO attendance
                    (student_id, class_id, attendance_date, status, remarks, marked_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    remarks = VALUES(remarks),
                    marked_by = VALUES(marked_by),
                    class_id = VALUES(class_id)"
            );

            foreach ($statuses as $student_id => $status) {

                $student_id = (int) $student_id;
                if ($student_id <= 0) continue;

                $status = in_array($status, $allowed_status, true) ? $status : 'present';
                $remark = trim($remarks[$student_id] ?? '');
                if (strlen($remark) > 255) $remark = substr($remark, 0, 255);
                $remark = $remark !== '' ? $remark : null;

                mysqli_stmt_bind_param(
                    $stmt,
                    'iisssi',
                    $student_id,
                    $class_id,
                    $att_date,
                    $status,
                    $remark,
                    $user_id
                );

                if (mysqli_stmt_execute($stmt)) {
                    if (mysqli_stmt_affected_rows($stmt) === 1) {
                        $inserted++;
                    } else {
                        $updated++;
                    }
                } else {
                    $skipped++;
                }
            }

            mysqli_stmt_close($stmt);
            mysqli_commit($conn);

            $parts = [];
            if ($inserted > 0) $parts[] = "$inserted new";
            if ($updated > 0)  $parts[] = "$updated updated";
            if ($skipped > 0)  $parts[] = "$skipped skipped";
            $msg = $parts ? 'Attendance saved: ' . implode(', ', $parts) . '.' : 'Nothing to save.';

            $flash = ['type' => 'success', 'message' => $msg];

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $flash = ['type' => 'error', 'message' => 'Save failed: ' . $ex->getMessage()];
        }
    }
}


/* =========================================================================
   VIEW — SELECTED CLASS + DATE
   ========================================================================= */

$view_class_id = (int) ($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
$view_date     = $_GET['attendance_date'] ?? ($_POST['attendance_date'] ?? date('Y-m-d'));

if (!DateTime::createFromFormat('Y-m-d', $view_date)) {
    $view_date = date('Y-m-d');
}

/* Auto-pick first allowed class if none selected */
if ($view_class_id === 0 && !empty($allowed_classes_list)) {
    $view_class_id = (int) $allowed_classes_list[0]['class_id'];
}

$can_view = in_array($view_class_id, $allowed_class_ids, true);

$students = [];
$existing_attendance = [];

if ($can_view && $view_class_id > 0) {

    /* ---------------------------------------------------------------------
       1) Fetch active students in this class
    --------------------------------------------------------------------- */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT student_id, admission_no, full_name, gender, photo, status
         FROM students
         WHERE class_id = ? AND status = 'active'
         ORDER BY full_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $view_class_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $students[] = $row;
    }
    mysqli_stmt_close($stmt);

    /* ---------------------------------------------------------------------
       2) Fetch existing attendance for the selected date
    --------------------------------------------------------------------- */
    if (!empty($students)) {

        $student_ids  = array_column($students, 'student_id');
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $types        = str_repeat('i', count($student_ids)) . 's';

        $params   = $student_ids;
        $params[] = $view_date;

        $stmt = mysqli_prepare(
            $conn,
            "SELECT student_id, status, remarks
             FROM attendance
             WHERE student_id IN ($placeholders)
               AND attendance_date = ?"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($res)) {
                $existing_attendance[(int)$row['student_id']] = $row;
            }
            mysqli_stmt_close($stmt);
        }
    }
}


/* =========================================================================
   SUMMARY
   ========================================================================= */

$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unmarked' => 0];

foreach ($students as $s) {
    $sid = (int)$s['student_id'];
    if (isset($existing_attendance[$sid])) {
        $st = $existing_attendance[$sid]['status'];
        if (isset($summary[$st])) $summary[$st]++;
    } else {
        $summary['unmarked']++;
    }
}

$total_students = count($students);
$marked_count   = $total_students - $summary['unmarked'];

$role_labels = [
    'teacher'     => 'Teacher',
    'academic'    => 'Academic Master',
    'headteacher' => 'Headteacher',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Attendance | PSRMS</title>

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

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 15px;
            background: var(--navy);
            color: var(--gold-light);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .6px;
        }
        .role-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--gold);
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

        /* READ-ONLY ACCESS MESSAGE */
        .readonly-banner {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 20px;
            background: var(--orange-bg);
            border: 1px solid #ecd9a8;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 13px;
            color: var(--orange);
            font-weight: 600;
            line-height: 1.55;
        }
        .readonly-banner .icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--orange);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 15px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .readonly-banner strong { display: block; margin-bottom: 4px; }

        /* CONTROLS */
        .controls-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .controls-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .controls-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .controls-control {
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

        select.controls-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        .controls-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* Selected class banner */
        .selected-class-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--cream);
            font-size: 12px;
            font-weight: 650;
            color: var(--navy);
        }
        .selected-class-banner .role {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: var(--gold-light);
            color: var(--navy);
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 42px;
            padding: 0 18px;
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
        .btn-primary:active { transform: scale(.98); }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; }

        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover { background: var(--gold-light); }

        /* SUMMARY */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .summary-pill {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            text-align: center;
        }

        .summary-pill .num { font-size: 20px; font-weight: 800; line-height: 1; margin-bottom: 4px; }
        .summary-pill .lbl { font-size: 9.5px; font-weight: 750; text-transform: uppercase; letter-spacing: .5px; }

        .summary-pill.present { border-top: 3px solid var(--green); }
        .summary-pill.present .num { color: var(--green); }
        .summary-pill.present .lbl { color: var(--green); }

        .summary-pill.absent { border-top: 3px solid var(--red); }
        .summary-pill.absent .num { color: var(--red); }
        .summary-pill.absent .lbl { color: var(--red); }

        .summary-pill.late { border-top: 3px solid var(--orange); }
        .summary-pill.late .num { color: var(--orange); }
        .summary-pill.late .lbl { color: var(--orange); }

        .summary-pill.excused { border-top: 3px solid var(--blue); }
        .summary-pill.excused .num { color: var(--blue); }
        .summary-pill.excused .lbl { color: var(--blue); }

        .summary-pill.unmarked { border-top: 3px solid var(--muted); }
        .summary-pill.unmarked .num { color: var(--muted); }
        .summary-pill.unmarked .lbl { color: var(--muted); }

        /* BULK */
        .bulk-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 14px;
            padding: 12px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        .bulk-actions .label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-right: 4px;
        }

        .bulk-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 34px;
            padding: 0 14px;
            font-family: inherit;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .bulk-btn:hover { border-color: var(--gold); color: var(--gold); }
        .bulk-btn.present-btn:hover { background: var(--green-bg); border-color: var(--green); color: var(--green); }
        .bulk-btn.absent-btn:hover  { background: var(--red-bg);   border-color: var(--red);   color: var(--red); }

        /* ATT LIST */
        .att-list {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .att-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
            padding: 14px 18px;
            border-bottom: 1px solid #f0f1f3;
            transition: .15s ease;
        }
        .att-row:last-child { border-bottom: none; }

        .att-row.unmarked {
            background: #fbfbf8;
            border-left: 3px solid var(--gold);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .student-avatar,
        .student-avatar img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
            border: 1.5px solid var(--border);
        }

        .student-avatar {
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
        }

        .student-name-wrap { min-width: 0; flex: 1; }

        .student-name {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 2px;
            overflow-wrap: anywhere;
        }

        .student-meta { color: var(--muted); font-size: 10.5px; }

        /* STATUS */
        .status-group {
            display: flex;
            gap: 4px;
            flex-wrap: nowrap;
        }

        .status-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-width: 60px;
            min-height: 38px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            color: var(--muted);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }
        .status-btn:hover { border-color: var(--gold); }

        .status-btn.active.present { background: var(--green);  color: #fff; border-color: var(--green); }
        .status-btn.active.absent  { background: var(--red);    color: #fff; border-color: var(--red); }
        .status-btn.active.late    { background: var(--orange); color: #fff; border-color: var(--orange); }
        .status-btn.active.excused { background: var(--blue);   color: #fff; border-color: var(--blue); }

        .status-btn:active { transform: scale(.96); }

        /* Sticky save bar */
        .save-bar {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            padding: 12px 16px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            background: var(--white);
            border-top: 1px solid var(--border);
            box-shadow: 0 -8px 24px rgba(16,24,43,.08);
            z-index: 900;
            gap: 10px;
            align-items: center;
        }

        .save-bar .save-info { flex: 1; min-width: 0; font-size: 11.5px; font-weight: 700; color: var(--navy); }
        .save-bar .save-info small { display: block; color: var(--muted); font-size: 10.5px; font-weight: 600; margin-top: 2px; }

        /* Empty */
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

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .summary-row { grid-template-columns: repeat(5, 1fr); gap: 8px; }
            .summary-pill { padding: 10px 8px; }
            .summary-pill .num { font-size: 17px; }
            .summary-pill .lbl { font-size: 9px; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 120px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .controls-form { grid-template-columns: 1fr; gap: 12px; }
            .controls-control, .btn { min-height: 46px; font-size: 14px; }

            .summary-row { grid-template-columns: repeat(3, 1fr); gap: 8px; }

            .bulk-actions {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding: 12px 14px;
            }
            .bulk-actions::-webkit-scrollbar { display: none; }
            .bulk-actions .bulk-btn { flex-shrink: 0; }

            .att-row {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 14px 16px;
            }

            .status-group {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 6px;
            }

            .status-btn {
                width: 100%;
                min-width: 0;
                min-height: 42px;
                font-size: 11px;
                padding: 0 4px;
            }

            .save-bar { display: flex; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 120px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .summary-row { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .summary-row .summary-pill:last-child { grid-column: 1 / -1; }

            .summary-pill { padding: 10px 6px; }
            .summary-pill .num { font-size: 18px; }

            .student-avatar,
            .student-avatar img { width: 36px; height: 36px; font-size: 13px; }

            .student-name { font-size: 13px; }
            .student-meta { font-size: 10px; }

            .status-btn { font-size: 10.5px; }
            .att-row { padding: 12px 14px; }
        }

        @media (max-width: 400px) {
            .status-group { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(120px, env(safe-area-inset-bottom));
                }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Attendance';
$topbar_subtitle = 'Mark and view attendance';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Attendance</h1>
            <p>Mark daily attendance for your class.</p>
        </div>

        <span class="role-badge">
            <?php echo e($role_labels[$teacher_role] ?? 'Teacher'); ?>
        </span>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- READ-ONLY: NO CLASS ASSIGNED -->
    <?php if (empty($allowed_classes_list)): ?>

        <div class="readonly-banner">
            <div class="icon">!</div>
            <div>
                <strong>Attendance is restricted to class teachers only.</strong>
                You are not assigned as a class teacher for any class in the active academic year.
                Only the class teacher of a class can mark its attendance.
                Please contact the headteacher if you believe this is an error.
            </div>
        </div>

        <div class="empty">
            <div class="empty-icon">🔒</div>
            <h3>No access to attendance</h3>
            <p>Only class teachers can mark attendance.</p>
        </div>

    <?php else: ?>

        <!-- CONTROLS -->
        <section class="controls-panel">
            <form method="GET" action="attendance.php" class="controls-form">

                <div class="controls-group">
                    <label for="class_id">Class</label>
                    <select name="class_id" id="class_id" class="controls-control"
                            onchange="this.form.submit()">
                        <?php foreach ($allowed_classes_list as $c): ?>
                            <option value="<?php echo (int)$c['class_id']; ?>"
                                <?php echo $view_class_id === (int)$c['class_id'] ? 'selected' : ''; ?>>
                                <?php echo e($c['label']); ?>
                                · Class Teacher
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="controls-group">
                    <label for="attendance_date">Date</label>
                    <input type="date" name="attendance_date" id="attendance_date"
                           class="controls-control"
                           value="<?php echo e($view_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>"
                           onchange="this.form.submit()">
                </div>

                <button type="submit" class="btn btn-primary">Load</button>

            </form>

            <?php if ($view_class_id > 0):
                $current_class = null;
                foreach ($allowed_classes_list as $c) {
                    if ((int)$c['class_id'] === $view_class_id) { $current_class = $c; break; }
                }
                if ($current_class):
            ?>
                <div class="selected-class-banner">
                    <span>You are the class teacher of <strong><?php echo e($current_class['label']); ?></strong></span>
                    <span class="role">Class Teacher</span>
                </div>
            <?php endif; endif; ?>
        </section>

        <!-- EMPTY STUDENTS -->
        <?php if (empty($students)): ?>

            <div class="empty">
                <div class="empty-icon">👥</div>
                <h3>No students found</h3>
                <p>This class has no active students registered.</p>
            </div>

        <?php else: ?>

            <!-- SUMMARY -->
            <div class="summary-row">
                <div class="summary-pill present">
                    <div class="num"><?php echo $summary['present']; ?></div>
                    <div class="lbl">Present</div>
                </div>
                <div class="summary-pill absent">
                    <div class="num"><?php echo $summary['absent']; ?></div>
                    <div class="lbl">Absent</div>
                </div>
                <div class="summary-pill late">
                    <div class="num"><?php echo $summary['late']; ?></div>
                    <div class="lbl">Late</div>
                </div>
                <div class="summary-pill excused">
                    <div class="num"><?php echo $summary['excused']; ?></div>
                    <div class="lbl">Excused</div>
                </div>
                <div class="summary-pill unmarked">
                    <div class="num"><?php echo $summary['unmarked']; ?></div>
                    <div class="lbl">Unmarked</div>
                </div>
            </div>

            <!-- BULK -->
            <div class="bulk-actions">
                <span class="label">Quick:</span>
                <button type="button" class="bulk-btn present-btn" onclick="markAll('present')">
                    ✓ All Present
                </button>
                <button type="button" class="bulk-btn absent-btn" onclick="markAll('absent')">
                    ✗ All Absent
                </button>
                <button type="button" class="bulk-btn" onclick="markAll(null)">
                    Clear
                </button>
            </div>

            <!-- FORM -->
            <form method="POST" action="attendance.php" id="attForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="class_id" value="<?php echo (int)$view_class_id; ?>">
                <input type="hidden" name="attendance_date" value="<?php echo e($view_date); ?>">

                <div class="att-list">
                    <?php foreach ($students as $s):
                        $sid      = (int)$s['student_id'];
                        $existing = $existing_attendance[$sid] ?? null;
                        $cur_st   = $existing ? $existing['status'] : '';
                        $cur_re   = $existing ? ($existing['remarks'] ?? '') : '';
                        $initial  = strtoupper(mb_substr($s['full_name'], 0, 1));
                        $is_unmarked = $cur_st === '';
                    ?>
                        <div class="att-row <?php echo $is_unmarked ? 'unmarked' : ''; ?>"
                             data-student-id="<?php echo $sid; ?>">

                            <div class="student-info">
                                <?php if (!empty($s['photo'])): ?>
                                    <img src="../uploads/students/<?php echo e($s['photo']); ?>"
                                         alt="" class="student-avatar">
                                <?php else: ?>
                                    <div class="student-avatar"><?php echo e($initial); ?></div>
                                <?php endif; ?>

                                <div class="student-name-wrap">
                                    <div class="student-name"><?php echo e($s['full_name']); ?></div>
                                    <div class="student-meta">
                                        <?php echo e($s['admission_no']); ?>
                                        · <?php echo e($s['gender']); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="status-group">
                                <?php
                                $statuses = [
                                    'present' => 'Present',
                                    'absent'  => 'Absent',
                                    'late'    => 'Late',
                                    'excused' => 'Excused',
                                ];
                                foreach ($statuses as $val => $label):
                                ?>
                                    <button type="button"
                                            class="status-btn <?php echo $cur_st === $val ? 'active ' . $val : ''; ?>"
                                            data-status="<?php echo $val; ?>"
                                            onclick="setStatus(this)">
                                        <?php echo $label; ?>
                                    </button>
                                <?php endforeach; ?>

                                <input type="hidden"
                                       name="status[<?php echo $sid; ?>]"
                                       value="<?php echo e($cur_st); ?>"
                                       id="status_<?php echo $sid; ?>">
                                <input type="hidden"
                                       name="remarks[<?php echo $sid; ?>]"
                                       value="<?php echo e($cur_re); ?>">
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- MOBILE STICKY SAVE -->
                <div class="save-bar">
                    <div class="save-info">
                        <?php echo $marked_count; ?> / <?php echo $total_students; ?> marked
                        <small><?php echo $summary['unmarked']; ?> still unmarked</small>
                    </div>
                    <button type="submit" class="btn btn-gold" id="saveBtn"
                            <?php echo $marked_count === 0 ? 'disabled' : ''; ?>>
                        Save
                    </button>
                </div>

                <!-- DESKTOP SAVE -->
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px;">
                    <button type="submit" class="btn btn-primary" id="saveBtnDesktop"
                            <?php echo $marked_count === 0 ? 'disabled' : ''; ?>>
                        Save Attendance
                    </button>
                </div>

            </form>

        <?php endif; ?>

    <?php endif; ?>

</main>


<script>
/* =========================================================
   SET STATUS
========================================================= */
function setStatus(btn) {
    const row     = btn.closest('.att-row');
    const sid     = row.dataset.studentId;
    const val     = btn.dataset.status;
    const hidden  = document.getElementById('status_' + sid);

    row.querySelectorAll('.status-btn').forEach(b => {
        b.classList.remove('active', 'present', 'absent', 'late', 'excused');
    });

    btn.classList.add('active', val);
    hidden.value = val;
    row.classList.remove('unmarked');

    updateSummary();
    updateSaveBtn();
}

/* =========================================================
   MARK ALL
========================================================= */
function markAll(status) {
    document.querySelectorAll('.att-row').forEach(row => {
        const sid = row.dataset.studentId;
        const buttons = row.querySelectorAll('.status-btn');
        const hidden = document.getElementById('status_' + sid);

        buttons.forEach(b => b.classList.remove('active', 'present', 'absent', 'late', 'excused'));

        if (status) {
            const target = row.querySelector(`.status-btn[data-status="${status}"]`);
            if (target) {
                target.classList.add('active', status);
                hidden.value = status;
                row.classList.remove('unmarked');
            }
        } else {
            hidden.value = '';
            row.classList.add('unmarked');
        }
    });

    updateSummary();
    updateSaveBtn();
}

/* =========================================================
   UPDATE SUMMARY
========================================================= */
function updateSummary() {
    const counts = { present: 0, absent: 0, late: 0, excused: 0, unmarked: 0 };

    document.querySelectorAll('.att-row').forEach(row => {
        const sid = row.dataset.studentId;
        const val = document.getElementById('status_' + sid).value;
        if (val && counts.hasOwnProperty(val)) counts[val]++;
        else counts.unmarked++;
    });

    const sP = document.querySelector('.summary-pill.present .num');
    const sA = document.querySelector('.summary-pill.absent  .num');
    const sL = document.querySelector('.summary-pill.late    .num');
    const sE = document.querySelector('.summary-pill.excused .num');
    const sU = document.querySelector('.summary-pill.unmarked .num');

    if (sP) sP.textContent = counts.present;
    if (sA) sA.textContent = counts.absent;
    if (sL) sL.textContent = counts.late;
    if (sE) sE.textContent = counts.excused;
    if (sU) sU.textContent = counts.unmarked;

    const total = counts.present + counts.absent + counts.late + counts.excused;
    const bar = document.querySelector('.save-bar .save-info');
    if (bar) {
        bar.innerHTML = total + ' / ' + (total + counts.unmarked) + ' marked' +
            '<small>' + counts.unmarked + ' still unmarked</small>';
    }
}

/* =========================================================
   ENABLE/DISABLE SAVE
========================================================= */
function updateSaveBtn() {
    const anyMarked = [...document.querySelectorAll('input[type="hidden"][name^="status"]')]
        .some(i => i.value !== '');

    const b1 = document.getElementById('saveBtn');
    const b2 = document.getElementById('saveBtnDesktop');

    if (b1) b1.disabled = !anyMarked;
    if (b2) b2.disabled = !anyMarked;
}
</script>

</body>
</html>