<?php

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| ADD / UPDATE TEACHER ACTIONS
|--------------------------------------------------------------------------
*/
$flash_message = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['form_action'] ?? '';

    if ($form_action === 'update_teacher') {
        $teacher_id       = (int)($_POST['teacher_id'] ?? 0);
        $first_name       = trim($_POST['first_name'] ?? '');
        $middle_name      = trim($_POST['middle_name'] ?? '');
        $last_name        = trim($_POST['last_name'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $gender           = trim($_POST['gender'] ?? '');
        $phone            = trim($_POST['phone'] ?? '');
        $employee_no      = trim($_POST['employee_no'] ?? '');
        $qualification    = trim($_POST['qualification'] ?? '');
        $specialization   = trim($_POST['specialization'] ?? '');
        $employment_status = trim($_POST['employment_status'] ?? 'inactive');
        $assignment_type  = trim($_POST['assignment_type'] ?? 'teacher');

        if ($teacher_id <= 0 || $first_name === '' || $last_name === '' || $email === '') {
            $flash_message = 'Please fill in all required fields.';
            $flash_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash_message = 'Please enter a valid email address.';
            $flash_type = 'error';
        } elseif (!in_array($employment_status, ['active', 'inactive'], true)) {
            $flash_message = 'Invalid employment status.';
            $flash_type = 'error';
        } else {
            $teacher_stmt = mysqli_prepare($conn, "SELECT user_id FROM teachers WHERE teacher_id = ? LIMIT 1");

            if ($teacher_stmt) {
                mysqli_stmt_bind_param($teacher_stmt, 'i', $teacher_id);
                mysqli_stmt_execute($teacher_stmt);
                $teacher_result = mysqli_stmt_get_result($teacher_stmt);
                $teacher_record = $teacher_result ? mysqli_fetch_assoc($teacher_result) : null;
                mysqli_stmt_close($teacher_stmt);

                if (!$teacher_record) {
                    $flash_message = 'Teacher record was not found.';
                    $flash_type = 'error';
                } else {
                    $user_id = (int)$teacher_record['user_id'];

                    mysqli_begin_transaction($conn);
                    try {
                        $user_stmt = mysqli_prepare($conn, "
                            UPDATE users
                            SET first_name = ?, middle_name = ?, last_name = ?, email = ?, gender = ?, phone = ?
                            WHERE user_id = ?
                        ");
                        if (!$user_stmt) {
                            throw new Exception(mysqli_error($conn));
                        }
                        mysqli_stmt_bind_param(
                            $user_stmt,
                            'ssssssi',
                            $first_name,
                            $middle_name,
                            $last_name,
                            $email,
                            $gender,
                            $phone,
                            $user_id
                        );
                        if (!mysqli_stmt_execute($user_stmt)) {
                            throw new Exception(mysqli_stmt_error($user_stmt));
                        }
                        mysqli_stmt_close($user_stmt);

                        $teacher_update = mysqli_prepare($conn, "
                            UPDATE teachers
                            SET employee_no = ?, qualification = ?, specialization = ?,
                                employment_status = ?, assignment_type = ?
                            WHERE teacher_id = ?
                        ");
                        if (!$teacher_update) {
                            throw new Exception(mysqli_error($conn));
                        }
                        mysqli_stmt_bind_param(
                            $teacher_update,
                            'sssssi',
                            $employee_no,
                            $qualification,
                            $specialization,
                            $employment_status,
                            $assignment_type,
                            $teacher_id
                        );
                        if (!mysqli_stmt_execute($teacher_update)) {
                            throw new Exception(mysqli_stmt_error($teacher_update));
                        }
                        mysqli_stmt_close($teacher_update);

                        mysqli_commit($conn);
                        $flash_message = 'Teacher details updated successfully.';
                        $flash_type = 'success';
                    } catch (Throwable $e) {
                        mysqli_rollback($conn);
                        $flash_message = 'Unable to update teacher: ' . $e->getMessage();
                        $flash_type = 'error';
                    }
                }
            } else {
                $flash_message = 'Unable to find the teacher record.';
                $flash_type = 'error';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function full_name(array $row): string
{
    $parts = array_filter([
        $row['first_name']  ?? '',
        $row['middle_name'] ?? '',
        $row['last_name']   ?? '',
    ], fn($v) => trim((string)$v) !== '');

    return trim(implode(' ', $parts));
}

/*
|--------------------------------------------------------------------------
| Search & Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

/*
|--------------------------------------------------------------------------
| Get Teachers
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        t.teacher_id,
        t.user_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.employment_status,
        t.assignment_type,
        t.created_at,

        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.gender,
        u.phone,
        u.profile_pic

    FROM teachers t

    INNER JOIN users u
        ON t.user_id = u.user_id

    WHERE u.role = 'teacher'
";

$params = [];
$types  = '';

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            u.first_name  LIKE ?
            OR u.middle_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR t.employee_no LIKE ?
            OR t.qualification LIKE ?
            OR t.specialization LIKE ?
        )
    ";

    $search_value = '%' . $search . '%';

    for ($i = 0; $i < 8; $i++) {
        $params[] = $search_value;
        $types   .= 's';
    }
}

/*
|--------------------------------------------------------------------------
| Employment Status
|--------------------------------------------------------------------------
*/

if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {

    $sql .= " AND t.employment_status = ? ";
    $params[] = $status;
    $types   .= 's';
}

/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

$sql .= " ORDER BY u.first_name ASC ";

/*
|--------------------------------------------------------------------------
| Execute Teacher Query
|--------------------------------------------------------------------------
*/

$teachers = [];

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {

    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $teachers[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_teachers    = 0;
$active_teachers   = 0;
$inactive_teachers = 0;

$result = mysqli_query($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(employment_status = 'active')   AS active_total,
        SUM(employment_status = 'inactive') AS inactive_total
    FROM teachers
");

if ($result) {
    $stats = mysqli_fetch_assoc($result);
    $total_teachers    = (int)($stats['total']          ?? 0);
    $active_teachers   = (int)($stats['active_total']   ?? 0);
    $inactive_teachers = (int)($stats['inactive_total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Assigned Class Count
|--------------------------------------------------------------------------
*/

$assigned_classes = 0;

$class_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'teacher_class'");

if ($class_table_check && mysqli_num_rows($class_table_check) > 0) {

    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM teacher_class");

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $assigned_classes = (int)($row['total'] ?? 0);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Teachers | PSRMS</title>

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
            --green: #3e7655;
            --red: #9b4747;
            --orange: #9a7422;
        }

        html, body { overflow-x: hidden; }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
            -webkit-text-size-adjust: 100%;
        }

        body.no-scroll { overflow: hidden; }

        /* =========================================================
           MOBILE TOPBAR
        ========================================================= */

        .mobile-topbar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 58px;
            background: var(--navy);
            color: var(--white);
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 1100;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }

        .mobile-topbar .brand {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px;
            height: 40px;
            border: none;
            background: rgba(255,255,255,.08);
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            padding: 0;
        }

        .hamburger span {
            display: block;
            width: 18px;
            height: 2px;
            background: var(--white);
            border-radius: 2px;
            transition: .2s ease;
        }

        .hamburger.active span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .hamburger.active span:nth-child(2) { opacity: 0; }
        .hamburger.active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050;
            opacity: 0;
            transition: opacity .25s ease;
        }

        .sidebar-overlay.open { display: block; opacity: 1; }

        /* =========================================================
           MAIN CONTENT
        ========================================================= */

        .main-content {
            margin-left: 255px;
            padding: 108px 30px 40px;
            transition: margin-left .25s ease;
        }

        /* =========================================================
           PAGE HEADER
        ========================================================= */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-heading p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .add-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 11px 17px;
            background: var(--navy);
            color: var(--white);
            border-radius: 7px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 650;
            transition: .2s ease;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .add-button:hover { background: var(--navy-dark); transform: translateY(-1px); }
        .add-button:active { transform: translateY(0); }

        .add-icon { color: var(--gold-light); font-size: 16px; }

        /* =========================================================
           STATS
        ========================================================= */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 19px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .stat-value {
            color: var(--navy);
            font-size: 26px;
            font-weight: 750;
            margin-top: 7px;
        }

        .stat-line {
            width: 23px;
            height: 2px;
            background: var(--gold);
            margin-top: 12px;
        }

        /* =========================================================
           FILTER
        ========================================================= */

        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.6fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .filter-control {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fcfcfd;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
            outline: none;
        }

        .filter-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,.08);
        }

        .filter-button {
            height: 42px;
            padding: 0 18px;
            border: none;
            border-radius: 6px;
            background: var(--navy);
            color: var(--white);
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            font-weight: 650;
        }

        .filter-button:hover { background: var(--navy-dark); }

        .reset-button {
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--white);
            color: var(--muted);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .reset-button:hover { color: var(--navy); border-color: #c8ccd3; }

        /* =========================================================
           TABLE
        ========================================================= */

        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow: hidden;
        }

        .table-header {
            padding: 17px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .table-header h2 { color: var(--navy); font-size: 14px; }
        .table-count { color: var(--muted); font-size: 10px; }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        thead { background: #fafaf8; }

        th {
            padding: 12px 15px;
            text-align: left;
            color: #737c8c;
            font-size: 9px;
            font-weight: 750;
            letter-spacing: .7px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f1f3;
            color: var(--text);
            font-size: 11px;
            vertical-align: middle;
        }

        tbody tr:hover { background: #fdfcf8; }
        tbody tr:last-child td { border-bottom: none; }

        /* TEACHER CELL */
        .teacher-cell { display: flex; align-items: center; gap: 10px; }

        .teacher-photo {
            width: 37px; height: 37px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e5e1d2;
        }

        .teacher-placeholder {
            width: 37px; height: 37px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .teacher-name { color: var(--navy); font-weight: 650; }
        .teacher-id { color: var(--muted); font-size: 9px; margin-top: 2px; }

        .assignment-badge {
            display: inline-block;
            margin-top: 3px;
            padding: 2px 6px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            border-radius: 10px;
            background: #eef1f7;
            color: var(--navy);
        }

        .email, .phone { color: var(--muted); }
        .employee-number { color: var(--navy); font-weight: 650; }
        .qualification { color: var(--text); }
        .specialization { color: var(--text); font-weight: 600; }

        /* STATUS */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 700;
        }

        .status::before {
            content: "";
            width: 5px; height: 5px;
            border-radius: 50%;
        }

        .status-active { color: var(--green); background: #eef6f0; }
        .status-active::before { background: var(--green); }

        .status-inactive { color: var(--orange); background: #faf5e8; }
        .status-inactive::before { background: var(--orange); }

        /* ACTIONS */
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .action-btn {
            min-width: 34px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            background: var(--white);
            color: var(--navy);
            text-decoration: none;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .action-btn:hover { border-color: var(--gold); color: var(--gold); }
        .action-btn:active { transform: scale(.96); background: #faf7ee; }

        .action-btn.primary {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }
        .action-btn.primary:hover {
            background: var(--navy-dark);
            color: var(--white);
            border-color: var(--navy-dark);
        }

        /* EMPTY */
        .empty-state { padding: 55px 20px; text-align: center; }

        .empty-icon {
            width: 50px; height: 50px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 750;
        }

        .empty-state h3 { color: var(--navy); font-size: 14px; margin-bottom: 5px; }
        .empty-state p { color: var(--muted); font-size: 11px; }

        /* =========================================================
           TEACHER MODALS
        ========================================================= */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(16, 24, 43, .62);
            backdrop-filter: blur(3px);
        }

        .modal-overlay.open { display: flex; }

        .teacher-modal {
            width: min(760px, 100%);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(16,24,43,.24);
            animation: modalIn .18s ease-out;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(10px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 { color: var(--navy); font-size: 17px; }
        .modal-header p { color: var(--muted); font-size: 10px; margin-top: 3px; }

        .modal-close {
            width: 34px;
            height: 34px;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: var(--white);
            color: var(--muted);
            cursor: pointer;
            font-size: 20px;
            line-height: 1;
        }
        .modal-close:hover { color: var(--navy); border-color: var(--gold); }

        .modal-body { padding: 20px; }

        .teacher-profile-head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 18px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }

        .modal-avatar {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e5e1d2;
            flex-shrink: 0;
        }

        .modal-avatar-placeholder {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 21px;
            flex-shrink: 0;
        }

        .modal-profile-name { color: var(--navy); font-size: 18px; font-weight: 750; }
        .modal-profile-meta { color: var(--muted); font-size: 10px; margin-top: 4px; }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .detail-item {
            padding: 12px;
            background: #fafaf8;
            border: 1px solid #eceef1;
            border-radius: 7px;
        }
        .detail-label {
            color: var(--muted);
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 750;
            margin-bottom: 5px;
        }
        .detail-value { color: var(--text); font-size: 11px; font-weight: 600; word-break: break-word; }

        .edit-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }
        .edit-field.full { grid-column: 1 / -1; }
        .edit-field label {
            display: block;
            color: var(--navy);
            font-size: 9px;
            font-weight: 750;
            margin-bottom: 6px;
        }
        .edit-field input,
        .edit-field select {
            width: 100%;
            height: 42px;
            padding: 0 11px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fcfcfd;
            color: var(--text);
            font: inherit;
            font-size: 11px;
            outline: none;
        }
        .edit-field input:focus,
        .edit-field select:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,.08);
        }
        .required { color: var(--red); }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 15px 20px;
            border-top: 1px solid var(--border);
        }
        .modal-button {
            min-height: 40px;
            padding: 0 16px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--muted);
            cursor: pointer;
            font: inherit;
            font-size: 11px;
            font-weight: 700;
        }
        .modal-button.primary {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }
        .modal-button.primary:hover { background: var(--navy-dark); }

        .flash-message {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 600;
        }
        .flash-success { color: var(--green); background: #eef6f0; border: 1px solid #d5e9da; }
        .flash-error { color: var(--red); background: #fbefef; border: 1px solid #efd7d7; }

        /* =========================================================
           BREAKPOINTS
        ========================================================= */

        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .mobile-topbar { display: flex; }

            .main-content {
                margin-left: 0;
                padding: 78px 14px 90px;
            }

            .page-header {
                align-items: stretch;
                flex-direction: column;
                gap: 12px;
            }

            .page-heading h1 { font-size: 21px; }
            .page-heading p { font-size: 11px; }

            .add-button {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card { padding: 14px; }
            .stat-value { font-size: 22px; }

            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-button,
            .reset-button {
                width: 100%;
                height: 46px;
                font-size: 13px;
            }

            /* -------- TABLE → CARDS -------- */
            .table-wrapper { overflow-x: visible; }

            table { min-width: 0; width: 100%; display: block; }
            thead { display: none; }
            tbody { display: block; }

            tbody tr {
                display: block;
                background: var(--white);
                border-bottom: 1px solid var(--border);
                padding: 14px 14px 8px;
                margin: 0;
            }
            tbody tr:last-child { border-bottom: none; }

            td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
                padding: 8px 0;
                border-bottom: 1px dashed #f0f1f3;
                font-size: 12px;
                text-align: right;
            }
            tbody tr td:last-child { border-bottom: none; }

            td::before {
                content: attr(data-label);
                font-size: 9px;
                font-weight: 750;
                letter-spacing: .6px;
                text-transform: uppercase;
                color: var(--muted);
                text-align: left;
                flex: 0 0 90px;
                padding-top: 4px;
            }

            /* Teacher row becomes the card header */
            td[data-label="Teacher"] {
                display: block;
                text-align: left;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--border);
            }
            td[data-label="Teacher"]::before { display: none; }

            /* Hide the "No." row on mobile — not useful */
            td[data-label="No."] {
                display: none;
            }

            .teacher-cell { justify-content: flex-start; }
            .teacher-photo,
            .teacher-placeholder {
                width: 44px;
                height: 44px;
                font-size: 15px;
            }

            .actions {
                justify-content: flex-end;
                width: 100%;
                gap: 6px;
                padding-top: 4px;
            }

            .action-btn {
                flex: 1;
                min-width: 0;
                height: 42px;
                font-size: 11px;
            }

            .table-header { padding: 14px 16px; }
            .table-header h2 { font-size: 13px; }
            .table-count { font-size: 9px; }
        }

        @media (max-width: 600px) {
            .modal-overlay { padding: 10px; }
            .teacher-modal { max-height: calc(100vh - 20px); }
            .modal-body { padding: 15px; }
            .modal-header { padding: 15px; }
            .modal-footer { padding: 12px 15px; }
            .details-grid,
            .edit-grid { grid-template-columns: 1fr; }
            .edit-field.full { grid-column: auto; }
            .modal-button { flex: 1; }
        }

        @media (max-width: 420px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-value { font-size: 20px; }
            td::before { flex: 0 0 72px; font-size: 8px; }
            td { font-size: 11px; }
        }

        /* COLLAPSED SIDEBAR (desktop) */
        body.sidebar-collapsed .main-content { margin-left: 78px; }
        @media (max-width: 800px) {
            body.sidebar-collapsed .main-content { margin-left: 0; }
        }
    </style>
</head>

<body>

<!-- =========================================================
     MOBILE TOPBAR
========================================================== -->
<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Admin</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>


<?php
$topbar_title    = 'Teachers';
$topbar_subtitle = '';
include '../includes/topbar.php';
?>
<?php include 'admin_sidebar.php'; ?>


<main class="main-content">

    <?php if ($flash_message !== ''): ?>
        <div class="flash-message flash-<?php echo e($flash_type); ?>" id="flashMessage">
            <?php echo e($flash_message); ?>
        </div>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-heading">
            <h1>Teachers</h1>
            <p>Manage teachers, teaching assignments and class responsibilities.</p>
        </div>

        <button
            type="button"
            class="add-button"
            data-action="add"
            data-url="add_teacher.php"
        >
            <span class="add-icon">+</span>
            Add Teacher
        </button>
    </div>

    <!-- STATISTICS -->
    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Teachers</div>
            <div class="stat-value"><?php echo number_format($total_teachers); ?></div>
            <div class="stat-line"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Active Teachers</div>
            <div class="stat-value"><?php echo number_format($active_teachers); ?></div>
            <div class="stat-line"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Inactive</div>
            <div class="stat-value"><?php echo number_format($inactive_teachers); ?></div>
            <div class="stat-line"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Class Assignments</div>
            <div class="stat-value"><?php echo number_format($assigned_classes); ?></div>
            <div class="stat-line"></div>
        </div>
    </section>

    <!-- FILTER -->
    <section class="filter-panel">
        <form method="GET" action="teachers.php" class="filter-form">

            <div class="filter-group">
                <label>Search Teacher</label>
                <input
                    type="text"
                    name="search"
                    class="filter-control"
                    placeholder="Name, email, phone, employee no. or specialization..."
                    value="<?php echo e($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label>Employment Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Employment Statuses</option>
                    <option value="active"   <?php echo $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="filter-button">Search</button>
            <a href="teachers.php" class="reset-button">Reset</a>
        </form>
    </section>

    <!-- TEACHERS TABLE -->
    <section class="table-panel">

        <div class="table-header">
            <h2>Teacher Records</h2>
            <span class="table-count">
                <?php echo number_format(count($teachers)); ?> teacher(s)
            </span>
        </div>

        <?php if (!empty($teachers)): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Teacher</th>
                            <th>Employee No.</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Qualification</th>
                            <th>Specialization</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php
                    $number = 1;

                    foreach ($teachers as $teacher):

                        $name = full_name($teacher);
                        if ($name === '') {
                            $name = 'Teacher #' . $teacher['teacher_id'];
                        }

                        $initial = strtoupper(substr($name, 0, 1));

                        $teacher_status = strtolower($teacher['employment_status'] ?? '');
                        if (!in_array($teacher_status, ['active', 'inactive'], true)) {
                            $teacher_status = 'inactive';
                        }

                        $assignment_type = $teacher['assignment_type'] ?? 'teacher';
                        $tid = (int)$teacher['teacher_id'];
                    ?>
                        <tr>

                            <!-- NUMBER -->
                            <td data-label="No."><?php echo $number++; ?></td>

                            <!-- TEACHER -->
                            <td data-label="Teacher">
                                <div class="teacher-cell">
                                    <?php if (!empty($teacher['profile_pic'])): ?>
                                        <img
                                            src="../uploads/<?php echo e($teacher['profile_pic']); ?>"
                                            alt="Teacher"
                                            class="teacher-photo"
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                        >
                                        <div class="teacher-placeholder" style="display:none;"><?php echo e($initial); ?></div>
                                    <?php else: ?>
                                        <div class="teacher-placeholder"><?php echo e($initial); ?></div>
                                    <?php endif; ?>

                                    <div>
                                        <div class="teacher-name"><?php echo e($name); ?></div>
                                        <?php if ($assignment_type !== 'teacher'): ?>
                                            <span class="assignment-badge"><?php echo e($assignment_type); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- EMPLOYEE NUMBER -->
                            <td data-label="Employee No.">
                                <span class="employee-number"><?php echo e($teacher['employee_no'] ?: '—'); ?></span>
                            </td>

                            <!-- EMAIL -->
                            <td data-label="Email">
                                <span class="email"><?php echo e($teacher['email'] ?: '—'); ?></span>
                            </td>

                            <!-- PHONE -->
                            <td data-label="Phone">
                                <span class="phone"><?php echo e($teacher['phone'] ?: '—'); ?></span>
                            </td>

                            <!-- QUALIFICATION -->
                            <td data-label="Qualification">
                                <span class="qualification"><?php echo e($teacher['qualification'] ?: 'Not specified'); ?></span>
                            </td>

                            <!-- SPECIALIZATION -->
                            <td data-label="Specialization">
                                <span class="specialization"><?php echo e($teacher['specialization'] ?: 'Not specified'); ?></span>
                            </td>

                            <!-- STATUS -->
                            <td data-label="Status">
                                <span class="status status-<?php echo e($teacher_status); ?>">
                                    <?php echo ucfirst(e($teacher_status)); ?>
                                </span>
                            </td>

                            <!-- ACTIONS -->
                            <td data-label="Actions">
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-btn"
                                        onclick="viewTeacher(<?php echo $tid; ?>)"
                                    >View</button>

                                    <button
                                        type="button"
                                        class="action-btn"
                                        onclick="editTeacher(<?php echo $tid; ?>)"
                                    >Edit</button>

                                    <button
                                        type="button"
                                        class="action-btn primary"
                                        data-action="assign"
                                        data-url="assign_teacher.php?id=<?php echo $tid; ?>"
                                    >Assign</button>
                                </div>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="empty-icon">T</div>
                <h3>No teachers found</h3>
                <p>There are no teacher records matching your search criteria.</p>
            </div>

        <?php endif; ?>

    </section>

    <!-- VIEW TEACHER MODAL -->
    <div class="modal-overlay" id="viewTeacherModal" aria-hidden="true">
        <div class="teacher-modal" role="dialog" aria-modal="true" aria-labelledby="viewTeacherTitle">
            <div class="modal-header">
                <div>
                    <h2 id="viewTeacherTitle">Teacher Details</h2>
                    <p>Teacher information from the school database</p>
                </div>
                <button type="button" class="modal-close" onclick="closeTeacherModal('viewTeacherModal')" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" id="viewTeacherBody"></div>
        </div>
    </div>

    <!-- EDIT TEACHER MODAL -->
    <div class="modal-overlay" id="editTeacherModal" aria-hidden="true">
        <div class="teacher-modal" role="dialog" aria-modal="true" aria-labelledby="editTeacherTitle">
            <form method="POST" action="teachers.php">
                <input type="hidden" name="form_action" value="update_teacher">
                <input type="hidden" name="teacher_id" id="edit_teacher_id">

                <div class="modal-header">
                    <div>
                        <h2 id="editTeacherTitle">Edit Teacher</h2>
                        <p>Update teacher account and employment information</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeTeacherModal('editTeacherModal')" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="edit-grid">
                        <div class="edit-field">
                            <label>First Name <span class="required">*</span></label>
                            <input type="text" name="first_name" id="edit_first_name" required>
                        </div>
                        <div class="edit-field">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" id="edit_middle_name">
                        </div>
                        <div class="edit-field">
                            <label>Last Name <span class="required">*</span></label>
                            <input type="text" name="last_name" id="edit_last_name" required>
                        </div>
                        <div class="edit-field">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" name="email" id="edit_email" required>
                        </div>
                        <div class="edit-field">
                            <label>Gender</label>
                            <select name="gender" id="edit_gender">
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                        <div class="edit-field">
                            <label>Phone</label>
                            <input type="text" name="phone" id="edit_phone">
                        </div>
                        <div class="edit-field">
                            <label>Employee No.</label>
                            <input type="text" name="employee_no" id="edit_employee_no">
                        </div>
                        <div class="edit-field">
                            <label>Qualification</label>
                            <input type="text" name="qualification" id="edit_qualification">
                        </div>
                        <div class="edit-field">
                            <label>Specialization</label>
                            <input type="text" name="specialization" id="edit_specialization">
                        </div>
                        <div class="edit-field">
                            <label>Employment Status</label>
                            <select name="employment_status" id="edit_employment_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="edit-field full">
                            <label>Assignment Type</label>
                            <select name="assignment_type" id="edit_assignment_type">
                                <option value="teacher">Teacher</option>
                                <option value="class_teacher">Class Teacher</option>
                                <option value="head_teacher">Head Teacher</option>
                                <option value="academic">Academic</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="modal-button" onclick="closeTeacherModal('editTeacherModal')">Cancel</button>
                    <button type="submit" class="modal-button primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

</main>

<script>
/* =========================================================
   SIDEBAR (desktop toggle — kept for compatibility)
   ========================================================= */
function toggleSidebar() {
    document.body.classList.toggle('sidebar-collapsed');
}


/* =========================================================
   MOBILE DRAWER SIDEBAR
   ========================================================= */
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');

    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar');
    if (sidebar) sidebar.classList.add('open');

    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}

function closeSidebar() {
    sidebarOverlay.classList.remove('open');

    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar');
    if (sidebar) sidebar.classList.remove('open');

    if (hamburgerBtn) hamburgerBtn.classList.remove('active');

    document.body.classList.remove('no-scroll');
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', function () {
        if (sidebarOverlay.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

/* Escape closes sidebar */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
});

/* Auto-close on resize to desktop */
window.addEventListener('resize', function () {
    if (window.innerWidth > 800) closeSidebar();
});


/* =========================================================
   TEACHER DATA FOR VIEW / EDIT MODALS
   ========================================================= */
const teacherData = <?php
    $teacher_json = [];
    foreach ($teachers as $teacher_row) {
        $teacher_json[(string)$teacher_row['teacher_id']] = [
            'teacher_id' => (int)$teacher_row['teacher_id'],
            'user_id' => (int)$teacher_row['user_id'],
            'first_name' => (string)($teacher_row['first_name'] ?? ''),
            'middle_name' => (string)($teacher_row['middle_name'] ?? ''),
            'last_name' => (string)($teacher_row['last_name'] ?? ''),
            'email' => (string)($teacher_row['email'] ?? ''),
            'gender' => (string)($teacher_row['gender'] ?? ''),
            'phone' => (string)($teacher_row['phone'] ?? ''),
            'profile_pic' => (string)($teacher_row['profile_pic'] ?? ''),
            'employee_no' => (string)($teacher_row['employee_no'] ?? ''),
            'qualification' => (string)($teacher_row['qualification'] ?? ''),
            'specialization' => (string)($teacher_row['specialization'] ?? ''),
            'employment_status' => (string)($teacher_row['employment_status'] ?? ''),
            'assignment_type' => (string)($teacher_row['assignment_type'] ?? 'teacher')
        ];
    }
    echo json_encode($teacher_json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

function escHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function teacherFullName(t) {
    return [t.first_name, t.middle_name, t.last_name]
        .filter(v => String(v || '').trim() !== '')
        .join(' ')
        .trim() || ('Teacher #' + t.teacher_id);
}

function displayValue(value, fallback = 'Not specified') {
    return String(value || '').trim() ? escHtml(value) : fallback;
}

function viewTeacher(id) {
    const t = teacherData[String(id)];
    if (!t) return;

    const name = teacherFullName(t);
    const initial = name.charAt(0).toUpperCase();
    const image = t.profile_pic
        ? `<img class="modal-avatar" src="../uploads/${escHtml(t.profile_pic)}" alt="Teacher" onerror="this.outerHTML='<div class=&quot;modal-avatar-placeholder&quot;>${initial}</div>'">`
        : `<div class="modal-avatar-placeholder">${escHtml(initial)}</div>`;

    document.getElementById('viewTeacherBody').innerHTML = `
        <div class="teacher-profile-head">
            ${image}
            <div>
                <div class="modal-profile-name">${escHtml(name)}</div>
                <div class="modal-profile-meta">Teacher ID: ${escHtml(t.teacher_id)} · Employee No: ${displayValue(t.employee_no, 'Not assigned')}</div>
            </div>
        </div>
        <div class="details-grid">
            <div class="detail-item"><div class="detail-label">Email</div><div class="detail-value">${displayValue(t.email)}</div></div>
            <div class="detail-item"><div class="detail-label">Phone</div><div class="detail-value">${displayValue(t.phone)}</div></div>
            <div class="detail-item"><div class="detail-label">Gender</div><div class="detail-value">${displayValue(t.gender)}</div></div>
            <div class="detail-item"><div class="detail-label">Qualification</div><div class="detail-value">${displayValue(t.qualification)}</div></div>
            <div class="detail-item"><div class="detail-label">Specialization</div><div class="detail-value">${displayValue(t.specialization)}</div></div>
            <div class="detail-item"><div class="detail-label">Employment Status</div><div class="detail-value">${displayValue(t.employment_status)}</div></div>
            <div class="detail-item"><div class="detail-label">Assignment Type</div><div class="detail-value">${displayValue(t.assignment_type)}</div></div>
            <div class="detail-item"><div class="detail-label">User ID</div><div class="detail-value">${displayValue(t.user_id)}</div></div>
        </div>
    `;

    openTeacherModal('viewTeacherModal');
}

function editTeacher(id) {
    const t = teacherData[String(id)];
    if (!t) return;

    document.getElementById('edit_teacher_id').value = t.teacher_id;
    document.getElementById('edit_first_name').value = t.first_name || '';
    document.getElementById('edit_middle_name').value = t.middle_name || '';
    document.getElementById('edit_last_name').value = t.last_name || '';
    document.getElementById('edit_email').value = t.email || '';
    document.getElementById('edit_gender').value = t.gender || '';
    document.getElementById('edit_phone').value = t.phone || '';
    document.getElementById('edit_employee_no').value = t.employee_no || '';
    document.getElementById('edit_qualification').value = t.qualification || '';
    document.getElementById('edit_specialization').value = t.specialization || '';
    document.getElementById('edit_employment_status').value = t.employment_status || 'inactive';
    document.getElementById('edit_assignment_type').value = t.assignment_type || 'teacher';

    openTeacherModal('editTeacherModal');
}

function openTeacherModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('no-scroll');
}

function closeTeacherModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.modal-overlay.open') && !sidebarOverlay?.classList.contains('open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-overlay').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeTeacherModal(modal.id);
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(function (modal) {
            closeTeacherModal(modal.id);
        });
    }
});

/* =========================================================
   ACTION BUTTON DELEGATION — ASSIGN ONLY
   ========================================================= */
document.addEventListener('click', function (event) {
    const btn = event.target.closest('[data-action="assign"]');
    if (!btn) return;

    if (btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    setTimeout(() => { btn.dataset.busy = '0'; }, 400);

    const url = btn.dataset.url;
    if (url) window.location.href = url;
});


/* Auto-hide success message */
const flashMessage = document.getElementById('flashMessage');
if (flashMessage) {
    setTimeout(function () {
        flashMessage.style.transition = 'opacity .3s ease';
        flashMessage.style.opacity = '0';
        setTimeout(() => flashMessage.remove(), 350);
    }, 4000);
}

/* =========================================================
   KEYBOARD SHORTCUT — "/" focuses the search
   ========================================================= */
document.addEventListener('keydown', function (e) {
    if (
        e.key === '/' &&
        !/input|textarea|select/i.test(document.activeElement.tagName)
    ) {
        const search = document.querySelector('input[name="search"]');
        if (search) {
            e.preventDefault();
            search.focus();
        }
    }
});
</script>

</body>
</html>