<?php

session_start();

require_once '../includes/db.php';


/*
|--------------------------------------------------------------------------
| Only POST requests are allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get login information
|--------------------------------------------------------------------------
*/

$identifier = trim($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';


/*
|--------------------------------------------------------------------------
| Validate input
|--------------------------------------------------------------------------
*/

if ($identifier === '') {

    $_SESSION['login_error'] = "Please enter your phone number or email.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Find user by email OR phone
|--------------------------------------------------------------------------
|
| We intentionally search by both.
|
| Example:
|
| Email:
| parent@example.com
|
| Phone:
| 0712345678
|
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
            status,
            activation_code_hash,
            activation_expires_at
        FROM users
        WHERE email = ?
           OR phone = ?
        LIMIT 1";


$stmt = mysqli_prepare($conn, $sql);


if (!$stmt) {

    $_SESSION['login_error'] =
        "Unable to process your request. Please try again.";

    header("Location: ../login.php");
    exit;
}


mysqli_stmt_bind_param(
    $stmt,
    "ss",
    $identifier,
    $identifier
);


mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| Account does not exist
|--------------------------------------------------------------------------
*/

if (!$user) {

    /*
    |--------------------------------------------------------------------------
    | Generic message
    |--------------------------------------------------------------------------
    |
    | We don't reveal whether an email/phone exists.
    |
    */

    $_SESSION['login_error'] =
        "Invalid phone number/email or password.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| INACTIVE PARENT ACCOUNT
|--------------------------------------------------------------------------
|
| A parent account may have been created by the class teacher
| but not activated by the parent yet.
|
|--------------------------------------------------------------------------
*/

if (
    $user['role'] === 'parent' &&
    $user['status'] !== 'active'
) {

    /*
    |--------------------------------------------------------------------------
    | Store temporary information for activation page
    |--------------------------------------------------------------------------
    |
    | We do NOT log the parent in.
    |
    */

    $_SESSION['activation_user_id'] = $user['user_id'];

    /*
    |--------------------------------------------------------------------------
    | Redirect to activation page
    |--------------------------------------------------------------------------
    */

    header("Location: ../parent/activate.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Other inactive accounts
|--------------------------------------------------------------------------
|
| Admin/teacher/etc. should not be allowed to login while inactive.
|
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
| Password is required for active accounts
|--------------------------------------------------------------------------
*/

if ($password === '') {

    $_SESSION['login_error'] =
        "Please enter your password.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Verify password
|--------------------------------------------------------------------------
*/

if (!password_verify($password, $user['password'])) {

    $_SESSION['login_error'] =
        "Invalid phone number/email or password.";

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Regenerate session ID
|--------------------------------------------------------------------------
|
| Prevents session fixation.
|
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);


/*
|--------------------------------------------------------------------------
| Build full name
|--------------------------------------------------------------------------
*/

$full_name = trim(
    ($user['first_name'] ?? '') . ' ' .
    ($user['middle_name'] ?? '') . ' ' .
    ($user['last_name'] ?? '')
);


/*
|--------------------------------------------------------------------------
| Store authenticated user
|--------------------------------------------------------------------------
*/

$_SESSION['logged_in']  = true;

$_SESSION['user_id']    = $user['user_id'];

$_SESSION['full_name']  = $full_name;

$_SESSION['email']      = $user['email'];

$_SESSION['role']       = $user['role'];

$_SESSION['gender']     = $user['gender'];

$_SESSION['phone']      = $user['phone'];

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

        /*
        |--------------------------------------------------------------------------
        | Invalid role
        |--------------------------------------------------------------------------
        */

        session_unset();
        session_destroy();

        session_start();

        $_SESSION['login_error'] =
            "Your account role is not configured correctly. Please contact the administrator.";

        header("Location: ../login.php");
        exit;
}