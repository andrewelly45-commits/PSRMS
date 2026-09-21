<?php

session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);


/*
|--------------------------------------------------------------------------
| Validate Teacher ID
|--------------------------------------------------------------------------
*/

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {

    header('Location: teachers.php');
    exit;
}


$teacher_id = (int) $_GET['id'];


/*
|--------------------------------------------------------------------------
| Get Teacher Information
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "
    SELECT
        t.teacher_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.employment_status,

        u.first_name,
        u.email,
        u.phone

    FROM teachers t

    INNER JOIN users u
        ON t.user_id = u.user_id

    WHERE t.teacher_id = ?

    LIMIT 1
    "
);


mysqli_stmt_bind_param(
    $stmt,
    'i',
    $teacher_id
);


mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$teacher = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| Teacher Not Found
|--------------------------------------------------------------------------
*/

if (!$teacher) {

    header('Location: teachers.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Get All Classes
|--------------------------------------------------------------------------
*/

$classes_result = mysqli_query(
    $conn,
    "
    SELECT
        class_id,
        class_name
    FROM classes
    ORDER BY class_id ASC
    "
);


$classes = [];

while ($row = mysqli_fetch_assoc($classes_result)) {

    $classes[] = $row;
}


/*
|--------------------------------------------------------------------------
| Get All Subjects
|--------------------------------------------------------------------------
*/

$subjects_result = mysqli_query(
    $conn,
    "
    SELECT
        subject_id,
        subject_name
    FROM subjects
    ORDER BY subject_name ASC
    "
);


$subjects = [];

while ($row = mysqli_fetch_assoc($subjects_result)) {

    $subjects[] = $row;
}


/*
|--------------------------------------------------------------------------
| Get Current Class Assignments
|--------------------------------------------------------------------------
*/

$assigned_classes = [];


$stmt = mysqli_prepare(
    $conn,
    "
    SELECT class_id
    FROM teacher_class
    WHERE teacher_id = ?
    "
);


mysqli_stmt_bind_param(
    $stmt,
    'i',
    $teacher_id
);


mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);


while ($row = mysqli_fetch_assoc($result)) {

    $assigned_classes[] =
        (int) $row['class_id'];
}


mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| Get Current Subject Assignments
|--------------------------------------------------------------------------
*/

$assigned_subjects = [];


$stmt = mysqli_prepare(
    $conn,
    "
    SELECT subject_id
    FROM teacher_subject
    WHERE teacher_id = ?
    "
);


mysqli_stmt_bind_param(
    $stmt,
    'i',
    $teacher_id
);


mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);


while ($row = mysqli_fetch_assoc($result)) {

    $assigned_subjects[] =
        (int) $row['subject_id'];
}


mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| Check Academic Responsibility
|--------------------------------------------------------------------------
*/

$is_academic = false;


$stmt = mysqli_prepare(
    $conn,
    "
    SELECT responsibility_id
    FROM teacher_responsibilities
    WHERE teacher_id = ?
    AND responsibility = 'academic'
    LIMIT 1
    "
);


mysqli_stmt_bind_param(
    $stmt,
    'i',
    $teacher_id
);


mysqli_stmt_execute($stmt);

mysqli_stmt_store_result($stmt);


if (mysqli_stmt_num_rows($stmt) > 0) {

    $is_academic = true;
}


mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| Get Current Head Teacher
|--------------------------------------------------------------------------
*/

$current_head_teacher_id = null;


$head_result = mysqli_query(
    $conn,
    "
    SELECT head_teacher_id
    FROM school_settings
    LIMIT 1
    "
);


if ($head_result && mysqli_num_rows($head_result) > 0) {

    $school_settings =
        mysqli_fetch_assoc($head_result);

    $current_head_teacher_id =
        $school_settings['head_teacher_id'];
}


/*
|--------------------------------------------------------------------------
| Check Head Teacher
|--------------------------------------------------------------------------
*/

$is_head_teacher = false;


if (
    $current_head_teacher_id !== null &&
    (int) $current_head_teacher_id === $teacher_id
) {

    $is_head_teacher = true;
}


/*
|--------------------------------------------------------------------------
| Save Assignments
|--------------------------------------------------------------------------
*/

$errors = [];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /*
    |--------------------------------------------------------------------------
    | Get Submitted Data
    |--------------------------------------------------------------------------
    */

    $selected_classes =
        $_POST['classes'] ?? [];


    $selected_subjects =
        $_POST['subjects'] ?? [];


    $academic =
        isset($_POST['academic']);


    $head_teacher =
        isset($_POST['head_teacher']);


    /*
    |--------------------------------------------------------------------------
    | Validate Arrays
    |--------------------------------------------------------------------------
    */

    if (!is_array($selected_classes)) {

        $selected_classes = [];
    }


    if (!is_array($selected_subjects)) {

        $selected_subjects = [];
    }


    /*
    |--------------------------------------------------------------------------
    | Start Transaction
    |--------------------------------------------------------------------------
    */

    mysqli_begin_transaction($conn);


    try {


        /*
        |--------------------------------------------------------------------------
        | Remove Existing Class Assignments
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "
            DELETE FROM teacher_class
            WHERE teacher_id = ?
            "
        );


        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $teacher_id
        );


        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);


        /*
        |--------------------------------------------------------------------------
        | Insert Selected Classes
        |--------------------------------------------------------------------------
        */

        if (!empty($selected_classes)) {

            $stmt = mysqli_prepare(
                $conn,
                "
                INSERT INTO teacher_class
                (
                    teacher_id,
                    class_id
                )
                VALUES
                (
                    ?,
                    ?
                )
                "
            );


            foreach ($selected_classes as $class_id) {

                $class_id =
                    (int) $class_id;


                if ($class_id <= 0) {

                    continue;
                }


                mysqli_stmt_bind_param(
                    $stmt,
                    'ii',
                    $teacher_id,
                    $class_id
                );


                mysqli_stmt_execute($stmt);
            }


            mysqli_stmt_close($stmt);
        }


        /*
        |--------------------------------------------------------------------------
        | Remove Existing Subject Assignments
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "
            DELETE FROM teacher_subject
            WHERE teacher_id = ?
            "
        );


        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $teacher_id
        );


        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);


        /*
        |--------------------------------------------------------------------------
        | Insert Selected Subjects
        |--------------------------------------------------------------------------
        */

        if (!empty($selected_subjects)) {

            $stmt = mysqli_prepare(
                $conn,
                "
                INSERT INTO teacher_subject
                (
                    teacher_id,
                    subject_id
                )
                VALUES
                (
                    ?,
                    ?
                )
                "
            );


            foreach ($selected_subjects as $subject_id) {

                $subject_id =
                    (int) $subject_id;


                if ($subject_id <= 0) {

                    continue;
                }


                mysqli_stmt_bind_param(
                    $stmt,
                    'ii',
                    $teacher_id,
                    $subject_id
                );


                mysqli_stmt_execute($stmt);
            }


            mysqli_stmt_close($stmt);
        }


        /*
        |--------------------------------------------------------------------------
        | Academic Responsibility
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "
            DELETE FROM teacher_responsibilities
            WHERE teacher_id = ?
            AND responsibility = 'academic'
            "
        );


        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $teacher_id
        );


        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);


        if ($academic) {

            $responsibility = 'academic';


            $stmt = mysqli_prepare(
                $conn,
                "
                INSERT INTO teacher_responsibilities
                (
                    teacher_id,
                    responsibility
                )
                VALUES
                (
                    ?,
                    ?
                )
                "
            );


            mysqli_stmt_bind_param(
                $stmt,
                'is',
                $teacher_id,
                $responsibility
            );


            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);
        }


        /*
        |--------------------------------------------------------------------------
        | Head Teacher Assignment
        |--------------------------------------------------------------------------
        */

        if ($head_teacher) {


            /*
            | Remove existing Head Teacher
            | and assign this teacher.
            */

            $stmt = mysqli_prepare(
                $conn,
                "
                UPDATE school_settings
                SET head_teacher_id = ?
                "
            );


            mysqli_stmt_bind_param(
                $stmt,
                'i',
                $teacher_id
            );


            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);


        } else {


            /*
            | If this teacher was Head Teacher,
            | remove the assignment.
            */

            if ($is_head_teacher) {

                mysqli_query(
                    $conn,
                    "
                    UPDATE school_settings
                    SET head_teacher_id = NULL
                    "
                );
            }

        }


        /*
        |--------------------------------------------------------------------------
        | Commit Transaction
        |--------------------------------------------------------------------------
        */

        mysqli_commit($conn);


        header(
            'Location: assign_teacher.php?id=' .
            $teacher_id .
            '&success=1'
        );

        exit;


    } catch (Exception $e) {


        /*
        |--------------------------------------------------------------------------
        | Rollback
        |--------------------------------------------------------------------------
        */

        mysqli_rollback($conn);


        $errors[] =
            'Unable to save assignments: ' .
            $e->getMessage();
    }

}


/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$current_page =
    basename($_SERVER['PHP_SELF']);

?>


<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Assign Teacher | PSRMS
    </title>


    <style>

        * {

            margin: 0;
            padding: 0;
            box-sizing: border-box;

        }


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

            --green: #397154;
            --green-bg: #edf6f0;

            --red: #a04b4b;
            --red-bg: #fbefef;

        }


        body {

            font-family:
                "Segoe UI",
                Arial,
                sans-serif;

            background: var(--cream);

            color: var(--text);

        }


        .main-content {

            margin-left: 255px;

            padding:
                105px
                30px
                40px;

        }


        /*
        |--------------------------------------------------------------------------
        | PAGE HEADER
        |--------------------------------------------------------------------------
        */

        .page-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 25px;

        }


        .page-title h1 {

            color: var(--navy);

            font-size: 25px;

        }


        .page-title p {

            color: var(--muted);

            font-size: 12px;

            margin-top: 5px;

        }


        .back-button {

            padding:
                10px
                16px;

            border:
                1px solid
                var(--border);

            border-radius: 6px;

            background: var(--white);

            color: var(--navy);

            text-decoration: none;

            font-size: 11px;

            font-weight: 650;

        }


        /*
        |--------------------------------------------------------------------------
        | TEACHER CARD
        |--------------------------------------------------------------------------
        */

        .teacher-card {

            background: var(--navy);

            border-radius: 10px;

            padding: 22px;

            color: var(--white);

            margin-bottom: 20px;

            max-width: 1000px;

        }


        .teacher-card small {

            color: var(--gold-light);

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: 1px;

        }


        .teacher-card h2 {

            margin-top: 7px;

            font-size: 20px;

        }


        .teacher-details {

            display: flex;

            flex-wrap: wrap;

            gap: 20px;

            margin-top: 15px;

        }


        .teacher-detail {

            font-size: 11px;

        }


        .teacher-detail span {

            display: block;

            color: #aeb7c8;

            margin-bottom: 3px;

        }


        /*
        |--------------------------------------------------------------------------
        | ALERT
        |--------------------------------------------------------------------------
        */

        .alert {

            max-width: 1000px;

            padding: 14px;

            border-radius: 7px;

            margin-bottom: 18px;

            font-size: 11px;

        }


        .alert-success {

            background: var(--green-bg);

            border:
                1px solid
                #cfe6d6;

            color: var(--green);

        }


        .alert-error {

            background: var(--red-bg);

            border:
                1px solid
                #efd2d2;

            color: var(--red);

        }


        /*
        |--------------------------------------------------------------------------
        | FORM CARD
        |--------------------------------------------------------------------------
        */

        .form-card {

            max-width: 1000px;

            background: var(--white);

            border:
                1px solid
                var(--border);

            border-radius: 10px;

            overflow: hidden;

        }


        .form-section {

            padding: 25px;

            border-bottom:
                1px solid
                var(--border);

        }


        .form-section:last-child {

            border-bottom: none;

        }


        .section-title {

            display: flex;

            align-items: center;

            gap: 10px;

            color: var(--navy);

            font-size: 14px;

            margin-bottom: 6px;

        }


        .section-number {

            width: 25px;
            height: 25px;

            border-radius: 50%;

            display: flex;

            align-items: center;
            justify-content: center;

            background: var(--navy);

            color: var(--gold-light);

            font-size: 10px;

            font-weight: 700;

        }


        .section-description {

            color: var(--muted);

            font-size: 10px;

            margin-left: 35px;

            margin-bottom: 18px;

        }


        /*
        |--------------------------------------------------------------------------
        | CHECKBOX GRID
        |--------------------------------------------------------------------------
        */

        .checkbox-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 12px;

        }


        .checkbox-item {

            position: relative;

        }


        .checkbox-item input {

            position: absolute;

            opacity: 0;

        }


        .checkbox-item label {

            min-height: 48px;

            display: flex;

            align-items: center;

            padding:
                0
                15px;

            border:
                1px solid
                var(--border);

            border-radius: 7px;

            background: #fcfcfb;

            color: var(--text);

            cursor: pointer;

            font-size: 11px;

            font-weight: 650;

            transition: .2s;

        }


        .checkbox-item input:checked + label {

            border-color: var(--gold);

            background:
                rgba(201,162,39,.08);

            color: var(--navy);

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIBILITIES
        |--------------------------------------------------------------------------
        */

        .responsibility-box {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 15px;

        }


        .responsibility-item {

            position: relative;

        }


        .responsibility-item input {

            position: absolute;

            opacity: 0;

        }


        .responsibility-item label {

            display: block;

            padding: 18px;

            border:
                1px solid
                var(--border);

            border-radius: 8px;

            background: #fcfcfb;

            cursor: pointer;

            transition: .2s;

        }


        .responsibility-item input:checked + label {

            border-color: var(--gold);

            background:
                rgba(201,162,39,.08);

        }


        .responsibility-item strong {

            display: block;

            color: var(--navy);

            font-size: 12px;

            margin-bottom: 5px;

        }


        .responsibility-item span {

            color: var(--muted);

            font-size: 10px;

            line-height: 1.5;

        }


        /*
        |--------------------------------------------------------------------------
        | FOOTER
        |--------------------------------------------------------------------------
        */

        .form-footer {

            display: flex;

            justify-content: flex-end;

            gap: 10px;

            padding: 18px 25px;

            background: #fafaf8;

        }


        .cancel-button {

            padding:
                11px
                18px;

            border:
                1px solid
                var(--border);

            border-radius: 6px;

            background: var(--white);

            color: var(--muted);

            text-decoration: none;

            font-size: 11px;

        }


        .save-button {

            padding:
                11px
                22px;

            border: none;

            border-radius: 6px;

            background: var(--navy);

            color: var(--white);

            cursor: pointer;

            font-size: 11px;

            font-weight: 700;

        }


        .save-button:hover {

            background: var(--navy-dark);

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 900px) {

            .main-content {

                margin-left: 0;

                padding:
                    100px
                    18px
                    30px;

            }


            .checkbox-grid {

                grid-template-columns:
                    repeat(2, 1fr);

            }

        }


        @media (max-width: 600px) {

            .page-header {

                align-items: flex-start;

                flex-direction: column;

                gap: 12px;

            }


            .checkbox-grid {

                grid-template-columns: 1fr;

            }


            .responsibility-box {

                grid-template-columns: 1fr;

            }


            .teacher-details {

                flex-direction: column;

                gap: 10px;

            }

        }

    </style>

</head>


<body>


<?php include 'admin_sidebar.php'; ?>


<?php include 'admin_topbar.php'; ?>


<main class="main-content">


    <div class="page-header">

        <div class="page-title">

            <h1>
                Assign Teacher
            </h1>

            <p>
                Manage teaching classes, subjects and additional responsibilities.
            </p>

        </div>


        <a
            href="teachers.php"
            class="back-button"
        >

            ← Back to Teachers

        </a>

    </div>


    <!-- TEACHER INFORMATION -->

    <div class="teacher-card">

        <small>
            Selected Teacher
        </small>


        <h2>

            <?php
            echo htmlspecialchars(
                $teacher['full_name']
            );
            ?>

        </h2>


        <div class="teacher-details">


            <div class="teacher-detail">

                <span>
                    Employee Number
                </span>

                <?php
                echo htmlspecialchars(
                    $teacher['employee_no']
                );
                ?>

            </div>


            <div class="teacher-detail">

                <span>
                    Qualification
                </span>

                <?php
                echo htmlspecialchars(
                    $teacher['qualification']
                );
                ?>

            </div>


            <div class="teacher-detail">

                <span>
                    Specialization
                </span>

                <?php
                echo htmlspecialchars(
                    $teacher['specialization']
                );
                ?>

            </div>


        </div>

    </div>


    <!-- SUCCESS MESSAGE -->

    <?php if (isset($_GET['success'])): ?>

        <div class="alert alert-success">

            Teacher assignments saved successfully.

        </div>

    <?php endif; ?>


    <!-- ERROR MESSAGE -->

    <?php if (!empty($errors)): ?>

        <div class="alert alert-error">

            <?php foreach ($errors as $error): ?>

                <p>

                    <?php
                    echo htmlspecialchars($error);
                    ?>

                </p>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- ASSIGNMENT FORM -->

    <form
        method="POST"
        class="form-card"
    >


        <!-- CLASSES -->

        <div class="form-section">


            <div class="section-title">

                <span class="section-number">
                    1
                </span>

                Assign Classes

            </div>


            <p class="section-description">

                Select all classes this teacher is allowed to teach.

            </p>


            <div class="checkbox-grid">


                <?php foreach ($classes as $class): ?>


                    <div class="checkbox-item">

                        <input
                            type="checkbox"
                            id="class_<?php echo $class['class_id']; ?>"
                            name="classes[]"
                            value="<?php echo $class['class_id']; ?>"

                            <?php
                            echo in_array(
                                (int) $class['class_id'],
                                $assigned_classes
                            )
                            ? 'checked'
                            : '';
                            ?>
                        >


                        <label
                            for="class_<?php echo $class['class_id']; ?>"
                        >

                            <?php
                            echo htmlspecialchars(
                                $class['class_name']
                            );
                            ?>

                        </label>

                    </div>


                <?php endforeach; ?>


            </div>

        </div>


        <!-- SUBJECTS -->

        <div class="form-section">


            <div class="section-title">

                <span class="section-number">
                    2
                </span>

                Assign Subjects

            </div>


            <p class="section-description">

                Select subjects this teacher will teach.

            </p>


            <div class="checkbox-grid">


                <?php foreach ($subjects as $subject): ?>


                    <div class="checkbox-item">

                        <input
                            type="checkbox"
                            id="subject_<?php echo $subject['subject_id']; ?>"
                            name="subjects[]"
                            value="<?php echo $subject['subject_id']; ?>"

                            <?php
                            echo in_array(
                                (int) $subject['subject_id'],
                                $assigned_subjects
                            )
                            ? 'checked'
                            : '';
                            ?>
                        >


                        <label
                            for="subject_<?php echo $subject['subject_id']; ?>"
                        >

                            <?php
                            echo htmlspecialchars(
                                $subject['subject_name']
                            );
                            ?>

                        </label>

                    </div>


                <?php endforeach; ?>


            </div>

        </div>


        <!-- RESPONSIBILITIES -->

        <div class="form-section">


            <div class="section-title">

                <span class="section-number">
                    3
                </span>

                Additional Responsibilities

            </div>


            <p class="section-description">

                Assign additional school responsibilities to this teacher.

            </p>


            <div class="responsibility-box">


                <div class="responsibility-item">

                    <input
                        type="checkbox"
                        id="academic"
                        name="academic"

                        <?php
                        echo $is_academic
                            ? 'checked'
                            : '';
                        ?>
                    >


                    <label for="academic">

                        <strong>
                            Academic
                        </strong>

                        <span>

                            Can monitor academic activities,
                            review marks and access academic reports.

                        </span>

                    </label>

                </div>


                <div class="responsibility-item">

                    <input
                        type="checkbox"
                        id="head_teacher"
                        name="head_teacher"

                        <?php
                        echo $is_head_teacher
                            ? 'checked'
                            : '';
                        ?>
                    >


                    <label for="head_teacher">

                        <strong>
                            Head Teacher
                        </strong>

                        <span>

                            Assign this teacher as the current
                            Head Teacher of the school.

                            Only one Head Teacher can exist at a time.

                        </span>

                    </label>

                </div>


            </div>

        </div>


        <!-- FORM FOOTER -->

        <div class="form-footer">


            <a
                href="teachers.php"
                class="cancel-button"
            >
                Cancel
            </a>


            <button
                type="submit"
                class="save-button"
            >
                Save Assignments
            </button>


        </div>


    </form>


</main>


</body>

</html>
