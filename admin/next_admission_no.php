<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

header('Content-Type: application/json');

$class_level = isset($_GET['class_level']) ? (int) $_GET['class_level'] : 0;
$year        = isset($_GET['year'])        ? (int) $_GET['year']        : 0;

if ($class_level <= 0 || $year <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$prefix = $year . '/' . $class_level . '/';
$like   = $prefix . '%';

$stmt = mysqli_prepare(
    $conn,
    "SELECT admission_no
     FROM students
     WHERE admission_no LIKE ?
     ORDER BY admission_no DESC
     LIMIT 1"
);

if (!$stmt) {
    echo json_encode(['error' => 'Query failed']);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $like);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$next_seq = 1;

if ($row = mysqli_fetch_assoc($res)) {
    $parts = explode('/', $row['admission_no']);
    $last  = (int) end($parts);
    $next_seq = $last + 1;
}

mysqli_stmt_close($stmt);

$admission_no = $prefix . str_pad((string)$next_seq, 3, '0', STR_PAD_LEFT);

echo json_encode([
    'success'      => true,
    'admission_no' => $admission_no,
    'prefix'       => $prefix,
    'sequence'     => $next_seq,
]);