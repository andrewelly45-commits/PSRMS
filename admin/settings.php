<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


/* =========================================================================
   UPLOAD DIR
   ========================================================================= */

$upload_dir = __DIR__ . '/../uploads/school/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}


/* =========================================================================
   LOAD / CREATE SETTINGS
   ========================================================================= */

$settings = [
    'setting_id'  => 1,
    'school_name' => '',
    'address'     => '',
    'phone'       => '',
    'phone1'      => '',
    'phone2'      => '',
    'email'       => '',
    'logo'        => '',
];

$res = mysqli_query(
    $conn,
    "SELECT setting_id, school_name, address, phone, phone1, phone2, email, logo
     FROM school_settings
     ORDER BY setting_id ASC
     LIMIT 1"
);

if ($res && mysqli_num_rows($res) > 0) {
    $settings = mysqli_fetch_assoc($res);
} else {
    /* Create the first row */
    $ins = mysqli_query(
        $conn,
        "INSERT INTO school_settings (school_name) VALUES ('Primary School')"
    );
    if ($ins) {
        $settings['setting_id'] = mysqli_insert_id($conn);
    }
}


/* =========================================================================
   MESSAGE
   ========================================================================= */

$message = '';
$message_type = '';


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $setting_id  = (int) ($_POST['setting_id'] ?? 0);
    $school_name = trim($_POST['school_name'] ?? '');
    $address     = trim($_POST['address']     ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $phone1      = trim($_POST['phone1']      ?? '');
    $phone2      = trim($_POST['phone2']      ?? '');
    $email       = trim($_POST['email']       ?? '');

    /* ---------- Validation ---------- */

    if ($school_name === '') {
        $message = 'School name is required.';
        $message_type = 'error';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';
    }

    /* ---------- Logo handling ---------- */

    $current_logo = $settings['logo'] ?? '';
    $new_logo     = $current_logo;

    if (
        $message_type !== 'error' &&
        isset($_FILES['logo']) &&
        $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {

            $message = 'There was a problem uploading the logo.';
            $message_type = 'error';

        } else {

            $tmp  = $_FILES['logo']['tmp_name'];
            $size = $_FILES['logo']['size'];

            if ($size > 2 * 1024 * 1024) {

                $message = 'Logo size must not exceed 2MB.';
                $message_type = 'error';

            } else {

                $info = @getimagesize($tmp);

                if ($info === false) {

                    $message = 'The uploaded file is not a valid image.';
                    $message_type = 'error';

                } else {

                    $allowed = [
                        IMAGETYPE_JPEG => 'jpg',
                        IMAGETYPE_PNG  => 'png',
                        IMAGETYPE_WEBP => 'webp',
                    ];

                    if (!isset($allowed[$info[2]])) {

                        $message = 'Only JPG, PNG and WEBP images are allowed.';
                        $message_type = 'error';

                    } else {

                        $ext      = $allowed[$info[2]];
                        $filename = 'school_logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dest     = $upload_dir . $filename;

                        if (move_uploaded_file($tmp, $dest)) {

                            /* Delete old logo */
                            if ($current_logo) {
                                $old = __DIR__ . '/../' . ltrim($current_logo, '/');
                                if (is_file($old)) @unlink($old);
                            }

                            $new_logo = 'uploads/school/' . $filename;

                        } else {

                            $message = 'Unable to save the uploaded logo.';
                            $message_type = 'error';

                        }
                    }
                }
            }
        }
    }

    /* ---------- Save ---------- */

    if ($message_type !== 'error') {

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE school_settings
             SET school_name = ?,
                 address = ?,
                 phone = ?,
                 phone1 = ?,
                 phone2 = ?,
                 email = ?,
                 logo = ?
             WHERE setting_id = ?"
        );

        mysqli_stmt_bind_param(
            $stmt,
            'sssssssi',
            $school_name,
            $address,
            $phone,
            $phone1,
            $phone2,
            $email,
            $new_logo,
            $setting_id
        );

        if (mysqli_stmt_execute($stmt)) {

            $message = 'School settings updated successfully.';
            $message_type = 'success';

            /* Refresh local copy */
            $settings['setting_id']  = $setting_id;
            $settings['school_name'] = $school_name;
            $settings['address']     = $address;
            $settings['phone']       = $phone;
            $settings['phone1']      = $phone1;
            $settings['phone2']      = $phone2;
            $settings['email']       = $email;
            $settings['logo']        = $new_logo;

        } else {

            $message = 'Could not update: ' . mysqli_error($conn);
            $message_type = 'error';
        }

        mysqli_stmt_close($stmt);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>School Settings | PSRMS</title>

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

        /* =========================================================
           MAIN LAYOUT
        ========================================================= */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* =========================================================
           PAGE HEADER
        ========================================================= */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        /* =========================================================
           ALERTS
        ========================================================= */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* =========================================================
           CARD
        ========================================================= */
        .settings-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            max-width: 1000px;
        }

        .card-section {
            padding: 22px;
            border-bottom: 1px solid var(--border);
        }

        .card-section:last-of-type { border-bottom: none; }

        .section-title {
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

        .section-title::before {
            content: "";
            width: 3px;
            height: 14px;
            background: var(--gold);
            border-radius: 2px;
        }

        /* =========================================================
           FORM
        ========================================================= */
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
        }

        textarea.form-control {
            height: auto;
            min-height: 90px;
            padding: 12px;
            resize: vertical;
            line-height: 1.5;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .help-text {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 5px;
        }

        /* =========================================================
           LOGO UPLOAD
        ========================================================= */
        .logo-block {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 22px;
            align-items: center;
        }

        .logo-preview {
            width: 160px;
            height: 160px;
            border-radius: 12px;
            border: 2px dashed var(--border);
            background: #fcfcfd;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
        }

        .logo-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
        }

        .logo-placeholder {
            color: var(--muted);
            text-align: center;
            font-size: 11.5px;
            padding: 16px;
            line-height: 1.4;
        }

        .logo-placeholder strong {
            display: block;
            color: var(--navy);
            font-size: 20px;
            margin-bottom: 6px;
        }

        .upload-hint strong {
            color: var(--navy);
            display: block;
            font-size: 12.5px;
            margin-bottom: 4px;
        }

        .upload-hint span {
            color: var(--muted);
            font-size: 11px;
            display: block;
            margin-bottom: 12px;
        }

        .file-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .file-input {
            display: none;
        }

        .choose-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--navy);
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .choose-btn:hover { border-color: var(--gold); color: var(--gold); }

        .file-name {
            color: var(--muted);
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        /* =========================================================
           FOOTER ACTIONS
        ========================================================= */
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

        .btn-ghost {
            background: var(--white);
            color: var(--muted);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { color: var(--navy); border-color: #c8ccd3; }

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; line-height: 1.45; }

            .card-section { padding: 18px; }

            .form-grid { grid-template-columns: 1fr; gap: 14px; }
            .form-group.full { grid-column: auto; }

            .form-control { height: 46px; font-size: 14px; }
            textarea.form-control { min-height: 100px; }

            .logo-block {
                grid-template-columns: 1fr;
                gap: 18px;
                text-align: center;
            }

            .logo-preview {
                width: 140px;
                height: 140px;
                margin: 0 auto;
            }

            .upload-hint { text-align: left; }

            .file-row {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .choose-btn {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            .file-name { text-align: center; }

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

            .card-section { padding: 16px; }
            .section-title { font-size: 11px; }

            .logo-preview {
                width: 120px;
                height: 120px;
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
$topbar_title    = 'School Settings';
$topbar_subtitle = 'Manage school information';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>School Settings</h1>
            <p>Manage the school information displayed across PSRMS.</p>
        </div>
    </div>

    <!-- FLASH -->
    <?php if ($message): ?>
        <div class="alert <?php echo e($message_type); ?>">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <!-- FORM CARD -->
    <form method="POST" action="settings.php"
          class="settings-card" enctype="multipart/form-data" autocomplete="off">

        <input type="hidden" name="setting_id"
               value="<?php echo (int)$settings['setting_id']; ?>">

        <!-- =============================================
             SECTION 1 — BASIC INFO
        ============================================== -->
        <div class="card-section">

            <div class="section-title">Basic Information</div>

            <div class="form-grid">

                <div class="form-group full">
                    <label>School Name <span class="req">*</span></label>
                    <input type="text"
                           name="school_name"
                           class="form-control"
                           value="<?php echo e($settings['school_name'] ?? ''); ?>"
                           placeholder="Enter school name"
                           maxlength="200"
                           required>
                </div>

                <div class="form-group full">
                    <label>School Address</label>
                    <textarea name="address"
                              class="form-control"
                              placeholder="Enter the complete school address"
                    ><?php echo e($settings['address'] ?? ''); ?></textarea>
                </div>

            </div>
        </div>

        <!-- =============================================
             SECTION 2 — CONTACT
        ============================================== -->
        <div class="card-section">

            <div class="section-title">Contact Information</div>

            <div class="form-grid">

                <div class="form-group">
                    <label>Main Phone</label>
                    <input type="text"
                           name="phone"
                           class="form-control"
                           value="<?php echo e($settings['phone'] ?? ''); ?>"
                           placeholder="e.g. +255 7XX XXX XXX"
                           maxlength="50">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email"
                           name="email"
                           class="form-control"
                           value="<?php echo e($settings['email'] ?? ''); ?>"
                           placeholder="school@example.com"
                           maxlength="150">
                </div>

                <div class="form-group">
                    <label>Alternate Phone 1</label>
                    <input type="text"
                           name="phone1"
                           class="form-control"
                           value="<?php echo e($settings['phone1'] ?? ''); ?>"
                           placeholder="Secondary phone"
                           maxlength="30">
                </div>

                <div class="form-group">
                    <label>Alternate Phone 2</label>
                    <input type="text"
                           name="phone2"
                           class="form-control"
                           value="<?php echo e($settings['phone2'] ?? ''); ?>"
                           placeholder="Tertiary phone"
                           maxlength="30">
                </div>

            </div>
        </div>

        <!-- =============================================
             SECTION 3 — LOGO
        ============================================== -->
        <div class="card-section">

            <div class="section-title">School Logo</div>

            <div class="logo-block">

                <div class="logo-preview" id="logoPreviewWrap">
                    <?php if (!empty($settings['logo'])): ?>
                        <img src="../<?php echo e($settings['logo']); ?>"
                             alt="School logo" id="logoPreview">
                    <?php else: ?>
                        <div class="logo-placeholder" id="logoPlaceholder">
                            <strong>Logo</strong>
                            No school logo uploaded yet
                        </div>
                        <img id="logoPreview" style="display:none;" alt="Logo preview">
                    <?php endif; ?>
                </div>

                <div class="upload-hint">
                    <strong>Upload a logo</strong>
                    <span>JPG, PNG or WEBP — max 2MB. Recommended: square or landscape PNG with transparent background.</span>

                    <div class="file-row">
                        <button type="button" class="choose-btn"
                                onclick="document.getElementById('logoInput').click()">
                            Choose Image
                        </button>
                        <span class="file-name" id="fileName">No file selected</span>

                        <input type="file"
                               name="logo"
                               id="logoInput"
                               class="file-input"
                               accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>

            </div>
        </div>

        <!-- FOOTER -->
        <div class="form-footer">
            <a href="../index.php" target="_blank" class="btn btn-ghost">
                View Website →
            </a>
            <button type="submit" class="btn btn-primary">
                Save Settings
            </button>
        </div>

    </form>

</main>


<script>
/* =========================================================
   LOGO LIVE PREVIEW
========================================================= */
(function () {
    const input       = document.getElementById('logoInput');
    const preview     = document.getElementById('logoPreview');
    const placeholder = document.getElementById('logoPlaceholder');
    const fileName    = document.getElementById('fileName');

    if (!input) return;

    input.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) {
            fileName.textContent = 'No file selected';
            return;
        }

        fileName.textContent = file.name;

        if (!file.type.startsWith('image/')) {
            alert('Please choose an image file.');
            this.value = '';
            fileName.textContent = 'No file selected';
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            alert('Logo must be smaller than 2MB.');
            this.value = '';
            fileName.textContent = 'No file selected';
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
})();
</script>

</body>
</html>