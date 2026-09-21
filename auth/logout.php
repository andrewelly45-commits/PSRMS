<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);


// ============================================
// CLEAR SESSION
// ============================================
$_SESSION = [];

// Unset session variables
session_unset();

// Destroy the session
session_destroy();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login
header("Location: ../index.php");
exit();
?>