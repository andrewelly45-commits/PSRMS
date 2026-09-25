<?php

session_start();

require_once '../includes/db.php';

$error   = '';
$success = '';
$step    = 1;   // 1 = enter phone + code, 2 = create password, 3 = done

$phone = '';

/*
|--------------------------------------------------------------------------
| Track Pending Activation User Between Steps
|--------------------------------------------------------------------------
*/

$pending_user_id = (int) ($_SESSION['pending_activation_user_id'] ?? 0);

/*
|==========================================================================
| STEP 2 — SET NEW PASSWORD
|==========================================================================
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_password') {

    $step = 2;

    if ($pending_user_id <= 0) {

        $error = "Your activation session has expired. Please enter the code again.";
        $step  = 1;

    } else {

        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (strlen($password) < 8) {

            $error = "Password must be at least 8 characters.";

        } elseif ($password !== $password2) {

            $error = "Passwords do not match.";

        } else {

            /* Re-check the account is still pending activation */
            $stmt = mysqli_prepare(
                $conn,
                "SELECT user_id
                 FROM users
                 WHERE user_id = ?
                   AND role = 'parent'
                   AND account_activated = 0
                 LIMIT 1"
            );

            if (!$stmt) {

                $error = "Unable to process activation.";

            } else {

                mysqli_stmt_bind_param($stmt, 'i', $pending_user_id);
                mysqli_stmt_execute($stmt);
                $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if (!$u) {

                    $error = "This account is not awaiting activation.";
                    $step  = 1;
                    unset($_SESSION['pending_activation_user_id']);

                } else {

                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    /*
                    |------------------------------------------------------------------
                    | NOTE: activated_at is intentionally NOT set here because it
                    | may not exist in older schemas. Add it via:
                    |
                    |   ALTER TABLE users ADD COLUMN activated_at DATETIME NULL;
                    |
                    | then include it in the UPDATE below if you want it.
                    |------------------------------------------------------------------
                    */
                    $update = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET
                            password                = ?,
                            account_activated       = 1,
                            status                  = 'active',
                            activation_code_hash    = NULL,
                            activation_expires_at   = NULL,
                            must_change_password    = 0
                         WHERE user_id = ?
                           AND role = 'parent'
                           AND account_activated = 0"
                    );

                    if (!$update) {

                        $error = "Unable to save the new password: " . mysqli_error($conn);

                    } else {

                        mysqli_stmt_bind_param($update, 'si', $hash, $pending_user_id);

                        if (mysqli_stmt_execute($update) && mysqli_stmt_affected_rows($update) > 0) {

                            mysqli_stmt_close($update);

                            unset($_SESSION['pending_activation_user_id']);

                            $success = "Your account is now active. You can log in with your new password.";
                            $step    = 3;

                        } else {

                            $error = "Account activation failed. Please try again.";
                            mysqli_stmt_close($update);
                        }
                    }
                }
            }
        }
    }
}

/*
|==========================================================================
| STEP 1 — VERIFY PHONE + ACTIVATION CODE
|==========================================================================
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_code') {

    $phone           = trim($_POST['phone'] ?? '');
    $activation_code = trim($_POST['activation_code'] ?? '');

    if ($phone === '') {

        $error = "Please enter your phone number.";

    } elseif ($activation_code === '') {

        $error = "Please enter your activation code.";

    } elseif (!preg_match('/^\d{6}$/', $activation_code)) {

        $error = "Activation code must contain exactly 6 digits.";

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                user_id,
                account_activated,
                activation_code_hash,
                activation_expires_at
             FROM users
             WHERE phone = ?
               AND role = 'parent'
             LIMIT 1"
        );

        if (!$stmt) {

            $error = "Unable to process activation.";

        } else {

            mysqli_stmt_bind_param($stmt, 's', $phone);
            mysqli_stmt_execute($stmt);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$user) {

                $error = "Invalid phone number or activation code.";

            } elseif ((int) $user['account_activated'] === 1) {

                $error = "This account has already been activated.";

            } elseif (empty($user['activation_code_hash'])) {

                $error = "This account does not have a valid activation code.";

            } elseif (
                empty($user['activation_expires_at']) ||
                strtotime($user['activation_expires_at']) < time()
            ) {

                $error = "The activation code has expired. Please contact the school.";

            } elseif (!password_verify($activation_code, $user['activation_code_hash'])) {

                $error = "Invalid phone number or activation code.";

            } else {

                /* Code verified → move to step 2 */
                $pending_user_id = (int) $user['user_id'];
                $_SESSION['pending_activation_user_id'] = $pending_user_id;
                $step = 2;
            }
        }
    }
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#172033"
    >

    <title>
        Activate Parent Account | PSRMS
    </title>

    <style>

        * { box-sizing: border-box; }

        :root {
            --navy: #172033;
            --navy-dark: #10182b;
            --gold: #c9a227;
            --cream: #f4f7fb;
            --white: #ffffff;
            --text: #172033;
            --muted: #687386;
            --border: #e1e6ed;
            --green: #17663c;
            --green-bg: #e8f7ef;
            --red: #a12626;
            --red-bg: #fff0f0;
        }

        html, body { overflow-x: hidden; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--cream);
            font-family: "Segoe UI", Inter, Arial, sans-serif;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-text-size-adjust: 100%;
        }

        .activation-wrapper {
            width: 100%;
            max-width: 460px;
        }

        .brand {
            text-align: center;
            margin-bottom: 20px;
        }

        .brand h1 {
            margin: 0;
            color: var(--navy);
            font-size: 28px;
            letter-spacing: .5px;
        }

        .brand span { color: var(--gold); }

        .brand p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .card {
            background: var(--white);
            border-radius: 16px;
            padding: 30px;
            border: 1px solid var(--border);
            box-shadow: 0 12px 35px rgba(23,32,51,.08);
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 22px;
            color: var(--navy);
        }

        .card-description {
            margin: 0 0 24px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .alert {
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .alert.error {
            background: var(--red-bg);
            border: 1px solid #f0c2c2;
            color: var(--red);
        }

        .alert.success {
            background: var(--green-bg);
            border: 1px solid #bfe7cf;
            color: var(--green);
        }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d8dfe8;
            border-radius: 9px;
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: .15s ease;
            background: #fff;
            color: var(--text);
        }

        input:focus {
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(23,32,51,.08);
        }

        .code-input {
            letter-spacing: 5px;
            text-align: center;
            font-size: 20px;
            font-weight: 800;
        }

        /* =========================================================
           PASSWORD FIELD WITH EYE TOGGLE
        ========================================================== */
        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 48px;   /* leave room for the eye button */
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: .15s ease;
            padding: 0;
        }

        .toggle-password:hover {
            background: #f0f3f8;
            color: var(--navy);
        }

        .toggle-password:focus-visible {
            outline: 2px solid var(--gold);
            outline-offset: 1px;
        }

        .toggle-password svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Hide the "eye-off" variant by default; JS toggles a class */
        .toggle-password .icon-eye-off { display: none; }
        .toggle-password.is-visible .icon-eye     { display: none; }
        .toggle-password.is-visible .icon-eye-off { display: block; }

        /* =========================================================
           BUTTON
        ========================================================== */
        button.btn {
            width: 100%;
            border: none;
            background: var(--navy);
            color: white;
            padding: 14px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: .15s ease;
        }

        button.btn:hover { background: var(--navy-dark); }
        button.btn:active { transform: scale(.99); }

        /* =========================================================
           SUCCESS / STEP 3
        ========================================================== */
        .success-box {
            text-align: center;
            padding: 8px 0 4px;
        }

        .success-box .icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: var(--green-bg);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: 800;
        }

        .success-box h2 {
            margin: 0 0 10px;
            color: var(--green);
            font-size: 20px;
        }

        .success-box p {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.6;
        }

        .success-box .btn-login {
            display: inline-block;
            margin-top: 22px;
            padding: 13px 26px;
            background: var(--navy);
            color: #fff;
            text-decoration: none;
            border-radius: 9px;
            font-weight: 700;
            font-size: 14px;
        }

        .success-box .btn-login:hover { background: var(--navy-dark); }

        /* =========================================================
           STEP 2 HEADER
        ========================================================== */
        .step-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
        }

        .step-header .check {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: var(--green-bg);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .step-header h2 {
            margin: 0;
            color: var(--navy);
            font-size: 19px;
        }

        .step-header p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12.5px;
        }

        /* =========================================================
           HELP / LINKS
        ========================================================== */
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: var(--muted);
        }

        .login-link a {
            color: var(--navy);
            font-weight: 700;
            text-decoration: none;
        }

        .help {
            margin-top: 18px;
            padding: 12px 14px;
            background: #f7f9fc;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        /* =========================================================
           MOBILE
        ========================================================== */
        @media (max-width: 480px) {

            body { padding: 14px; }

            .card { padding: 22px 18px; }

            .brand h1 { font-size: 24px; }
            .brand p  { font-size: 12px; }

            .card h2  { font-size: 19px; }

            .code-input {
                font-size: 18px;
                letter-spacing: 4px;
            }

            input {
                font-size: 16px;    /* prevent iOS zoom on focus */
                padding: 14px 13px;
            }

            .password-wrap input {
                padding-right: 46px;
            }

            button.btn {
                padding: 15px;
                font-size: 15px;
            }

            .step-header { gap: 12px; }
            .step-header .check {
                width: 42px;
                height: 42px;
                font-size: 20px;
            }
            .step-header h2 { font-size: 17px; }
        }

        @media (max-width: 360px) {
            .brand h1 { font-size: 22px; }
            .code-input { font-size: 17px; letter-spacing: 3px; }
        }

        @media (max-width: 480px) {
            @supports (padding: max(0px)) {
                body {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                }
            }
        }

    </style>

</head>

<body>

<div class="activation-wrapper">

    <div class="brand">
        <h1>PSR<span>MS</span></h1>
        <p>Primary School Records Management System</p>
    </div>

    <div class="card">

        <?php if ($step === 3): ?>

            <!-- =========================================================
                 STEP 3 — DONE
            ========================================================== -->
            <div class="success-box">

                <div class="icon">✓</div>

                <h2>Account Activated</h2>

                <p><?= htmlspecialchars($success) ?></p>

                <p>
                    Use your phone number and the password you just
                    created to log in.
                </p>

                <a href="../login.php" class="btn-login">
                    Go to Login
                </a>

            </div>

        <?php elseif ($step === 2): ?>

            <!-- =========================================================
                 STEP 2 — CREATE NEW PASSWORD
            ========================================================== -->
            <div class="step-header">
                <div class="check">✓</div>
                <div>
                    <h2>Create Your Password</h2>
                    <p>Activation code verified</p>
                </div>
            </div>

            <p class="card-description">
                Choose a password with at least 8 characters.
                You'll use it together with your phone number
                to log in.
            </p>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">

                <input type="hidden" name="action" value="set_password">

                <div class="form-group">
                    <label>New Password</label>

                    <div class="password-wrap">
                        <input
                            type="password"
                            name="password"
                            id="password"
                            minlength="8"
                            autocomplete="new-password"
                            autofocus
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="password"
                            aria-label="Show password"
                        >
                            <svg class="icon-eye" viewBox="0 0 24 24">
                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24">
                                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.7 20.7 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c7 0 11 7 11 7a20.75 20.75 0 0 1-3.17 4.19"/>
                                <path d="M1 1l22 22"/>
                                <path d="M9.5 9.5a3 3 0 0 0 4.24 4.24"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>

                    <div class="password-wrap">
                        <input
                            type="password"
                            name="password2"
                            id="password2"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="password2"
                            aria-label="Show password"
                        >
                            <svg class="icon-eye" viewBox="0 0 24 24">
                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24">
                                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.7 20.7 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c7 0 11 7 11 7a20.75 20.75 0 0 1-3.17 4.19"/>
                                <path d="M1 1l22 22"/>
                                <path d="M9.5 9.5a3 3 0 0 0 4.24 4.24"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn">
                    Set Password &amp; Activate
                </button>

            </form>

        <?php else: ?>

            <!-- =========================================================
                 STEP 1 — ENTER PHONE + ACTIVATION CODE
            ========================================================== -->
            <h2>Activate Parent Account</h2>

            <p class="card-description">
                Enter the phone number registered by the school
                and the 6-digit activation code you received.
            </p>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">

                <input type="hidden" name="action" value="verify_code">

                <div class="form-group">
                    <label>Phone Number</label>
                    <input
                        type="text"
                        name="phone"
                        value="<?= htmlspecialchars($phone) ?>"
                        placeholder="0712345678"
                        autocomplete="tel"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Activation Code</label>
                    <input
                        type="text"
                        name="activation_code"
                        class="code-input"
                        placeholder="123456"
                        maxlength="6"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        required
                    >
                </div>

                <button type="submit" class="btn">
                    Verify Code
                </button>

            </form>

            <div class="help">
                <strong>Don't have an activation code?</strong><br>
                Contact the school administration or the
                class teacher who registered your child.
            </div>

            <div class="login-link">
                Already activated?
                <a href="../login.php">Login here</a>
            </div>

        <?php endif; ?>

    </div>

</div>


<script>
/* =========================================================================
   PASSWORD VISIBILITY TOGGLE
   ========================================================================= */

document.querySelectorAll('.toggle-password').forEach(function (btn) {

    btn.addEventListener('click', function () {

        const targetId = btn.getAttribute('data-target');
        const input    = document.getElementById(targetId);
        if (!input) return;

        const isVisible = input.type === 'text';

        input.type = isVisible ? 'password' : 'text';

        btn.classList.toggle('is-visible', !isVisible);
        btn.setAttribute(
            'aria-label',
            isVisible ? 'Show password' : 'Hide password'
        );

        /* keep the caret at the end when switching */
        const len = input.value.length;
        try { input.setSelectionRange(len, len); } catch (e) {}
    });
});
</script>

</body>

</html>