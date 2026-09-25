<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('parent');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function tableExists(mysqli $conn, string $t): bool
{
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $ajax_action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       UPDATE PROFILE
    ------------------------------------------------------------- */
    if ($ajax_action === 'update_profile') {

        $first_name  = trim($_POST['first_name']  ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name   = trim($_POST['last_name']   ?? '');
        $email       = trim($_POST['email']       ?? '');
        $phone       = trim($_POST['phone']       ?? '');
        $gender      = trim($_POST['gender']      ?? '');
        $occupation  = trim($_POST['occupation']  ?? '');
        $address     = trim($_POST['address']     ?? '');

        $errors = [];

        if ($first_name === '') $errors['first_name'] = 'First name is required.';
        if ($last_name  === '') $errors['last_name']  = 'Last name is required.';

        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($phone === '') $errors['phone'] = 'Phone number is required.';

        if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
            $gender = '';
        }

        if (empty($errors['email'])) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $email, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors['email'] = 'This email is already used by another account.';
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

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET first_name = ?, middle_name = ?, last_name = ?,
                     email = ?, phone = ?, gender = ?
                 WHERE user_id = ?"
            );
            if (!$stmt) throw new Exception('Could not prepare user update: ' . mysqli_error($conn));

            mysqli_stmt_bind_param(
                $stmt, 'ssssssi',
                $first_name, $middle_name, $last_name,
                $email, $phone, $gender, $user_id
            );
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Could not update user: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE parents SET occupation = ?, address = ? WHERE user_id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssi', $occupation, $address, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);

            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name']  = $last_name;

            $new_full_name = trim($first_name . ' ' .
                ($middle_name ? $middle_name . ' ' : '') . $last_name);
            $new_initial = strtoupper(mb_substr($first_name ?: $new_full_name, 0, 1));

            json_response([
                'success'      => true,
                'message'      => 'Profile updated successfully.',
                'full_name'    => $new_full_name,
                'initial'      => $new_initial,
                'email'        => $email,
                'phone'        => $phone,
                'occupation'   => $occupation,
                'address'      => $address,
                'gender'       => $gender,
            ]);

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            json_response([
                'success' => false,
                'message' => 'Could not update profile: ' . $ex->getMessage(),
            ]);
        }
    }


    /* -------------------------------------------------------------
       CHANGE PASSWORD
    ------------------------------------------------------------- */
    if ($ajax_action === 'change_password') {

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $errors = [];

        if ($current === '') $errors['current_password'] = 'Current password is required.';
        if (strlen($new) < 6) $errors['new_password'] = 'New password must be at least 6 characters.';
        if ($new !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';

        if (empty($errors['current_password'])) {
            $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE user_id = ? LIMIT 1");
            $hash = null;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $user_id);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                $row = mysqli_fetch_assoc($r);
                $hash = $row['password'] ?? null;
                mysqli_stmt_close($stmt);
            }

            if (!$hash) {
                $errors['current_password'] = 'Account not found.';
            } elseif (!password_verify($current, $hash)) {
                $errors['current_password'] = 'Current password is incorrect.';
            } elseif (password_verify($new, $hash)) {
                $errors['new_password'] = 'New password must be different from the current one.';
            }
        }

        if (!empty($errors)) {
            json_response([
                'success' => false,
                'message' => 'Please fix the highlighted fields.',
                'errors'  => $errors,
            ]);
        }

        $new_hash = password_hash($new, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE user_id = ?");
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'Could not prepare password update.']);
        }
        mysqli_stmt_bind_param($stmt, 'si', $new_hash, $user_id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            json_response(['success' => false, 'message' => 'Could not update password.']);
        }

        json_response([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }


    /* -------------------------------------------------------------
       UPLOAD PHOTO
    ------------------------------------------------------------- */
    if ($ajax_action === 'upload_photo') {

        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'No file uploaded or upload failed.']);
        }

        $file = $_FILES['photo'];

        if ($file['size'] > 2 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'File must be smaller than 2 MB.']);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];

        if (!isset($allowed[$mime])) {
            json_response(['success' => false, 'message' => 'Only JPG, PNG, WEBP, or GIF allowed.']);
        }

        $ext = $allowed[$mime];

        $upload_dir = __DIR__ . '/../uploads/users/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        if (!is_writable($upload_dir)) {
            json_response(['success' => false, 'message' => 'Upload folder is not writable.']);
        }

        $new_name = 'user_' . $user_id . '_' . time() . '.' . $ext;
        $dest     = $upload_dir . $new_name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_response(['success' => false, 'message' => 'Could not save the file.']);
        }

        $stmt = mysqli_prepare($conn, "SELECT profile_pic FROM users WHERE user_id = ? LIMIT 1");
        $old = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($r);
            $old = $row['profile_pic'] ?? null;
            mysqli_stmt_close($stmt);
        }

        if ($old && is_file($upload_dir . $old)) {
            @unlink($upload_dir . $old);
        }

        $stmt = mysqli_prepare($conn, "UPDATE users SET profile_pic = ? WHERE user_id = ?");
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'Could not prepare update.']);
        }
        mysqli_stmt_bind_param($stmt, 'si', $new_name, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        json_response([
            'success'   => true,
            'message'   => 'Photo updated successfully.',
            'photo_url' => '../uploads/users/' . $new_name,
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   LOAD PARENT
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id, u.first_name, u.middle_name, u.last_name,
        u.email, u.phone, u.gender, u.profile_pic, u.status, u.created_at,
        p.parent_id, p.occupation, p.address
     FROM users u
     INNER JOIN parents p ON p.user_id = u.user_id
     WHERE u.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$parent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$parent) {
    die('Parent profile not found.');
}

$parent_id   = (int) $parent['parent_id'];
$full_name   = trim(
    $parent['first_name'] . ' ' .
    ($parent['middle_name'] ? $parent['middle_name'] . ' ' : '') .
    $parent['last_name']
);
$initial     = strtoupper(mb_substr($parent['first_name'] ?: $full_name, 0, 1));


/* =========================================================================
   CHILD COUNT (for info card)
   ========================================================================= */

$child_count = 0;
if (tableExists($conn, 'parent_children')) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM parent_children WHERE parent_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $parent_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $child_count = (int)($r['c'] ?? 0);
        mysqli_stmt_close($stmt);
    }
}

$member_since = !empty($parent['created_at'])
    ? date('F Y', strtotime($parent['created_at']))
    : '—';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>My Profile | PSRMS Parent</title>

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
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title h1 i { color: var(--gold); font-size: 22px; }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 6px;
        }

        /* LAYOUT */
        .profile-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 22px;
            align-items: start;
        }

        /* PROFILE CARD (LEFT) */
        .profile-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            position: sticky;
            top: calc(var(--topbar-h) + 20px);
        }

        .profile-cover {
            height: 90px;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 60%, #2a3a5c 100%);
            position: relative;
        }

        .profile-cover::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(201,162,39,.18), transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(201,162,39,.10), transparent 40%);
            pointer-events: none;
        }

        .profile-avatar-wrap {
            margin-top: -50px;
            display: flex;
            justify-content: center;
            position: relative;
            z-index: 2;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid var(--white);
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 800;
            object-fit: cover;
            box-shadow: 0 8px 22px rgba(16,24,43,.18);
            position: relative;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--gold);
            color: var(--navy);
            border: 3px solid var(--white);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
            box-shadow: 0 4px 12px rgba(201,162,39,.4);
        }

        .avatar-upload-btn:hover {
            background: var(--gold-light);
            transform: scale(1.06);
        }

        .profile-info {
            padding: 16px 22px 22px;
            text-align: center;
        }

        .profile-name {
            color: var(--navy);
            font-size: 17px;
            font-weight: 750;
            margin-bottom: 4px;
            overflow-wrap: anywhere;
        }

        .profile-email {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 14px;
            overflow-wrap: anywhere;
        }

        .profile-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            background: var(--green-bg);
            color: var(--green);
            font-size: 10.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 18px;
        }

        .profile-status-pill::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--green);
        }

        .profile-status-pill.inactive {
            background: var(--orange-bg);
            color: var(--orange);
        }
        .profile-status-pill.inactive::before { background: var(--orange); }

        .profile-info-rows {
            text-align: left;
            border-top: 1px solid var(--border);
            padding-top: 14px;
            margin-top: 4px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 0;
            font-size: 12.5px;
            border-bottom: 1px dashed #f0f1f3;
        }

        .info-row:last-child { border-bottom: none; }

        .info-row .ico {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #f3f5f9;
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        .info-row .ico.gold { background: var(--gold-light); color: var(--navy); }
        .info-row .ico.blue { background: var(--blue-bg); color: var(--blue); }
        .info-row .ico.green { background: var(--green-bg); color: var(--green); }

        .info-row > div:last-child { min-width: 0; flex: 1; }

        .info-row .lbl {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 1px;
        }

        .info-row .val {
            color: var(--navy);
            font-weight: 650;
            font-size: 12.5px;
            overflow-wrap: anywhere;
        }

        /* RIGHT SIDE — TABS */
        .tabs-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .tabs-nav {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .tabs-nav::-webkit-scrollbar { display: none; }

        .tab-btn {
            flex: 1;
            min-width: 130px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 16px 18px;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            transition: .15s ease;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .tab-btn:hover { color: var(--navy); }

        .tab-btn.active {
            color: var(--navy);
            border-bottom-color: var(--gold);
            background: var(--white);
        }

        .tab-btn i { font-size: 13px; }
        .tab-btn.active i { color: var(--gold); }

        .tab-pane {
            display: none;
            padding: 26px;
        }

        .tab-pane.active { display: block; }

        /* FORM */
        .form-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--navy);
            font-size: 12px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border);
        }

        .form-section-title::before {
            content: "";
            width: 3px;
            height: 14px;
            background: var(--gold);
            border-radius: 2px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 22px;
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
            color: var(--text);
            outline: none;
            transition: .15s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 90px;
            padding: 12px;
            resize: vertical;
            line-height: 1.55;
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

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 0 22px;
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
            color: var(--muted);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { color: var(--navy); border-color: var(--navy); }

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

        /* PASSWORD STRENGTH */
        .pwd-strength {
            margin-top: 8px;
            height: 5px;
            background: #eef0f5;
            border-radius: 5px;
            overflow: hidden;
        }

        .pwd-strength-bar {
            height: 100%;
            width: 0%;
            background: var(--red);
            border-radius: 5px;
            transition: width .2s ease, background .2s ease;
        }

        .pwd-strength-label {
            margin-top: 6px;
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
        }

        .info-note {
            background: #f4f8fc;
            border: 1px solid #d5e4f0;
            border-left: 3px solid var(--blue);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 12px;
            color: var(--blue);
            line-height: 1.55;
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }

        .info-note i { margin-top: 2px; font-size: 14px; flex-shrink: 0; }

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

        .toast i { color: var(--green); font-size: 14px; }
        .toast.error { border-left-color: var(--red); }
        .toast.error i { color: var(--red); }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }

        /* =========================================================
           RESPONSIVE — TABLET
        ========================================================= */
        @media (max-width: 1000px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
            .profile-card {
                position: static;
            }
        }

        /* =========================================================
           RESPONSIVE — MOBILE
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
            .page-title p  { font-size: 12px; line-height: 1.45; }

            /* Profile card — more compact on mobile */
            .profile-cover { height: 72px; }
            .profile-avatar-wrap { margin-top: -42px; }

            .profile-avatar {
                width: 84px;
                height: 84px;
                font-size: 30px;
                border-width: 3px;
            }

            .avatar-upload-btn {
                width: 30px;
                height: 30px;
                font-size: 11px;
                border-width: 2px;
            }

            .profile-info {
                padding: 12px 16px 16px;
            }

            .profile-name { font-size: 16px; }
            .profile-email { font-size: 11.5px; margin-bottom: 12px; }

            .profile-info-rows {
                padding-top: 12px;
                margin-top: 0;
            }

            .info-row {
                padding: 8px 0;
            }

            /* Tabs — scrollable pill row on mobile */
            .tabs-nav {
                padding: 8px 12px;
                gap: 8px;
                background: #fcfcfa;
            }

            .tab-btn {
                flex: 0 0 auto;
                min-width: auto;
                padding: 10px 16px;
                font-size: 12.5px;
                border-radius: 30px;
                border: 1px solid var(--border);
                background: var(--white);
                border-bottom: 1px solid var(--border);
            }

            .tab-btn.active {
                background: var(--navy);
                color: var(--white);
                border-color: var(--navy);
                border-bottom-color: var(--navy);
            }

            .tab-btn.active i { color: var(--gold-light); }

            .tab-pane {
                padding: 20px;
            }

            /* Form — one column on mobile */
            .form-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .form-control {
                height: 46px;
                font-size: 14px;
            }

            textarea.form-control {
                min-height: 100px;
                font-size: 14px;
            }

            /* Buttons — full width stacked */
            .form-actions {
                flex-direction: column;
                padding-top: 16px;
            }

            .form-actions .btn {
                width: 100%;
                min-height: 48px;
                font-size: 13.5px;
            }
        }

        /* =========================================================
           RESPONSIVE — SMALL MOBILE
        ========================================================= */
        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .profile-cover { height: 64px; }
            .profile-avatar-wrap { margin-top: -38px; }

            .profile-avatar {
                width: 76px;
                height: 76px;
                font-size: 27px;
            }

            .avatar-upload-btn {
                width: 28px;
                height: 28px;
                font-size: 10px;
            }

            .profile-info { padding: 10px 14px 14px; }
            .profile-name { font-size: 15px; }
            .profile-email { font-size: 11px; }

            .info-row .ico {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }

            .info-row .lbl { font-size: 9.5px; }
            .info-row .val { font-size: 12px; }

            .tab-pane { padding: 16px; }

            .form-section-title { font-size: 11px; }

            .form-group label { font-size: 10.5px; }
            .form-control { height: 44px; font-size: 13.5px; }

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
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'My Profile';
$topbar_subtitle = 'Account Settings';
include '../includes/topbar.php';
?>

<?php include 'parent_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-user-gear"></i> My Profile</h1>
            <p>Manage your personal information, password, and profile photo.</p>
        </div>
    </div>

    <div class="profile-layout">

        <!-- =========================================================
             LEFT: PROFILE CARD
        ========================================================= -->
        <aside class="profile-card">

            <div class="profile-cover"></div>

            <div class="profile-avatar-wrap">
                <div class="profile-avatar" id="avatarPreview">
                    <?php if (!empty($parent['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $parent['profile_pic'])): ?>
                        <img id="avatarImg"
                             src="../uploads/users/<?php echo e($parent['profile_pic']); ?>?v=<?php echo time(); ?>"
                             alt="<?php echo e($full_name); ?>">
                    <?php else: ?>
                        <span id="avatarInitial"><?php echo e($initial); ?></span>
                    <?php endif; ?>

                    <label for="photoInput" class="avatar-upload-btn" title="Change photo">
                        <i class="fa-solid fa-camera"></i>
                    </label>
                </div>
            </div>

            <input type="file" id="photoInput" accept="image/*" style="display:none;">

            <div class="profile-info">
                <div class="profile-name" id="profileName"><?php echo e($full_name); ?></div>
                <div class="profile-email" id="profileEmail"><?php echo e($parent['email']); ?></div>

                <?php $status_lower = strtolower($parent['status'] ?? 'active'); ?>
                <span class="profile-status-pill <?php echo $status_lower !== 'active' ? 'inactive' : ''; ?>">
                    <?php echo e(ucfirst($status_lower)); ?>
                </span>

                <div class="profile-info-rows">

                    <div class="info-row">
                        <div class="ico gold"><i class="fa-solid fa-id-badge"></i></div>
                        <div>
                            <div class="lbl">Parent ID</div>
                            <div class="val">#<?php echo (int)$parent['parent_id']; ?></div>
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="ico blue"><i class="fa-solid fa-children"></i></div>
                        <div>
                            <div class="lbl">Linked Children</div>
                            <div class="val">
                                <?php echo $child_count; ?>
                                <?php echo $child_count === 1 ? ' child' : ' children'; ?>
                            </div>
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="ico green"><i class="fa-solid fa-calendar-check"></i></div>
                        <div>
                            <div class="lbl">Member Since</div>
                            <div class="val"><?php echo e($member_since); ?></div>
                        </div>
                    </div>

                </div>
            </div>

        </aside>


        <!-- =========================================================
             RIGHT: TABS
        ========================================================= -->
        <div class="tabs-card">

            <div class="tabs-nav">
                <button type="button" class="tab-btn active" data-tab="personal">
                    <i class="fa-solid fa-user"></i> Personal Info
                </button>
                <button type="button" class="tab-btn" data-tab="password">
                    <i class="fa-solid fa-lock"></i> Password
                </button>
                <button type="button" class="tab-btn" data-tab="photo">
                    <i class="fa-solid fa-image"></i> Photo
                </button>
            </div>

            <!-- =========================================================
                 TAB 1: PERSONAL INFO
            ========================================================= -->
            <div class="tab-pane active" id="tab-personal">

                <div class="form-section-title">Personal Information</div>

                <form id="profileForm" autocomplete="off">

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="first_name">First Name <span class="req">*</span></label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                   value="<?php echo e($parent['first_name']); ?>" maxlength="100" required>
                            <div class="field-error" data-error-for="first_name"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                   value="<?php echo e($parent['middle_name']); ?>" maxlength="100">
                            <div class="field-error" data-error-for="middle_name"></div>
                        </div>

                        <div class="form-group">
                            <label for="last_name">Last Name <span class="req">*</span></label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                   value="<?php echo e($parent['last_name']); ?>" maxlength="100" required>
                            <div class="field-error" data-error-for="last_name"></div>
                        </div>

                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="">— Select —</option>
                                <option value="male"   <?php echo strtolower($parent['gender'] ?? '') === 'male'   ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo strtolower($parent['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other"  <?php echo strtolower($parent['gender'] ?? '') === 'other'  ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <div class="field-error" data-error-for="gender"></div>
                        </div>

                    </div>

                    <div class="form-section-title">Contact &amp; Account</div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="email">Email <span class="req">*</span></label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?php echo e($parent['email']); ?>" maxlength="150" required>
                            <div class="field-error" data-error-for="email"></div>
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone <span class="req">*</span></label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                   value="<?php echo e($parent['phone']); ?>" maxlength="30" required>
                            <div class="field-error" data-error-for="phone"></div>
                        </div>

                        <div class="form-group full">
                            <label for="occupation">Occupation</label>
                            <input type="text" id="occupation" name="occupation" class="form-control"
                                   value="<?php echo e($parent['occupation']); ?>" maxlength="150">
                            <div class="field-error" data-error-for="occupation"></div>
                        </div>

                        <div class="form-group full">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="3"><?php echo e($parent['address']); ?></textarea>
                            <div class="field-error" data-error-for="address"></div>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                            <span class="spinner"></span>
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span class="btn-label">Save Changes</span>
                        </button>
                        <button type="reset" class="btn btn-ghost">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </button>
                    </div>

                </form>

            </div>

            <!-- =========================================================
                 TAB 2: PASSWORD
            ========================================================= -->
            <div class="tab-pane" id="tab-password">

                <div class="info-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        For your security, choose a strong password at least 6 characters long.
                        You'll keep using this same password on your next login.
                    </div>
                </div>

                <div class="form-section-title">Change Password</div>

                <form id="passwordForm" autocomplete="off">

                    <div class="form-grid">

                        <div class="form-group full">
                            <label for="current_password">Current Password <span class="req">*</span></label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control" required>
                            <div class="field-error" data-error-for="current_password"></div>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password <span class="req">*</span></label>
                            <input type="password" id="new_password" name="new_password"
                                   class="form-control" minlength="6" required>
                            <div class="pwd-strength">
                                <div class="pwd-strength-bar" id="pwdStrengthBar"></div>
                            </div>
                            <div class="pwd-strength-label" id="pwdStrengthLabel">Enter a new password</div>
                            <div class="field-error" data-error-for="new_password"></div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password <span class="req">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   class="form-control" minlength="6" required>
                            <div class="field-error" data-error-for="confirm_password"></div>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="savePasswordBtn">
                            <span class="spinner"></span>
                            <i class="fa-solid fa-key"></i>
                            <span class="btn-label">Update Password</span>
                        </button>
                        <button type="reset" class="btn btn-ghost">
                            <i class="fa-solid fa-rotate-left"></i> Clear
                        </button>
                    </div>

                </form>

            </div>

            <!-- =========================================================
                 TAB 3: PHOTO
            ========================================================= -->
            <div class="tab-pane" id="tab-photo">

                <div class="info-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Upload a photo up to <strong>2 MB</strong>. Supported formats:
                        JPG, PNG, WEBP, GIF. The photo is used on your profile.
                    </div>
                </div>

                <div class="form-section-title">Profile Photo</div>

                <div style="text-align:center;padding:20px 0;">

                    <div style="
                        width:140px;height:140px;margin:0 auto 20px;border-radius:50%;
                        background:var(--navy);color:var(--gold-light);
                        display:flex;align-items:center;justify-content:center;
                        font-size:52px;font-weight:800;overflow:hidden;
                        border:3px dashed var(--border);position:relative;">
                        <div id="photoPreviewBig" style="
                            width:100%;height:100%;display:flex;align-items:center;
                            justify-content:center;overflow:hidden;">
                            <?php if (!empty($parent['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $parent['profile_pic'])): ?>
                                <img src="../uploads/users/<?php echo e($parent['profile_pic']); ?>?v=<?php echo time(); ?>"
                                     style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <?php echo e($initial); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                        <label for="photoInput2" class="btn btn-gold">
                            <i class="fa-solid fa-upload"></i> Choose Photo
                        </label>
                        <input type="file" id="photoInput2" accept="image/*" style="display:none;">
                    </div>

                    <p style="margin-top:16px;font-size:12px;color:var(--muted);line-height:1.55;">
                        Recommended: square image, at least 300×300 pixels.
                    </p>

                </div>

            </div>

        </div>

    </div>

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
   TABS
   ========================================================================= */
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const target = btn.dataset.tab;

        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));

        btn.classList.add('active');
        document.getElementById('tab-' + target).classList.add('active');
    });
});


/* =========================================================================
   FORM ERROR HELPERS
   ========================================================================= */
function clearErrors(form) {
    form.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));
    form.querySelectorAll('.field-error').forEach(el => {
        el.textContent = '';
        el.classList.remove('show');
    });
}

function showErrors(form, errors) {
    Object.entries(errors).forEach(([field, msg]) => {
        const input = form.querySelector('[name="' + field + '"]');
        if (input) input.classList.add('error');
        const err = form.querySelector('[data-error-for="' + field + '"]');
        if (err) {
            err.textContent = msg;
            err.classList.add('show');
        }
    });
}


/* =========================================================================
   SAVE PROFILE
   ========================================================================= */
const profileForm = document.getElementById('profileForm');
const saveProfileBtn = document.getElementById('saveProfileBtn');

profileForm.addEventListener('submit', async e => {
    e.preventDefault();
    clearErrors(profileForm);

    const fd = new FormData(profileForm);
    fd.set('ajax_action', 'update_profile');

    const original = saveProfileBtn.innerHTML;
    saveProfileBtn.disabled = true;
    saveProfileBtn.classList.add('loading');

    try {
        const res = await fetch('profile.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const raw = await res.text();
        let json;
        try {
            json = JSON.parse(raw);
        } catch (err) {
            console.error(raw);
            throw new Error('Server returned an unexpected response.');
        }

        if (!json.success) {
            if (json.errors) showErrors(profileForm, json.errors);
            showToast(json.message || 'Could not save.', 'error');
            return;
        }

        showToast(json.message, 'success');

        /* Update profile card live */
        document.getElementById('profileName').textContent  = json.full_name;
        document.getElementById('profileEmail').textContent = json.email;

        const avatarInitial = document.getElementById('avatarInitial');
        if (avatarInitial) avatarInitial.textContent = json.initial;

    } catch (err) {
        console.error(err);
        showToast(err.message || 'Network error. Please try again.', 'error');
    } finally {
        saveProfileBtn.disabled = false;
        saveProfileBtn.classList.remove('loading');
        saveProfileBtn.innerHTML = original;
    }
});


/* =========================================================================
   CHANGE PASSWORD
   ========================================================================= */
const passwordForm = document.getElementById('passwordForm');
const savePasswordBtn = document.getElementById('savePasswordBtn');
const newPwdInput = document.getElementById('new_password');
const pwdBar = document.getElementById('pwdStrengthBar');
const pwdLabel = document.getElementById('pwdStrengthLabel');

function scorePassword(pwd) {
    let score = 0;
    if (!pwd) return 0;
    if (pwd.length >= 6) score++;
    if (pwd.length >= 10) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    return Math.min(score, 5);
}

newPwdInput.addEventListener('input', () => {
    const s = scorePassword(newPwdInput.value);
    const pct = (s / 5) * 100;
    pwdBar.style.width = pct + '%';

    const colors = ['#9b4747', '#9b4747', '#c9a227', '#9a7422', '#3e7655', '#3e7655'];
    pwdBar.style.background = colors[s] || '#9b4747';

    const labels = [
        'Enter a new password',
        'Very weak',
        'Weak',
        'Fair',
        'Strong',
        'Very strong'
    ];
    pwdLabel.textContent = labels[s];
});

passwordForm.addEventListener('submit', async e => {
    e.preventDefault();
    clearErrors(passwordForm);

    const fd = new FormData(passwordForm);
    fd.set('ajax_action', 'change_password');

    const original = savePasswordBtn.innerHTML;
    savePasswordBtn.disabled = true;
    savePasswordBtn.classList.add('loading');

    try {
        const res = await fetch('profile.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const raw = await res.text();
        let json;
        try {
            json = JSON.parse(raw);
        } catch (err) {
            console.error(raw);
            throw new Error('Server returned an unexpected response.');
        }

        if (!json.success) {
            if (json.errors) showErrors(passwordForm, json.errors);
            showToast(json.message || 'Could not change password.', 'error');
            return;
        }

        showToast(json.message, 'success');
        passwordForm.reset();
        pwdBar.style.width = '0%';
        pwdLabel.textContent = 'Enter a new password';

    } catch (err) {
        console.error(err);
        showToast(err.message || 'Network error. Please try again.', 'error');
    } finally {
        savePasswordBtn.disabled = false;
        savePasswordBtn.classList.remove('loading');
        savePasswordBtn.innerHTML = original;
    }
});


/* =========================================================================
   PHOTO UPLOAD (both inputs → same handler)
   ========================================================================= */
function handlePhotoUpload(inputEl) {
    if (!inputEl.files || !inputEl.files[0]) return;

    const file = inputEl.files[0];

    if (file.size > 2 * 1024 * 1024) {
        showToast('File must be smaller than 2 MB.', 'error');
        inputEl.value = '';
        return;
    }

    const fd = new FormData();
    fd.set('ajax_action', 'upload_photo');
    fd.set('photo', file);

    /* Preview instantly */
    const reader = new FileReader();
    reader.onload = e => {
        const preview = document.getElementById('photoPreviewBig');
        if (preview) preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';

        const av = document.getElementById('avatarPreview');
        if (av) {
            const initial = document.getElementById('avatarInitial');
            if (initial) initial.remove();
            let img = document.getElementById('avatarImg');
            if (!img) {
                img = document.createElement('img');
                img.id = 'avatarImg';
                av.insertBefore(img, av.firstChild);
            }
            img.src = e.target.result;
        }
    };
    reader.readAsDataURL(file);

    /* Upload */
    fetch('profile.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(json => {
        if (!json.success) {
            showToast(json.message || 'Upload failed.', 'error');
            return;
        }
        showToast('Photo updated successfully.', 'success');
        /* Refresh the actual images from server (avoids stale cache) */
        if (json.photo_url) {
            const stamp = '?v=' + Date.now();
            const av = document.getElementById('avatarImg');
            if (av) av.src = json.photo_url + stamp;
            const prev = document.querySelector('#photoPreviewBig img');
            if (prev) prev.src = json.photo_url + stamp;
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error while uploading.', 'error');
    })
    .finally(() => {
        inputEl.value = '';
    });
}

document.getElementById('photoInput').addEventListener('change', function () {
    handlePhotoUpload(this);
});
document.getElementById('photoInput2').addEventListener('change', function () {
    handlePhotoUpload(this);
});


/* =========================================================================
   MOBILE SIDEBAR
   ========================================================================= */
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');
    const s = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (s) s.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}
function closeSidebar() {
    sidebarOverlay.classList.remove('open');
    const s = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (s) s.classList.remove('open');
    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
    document.body.classList.remove('no-scroll');
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', () => {
        if (sidebarOverlay.classList.contains('open')) closeSidebar();
        else openSidebar();
    });
}
if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });
</script>

</body>
</html>