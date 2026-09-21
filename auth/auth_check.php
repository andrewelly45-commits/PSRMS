<?php

/*
|--------------------------------------------------------------------------
| PSRMS Authentication & Authorization Check
|--------------------------------------------------------------------------
|
| Usage:
|
| require_once '../auth/auth_check.php';
|
| For a specific role:
|
| require_once '../auth/auth_check.php';
| requireRole('admin');
|
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| Prevent browser from caching protected pages
|--------------------------------------------------------------------------
*/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");


/*
|--------------------------------------------------------------------------
| Check whether user is logged in
|--------------------------------------------------------------------------
*/

function requireLogin()
{
    if (
        !isset($_SESSION['logged_in']) ||
        $_SESSION['logged_in'] !== true ||
        !isset($_SESSION['user_id']) ||
        !isset($_SESSION['role'])
    ) {

        header("Location: ../login.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Check user role
|--------------------------------------------------------------------------
*/

function requireRole($allowedRoles)
{
    requireLogin();

    // Convert a single role into an array
    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    /*
    |--------------------------------------------------------------------------
    | Check whether current user's role is allowed
    |--------------------------------------------------------------------------
    */

    if (!in_array($_SESSION['role'], $allowedRoles, true)) {

        http_response_code(403);

        echo "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>

            <title>Access Denied | PSRMS</title>

            <style>

                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                body {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 25px;
                    background: #f7f5ef;
                    font-family: 'Segoe UI', Arial, sans-serif;
                    color: #263044;
                }

                .error-box {
                    width: 100%;
                    max-width: 500px;
                    background: #ffffff;
                    padding: 45px;
                    text-align: center;
                    border-radius: 14px;
                    border: 1px solid #e7e8ec;
                    box-shadow: 0 20px 50px rgba(16, 24, 43, .10);
                }

                .icon {
                    width: 60px;
                    height: 60px;
                    margin: 0 auto 22px;
                    border-radius: 50%;
                    background: #17233c;
                    color: #e2c65a;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 25px;
                    font-weight: 700;
                }

                h1 {
                    color: #17233c;
                    font-size: 26px;
                    margin-bottom: 12px;
                }

                p {
                    color: #747d8e;
                    font-size: 14px;
                    line-height: 1.7;
                    margin-bottom: 25px;
                }

                a {
                    display: inline-block;
                    padding: 12px 22px;
                    background: #17233c;
                    color: #ffffff;
                    text-decoration: none;
                    border-radius: 7px;
                    font-size: 13px;
                    font-weight: 650;
                }

                a:hover {
                    background: #10182b;
                }

            </style>
        </head>

        <body>

            <div class='error-box'>

                <div class='icon'>
                    !
                </div>

                <h1>
                    Access Denied
                </h1>

                <p>
                    You do not have permission to access this page.
                    Please return to your dashboard or contact the
                    school administrator if you believe this is an error.
                </p>

                <a href='../index.php'>
                    Return to Portal
                </a>

            </div>

        </body>
        </html>
        ";

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Get current logged-in user
|--------------------------------------------------------------------------
*/

function currentUser()
{
    requireLogin();

    return [
        'user_id'    => $_SESSION['user_id'] ?? null,
        'full_name'  => $_SESSION['full_name'] ?? null,
        'email'      => $_SESSION['email'] ?? null,
        'role'       => $_SESSION['role'] ?? null,
        'gender'     => $_SESSION['gender'] ?? null,
        'phone'      => $_SESSION['phone'] ?? null,
        'profile_pic'=> $_SESSION['profile_pic'] ?? null
    ];
}


/*
|--------------------------------------------------------------------------
| Check current user's role
|--------------------------------------------------------------------------
*/

function hasRole($role)
{
    requireLogin();

    return isset($_SESSION['role']) &&
           $_SESSION['role'] === $role;
}


/*
|--------------------------------------------------------------------------
| Check whether current user is one of several roles
|--------------------------------------------------------------------------
*/

function hasAnyRole($roles)
{
    requireLogin();

    if (!is_array($roles)) {
        $roles = [$roles];
    }

    return in_array($_SESSION['role'], $roles, true);
}