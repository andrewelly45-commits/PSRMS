<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('super_admin');

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


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       SAVE USER (create or update)
    ------------------------------------------------------------- */
    if ($action === 'save_user') {

        $edit_id     = (int)($_POST['user_id']     ?? 0);
        $first_name  = trim($_POST['first_name']   ?? '');
        $middle_name = trim($_POST['middle_name']  ?? '');
        $last_name   = trim($_POST['last_name']    ?? '');
        $email       = trim($_POST['email']        ?? '');
        $phone       = trim($_POST['phone']        ?? '');
        $role        = trim($_POST['role']         ?? '');
        $status      = trim($_POST['status']       ?? 'active');
        $password    = $_POST['password']          ?? '';

        $errors = [];

        if ($first_name === '') $errors['first_name'] = 'First name required.';
        if ($last_name  === '') $errors['last_name']  = 'Last name required.';

        if ($email === '') {
            $errors['email'] = 'Email required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email.';
        }

        $valid_roles = ['super_admin', 'admin', 'teacher', 'parent'];
        if (!in_array($role, $valid_roles, true)) {
            $errors['role'] = 'Invalid role.';
        }

        $valid_status = ['active', 'inactive', 'suspended'];
        if (!in_array($status, $valid_status, true)) {
            $status = 'active';
        }

        $is_edit = $edit_id > 0;

        if (!$is_edit && strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }
        if ($is_edit && $password !== '' && strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }

        /* Email uniqueness */
        if (empty($errors['email'])) {
            $sql = $is_edit
                ? "SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1"
                : "SELECT user_id FROM users WHERE email = ? LIMIT 1";

            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                if ($is_edit) {
                    mysqli_stmt_bind_param($stmt, 'si', $email, $edit_id);
                } else {
                    mysqli_stmt_bind_param($stmt, 's', $email);
                }
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors['email'] = 'Email already in use.';
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (!empty($errors)) {
            json_response([
                'success' => false,
                'message' => 'Please fix the highlighted fields.',
                'errors'  => $errors,
            ]);
        }

        try {
            if (!$is_edit) {

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO users
                        (first_name, middle_name, last_name, email, phone, password, role, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$stmt) throw new Exception(mysqli_error($conn));
                mysqli_stmt_bind_param(
                    $stmt, 'ssssssss',
                    $first_name, $middle_name, $last_name,
                    $email, $phone, $hash, $role, $status
                );
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
                $new_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                json_response([
                    'success' => true,
                    'message' => 'User created successfully.',
                    'user_id' => $new_id,
                ]);
            }

            /* Update */
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET first_name=?, middle_name=?, last_name=?,
                         email=?, phone=?, role=?, status=?, password=?
                     WHERE user_id=?"
                );
                if (!$stmt) throw new Exception(mysqli_error($conn));
                mysqli_stmt_bind_param(
                    $stmt, 'ssssssssi',
                    $first_name, $middle_name, $last_name,
                    $email, $phone, $role, $status, $hash, $edit_id
                );
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET first_name=?, middle_name=?, last_name=?,
                         email=?, phone=?, role=?, status=?
                     WHERE user_id=?"
                );
                if (!$stmt) throw new Exception(mysqli_error($conn));
                mysqli_stmt_bind_param(
                    $stmt, 'sssssssi',
                    $first_name, $middle_name, $last_name,
                    $email, $phone, $role, $status, $edit_id
                );
            }
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            json_response([
                'success' => true,
                'message' => 'User updated successfully.',
                'user_id' => $edit_id,
            ]);

        } catch (Throwable $ex) {
            json_response(['success' => false, 'message' => $ex->getMessage()]);
        }
    }

    /* -------------------------------------------------------------
       DELETE USER
    ------------------------------------------------------------- */
    if ($action === 'delete_user') {

        $target = (int)($_POST['user_id'] ?? 0);
        if ($target <= 0) json_response(['success' => false, 'message' => 'Invalid user.']);
        if ($target === $user_id) json_response(['success' => false, 'message' => 'You cannot delete your own account.']);

        /* Prevent deleting the last super admin */
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'super_admin'");
        if ($r) {
            $c = (int)(mysqli_fetch_assoc($r)['c'] ?? 0);
            $chk = mysqli_prepare($conn, "SELECT role FROM users WHERE user_id = ? LIMIT 1");
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'i', $target);
                mysqli_stmt_execute($chk);
                $rr = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
                mysqli_stmt_close($chk);
                if (($rr['role'] ?? '') === 'super_admin' && $c <= 1) {
                    json_response(['success' => false, 'message' => 'Cannot delete the last super admin.']);
                }
            }
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE user_id = ?");
        if (!$stmt) json_response(['success' => false, 'message' => 'Database error.']);
        mysqli_stmt_bind_param($stmt, 'i', $target);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        json_response([
            'success' => $ok,
            'message' => $ok ? 'User deleted.' : 'Could not delete user.',
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   FILTERS + LIST
   ========================================================================= */

$search     = trim($_GET['q']       ?? '');
$role_f     = trim($_GET['role']    ?? '');
$status_f   = trim($_GET['status']  ?? '');

$where  = ["1=1"];
$params = [];
$types  = '';

if ($search !== '') {
    $where[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if (in_array($role_f, ['super_admin', 'admin', 'teacher', 'parent'], true)) {
    $where[] = "role = ?";
    $params[] = $role_f;
    $types .= 's';
}

if (in_array($status_f, ['active', 'inactive', 'suspended'], true)) {
    $where[] = "status = ?";
    $params[] = $status_f;
    $types .= 's';
}

$sql = "SELECT user_id, first_name, middle_name, last_name, email, phone, role, status, profile_pic, created_at
        FROM users
        WHERE " . implode(' AND ', $where) . "
        ORDER BY user_id DESC
        LIMIT 500";

$stmt = mysqli_prepare($conn, $sql);
$users = [];
if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $users[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   COUNTS
   ========================================================================= */

$counts = ['super_admin' => 0, 'admin' => 0, 'teacher' => 0, 'parent' => 0, 'total' => 0];
$r = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(role='super_admin') AS sa,
        SUM(role='admin') AS a,
        SUM(role='teacher') AS t,
        SUM(role='parent') AS p
     FROM users"
);
if ($r && $row = mysqli_fetch_assoc($r)) {
    $counts['total']       = (int)$row['total'];
    $counts['super_admin'] = (int)$row['sa'];
    $counts['admin']       = (int)$row['a'];
    $counts['teacher']     = (int)$row['t'];
    $counts['parent']      = (int)$row['p'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>User Management | Super Admin</title>

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
        .page-title p { color: var(--muted); font-size: 12.5px; margin-top: 6px; }

        /* BTN */
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
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
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
        .btn-danger { background: var(--red); color: #fff; }
        .btn-danger:hover:not(:disabled) { background: #863a3a; }
        .btn .spinner {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }
        .btn.loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* STATS */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .mini-stat {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .mini-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }
        .mini-icon.navy  { background: var(--navy);   color: var(--gold-light); }
        .mini-icon.gold  { background: var(--gold-light); color: var(--navy); }
        .mini-icon.blue  { background: var(--blue-bg);   color: var(--blue); }
        .mini-icon.green { background: var(--green-bg);  color: var(--green); }
        .mini-icon.orange{ background: var(--orange-bg); color: var(--orange); }
        .mini-stat .lbl {
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
        }
        .mini-stat .val {
            color: var(--navy);
            font-size: 17px;
            font-weight: 750;
            margin-top: 2px;
        }

        /* FILTERS */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }
        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }
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

        /* TABLE PANEL */
        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }
        table.data-table thead th {
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
        table.data-table tbody td {
            padding: 12px 14px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
        }
        table.data-table tbody tr:last-child td { border-bottom: none; }
        table.data-table tbody tr:hover { background: #fbfbf8; }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .user-avatar-sm {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
        }
        .user-avatar-sm img { width: 100%; height: 100%; object-fit: cover; }
        .user-name {
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .user-meta {
            color: var(--muted);
            font-size: 10.5px;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            white-space: nowrap;
        }
        .badge-role-super_admin { background: var(--gold); color: var(--navy); }
        .badge-role-admin       { background: var(--blue-bg); color: var(--blue); }
        .badge-role-teacher     { background: var(--green-bg); color: var(--green); }
        .badge-role-parent      { background: var(--orange-bg); color: var(--orange); }

        .badge-status-active    { background: var(--green-bg); color: var(--green); }
        .badge-status-inactive  { background: var(--orange-bg); color: var(--orange); }
        .badge-status-suspended { background: var(--red-bg); color: var(--red); }

        .badge::before {
            content: "";
            width: 5px; height: 5px;
            border-radius: 50%;
            background: currentColor;
        }
        .badge-role-super_admin::before { display: none; }

        .row-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
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
        }
        .icon-btn:hover { border-color: var(--gold); }
        .icon-btn.danger { color: var(--red); }
        .icon-btn.danger:hover { border-color: var(--red); background: var(--red-bg); }

        /* MOBILE CARDS */
        .card-list { display: none; }
        .user-card {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
        }
        .user-card:last-child { border-bottom: none; }
        .user-card-top {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 10px;
        }
        .user-card-body { flex: 1; min-width: 0; }
        .user-card-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .user-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }
        .user-card-actions .icon-btn {
            min-height: 40px;
            font-size: 12px;
        }

        /* EMPTY */
        .empty {
            padding: 60px 24px;
            text-align: center;
            color: var(--muted);
        }
        .empty-icon {
            width: 68px; height: 68px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }
        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 6px; }

        /* MODAL */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(16,24,43,.55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
            overflow-y: auto;
        }
        .modal-backdrop.open { display: flex; }

        .modal {
            background: var(--white);
            border-radius: 14px;
            width: 100%;
            max-width: 620px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.3);
            animation: pop .2s ease-out;
        }
        @keyframes pop {
            from { transform: scale(.96); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1;
            border-radius: 14px 14px 0 0;
        }
        .modal-header h3 {
            color: var(--navy);
            font-size: 15px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-header h3 i { color: var(--gold); }
        .modal-close {
            width: 34px; height: 34px;
            border: none;
            background: #f3f4f6;
            border-radius: 8px;
            color: var(--muted);
            font-size: 16px;
            cursor: pointer;
        }
        .modal-close:hover { background: #e5e7eb; color: var(--navy); }

        .modal-body { padding: 22px; }
        .modal-footer {
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            background: #fafaf8;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            border-radius: 0 0 14px 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { min-width: 0; }
        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .form-group label .req { color: var(--red); margin-left: 2px; }
        .form-control {
            width: 100%;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13.5px;
            background: #fcfcfd;
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }
        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }
        .form-control:focus {
            border-color: var(--gold);
            background-color: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }
        .form-control.error {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(155,71,71,.1);
        }
        .field-error {
            display: none;
            color: var(--red);
            font-size: 11px;
            margin-top: 5px;
        }
        .field-error.show { display: block; }

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
            box-shadow: 0 10px 30px rgba(16,24,43,.12);
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            min-width: 260px;
            max-width: 380px;
            pointer-events: auto;
            animation: slideIn .25s ease;
        }
        .toast i { color: var(--green); flex-shrink: 0; }
        .toast.error { border-left-color: var(--red); }
        .toast.error i { color: var(--red); }
        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }

        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .stats-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }
            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-title h1 i { font-size: 18px; }
            .page-title p  { font-size: 12px; }

            .stats-row { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .mini-stat { padding: 11px; gap: 10px; }
            .mini-icon { width: 34px; height: 34px; font-size: 14px; }
            .mini-stat .val { font-size: 15px; }

            .filter-form { grid-template-columns: 1fr; gap: 12px; }
            .filter-control { height: 46px; font-size: 14px; }
            .filter-form .btn { width: 100%; min-height: 46px; }

            .table-wrapper { display: none; }
            .card-list { display: block; }

            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal { border-radius: 16px 16px 0 0; max-width: 100%; max-height: 92vh; }
            .modal-header { border-radius: 16px 16px 0 0; }
            .form-grid { grid-template-columns: 1fr; }
            .modal-footer { flex-direction: column-reverse; padding: 14px 18px; }
            .modal-footer .btn { width: 100%; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .stats-row { gap: 8px; }
            .mini-stat {
                padding: 10px;
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .mini-icon { width: 30px; height: 30px; font-size: 12px; }
            .mini-stat .val { font-size: 14px; }

            .user-avatar-sm { width: 34px; height: 34px; font-size: 11px; }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast { min-width: auto; max-width: 100%; }
        }

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

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'User Management';
$topbar_subtitle = 'Super Admin';
include '../includes/topbar.php';
?>

<?php include 'superadmin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-users-gear"></i> User Management</h1>
            <p>Create, edit, and manage all system users across every role.</p>
        </div>
        <div>
            <button type="button" class="btn btn-gold" onclick="openUserModal()">
                <i class="fa-solid fa-user-plus"></i> Add New User
            </button>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-row">
        <div class="mini-stat">
            <div class="mini-icon navy"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="lbl">Total</div>
                <div class="val"><?php echo number_format($counts['total']); ?></div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="mini-icon gold"><i class="fa-solid fa-crown"></i></div>
            <div>
                <div class="lbl">Super Admins</div>
                <div class="val"><?php echo number_format($counts['super_admin']); ?></div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="mini-icon blue"><i class="fa-solid fa-user-tie"></i></div>
            <div>
                <div class="lbl">Admins</div>
                <div class="val"><?php echo number_format($counts['admin']); ?></div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="mini-icon green"><i class="fa-solid fa-chalkboard-user"></i></div>
            <div>
                <div class="lbl">Teachers</div>
                <div class="val"><?php echo number_format($counts['teacher']); ?></div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="mini-icon orange"><i class="fa-solid fa-user-group"></i></div>
            <div>
                <div class="lbl">Parents</div>
                <div class="val"><?php echo number_format($counts['parent']); ?></div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" action="users.php" class="filter-panel">
        <div class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="Name or email..."
                       value="<?php echo e($search); ?>">
            </div>

            <div class="filter-group">
                <label>Role</label>
                <select name="role" class="filter-control">
                    <option value="">All Roles</option>
                    <option value="super_admin" <?php echo $role_f === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                    <option value="admin"       <?php echo $role_f === 'admin'       ? 'selected' : ''; ?>>Admin</option>
                    <option value="teacher"     <?php echo $role_f === 'teacher'     ? 'selected' : ''; ?>>Teacher</option>
                    <option value="parent"      <?php echo $role_f === 'parent'      ? 'selected' : ''; ?>>Parent</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Statuses</option>
                    <option value="active"    <?php echo $status_f === 'active'    ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive"  <?php echo $status_f === 'inactive'  ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo $status_f === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>

            <?php if ($search || $role_f || $status_f): ?>
                <a href="users.php" class="btn btn-ghost">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>

        </div>
    </form>

    <!-- TABLE -->
    <section class="table-panel">

        <?php if (empty($users)): ?>

            <div class="empty">
                <div class="empty-icon"><i class="fa-solid fa-users-slash"></i></div>
                <h3>No users found</h3>
                <p>Try adjusting the filters or add a new user.</p>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $initials = strtoupper(
                                mb_substr($u['first_name'], 0, 1) .
                                mb_substr($u['last_name'], 0, 1)
                            );
                            $role_lower   = strtolower($u['role']);
                            $status_lower = strtolower($u['status']);
                        ?>
                            <tr data-user-id="<?php echo (int)$u['user_id']; ?>">
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar-sm">
                                            <?php if (!empty($u['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $u['profile_pic'])): ?>
                                                <img src="../uploads/users/<?php echo e($u['profile_pic']); ?>" alt="">
                                            <?php else: ?>
                                                <?php echo e($initials); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="user-name">
                                                <?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>
                                            </div>
                                            <div class="user-meta">#<?php echo (int)$u['user_id']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo e($u['email']); ?></td>
                                <td>
                                    <span class="badge badge-role-<?php echo e($role_lower); ?>">
                                        <?php echo e(str_replace('_', ' ', $role_lower)); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-status-<?php echo e($status_lower); ?>">
                                        <?php echo e($status_lower); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="row-actions" style="justify-content:flex-end;">
                                        <button type="button" class="icon-btn edit-btn"
                                                data-user='<?php echo e(json_encode([
                                                    "user_id"     => (int)$u["user_id"],
                                                    "first_name"  => $u["first_name"],
                                                    "middle_name" => $u["middle_name"],
                                                    "last_name"   => $u["last_name"],
                                                    "email"       => $u["email"],
                                                    "phone"       => $u["phone"],
                                                    "role"        => $u["role"],
                                                    "status"      => $u["status"],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </button>
                                        <button type="button" class="icon-btn danger delete-btn"
                                                data-user-id="<?php echo (int)$u['user_id']; ?>"
                                                data-user-name="<?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($users as $u):
                    $initials = strtoupper(
                        mb_substr($u['first_name'], 0, 1) .
                        mb_substr($u['last_name'], 0, 1)
                    );
                    $role_lower   = strtolower($u['role']);
                    $status_lower = strtolower($u['status']);
                ?>
                    <div class="user-card" data-user-id="<?php echo (int)$u['user_id']; ?>">

                        <div class="user-card-top">
                            <div class="user-avatar-sm">
                                <?php if (!empty($u['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $u['profile_pic'])): ?>
                                    <img src="../uploads/users/<?php echo e($u['profile_pic']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo e($initials); ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-card-body">
                                <div class="user-name">
                                    <?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>
                                </div>
                                <div class="user-meta"><?php echo e($u['email']); ?></div>
                            </div>
                        </div>

                        <div class="user-card-badges">
                            <span class="badge badge-role-<?php echo e($role_lower); ?>">
                                <?php echo e(str_replace('_', ' ', $role_lower)); ?>
                            </span>
                            <span class="badge badge-status-<?php echo e($status_lower); ?>">
                                <?php echo e($status_lower); ?>
                            </span>
                        </div>

                        <div class="user-card-actions">
                            <button type="button" class="icon-btn edit-btn"
                                    data-user='<?php echo e(json_encode([
                                        "user_id"     => (int)$u["user_id"],
                                        "first_name"  => $u["first_name"],
                                        "middle_name" => $u["middle_name"],
                                        "last_name"   => $u["last_name"],
                                        "email"       => $u["email"],
                                        "phone"       => $u["phone"],
                                        "role"        => $u["role"],
                                        "status"      => $u["status"],
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                <i class="fa-solid fa-pen"></i> Edit
                            </button>
                            <button type="button" class="icon-btn danger delete-btn"
                                    data-user-id="<?php echo (int)$u['user_id']; ?>"
                                    data-user-name="<?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>">
                                <i class="fa-solid fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </section>

</main>


<!-- USER MODAL -->
<div class="modal-backdrop" id="userModal">
    <div class="modal">
        <form id="userForm" autocomplete="off">
            <input type="hidden" name="user_id" id="edit_user_id" value="">

            <div class="modal-header">
                <h3 id="userModalTitle"><i class="fa-solid fa-user-plus"></i> Add New User</h3>
                <button type="button" class="modal-close" onclick="closeUserModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group">
                        <label>First Name <span class="req">*</span></label>
                        <input type="text" name="first_name" class="form-control" maxlength="100" required>
                        <div class="field-error" data-error-for="first_name"></div>
                    </div>

                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label>Last Name <span class="req">*</span></label>
                        <input type="text" name="last_name" class="form-control" maxlength="100" required>
                        <div class="field-error" data-error-for="last_name"></div>
                    </div>

                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" class="form-control" maxlength="30">
                    </div>

                    <div class="form-group full">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" class="form-control" maxlength="150" required>
                        <div class="field-error" data-error-for="email"></div>
                    </div>

                    <div class="form-group">
                        <label>Role <span class="req">*</span></label>
                        <select name="role" class="form-control" required>
                            <option value="admin">Admin</option>
                            <option value="teacher">Teacher</option>
                            <option value="parent">Parent</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                        <div class="field-error" data-error-for="role"></div>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>
                            Password <span class="req" id="pwdReq">*</span>
                        </label>
                        <input type="password" name="password" id="passwordInput"
                               class="form-control" minlength="6" maxlength="100">
                        <div class="field-error" data-error-for="password"></div>
                        <div style="font-size:11px;color:var(--muted);margin-top:5px;" id="pwdHint">
                            Minimum 6 characters.
                        </div>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveUserBtn">
                    <span class="spinner"></span>
                    <i class="fa-solid fa-floppy-disk"></i> Save User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-body" style="text-align:center;padding:30px 22px;">
            <div style="width:64px;height:64px;margin:0 auto 16px;border-radius:50%;background:var(--red-bg);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:26px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 style="color:var(--navy);font-size:17px;margin-bottom:8px;">Delete User?</h3>
            <p style="color:var(--muted);font-size:13px;line-height:1.55;">
                You are about to delete
                <strong id="delUserName" style="color:var(--navy);">this user</strong>.
                This cannot be undone.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                <span class="spinner"></span>
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>


<script>
/* ============ TOASTS ============ */
function showToast(msg, type = 'success') {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : '');
    el.innerHTML = '<i class="fa-solid ' +
        (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') +
        '"></i><span>' + msg + '</span>';
    wrap.appendChild(el);
    setTimeout(() => {
        el.style.transition = 'opacity .25s, transform .25s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, 3200);
}

/* ============ USER MODAL ============ */
const userModal     = document.getElementById('userModal');
const userForm      = document.getElementById('userForm');
const modalTitle    = document.getElementById('userModalTitle');
const pwdReq        = document.getElementById('pwdReq');
const pwdHint       = document.getElementById('pwdHint');
const passwordInput = document.getElementById('passwordInput');
const editIdInput   = document.getElementById('edit_user_id');

function clearErrors() {
    userForm.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));
    userForm.querySelectorAll('.field-error').forEach(el => {
        el.textContent = '';
        el.classList.remove('show');
    });
}
function showErrors(errors) {
    Object.entries(errors || {}).forEach(([f, m]) => {
        const inp = userForm.querySelector('[name="' + f + '"]');
        if (inp) inp.classList.add('error');
        const err = userForm.querySelector('[data-error-for="' + f + '"]');
        if (err) { err.textContent = m; err.classList.add('show'); }
    });
}

function openUserModal(data = null) {
    clearErrors();
    userForm.reset();
    editIdInput.value = '';

    if (data && data.user_id) {
        modalTitle.innerHTML = '<i class="fa-solid fa-pen"></i> Edit User';
        editIdInput.value = data.user_id;
        userForm.first_name.value  = data.first_name  || '';
        userForm.middle_name.value = data.middle_name || '';
        userForm.last_name.value   = data.last_name   || '';
        userForm.email.value       = data.email       || '';
        userForm.phone.value       = data.phone       || '';
        userForm.role.value        = data.role        || 'parent';
        userForm.status.value      = data.status      || 'active';
        pwdReq.style.display = 'none';
        passwordInput.required = false;
        pwdHint.textContent = 'Leave blank to keep current password.';
    } else {
        modalTitle.innerHTML = '<i class="fa-solid fa-user-plus"></i> Add New User';
        pwdReq.style.display = '';
        passwordInput.required = true;
        pwdHint.textContent = 'Minimum 6 characters.';
    }

    userModal.classList.add('open');
    document.body.classList.add('no-scroll');
}

function closeUserModal() {
    userModal.classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

/* ============ DELETE MODAL ============ */
let pendingDeleteId = 0;
const deleteModal  = document.getElementById('deleteModal');

function openDeleteModal(id, name) {
    pendingDeleteId = id;
    document.getElementById('delUserName').textContent = name;
    deleteModal.classList.add('open');
    document.body.classList.add('no-scroll');
}
function closeDeleteModal() {
    deleteModal.classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

/* ============ EVENT BINDINGS ============ */
document.addEventListener('click', e => {
    const editBtn = e.target.closest('.edit-btn');
    if (editBtn) {
        try {
            openUserModal(JSON.parse(editBtn.dataset.user));
        } catch (err) {
            console.error(err);
            showToast('Could not open user.', 'error');
        }
        return;
    }
    const delBtn = e.target.closest('.delete-btn');
    if (delBtn) {
        openDeleteModal(
            parseInt(delBtn.dataset.userId, 10),
            delBtn.dataset.userName || 'this user'
        );
        return;
    }
});

/* Close modals on backdrop click */
document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) {
            bd.classList.remove('open');
            if (!document.querySelector('.modal-backdrop.open')) {
                document.body.classList.remove('no-scroll');
            }
        }
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(bd => bd.classList.remove('open'));
        document.body.classList.remove('no-scroll');
    }
});

/* ============ SAVE USER ============ */
userForm.addEventListener('submit', async e => {
    e.preventDefault();
    clearErrors();

    const fd = new FormData(userForm);
    fd.set('ajax_action', 'save_user');

    const btn = document.getElementById('saveUserBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    try {
        const res = await fetch('users.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const raw = await res.text();
        let json;
        try { json = JSON.parse(raw); }
        catch { console.error(raw); throw new Error('Unexpected response.'); }

        if (!json.success) {
            if (json.errors) showErrors(json.errors);
            showToast(json.message || 'Could not save.', 'error');
            return;
        }

        showToast(json.message, 'success');
        closeUserModal();
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        console.error(err);
        showToast(err.message || 'Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});

/* ============ DELETE USER ============ */
document.getElementById('confirmDeleteBtn').addEventListener('click', async function () {
    if (!pendingDeleteId) return;

    const fd = new FormData();
    fd.set('ajax_action', 'delete_user');
    fd.set('user_id', pendingDeleteId);

    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    try {
        const res = await fetch('users.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Could not delete.', 'error');
            return;
        }

        showToast(json.message, 'success');
        closeDeleteModal();
        setTimeout(() => window.location.reload(), 700);
    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});

/* ============ MOBILE SIDEBAR ============ */
const hamburgerBtn   = document.getElementById('appHamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll', 'sidebar-mobile-open');
    if (sidebarOverlay) sidebarOverlay.classList.add('open');
    const s = document.querySelector('.sa-sidebar');
    if (s) s.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}
function closeSidebar() {
    document.body.classList.remove('sidebar-mobile-open');
    if (sidebarOverlay) sidebarOverlay.classList.remove('open');
    const s = document.querySelector('.sa-sidebar');
    if (s) s.classList.remove('open');
    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}
if (hamburgerBtn) hamburgerBtn.addEventListener('click', () => {
    if (document.body.classList.contains('sidebar-mobile-open')) closeSidebar();
    else openSidebar();
});
if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });
</script>

</body>
</html>