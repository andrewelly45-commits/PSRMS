<?php

session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';


/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$errors = [];
$success = '';

$first_name      = '';
$middle_name     = '';
$last_name       = '';
$gender          = '';
$email           = '';
$phone           = '';
$employee_no     = '';
$qualification   = '';
$specialization  = '';

$employment_status = 'active';
$assignment_type   = 'teacher';


/* Check if a headteacher already exists (for UI) */
$headteacher_exists = false;

$res = mysqli_query(
    $conn,
    "SELECT teacher_id FROM teachers
     WHERE assignment_type = 'headteacher' LIMIT 1"
);

if ($res && mysqli_num_rows($res) > 0) {
    $headteacher_exists = true;
}


/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Get Form Data */
    $first_name      = trim($_POST['first_name'] ?? '');
    $middle_name     = trim($_POST['middle_name'] ?? '');
    $last_name       = trim($_POST['last_name'] ?? '');
    $gender          = trim($_POST['gender'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $employee_no     = trim($_POST['employee_no'] ?? '');
    $qualification   = trim($_POST['qualification'] ?? '');
    $specialization  = trim($_POST['specialization'] ?? '');

    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $employment_status = $_POST['employment_status'] ?? 'active';
    $assignment_type   = $_POST['assignment_type'] ?? 'teacher';


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    // First Name
    if ($first_name === '') {
        $errors[] = 'First name is required.';
    } elseif (strlen($first_name) < 2) {
        $errors[] = 'First name must be at least 2 characters.';
    } elseif (strlen($first_name) > 150) {
        $errors[] = 'First name cannot exceed 150 characters.';
    } elseif (!preg_match("/^[a-zA-Z\s\-']+$/", $first_name)) {
        $errors[] = 'First name may only contain letters, spaces, hyphens and apostrophes.';
    }

    // Middle Name (optional)
    if ($middle_name !== '') {
        if (strlen($middle_name) > 150) {
            $errors[] = 'Middle name cannot exceed 150 characters.';
        } elseif (!preg_match("/^[a-zA-Z\s\-']+$/", $middle_name)) {
            $errors[] = 'Middle name may only contain letters, spaces, hyphens and apostrophes.';
        }
    }

    // Last Name
    if ($last_name === '') {
        $errors[] = 'Last name is required.';
    } elseif (strlen($last_name) < 2) {
        $errors[] = 'Last name must be at least 2 characters.';
    } elseif (strlen($last_name) > 150) {
        $errors[] = 'Last name cannot exceed 150 characters.';
    } elseif (!preg_match("/^[a-zA-Z\s\-']+$/", $last_name)) {
        $errors[] = 'Last name may only contain letters, spaces, hyphens and apostrophes.';
    }

    // Gender
    if ($gender === '') {
        $errors[] = 'Gender is required.';
    } elseif (!in_array($gender, ['male', 'female', 'other'], true)) {
        $errors[] = 'Invalid gender selected.';
    }

    // Email
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (strlen($email) > 150) {
        $errors[] = 'Email address cannot exceed 150 characters.';
    }

    // Phone (optional)
    if ($phone !== '') {
        if (preg_match("/[a-zA-Z]/", $phone)) {
            $errors[] = 'Phone number must not contain letters.';
        } elseif (!preg_match("/^[0-9\s\+\-\(\)]+$/", $phone)) {
            $errors[] = 'Phone number may only contain digits, spaces, and + - ( ) characters.';
        } elseif (strlen($phone) > 30) {
            $errors[] = 'Phone number cannot exceed 30 characters.';
        }
    }

    // Employee Number
    if ($employee_no === '') {
        $errors[] = 'Employee number is required.';
    } elseif (strlen($employee_no) > 20) {
        $errors[] = 'Employee number cannot exceed 20 characters.';
    } elseif (!preg_match("/^[a-zA-Z0-9\-]+$/", $employee_no)) {
        $errors[] = 'Employee number may only contain letters, digits and hyphens.';
    }

    // Qualification
    if ($qualification === '') {
        $errors[] = 'Qualification is required.';
    } elseif (strlen($qualification) > 100) {
        $errors[] = 'Qualification cannot exceed 100 characters.';
    }

    // Specialization (optional)
    if ($specialization !== '' && strlen($specialization) > 100) {
        $errors[] = 'Specialization cannot exceed 100 characters.';
    }

    // Password
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must contain at least 6 characters.';
    } elseif (strlen($password) > 255) {
        $errors[] = 'Password cannot exceed 255 characters.';
    }

    // Confirm Password
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    // Employment Status
    if (!in_array($employment_status, ['active', 'inactive'], true)) {
        $errors[] = 'Invalid employment status.';
    }

    // Assignment Type
    if (!in_array($assignment_type, ['teacher', 'academic', 'headteacher'], true)) {
        $errors[] = 'Invalid assignment type.';
    }


    /*
    |--------------------------------------------------------------------------
    | Only ONE Headteacher Allowed
    |--------------------------------------------------------------------------
    */

    if (empty($errors) && $assignment_type === 'headteacher') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT teacher_id FROM teachers
             WHERE assignment_type = 'headteacher'
             LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) > 0) {
                $errors[] = 'A headteacher already exists. Only one headteacher is allowed per school.';
            }
            mysqli_stmt_close($stmt);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Email
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT user_id FROM users WHERE email = ? LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) > 0) {
                $errors[] = 'A user account with this email already exists.';
            }
            mysqli_stmt_close($stmt);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Employee Number
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT teacher_id FROM teachers WHERE employee_no = ? LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $employee_no);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) > 0) {
                $errors[] = 'This employee number is already assigned to another teacher.';
            }
            mysqli_stmt_close($stmt);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Create Account
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        mysqli_begin_transaction($conn);

        try {

            /* Insert User */
            $role = 'teacher';

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO users
                 (first_name, middle_name, last_name, gender,
                  email, phone, password, role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception('Unable to prepare user account query.');
            }

            mysqli_stmt_bind_param(
                $stmt,
                'ssssssss',
                $first_name,
                $middle_name,
                $last_name,
                $gender,
                $email,
                $phone,
                $password_hash,
                $role
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_stmt_error($stmt));
            }

            $user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);


            /* Insert Teacher */
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO teachers
                 (user_id, employee_no, qualification, specialization,
                  employment_status, assignment_type)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception('Unable to prepare teacher query.');
            }

            mysqli_stmt_bind_param(
                $stmt,
                'isssss',
                $user_id,
                $employee_no,
                $qualification,
                $specialization,
                $employment_status,
                $assignment_type
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_stmt_error($stmt));
            }

            mysqli_stmt_close($stmt);

            mysqli_commit($conn);

            header('Location: teachers.php?success=teacher_created');
            exit;

        } catch (Exception $e) {

            mysqli_rollback($conn);

            /* Friendly message if DB blocked duplicate headteacher */
            if (
                strpos($e->getMessage(), 'uniq_headteacher') !== false
                || strpos($e->getMessage(), 'Only one headteacher') !== false
            ) {
                $errors[] = 'A headteacher already exists. Only one headteacher is allowed per school.';
            } else {
                $errors[] = 'Teacher could not be created: ' . $e->getMessage();
            }
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Teacher | PSRMS</title>

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
            --border: #e1e4e9;
            --red: #a04b4b;
            --red-bg: #fbefef;
            --green: #397154;
            --green-bg: #edf6f0;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 255px;
            padding: 105px 30px 40px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 750;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 39px;
            padding: 0 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--white);
            color: var(--navy);
            text-decoration: none;
            font-size: 11px;
            font-weight: 650;
        }

        .back-button:hover { border-color: var(--gold); }

        .alert {
            border-radius: 7px;
            padding: 13px 15px;
            margin-bottom: 20px;
            font-size: 11px;
        }

        .alert-error {
            background: var(--red-bg);
            border: 1px solid #efd2d2;
            color: var(--red);
        }

        .alert-error ul { padding-left: 17px; }
        .alert-error li { margin-bottom: 3px; }

        .form-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow: hidden;
            max-width: 1000px;
        }

        .section-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
        }

        .section-header h2 { color: var(--navy); font-size: 14px; }
        .section-header p { color: var(--muted); font-size: 10px; margin-top: 4px; }

        .form-body { padding: 24px; }

        .form-section { margin-bottom: 28px; }
        .form-section:last-child { margin-bottom: 0; }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--navy);
            font-size: 12px;
            font-weight: 750;
            margin-bottom: 16px;
        }

        .section-number {
            width: 23px;
            height: 23px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--navy);
            color: var(--gold-light);
            font-size: 10px;
            font-weight: 750;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px 18px;
        }

        .form-group { min-width: 0; }
        .form-group.full { grid-column: 1 / -1; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .required { color: var(--red); }

        .form-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fdfdfd;
            color: var(--text);
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            outline: none;
            transition: .2s ease;
        }

        textarea.form-control {
            height: 90px;
            padding: 11px 12px;
            resize: vertical;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.08);
        }

        .help-text {
            color: var(--muted);
            font-size: 9px;
            margin-top: 5px;
        }

        .help-text.warn { color: var(--red); }

        .password-wrapper { position: relative; }

        .password-wrapper .form-control { padding-right: 70px; }

        .show-password {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            font-size: 9px;
            font-weight: 700;
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 25px 0;
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 17px 22px;
            background: #fafaf8;
            border-top: 1px solid var(--border);
        }

        .cancel-button {
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 17px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--white);
            color: var(--muted);
            text-decoration: none;
            font-size: 11px;
            font-weight: 650;
        }

        .save-button {
            height: 40px;
            border: none;
            border-radius: 6px;
            padding: 0 20px;
            background: var(--navy);
            color: var(--white);
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .save-button:hover { background: var(--navy-dark); }

        @media (max-width: 900px) {
            .main-content { margin-left: 0; padding: 100px 18px 30px; }
        }

        @media (max-width: 650px) {
            .page-header { align-items: flex-start; flex-direction: column; gap: 12px; }
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .form-body { padding: 18px; }
            .form-footer { padding: 15px 18px; }
        }
    </style>
</head>

<body>

<?php include 'admin_sidebar.php'; ?>
<?php include 'admin_header.php'; ?>

<main class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Add Teacher</h1>
            <p>Create a teacher profile and login account.</p>
        </div>

        <a href="teachers.php" class="back-button">
            ← Back to Teachers
        </a>
    </div>


    <!-- ERRORS -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <!-- FORM -->
    <form method="POST" action="add_teacher.php" class="form-card" autocomplete="off">

        <div class="section-header">
            <h2>Teacher Registration</h2>
            <p>Enter the teacher's personal, employment and login information.</p>
        </div>

        <div class="form-body">

            <!-- =============================================
                 1. PERSONAL INFORMATION
            ============================================== -->
            <section class="form-section">

                <div class="form-section-title">
                    <span class="section-number">1</span>
                    Personal Information
                </div>

                <div class="form-grid">

                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="first_name"
                            class="form-control"
                            value="<?php echo htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Enter first name"
                            maxlength="150"
                            oninput="blockDigits(this)" required>
                    </div>

                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" id="middle_name"
                            class="form-control"
                            value="<?php echo htmlspecialchars($middle_name, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Enter middle name (optional)"
                            maxlength="150"
                            oninput="blockDigits(this)">
                    </div>

                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" id="last_name"
                            class="form-control"
                            value="<?php echo htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Enter last name"
                            maxlength="150"
                            oninput="blockDigits(this)" required>
                    </div>

                    <!-- NEW: GENDER -->
                    <div class="form-group">
                        <label>Gender <span class="required">*</span></label>
                        <select name="gender" class="form-control" required>
                            <option value="" disabled
                                <?php echo $gender === '' ? 'selected' : ''; ?>>
                                Select gender
                            </option>
                            <option value="male"
                                <?php echo $gender === 'male' ? 'selected' : ''; ?>>
                                Male
                            </option>
                            <option value="female"
                                <?php echo $gender === 'female' ? 'selected' : ''; ?>>
                                Female
                            </option>
                            <option value="other"
                                <?php echo $gender === 'other' ? 'selected' : ''; ?>>
                                Other
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control"
                            value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="teacher@example.com"
                            maxlength="150" required>
                        <div class="help-text">This email will be used for login.</div>
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="phone"
                            class="form-control"
                            value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. 0712 345 678"
                            maxlength="30"
                            oninput="blockLetters(this)">
                    </div>

                </div>
            </section>


            <div class="divider"></div>


            <!-- =============================================
                 2. EMPLOYMENT INFORMATION
            ============================================== -->
            <section class="form-section">

                <div class="form-section-title">
                    <span class="section-number">2</span>
                    Employment Information
                </div>

                <div class="form-grid">

                    <div class="form-group">
                        <label>Employee Number <span class="required">*</span></label>
                        <input type="text" name="employee_no" class="form-control"
                            value="<?php echo htmlspecialchars($employee_no, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. TCH001"
                            maxlength="20" required>
                    </div>

                    <div class="form-group">
                        <label>Employment Status</label>
                        <select name="employment_status" class="form-control">
                            <option value="active"
                                <?php echo $employment_status === 'active' ? 'selected' : ''; ?>>
                                Active
                            </option>
                            <option value="inactive"
                                <?php echo $employment_status === 'inactive' ? 'selected' : ''; ?>>
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Qualification <span class="required">*</span></label>
                        <input type="text" name="qualification" class="form-control"
                            value="<?php echo htmlspecialchars($qualification, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. Diploma in Education"
                            maxlength="100" required>
                    </div>

                    <div class="form-group">
                        <label>Specialization</label>
                        <input type="text" name="specialization" class="form-control"
                            value="<?php echo htmlspecialchars($specialization, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. Mathematics"
                            maxlength="100">
                    </div>

                    <!-- NEW: ASSIGNMENT TYPE -->
                    <div class="form-group">
                        <label>Assignment Type <span class="required">*</span></label>
                        <select name="assignment_type" class="form-control" required>
                            <option value="teacher"
                                <?php echo $assignment_type === 'teacher' ? 'selected' : ''; ?>>
                                Teacher
                            </option>
                            <option value="academic"
                                <?php echo $assignment_type === 'academic' ? 'selected' : ''; ?>>
                                Academic
                            </option>
                            <?php if (!$headteacher_exists): ?>
                                <option value="headteacher"
                                    <?php echo $assignment_type === 'headteacher' ? 'selected' : ''; ?>>
                                    Headteacher
                                </option>
                            <?php endif; ?>
                        </select>

                        <?php if ($headteacher_exists): ?>
                            <div class="help-text warn">
                                A headteacher is already assigned. Only one is allowed.
                            </div>
                        <?php else: ?>
                            <div class="help-text">
                                Only one headteacher can be assigned to the school.
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </section>


            <div class="divider"></div>


            <!-- =============================================
                 3. LOGIN ACCOUNT
            ============================================== -->
            <section class="form-section">

                <div class="form-section-title">
                    <span class="section-number">3</span>
                    Login Account
                </div>

                <div class="form-grid">

                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password"
                                class="form-control"
                                placeholder="Minimum 6 characters"
                                maxlength="255" required>
                            <button type="button" class="show-password"
                                onclick="togglePassword('password', this)">
                                SHOW
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password"
                                class="form-control"
                                placeholder="Repeat password"
                                maxlength="255" required>
                            <button type="button" class="show-password"
                                onclick="togglePassword('confirm_password', this)">
                                SHOW
                            </button>
                        </div>
                    </div>

                </div>
            </section>

        </div>


        <!-- FOOTER -->
        <div class="form-footer">
            <a href="teachers.php" class="cancel-button">Cancel</a>
            <button type="submit" class="save-button">
                Create Teacher Account
            </button>
        </div>

    </form>

</main>


<script>
function togglePassword(fieldId, button) {
    const field = document.getElementById(fieldId);

    if (field.type === 'password') {
        field.type = 'text';
        button.textContent = 'HIDE';
    } else {
        field.type = 'password';
        button.textContent = 'SHOW';
    }
}

function blockDigits(input) {
    input.value = input.value.replace(/[0-9]/g, '');
}

function blockLetters(input) {
    input.value = input.value.replace(/[a-zA-Z]/g, '');
}
</script>

</body>
</html>