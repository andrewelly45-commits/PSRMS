<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);


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

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['assign_teacher_flash'] = ['type' => $type, 'message' => $message];
    header('Location: assign_teacher.php?id=' . (int)($_GET['id'] ?? 0));
    exit;
}


/* =========================================================================
   VALIDATE TEACHER ID
   ========================================================================= */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: teachers.php');
    exit;
}

$teacher_id = (int) $_GET['id'];


/* =========================================================================
   ACTIVE ACADEMIC YEAR
   ========================================================================= */

$active_year    = null;
$active_year_id = 0;

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
    }
}


/* =========================================================================
   LOAD TEACHER
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        t.teacher_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.employment_status,
        t.assignment_type,

        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.phone
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.teacher_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
mysqli_stmt_execute($stmt);
$teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$teacher) {
    header('Location: teachers.php');
    exit;
}

$teacher['full_name'] = trim(
    $teacher['first_name'] . ' ' .
    ($teacher['middle_name'] ? $teacher['middle_name'] . ' ' : '') .
    $teacher['last_name']
);

$is_academic     = ($teacher['assignment_type'] === 'academic');
$is_head_teacher = ($teacher['assignment_type'] === 'headteacher');


/* =========================================================================
   LOAD CLASSES
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
        $classes[] = $row;
    }
}


/* =========================================================================
   LOAD SUBJECTS
   ========================================================================= */

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


/* =========================================================================
   CURRENT ASSIGNMENTS
   ========================================================================= */

$assigned_classes  = [];
$assigned_subjects = [];

if ($active_year_id > 0 && tableExists($conn, 'teacher_assignments')) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT class_id, subject_id
         FROM teacher_assignments
         WHERE teacher_id = ?
           AND academic_year_id = ?
           AND status = 'active'"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $assigned_classes[(int)$row['class_id']]    = true;
        $assigned_subjects[(int)$row['subject_id']] = true;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   SAVE
   ========================================================================= */

$flash = $_SESSION['assign_teacher_flash'] ?? null;
unset($_SESSION['assign_teacher_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$active_year) {
        redirect_with_flash('error', 'No active academic year. Please activate one first.');
    }

    $selected_classes   = $_POST['classes']  ?? [];
    $selected_subjects  = $_POST['subjects'] ?? [];
    $academic_flag      = isset($_POST['academic']);
    $head_flag          = isset($_POST['head_teacher']);
    $class_teacher_flag = isset($_POST['class_teacher']);

    if (!is_array($selected_classes))  $selected_classes  = [];
    if (!is_array($selected_subjects)) $selected_subjects = [];

    $selected_classes  = array_values(array_unique(array_map('intval', $selected_classes)));
    $selected_subjects = array_values(array_unique(array_map('intval', $selected_subjects)));

    mysqli_begin_transaction($conn);

    try {

        /* ---- 1. Replace teacher_assignments ---- */
        if (tableExists($conn, 'teacher_assignments')) {

            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM teacher_assignments
                 WHERE teacher_id = ?
                   AND academic_year_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if (!empty($selected_classes) && !empty($selected_subjects)) {

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO teacher_assignments
                        (teacher_id, class_id, subject_id, academic_year_id, status)
                     VALUES (?, ?, ?, ?, 'active')"
                );

                foreach ($selected_classes as $cid) {
                    foreach ($selected_subjects as $sid) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            'iiii',
                            $teacher_id,
                            $cid,
                            $sid,
                            $active_year_id
                        );
                        mysqli_stmt_execute($stmt);
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }


        /* ---- 2. class_teachers ---- */
        if (tableExists($conn, 'class_teachers')) {

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE class_teachers
                 SET status = 'inactive'
                 WHERE teacher_id = ?
                   AND academic_year_id = ?
                   AND status = 'active'"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($class_teacher_flag && !empty($selected_classes)) {

                $ct_class = $selected_classes[0];

                /* Demote any existing class teacher of that class */
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE class_teachers
                     SET status = 'inactive'
                     WHERE class_id = ?
                       AND academic_year_id = ?
                       AND status = 'active'"
                );
                mysqli_stmt_bind_param($stmt, 'ii', $ct_class, $active_year_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                /* Insert new */
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO class_teachers
                        (class_id, teacher_id, academic_year_id, status)
                     VALUES (?, ?, ?, 'active')"
                );
                mysqli_stmt_bind_param($stmt, 'iii', $ct_class, $teacher_id, $active_year_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }


        /* ---- 3. Role ---- */
        $new_role = 'teacher';
        if ($head_flag) {
            $new_role = 'headteacher';
        } elseif ($academic_flag) {
            $new_role = 'academic';
        }

        if ($new_role === 'headteacher') {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE teachers
                 SET assignment_type = 'teacher'
                 WHERE assignment_type = 'headteacher'
                   AND teacher_id <> ?"
            );
            mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE teachers
             SET assignment_type = ?,
                 role_assigned_by = ?,
                 role_assigned_at = NOW()
             WHERE teacher_id = ?"
        );
        $assigner_id = (int) ($_SESSION['user_id'] ?? 0);
        mysqli_stmt_bind_param($stmt, 'sii', $new_role, $assigner_id, $teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);

        $_SESSION['assign_teacher_flash'] = [
            'type'    => 'success',
            'message' => 'Teacher assignments saved successfully.'
        ];
        header('Location: assign_teacher.php?id=' . $teacher_id);
        exit;

    } catch (Exception $ex) {
        mysqli_rollback($conn);
        $flash = ['type' => 'error', 'message' => 'Save failed: ' . $ex->getMessage()];
    }
}


/* =========================================================================
   RELOAD CURRENT STATE AFTER POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $assigned_classes  = [];
    $assigned_subjects = [];

    if ($active_year_id > 0 && tableExists($conn, 'teacher_assignments')) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT class_id, subject_id
             FROM teacher_assignments
             WHERE teacher_id = ?
               AND academic_year_id = ?
               AND status = 'active'"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $assigned_classes[(int)$row['class_id']]    = true;
            $assigned_subjects[(int)$row['subject_id']] = true;
        }
        mysqli_stmt_close($stmt);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT assignment_type FROM teachers WHERE teacher_id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        $teacher['assignment_type'] = $row['assignment_type'];
        $is_academic     = ($row['assignment_type'] === 'academic');
        $is_head_teacher = ($row['assignment_type'] === 'headteacher');
    }
}


/* =========================================================================
   IS CLASS TEACHER?
   ========================================================================= */

$is_class_teacher = false;
$class_teacher_of = null;

if ($active_year_id > 0 && tableExists($conn, 'class_teachers')) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT ct.class_id, c.class_name, c.stream
         FROM class_teachers ct
         INNER JOIN classes c ON c.class_id = ct.class_id
         WHERE ct.teacher_id = ?
           AND ct.academic_year_id = ?
           AND ct.status = 'active'
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $teacher_id, $active_year_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        $is_class_teacher = true;
        $class_teacher_of = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Assign Teacher | PSRMS</title>

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

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 42px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--navy);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .back-button:hover { border-color: var(--gold); }

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

        /* TEACHER CARD */
        .teacher-card {
            background: var(--navy);
            border-radius: 12px;
            padding: 24px;
            color: var(--white);
            margin-bottom: 22px;
            max-width: 1000px;
            position: relative;
            overflow: hidden;
        }

        .teacher-card::after {
            content: "";
            position: absolute;
            right: -50px;
            top: -50px;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            border: 1px solid rgba(201,162,39,.15);
            pointer-events: none;
        }

        .teacher-card small {
            color: var(--gold-light);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 750;
        }

        .teacher-card h2 {
            margin-top: 8px;
            font-size: 21px;
            font-weight: 750;
        }

        .teacher-details {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-top: 18px;
        }

        .teacher-detail { font-size: 12px; }

        .teacher-detail span {
            display: block;
            color: #aeb7c8;
            margin-bottom: 4px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-weight: 700;
        }

        .teacher-detail strong { font-weight: 650; }

        /* ROLE CHIPS */
        .role-chips {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .role-chip {
            display: inline-block;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: rgba(255,255,255,.1);
            color: var(--gold-light);
        }
        .role-chip.active {
            background: var(--gold);
            color: var(--navy);
        }

        /* FORM CARD */
        .form-card {
            max-width: 1000px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .form-section {
            padding: 24px;
            border-bottom: 1px solid var(--border);
        }

        .form-section:last-of-type { border-bottom: none; }

        .section-title {
            display: flex;
            align-items: center;
            gap: 11px;
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            margin-bottom: 6px;
        }

        .section-number {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--navy);
            color: var(--gold-light);
            font-size: 11px;
            font-weight: 750;
            flex-shrink: 0;
        }

        .section-description {
            color: var(--muted);
            font-size: 11.5px;
            margin-left: 37px;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        /* CHECKBOX GRID */
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .checkbox-item { position: relative; }

        .checkbox-item input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .checkbox-item label {
            min-height: 46px;
            display: flex;
            align-items: center;
            padding: 0 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfb;
            color: var(--text);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .checkbox-item label:hover { border-color: var(--gold); }

        .checkbox-item input:checked + label {
            border-color: var(--gold);
            background: rgba(201,162,39,.08);
            color: var(--navy);
            font-weight: 700;
        }

        .checkbox-item input:checked + label::before {
            content: "✓";
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            border-radius: 4px;
            background: var(--gold);
            color: var(--navy);
            font-size: 11px;
            font-weight: 900;
            margin-right: 8px;
            flex-shrink: 0;
        }

        /* RESPONSIBILITIES */
        .responsibility-box {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .responsibility-item { position: relative; }

        .responsibility-item input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .responsibility-item label {
            display: block;
            padding: 16px 18px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfcfb;
            cursor: pointer;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .responsibility-item label:hover { border-color: var(--gold); }

        .responsibility-item input:checked + label {
            border-color: var(--gold);
            background: rgba(201,162,39,.08);
        }

        .responsibility-item strong {
            display: block;
            color: var(--navy);
            font-size: 13px;
            margin-bottom: 5px;
            font-weight: 750;
        }

        .responsibility-item span {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.55;
        }

        /* FOOTER */
        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 18px 24px;
            background: #fafaf8;
            border-top: 1px solid var(--border);
        }

        .cancel-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 20px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--white);
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 650;
        }
        .cancel-button:hover { color: var(--navy); border-color: #c8ccd3; }

        .save-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 24px;
            border: none;
            border-radius: 9px;
            background: var(--navy);
            color: var(--white);
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            transition: .15s ease;
        }
        .save-button:hover { background: var(--navy-dark); }
        .save-button[disabled] { opacity: .55; cursor: not-allowed; }

        /* =========================================================
           RESPONSIVE — TABLET
        ========================================================= */
        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }
            .checkbox-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* =========================================================
           RESPONSIVE — MOBILE
        ========================================================= */
        @media (max-width: 800px) {

            body.sidebar-collapsed .main-content { margin-left: 0; }

            /* Header */
            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                margin-bottom: 18px;
            }

            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; line-height: 1.45; }

            .back-button {
                width: 100%;
                justify-content: center;
                min-height: 46px;
                font-size: 13px;
            }

            /* Teacher card */
            .teacher-card {
                padding: 20px;
                margin-bottom: 18px;
            }

            .teacher-card h2 { font-size: 18px; line-height: 1.3; }

            .teacher-details {
                flex-direction: column;
                gap: 12px;
                margin-top: 14px;
            }

            .teacher-detail { font-size: 12.5px; }

            .role-chips { margin-top: 14px; }

            /* Form sections */
            .form-section { padding: 20px 18px; }

            .section-title { font-size: 13px; }

            .section-description {
                margin-left: 0;
                margin-top: 8px;
                font-size: 11.5px;
                line-height: 1.55;
            }

            /* Checkbox grid → single column on phone */
            .checkbox-grid { grid-template-columns: 1fr; gap: 8px; }

            .checkbox-item label {
                min-height: 48px;
                font-size: 13px;
                padding: 0 16px;
            }

            /* Responsibilities → single column */
            .responsibility-box { grid-template-columns: 1fr; gap: 10px; }

            .responsibility-item label { padding: 16px; }

            .responsibility-item strong { font-size: 14px; }
            .responsibility-item span   { font-size: 11.5px; }

            /* Footer stacks */
            .form-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
                gap: 8px;
            }

            .form-footer .cancel-button,
            .form-footer .save-button {
                width: 100%;
                min-height: 46px;
                font-size: 13.5px;
            }

            /* Alerts */
            .alert {
                font-size: 12.5px;
                padding: 12px 14px;
                line-height: 1.5;
            }
        }

        /* =========================================================
           RESPONSIVE — SMALL PHONES
        ========================================================= */
        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .teacher-card {
                padding: 16px;
                border-radius: 10px;
            }

            .teacher-card h2 { font-size: 16.5px; }

            .form-section { padding: 16px 14px; }

            .section-number {
                width: 24px;
                height: 24px;
                font-size: 10.5px;
            }

            .section-title { font-size: 12.5px; }

            .checkbox-item label {
                min-height: 46px;
                font-size: 12.5px;
                padding: 0 14px;
            }

            .responsibility-item label { padding: 14px; }

            .role-chip { font-size: 9px; }
        }

        /* =========================================================
           SAFE AREA — iPhone home bar
        ========================================================= */
        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .form-footer {
                    padding-bottom: max(14px, env(safe-area-inset-bottom));
                }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Assign Teacher';
$topbar_subtitle = 'Manage classes, subjects & responsibilities';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Assign Teacher</h1>
            <p>Manage teaching classes, subjects and additional responsibilities.</p>
        </div>

        <a href="teachers.php" class="back-button">← Back to Teachers</a>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- NO ACTIVE YEAR -->
    <?php if (!$active_year): ?>
        <div class="alert error">
            ⚠ There is no active academic year. Please activate one from
            <a href="academic_years.php" style="color:inherit;font-weight:800;text-decoration:underline;">
                Academic Years
            </a>
            before saving assignments.
        </div>
    <?php endif; ?>

    <!-- TEACHER CARD -->
    <div class="teacher-card">
        <small>Selected Teacher</small>
        <h2><?php echo e($teacher['full_name']); ?></h2>

        <div class="teacher-details">
            <div class="teacher-detail">
                <span>Employee Number</span>
                <strong><?php echo e($teacher['employee_no'] ?: '—'); ?></strong>
            </div>
            <div class="teacher-detail">
                <span>Qualification</span>
                <strong><?php echo e($teacher['qualification'] ?: '—'); ?></strong>
            </div>
            <div class="teacher-detail">
                <span>Specialization</span>
                <strong><?php echo e($teacher['specialization'] ?: '—'); ?></strong>
            </div>
            <div class="teacher-detail">
                <span>Email</span>
                <strong><?php echo e($teacher['email'] ?: '—'); ?></strong>
            </div>
        </div>

        <div class="role-chips">
            <?php if ($is_head_teacher): ?>
                <span class="role-chip active">Headteacher</span>
            <?php endif; ?>
            <?php if ($is_academic): ?>
                <span class="role-chip active">Academic Master</span>
            <?php endif; ?>
            <?php if ($is_class_teacher): ?>
                <span class="role-chip active">Class Teacher · <?php echo e($class_teacher_of); ?></span>
            <?php endif; ?>
            <?php if (
                $teacher['assignment_type'] === 'teacher' &&
                !$is_class_teacher
            ): ?>
                <span class="role-chip">Regular Teacher</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- FORM -->
    <form method="POST" class="form-card">

        <!-- CLASSES -->
        <div class="form-section">
            <div class="section-title">
                <span class="section-number">1</span>
                Classes
            </div>
            <p class="section-description">
                Select all classes this teacher is allowed to teach.
            </p>

            <?php if (empty($classes)): ?>
                <p style="color:var(--muted);font-size:12px;">No active classes found.</p>
            <?php else: ?>
                <div class="checkbox-grid">
                    <?php foreach ($classes as $c): ?>
                        <div class="checkbox-item">
                            <input
                                type="checkbox"
                                id="class_<?php echo (int)$c['class_id']; ?>"
                                name="classes[]"
                                value="<?php echo (int)$c['class_id']; ?>"
                                <?php echo isset($assigned_classes[(int)$c['class_id']]) ? 'checked' : ''; ?>
                            >
                            <label for="class_<?php echo (int)$c['class_id']; ?>">
                                <?php echo e($c['label']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- SUBJECTS -->
        <div class="form-section">
            <div class="section-title">
                <span class="section-number">2</span>
                Subjects
            </div>
            <p class="section-description">
                Select the subjects this teacher will teach.
            </p>

            <?php if (empty($subjects)): ?>
                <p style="color:var(--muted);font-size:12px;">No active subjects found.</p>
            <?php else: ?>
                <div class="checkbox-grid">
                    <?php foreach ($subjects as $s): ?>
                        <div class="checkbox-item">
                            <input
                                type="checkbox"
                                id="subject_<?php echo (int)$s['subject_id']; ?>"
                                name="subjects[]"
                                value="<?php echo (int)$s['subject_id']; ?>"
                                <?php echo isset($assigned_subjects[(int)$s['subject_id']]) ? 'checked' : ''; ?>
                            >
                            <label for="subject_<?php echo (int)$s['subject_id']; ?>">
                                <?php echo e($s['subject_name']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RESPONSIBILITIES -->
        <div class="form-section">
            <div class="section-title">
                <span class="section-number">3</span>
                Additional Responsibilities
            </div>
            <p class="section-description">
                Assign extra roles. Only one headteacher can exist at a time.
            </p>

            <div class="responsibility-box">

                <div class="responsibility-item">
                    <input
                        type="checkbox"
                        id="academic"
                        name="academic"
                        <?php echo $is_academic ? 'checked' : ''; ?>
                    >
                    <label for="academic">
                        <strong>Academic Master</strong>
                        <span>
                            Can monitor academic activities, review marks
                            and access academic reports.
                        </span>
                    </label>
                </div>

                <div class="responsibility-item">
                    <input
                        type="checkbox"
                        id="class_teacher"
                        name="class_teacher"
                        <?php echo $is_class_teacher ? 'checked' : ''; ?>
                    >
                    <label for="class_teacher">
                        <strong>Class Teacher</strong>
                        <span>
                            Becomes class teacher of the <em>first selected class above</em>.
                            Can then mark attendance for that class.
                        </span>
                    </label>
                </div>

                <div class="responsibility-item">
                    <input
                        type="checkbox"
                        id="head_teacher"
                        name="head_teacher"
                        <?php echo $is_head_teacher ? 'checked' : ''; ?>
                    >
                    <label for="head_teacher">
                        <strong>Headteacher</strong>
                        <span>
                            Assigns this teacher as the school headteacher.
                            Only one headteacher is allowed.
                        </span>
                    </label>
                </div>

            </div>
        </div>

        <!-- FOOTER -->
        <div class="form-footer">
            <a href="teachers.php" class="cancel-button">Cancel</a>
            <button type="submit" class="save-button"
                <?php echo $active_year ? '' : 'disabled title="No active academic year"'; ?>>
                Save Assignments
            </button>
        </div>

    </form>

</main>

</body>
</html>