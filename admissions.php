<?php
include 'includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| FETCH SCHOOL SETTINGS
|--------------------------------------------------------------------------
*/

$school = [
    'school_name' => 'Primary School',
    'address'     => '',
    'phone'       => '',
    'phone1'      => '',
    'phone2'      => '',
    'email'       => '',
    'logo'        => '',
];

$query = "
    SELECT setting_id, school_name, address, phone, phone1, phone2, email, logo
    FROM school_settings
    ORDER BY setting_id ASC
    LIMIT 1
";
$result = @mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {
    $school = mysqli_fetch_assoc($result);
}

$school_name = htmlspecialchars($school['school_name'] ?? 'Primary School');
$address     = htmlspecialchars($school['address']     ?? '');
$phone       = htmlspecialchars($school['phone']       ?? '');
$phone1      = htmlspecialchars($school['phone1']      ?? '');
$phone2      = htmlspecialchars($school['phone2']      ?? '');
$email       = htmlspecialchars($school['email']       ?? '');

$logo = !empty($school['logo']) ? htmlspecialchars($school['logo']) : '';

/*
|--------------------------------------------------------------------------
| FETCH ACTIVE ACADEMIC YEAR
|--------------------------------------------------------------------------
*/

$active_year = '';
$res = @mysqli_query(
    $conn,
    "SELECT year FROM academic_years WHERE status = 'active' ORDER BY year DESC LIMIT 1"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $active_year = $row['year'];
}

/*
|--------------------------------------------------------------------------
| FETCH AVAILABLE CLASSES (for showing which classes are open)
|--------------------------------------------------------------------------
*/

$classes = [];
$res = @mysqli_query(
    $conn,
    "SELECT class_id, class_name, stream, class_level
     FROM classes
     WHERE status = 'active'
     ORDER BY class_level ASC, class_name ASC, stream ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $classes[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| CONTACT FORM SUBMISSION
|--------------------------------------------------------------------------
*/

$form_message = '';
$form_type    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enquire') {

    $parent_name = trim($_POST['parent_name'] ?? '');
    $child_name  = trim($_POST['child_name']  ?? '');
    $child_dob   = trim($_POST['child_dob']   ?? '');
    $form_class  = trim($_POST['class_id']    ?? '');
    $form_phone  = trim($_POST['phone']       ?? '');
    $form_email  = trim($_POST['email']       ?? '');
    $notes       = trim($_POST['notes']       ?? '');

    $errors = [];

    if ($parent_name === '') $errors[] = 'Parent / guardian name is required.';
    if ($child_name  === '') $errors[] = 'Child name is required.';
    if ($form_phone  === '') $errors[] = 'Phone number is required.';
    if ($form_email  !== '' && !filter_var($form_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($child_dob !== '' && !DateTime::createFromFormat('Y-m-d', $child_dob)) {
        $errors[] = 'Invalid date of birth.';
    }

    if (!empty($errors)) {
        $form_message = implode(' ', $errors);
        $form_type    = 'error';
    } else {

        /* Save enquiry if table exists */
        $tbl_check = @mysqli_query($conn, "SHOW TABLES LIKE 'admission_enquiries'");

        if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {

            $class_param = $form_class !== '' ? (int) $form_class : null;
            $dob_param   = $child_dob  !== '' ? $child_dob       : null;

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO admission_enquiries
                    (parent_name, child_name, child_dob, class_id, phone, email, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'sssisss',
                $parent_name,
                $child_name,
                $dob_param,
                $class_param,
                $form_phone,
                $form_email,
                $notes
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $form_message = 'Thank you! Your enquiry has been received. We will contact you shortly.';
                $form_type    = 'success';

                /* Clear form */
                $parent_name = $child_name = $child_dob = $form_class = '';
                $form_phone  = $form_email = $notes = '';

            } else {
                mysqli_stmt_close($stmt);
                $form_message = 'Could not save your enquiry. Please try again or call us directly.';
                $form_type    = 'error';
            }

        } else {
            /* Table doesn't exist — just acknowledge */
            $form_message = 'Thank you! Your enquiry has been received. We will contact you shortly.';
            $form_type    = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admissions | <?php echo $school_name; ?></title>
    <meta name="description" content="Admission requirements, process and application form for <?php echo $school_name; ?>.">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy: #17233c;
            --navy-dark: #0c1425;
            --gold: #c9a227;
            --gold-light: #e4c85a;
            --cream: #f7f5ef;
            --white: #ffffff;
            --text: #263044;
            --muted: #6c7484;
            --border: #e7e8ec;
            --red: #9b4747;
            --red-bg: #fbefef;
            --green: #3e7655;
            --green-bg: #eef6f0;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--white);
            color: var(--text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }

        /* =========================================================
           NAVBAR (same as index.php)
        ========================================================= */
        .navbar {
            width: 100%;
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 7%;
            background: rgba(255, 255, 255, 0.98);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(8px);
        }

        .brand { display: flex; align-items: center; gap: 13px; }

        .brand-logo {
            width: 48px; height: 48px;
            border-radius: 10px;
            overflow: hidden;
            background: var(--navy);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .brand-logo img { width: 100%; height: 100%; object-fit: cover; }
        .brand-mark { color: var(--gold-light); font-weight: 800; font-size: 15px; letter-spacing: 1px; }
        .brand-text strong { display: block; color: var(--navy); font-size: 16px; letter-spacing: .4px; }

        .nav-links { display: flex; align-items: center; gap: 30px; }
        .nav-links a { color: var(--text); font-size: 14px; font-weight: 500; transition: .25s; }
        .nav-links a:hover { color: var(--gold); }
        .nav-links a.active { color: var(--gold); font-weight: 650; }

        .nav-login {
            padding: 11px 21px;
            border: 1px solid var(--navy);
            color: var(--navy) !important;
            border-radius: 7px;
        }
        .nav-login:hover { background: var(--navy); color: var(--white) !important; }

        .nav-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            flex-direction: column;
            gap: 5px;
        }
        .nav-toggle span { width: 26px; height: 3px; background: var(--navy); border-radius: 2px; }

        /* =========================================================
           HERO
        ========================================================= */
        .hero {
            background:
                linear-gradient(90deg, rgba(8,17,33,.94) 0%, rgba(14,27,48,.85) 100%),
                url("assets/images/school-background.jpg");
            background-size: cover;
            background-position: center;
            color: var(--white);
            padding: 90px 7% 80px;
            text-align: center;
        }

        .hero-label {
            display: inline-block;
            color: var(--gold-light);
            font-size: 11px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 14px;
        }

        .hero h1 {
            font-size: clamp(30px, 5vw, 52px);
            line-height: 1.1;
            margin-bottom: 18px;
            font-weight: 750;
        }

        .hero h1 span { color: var(--gold-light); }

        .hero p {
            max-width: 680px;
            margin: 0 auto;
            color: #d6dbe6;
            font-size: 16px;
            line-height: 1.7;
        }

        .year-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 26px;
            padding: 10px 18px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 30px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--gold-light);
            letter-spacing: 1px;
        }

        .year-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,.3);
        }

        /* =========================================================
           SECTION BASE
        ========================================================= */
        .section { padding: 80px 7%; }
        .section-label {
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 11px;
            font-weight: 750;
            margin-bottom: 12px;
        }
        .section-heading { max-width: 720px; margin-bottom: 45px; }
        .section-heading h2 {
            color: var(--navy);
            font-size: clamp(26px, 3.5vw, 36px);
            line-height: 1.2;
            margin-bottom: 14px;
        }
        .section-heading p { color: var(--muted); font-size: 15px; }

        /* =========================================================
           STEPS (PROCESS)
        ========================================================= */
        .steps-section { background: var(--cream); }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .step-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px 24px;
            transition: .25s ease;
            position: relative;
            overflow: hidden;
        }

        .step-card:hover {
            transform: translateY(-5px);
            border-color: rgba(201,162,39,.5);
            box-shadow: 0 15px 35px rgba(23,35,60,.08);
        }

        .step-number {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 16px;
        }

        .step-card h3 {
            color: var(--navy);
            font-size: 16px;
            margin-bottom: 8px;
        }

        .step-card p {
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.6;
        }

        /* =========================================================
           REQUIREMENTS + CLASSES (2 columns)
        ========================================================= */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: start;
        }

        .info-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            height: 100%;
        }

        .info-card h3 {
            color: var(--navy);
            font-size: 18px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-card h3::before {
            content: "";
            width: 3px;
            height: 18px;
            background: var(--gold);
            border-radius: 2px;
        }

        .requirements-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .requirements-list li {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            font-size: 14px;
            color: var(--text);
            line-height: 1.6;
        }

        .requirements-list li::before {
            content: "✓";
            color: var(--green);
            font-weight: 900;
            font-size: 15px;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--green-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px;
        }

        .class-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .class-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--cream);
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--navy);
            border: 1px solid transparent;
            transition: .15s ease;
        }

        .class-item:hover {
            border-color: var(--gold);
            background: #fdfcf8;
        }

        .class-item .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            flex-shrink: 0;
            margin-right: 10px;
        }

        .class-item .name {
            flex: 1;
            display: flex;
            align-items: center;
        }

        .class-item .badge {
            font-size: 10px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: 3px 9px;
            border-radius: 12px;
            background: var(--green-bg);
            color: var(--green);
        }

        .no-classes {
            text-align: center;
            padding: 24px 10px;
            color: var(--muted);
            font-size: 13.5px;
        }

        /* =========================================================
           ENQUIRY FORM
        ========================================================= */
        .form-section { background: var(--cream); }

        .form-wrap {
            max-width: 800px;
            margin: 0 auto;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 40px;
            box-shadow: 0 15px 40px rgba(23,35,60,.05);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            color: var(--navy);
            font-size: 24px;
            margin-bottom: 8px;
        }

        .form-header p {
            color: var(--muted);
            font-size: 14px;
        }

        .alert {
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 22px;
            font-size: 13.5px;
            font-weight: 600;
            line-height: 1.5;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group { min-width: 0; }
        .form-group.full { grid-column: 1 / -1; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-group label .req { color: var(--red); margin-left: 2px; }

        .form-control {
            width: 100%;
            height: 46px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 14px;
            font-family: inherit;
            font-size: 14px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            transition: .2s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 100px;
            padding: 12px 14px;
            resize: vertical;
            line-height: 1.6;
        }

        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 14px;
            padding-right: 40px;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 28px;
            border-radius: 8px;
            border: none;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
            white-space: nowrap;
        }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover { background: var(--navy-dark); transform: translateY(-2px); }
        .btn-primary:active { transform: scale(.98); }

        .btn-gold { background: var(--gold); color: var(--navy-dark); }
        .btn-gold:hover { background: var(--gold-light); transform: translateY(-2px); }

        .btn-outline {
            background: transparent;
            color: var(--navy);
            border: 1px solid var(--navy);
        }
        .btn-outline:hover { background: var(--navy); color: var(--white); }

        .form-submit-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 22px;
        }

        /* =========================================================
           CONTACT BAND
        ========================================================= */
        .contact-band {
            background: var(--navy);
            color: var(--white);
            padding: 70px 7%;
        }

        .contact-band-inner {
            max-width: 1100px;
            margin: 0 auto;
            text-align: center;
        }

        .contact-band h2 {
            font-size: clamp(24px, 3.5vw, 34px);
            margin-bottom: 14px;
        }

        .contact-band p {
            color: #c3cbd9;
            margin-bottom: 32px;
            font-size: 15px;
        }

        .contact-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 30px;
        }

        .contact-card {
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px;
            padding: 26px 20px;
            transition: .2s ease;
        }

        .contact-card:hover {
            background: rgba(255,255,255,.08);
            border-color: rgba(201,162,39,.4);
        }

        .contact-card .icon {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .contact-card h4 {
            color: var(--gold-light);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 750;
            margin-bottom: 8px;
        }

        .contact-card p, .contact-card a {
            color: var(--white);
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
        }

        .contact-card a:hover { color: var(--gold-light); }

        /* =========================================================
           FOOTER
        ========================================================= */
        footer {
            background: var(--navy-dark);
            color: #aab3c2;
            padding: 40px 7% 25px;
            text-align: center;
        }

        .footer-bottom {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 12.5px;
        }

        .footer-bottom strong { color: var(--white); }

        .footer-links {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 20px;
        }

        .footer-links a {
            color: #aab3c2;
            font-size: 13px;
            transition: .2s;
        }

        .footer-links a:hover { color: var(--gold-light); }

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 992px) {
            .steps-grid { grid-template-columns: repeat(2, 1fr); }
            .two-col    { grid-template-columns: 1fr; gap: 24px; }
            .contact-cards { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .nav-toggle { display: flex; }
            .nav-links {
                position: absolute;
                top: 78px; left: 0; right: 0;
                background: var(--white);
                flex-direction: column;
                align-items: flex-start;
                gap: 0;
                padding: 0 7%;
                max-height: 0;
                overflow: hidden;
                transition: max-height .3s ease;
                border-bottom: 1px solid var(--border);
            }
            .nav-links.open { max-height: 500px; padding: 10px 7% 20px; }
            .nav-links a { padding: 12px 0; width: 100%; }
            .nav-login { margin-top: 8px; text-align: center; }

            .hero { padding: 60px 6% 55px; }

            .section { padding: 60px 6%; }
            .section-heading { margin-bottom: 35px; }

            .form-wrap { padding: 26px 20px; }

            .form-grid { grid-template-columns: 1fr; gap: 14px; }
            .form-group.full { grid-column: auto; }

            .form-control { height: 48px; font-size: 15px; }

            .form-submit-row { justify-content: stretch; }
            .form-submit-row .btn { width: 100%; }

            .contact-band { padding: 60px 6%; }
            .contact-band h2 { font-size: 22px; }
        }

        @media (max-width: 550px) {
            .navbar { padding: 12px 5%; }
            .brand-text strong { font-size: 14px; }

            .steps-grid { grid-template-columns: 1fr; gap: 14px; }
            .step-card  { padding: 22px 18px; }

            .info-card { padding: 22px 18px; }

            .form-wrap { padding: 22px 16px; }
            .form-header h2 { font-size: 20px; }

            .footer-bottom { flex-direction: column; gap: 8px; text-align: center; }
        }
    </style>
</head>
<body>

<!-- =========================================================
     NAVBAR
========================================================= -->
<header class="navbar">
    <a href="index.php" class="brand">
        <div class="brand-logo">
            <?php if (!empty($logo)): ?>
                <img src="<?php echo $logo; ?>" alt="<?php echo $school_name; ?> Logo">
            <?php else: ?>
                <span class="brand-mark">PS</span>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <strong><?php echo $school_name; ?></strong>
        </div>
    </a>

    <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation">
        <span></span><span></span><span></span>
    </button>

    <nav class="nav-links" id="navLinks">
        <a href="index.php">Home</a>
        <a href="index.php#institutions">Our Schools</a>
        <a href="admissions.php" class="active">Admissions</a>
        <a href="index.php#gallery">Gallery</a>
        <a href="index.php#contact">Contact</a>
        <a href="login.php" class="nav-login">Login</a>
    </nav>
</header>

<!-- =========================================================
     HERO
========================================================= -->
<section class="hero">
    <div class="hero-label">Admissions</div>
    <h1>
        Join <span><?php echo $school_name; ?></span>
    </h1>
    <p>
        We welcome applications for all classes from Kindergarten 1 through Standard Seven.
        Learn about the process, requirements and submit an enquiry — our team will reach out to you.
    </p>

    <?php if (!empty($active_year)): ?>
        <div class="year-badge">
            Now accepting applications for <?php echo htmlspecialchars($active_year); ?>
        </div>
    <?php endif; ?>
</section>

<!-- =========================================================
     PROCESS STEPS
========================================================= -->
<section class="section steps-section">
    <div class="section-heading">
        <div class="section-label">01 — How to Apply</div>
        <h2>Admission in 4 Simple Steps</h2>
        <p>Our admission process is designed to be straightforward for families.</p>
    </div>

    <div class="steps-grid">

        <div class="step-card">
            <div class="step-number">1</div>
            <h3>Submit an Enquiry</h3>
            <p>Fill in the form below or visit the school office to express your interest.</p>
        </div>

        <div class="step-card">
            <div class="step-number">2</div>
            <h3>Meet with Us</h3>
            <p>Parents/guardians and the child are invited for a short interview and school tour.</p>
        </div>

        <div class="step-card">
            <div class="step-number">3</div>
            <h3>Submit Documents</h3>
            <p>Provide the required documents (see the checklist) for verification.</p>
        </div>

        <div class="step-card">
            <div class="step-number">4</div>
            <h3>Confirm Placement</h3>
            <p>Upon acceptance, complete the enrolment and pay the admission fee.</p>
        </div>

    </div>
</section>

<!-- =========================================================
     REQUIREMENTS + AVAILABLE CLASSES
========================================================= -->
<section class="section">
    <div class="section-heading">
        <div class="section-label">02 — What You Need</div>
        <h2>Requirements &amp; Available Classes</h2>
        <p>Here's what to prepare and which classes are currently open for admission.</p>
    </div>

    <div class="two-col">

        <!-- REQUIREMENTS -->
        <div class="info-card">
            <h3>Required Documents</h3>
            <ul class="requirements-list">
                <li>Original birth certificate or a certified copy</li>
                <li>Completed admission application form</li>
                <li>Two recent passport-size photographs of the child</li>
                <li>Immunisation / health record card</li>
                <li>Previous school report (for Standard 2 – Standard 7)</li>
                <li>Transfer letter (for students from another school)</li>
                <li>Parent/guardian ID or passport copy</li>
                <li>Proof of residence (utility bill or similar)</li>
            </ul>
        </div>

        <!-- CLASSES -->
        <div class="info-card">
            <h3>Available Classes</h3>

            <?php if (!empty($classes)): ?>
                <div class="class-list">
                    <?php foreach ($classes as $c): ?>
                        <div class="class-item">
                            <span class="name">
                                <span class="dot"></span>
                                <?php
                                echo htmlspecialchars($c['class_name']);
                                if (!empty($c['stream'])) {
                                    echo ' — ' . htmlspecialchars($c['stream']);
                                }
                                ?>
                            </span>
                            <span class="badge">Open</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-classes">
                    Class information will be available soon.<br>
                    Please <a href="#enquiry" style="color:var(--gold);font-weight:700;">send an enquiry</a> below.
                </div>
            <?php endif; ?>

        </div>

    </div>
</section>

<!-- =========================================================
     ENQUIRY FORM
========================================================= -->
<section class="section form-section" id="enquiry">
    <div class="form-wrap">

        <div class="form-header">
            <h2>Send an Admission Enquiry</h2>
            <p>Fill in your details and we will get back to you as soon as possible.</p>
        </div>

        <?php if ($form_message): ?>
            <div class="alert <?php echo $form_type; ?>">
                <?php echo htmlspecialchars($form_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="admissions.php#enquiry" autocomplete="off">

            <div class="form-grid">

                <div class="form-group">
                    <label>Parent / Guardian Name <span class="req">*</span></label>
                    <input type="text" name="parent_name" class="form-control"
                           value="<?php echo htmlspecialchars($parent_name ?? ''); ?>"
                           placeholder="Enter your full name" maxlength="150" required>
                </div>

                <div class="form-group">
                    <label>Phone Number <span class="req">*</span></label>
                    <input type="text" name="phone" class="form-control"
                           value="<?php echo htmlspecialchars($form_phone ?? ''); ?>"
                           placeholder="e.g. +255 7XX XXX XXX" maxlength="30" required>
                </div>

                <div class="form-group">
                    <label>Child's Full Name <span class="req">*</span></label>
                    <input type="text" name="child_name" class="form-control"
                           value="<?php echo htmlspecialchars($child_name ?? ''); ?>"
                           placeholder="Enter the child's name" maxlength="150" required>
                </div>

                <div class="form-group">
                    <label>Child's Date of Birth</label>
                    <input type="date" name="child_dob" class="form-control"
                           value="<?php echo htmlspecialchars($child_dob ?? ''); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label>Class Interested In</label>
                    <select name="class_id" class="form-control">
                        <option value="">— Select class —</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int)$c['class_id']; ?>"
                                <?php echo (string)($form_class ?? '') === (string)$c['class_id'] ? 'selected' : ''; ?>>
                                <?php
                                echo htmlspecialchars($c['class_name']);
                                if (!empty($c['stream'])) {
                                    echo ' - ' . htmlspecialchars($c['stream']);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Your Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($form_email ?? ''); ?>"
                           placeholder="you@example.com" maxlength="150">
                </div>

                <div class="form-group full">
                    <label>Additional Notes</label>
                    <textarea name="notes" class="form-control"
                              placeholder="Anything else we should know? (optional)"><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
                </div>

            </div>

            <div class="form-submit-row">
                <button type="submit" class="btn btn-primary">Send Enquiry</button>
            </div>

            <input type="hidden" name="action" value="enquire">
        </form>

    </div>
</section>

<!-- =========================================================
     CONTACT BAND
========================================================= -->
<section class="contact-band" id="contact">
    <div class="contact-band-inner">
        <h2>Visit or Call Us</h2>
        <p>Have questions? Reach out to our admissions office directly.</p>

        <div class="contact-cards">

            <?php if (!empty($address)): ?>
                <div class="contact-card">
                    <div class="icon">📍</div>
                    <h4>Address</h4>
                    <p><?php echo $address; ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($phone) || !empty($phone1) || !empty($phone2)): ?>
                <div class="contact-card">
                    <div class="icon">📞</div>
                    <h4>Phone</h4>
                    <?php if (!empty($phone)):  ?><p><a href="tel:<?php echo htmlspecialchars(str_replace(' ','',$phone));  ?>"><?php echo $phone;  ?></a></p><?php endif; ?>
                    <?php if (!empty($phone1)): ?><p><a href="tel:<?php echo htmlspecialchars(str_replace(' ','',$phone1)); ?>"><?php echo $phone1; ?></a></p><?php endif; ?>
                    <?php if (!empty($phone2)): ?><p><a href="tel:<?php echo htmlspecialchars(str_replace(' ','',$phone2)); ?>"><?php echo $phone2; ?></a></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($email)): ?>
                <div class="contact-card">
                    <div class="icon">✉</div>
                    <h4>Email</h4>
                    <p><a href="mailto:<?php echo $email; ?>"><?php echo $email; ?></a></p>
                </div>
            <?php endif; ?>

        </div>
    </div>
</section>

<!-- =========================================================
     FOOTER
========================================================= -->
<footer>
    <div class="footer-links">
        <a href="index.php">Home</a>
        <a href="index.php#institutions">Our Schools</a>
        <a href="admissions.php">Admissions</a>
        <a href="index.php#gallery">Gallery</a>
        <a href="login.php">Login</a>
    </div>

    <div class="footer-bottom">
        <p>© <?php echo date('Y'); ?> <strong><?php echo $school_name; ?></strong>. All rights reserved.</p>
    </div>
</footer>


<script>
/* =========================================================
   MOBILE NAV TOGGLE
========================================================= */
(function () {
    const toggle = document.getElementById('navToggle');
    const links  = document.getElementById('navLinks');

    if (toggle) {
        toggle.addEventListener('click', () => {
            links.classList.toggle('open');
        });
    }

    document.querySelectorAll('#navLinks a').forEach(a => {
        a.addEventListener('click', () => links.classList.remove('open'));
    });
})();


/* =========================================================
   SMOOTH SCROLL to #enquiry when arriving with ?#enquiry
========================================================= */
if (window.location.hash === '#enquiry') {
    const target = document.getElementById('enquiry');
    if (target) setTimeout(() => target.scrollIntoView({ behavior: 'smooth' }), 100);
}
</script>

</body>
</html>