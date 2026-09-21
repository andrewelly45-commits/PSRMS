<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        s.student_id,
        s.admission_no,
        s.full_name,
        s.gender,
        s.date_of_birth,
        s.class_id,
        s.admission_date,
        s.photo,
        s.status,
        s.created_at,
        c.class_name,
        c.stream,
        c.class_level
     FROM students s
     LEFT JOIN classes c ON c.class_id = s.class_id
     WHERE s.student_id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

/* Build display fields */
$row['class_label'] = $row['class_name']
    ? $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '')
    : 'Not Assigned';

$row['photo_url'] = !empty($row['photo'])
    ? '../uploads/students/' . $row['photo']
    : '';

$row['date_of_birth_fmt'] = !empty($row['date_of_birth'])
    ? date('d M Y', strtotime($row['date_of_birth']))
    : '';

$row['admission_date_fmt'] = !empty($row['admission_date'])
    ? date('d M Y', strtotime($row['admission_date']))
    : '';

$row['created_at_fmt'] = !empty($row['created_at'])
    ? date('d M Y', strtotime($row['created_at']))
    : '';

echo json_encode(['success' => true, 'student' => $row]);