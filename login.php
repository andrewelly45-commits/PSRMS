<?php
session_start();

/*
|--------------------------------------------------------------------------
| OPTIONAL: FETCH SCHOOL SETTINGS (so logo/name stay dynamic)
|--------------------------------------------------------------------------
*/
$school_name = 'Primary School';
$logo        = '';

if (file_exists('includes/db.php')) {
    include 'includes/db.php';

    $q = "SELECT school_name, logo FROM school_settings ORDER BY setting_id ASC LIMIT 1";
    $r = @mysqli_query($conn, $q);

    if ($r && mysqli_num_rows($r) > 0) {
        $row         = mysqli_fetch_assoc($r);
        $school_name = htmlspecialchars($row['school_name'] ?? 'Primary School');
        $logo        = !empty($row['logo']) ? htmlspecialchars($row['logo']) : '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Login | <?php echo $school_name; ?></title>

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
            --border: #e2e4e9;
            --danger: #a33a3a;
        }

        html, body { height: 100%; }

        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            min-height: 100dvh;
            -webkit-text-size-adjust: 100%;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--white);
            border-radius: 14px;
            padding: 32px 26px;
            box-shadow: 0 15px 40px rgba(16, 24, 43, .10);
        }

        /* ---------- BRAND ---------- */
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            margin-bottom: 26px;
            text-decoration: none;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 1px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .brand-name strong {
            display: block;
            color: var(--navy);
            font-size: 15px;
            line-height: 1.2;
        }

        .brand-name span {
            display: block;
            color: var(--muted);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            margin-top: 2px;
        }

        /* ---------- HEADER ---------- */
        .form-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .form-header h1 {
            color: var(--navy);
            font-size: 22px;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .form-header p {
            color: var(--muted);
            font-size: 12.5px;
        }

        /* ---------- ALERT ---------- */
        .alert {
            padding: 11px 13px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 12.5px;
            color: var(--danger);
            background: #fff4f4;
            border: 1px solid #f1d6d6;
        }

        /* ---------- FORM ---------- */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 12px;
            font-weight: 650;
            margin-bottom: 7px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            height: 48px;
            padding: 0 15px 0 42px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fcfcfd;
            color: var(--text);
            font-family: inherit;
            font-size: 16px; /* prevents iOS zoom */
            outline: none;
            transition: .2s ease;
            -webkit-appearance: none;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .form-control::placeholder {
            color: #a4abb7;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa2b1;
            font-size: 14px;
            pointer-events: none;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: var(--navy);
            cursor: pointer;
            font-size: 10.5px;
            font-weight: 700;
            padding: 6px 8px;
            letter-spacing: .5px;
        }

        .password-toggle:hover { color: var(--gold); }

        /* ---------- OPTIONS ---------- */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
        }

        .remember input {
            accent-color: var(--gold);
            width: 15px;
            height: 15px;
        }

        .forgot {
            color: var(--navy);
            font-size: 12px;
            font-weight: 650;
            text-decoration: none;
        }

        .forgot:hover { color: var(--gold); }

        /* ---------- BUTTON ---------- */
        .login-button {
            width: 100%;
            height: 50px;
            border: none;
            border-radius: 9px;
            background: var(--navy);
            color: var(--white);
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .login-button:hover { background: var(--navy-dark); }
        .login-button:active { transform: scale(.98); }

        /* ---------- FOOTER ---------- */
        .security-note {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            text-align: center;
            color: #8a92a0;
            font-size: 10.5px;
            line-height: 1.6;
        }

        .back-home {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .back-home:hover { color: var(--navy); }

        /* ---------- SMALL PHONES ---------- */
        @media (max-width: 420px) {
            body { padding: 12px; }
            .login-card {
                padding: 26px 20px;
                border-radius: 12px;
            }
            .form-header h1 { font-size: 20px; }
            .form-header { margin-bottom: 20px; }
            .brand { margin-bottom: 22px; }
        }

        /* ---------- SAFE AREA (iPhone notch) ---------- */
        @supports (padding: max(0px)) {
            body {
                padding-left: max(12px, env(safe-area-inset-left));
                padding-right: max(12px, env(safe-area-inset-right));
            }
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- BRAND -->
    <a href="index.php" class="brand">
        <div class="brand-mark">
            <?php if (!empty($logo)): ?>
                <img src="<?php echo $logo; ?>" alt="<?php echo $school_name; ?>">
            <?php else: ?>
                PS
            <?php endif; ?>
        </div>
        <div class="brand-name">
            <strong><?php echo $school_name; ?></strong>
            <span>Records Portal</span>
        </div>
    </a>

    <!-- HEADER -->
    <div class="form-header">
        <h1>Welcome back</h1>
        <p>Sign in to continue to your account</p>
    </div>

    <!-- ERROR -->
    <?php if (isset($_SESSION['login_error'])): ?>
        <div class="alert">
            <?php
                echo htmlspecialchars($_SESSION['login_error']);
                unset($_SESSION['login_error']);
            ?>
        </div>
    <?php endif; ?>

  <!-- FORM -->
<form action="auth/login_process.php" method="POST" autocomplete="on">

    <div class="form-group">
        <label for="identifier">Phone Number or Email</label>

        <div class="input-wrapper">
            <span class="input-icon">☎</span>

            <input
                type="text"
                id="identifier"
                name="identifier"
                class="form-control"
                placeholder="Phone number or email"
                autocomplete="username"
                required
            >
        </div>
    </div>


    <div class="form-group">
        <label for="password">Password</label>

        <div class="input-wrapper">

            <span class="input-icon">•</span>

            <input
                type="password"
                id="password"
                name="password"
                class="form-control"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <button
                type="button"
                class="password-toggle"
                onclick="togglePassword(this)"
            >
                SHOW
            </button>

        </div>
    </div>


    <div class="form-options">

        <label class="remember">
            <input type="checkbox" name="remember">
            Remember me
        </label>

        <a href="#" class="forgot">
            Forgot?
        </a>

    </div>


    <button type="submit" class="login-button">
        Sign In
    </button>

</form>

    <div class="security-note">
        Your login is protected. Do not share your credentials.
    </div>

    <a href="index.php" class="back-home">
        ← Back to school portal
    </a>

</div>

<script>
    function togglePassword(btn) {
        const input = document.getElementById('password');
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.textContent = isHidden ? 'HIDE' : 'SHOW';
    }
</script>

</body>
</html>