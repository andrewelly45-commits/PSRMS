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

$id         = (int) ($_POST['student_id'] ?? 0);
$new_class  = (int) ($_POST['class_id']   ?? 0);
$note       = trim($_POST['note'] ?? '');
$mark_transferred = isset($_POST['mark_transferred']) && $_POST['mark_transferred'] === '1';

if ($id <= 0) fail('Invalid student ID');

/* If transferring, we need a new class */
if ($new_class <= 0) {
    fail('Please select a class to transfer to.');
}

/* Confirm class exists */
$stmt = mysqli_prepare(
    $conn,
    "SELECT class_id FROM classes WHERE class_id = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $new_class);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
if (mysqli_stmt_num_rows($stmt) === 0) {
    mysqli_stmt_close($stmt);
    fail('Selected class does not exist.');
}
mysqli_stmt_close($stmt);

/* Get current student to verify */
$stmt = mysqli_prepare(
    $conn,
    "SELECT student_id, class_id, status FROM students WHERE student_id = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$cur = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$cur) fail('Student not found.');

/* Update class_id and optionally status */
if ($mark_transferred) {
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE students SET class_id = ?, status = 'transferred' WHERE student_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $new_class, $id);
} else {
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE students SET class_id = ? WHERE student_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $new_class, $id);
}

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    fail('Could not transfer: ' . $err);
}
mysqli_stmt_close($stmt);

/* Return updated record */
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