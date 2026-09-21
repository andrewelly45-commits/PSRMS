<?php
session_start();

require_once '../auth/auth_check.php';
require_once '../includes/db.php';

/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| CREATE UPLOAD DIRECTORY IF IT DOES NOT EXIST
|--------------------------------------------------------------------------
*/

$upload_dir = '../uploads/school/';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$message = '';
$message_type = '';

/*
|--------------------------------------------------------------------------
| GET CURRENT SCHOOL SETTINGS
|--------------------------------------------------------------------------
*/

$settings = [
    'setting_id'   => 1,
    'school_name'  => '',
    'school_code'  => '',
    'address'      => '',
    'phone'        => '',
    'email'        => '',
    'logo'         => '',
    'head_teacher' => ''
];

$query = "
    SELECT
        setting_id,
        school_name,
        school_code,
        address,
        phone,
        email,
        logo,
        head_teacher
    FROM school_settings
    ORDER BY setting_id ASC
    LIMIT 1
";

$result = mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {

    $settings = mysqli_fetch_assoc($result);

} else {

    /*
    |--------------------------------------------------------------------------
    | CREATE FIRST SETTINGS ROW IF TABLE IS EMPTY
    |--------------------------------------------------------------------------
    */

    $insert = "
        INSERT INTO school_settings (
            school_name,
            school_code,
            address,
            phone,
            email,
            logo,
            head_teacher
        )
        VALUES (
            'Primary School',
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL
        )
    ";

    if (mysqli_query($conn, $insert)) {

        $settings['setting_id'] = mysqli_insert_id($conn);

    }
}

/*
|--------------------------------------------------------------------------
| UPDATE SCHOOL SETTINGS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $setting_id = (int)($_POST['setting_id'] ?? 0);

    $school_name = trim($_POST['school_name'] ?? '');
    $school_code = trim($_POST['school_code'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $head_teacher = trim($_POST['head_teacher'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($school_name === '') {

        $message = 'School name is required.';
        $message_type = 'error';

    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
        $message_type = 'error';

    } else {

        /*
        |--------------------------------------------------------------------------
        | CURRENT LOGO
        |--------------------------------------------------------------------------
        */

        $current_logo = $settings['logo'] ?? '';
        $new_logo = $current_logo;

        /*
        |--------------------------------------------------------------------------
        | LOGO UPLOAD
        |--------------------------------------------------------------------------
        */

        if (
            isset($_FILES['logo']) &&
            $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {

                $message = 'There was a problem uploading the logo.';
                $message_type = 'error';

            } else {

                $file_tmp = $_FILES['logo']['tmp_name'];
                $file_name = $_FILES['logo']['name'];
                $file_size = $_FILES['logo']['size'];

                /*
                |--------------------------------------------------------------------------
                | MAXIMUM SIZE: 2MB
                |--------------------------------------------------------------------------
                */

                if ($file_size > 2 * 1024 * 1024) {

                    $message = 'Logo size must not exceed 2MB.';
                    $message_type = 'error';

                } else {

                    $image_info = getimagesize($file_tmp);

                    if ($image_info === false) {

                        $message = 'The uploaded file is not a valid image.';
                        $message_type = 'error';

                    } else {

                        $allowed_types = [
                            IMAGETYPE_JPEG => 'jpg',
                            IMAGETYPE_PNG  => 'png',
                            IMAGETYPE_WEBP => 'webp'
                        ];

                        $image_type = $image_info[2];

                        if (!isset($allowed_types[$image_type])) {

                            $message = 'Only JPG, PNG and WEBP images are allowed.';
                            $message_type = 'error';

                        } else {

                            $extension = $allowed_types[$image_type];

                            /*
                            |--------------------------------------------------------------------------
                            | UNIQUE FILE NAME
                            |--------------------------------------------------------------------------
                            */

                            $new_file_name =
                                'school_logo_' .
                                time() .
                                '_' .
                                bin2hex(random_bytes(4)) .
                                '.' .
                                $extension;

                            $destination =
                                $upload_dir . $new_file_name;

                            if (move_uploaded_file($file_tmp, $destination)) {

                                $new_logo =
                                    'uploads/school/' . $new_file_name;

                                /*
                                |--------------------------------------------------------------------------
                                | DELETE OLD LOGO
                                |--------------------------------------------------------------------------
                                */

                                if (!empty($current_logo)) {

                                    $old_logo_path =
                                        '../' . ltrim($current_logo, '/');

                                    if (
                                        file_exists($old_logo_path) &&
                                        is_file($old_logo_path)
                                    ) {
                                        unlink($old_logo_path);
                                    }
                                }

                            } else {

                                $message =
                                    'Unable to save the uploaded logo.';
                                $message_type = 'error';

                            }
                        }
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        if ($message_type !== 'error') {

            $stmt = mysqli_prepare(
                $conn,
                "
                UPDATE school_settings
                SET
                    school_name = ?,
                    school_code = ?,
                    address = ?,
                    phone = ?,
                    email = ?,
                    logo = ?,
                    head_teacher = ?
                WHERE setting_id = ?
                "
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sssssssi",
                $school_name,
                $school_code,
                $address,
                $phone,
                $email,
                $new_logo,
                $head_teacher,
                $setting_id
            );

            if (mysqli_stmt_execute($stmt)) {

                $message =
                    'School settings have been updated successfully.';

                $message_type = 'success';

                /*
                |--------------------------------------------------------------------------
                | REFRESH SETTINGS
                |--------------------------------------------------------------------------
                */

                $settings['setting_id'] = $setting_id;
                $settings['school_name'] = $school_name;
                $settings['school_code'] = $school_code;
                $settings['address'] = $address;
                $settings['phone'] = $phone;
                $settings['email'] = $email;
                $settings['logo'] = $new_logo;
                $settings['head_teacher'] = $head_teacher;

            } else {

                $message =
                    'Unable to update school settings: ' .
                    mysqli_error($conn);

                $message_type = 'error';
            }

            mysqli_stmt_close($stmt);
        }
    }
}


/*
|--------------------------------------------------------------------------
| ESCAPE OUTPUT
|--------------------------------------------------------------------------
*/

$school_name = htmlspecialchars(
    $settings['school_name'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$school_code = htmlspecialchars(
    $settings['school_code'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$address = htmlspecialchars(
    $settings['address'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$phone = htmlspecialchars(
    $settings['phone'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$email = htmlspecialchars(
    $settings['email'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$head_teacher = htmlspecialchars(
    $settings['head_teacher'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$logo = $settings['logo'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>School Settings | PSRMS</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f4f6f9;
            color: #263044;
        }

        .page {
            min-height: 100vh;
            padding: 35px;
        }

        .page-header {
            margin-bottom: 28px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #17233c;
            margin-bottom: 6px;
        }

        .page-header p {
            color: #747d8d;
            font-size: 14px;
        }

        .settings-container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .settings-card {
            background: #ffffff;
            border: 1px solid #e4e7ec;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 12px 35px rgba(20, 31, 51, 0.06);
        }

        .card-header {
            padding: 24px 28px;
            border-bottom: 1px solid #e8ebef;
            background: #fafbfc;
        }

        .card-header h2 {
            font-size: 18px;
            color: #17233c;
            margin-bottom: 4px;
        }

        .card-header p {
            color: #7b8493;
            font-size: 13px;
        }

        .card-body {
            padding: 30px;
        }

        .alert {
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert.success {
            background: #edf8f1;
            color: #217346;
            border: 1px solid #ccebd7;
        }

        .alert.error {
            background: #fff1f1;
            color: #a52a2a;
            border: 1px solid #f1cccc;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 13px;
            font-weight: 650;
            color: #27344d;
            margin-bottom: 8px;
        }

        label span {
            color: #c9a227;
        }

        input,
        textarea {
            width: 100%;
            border: 1px solid #dfe3e9;
            border-radius: 8px;
            padding: 12px 14px;
            font-family: inherit;
            font-size: 14px;
            color: #263044;
            background: #ffffff;
            outline: none;
            transition: .2s;
        }

        input:focus,
        textarea:focus {
            border-color: #c9a227;
            box-shadow: 0 0 0 3px rgba(201, 162, 39, .10);
        }

        textarea {
            min-height: 105px;
            resize: vertical;
        }

        .logo-section {
            margin-top: 30px;
            padding-top: 28px;
            border-top: 1px solid #e8ebef;
        }

        .logo-section h3 {
            color: #17233c;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .logo-section > p {
            color: #7b8493;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .logo-upload {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 25px;
            align-items: center;
        }

        .logo-preview {
            width: 170px;
            height: 170px;
            border-radius: 12px;
            border: 1px solid #dfe3e9;
            background: #f7f8fa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .logo-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .logo-placeholder {
            color: #9aa2af;
            text-align: center;
            font-size: 12px;
            padding: 20px;
        }

        .file-input {
            border: 2px dashed #d9dee6;
            padding: 25px;
            border-radius: 10px;
            background: #fafbfc;
        }

        .file-input input {
            border: none;
            padding: 0;
            background: transparent;
        }

        .file-help {
            color: #8a929f;
            font-size: 12px;
            margin-top: 10px;
        }

        .form-actions {
            margin-top: 32px;
            padding-top: 25px;
            border-top: 1px solid #e8ebef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 12px 22px;
            font-size: 14px;
            font-weight: 650;
            cursor: pointer;
            text-decoration: none;
            transition: .2s;
        }

        .btn-primary {
            background: #17233c;
            color: #ffffff;
        }

        .btn-primary:hover {
            background: #243451;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #eef1f5;
            color: #364258;
        }

        .btn-secondary:hover {
            background: #e3e7ed;
        }

        .settings-note {
            margin-top: 22px;
            padding: 15px 17px;
            background: #faf7eb;
            border-left: 3px solid #c9a227;
            color: #665825;
            font-size: 13px;
            border-radius: 5px;
        }

        @media (max-width: 800px) {

            .page {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .logo-upload {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 500px) {

            .page {
                padding: 12px;
            }

            .card-body {
                padding: 20px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

        }

    </style>

</head>


<body>


<div class="page">

    <div class="settings-container">

        <div class="page-header">

            <h1>
                School Settings
            </h1>

            <p>
                Manage the school information displayed across PSRMS.
            </p>

        </div>


        <div class="settings-card">

            <div class="card-header">

                <h2>
                    School Information
                </h2>

                <p>
                    These details will be fetched automatically by
                    the public website and other system components.
                </p>

            </div>


            <div class="card-body">


                <?php if (!empty($message)): ?>

                    <div class="alert <?php echo $message_type; ?>">

                        <?php echo htmlspecialchars($message); ?>

                    </div>

                <?php endif; ?>


                <form
                    method="POST"
                    enctype="multipart/form-data"
                >

                    <input
                        type="hidden"
                        name="setting_id"
                        value="<?php echo (int)$settings['setting_id']; ?>"
                    >


                    <div class="form-grid">


                        <!-- SCHOOL NAME -->

                        <div class="form-group">

                            <label>
                                School Name <span>*</span>
                            </label>

                            <input
                                type="text"
                                name="school_name"
                                value="<?php echo $school_name; ?>"
                                placeholder="Enter school name"
                                maxlength="200"
                                required
                            >

                        </div>


                        <!-- SCHOOL CODE -->

                        <div class="form-group">

                            <label>
                                School Code
                            </label>

                            <input
                                type="text"
                                name="school_code"
                                value="<?php echo $school_code; ?>"
                                placeholder="e.g. PSRMS-001"
                                maxlength="50"
                            >

                        </div>


                        <!-- HEAD TEACHER -->

                        <div class="form-group">

                            <label>
                                Head Teacher
                            </label>

                            <input
                                type="text"
                                name="head_teacher"
                                value="<?php echo $head_teacher; ?>"
                                placeholder="Enter head teacher name"
                                maxlength="150"
                            >

                        </div>


                        <!-- PHONE -->

                        <div class="form-group">

                            <label>
                                Phone Number
                            </label>

                            <input
                                type="text"
                                name="phone"
                                value="<?php echo $phone; ?>"
                                placeholder="e.g. +255 7XX XXX XXX"
                                maxlength="50"
                            >

                        </div>


                        <!-- EMAIL -->

                        <div class="form-group">

                            <label>
                                Email Address
                            </label>

                            <input
                                type="email"
                                name="email"
                                value="<?php echo $email; ?>"
                                placeholder="school@example.com"
                                maxlength="150"
                            >

                        </div>


                        <!-- ADDRESS -->

                        <div class="form-group full">

                            <label>
                                School Address
                            </label>

                            <textarea
                                name="address"
                                placeholder="Enter the complete school address"
                            ><?php echo $address; ?></textarea>

                        </div>


                    </div>


                    <!-- LOGO -->

                    <div class="logo-section">

                        <h3>
                            School Logo
                        </h3>

                        <p>
                            Upload the logo that will appear on the
                            public website, sidebar and other system areas.
                        </p>


                        <div class="logo-upload">


                            <div class="logo-preview">

                                <?php if (!empty($logo)): ?>

                                    <img
                                        src="../<?php echo htmlspecialchars($logo); ?>"
                                        alt="School Logo"
                                        id="logoPreview"
                                    >

                                <?php else: ?>

                                    <div
                                        class="logo-placeholder"
                                        id="logoPlaceholder"
                                    >
                                        No school logo uploaded
                                    </div>

                                    <img
                                        id="logoPreview"
                                        style="display:none;"
                                        alt="Logo Preview"
                                    >

                                <?php endif; ?>

                            </div>


                            <div class="file-input">

                                <input
                                    type="file"
                                    name="logo"
                                    id="logoInput"
                                    accept="image/jpeg,image/png,image/webp"
                                >

                                <div class="file-help">

                                    Accepted formats:
                                    JPG, PNG and WEBP.

                                    Maximum size: 2MB.

                                </div>

                            </div>


                        </div>

                    </div>


                    <div class="settings-note">

                        <strong>Important:</strong>
                        Changes made here will automatically be available
                        to the public website because the front page reads
                        the school information directly from the
                        <code>school_settings</code> table.

                    </div>


                    <div class="form-actions">

                        <a
                            href="../index.php"
                            target="_blank"
                            class="btn btn-secondary"
                        >
                            View Website
                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Save School Settings
                        </button>

                    </div>


                </form>

            </div>

        </div>

    </div>

</div>


<script>

    /*
    |--------------------------------------------------------------------------
    | LOGO LIVE PREVIEW
    |--------------------------------------------------------------------------
    */

    const logoInput = document.getElementById('logoInput');
    const logoPreview = document.getElementById('logoPreview');
    const logoPlaceholder =
        document.getElementById('logoPlaceholder');

    if (logoInput) {

        logoInput.addEventListener('change', function () {

            const file = this.files[0];

            if (!file) {
                return;
            }

            if (!file.type.startsWith('image/')) {
                alert('Please select an image file.');
                this.value = '';
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {

                logoPreview.src = event.target.result;
                logoPreview.style.display = 'block';

                if (logoPlaceholder) {
                    logoPlaceholder.style.display = 'none';
                }

            };

            reader.readAsDataURL(file);

        });

    }

</script>


</body>
</html>