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
    $_SESSION['gallery_flash'] = ['type' => $type, 'message' => $message];
    header('Location: gallery.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


/* =========================================================================
   CATEGORIES
   ========================================================================= */

$categories = [
    'general'    => 'General',
    'sports'     => 'Sports',
    'academic'   => 'Academic',
    'events'     => 'Events',
    'graduation' => 'Graduation',
    'trips'      => 'Trips',
    'cultural'   => 'Cultural',
    'other'      => 'Other',
];


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $title       = trim($_POST['title'] ?? '');
        $category    = $_POST['category'] ?? 'general';
        $description = trim($_POST['description'] ?? '');
        $taken_on    = trim($_POST['taken_on'] ?? '');

        $errors = [];

        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors[] = 'Title cannot exceed 255 characters.';
        }

        if (!isset($categories[$category])) {
            $category = 'general';
        }

        if ($taken_on !== '' && !DateTime::createFromFormat('Y-m-d', $taken_on)) {
            $errors[] = 'Invalid date.';
        }

        /* Image upload */
        $image_filename = null;

        if (empty($errors)) {

            if (empty($_FILES['image']['name'])) {
                $errors[] = 'Please choose an image.';
            } else {

                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $max     = 4 * 1024 * 1024; /* 4 MB */

                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed, true)) {
                    $errors[] = 'Image must be JPG, PNG, WEBP or GIF.';
                } elseif ($_FILES['image']['size'] > $max) {
                    $errors[] = 'Image must be smaller than 4MB.';
                } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Image upload failed. Please try again.';
                } else {

                    $info = @getimagesize($_FILES['image']['tmp_name']);
                    if ($info === false) {
                        $errors[] = 'The uploaded file is not a valid image.';
                    } else {

                        $dir = __DIR__ . '/../uploads/gallery/';
                        if (!is_dir($dir)) @mkdir($dir, 0755, true);

                        if (!is_writable($dir)) {
                            $errors[] = 'Upload directory is not writable.';
                        } else {

                            $image_filename = 'gal_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $target = $dir . $image_filename;

                            if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                                $errors[] = 'Could not save the uploaded image.';
                                $image_filename = null;
                            }
                        }
                    }
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['gallery_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: gallery.php');
            exit;
        }

        $taken_param = $taken_on !== '' ? $taken_on : null;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO gallery (title, category, image, description, taken_on)
             VALUES (?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'sssss',
            $title,
            $category,
            $image_filename,
            $description,
            $taken_param
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Image added to gallery successfully.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        if ($image_filename) {
            @unlink(__DIR__ . '/../uploads/gallery/' . $image_filename);
        }

        redirect_with_flash('error', 'Could not add image: ' . $err);
    }

    /* -------------------------------------------------------------
       UPDATE
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $id          = (int) ($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $category    = $_POST['category'] ?? 'general';
        $description = trim($_POST['description'] ?? '');
        $taken_on    = trim($_POST['taken_on'] ?? '');

        $errors = [];

        if ($id <= 0) $errors[] = 'Invalid entry.';

        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors[] = 'Title cannot exceed 255 characters.';
        }

        if (!isset($categories[$category])) {
            $category = 'general';
        }

        if ($taken_on !== '' && !DateTime::createFromFormat('Y-m-d', $taken_on)) {
            $errors[] = 'Invalid date.';
        }

        /* Fetch existing image */
        $existing = null;
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "SELECT image FROM gallery WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $existing = $row ? $row['image'] : null;
        }

        /* Optional image replacement */
        $image_filename = $existing;

        if (empty($errors) && !empty($_FILES['image']['name'])) {

            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $max     = 4 * 1024 * 1024;

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Image must be JPG, PNG, WEBP or GIF.';
            } elseif ($_FILES['image']['size'] > $max) {
                $errors[] = 'Image must be smaller than 4MB.';
            } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Image upload failed.';
            } else {

                $info = @getimagesize($_FILES['image']['tmp_name']);
                if ($info === false) {
                    $errors[] = 'The uploaded file is not a valid image.';
                } else {

                    $dir = __DIR__ . '/../uploads/gallery/';
                    if (!is_dir($dir)) @mkdir($dir, 0755, true);

                    $new_image = 'gal_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $target = $dir . $new_image;

                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                        $image_filename = $new_image;

                        /* Delete old image */
                        if ($existing && file_exists($dir . $existing)) {
                            @unlink($dir . $existing);
                        }
                    } else {
                        $errors[] = 'Could not save the new image.';
                    }
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['gallery_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: gallery.php');
            exit;
        }

        $taken_param = $taken_on !== '' ? $taken_on : null;

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE gallery
             SET title = ?, category = ?, image = ?, description = ?, taken_on = ?
             WHERE id = ?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'sssssi',
            $title,
            $category,
            $image_filename,
            $description,
            $taken_param,
            $id
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Entry updated.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not update: ' . $err);
    }

    /* -------------------------------------------------------------
       DELETE
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) redirect_with_flash('error', 'Invalid entry.');

        /* Fetch image filename first so we can remove the file */
        $stmt = mysqli_prepare($conn, "SELECT image FROM gallery WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) redirect_with_flash('error', 'Entry not found.');

        $stmt = mysqli_prepare($conn, "DELETE FROM gallery WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($row['image']) {
            @unlink(__DIR__ . '/../uploads/gallery/' . $row['image']);
        }

        redirect_with_flash('success', 'Entry deleted.');
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */

$flash = $_SESSION['gallery_flash'] ?? null;
unset($_SESSION['gallery_flash']);


/* =========================================================================
   FILTERS
   ========================================================================= */

$search   = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(title LIKE ? OR description LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

if ($category !== '' && isset($categories[$category])) {
    $where[]  = "category = ?";
    $params[] = $category;
    $types   .= 's';
}

$sql = "SELECT id, title, category, image, description, taken_on, created_at FROM gallery";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY COALESCE(taken_on, DATE(created_at)) DESC, id DESC";

$gallery = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $gallery[] = $row;
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   STATS
   ========================================================================= */

$total = 0;
$per_category = [];

$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM gallery");
if ($res && $row = mysqli_fetch_assoc($res)) {
    $total = (int) $row['c'];
}

$res = mysqli_query($conn, "SELECT category, COUNT(*) AS c FROM gallery GROUP BY category");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $per_category[$row['category']] = (int) $row['c'];
    }
}

$has_filters = ($search !== '' || ($category !== '' && isset($categories[$category])));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Gallery | PSRMS</title>

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

        /* LAYOUT */
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

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
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

        .btn-danger {
            background: var(--red-bg);
            color: var(--red);
            border: 1px solid #efd2d2;
        }
        .btn-danger:hover { background: #f6dcdc; border-color: var(--red); }

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

        .filter-panel.collapsed .filter-toggle .chev {
            transform: rotate(-90deg);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
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

        /* RESULTS */
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
           GALLERY GRID
        ========================================================= */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        .gallery-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            transition: .2s ease;
            display: flex;
            flex-direction: column;
        }

        .gallery-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(16,24,43,.10);
            border-color: rgba(201,162,39,.4);
        }

        .gallery-card-image {
            position: relative;
            width: 100%;
            aspect-ratio: 4/3;
            background: #f3f4f6;
            overflow: hidden;
        }

        .gallery-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: .3s ease;
        }

        .gallery-card:hover .gallery-card-image img {
            transform: scale(1.04);
        }

        .gallery-category-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            padding: 4px 10px;
            border-radius: 20px;
            background: rgba(23,35,60,.85);
            color: var(--gold-light);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .5px;
            backdrop-filter: blur(4px);
        }

        .gallery-card-body {
            padding: 14px 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .gallery-card-title {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 6px;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .gallery-card-desc {
            color: var(--muted);
            font-size: 11.5px;
            line-height: 1.5;
            flex: 1;
            margin-bottom: 12px;
            overflow-wrap: anywhere;
        }

        .gallery-card-date {
            color: var(--muted);
            font-size: 10.5px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .gallery-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .gallery-card-actions .icon-btn { width: 100%; }

        /* ICON BUTTON */
        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 34px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        .icon-btn.danger { color: var(--red); border-color: #efd2d2; }
        .icon-btn.danger:hover { background: var(--red-bg); border-color: var(--red); }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
            font-size: 12.5px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
        }

        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        /* MODAL */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16,24,43,.55);
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
            max-width: 560px;
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
        .form-group { margin-bottom: 16px; }
        .form-group.full { grid-column: 1 / -1; }

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
            -webkit-appearance: none;
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 90px;
            padding: 12px;
            resize: vertical;
            line-height: 1.5;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        /* IMAGE UPLOAD */
        .image-upload {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            border: 2px dashed var(--border);
            border-radius: 10px;
            background: #fcfcfd;
            transition: .2s ease;
            cursor: pointer;
        }

        .image-upload:hover,
        .image-upload.dragover {
            border-color: var(--gold);
            background: #fdfcf8;
        }

        .image-preview {
            width: 72px;
            height: 72px;
            border-radius: 8px;
            flex-shrink: 0;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-text { flex: 1; min-width: 0; }
        .image-text strong { color: var(--navy); font-size: 12.5px; font-weight: 700; display: block; }
        .image-text span   { color: var(--muted); font-size: 11px; margin-top: 3px; display: block; }

        .image-input { display: none; }

        .image-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--navy);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            flex-shrink: 0;
        }

        .image-btn:hover { border-color: var(--gold); color: var(--gold); }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
            .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
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
            .btn { min-height: 46px; font-size: 14px; }

            .gallery-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .gallery-card-body { padding: 12px; }
            .gallery-card-title { font-size: 12.5px; }
            .gallery-card-desc  { font-size: 11px; }

            /* MODAL — bottom sheet */
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

            .form-grid { grid-template-columns: 1fr; gap: 12px; }
            .image-upload { flex-direction: column; text-align: center; }
            .image-btn { width: 100%; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 19px; }

            .gallery-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .gallery-card-body { padding: 10px; }
            .gallery-card-title { font-size: 12px; }
            .gallery-card-date  { font-size: 10px; }

            .gallery-card-actions { grid-template-columns: 1fr; gap: 5px; }
            .gallery-card-actions .icon-btn { min-height: 36px; font-size: 11px; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .gallery-grid { grid-template-columns: 1fr; }
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
$topbar_title    = 'Gallery';
$topbar_subtitle = 'School photo gallery';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Gallery</h1>
            <p>Manage the school photo gallery.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            + Add Image
        </button>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Images</div>
            <div class="value"><?php echo number_format($total); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Sports</div>
            <div class="value"><?php echo number_format($per_category['sports'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Events</div>
            <div class="value"><?php echo number_format($per_category['events'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Academic</div>
            <div class="value"><?php echo number_format($per_category['academic'] ?? 0); ?></div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="gallery.php" class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="Title or description…"
                       value="<?php echo e($search); ?>">
            </div>

            <div class="filter-group">
                <label>Category</label>
                <select name="category" class="filter-control">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?php echo e($key); ?>"
                            <?php echo $category === $key ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="gallery.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>

        </form>
    </section>

    <!-- RESULTS -->
    <div class="results-bar">
        <h2>Gallery Images</h2>
        <span class="results-count">
            <?php echo number_format(count($gallery)); ?> image(s)
        </span>
    </div>

    <!-- GRID -->
    <?php if (empty($gallery)): ?>

        <div class="empty">
            <div class="empty-icon">G</div>
            <h3>No images found</h3>
            <p>
                <?php if ($has_filters): ?>
                    Try clearing the filters.
                <?php else: ?>
                    Click <strong>Add Image</strong> to upload your first photo.
                <?php endif; ?>
            </p>
        </div>

    <?php else: ?>

        <div class="gallery-grid">
            <?php foreach ($gallery as $g):
                $img_url = '../uploads/gallery/' . e($g['image']);
                $cat_label = $categories[$g['category']] ?? ucfirst($g['category']);
                $date = $g['taken_on'] ?: $g['created_at'];
            ?>
                <div class="gallery-card">

                    <div class="gallery-card-image">
                        <img src="<?php echo $img_url; ?>"
                             alt="<?php echo e($g['title']); ?>"
                             loading="lazy"
                             onerror="this.style.display='none';this.parentElement.innerHTML+='<div style=&quot;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:11px;&quot;>Image not found</div>';">

                        <span class="gallery-category-badge">
                            <?php echo e($cat_label); ?>
                        </span>
                    </div>

                    <div class="gallery-card-body">

                        <div class="gallery-card-title"><?php echo e($g['title']); ?></div>

                        <?php if (!empty($g['description'])): ?>
                            <div class="gallery-card-desc">
                                <?php
                                $desc = $g['description'];
                                echo e(mb_strlen($desc) > 100 ? mb_substr($desc, 0, 100) . '…' : $desc);
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($date): ?>
                            <div class="gallery-card-date">
                                📅 <?php echo e(date('d M Y', strtotime($date))); ?>
                            </div>
                        <?php endif; ?>

                        <div class="gallery-card-actions">

                            <button type="button" class="icon-btn"
                                onclick='openEditModal(<?php echo json_encode([
                                    "id"          => (int)$g["id"],
                                    "title"       => $g["title"],
                                    "category"    => $g["category"],
                                    "description" => $g["description"],
                                    "taken_on"    => $g["taken_on"],
                                    "image"       => $g["image"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Edit
                            </button>

                            <form method="POST" style="display:contents;"
                                  onsubmit="return confirm('Delete this image permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                                <button type="submit" class="icon-btn danger">Delete</button>
                            </form>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</main>


<!-- =========================================================
     CREATE MODAL
========================================================= -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST" action="gallery.php" enctype="multipart/form-data" autocomplete="off">

            <div class="modal-header">
                <h2>Add Image</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="form-group">
                    <label>Image <span class="req">*</span></label>

                    <div class="image-upload" id="createImageUpload">
                        <div class="image-preview" id="createImagePreview">
                            <span>+</span>
                        </div>
                        <div class="image-text">
                            <strong>Choose an image</strong>
                            <span>JPG, PNG, WEBP or GIF — max 4MB</span>
                        </div>
                        <button type="button" class="image-btn"
                            onclick="document.getElementById('createImageInput').click()">
                            Choose
                        </button>
                        <input type="file" id="createImageInput" name="image"
                               class="image-input" accept="image/*" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-control"
                           maxlength="255" placeholder="e.g. Prize Giving Day 2025" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" class="form-control">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo e($key); ?>">
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date Taken</label>
                        <input type="date" name="taken_on" class="form-control"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control"
                              placeholder="Optional description…"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Upload Image
                </button>
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
        <form method="POST" action="gallery.php" enctype="multipart/form-data" autocomplete="off">

            <div class="modal-header">
                <h2>Edit Image</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="form-group">
                    <label>Current Image</label>

                    <div class="image-upload" id="editImageUpload">
                        <div class="image-preview" id="editImagePreview"></div>
                        <div class="image-text">
                            <strong>Replace image</strong>
                            <span>Leave empty to keep current image</span>
                        </div>
                        <button type="button" class="image-btn"
                            onclick="document.getElementById('editImageInput').click()">
                            Change
                        </button>
                        <input type="file" id="editImageInput" name="image"
                               class="image-input" accept="image/*">
                    </div>
                </div>

                <div class="form-group">
                    <label>Title <span class="req">*</span></label>
                    <input type="text" name="title" id="edit_title"
                           class="form-control" maxlength="255" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="edit_category" class="form-control">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo e($key); ?>">
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date Taken</label>
                        <input type="date" name="taken_on" id="edit_taken_on"
                               class="form-control" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description"
                              class="form-control" placeholder="Optional description…"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Save Changes
                </button>
            </div>

            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
        </form>
    </div>
</div>


<script>
/* =========================================================
   MODAL HELPERS
========================================================= */
function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openEditModal(data) {
    document.getElementById('edit_id').value          = data.id          || '';
    document.getElementById('edit_title').value       = data.title       || '';
    document.getElementById('edit_category').value    = data.category    || 'general';
    document.getElementById('edit_taken_on').value    = data.taken_on    || '';
    document.getElementById('edit_description').value = data.description || '';

    const preview = document.getElementById('editImagePreview');
    if (data.image) {
        preview.innerHTML = '<img src="../uploads/gallery/' +
            data.image.replace(/"/g, '&quot;') + '" alt="">';
    } else {
        preview.innerHTML = '';
    }

    /* Reset file input */
    document.getElementById('editImageInput').value = '';

    document.getElementById('editModal').classList.add('open');
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
   IMAGE PREVIEW
========================================================= */
function bindImagePreview(inputId, uploadId, previewId) {
    const input   = document.getElementById(inputId);
    const upload  = document.getElementById(uploadId);
    const preview = document.getElementById(previewId);

    if (!input || !upload || !preview) return;

    input.addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            alert('Please choose an image.');
            input.value = '';
            return;
        }
        if (file.size > 4 * 1024 * 1024) {
            alert('Image must be smaller than 4MB.');
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = ev => {
            preview.innerHTML = '<img src="' + ev.target.result + '" alt="">';
        };
        reader.readAsDataURL(file);
    });

    upload.addEventListener('click', e => {
        if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT') {
            input.click();
        }
    });

    ['dragenter', 'dragover'].forEach(evt => {
        upload.addEventListener(evt, e => {
            e.preventDefault();
            upload.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        upload.addEventListener(evt, e => {
            e.preventDefault();
            upload.classList.remove('dragover');
        });
    });
    upload.addEventListener('drop', e => {
        const file = e.dataTransfer.files[0];
        if (file) {
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change'));
        }
    });
}

bindImagePreview('createImageInput', 'createImageUpload', 'createImagePreview');
bindImagePreview('editImageInput',   'editImageUpload',   'editImagePreview');


/* =========================================================
   FILTER PANEL COLLAPSE
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