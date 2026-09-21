<?php

session_start();

require_once '../includes/db.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit;
}


$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';


/*
|--------------------------------------------------------------------------
| Validate input
|--------------------------------------------------------------------------
*/

if ($email === '' || $password === '') {

    $_SESSION['login_error'] = "Please enter your email and password.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Find user
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            user_id,
            first_name,
            middle_name,
            last_name,
            email,
            password,
            role,
            gender,
            phone,
            profile_pic,
            status
        FROM users
        WHERE email = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {

    $_SESSION['login_error'] = "Unable to process your request.";

    header("Location: ../login.php");
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $email);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| Check whether user exists
|--------------------------------------------------------------------------
*/

if (!$user) {

    $_SESSION['login_error'] = "Invalid email or password.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Check account status
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {

    $_SESSION['login_error'] =
        "Your account is currently inactive. Please contact the school administrator.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Verify password
|--------------------------------------------------------------------------
*/

if (!password_verify($password, $user['password'])) {

    $_SESSION['login_error'] = "Invalid email or password.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Regenerate session ID
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);


/*
|--------------------------------------------------------------------------
| Store authenticated user
|--------------------------------------------------------------------------
*/

$_SESSION['logged_in'] = true;

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role'];
$_SESSION['gender'] = $user['gender'];
$_SESSION['phone'] = $user['phone'];
$_SESSION['profile_pic'] = $user['profile_pic'];


/*
|--------------------------------------------------------------------------
| Redirect according to role
|--------------------------------------------------------------------------
*/

switch ($user['role']) {

    case 'admin':

        header("Location: ../admin/dashboard.php");
        exit;


    case 'teacher':

        header("Location: ../teacher/dashboard.php");
        exit;


    case 'academic':

        header("Location: ../academic/dashboard.php");
        exit;


    case 'parent':

        header("Location: ../parent/dashboard.php");
        exit;


    default:

        // Remove authentication if role is invalid
        session_unset();
        session_destroy();

        session_start();

        $_SESSION['login_error'] =
            "Your account role is not configured correctly. Please contact the administrator.";

        header("Location: ../login.php");
        exit;
}