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
    $_SESSION['student_flash'] = ['type' => $type, 'message' => $message];
    header('Location: students.php');
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

/**
 * Generate the next admission number for a given class level + year.
 * Format: {YEAR}/{LEVEL}/{SEQ}  e.g. 2025/1/001
 */
function generate_admission_no(mysqli $conn, int $class_level, int $academic_year): ?string
{
    if ($class_level <= 0 || $academic_year <= 0) return null;

    $prefix = $academic_year . '/' . $class_level . '/';
    $like   = $prefix . '%';

    $stmt = mysqli_prepare(
        $conn,
        "SELECT admission_no
         FROM students
         WHERE admission_no LIKE ?
         ORDER BY admission_no DESC
         LIMIT 1"
    );
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $next_seq = 1;
    if ($row = mysqli_fetch_assoc($res)) {
        $parts = explode('/', $row['admission_no']);
        $last  = (int) end($parts);
        $next_seq = $last + 1;
    }
    mysqli_stmt_close($stmt);

    return $prefix . str_pad((string)$next_seq, 3, '0', STR_PAD_LEFT);
}


/* =========================================================================
   ACTIVE ACADEMIC YEAR
   ========================================================================= */

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

$active_year_n = $active_year ? (int) $active_year['year'] : 0;


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
        $row['label'] = $row['class_name']
            . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $classes[] = $row;
    }
}

$class_levels_js = [];
foreach ($classes as $c) {
    $class_levels_js[(int)$c['class_id']] = (int) $c['class_level'];
}


/* =========================================================================
   LOAD EXISTING PARENTS (for the linking dropdown)
   ========================================================================= */

$parents = [];
$has_parents_table = tableExists($conn, 'parents');
$has_links_table   = tableExists($conn, 'student_parents');

if ($has_parents_table) {
    $res = mysqli_query(
        $conn,
        "SELECT
            p.parent_id,
            p.occupation,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.phone,
            u.email
         FROM parents p
         INNER JOIN users u ON u.user_id = p.user_id
         WHERE u.status = 'active'
         ORDER BY u.first_name ASC, u.last_name ASC"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $row['full_name'] = trim(
                $row['first_name'] . ' ' .
                ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
                $row['last_name']
            );
            $parents[] = $row;
        }
    }
}


/* =========================================================================
   HANDLE POST
   ========================================================================= */

$errors = [];

$full_name      = '';
$gender         = '';
$date_of_birth  = '';
$class_id       = '';
$admission_date = date('Y-m-d');
$status         = 'active';

/* Parents submitted with the form */
$link_parent_ids      = [];
$link_relationships   = [];
$link_primary_flags   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name      = trim($_POST['full_name'] ?? '');
    $gender         = $_POST['gender'] ?? '';
    $date_of_birth  = trim($_POST['date_of_birth'] ?? '');
    $class_id       = $_POST['class_id'] ?? '';
    $admission_date = trim($_POST['admission_date'] ?? '');
    $status         = $_POST['status'] ?? 'active';

    $link_parent_ids    = $_POST['link_parent_id']    ?? [];
    $link_relationships = $_POST['link_relationship'] ?? [];
    $link_primary_flags = $_POST['link_primary']      ?? [];

    /* ---------- Validation ---------- */

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    } elseif (strlen($full_name) < 2) {
        $errors[] = 'Full name must be at least 2 characters.';
    } elseif (strlen($full_name) > 150) {
        $errors[] = 'Full name cannot exceed 150 characters.';
    } elseif (!preg_match("/^[a-zA-Z\s\-'.]+$/", $full_name)) {
        $errors[] = 'Full name may only contain letters, spaces, hyphens and apostrophes.';
    }

    if (!in_array($gender, ['Male', 'Female'], true)) {
        $errors[] = 'Please select a valid gender.';
    }

    if ($date_of_birth !== '' && !DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $errors[] = 'Date of birth is not valid.';
    }

    if ($admission_date !== '' && !DateTime::createFromFormat('Y-m-d', $admission_date)) {
        $errors[] = 'Admission date is not valid.';
    }

    if ($class_id === '' || !ctype_digit((string)$class_id)) {
        $errors[] = 'Please select a class.';
    }

    if (!in_array($status, ['active', 'inactive', 'graduated', 'transferred'], true)) {
        $status = 'active';
    }

    if (!$active_year) {
        $errors[] = 'There is no active academic year. Please activate one first.';
    }

    /* ---------- Confirm class exists + fetch class_level ---------- */
    $class_level = 0;

    if (empty($errors) && $class_id !== '') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT class_id, class_level FROM classes WHERE class_id = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $class_id);
        mysqli_stmt_execute($stmt);
        $crow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$crow) {
            $errors[] = 'Selected class does not exist.';
        } else {
            $class_level = (int) $crow['class_level'];
            if ($class_level <= 0) {
                $errors[] = 'The selected class does not have a class level set. Please edit the class.';
            }
        }
    }

    /* ---------- Validate parent links ---------- */

    $clean_links = [];

    if ($has_links_table && !empty($link_parent_ids)) {

        $seen = [];

        foreach ($link_parent_ids as $i => $pid) {

            $pid = (int) $pid;
            if ($pid <= 0) continue;

            if (isset($seen[$pid])) {
                $errors[] = 'The same parent was selected more than once.';
                continue;
            }
            $seen[$pid] = true;

            $rel = $link_relationships[$i] ?? 'Guardian';
            if (!in_array($rel, ['Father', 'Mother', 'Guardian', 'Other'], true)) {
                $rel = 'Guardian';
            }

            $is_primary = isset($link_primary_flags[$i]) && (int)$link_primary_flags[$i] === 1 ? 1 : 0;

            $clean_links[] = [
                'parent_id'    => $pid,
                'relationship' => $rel,
                'is_primary'   => $is_primary,
            ];
        }

        /* Only one primary guardian allowed */
        $primary_count = 0;
        foreach ($clean_links as $l) {
            if ($l['is_primary']) $primary_count++;
        }
        if ($primary_count > 1) {
            $errors[] = 'Only one parent can be marked as the primary guardian.';
        }
    }

    /* ---------- Photo upload ---------- */
    $photo_filename = null;

    if (empty($errors) && !empty($_FILES['photo']['name'])) {

        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        $max_size    = 2 * 1024 * 1024;

        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true)) {
            $errors[] = 'Photo must be a JPG, PNG, or WEBP file.';
        } elseif ($_FILES['photo']['size'] > $max_size) {
            $errors[] = 'Photo must be smaller than 2MB.';
        } elseif ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed. Please try again.';
        } else {

            $info = @getimagesize($_FILES['photo']['tmp_name']);
            if ($info === false) {
                $errors[] = 'The uploaded file is not a valid image.';
            } else {

                $upload_dir = __DIR__ . '/../uploads/students/';

                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                if (!is_writable($upload_dir)) {
                    $errors[] = 'Upload directory is not writable.';
                } else {

                    $photo_filename = 'stu_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $target = $upload_dir . $photo_filename;

                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                        $errors[] = 'Could not save the uploaded photo.';
                        $photo_filename = null;
                    }
                }
            }
        }
    }

    /* ---------- Insert ---------- */

    if (empty($errors)) {

        $dob_param  = $date_of_birth  !== '' ? $date_of_birth  : null;
        $date_param = $admission_date !== '' ? $admission_date : null;

        $inserted     = false;
        $admission_no = '';
        $new_student_id = 0;
        $attempts     = 0;

        while (!$inserted && $attempts < 5) {

            $attempts++;

            $candidate = generate_admission_no($conn, $class_level, $active_year_n);
            if (!$candidate) {
                $errors[] = 'Could not generate an admission number.';
                break;
            }

            mysqli_begin_transaction($conn);

            try {

                /* Student */
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO students
                        (admission_no, full_name, gender, date_of_birth,
                         class_id, admission_date, photo, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssssisss',
                    $candidate,
                    $full_name,
                    $gender,
                    $dob_param,
                    $class_id,
                    $date_param,
                    $photo_filename,
                    $status
                );

                if (!mysqli_stmt_execute($stmt)) {
                    $errno = mysqli_stmt_errno($stmt);
                    $err   = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);

                    /* 1062 = duplicate admission_no → retry */
                    if ($errno === 1062) {
                        mysqli_rollback($conn);
                        continue;
                    }

                    throw new Exception($err);
                }

                $new_student_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                /* Parent links */
                if ($has_links_table && !empty($clean_links)) {
                    $link_stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO student_parents
                            (parent_id, student_id, relationship, is_primary_guardian)
                         VALUES (?, ?, ?, ?)"
                    );
                    foreach ($clean_links as $l) {
                        mysqli_stmt_bind_param(
                            $link_stmt,
                            'iisi',
                            $l['parent_id'],
                            $new_student_id,
                            $l['relationship'],
                            $l['is_primary']
                        );
                        mysqli_stmt_execute($link_stmt);
                    }
                    mysqli_stmt_close($link_stmt);
                }

                mysqli_commit($conn);
                $inserted     = true;
                $admission_no = $candidate;

            } catch (Exception $ex) {
                mysqli_rollback($conn);
                $errors[] = 'Could not save student: ' . $ex->getMessage();
                break;
            }
        }

        if ($inserted) {
            redirect_with_flash(
                'success',
                "Student \"$full_name\" added successfully. Admission No.: $admission_no"
            );
        }

        if (empty($errors) && !$inserted) {
            $errors[] = 'Could not generate a unique admission number after several attempts.';
        }

        if (!$inserted && $photo_filename) {
            @unlink(__DIR__ . '/../uploads/students/' . $photo_filename);
        }
    }
}

$has_errors = !empty($errors);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Add Student | PSRMS</title>

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
        .alert.error { background: var(--red-bg); border: 1px solid #efd2d2; color: var(--red); }
        .alert.error ul { padding-left: 18px; margin-top: 4px; }
        .alert.error li { margin-bottom: 3px; }

        /* FORM CARD */
        .form-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            max-width: 1000px;
        }

        .form-section {
            padding: 22px;
            border-bottom: 1px solid var(--border);
        }

        .form-section:last-of-type { border-bottom: none; }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--navy);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 16px;
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
            gap: 16px 18px;
        }

        .form-group { min-width: 0; }
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
            transition: .2s ease;
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
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .help-text { color: var(--muted); font-size: 10.5px; margin-top: 5px; }

        /* ADMISSION PREVIEW */
        .admission-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 44px;
            padding: 0 14px;
            background: var(--navy);
            color: var(--gold-light);
            border-radius: 8px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 1px;
            transition: .2s ease;
            overflow: hidden;
        }

        .admission-preview.pending {
            background: #f3f4f6;
            color: var(--muted);
            letter-spacing: 0;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 600;
        }

        .admission-preview.no-year {
            background: var(--red-bg);
            color: var(--red);
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0;
            flex-wrap: wrap;
            padding: 10px 14px;
        }

        .admission-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--gold);
            flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(201,162,39,.22);
        }

        /* PARENT LINKS */
        .link-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 12px;
        }

        .link-row {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
            gap: 8px;
            align-items: end;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfcfd;
        }

        .link-row .form-group { margin: 0; }
        .link-row .form-group label { font-size: 10.5px; }
        .link-row .form-control { height: 42px; font-size: 13px; }

        .primary-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            cursor: pointer;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--muted);
            user-select: none;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .primary-toggle input { display: none; }

        .primary-toggle .dot {
            width: 14px;
            height: 14px;
            border: 2px solid #cfd4dc;
            border-radius: 50%;
            transition: .15s ease;
        }

        .primary-toggle.on {
            color: var(--orange);
            border-color: var(--gold);
            background: #faf5e8;
        }
        .primary-toggle.on .dot {
            border-color: var(--gold);
            background: radial-gradient(circle, var(--gold) 40%, transparent 45%);
        }

        .link-remove {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--red);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            -webkit-tap-highlight-color: transparent;
        }
        .link-remove:hover { background: var(--red-bg); border-color: var(--red); }

        .add-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            background: var(--white);
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--navy);
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            font-weight: 650;
            -webkit-tap-highlight-color: transparent;
        }
        .add-link-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
            background: #fdfcf8;
        }

        .no-links-hint {
            color: var(--muted);
            font-size: 12px;
            padding: 6px 0 10px;
            font-style: italic;
        }

        /* PHOTO */
        .photo-upload {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px;
            border: 2px dashed var(--border);
            border-radius: 10px;
            background: #fcfcfd;
            transition: .2s ease;
            cursor: pointer;
        }
        .photo-upload:hover,
        .photo-upload.dragover {
            border-color: var(--gold);
            background: #fdfcf8;
        }

        .photo-preview {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            overflow: hidden;
            border: 2px solid var(--border);
        }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; }

        .photo-text { flex: 1; min-width: 0; }
        .photo-text strong { color: var(--navy); font-size: 12.5px; font-weight: 700; display: block; }
        .photo-text span   { color: var(--muted); font-size: 11px; margin-top: 3px; display: block; }

        .photo-input { display: none; }

        .photo-btn {
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
        .photo-btn:hover { border-color: var(--gold); color: var(--gold); }

        /* FOOTER */
        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 18px 22px;
            background: #fafaf8;
            border-top: 1px solid var(--border);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 44px;
            padding: 0 22px;
            border: none;
            border-radius: 9px;
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
        .btn-primary[disabled] { opacity: .55; cursor: not-allowed; }

        .btn-ghost {
            background: var(--white);
            color: var(--muted);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { color: var(--navy); border-color: #c8ccd3; }

        /* RESPONSIVE */
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

            .back-button {
                width: 100%;
                justify-content: center;
                min-height: 46px;
                font-size: 13px;
            }

            .form-section { padding: 18px; }
            .form-grid { grid-template-columns: 1fr; gap: 14px; }
            .form-group.full { grid-column: auto; }
            .form-control { font-size: 14px; height: 46px; }
            .photo-upload { padding: 12px; }
            .photo-preview { width: 60px; height: 60px; font-size: 18px; }
            .photo-text strong { font-size: 12px; }
            .photo-text span   { font-size: 10.5px; }

            .link-row {
                grid-template-columns: 1fr;
                padding: 12px;
                gap: 10px;
            }
            .link-row .link-remove {
                width: 100%;
                height: 42px;
            }

            .form-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
            }
            .form-footer .btn { width: 100%; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .form-section { padding: 16px; }
            .form-section-title { font-size: 11px; }

            .photo-upload { flex-direction: column; text-align: center; }
            .photo-btn { width: 100%; min-height: 42px; }

            .admission-preview {
                font-size: 13px;
                padding: 0 12px;
                letter-spacing: .5px;
            }
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
$topbar_title    = 'Add Student';
$topbar_subtitle = 'Register a new student';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Add Student</h1>
            <p>Register a new student and link their parent(s) or guardian(s).</p>
        </div>

        <a href="students.php" class="back-button">← Back to Students</a>
    </div>

    <!-- ERRORS -->
    <?php if ($has_errors): ?>
        <div class="alert error">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?php echo e($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- FORM -->
    <form method="POST" action="add_student.php"
          class="form-card" enctype="multipart/form-data" autocomplete="off">

        <!-- SECTION 1 — BASIC -->
        <div class="form-section">
            <div class="form-section-title">Basic Information</div>

            <div class="form-grid">

                <div class="form-group full">
                    <label for="class_id">Class <span class="req">*</span></label>
                    <select id="class_id" name="class_id" class="form-control" required>
                        <option value="">— Select class —</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int)$c['class_id']; ?>"
                                <?php echo (string)$class_id === (string)$c['class_id'] ? 'selected' : ''; ?>>
                                <?php echo e($c['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($classes)): ?>
                        <div class="help-text" style="color:var(--red);">
                            ⚠ No active classes found. <a href="classes.php" style="color:var(--red);font-weight:700;">Add a class</a> first.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group full">
                    <label>Admission Number (Auto-generated)</label>
                    <?php if (!$active_year): ?>
                        <div class="admission-preview no-year" id="admissionPreview">
                            ⚠ No active academic year.
                            <a href="academic_years.php" style="color:inherit;font-weight:800;text-decoration:underline;margin-left:6px;">Activate one</a>
                        </div>
                    <?php else: ?>
                        <div class="admission-preview pending" id="admissionPreview">
                            Select a class to generate the admission number
                        </div>
                    <?php endif; ?>
                    <div class="help-text">
                        Format: <strong>YEAR / CLASS-LEVEL / SEQUENCE</strong>
                        — e.g. <?php echo e($active_year_n ?: '2025'); ?>/1/001
                    </div>
                </div>

                <div class="form-group full">
                    <label for="full_name">Full Name <span class="req">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                           value="<?php echo e($full_name); ?>"
                           placeholder="Enter student's full name"
                           maxlength="150"
                           oninput="this.value = this.value.replace(/[0-9]/g, '')"
                           required>
                    <div class="help-text">Letters, spaces, hyphens and apostrophes only.</div>
                </div>

                <div class="form-group">
                    <label for="gender">Gender <span class="req">*</span></label>
                    <select id="gender" name="gender" class="form-control" required>
                        <option value="" disabled <?php echo $gender === '' ? 'selected' : ''; ?>>Select gender</option>
                        <option value="Male"   <?php echo $gender === 'Male'   ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                           value="<?php echo e($date_of_birth); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>

            </div>
        </div>

        <!-- SECTION 2 — ENROLLMENT -->
        <div class="form-section">
            <div class="form-section-title">Enrollment</div>

            <div class="form-grid">

                <div class="form-group">
                    <label for="admission_date">Admission Date</label>
                    <input type="date" id="admission_date" name="admission_date" class="form-control"
                           value="<?php echo e($admission_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="active"      <?php echo $status === 'active'      ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive"    <?php echo $status === 'inactive'    ? 'selected' : ''; ?>>Inactive</option>
                        <option value="graduated"   <?php echo $status === 'graduated'   ? 'selected' : ''; ?>>Graduated</option>
                        <option value="transferred" <?php echo $status === 'transferred' ? 'selected' : ''; ?>>Transferred</option>
                    </select>
                </div>

            </div>
        </div>

        <!-- SECTION 3 — PARENT LINKS -->
        <?php if ($has_links_table): ?>
        <div class="form-section">
            <div class="form-section-title">Parents / Guardians</div>

            <div class="link-list" id="linkList">
                <!-- dynamic rows injected here -->
            </div>

            <div class="no-links-hint" id="noLinksHint">
                <?php if (empty($parents)): ?>
                    No parent accounts exist yet. <a href="add_parent.php" style="color:var(--gold);font-weight:700;">Add a parent</a> first, then link them here.
                <?php else: ?>
                    No parents linked yet.
                <?php endif; ?>
            </div>

            <?php if (!empty($parents)): ?>
                <button type="button" class="add-link-btn" id="addLinkBtn">
                    + Link Parent / Guardian
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SECTION 4 — PHOTO -->
        <div class="form-section">
            <div class="form-section-title">Photo (Optional)</div>

            <div class="photo-upload" id="photoUpload">
                <div class="photo-preview" id="photoPreview">
                    <span id="photoInitial">?</span>
                </div>
                <div class="photo-text">
                    <strong>Student Photo</strong>
                    <span>JPG, PNG or WEBP — max 2MB</span>
                </div>
                <button type="button" class="photo-btn"
                        onclick="document.getElementById('photoInput').click()">
                    Choose Photo
                </button>
                <input type="file" id="photoInput" name="photo"
                       class="photo-input" accept="image/jpeg,image/png,image/webp">
            </div>
        </div>

        <!-- FOOTER -->
        <div class="form-footer">
            <a href="students.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"
                <?php echo $active_year ? '' : 'disabled title="No active academic year"'; ?>>
                Save Student
            </button>
        </div>

    </form>

</main>


<script>
/* =========================================================
   CONFIG from PHP
========================================================= */
const CLASS_LEVELS = <?php echo json_encode($class_levels_js); ?>;
const ACTIVE_YEAR  = <?php echo (int) $active_year_n; ?>;
const ALL_PARENTS  = <?php echo json_encode(array_map(function ($p) {
    return [
        'parent_id' => (int) $p['parent_id'],
        'full_name' => $p['full_name'],
        'phone'     => $p['phone'],
        'email'     => $p['email'],
    ];
}, $parents), JSON_UNESCAPED_UNICODE); ?>;

const RELATIONSHIPS = ['Father', 'Mother', 'Guardian', 'Other'];


/* =========================================================
   ADMISSION NUMBER PREVIEW
========================================================= */
(function () {
    const classSelect = document.getElementById('class_id');
    const preview     = document.getElementById('admissionPreview');

    if (!classSelect || !preview || !ACTIVE_YEAR) return;

    let lastRequest = 0;

    function setPending(text) {
        preview.classList.add('pending');
        preview.classList.remove('no-year');
        preview.innerHTML = text || 'Select a class to generate the admission number';
    }

    function setValue(value) {
        preview.classList.remove('pending', 'no-year');
        preview.innerHTML = '<span class="admission-dot"></span>' + value;
    }

    function updatePreview() {
        const cid   = classSelect.value;
        const level = CLASS_LEVELS[cid];

        if (!cid || !level) {
            setPending();
            return;
        }

        const reqId = ++lastRequest;
        setPending('Generating…');

        fetch('next_admission_no.php?class_level=' + encodeURIComponent(level) +
              '&year=' + encodeURIComponent(ACTIVE_YEAR), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (reqId !== lastRequest) return;
            if (data && data.admission_no) setValue(data.admission_no);
            else setPending('Could not generate admission number');
        })
        .catch(() => {
            if (reqId !== lastRequest) return;
            setPending('Could not generate admission number');
        });
    }

    classSelect.addEventListener('change', updatePreview);
    if (classSelect.value) updatePreview();
})();


/* =========================================================
   PARENT LINK ROWS
========================================================= */
(function () {
    const linkList   = document.getElementById('linkList');
    const noLinkHint = document.getElementById('noLinksHint');
    const addLinkBtn = document.getElementById('addLinkBtn');

    if (!linkList || !addLinkBtn) return;

    function escHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function parentOptions(selectedId) {
        let html = '<option value="">— Select parent —</option>';
        for (const p of ALL_PARENTS) {
            const sel = String(p.parent_id) === String(selectedId) ? ' selected' : '';
            const label = p.full_name + (p.phone ? ' · ' + p.phone : '');
            html += `<option value="${p.parent_id}"${sel}>${escHtml(label)}</option>`;
        }
        return html;
    }

    function relationshipOptions(selected) {
        return RELATIONSHIPS.map(r =>
            `<option value="${r}"${r === selected ? ' selected' : ''}>${r}</option>`
        ).join('');
    }

    function refreshHint() {
        const has = linkList.querySelectorAll('.link-row').length > 0;
        noLinkHint.style.display = has ? 'none' : '';
    }

    function enforceSinglePrimary(activeRow) {
        document.querySelectorAll('.link-row').forEach(r => {
            if (r !== activeRow) {
                const cb = r.querySelector('.link-primary');
                const lb = r.querySelector('.primary-toggle');
                if (cb && cb.checked) {
                    cb.checked = false;
                    lb.classList.remove('on');
                }
            }
        });
    }

    function createRow() {
        const row = document.createElement('div');
        row.className = 'link-row';

        row.innerHTML = `
            <div class="form-group">
                <label>Parent / Guardian</label>
                <select name="link_parent_id[]" class="form-control link-parent">
                    ${parentOptions('')}
                </select>
            </div>

            <div class="form-group">
                <label>Relationship</label>
                <select name="link_relationship[]" class="form-control link-rel">
                    ${relationshipOptions('Guardian')}
                </select>
            </div>

            <label class="primary-toggle" title="Mark as primary guardian">
                <input type="checkbox" name="link_primary[]" value="1" class="link-primary">
                <span class="dot"></span>
                <span>Primary</span>
            </label>

            <button type="button" class="link-remove" title="Remove">✕</button>
        `;

        const cb = row.querySelector('.link-primary');
        const lb = row.querySelector('.primary-toggle');
        cb.addEventListener('change', () => {
            lb.classList.toggle('on', cb.checked);
            if (cb.checked) enforceSinglePrimary(row);
        });

        row.querySelector('.link-remove').addEventListener('click', () => {
            row.remove();
            refreshHint();
        });

        linkList.appendChild(row);
        refreshHint();
    }

    addLinkBtn.addEventListener('click', createRow);

    /* Start with one row if parents exist */
    if (ALL_PARENTS.length > 0) createRow();
})();


/* =========================================================
   PHOTO PREVIEW
========================================================= */
(function () {
    const input   = document.getElementById('photoInput');
    const preview = document.getElementById('photoPreview');
    const upload  = document.getElementById('photoUpload');
    const nameInput = document.getElementById('full_name');

    function updateInitial() {
        const name = nameInput.value.trim();
        const initial = name ? name.charAt(0).toUpperCase() : '?';
        if (!preview.querySelector('img')) {
            preview.innerHTML = '<span>' + initial + '</span>';
        }
    }

    function showPreview(file) {
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            alert('Please choose an image file.');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            alert('Photo must be smaller than 2MB.');
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
        };
        reader.readAsDataURL(file);
    }

    if (input) {
        input.addEventListener('change', e => {
            if (e.target.files && e.target.files[0]) showPreview(e.target.files[0]);
        });
    }

    if (upload) {
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
                showPreview(file);
            }
        });
    }

    if (nameInput) nameInput.addEventListener('input', updateInitial);
    updateInitial();
})();
</script>

</body>
</html>