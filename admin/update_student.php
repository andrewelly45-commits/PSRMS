<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

header('Content-Type: application/json');

function fail(string $msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request method');
}

$id              = (int) ($_POST['student_id'] ?? 0);
$admission_no    = trim($_POST['admission_no'] ?? '');
$full_name       = trim($_POST['full_name'] ?? '');
$gender          = $_POST['gender'] ?? '';
$date_of_birth   = trim($_POST['date_of_birth'] ?? '');
$class_id        = $_POST['class_id'] ?? '';
$admission_date  = trim($_POST['admission_date'] ?? '');
$status          = $_POST['status'] ?? 'active';

if ($id <= 0) fail('Invalid student ID');

$errors = [];

if ($admission_no === '') {
    $errors[] = 'Admission number is required.';
} elseif (strlen($admission_no) > 50) {
    $errors[] = 'Admission number is too long.';
}

if ($full_name === '') {
    $errors[] = 'Full name is required.';
} elseif (!preg_match("/^[a-zA-Z\s\-'.]+$/", $full_name)) {
    $errors[] = 'Full name contains invalid characters.';
}

if (!in_array($gender, ['Male', 'Female'], true)) {
    $errors[] = 'Please select a valid gender.';
}

if ($date_of_birth !== '' && !DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
    $errors[] = 'Invalid date of birth.';
}
if ($admission_date !== '' && !DateTime::createFromFormat('Y-m-d', $admission_date)) {
    $errors[] = 'Invalid admission date.';
}
if ($class_id !== '' && !ctype_digit((string)$class_id)) {
    $errors[] = 'Invalid class.';
}
if (!in_array($status, ['active', 'inactive', 'graduated', 'transferred'], true)) {
    $status = 'active';
}

if (!empty($errors)) {
    fail(implode(' ', $errors));
}

/* Duplicate admission_no check (excluding self) */
$stmt = mysqli_prepare(
    $conn,
    "SELECT student_id FROM students WHERE admission_no = ? AND student_id <> ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'si', $admission_no, $id);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
if (mysqli_stmt_num_rows($stmt) > 0) {
    mysqli_stmt_close($stmt);
    fail('Another student already uses this admission number.');
}
mysqli_stmt_close($stmt);

$dob  = $date_of_birth  !== '' ? $date_of_birth  : null;
$date = $admission_date !== '' ? $admission_date : null;
$cid  = $class_id       !== '' ? (int) $class_id : null;

$stmt = mysqli_prepare(
    $conn,
    "UPDATE students
     SET admission_no = ?,
         full_name = ?,
         gender = ?,
         date_of_birth = ?,
         class_id = ?,
         admission_date = ?,
         status = ?
     WHERE student_id = ?"
);

mysqli_stmt_bind_param(
    $stmt,
    'ssssissi',
    $admission_no,
    $full_name,
    $gender,
    $dob,
    $cid,
    $date,
    $status,
    $id
);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    fail('Could not update: ' . $err);
}
mysqli_stmt_close($stmt);

/* Return the updated record with class label */
$stmt = mysqli_prepare(
    $conn,
    "SELECT s.*, c.class_name, c.stream
     FROM students s
     LEFT JOIN classes c ON c.class_id = s.class_id
     WHERE s.student_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$row['class_label'] = $row['class_name']
    ? $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '')
    : 'Not Assigned';

echo json_encode(['success' => true, 'student' => $row]);