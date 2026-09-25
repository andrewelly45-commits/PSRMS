<?php

session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';


/* =========================================================================
   HELPERS
   ========================================================================= */

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['subjects_flash'] = ['type' => $type, 'message' => $message];
    header('Location: subjects.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


/* Valid enum values — MATCH your DB */
$valid_types = [
    'academic',
    'competency',
    'science',
    'business',
    'arts',
    'language',
    'technical',
    'religious',
    'vocational',
    'other',
];


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* -------------------------------------------------------------
       AJAX: ASSIGN SUBJECTS TO CLASSES
    ------------------------------------------------------------- */
    if (($_POST['ajax_action'] ?? '') === 'assign_subjects') {
        header('Content-Type: application/json; charset=utf-8');

        $class_ids   = $_POST['class_ids'] ?? [];
        $subject_ids = $_POST['subject_ids'] ?? [];

        if (!is_array($class_ids)) $class_ids = [$class_ids];
        if (!is_array($subject_ids)) $subject_ids = [$subject_ids];

        $class_ids = array_values(array_unique(array_filter(array_map('intval', $class_ids), fn($id) => $id > 0)));
        $subject_ids = array_values(array_unique(array_filter(array_map('intval', $subject_ids), fn($id) => $id > 0)));

        if (!$class_ids) {
            echo json_encode(['success' => false, 'message' => 'Please select at least one class.']);
            exit;
        }
        if (!$subject_ids) {
            echo json_encode(['success' => false, 'message' => 'Please select at least one subject.']);
            exit;
        }

        /* Create the relationship table automatically if it does not exist. */
        $create_table = "
            CREATE TABLE IF NOT EXISTS class_subjects (
                class_subject_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                class_id INT NOT NULL,
                subject_id INT NOT NULL,
                assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (class_subject_id),
                UNIQUE KEY uq_class_subject (class_id, subject_id),
                KEY idx_class_id (class_id),
                KEY idx_subject_id (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!mysqli_query($conn, $create_table)) {
            echo json_encode(['success' => false, 'message' => 'Could not prepare the assignment table: ' . mysqli_error($conn)]);
            exit;
        }

        /* Validate selected classes. */
        $valid_classes = [];
        $stmt = mysqli_prepare($conn, 'SELECT class_id FROM classes WHERE class_id = ? LIMIT 1');
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Could not validate the selected classes.']);
            exit;
        }
        foreach ($class_ids as $id) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) $valid_classes[] = $id;
            mysqli_stmt_free_result($stmt);
        }
        mysqli_stmt_close($stmt);

        if (!$valid_classes) {
            echo json_encode(['success' => false, 'message' => 'None of the selected classes could be found.']);
            exit;
        }

        /* Validate selected subjects. */
        $valid_subjects = [];
        $stmt = mysqli_prepare($conn, 'SELECT subject_id FROM subjects WHERE subject_id = ? LIMIT 1');
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Could not validate the selected subjects.']);
            exit;
        }
        foreach ($subject_ids as $id) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) $valid_subjects[] = $id;
            mysqli_stmt_free_result($stmt);
        }
        mysqli_stmt_close($stmt);

        if (!$valid_subjects) {
            echo json_encode(['success' => false, 'message' => 'None of the selected subjects could be found.']);
            exit;
        }

        $insert = mysqli_prepare($conn, 'INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (?, ?)');
        if (!$insert) {
            echo json_encode(['success' => false, 'message' => 'Could not prepare the assignment query: ' . mysqli_error($conn)]);
            exit;
        }

        $assigned = 0;
        $already_exists = 0;
        mysqli_begin_transaction($conn);

        try {
            foreach ($valid_classes as $class_id) {
                foreach ($valid_subjects as $subject_id) {
                    mysqli_stmt_bind_param($insert, 'ii', $class_id, $subject_id);
                    if (!mysqli_stmt_execute($insert)) {
                        throw new Exception(mysqli_stmt_error($insert));
                    }
                    if (mysqli_stmt_affected_rows($insert) > 0) $assigned++;
                    else $already_exists++;
                }
            }

            mysqli_commit($conn);
            mysqli_stmt_close($insert);

            $message = $assigned . ' subject assignment' . ($assigned === 1 ? '' : 's') . ' saved successfully.';
            if ($already_exists > 0) {
                $message .= ' ' . $already_exists . ' assignment' . ($already_exists === 1 ? '' : 's') . ' already existed.';
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'assigned' => $assigned,
                'already_exists' => $already_exists
            ]);
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            mysqli_stmt_close($insert);
            echo json_encode(['success' => false, 'message' => 'Assignment failed: ' . $e->getMessage()]);
            exit;
        }
    }

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_type = $_POST['subject_type'] ?? 'academic';
        $status       = $_POST['status'] ?? 'active';

        $errors = [];

        if ($subject_name === '') {
            $errors[] = 'Subject name is required.';
        } elseif (strlen($subject_name) > 150) {
            $errors[] = 'Subject name cannot exceed 150 characters.';
        }

        if (!in_array($subject_type, $valid_types, true)) {
            $subject_type = 'academic';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT subject_id FROM subjects WHERE subject_name = ? LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $subject_name);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors[] = 'A subject with this name already exists.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            $_SESSION['subjects_flash'] = [
                'type' => 'error',
                'message' => implode(' ', $errors),
            ];
            header('Location: subjects.php');
            exit;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO subjects (subject_name, subject_type, status)
             VALUES (?, ?, ?)"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sss',
                $subject_name,
                $subject_type,
                $status
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject created successfully.');
            }

            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Could not create subject: ' . $err);
        }

        redirect_with_flash('error', 'Could not prepare query.');
    }


    /* -------------------------------------------------------------
       UPDATE
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $subject_id   = (int) ($_POST['subject_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_type = $_POST['subject_type'] ?? 'academic';
        $status       = $_POST['status'] ?? 'active';

        $errors = [];

        if ($subject_id <= 0) {
            $errors[] = 'Invalid subject.';
        }

        if ($subject_name === '') {
            $errors[] = 'Subject name is required.';
        } elseif (strlen($subject_name) > 150) {
            $errors[] = 'Subject name cannot exceed 150 characters.';
        }

        if (!in_array($subject_type, $valid_types, true)) {
            $subject_type = 'academic';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT subject_id FROM subjects
                 WHERE subject_name = ? AND subject_id <> ?
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $subject_name, $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors[] = 'Another subject already uses this name.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            $_SESSION['subjects_flash'] = [
                'type' => 'error',
                'message' => implode(' ', $errors),
            ];
            header('Location: subjects.php');
            exit;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE subjects
             SET subject_name = ?,
                 subject_type = ?,
                 status = ?
             WHERE subject_id = ?"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sssi',
                $subject_name,
                $subject_type,
                $status,
                $subject_id
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject updated successfully.');
            }

            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            redirect_with_flash('error', 'Could not update subject: ' . $err);
        }

        redirect_with_flash('error', 'Could not prepare query.');
    }


    /* -------------------------------------------------------------
       TOGGLE STATUS
    ------------------------------------------------------------- */
    if ($action === 'toggle') {

        $subject_id = (int) ($_POST['subject_id'] ?? 0);

        if ($subject_id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE subjects
                 SET status = IF(status = 'active', 'inactive', 'active')
                 WHERE subject_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject status updated.');
            }
        }

        redirect_with_flash('error', 'Could not update status.');
    }


    /* -------------------------------------------------------------
       DELETE
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $subject_id = (int) ($_POST['subject_id'] ?? 0);

        if ($subject_id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM subjects WHERE subject_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $subject_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                redirect_with_flash('success', 'Subject deleted.');
            }
        }

        redirect_with_flash('error', 'Could not delete subject.');
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */

$flash = $_SESSION['subjects_flash'] ?? null;
unset($_SESSION['subjects_flash']);


/* =========================================================================
   FETCH CLASSES (REAL DATA FROM DATABASE)
   ========================================================================= */

$classes = [];

$class_res = mysqli_query(
    $conn,
    "SELECT class_id, class_name FROM classes ORDER BY class_name ASC"
);

if ($class_res) {
    while ($row = mysqli_fetch_assoc($class_res)) {
        $classes[] = [
            'id'   => (int) $row['class_id'],
            'name' => $row['class_name'],
        ];
    }
    mysqli_free_result($class_res);
}


/* =========================================================================
   FETCH ASSIGNED SUBJECT IDS PER CLASS (for the modal filter)
   ========================================================================= */

$assignments_map = [];

$map_res = mysqli_query($conn, "SELECT class_id, subject_id FROM class_subjects");
if ($map_res) {
    while ($row = mysqli_fetch_assoc($map_res)) {
        $cid = (int) $row['class_id'];
        $sid = (int) $row['subject_id'];
        if (!isset($assignments_map[$cid])) $assignments_map[$cid] = [];
        $assignments_map[$cid][] = $sid;
    }
    mysqli_free_result($map_res);
}


/* =========================================================================
   FETCH CLASS ASSIGNMENTS (for the assignment card)
   ========================================================================= */

$class_assignments = [];

$assign_res = mysqli_query(
    $conn,
    "SELECT
        c.class_id,
        c.class_name,
        s.subject_id,
        s.subject_name,
        s.subject_type
     FROM classes c
     LEFT JOIN class_subjects cs ON cs.class_id = c.class_id
     LEFT JOIN subjects s        ON s.subject_id = cs.subject_id
     ORDER BY c.class_name ASC, s.subject_name ASC"
);

if ($assign_res) {
    while ($row = mysqli_fetch_assoc($assign_res)) {
        $cid = (int) $row['class_id'];

        if (!isset($class_assignments[$cid])) {
            $class_assignments[$cid] = [
                'class_id'   => $cid,
                'class_name' => $row['class_name'],
                'subjects'   => [],
            ];
        }

        if (!empty($row['subject_id'])) {
            $class_assignments[$cid]['subjects'][] = [
                'subject_id'   => (int) $row['subject_id'],
                'subject_name' => $row['subject_name'],
                'subject_type' => $row['subject_type'],
            ];
        }
    }
    mysqli_free_result($assign_res);
}

$class_assignments = array_values($class_assignments);


/* =========================================================================
   FILTERS
   ========================================================================= */

$search        = trim($_GET['q'] ?? '');
$type_filter   = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "subject_name LIKE ?";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $types   .= 's';
}

if (in_array($type_filter, $valid_types, true)) {
    $where[]  = "subject_type = ?";
    $params[] = $type_filter;
    $types   .= 's';
}

if (in_array($status_filter, ['active', 'inactive'], true)) {
    $where[]  = "status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

$sql = "SELECT subject_id, subject_name, subject_type, status, created_at
        FROM subjects";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY subject_name ASC";

$subjects = [];

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $subjects[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   COUNTS
   ========================================================================= */

$total_subjects  = 0;
$active_count    = 0;
$inactive_count  = 0;

$count_res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')   AS active_total,
        SUM(status = 'inactive') AS inactive_total
     FROM subjects"
);

if ($count_res) {
    $c = mysqli_fetch_assoc($count_res);
    $total_subjects = (int)($c['total'] ?? 0);
    $active_count   = (int)($c['active_total'] ?? 0);
    $inactive_count = (int)($c['inactive_total'] ?? 0);
}

$has_filters = ($search !== '' || $type_filter !== '' || $status_filter !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Subjects | PSRMS Admin</title>

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
            --pink: #8f3a6a;
            --pink-bg: #fbeef5;
            --sky: #2a6b8f;
            --sky-bg: #eaf6fb;
            --violet: #6a4a9e;
            --violet-bg: #f4efff;
            --leaf: #467a3c;
            --leaf-bg: #f0f7ee;
            --grey: #7a7a72;
            --grey-bg: #f0efec;
            --sidebar-w: 250px;
            --topbar-h: 78px;
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

        .mobile-topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 58px;
            background: var(--navy);
            color: var(--white);
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 1100;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }

        .mobile-topbar .brand { font-size: 14px; font-weight: 800; letter-spacing: .5px; }
        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px; height: 40px;
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
            width: 18px; height: 2px;
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

        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 0 16px;
            min-height: 42px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
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

        .btn-gold {
            background: var(--gold);
            color: var(--navy-dark);
            font-weight: 800;
        }
        .btn-gold:hover { background: var(--gold-light); }

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

        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.6fr 1fr 1fr auto auto;
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
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .table-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
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

        .subject-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
        }

        .subject-id {
            color: var(--muted);
            font-size: 10px;
            margin-top: 2px;
        }

        .badge {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 750;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }

        .badge-academic   { background: var(--blue-bg);   color: var(--blue); }
        .badge-competency { background: var(--green-bg);  color: var(--green); }
        .badge-science    { background: var(--purple-bg); color: var(--purple); }
        .badge-business   { background: var(--orange-bg); color: var(--orange); }
        .badge-arts       { background: var(--pink-bg);   color: var(--pink); }
        .badge-language   { background: var(--sky-bg);    color: var(--sky); }
        .badge-technical  { background: var(--blue-bg);   color: var(--blue); }
        .badge-religious  { background: var(--violet-bg); color: var(--violet); }
        .badge-vocational { background: var(--leaf-bg);   color: var(--leaf); }
        .badge-other      { background: var(--grey-bg);   color: var(--grey); }

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

        .icon-btn.danger { color: var(--red); }
        .icon-btn.danger:hover { border-color: var(--red); background: var(--red-bg); }

        .icon-btn.assign { color: var(--gold); border-color: var(--gold); font-weight: 800; }
        .icon-btn.assign:hover { background: #fef9e7; }

        .empty {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .card-list { display: none; }

        .subject-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .subject-card:last-child { border-bottom: none; }

        .subject-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .subject-card-meta {
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
        }

        .subject-card-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .subject-card-actions .icon-btn,
        .subject-card-actions form,
        .subject-card-actions form button {
            width: 100%;
            min-height: 40px;
            font-size: 11.5px;
        }

        /* ===== CLASS ASSIGNMENTS CARD ===== */
        .assignments-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-top: 22px;
        }

        .assignments-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .assignments-card-header h2 {
            color: var(--navy);
            font-size: 15px;
            font-weight: 750;
        }

        .assignments-count-badge {
            background: var(--blue-bg);
            color: var(--blue);
            font-size: 11px;
            font-weight: 800;
            padding: 5px 12px;
            border-radius: 20px;
        }

        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
        }

        .class-assign-card {
            background: #fafbfd;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .class-assign-card:hover {
            border-color: var(--gold);
            box-shadow: 0 3px 12px rgba(201,162,39,.10);
        }

        .class-assign-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .class-assign-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 750;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .class-assign-count {
            background: var(--gold);
            color: var(--navy-dark);
            font-size: 10px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .class-assign-subjects {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .class-assign-subjects .badge {
            font-size: 9.5px;
            padding: 4px 9px;
            text-transform: none;
            letter-spacing: 0;
            font-weight: 700;
        }

        .class-assign-empty {
            color: var(--muted);
            font-size: 11.5px;
            font-style: italic;
            padding: 6px 0;
        }

        /* Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(16,24,43,.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-backdrop.open { display: flex; }

        .modal {
            background: var(--white);
            border-radius: 10px;
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            animation: pop .2s ease-out;
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
        }

        @keyframes pop {
            from { transform: scale(.96); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .modal-header h2 {
            color: var(--navy);
            font-size: 14px;
        }

        .modal-close {
            border: none;
            background: transparent;
            font-size: 22px;
            color: var(--muted);
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }

        .modal-body {
            padding: 22px;
            overflow-y: auto;
            flex: 1;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 16px;
        }

        .form-grid .full { grid-column: 1 / -1; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .required { color: var(--red); }

        .form-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13px;
            color: var(--text);
            outline: none;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .modal-footer {
            padding: 15px 22px;
            background: #fafaf8;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        /* ===== ASSIGN MODAL SPECIAL ===== */
        .assign-class-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .assign-class-selector label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            cursor: pointer;
            background: #f4f6fa;
            padding: 8px 14px;
            border-radius: 30px;
            border: 1px solid transparent;
            transition: all .15s;
        }

        .assign-class-selector label:hover {
            background: #e9edf4;
        }

        .assign-class-selector input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--gold);
            cursor: pointer;
        }

        .assign-class-selector label.checked {
            background: #fef9e7;
            border-color: var(--gold);
        }

        .subject-checkbox-list {
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
            margin-bottom: 6px;
        }

        .subject-checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #f0f1f3;
            cursor: pointer;
            font-size: 13px;
            transition: background .1s;
        }

        .subject-checkbox-item:last-child { border-bottom: none; }
        .subject-checkbox-item:hover { background: #f6f8fc; }

        .subject-checkbox-item input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: var(--navy);
            cursor: pointer;
            flex-shrink: 0;
        }

        .subject-checkbox-item .sub-info {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .subject-checkbox-item .sub-name {
            font-weight: 600;
            color: var(--navy);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .subject-checkbox-item .sub-type {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--muted);
            background: #f0f2f5;
            padding: 3px 8px;
            border-radius: 12px;
            flex-shrink: 0;
        }

        .select-all-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 14px;
            background: #f8f9fc;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .select-all-bar label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: var(--navy);
            cursor: pointer;
        }

        .select-all-bar input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--gold);
            cursor: pointer;
        }

        .select-all-bar input[type="checkbox"]:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .selected-count-badge {
            background: var(--gold);
            color: var(--navy-dark);
            font-size: 11px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .assign-filter-toggle {
            background: #fff8e1;
            border: 1px solid #f0d98c;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 12px;
            font-size: 12px;
        }

        .assign-filter-toggle label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--navy);
            font-weight: 700;
            cursor: pointer;
        }

        .assign-filter-toggle input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--gold);
            cursor: pointer;
        }

        .assign-hidden {
            display: none !important;
        }

        .assign-status-message {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.45;
        }

        .assign-status-message.success {
            background: #ecfdf3;
            color: #166534;
            border: 1px solid #86efac;
        }

        .assign-status-message.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .assign-empty {
            text-align: center;
            padding: 30px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {
            .mobile-topbar { display: flex; }
            .main-content { margin-left: 0; padding: 78px 16px 30px; }
            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-header .btn { width: 100%; min-height: 46px; font-size: 13px; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }
            .filter-control, .btn { height: 46px; font-size: 13px; }
            .table-wrapper { display: none; }
            .card-list { display: block; }
        }

        @media (max-width: 550px) {
            .main-content { padding: 74px 14px 24px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card .value { font-size: 18px; }
            .stat-card .label { font-size: 9px; }
            .subject-card-meta { grid-template-columns: 1fr; gap: 8px; }
            .subject-card-actions { grid-template-columns: 1fr 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .modal-backdrop { padding: 12px; align-items: flex-start; }
            .modal { margin-top: 20px; border-radius: 12px; max-width: 100%; }
            .modal-body { padding: 18px; }
            .modal-footer { flex-direction: column-reverse; padding: 14px 18px; }
            .modal-footer .btn { width: 100%; }
            .assign-class-selector { flex-direction: column; gap: 8px; }
            .assign-class-selector label { width: 100%; }
            .assignments-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 380px) {
            .stats-grid { grid-template-columns: 1fr; }
            .subject-card-actions { grid-template-columns: 1fr; }
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

<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Admin</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'admin_sidebar.php'; ?>
<?php
$topbar_title    = 'Subjects';
$topbar_subtitle = '';
include '../includes/topbar.php';
?>

<main class="main-content">

    <div class="page-header">
        <div class="page-title">
            <h1>Subjects</h1>
            <p>Manage all subjects taught in the school.</p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-gold" onclick="openAssignModal()">
                📚 Assign to Classes
            </button>
            <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                + Add Subject
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Subjects</div>
            <div class="value"><?php echo number_format($total_subjects); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo number_format($active_count); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Inactive</div>
            <div class="value"><?php echo number_format($inactive_count); ?></div>
        </div>
    </div>

    <form method="GET" class="filter-panel">
        <div class="filter-form">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="q" class="filter-control" placeholder="Subject name…" value="<?php echo e($search); ?>">
            </div>
            <div class="filter-group">
                <label>Type</label>
                <select name="type" class="filter-control">
                    <option value="">All Types</option>
                    <?php foreach ($valid_types as $t): ?>
                        <option value="<?php echo e($t); ?>" <?php echo $type_filter === $t ? 'selected' : ''; ?>>
                            <?php echo ucfirst(e($t)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Statuses</option>
                    <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-ghost">Filter</button>
            <?php if ($has_filters): ?>
                <a href="subjects.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-card">
        <?php if (empty($subjects)): ?>
            <div class="empty">
                <h3>No subjects found</h3>
                <p><?php echo $has_filters ? 'Try clearing the filters.' : 'Add your first subject to get started.'; ?></p>
            </div>
        <?php else: ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $n = 1; foreach ($subjects as $s): ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td>
                                <div class="subject-name"><?php echo e($s['subject_name']); ?></div>
                                <div class="subject-id">ID #<?php echo (int)$s['subject_id']; ?></div>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo e($s['subject_type']); ?>">
                                    <?php echo e($s['subject_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status status-<?php echo e($s['status']); ?>">
                                    <?php echo e(ucfirst($s['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo e(date('M j, Y', strtotime($s['created_at']))); ?></td>
                            <td>
                                <div class="actions">
                                    <button type="button" class="icon-btn assign"
                                        onclick='openAssignForSubject(<?php echo (int)$s["subject_id"]; ?>, "<?php echo e(addslashes($s["subject_name"])); ?>")'>
                                        Assign
                                    </button>
                                    <button type="button" class="icon-btn"
                                        onclick='openEditModal(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        Edit
                                    </button>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Toggle status for this subject?');">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                        <button type="submit" class="icon-btn">
                                            <?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete this subject permanently?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                        <button type="submit" class="icon-btn danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-list">
                <?php foreach ($subjects as $s): ?>
                    <div class="subject-card">
                        <div class="subject-card-top">
                            <div style="min-width:0;flex:1;">
                                <div class="subject-name"><?php echo e($s['subject_name']); ?></div>
                                <div class="subject-id">ID #<?php echo (int)$s['subject_id']; ?></div>
                            </div>
                            <span class="status status-<?php echo e($s['status']); ?>">
                                <?php echo e(ucfirst($s['status'])); ?>
                            </span>
                        </div>
                        <div class="subject-card-meta">
                            <div class="meta-item">
                                <span class="k">Type</span>
                                <span class="v">
                                    <span class="badge badge-<?php echo e($s['subject_type']); ?>">
                                        <?php echo e($s['subject_type']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Created</span>
                                <span class="v"><?php echo e(date('M j, Y', strtotime($s['created_at']))); ?></span>
                            </div>
                        </div>
                        <div class="subject-card-actions">
                            <button type="button" class="icon-btn assign"
                                onclick='openAssignForSubject(<?php echo (int)$s["subject_id"]; ?>, "<?php echo e(addslashes($s["subject_name"])); ?>")'>
                                Assign
                            </button>
                            <button type="button" class="icon-btn"
                                onclick='openEditModal(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Edit
                            </button>
                            <form method="POST" onsubmit="return confirm('Toggle status for this subject?');">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                <button type="submit" class="icon-btn">
                                    <?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this subject permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                <button type="submit" class="icon-btn danger">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- =========================================================
         CLASS ASSIGNMENTS CARD
    ========================================================= -->
    <div class="assignments-card">
        <div class="assignments-card-header">
            <h2>📋 Subjects Assigned to Each Class</h2>
            <span class="assignments-count-badge">
                <?php echo count($class_assignments); ?> class<?php echo count($class_assignments) === 1 ? '' : 'es'; ?>
            </span>
        </div>

        <?php if (empty($class_assignments)): ?>
            <div class="empty" style="padding:40px 20px;">
                <h3>No classes found</h3>
                <p>Add classes first, then assign subjects to them.</p>
            </div>
        <?php else: ?>
            <div class="assignments-grid">
                <?php foreach ($class_assignments as $ca): ?>
                    <div class="class-assign-card">
                        <div class="class-assign-header">
                            <div class="class-assign-name">
                                <?php echo e($ca['class_name']); ?>
                            </div>
                            <div class="class-assign-count">
                                <?php echo count($ca['subjects']); ?>
                                subject<?php echo count($ca['subjects']) === 1 ? '' : 's'; ?>
                            </div>
                        </div>

                        <?php if (empty($ca['subjects'])): ?>
                            <div class="class-assign-empty">
                                No subjects assigned yet.
                            </div>
                        <?php else: ?>
                            <div class="class-assign-subjects">
                                <?php foreach ($ca['subjects'] as $sub): ?>
                                    <span class="badge badge-<?php echo e($sub['subject_type']); ?>">
                                        <?php echo e($sub['subject_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- =========================================================
     ASSIGN TO CLASSES MODAL
========================================================= -->
<div class="modal-backdrop" id="assignModal">
    <div class="modal" style="max-width:620px;">
        <form method="POST" action="subjects.php" id="assignForm">
            <div class="modal-header">
                <h2>📚 Assign Subjects to Classes</h2>
                <button type="button" class="modal-close" onclick="closeModal('assignModal')">×</button>
            </div>

            <div class="modal-body">
                <!-- Class selector -->
                <div style="margin-bottom:6px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;">
                    Select Classes
                </div>
                <div class="assign-class-selector" id="classSelector">
                    <!-- Populated by JS from PHP data -->
                </div>

                <!-- Only-new filter -->
                <div class="assign-filter-toggle">
                    <label>
                        <input type="checkbox" id="onlyNewSubjects">
                        Only show subjects not yet assigned to the selected classes
                    </label>
                </div>

                <!-- Subject list -->
                <div style="margin-bottom:6px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;">
                    Select Subjects
                </div>

                <div class="select-all-bar">
                    <label>
                        <input type="checkbox" id="selectAllSubjects">
                        Select All Subjects
                    </label>
                    <span class="selected-count-badge" id="subjectCountBadge">0 selected</span>
                </div>

                <div class="subject-checkbox-list" id="subjectCheckboxList">
                    <!-- Populated by JS -->
                </div>

                <p style="font-size:11px;color:var(--muted);margin-top:10px;">
                    ✅ Each selected class will be assigned all checked subjects.
                </p>

                <div id="assignStatusMessage" class="assign-status-message" style="display:none;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn">Assign Subjects</button>
            </div>

            <div id="hiddenInputsContainer"></div>
        </form>
    </div>
</div>

<!-- =========================================================
     CREATE MODAL
========================================================= -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST" action="subjects.php">
            <div class="modal-header">
                <h2>Add Subject</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Subject Name <span class="required">*</span></label>
                        <input type="text" name="subject_name" class="form-control" maxlength="150" placeholder="e.g. Mathematics" required>
                    </div>
                    <div class="form-group full">
                        <label>Type</label>
                        <select name="subject_type" class="form-control">
                            <?php foreach ($valid_types as $t): ?>
                                <option value="<?php echo e($t); ?>" <?php echo $t === 'academic' ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(e($t)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Subject</button>
            </div>
            <input type="hidden" name="action" value="create">
        </form>
    </div>
</div>

<!-- =========================================================
     EDIT MODAL
========================================================= -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <form method="POST" action="subjects.php">
            <div class="modal-header">
                <h2>Edit Subject</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Subject Name <span class="required">*</span></label>
                        <input type="text" name="subject_name" id="edit_subject_name" class="form-control" maxlength="150" required>
                    </div>
                    <div class="form-group full">
                        <label>Type</label>
                        <select name="subject_type" id="edit_subject_type" class="form-control">
                            <?php foreach ($valid_types as $t): ?>
                                <option value="<?php echo e($t); ?>"><?php echo ucfirst(e($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="subject_id" id="edit_subject_id">
        </form>
    </div>
</div>

<script>
/* =========================================================
   MODAL CONTROLS
   ========================================================= */
function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openEditModal(data) {
    document.getElementById('edit_subject_id').value   = data.subject_id    || '';
    document.getElementById('edit_subject_name').value = data.subject_name  || '';
    document.getElementById('edit_subject_type').value = data.subject_type  || 'academic';
    document.getElementById('edit_status').value       = data.status        || 'active';

    document.getElementById('editModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-backdrop').forEach(function (bd) {
    bd.addEventListener('click', function (e) {
        if (e.target === bd) closeModal(bd.id);
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(bd => closeModal(bd.id));
        closeSidebar();
    }
});

/* =========================================================
   MOBILE DRAWER
   ========================================================= */
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');
    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar, #adminSidebar');
    if (sidebar) sidebar.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}

function closeSidebar() {
    sidebarOverlay.classList.remove('open');
    const sidebar = document.querySelector('.admin-sidebar, #sidebar, .sidebar, #adminSidebar');
    if (sidebar) sidebar.classList.remove('open');
    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', () => {
        sidebarOverlay.classList.contains('open') ? closeSidebar() : openSidebar();
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', () => {
    if (window.innerWidth > 800) closeSidebar();
});

/* =========================================================
   ASSIGN SUBJECTS TO CLASSES
   ========================================================= */

// All subjects (injected from PHP)
const allSubjects = <?php echo json_encode($subjects, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// All classes (from DB)
const allClasses = <?php echo json_encode($classes, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// Map: class_id => [subject_id, subject_id, ...] (already assigned)
const assignmentsMap = <?php echo json_encode($assignments_map, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

let selectedSubjectIds = new Set();

/* --- Open the assign modal (bulk) --- */
function openAssignModal() {
    selectedSubjectIds.clear();
    renderAssignModal();
    applySubjectVisibilityFilter();
    document.getElementById('assignModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

/* --- Open for a specific subject (pre-select) --- */
function openAssignForSubject(subjectId, subjectName) {
    selectedSubjectIds.clear();
    selectedSubjectIds.add(String(subjectId));
    renderAssignModal();
    applySubjectVisibilityFilter();
    document.querySelector('#assignModal .modal-header h2').textContent =
        `📚 Assign "${subjectName}" to Classes`;
    document.getElementById('assignModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

/* --- Render the modal content --- */
function renderAssignModal() {
    const classContainer = document.getElementById('classSelector');
    const subjectList    = document.getElementById('subjectCheckboxList');

    if (selectedSubjectIds.size <= 1) {
        document.querySelector('#assignModal .modal-header h2').textContent =
            '📚 Assign Subjects to Classes';
    }

    /* ---- Classes ---- */
    classContainer.innerHTML = '';

    if (allClasses.length === 0) {
        classContainer.innerHTML =
            '<div class="assign-empty" style="width:100%;">No classes found in the database. Please add classes first.</div>';
    } else {
        allClasses.forEach(cls => {
            const label = document.createElement('label');
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = cls.id;
            cb.name = 'class_ids[]';
            cb.dataset.classId = cls.id;

            cb.addEventListener('change', function () {
                label.classList.toggle('checked', this.checked);
                // Re-evaluate the "only new" filter whenever classes change
                applySubjectVisibilityFilter();
            });

            label.appendChild(cb);
            label.appendChild(document.createTextNode(' ' + cls.name));
            classContainer.appendChild(label);
        });
    }

    /* ---- Subjects ---- */
    subjectList.innerHTML = '';

    if (allSubjects.length === 0) {
        subjectList.innerHTML =
            '<div class="assign-empty">No subjects available. Add subjects first.</div>';
        document.getElementById('assignSubmitBtn').disabled = true;
    } else {
        document.getElementById('assignSubmitBtn').disabled = false;

        allSubjects.forEach(sub => {
            const item = document.createElement('label');
            item.className = 'subject-checkbox-item';
            item.dataset.subjectId = sub.subject_id;

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = sub.subject_id;
            cb.dataset.subjectId = sub.subject_id;

            if (selectedSubjectIds.has(String(sub.subject_id))) {
                cb.checked = true;
            }

            cb.addEventListener('change', function () {
                if (this.checked) selectedSubjectIds.add(this.value);
                else selectedSubjectIds.delete(this.value);
                updateSubjectCountBadge();
                updateSelectAllCheckbox();
            });

            const info = document.createElement('div');
            info.className = 'sub-info';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'sub-name';
            nameSpan.textContent = sub.subject_name;

            const typeSpan = document.createElement('span');
            typeSpan.className = 'sub-type';
            typeSpan.textContent = sub.subject_type;

            info.appendChild(nameSpan);
            info.appendChild(typeSpan);

            item.appendChild(cb);
            item.appendChild(info);
            subjectList.appendChild(item);
        });
    }

    updateSubjectCountBadge();
    updateSelectAllCheckbox();
    bindSelectAll();
    bindOnlyNewToggle();
}

/* --- Show/hide subjects based on the "only new" toggle --- */
function applySubjectVisibilityFilter() {
    const onlyNew = document.getElementById('onlyNewSubjects');
    if (!onlyNew) return;

    const selectedClasses = Array.from(
        document.querySelectorAll('#classSelector input[type="checkbox"]:checked')
    ).map(cb => String(cb.value));

    const items = document.querySelectorAll('#subjectCheckboxList .subject-checkbox-item');

    items.forEach(item => {
        const sid = String(item.dataset.subjectId);

        // If the toggle is OFF → always show
        if (!onlyNew.checked) {
            item.classList.remove('assign-hidden');
            return;
        }

        // If toggle is ON but no class selected → show all (nothing to hide against)
        if (selectedClasses.length === 0) {
            item.classList.remove('assign-hidden');
            return;
        }

        // "Only new" = NOT already assigned to any of the currently selected classes
        const alreadyAssigned = selectedClasses.some(cid => {
            const list = assignmentsMap[cid] || [];
            return list.map(String).includes(sid);
        });

        if (alreadyAssigned) {
            item.classList.add('assign-hidden');
        } else {
            item.classList.remove('assign-hidden');
        }
    });

    updateSubjectCountBadge();
    updateSelectAllCheckbox();
}

/* --- Bind the "only new" checkbox (once) --- */
function bindOnlyNewToggle() {
    const cb = document.getElementById('onlyNewSubjects');
    if (!cb || cb.dataset.bound === '1') return;
    cb.dataset.bound = '1';
    cb.addEventListener('change', applySubjectVisibilityFilter);
}

/* --- Bind the "select all" checkbox --- */
function bindSelectAll() {
    const selectAllCb = document.getElementById('selectAllSubjects');
    if (!selectAllCb) return;

    // Replace to drop old listeners
    const newSelectAll = selectAllCb.cloneNode(true);
    selectAllCb.parentNode.replaceChild(newSelectAll, selectAllCb);

    newSelectAll.addEventListener('change', function () {
        // Only affect VISIBLE items
        const visibleCheckboxes = document.querySelectorAll(
            '#subjectCheckboxList .subject-checkbox-item:not(.assign-hidden) input[type="checkbox"]'
        );

        if (this.checked) {
            visibleCheckboxes.forEach(cb => {
                cb.checked = true;
                selectedSubjectIds.add(cb.value);
            });
        } else {
            visibleCheckboxes.forEach(cb => {
                cb.checked = false;
                selectedSubjectIds.delete(cb.value);
            });
        }
        updateSubjectCountBadge();
    });
}

/* --- Count badge --- */
function updateSubjectCountBadge() {
    const badge = document.getElementById('subjectCountBadge');
    if (badge) badge.textContent = selectedSubjectIds.size + ' selected';
}

/* --- Sync Select All state with visible items --- */
function updateSelectAllCheckbox() {
    const selectAll = document.getElementById('selectAllSubjects');
    if (!selectAll) return;

    const visible = document.querySelectorAll(
        '#subjectCheckboxList .subject-checkbox-item:not(.assign-hidden) input[type="checkbox"]'
    );

    if (visible.length === 0) {
        selectAll.checked = false;
        selectAll.disabled = true;
        return;
    }

    selectAll.disabled = false;
    selectAll.checked = Array.from(visible).every(cb => cb.checked);
}

/* --- AJAX submit --- */
document.getElementById('assignForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const submitBtn  = document.getElementById('assignSubmitBtn');
    const classIds   = Array.from(
        document.querySelectorAll('#classSelector input[type="checkbox"]:checked')
    ).map(cb => cb.value);
    const subjectIds = Array.from(selectedSubjectIds);

    if (classIds.length === 0) {
        showAssignMessage('Please select at least one class.', 'error');
        return;
    }
    if (subjectIds.length === 0) {
        showAssignMessage('Please select at least one subject.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('ajax_action', 'assign_subjects');
    classIds.forEach(id => formData.append('class_ids[]', id));
    subjectIds.forEach(id => formData.append('subject_ids[]', id));

    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Assigning...';

    try {
        const response = await fetch('subjects.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (err) {
            console.error(rawText);
            throw new Error('The server returned an unexpected response. Check the PHP error log.');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Could not assign the subjects.');
        }

        showAssignMessage(data.message || 'Subjects assigned successfully.', 'success');

        setTimeout(() => {
            closeModal('assignModal');
            // Reload so the assignments card refreshes
            window.location.reload();
        }, 900);
    } catch (error) {
        console.error('Subject assignment error:', error);
        showAssignMessage(error.message || 'An unexpected error occurred.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
});

function showAssignMessage(message, type) {
    const box = document.getElementById('assignStatusMessage');
    if (!box) return;
    box.textContent = message;
    box.className = 'assign-status-message ' + (type === 'success' ? 'success' : 'error');
    box.style.display = 'block';
}

/* --- Reset when modal closes --- */
document.getElementById('assignModal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeModal('assignModal');
        resetAssignModal();
    }
});

function resetAssignModal() {
    selectedSubjectIds.clear();
    document.getElementById('hiddenInputsContainer').innerHTML = '';

    const statusBox = document.getElementById('assignStatusMessage');
    if (statusBox) {
        statusBox.textContent = '';
        statusBox.className = 'assign-status-message';
        statusBox.style.display = 'none';
    }

    const onlyNew = document.getElementById('onlyNewSubjects');
    if (onlyNew) onlyNew.checked = false;

    document.querySelector('#assignModal .modal-header h2').textContent =
        '📚 Assign Subjects to Classes';

    document.querySelectorAll('#classSelector input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
        cb.closest('label').classList.remove('checked');
    });
    document.querySelectorAll('#subjectCheckboxList input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('#subjectCheckboxList .subject-checkbox-item').forEach(i => {
        i.classList.remove('assign-hidden');
    });

    updateSubjectCountBadge();
    updateSelectAllCheckbox();
}
</script>

</body>
</html>