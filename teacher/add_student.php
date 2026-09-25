<?php
session_start();

require_once '../includes/db.php';
require_once '../auth/auth_check.php';

/* =========================================================================
   TEACHER AUTHENTICATION
   ========================================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$sql = "
    SELECT
        t.teacher_id,
        c.class_id,
        c.class_name,
        c.class_level,
        c.stream,
        ct.academic_year_id
    FROM teachers t
    INNER JOIN users u ON u.user_id = t.user_id
    INNER JOIN class_teachers ct
        ON ct.teacher_id = t.teacher_id
        AND ct.status = 'active'
    INNER JOIN classes c
        ON c.class_id = ct.class_id
        AND c.status = 'active'
    INNER JOIN academic_years ay
        ON ay.academic_year_id = ct.academic_year_id
        AND ay.status = 'active'
    WHERE t.user_id = ?
      AND t.employment_status = 'active'
    ORDER BY ct.class_teacher_id DESC
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) die("Failed to prepare teacher query: " . mysqli_error($conn));

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$teacher_class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

/* ACCESS DENIED */
if (!$teacher_class) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied | PSRMS</title>
    <style>
        *{box-sizing:border-box;}
        body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#f4f6f8;color:#1f2937;
             min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .box{max-width:560px;width:100%;background:#fff;padding:40px 32px;border-radius:14px;
             text-align:center;box-shadow:0 12px 36px rgba(0,0,0,.08);border:1px solid #e5e7eb;}
        .icon{width:70px;height:70px;margin:0 auto 20px;border-radius:50%;background:#fff0f0;
              color:#a12626;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;}
        .box h2{margin:0 0 12px;color:#a12626;font-size:22px;}
        .box p{margin:0 0 8px;color:#4b5563;font-size:14.5px;line-height:1.6;}
        .hint{margin-top:18px;padding:12px 16px;background:#fffbe9;border:1px solid #f0e0a8;
              border-radius:10px;font-size:13px;color:#7a5a00;text-align:left;}
        .actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:24px;}
        .box a{display:inline-flex;align-items:center;justify-content:center;padding:12px 22px;
               background:#243447;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:13.5px;}
        .box a.secondary{background:#fff;color:#243447;border:1px solid #d7dce2;}
        @media (max-width:480px){.box{padding:30px 22px;}.actions{flex-direction:column;}.box a{width:100%;}}
    </style></head><body>
        <div class="box">
            <div class="icon">!</div>
            <h2>Access Denied</h2>
            <p>Only the <strong>class teacher</strong> of a class can register new students.</p>
            <p>You are not currently assigned as an active class teacher.</p>
            <div class="hint"><strong>What to do:</strong><br>
                Contact the headteacher or academic master to confirm your
                class-teacher assignment for the active academic year.</div>
            <div class="actions">
                <a href="students.php">Back to Students</a>
                <a href="dashboard.php" class="secondary">Dashboard</a>
            </div>
        </div>
    </body></html>
    <?php
    exit;
}

/* =========================================================================
   TEACHER / CLASS
   ========================================================================= */

$class_id         = (int) $teacher_class['class_id'];
$class_name       = $teacher_class['class_name'];
$class_level      = (int) $teacher_class['class_level'];
$stream           = $teacher_class['stream'];
$academic_year_id = (int) $teacher_class['academic_year_id'];

if ($class_level <= 0) die("The assigned class does not have a valid class level.");

$stmt = mysqli_prepare(
    $conn,
    "SELECT year FROM academic_years
     WHERE academic_year_id = ? AND status = 'active' LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $academic_year_id);
mysqli_stmt_execute($stmt);
$ay = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$ay) die("The academic year assigned to your class is not active or does not exist.");
$academic_year = (int) $ay['year'];

/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function generateAdmissionNumber($conn, $class_level, $academic_year)
{
    $prefix = $class_level . $academic_year;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT admission_no FROM students
         WHERE admission_no LIKE CONCAT(?, '%')
         ORDER BY CAST(RIGHT(admission_no, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );
    if (!$stmt) throw new Exception("Failed to prepare admission query: " . mysqli_error($conn));

    mysqli_stmt_bind_param($stmt, "s", $prefix);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $last = 0;
    if ($row = mysqli_fetch_assoc($result)) {
        $last = (int) substr($row['admission_no'], -4);
    }
    mysqli_stmt_close($stmt);

    $next = $last + 1;
    if ($next > 9999) throw new Exception("Admission sequence reached 9999 for this class/year.");

    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function normalize_date(?string $value): ?string
{
    if ($value === null) return null;
    $value = trim($value);
    if ($value === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;

    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return null;

    $year = (int) $dt->format('Y');
    if ($year < 1900 || $year > ((int) date('Y') + 1)) return null;

    return $value;
}

/* =========================================================================
   FLASH  (POST / REDIRECT / GET)
   ========================================================================= */

$success = $_SESSION['add_student_success'] ?? '';
$error   = $_SESSION['add_student_error']   ?? '';
$parent_credentials = $_SESSION['parent_credentials'] ?? null;

unset(
    $_SESSION['add_student_success'],
    $_SESSION['add_student_error'],
    $_SESSION['parent_credentials']
);

/* =========================================================================
   STATE
   ========================================================================= */

$mode         = $_POST['mode']  ?? $_GET['mode']  ?? 'search';
$search_query = trim($_POST['search'] ?? $_GET['search'] ?? '');

$selected_parent          = null;
$selected_parent_children = [];
$selected_parent_id       = 0;

/* =========================================================================
   SEARCH
   ========================================================================= */

$search_results = [];

if ($mode === 'search' && $search_query !== '') {

    $like = '%' . $search_query . '%';

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            u.user_id,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.phone,
            u.email,
            u.gender,
            p.parent_id,
            p.occupation,
            p.address,
            (SELECT COUNT(*) FROM parent_children pc WHERE pc.parent_id = p.parent_id) AS children_count
         FROM users u
         INNER JOIN parents p ON p.user_id = u.user_id
         WHERE u.role = 'parent'
           AND (
                u.phone LIKE ?
                OR u.first_name LIKE ?
                OR u.middle_name LIKE ?
                OR u.last_name LIKE ?
                OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
           )
         ORDER BY u.first_name ASC, u.last_name ASC
         LIMIT 15"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sssss",
            $like, $like, $like, $like, $like);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $search_results[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

/* =========================================================================
   LOAD SELECTED EXISTING PARENT
   ========================================================================= */

if ($mode === 'existing') {

    $selected_parent_id = (int) ($_POST['parent_id'] ?? $_GET['parent_id'] ?? 0);

    if ($selected_parent_id > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                u.user_id, u.first_name, u.middle_name, u.last_name,
                u.email, u.gender, u.phone,
                p.parent_id, p.occupation, p.address
             FROM users u
             INNER JOIN parents p ON p.user_id = u.user_id
             WHERE p.parent_id = ?
               AND u.role = 'parent'
             LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $selected_parent_id);
            mysqli_stmt_execute($stmt);
            $selected_parent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        }
    }

    if (!$selected_parent) {
        $mode = 'search';
        $selected_parent_id = 0;
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT s.student_id, s.full_name, s.admission_no,
                    c.class_name, c.stream
             FROM parent_children pc
             INNER JOIN students s ON s.student_id = pc.student_id
             LEFT JOIN classes c ON c.class_id = s.class_id
             WHERE pc.parent_id = ?
             ORDER BY s.full_name ASC"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $selected_parent_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $selected_parent_children[] = $row;
            }
            mysqli_stmt_close($stmt);
        }
    }
}

/* =========================================================================
   FORM VALUES (for redisplay on validation error)
   ========================================================================= */

$form = $_SESSION['add_student_form'] ?? [];
unset($_SESSION['add_student_form']);

$student_name   = $form['student_name']   ?? '';
$student_gender = $form['student_gender'] ?? '';
$date_of_birth  = $form['date_of_birth']  ?? '';
$admission_date = $form['admission_date'] ?? date('Y-m-d');

$parent_first_name  = $form['parent_first_name']  ?? '';
$parent_middle_name = $form['parent_middle_name'] ?? '';
$parent_last_name   = $form['parent_last_name']   ?? '';
$parent_gender      = $form['parent_gender']      ?? '';
$parent_phone       = $form['parent_phone']       ?? '';
$parent_email       = $form['parent_email']       ?? '';
$parent_occupation  = $form['parent_occupation']  ?? '';
$parent_address     = $form['parent_address']     ?? '';
$relationship       = $form['relationship']       ?? 'Guardian';

/* =========================================================================
   ADMISSION NUMBER DISPLAY
   ========================================================================= */

$admission_no = '';
try {
    $admission_no = generateAdmissionNumber($conn, $class_level, $academic_year);
} catch (Exception $e) {
    if ($error === '') $error = $e->getMessage();
}

/* =========================================================================
   POST — REGISTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {

    /* Collect */
    $student_name   = trim($_POST['student_name'] ?? '');
    $student_gender = trim($_POST['student_gender'] ?? '');
    $date_of_birth  = trim($_POST['date_of_birth'] ?? '');
    $admission_date = trim($_POST['admission_date'] ?? '');

    $parent_first_name  = trim($_POST['parent_first_name'] ?? '');
    $parent_middle_name = trim($_POST['parent_middle_name'] ?? '');
    $parent_last_name   = trim($_POST['parent_last_name'] ?? '');
    $parent_gender      = trim($_POST['parent_gender'] ?? '');
    $parent_phone       = trim($_POST['parent_phone'] ?? '');
    $parent_email       = trim($_POST['parent_email'] ?? '');
    $parent_occupation  = trim($_POST['parent_occupation'] ?? '');
    $parent_address     = trim($_POST['parent_address'] ?? '');
    $relationship       = trim($_POST['relationship'] ?? 'Guardian');

    $date_of_birth_db  = normalize_date($date_of_birth);
    $admission_date_db = normalize_date($admission_date);
    if ($admission_date_db === null) $admission_date_db = date('Y-m-d');

    /* Validate */
    if ($student_name === '') {
        $error = "Student name is required.";
    } elseif (!in_array($student_gender, ['Male', 'Female'], true)) {
        $error = "Please select a valid student gender.";
    } elseif ($date_of_birth !== '' && $date_of_birth_db === null) {
        $error = "Please enter a valid date of birth.";
    } elseif (!in_array($relationship, ['Father', 'Mother', 'Guardian', 'Other'], true)) {
        $error = "Invalid parent relationship.";
    } elseif ($mode === 'new') {
        if ($parent_first_name === '') $error = "Parent first name is required.";
        elseif ($parent_last_name === '') $error = "Parent last name is required.";
        elseif (!in_array($parent_gender, ['Male', 'Female'], true)) $error = "Please select a valid parent gender.";
        elseif ($parent_phone === '') $error = "Parent phone number is required.";
        elseif ($parent_email !== '' && !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid parent email address.";
        }
    }

    /* DB work */
    if ($error === '') {

        mysqli_begin_transaction($conn);

        try {

            /* ==========================================================
               DUPLICATE STUDENT CHECK
               ==========================================================
               Prevents accidental double insert if the same student
               name is submitted twice for the same class in the same
               academic year while the first one is still active.
            ========================================================== */
            $dup_stmt = mysqli_prepare(
                $conn,
                "SELECT student_id
                 FROM students
                 WHERE class_id = ?
                   AND full_name = ?
                   AND status = 'active'
                 LIMIT 1"
            );
            if (!$dup_stmt) {
                throw new Exception("Failed to prepare duplicate student check: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($dup_stmt, "is", $class_id, $student_name);
            mysqli_stmt_execute($dup_stmt);
            mysqli_stmt_store_result($dup_stmt);

            if (mysqli_stmt_num_rows($dup_stmt) > 0) {
                mysqli_stmt_close($dup_stmt);
                throw new Exception(
                    "A student with this exact name already exists in this class. "
                    . "Please check the existing students list."
                );
            }
            mysqli_stmt_close($dup_stmt);

            /* Admission number */
            $admission_no = generateAdmissionNumber($conn, $class_level, $academic_year);

            $chk = mysqli_prepare($conn, "SELECT student_id FROM students WHERE admission_no = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "s", $admission_no);
            mysqli_stmt_execute($chk);
            if (mysqli_num_rows(mysqli_stmt_get_result($chk)) > 0) {
                mysqli_stmt_close($chk);
                throw new Exception("The generated admission number already exists. Please try again.");
            }
            mysqli_stmt_close($chk);

            /* Insert student */
            if ($date_of_birth_db === null) {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO students
                     (admission_no, full_name, gender, date_of_birth, class_id, admission_date, status)
                     VALUES (?, ?, ?, NULL, ?, ?, 'active')"
                );
                mysqli_stmt_bind_param(
                    $stmt, "sssis",
                    $admission_no, $student_name, $student_gender, $class_id, $admission_date_db
                );
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO students
                     (admission_no, full_name, gender, date_of_birth, class_id, admission_date, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')"
                );
                mysqli_stmt_bind_param(
                    $stmt, "ssssis",
                    $admission_no, $student_name, $student_gender, $date_of_birth_db, $class_id, $admission_date_db
                );
            }
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to add student: " . mysqli_stmt_error($stmt));
            }
            $student_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            /* ----------------------------------------------------------
               CASE A — Existing parent
            ---------------------------------------------------------- */
            if ($mode === 'existing') {

                if (!$selected_parent) {
                    throw new Exception("The selected parent is no longer available.");
                }

                $parent_id = (int) $selected_parent['parent_id'];

                $dup = mysqli_prepare(
                    $conn,
                    "SELECT id FROM parent_children WHERE parent_id = ? AND student_id = ? LIMIT 1"
                );
                mysqli_stmt_bind_param($dup, "ii", $parent_id, $student_id);
                mysqli_stmt_execute($dup);
                mysqli_stmt_store_result($dup);
                if (mysqli_stmt_num_rows($dup) > 0) {
                    mysqli_stmt_close($dup);
                    throw new Exception("This student is already linked to this parent.");
                }
                mysqli_stmt_close($dup);

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO parent_children
                     (parent_id, student_id, relationship, is_primary_guardian)
                     VALUES (?, ?, ?, 0)"
                );
                mysqli_stmt_bind_param($stmt, "iis", $parent_id, $student_id, $relationship);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Failed to link parent to student: " . mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);

                $_SESSION['parent_credentials'] = [
                    'mode'         => 'existing',
                    'student_name' => $student_name,
                    'admission_no' => $admission_no,
                    'parent_name'  => trim(
                        $selected_parent['first_name'] . ' ' .
                        ($selected_parent['middle_name'] ? $selected_parent['middle_name'] . ' ' : '') .
                        $selected_parent['last_name']
                    ),
                    'parent_phone' => $selected_parent['phone'],
                ];
                $_SESSION['add_student_success'] =
                    "Student registered successfully and linked to the existing parent.";

            /* ----------------------------------------------------------
               CASE B — New parent
            ---------------------------------------------------------- */
            } else {

                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT user_id FROM users WHERE phone = ? AND role = 'parent' LIMIT 1"
                );
                mysqli_stmt_bind_param($stmt, "s", $parent_phone);
                mysqli_stmt_execute($stmt);
                if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {
                    mysqli_stmt_close($stmt);
                    throw new Exception(
                        "This phone number is already registered. "
                        . "Please search for the existing parent instead."
                    );
                }
                mysqli_stmt_close($stmt);

                if ($parent_email !== '') {
                    $stmt = mysqli_prepare(
                        $conn,
                        "SELECT user_id FROM users WHERE email = ? LIMIT 1"
                    );
                    mysqli_stmt_bind_param($stmt, "s", $parent_email);
                    mysqli_stmt_execute($stmt);
                    if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {
                        mysqli_stmt_close($stmt);
                        throw new Exception("This email is already registered.");
                    }
                    mysqli_stmt_close($stmt);
                }

                $activation_code      = (string) random_int(100000, 999999);
                $activation_code_hash = password_hash($activation_code, PASSWORD_DEFAULT);
                $activation_expires_at = date('Y-m-d H:i:s', time() + 86400);

                $placeholder_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO users
                     (first_name, middle_name, last_name, email, password, role,
                      gender, phone, status, account_activated,
                      activation_code_hash, activation_expires_at, must_change_password)
                     VALUES (?, ?, ?, ?, ?, 'parent', ?, ?, 'inactive', 0, ?, ?, 1)"
                );
                if (!$stmt) throw new Exception("Failed to prepare parent user insert: " . mysqli_error($conn));

                mysqli_stmt_bind_param(
                    $stmt, "sssssssss",
                    $parent_first_name, $parent_middle_name, $parent_last_name,
                    $parent_email, $placeholder_password,
                    $parent_gender, $parent_phone,
                    $activation_code_hash, $activation_expires_at
                );
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Failed to create parent: " . mysqli_stmt_error($stmt));
                }
                $parent_user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO parents (user_id, occupation, address) VALUES (?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $stmt, "iss",
                    $parent_user_id, $parent_occupation, $parent_address
                );
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Failed to create parent profile: " . mysqli_stmt_error($stmt));
                }
                $parent_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO parent_children
                     (parent_id, student_id, relationship, is_primary_guardian)
                     VALUES (?, ?, ?, 1)"
                );
                mysqli_stmt_bind_param($stmt, "iis", $parent_id, $student_id, $relationship);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Failed to link parent to student: " . mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);

                $_SESSION['parent_credentials'] = [
                    'mode'            => 'new',
                    'student_name'    => $student_name,
                    'admission_no'    => $admission_no,
                    'parent_name'     => trim(
                        $parent_first_name . ' ' .
                        ($parent_middle_name !== '' ? $parent_middle_name . ' ' : '') .
                        $parent_last_name
                    ),
                    'parent_phone'    => $parent_phone,
                    'activation_code' => $activation_code,
                    'expires_at'      => $activation_expires_at,
                ];
                $_SESSION['add_student_success'] =
                    "Student and parent account created successfully.";
            }

            /* ----------------------------------------------------------
               POST / REDIRECT / GET
               ----------------------------------------------------------
               Redirect back to a fresh GET request so the browser
               cannot resubmit the form on refresh.
            ---------------------------------------------------------- */
            header("Location: add_student.php");
            exit;

        } catch (Throwable $e) {

            mysqli_rollback($conn);

            /* Keep the error + form values in session, then redirect
               back so a refresh can never re-trigger the POST. */
            $_SESSION['add_student_error'] = $e->getMessage();

            $redirect_mode = ($mode === 'existing' && $selected_parent)
                ? 'existing'
                : $mode;

            $redirect = "add_student.php?mode=" . urlencode($redirect_mode);
            if ($redirect_mode === 'existing' && $selected_parent) {
                $redirect .= "&parent_id=" . (int)$selected_parent['parent_id'];
            }

            /* Preserve form data so nothing is lost on the redirect */
            $_SESSION['add_student_form'] = [
                'student_name'       => $student_name,
                'student_gender'     => $student_gender,
                'date_of_birth'      => $date_of_birth,
                'admission_date'     => $admission_date,
                'parent_first_name'  => $parent_first_name,
                'parent_middle_name' => $parent_middle_name,
                'parent_last_name'   => $parent_last_name,
                'parent_gender'      => $parent_gender,
                'parent_phone'       => $parent_phone,
                'parent_email'       => $parent_email,
                'parent_occupation'  => $parent_occupation,
                'parent_address'     => $parent_address,
                'relationship'       => $relationship,
            ];

            header("Location: " . $redirect);
            exit;
        }
    } else {

        /* Validation error — keep form data and redirect */
        $_SESSION['add_student_error'] = $error;
        $_SESSION['add_student_form'] = [
            'student_name'       => $student_name,
            'student_gender'     => $student_gender,
            'date_of_birth'      => $date_of_birth,
            'admission_date'     => $admission_date,
            'parent_first_name'  => $parent_first_name,
            'parent_middle_name' => $parent_middle_name,
            'parent_last_name'   => $parent_last_name,
            'parent_gender'      => $parent_gender,
            'parent_phone'       => $parent_phone,
            'parent_email'       => $parent_email,
            'parent_occupation'  => $parent_occupation,
            'parent_address'     => $parent_address,
            'relationship'       => $relationship,
        ];

        $redirect = "add_student.php?mode=" . urlencode($mode);
        if ($mode === 'existing' && $selected_parent) {
            $redirect .= "&parent_id=" . (int)$selected_parent['parent_id'];
        }

        header("Location: " . $redirect);
        exit;
    }
}

/* Re-read flash after redirect fall-through */
$success            = $_SESSION['add_student_success'] ?? $success;
$error              = $_SESSION['add_student_error']   ?? $error;
$parent_credentials = $_SESSION['parent_credentials']  ?? $parent_credentials;

unset(
    $_SESSION['add_student_success'],
    $_SESSION['add_student_error'],
    $_SESSION['parent_credentials']
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Add Student - PSRMS</title>

    <style>
        * { box-sizing: border-box; }
        :root{
            --navy:#172033;--navy-dark:#10182b;--gold:#c9a227;--gold-light:#e2c65a;
            --cream:#f4f7fb;--white:#ffffff;--text:#172033;--muted:#687386;
            --border:#e4e9f0;--red:#a12626;--red-bg:#fff0f0;
            --green:#17663c;--green-bg:#e8f7ef;--blue:#2f5d8f;--blue-bg:#eaf1fa;
            --sidebar-w:250px;--topbar-h:64px;
        }
        html,body{overflow-x:hidden;}
        body{margin:0;background:var(--cream);
             font-family:"Segoe UI",Inter,Arial,Helvetica,sans-serif;
             color:var(--text);min-height:100vh;-webkit-text-size-adjust:100%;}
        body.no-scroll{overflow:hidden;}

        .main-content{margin-left:var(--sidebar-w);
                      padding:calc(var(--topbar-h) + 30px) 30px 40px;
                      transition:margin-left .25s ease;}
        @media (min-width:801px){body.sidebar-collapsed .main-content{margin-left:78px;}}

        /* MOBILE TOPBAR */
        .mobile-topbar{display:none;position:fixed;top:0;left:0;right:0;height:58px;
                       background:var(--navy);color:var(--white);align-items:center;
                       justify-content:space-between;padding:0 16px;z-index:1100;
                       box-shadow:0 2px 8px rgba(0,0,0,.15);}
        .mobile-topbar .brand{font-size:14px;font-weight:800;letter-spacing:.5px;}
        .mobile-topbar .brand span{color:var(--gold-light);}
        .hamburger{width:40px;height:40px;border:none;background:rgba(255,255,255,.08);
                   border-radius:6px;display:flex;flex-direction:column;align-items:center;
                   justify-content:center;gap:4px;cursor:pointer;padding:0;}
        .hamburger span{display:block;width:18px;height:2px;background:var(--white);
                        border-radius:2px;transition:.2s ease;}
        .hamburger.active span:nth-child(1){transform:translateY(6px) rotate(45deg);}
        .hamburger.active span:nth-child(2){opacity:0;}
        .hamburger.active span:nth-child(3){transform:translateY(-6px) rotate(-45deg);}
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
                         z-index:1050;opacity:0;transition:opacity .25s ease;}
        .sidebar-overlay.open{display:block;opacity:1;}

        .page-wrapper{max-width:1100px;margin:0 auto;}
        .page-header{margin-bottom:22px;}
        .page-header h1{margin:0 0 7px;font-size:28px;color:var(--navy);}
        .page-header p{margin:0;color:var(--muted);font-size:13px;}

        /* STEPS */
        .steps{display:flex;align-items:center;gap:10px;margin-bottom:22px;flex-wrap:wrap;}
        .step{display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:20px;
              font-size:12px;font-weight:700;background:var(--white);border:1px solid var(--border);
              color:var(--muted);}
        .step .num{width:22px;height:22px;border-radius:50%;background:#eef2f7;color:var(--navy);
                   display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;}
        .step.active{background:var(--navy);color:#fff;border-color:var(--navy);}
        .step.active .num{background:var(--gold);color:var(--navy);}
        .step.done{background:var(--green-bg);color:var(--green);border-color:#cfe5d7;}
        .step.done .num{background:var(--green);color:#fff;}
        .step-arrow{color:var(--muted);font-size:14px;}

        .class-card{background:var(--navy);color:#fff;padding:18px 22px;border-radius:14px;
                    margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;
                    gap:20px;flex-wrap:wrap;}
        .class-card h3{margin:0 0 5px;font-size:17px;}
        .class-card p{margin:0;opacity:.8;font-size:12.5px;}
        .admission-box{background:#fff;border:1px solid var(--border);border-radius:12px;
                       padding:12px 16px;min-width:200px;}
        .admission-box small{display:block;color:#778195;margin-bottom:5px;font-size:10.5px;}
        .admission-number{font-size:21px;font-weight:800;letter-spacing:1px;color:var(--navy);
                          overflow-wrap:anywhere;}

        .alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;
               font-weight:600;line-height:1.5;}
        .alert-success{background:var(--green-bg);color:var(--green);border:1px solid #bfe7cf;}
        .alert-error{background:var(--red-bg);color:var(--red);border:1px solid #f0c2c2;}

        .card{background:#fff;border:1px solid var(--border);border-radius:12px;
              padding:22px;margin-bottom:18px;box-shadow:0 4px 18px rgba(23,32,51,.04);}
        .card-title{margin:0 0 6px;font-size:16px;color:var(--navy);font-weight:750;}
        .card-sub{margin:0 0 18px;font-size:12.5px;color:var(--muted);line-height:1.5;}

        .search-row{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;}
        .search-row input{flex:1;min-width:180px;height:46px;border:1px solid var(--border);
                          border-radius:9px;padding:0 14px;font-size:15px;font-family:inherit;
                          outline:none;background:#fcfcfd;}
        .search-row input:focus{border-color:var(--navy);background:#fff;
                                box-shadow:0 0 0 3px rgba(23,32,51,.08);}
        .search-row button,
        .btn-primary{height:46px;padding:0 20px;background:var(--navy);color:#fff;border:none;
                     border-radius:9px;font-family:inherit;font-size:13.5px;font-weight:700;
                     cursor:pointer;white-space:nowrap;transition:.15s ease;}
        .search-row button:hover,
        .btn-primary:hover{background:var(--navy-dark);}

        .btn-ghost{height:46px;padding:0 18px;background:#fff;color:var(--navy);
                   border:1px solid var(--border);border-radius:9px;font-family:inherit;
                   font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none;
                   display:inline-flex;align-items:center;justify-content:center;}
        .btn-ghost:hover{border-color:var(--gold);}

        .results{display:grid;gap:10px;margin-top:18px;}
        .parent-row{display:flex;gap:14px;align-items:center;padding:14px 16px;
                    background:#fcfcfd;border:1px solid var(--border);border-radius:10px;
                    transition:.15s ease;}
        .parent-row:hover{border-color:var(--gold);background:#fff;}
        .parent-avatar{width:46px;height:46px;border-radius:50%;background:var(--navy);
                       color:var(--gold-light);display:flex;align-items:center;justify-content:center;
                       font-size:17px;font-weight:800;flex-shrink:0;}
        .parent-info{flex:1;min-width:0;}
        .parent-name{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:2px;
                     overflow-wrap:anywhere;}
        .parent-meta{font-size:12px;color:var(--muted);overflow-wrap:anywhere;}
        .parent-children{font-size:11px;color:var(--muted);margin-top:3px;}
        .btn-use{padding:9px 16px;background:var(--gold);color:var(--navy);border:none;
                 border-radius:8px;font-weight:700;font-size:12.5px;cursor:pointer;
                 font-family:inherit;white-space:nowrap;transition:.15s ease;}
        .btn-use:hover{background:var(--gold-light);}

        .divider{display:flex;align-items:center;gap:12px;color:var(--muted);
                 font-size:11.5px;font-weight:700;letter-spacing:.5px;
                 text-transform:uppercase;margin:20px 0;}
        .divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--border);}

        .selected-parent{display:flex;gap:14px;align-items:center;padding:14px 16px;
                         background:var(--green-bg);border:1px solid #cfe5d7;border-radius:10px;
                         margin-bottom:18px;flex-wrap:wrap;}
        .selected-parent .parent-avatar{background:var(--green);color:#fff;}
        .selected-parent .parent-name{color:var(--green);}
        .selected-parent .parent-meta{color:#2f6b47;}
        .selected-parent .change-btn{padding:8px 14px;background:#fff;color:var(--green);
                                     border:1px solid #cfe5d7;border-radius:7px;
                                     font-size:12px;font-weight:700;text-decoration:none;}
        .selected-parent .change-btn:hover{border-color:var(--green);}

        .children-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;
                        padding-top:12px;border-top:1px dashed #cfe5d7;}
        .child-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;
                    background:#fff;border:1px solid #cfe5d7;border-radius:20px;
                    font-size:11.5px;font-weight:700;color:#2f6b47;}
        .child-chip .dot{width:5px;height:5px;border-radius:50%;background:var(--green);}

        .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;}
        .form-group{display:flex;flex-direction:column;min-width:0;}
        .form-group.full{grid-column:1/-1;}
        label{margin-bottom:6px;font-weight:600;font-size:13px;color:var(--navy);}
        input,select,textarea{width:100%;border:1px solid #d9e0e8;border-radius:9px;
                              padding:12px 13px;font-size:14px;font-family:inherit;
                              outline:none;background:#fff;color:var(--text);transition:.15s ease;}
        input:focus,select:focus,textarea:focus{border-color:var(--navy);
                                                box-shadow:0 0 0 3px rgba(23,32,51,.08);}
        input[readonly],textarea[readonly]{background:#f4f6f9;font-weight:600;color:var(--muted);}
        textarea{min-height:90px;resize:vertical;}
        .required{color:#c62828;}

        .submit-area{display:flex;justify-content:flex-end;gap:10px;margin-top:22px;
                     flex-wrap:wrap;}

        /* CREDENTIALS */
        .credentials-card{background:#fff;border:1px solid #cfe4d8;border-radius:15px;
                          padding:24px;margin-bottom:22px;box-shadow:0 6px 25px rgba(23,32,51,.06);}
        .credentials-header{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
        .credentials-icon{width:48px;height:48px;border-radius:50%;background:var(--green-bg);
                          color:var(--green);display:flex;align-items:center;justify-content:center;
                          font-size:23px;font-weight:800;flex-shrink:0;}
        .credentials-header h2{margin:0 0 5px;color:var(--green);font-size:19px;}
        .credentials-header p{margin:0;color:var(--muted);font-size:13px;}

        .credentials-warning{background:#fff8df;border:1px solid #ead58b;color:#705900;
                             border-radius:10px;padding:13px 15px;font-size:13px;line-height:1.6;
                             margin-bottom:20px;}
        .credentials-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}
        .credential-item{background:#f7f9fc;border:1px solid #e3e8ef;border-radius:9px;
                         padding:13px 15px;}
        .credential-item span{display:block;font-size:11px;color:#778195;margin-bottom:5px;}
        .credential-item strong{display:block;font-size:15px;color:var(--navy);
                                word-break:break-word;}
        .credential-item.activation{background:#fff8df;border-color:#ead58b;
                                    grid-column:1/-1;text-align:center;}
        .credential-item.activation strong{font-size:30px;letter-spacing:8px;
                                           color:#7a5a00;font-weight:900;}
        .expiry{margin-top:15px;color:var(--muted);font-size:12px;text-align:center;}
        .credentials-actions{margin-top:18px;text-align:center;}
        .btn-print{border:none;background:var(--navy);color:#fff;padding:11px 22px;
                   border-radius:8px;font-weight:700;font-size:13.5px;cursor:pointer;
                   font-family:inherit;}

        @media (max-width:900px){
            .mobile-topbar{display:flex;}
            .main-content{margin-left:0;padding:calc(var(--topbar-h) + 20px) 16px 40px;}
            body.sidebar-collapsed .main-content{margin-left:0;}
        }
        @media (max-width:700px){
            .main-content{padding:calc(var(--topbar-h) + 14px) 14px 40px;}
            .page-header h1{font-size:22px;}
            .page-header p{font-size:12.5px;}
            .class-card{flex-direction:column;align-items:stretch;padding:16px;}
            .admission-box{width:100%;}
            .admission-number{font-size:19px;}
            .card{padding:18px;border-radius:10px;}

            .form-grid{grid-template-columns:1fr;gap:14px;}
            .form-group.full{grid-column:auto;}
            input,select,textarea{font-size:15px;padding:13px 14px;}

            .search-row{flex-direction:column;}
            .search-row input,.search-row button{width:100%;}
            .parent-row{flex-wrap:wrap;}
            .btn-use{width:100%;}

            .submit-area{flex-direction:column-reverse;}
            .submit-area .btn-primary,
            .submit-area .btn-ghost{width:100%;}

            .credentials-grid{grid-template-columns:1fr;}
            .credentials-card{padding:18px;}
            .credential-item.activation strong{font-size:24px;letter-spacing:5px;}
        }
        @media (max-width:400px){
            .page-header h1{font-size:19px;}
            .admission-number{font-size:17px;letter-spacing:.5px;}
            .card{padding:16px 14px;}
            .credential-item.activation strong{font-size:20px;letter-spacing:3px;}
        }
        @media (max-width:900px){
            @supports (padding:max(0px)){
                .main-content{padding-left:max(14px,env(safe-area-inset-left));
                              padding-right:max(14px,env(safe-area-inset-right));}
            }
        }
        @media print{
            body *{visibility:hidden;}
            .credentials-card,.credentials-card *{visibility:visible;}
            .credentials-card{position:absolute;left:0;top:0;width:100%;box-shadow:none;
                              border:1px solid #ccc;}
            .credentials-actions{display:none;}
        }
    </style>
</head>

<body>

<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Teacher</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Add Student';
$topbar_subtitle = 'Register a new student';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">
    <div class="page-wrapper">

        <div class="page-header">
            <h1>Add Student</h1>
            <p>First find the parent, then register the student.</p>
        </div>

        <!-- STEP INDICATOR -->
        <div class="steps">
            <div class="step <?= $mode === 'search' ? 'active' : 'done' ?>">
                <span class="num">1</span> Find Parent
            </div>
            <span class="step-arrow">→</span>
            <div class="step <?= $mode === 'new' ? 'active' : ($mode === 'existing' ? 'done' : '') ?>">
                <span class="num">2</span> Student & Parent Details
            </div>
            <span class="step-arrow">→</span>
            <div class="step">
                <span class="num">3</span> Done
            </div>
        </div>

        <!-- CLASS INFO -->
        <div class="class-card">
            <div>
                <h3>
                    <?= e($class_name) ?>
                    <?php if (!empty($stream)): ?> - <?= e($stream) ?><?php endif; ?>
                </h3>
                <p>
                    Class Level: <?= e($class_level) ?>
                    &nbsp; | &nbsp;
                    Academic Year: <?= e($academic_year) ?>
                </p>
            </div>
            <div class="admission-box">
                <small>Generated Admission Number</small>
                <div class="admission-number"><?= e($admission_no) ?></div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <!-- SUCCESS CARD -->
        <?php if ($parent_credentials): ?>
            <div class="credentials-card">

                <?php if (($parent_credentials['mode'] ?? 'new') === 'existing'): ?>

                    <div class="credentials-header">
                        <div class="credentials-icon">✓</div>
                        <div>
                            <h2>Student Linked Successfully</h2>
                            <p>Linked to an existing parent account.</p>
                        </div>
                    </div>

                    <div class="credentials-warning"
                         style="background:#eef6f0;border-color:#cfe5d7;color:#2f6b47;">
                        <strong>No new activation needed.</strong>
                        The parent already has an account. The student is now visible
                        on the parent's <em>My Children</em> page immediately.
                    </div>

                    <div class="credentials-grid">
                        <div class="credential-item">
                            <span>Student</span>
                            <strong><?= e($parent_credentials['student_name']) ?></strong>
                        </div>
                        <div class="credential-item">
                            <span>Admission Number</span>
                            <strong><?= e($parent_credentials['admission_no']) ?></strong>
                        </div>
                        <div class="credential-item">
                            <span>Parent</span>
                            <strong><?= e($parent_credentials['parent_name']) ?></strong>
                        </div>
                        <div class="credential-item">
                            <span>Parent Phone</span>
                            <strong><?= e($parent_credentials['parent_phone']) ?></strong>
                        </div>
                    </div>

                <?php else: ?>

                    <div class="credentials-header">
                        <div class="credentials-icon">✓</div>
                        <div>
                            <h2>Student Registered Successfully</h2>
                            <p>The parent account is waiting for activation.</p>
                        </div>
                    </div>

                    <div class="credentials-warning">
                        <strong>Important:</strong>
                        Give this activation code to the parent. They will use it with
                        their phone number to <strong>create their own password</strong>
                        on first login. This code will not be shown again.
                    </div>

                    <div class="credentials-grid">
                        <div class="credential-item">
                            <span>Student</span>
                            <strong><?= e($parent_credentials['student_name']) ?></strong>
                        </div>
                        <div class="credential-item">
                            <span>Admission Number</span>
                            <strong><?= e($parent_credentials['admission_no']) ?></strong>
                        </div>
                        <div class="credential-item">
                            <span>Parent</span>
                            <strong><?= e($parent_credentials['parent_name']) ?></strong>
                        </div>
                        <div class="credential-item">
                            <span>Phone / Login</span>
                            <strong><?= e($parent_credentials['parent_phone']) ?></strong>
                        </div>
                        <div class="credential-item activation">
                            <span>Activation Code</span>
                            <strong><?= e($parent_credentials['activation_code']) ?></strong>
                        </div>
                    </div>

                    <div class="expiry">
                        Activation code expires:
                        <strong><?= e($parent_credentials['expires_at']) ?></strong>
                    </div>

                    <div class="credentials-actions">
                        <button type="button" class="btn-print" onclick="window.print()">
                            Print Activation Code
                        </button>
                    </div>

                <?php endif; ?>

            </div>
        <?php endif; ?>

        <!-- STEP 1 -->
        <?php if ($mode === 'search'): ?>

            <div class="card">
                <h2 class="card-title">Find the Parent</h2>
                <p class="card-sub">
                    Search by phone number or name. If the parent already has an
                    account, use it — otherwise create a new one.
                </p>

                <form method="GET" action="" class="search-row">
                    <input
                        type="text"
                        name="search"
                        placeholder="Phone, first name, or last name…"
                        value="<?= e($search_query) ?>"
                        autofocus
                    >
                    <button type="submit">Search</button>
                </form>

                <?php if ($search_query !== '' && empty($search_results)): ?>
                    <div class="alert alert-error" style="margin-top:16px;margin-bottom:0;">
                        No parent found for "<strong><?= e($search_query) ?></strong>".
                        You can create a new parent account below.
                    </div>
                <?php endif; ?>

                <?php if (!empty($search_results)): ?>
                    <div class="results">
                        <?php foreach ($search_results as $p):
                            $initial = strtoupper(mb_substr($p['first_name'], 0, 1));
                            $pname = trim(
                                $p['first_name'] . ' ' .
                                ($p['middle_name'] ? $p['middle_name'] . ' ' : '') .
                                $p['last_name']
                            );
                        ?>
                            <div class="parent-row">
                                <div class="parent-avatar"><?= e($initial) ?></div>
                                <div class="parent-info">
                                    <div class="parent-name"><?= e($pname) ?></div>
                                    <div class="parent-meta"><?= e($p['phone'] ?: '—') ?></div>
                                    <div class="parent-children">
                                        <?= (int)$p['children_count'] ?> existing
                                        child<?= (int)$p['children_count'] === 1 ? '' : 'ren' ?>
                                    </div>
                                </div>
                                <form method="POST" action="" style="margin:0;">
                                    <input type="hidden" name="mode" value="existing">
                                    <input type="hidden" name="parent_id" value="<?= (int)$p['parent_id'] ?>">
                                    <button type="submit" class="btn-use">Use this parent →</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="divider">or</div>

                <form method="POST" action="" style="margin:0;">
                    <input type="hidden" name="mode" value="new">
                    <button type="submit" class="btn-ghost" style="width:100%;">
                        + Create a new parent account
                    </button>
                </form>
            </div>

        <!-- STEP 2A -->
        <?php elseif ($mode === 'existing' && $selected_parent): ?>

            <div class="card">
                <h2 class="card-title">Confirm the Parent</h2>

                <div class="selected-parent">
                    <?php $sel_initial = strtoupper(mb_substr($selected_parent['first_name'], 0, 1)); ?>
                    <div class="parent-avatar"><?= e($sel_initial) ?></div>
                    <div class="parent-info">
                        <div class="parent-name">
                            <?= e(trim(
                                $selected_parent['first_name'] . ' ' .
                                ($selected_parent['middle_name'] ? $selected_parent['middle_name'] . ' ' : '') .
                                $selected_parent['last_name']
                            )) ?>
                        </div>
                        <div class="parent-meta"><?= e($selected_parent['phone'] ?: '—') ?></div>
                    </div>
                    <a href="add_student.php" class="change-btn">Change parent</a>
                </div>

                <?php if (!empty($selected_parent_children)): ?>
                    <div style="font-size:12.5px;color:var(--muted);margin-bottom:4px;">
                        Already linked children
                    </div>
                    <div class="children-chips">
                        <?php foreach ($selected_parent_children as $sc): ?>
                            <span class="child-chip">
                                <span class="dot"></span>
                                <?= e($sc['full_name']) ?>
                                (<?= e($sc['admission_no']) ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="mode" value="existing">
                <input type="hidden" name="parent_id" value="<?= (int)$selected_parent['parent_id'] ?>">

                <div class="card">
                    <h2 class="card-title">Student Information</h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Admission Number</label>
                            <input type="text" value="<?= e($admission_no) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Class</label>
                            <input type="text" readonly
                                   value="<?= e($class_name . (!empty($stream) ? ' - ' . $stream : '')) ?>">
                        </div>
                        <div class="form-group full">
                            <label>Student Full Name <span class="required">*</span></label>
                            <input type="text" name="student_name"
                                   value="<?= e($student_name) ?>" required autofocus>
                        </div>
                        <div class="form-group">
                            <label>Gender <span class="required">*</span></label>
                            <select name="student_gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male"   <?= $student_gender === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $student_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth</label>
                            <input type="date" name="date_of_birth"
                                   value="<?= e($date_of_birth) ?>">
                        </div>
                        <div class="form-group">
                            <label>Admission Date</label>
                            <input type="date" name="admission_date"
                                   value="<?= e($admission_date) ?>">
                        </div>
                        <div class="form-group full">
                            <label>Relationship to Student <span class="required">*</span></label>
                            <select name="relationship" required>
                                <option value="Father"   <?= $relationship === 'Father'   ? 'selected' : '' ?>>Father</option>
                                <option value="Mother"   <?= $relationship === 'Mother'   ? 'selected' : '' ?>>Mother</option>
                                <option value="Guardian" <?= $relationship === 'Guardian' ? 'selected' : '' ?>>Guardian</option>
                                <option value="Other"    <?= $relationship === 'Other'    ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="submit-area">
                        <a href="add_student.php" class="btn-ghost">Cancel</a>
                        <button type="submit" class="btn-primary">
                            Register Student
                        </button>
                    </div>
                </div>
            </form>

        <!-- STEP 2B -->
        <?php elseif ($mode === 'new'): ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="mode" value="new">

                <div class="card">
                    <h2 class="card-title">Student Information</h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Admission Number</label>
                            <input type="text" value="<?= e($admission_no) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Class</label>
                            <input type="text" readonly
                                   value="<?= e($class_name . (!empty($stream) ? ' - ' . $stream : '')) ?>">
                        </div>
                        <div class="form-group full">
                            <label>Student Full Name <span class="required">*</span></label>
                            <input type="text" name="student_name"
                                   value="<?= e($student_name) ?>" required autofocus>
                        </div>
                        <div class="form-group">
                            <label>Gender <span class="required">*</span></label>
                            <select name="student_gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male"   <?= $student_gender === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $student_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth</label>
                            <input type="date" name="date_of_birth"
                                   value="<?= e($date_of_birth) ?>">
                        </div>
                        <div class="form-group">
                            <label>Admission Date</label>
                            <input type="date" name="admission_date"
                                   value="<?= e($admission_date) ?>">
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2 class="card-title">New Parent Account</h2>
                    <p class="card-sub">
                        These details will create the parent's login account.
                        They will receive an activation code to set their password.
                    </p>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>First Name <span class="required">*</span></label>
                            <input type="text" name="parent_first_name"
                                   value="<?= e($parent_first_name) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Middle Name</label>
                            <input type="text" name="parent_middle_name"
                                   value="<?= e($parent_middle_name) ?>">
                        </div>
                        <div class="form-group">
                            <label>Last Name <span class="required">*</span></label>
                            <input type="text" name="parent_last_name"
                                   value="<?= e($parent_last_name) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Gender <span class="required">*</span></label>
                            <select name="parent_gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male"   <?= $parent_gender === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $parent_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Phone Number <span class="required">*</span></label>
                            <input type="text" name="parent_phone"
                                   value="<?= e($parent_phone) ?>"
                                   placeholder="e.g. 0712345678" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="parent_email"
                                   value="<?= e($parent_email) ?>"
                                   placeholder="parent@example.com">
                        </div>
                        <div class="form-group">
                            <label>Relationship <span class="required">*</span></label>
                            <select name="relationship" required>
                                <option value="Father"   <?= $relationship === 'Father'   ? 'selected' : '' ?>>Father</option>
                                <option value="Mother"   <?= $relationship === 'Mother'   ? 'selected' : '' ?>>Mother</option>
                                <option value="Guardian" <?= $relationship === 'Guardian' ? 'selected' : '' ?>>Guardian</option>
                                <option value="Other"    <?= $relationship === 'Other'    ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Occupation</label>
                            <input type="text" name="parent_occupation"
                                   value="<?= e($parent_occupation) ?>">
                        </div>
                        <div class="form-group full">
                            <label>Address</label>
                            <textarea name="parent_address"><?= e($parent_address) ?></textarea>
                        </div>
                    </div>

                    <div class="submit-area">
                        <a href="add_student.php" class="btn-ghost">Cancel</a>
                        <button type="submit" class="btn-primary">
                            Create Parent & Register Student
                        </button>
                    </div>
                </div>
            </form>

        <?php endif; ?>

    </div>
</main>

<script>
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar(){
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');
    const s=document.querySelector('.teacher-sidebar,.admin-sidebar,#sidebar,.sidebar');
    if(s)s.classList.add('open');
    if(hamburgerBtn)hamburgerBtn.classList.add('active');
}
function closeSidebar(){
    sidebarOverlay.classList.remove('open');
    const s=document.querySelector('.teacher-sidebar,.admin-sidebar,#sidebar,.sidebar');
    if(s)s.classList.remove('open');
    if(hamburgerBtn)hamburgerBtn.classList.remove('active');
    document.body.classList.remove('no-scroll');
}
if(hamburgerBtn)hamburgerBtn.addEventListener('click',()=>{
    if(sidebarOverlay.classList.contains('open'))closeSidebar();
    else openSidebar();
});
if(sidebarOverlay)sidebarOverlay.addEventListener('click',closeSidebar);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSidebar();});
window.addEventListener('resize',()=>{if(window.innerWidth>900)closeSidebar();});
</script>

</body>
</html>