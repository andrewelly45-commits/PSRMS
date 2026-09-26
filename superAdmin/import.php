<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');   // super_admin auto-passes

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function tableExists(mysqli $conn, string $t): bool {
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safe_t = mysqli_real_escape_string($conn, $table);
    $safe_c = mysqli_real_escape_string($conn, $column);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_t` LIKE '$safe_c'");
    return $r && mysqli_num_rows($r) > 0;
}


/* =========================================================================
   IMPORT DEFINITIONS
   Each target defines:
     - label, icon
     - required: [column => [label, required?]]
     - sample:   example row
     - process:  callback to validate/insert a single row (returns [ok, error])
   ========================================================================= */

$import_targets = [

    'students' => [
        'label'  => 'Students',
        'icon'   => 'fa-user-graduate',
        'color'  => 'blue',
        'desc'   => 'Import students with admission number, name, class, and status.',
        'columns' => [
            'admission_no' => ['Admission No',    true],
            'full_name'    => ['Full Name',       true],
            'class_name'   => ['Class',           true],   // resolved to class_id
            'gender'       => ['Gender',          false],  // male/female/other
            'date_of_birth'=> ['Date of Birth',   false],  // YYYY-MM-DD
            'status'       => ['Status',          false],  // active/inactive
        ],
        'sample' => [
            'admission_no' => 'PS/2025/001',
            'full_name'    => 'John Doe',
            'class_name'   => 'Form 1',
            'gender'       => 'male',
            'date_of_birth'=> '2010-03-15',
            'status'       => 'active',
        ],
    ],

    'teachers' => [
        'label'  => 'Teachers',
        'icon'   => 'fa-chalkboard-user',
        'color'  => 'green',
        'desc'   => 'Import teachers. Creates a user account with a default password.',
        'columns' => [
            'first_name'    => ['First Name',    true],
            'middle_name'   => ['Middle Name',   false],
            'last_name'     => ['Last Name',     true],
            'email'         => ['Email',         true],
            'phone'         => ['Phone',         false],
            'gender'        => ['Gender',        false],
            'qualification' => ['Qualification', false],
            'password'      => ['Password',      false],   // default: ChangeMe123!
        ],
        'sample' => [
            'first_name'    => 'Jane',
            'middle_name'   => '',
            'last_name'     => 'Smith',
            'email'         => 'jane.smith@school.test',
            'phone'         => '0700000001',
            'gender'        => 'female',
            'qualification' => 'B.Ed',
            'password'      => 'ChangeMe123!',
        ],
    ],

    'parents' => [
        'label'  => 'Parents',
        'icon'   => 'fa-user-group',
        'color'  => 'orange',
        'desc'   => 'Import parent/guardian accounts. Optionally link to a student by admission no.',
        'columns' => [
            'first_name'    => ['First Name',    true],
            'middle_name'   => ['Middle Name',   false],
            'last_name'     => ['Last Name',     true],
            'email'         => ['Email',         true],
            'phone'         => ['Phone',         false],
            'occupation'    => ['Occupation',    false],
            'address'       => ['Address',       false],
            'student_adm'   => ['Student Adm No',false],   // links via parent_children
            'relationship'  => ['Relationship',  false],   // Father/Mother/Guardian/Other
            'is_primary'    => ['Primary Guardian (1/0)', false],
            'password'      => ['Password',      false],
        ],
        'sample' => [
            'first_name'    => 'Michael',
            'middle_name'   => '',
            'last_name'     => 'Doe',
            'email'         => 'michael.doe@school.test',
            'phone'         => '0700000002',
            'occupation'    => 'Engineer',
            'address'       => '123 Main St',
            'student_adm'   => 'PS/2025/001',
            'relationship'  => 'Father',
            'is_primary'    => '1',
            'password'      => 'ChangeMe123!',
        ],
    ],

    'subjects' => [
        'label'  => 'Subjects',
        'icon'   => 'fa-book',
        'color'  => 'purple',
        'desc'   => 'Import subjects with name, type, and status.',
        'columns' => [
            'subject_name' => ['Subject Name', true],
            'subject_type' => ['Type',         false],
            'status'       => ['Status',       false],
        ],
        'sample' => [
            'subject_name' => 'Mathematics',
            'subject_type' => 'academic',
            'status'       => 'active',
        ],
    ],

    'classes' => [
        'label'  => 'Classes',
        'icon'   => 'fa-school',
        'color'  => 'gold',
        'desc'   => 'Import school classes with level, stream, and status.',
        'columns' => [
            'class_name'  => ['Class Name', true],
            'class_level' => ['Level',      true],
            'stream'      => ['Stream',     false],
            'status'      => ['Status',     false],
        ],
        'sample' => [
            'class_name'  => 'Form 1',
            'class_level' => '1',
            'stream'      => 'A',
            'status'      => 'active',
        ],
    ],

];


/* =========================================================================
   CSV PARSER
   ========================================================================= */

function parseCsv(string $filepath, int $max_rows = 5000): array {
    $rows = [];
    if (($fh = fopen($filepath, 'r')) === false) {
        return ['error' => 'Could not open file.'];
    }

    /* Detect delimiter from first line */
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['error' => 'Empty file.'];
    }

    $delims = [',' => 0, ';' => 0, "\t" => 0];
    foreach ($delims as $d => $c) {
        $delims[$d] = substr_count($first, $d);
    }
    arsort($delims);
    $delimiter = key($delims) ?: ',';

    rewind($fh);

    /* Headers */
    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers) {
        fclose($fh);
        return ['error' => 'Could not read headers.'];
    }

    $headers = array_map(function ($h) {
        $h = trim((string)$h);
        $h = preg_replace('/[^a-z0-9_]+/i', '_', $h);
        return strtolower(trim($h, '_'));
    }, $headers);

    /* Rows */
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false && count($rows) < $max_rows) {
        /* Skip empty rows */
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;

        $assoc = [];
        foreach ($headers as $i => $key) {
            $assoc[$key] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
        $rows[] = $assoc;
    }

    fclose($fh);

    return [
        'headers' => $headers,
        'rows'    => $rows,
        'count'   => count($rows),
    ];
}


/* =========================================================================
   ROW VALIDATION + INSERTION
   ========================================================================= */

function processRow(mysqli $conn, string $target, array $row, bool $dry_run): array {

    $first = fn(string $v) => trim((string)$v);

    /* ------------------------------------------------------------------
       STUDENTS
       ------------------------------------------------------------------ */
    if ($target === 'students') {

        $adm   = $first($row['admission_no'] ?? '');
        $name  = $first($row['full_name']    ?? '');
        $cls   = $first($row['class_name']   ?? '');
        $gen   = strtolower($first($row['gender'] ?? ''));
        $dob   = $first($row['date_of_birth']?? '');
        $stat  = strtolower($first($row['status'] ?? 'active'));

        if ($adm === '')  return ['ok' => false, 'error' => 'Missing admission_no'];
        if ($name === '') return ['ok' => false, 'error' => 'Missing full_name'];
        if ($cls === '')  return ['ok' => false, 'error' => 'Missing class_name'];

        /* Class lookup */
        $stmt = mysqli_prepare($conn, "SELECT class_id FROM classes WHERE class_name = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $cls);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $c = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);

        if (!$c) return ['ok' => false, 'error' => "Unknown class: $cls"];
        $class_id = (int)$c['class_id'];

        /* Gender normalization */
        if (!in_array($gen, ['male','female','other'], true)) $gen = null;
        /* Status */
        if (!in_array($stat, ['active','inactive','graduated','transferred'], true)) $stat = 'active';

        /* Duplicate admission_no check */
        $stmt = mysqli_prepare($conn, "SELECT student_id FROM students WHERE admission_no = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $adm);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($exists) return ['ok' => false, 'error' => "Duplicate admission_no: $adm"];

        if ($dry_run) return ['ok' => true, 'error' => null];

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO students (admission_no, full_name, class_id, gender, date_of_birth, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return ['ok' => false, 'error' => mysqli_error($conn)];

        $dob_val = ($dob !== '') ? $dob : null;
        mysqli_stmt_bind_param($stmt, 'ssisss', $adm, $name, $class_id, $gen, $dob_val, $stat);
        $ok = mysqli_stmt_execute($stmt);
        $err = $ok ? null : mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        return ['ok' => $ok, 'error' => $err];
    }

    /* ------------------------------------------------------------------
       TEACHERS
       ------------------------------------------------------------------ */
    if ($target === 'teachers') {

        $fn = $first($row['first_name']  ?? '');
        $mn = $first($row['middle_name'] ?? '');
        $ln = $first($row['last_name']   ?? '');
        $em = $first($row['email']       ?? '');
        $ph = $first($row['phone']       ?? '');
        $ge = strtolower($first($row['gender'] ?? ''));
        $qu = $first($row['qualification'] ?? '');
        $pw = $first($row['password'] ?? 'ChangeMe123!');

        if ($fn === '') return ['ok' => false, 'error' => 'Missing first_name'];
        if ($ln === '') return ['ok' => false, 'error' => 'Missing last_name'];
        if ($em === '') return ['ok' => false, 'error' => 'Missing email'];
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => "Invalid email: $em"];
        if (strlen($pw) < 6) $pw = 'ChangeMe123!';
        if (!in_array($ge, ['male','female','other'], true)) $ge = null;

        /* Duplicate email */
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $em);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($exists) return ['ok' => false, 'error' => "Email already exists: $em"];

        if ($dry_run) return ['ok' => true, 'error' => null];

        mysqli_begin_transaction($conn);

        try {
            $hash = password_hash($pw, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO users (first_name, middle_name, last_name, email, phone, password, role, gender, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?, 'active')"
            );
            mysqli_stmt_bind_param($stmt, 'sssssss',
                $fn, $mn, $ln, $em, $ph, $hash, $ge);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_stmt_error($stmt));
            }
            $new_user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            if (tableExists($conn, 'teachers') && columnExists($conn, 'teachers', 'qualification')) {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO teachers (user_id, qualification) VALUES (?, ?)"
                );
                mysqli_stmt_bind_param($stmt, 'is', $new_user_id, $qu);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);
            return ['ok' => true, 'error' => null];

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => $ex->getMessage()];
        }
    }

    /* ------------------------------------------------------------------
       PARENTS
       ------------------------------------------------------------------ */
    if ($target === 'parents') {

        $fn = $first($row['first_name']  ?? '');
        $mn = $first($row['middle_name'] ?? '');
        $ln = $first($row['last_name']   ?? '');
        $em = $first($row['email']       ?? '');
        $ph = $first($row['phone']       ?? '');
        $oc = $first($row['occupation']  ?? '');
        $ad = $first($row['address']     ?? '');
        $sa = $first($row['student_adm'] ?? '');
        $rel = $first($row['relationship'] ?? 'Guardian');
        $prim = (int)($first($row['is_primary'] ?? '0')) ? 1 : 0;
        $pw = $first($row['password'] ?? 'ChangeMe123!');

        if ($fn === '') return ['ok' => false, 'error' => 'Missing first_name'];
        if ($ln === '') return ['ok' => false, 'error' => 'Missing last_name'];
        if ($em === '') return ['ok' => false, 'error' => 'Missing email'];
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => "Invalid email: $em"];
        if (strlen($pw) < 6) $pw = 'ChangeMe123!';
        if (!in_array($rel, ['Father','Mother','Guardian','Other'], true)) $rel = 'Guardian';

        /* Duplicate email */
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $em);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($exists) return ['ok' => false, 'error' => "Email already exists: $em"];

        /* Student lookup (optional) */
        $student_id = 0;
        if ($sa !== '') {
            $stmt = mysqli_prepare($conn, "SELECT student_id FROM students WHERE admission_no = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $sa);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            if ($row2 = mysqli_fetch_assoc($r)) $student_id = (int)$row2['student_id'];
            mysqli_stmt_close($stmt);
            if ($student_id === 0) return ['ok' => false, 'error' => "Student not found: $sa"];
        }

        if ($dry_run) return ['ok' => true, 'error' => null];

        mysqli_begin_transaction($conn);

        try {
            $hash = password_hash($pw, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO users (first_name, middle_name, last_name, email, phone, password, role, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'parent', 'active')"
            );
            mysqli_stmt_bind_param($stmt, 'ssssss',
                $fn, $mn, $ln, $em, $ph, $hash);
            if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
            $new_user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            /* Parent row */
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO parents (user_id, occupation, address) VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iss', $new_user_id, $oc, $ad);
            mysqli_stmt_execute($stmt);
            $parent_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            /* Link student */
            if ($student_id > 0 && tableExists($conn, 'parent_children')) {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO parent_children (parent_id, student_id, relationship, is_primary_guardian)
                     VALUES (?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param($stmt, 'iisi', $parent_id, $student_id, $rel, $prim);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);
            return ['ok' => true, 'error' => null];

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => $ex->getMessage()];
        }
    }

    /* ------------------------------------------------------------------
       SUBJECTS
       ------------------------------------------------------------------ */
    if ($target === 'subjects') {

        $name = $first($row['subject_name'] ?? '');
        $type = strtolower($first($row['subject_type'] ?? 'academic'));
        $stat = strtolower($first($row['status'] ?? 'active'));

        if ($name === '') return ['ok' => false, 'error' => 'Missing subject_name'];

        $valid_types = ['academic','competency','science','business','arts','language','technical','religious','vocational','other'];
        if (!in_array($type, $valid_types, true)) $type = 'academic';
        if (!in_array($stat, ['active','inactive'], true)) $stat = 'active';

        /* Duplicate check */
        $stmt = mysqli_prepare($conn, "SELECT subject_id FROM subjects WHERE subject_name = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $name);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($exists) return ['ok' => false, 'error' => "Duplicate subject: $name"];

        if ($dry_run) return ['ok' => true, 'error' => null];

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO subjects (subject_name, subject_type, status) VALUES (?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'sss', $name, $type, $stat);
        $ok = mysqli_stmt_execute($stmt);
        $err = $ok ? null : mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        return ['ok' => $ok, 'error' => $err];
    }

    /* ------------------------------------------------------------------
       CLASSES
       ------------------------------------------------------------------ */
    if ($target === 'classes') {

        $name  = $first($row['class_name'] ?? '');
        $level = (int)$first($row['class_level'] ?? '0');
        $str   = $first($row['stream'] ?? '');
        $stat  = strtolower($first($row['status'] ?? 'active'));

        if ($name === '') return ['ok' => false, 'error' => 'Missing class_name'];
        if ($level <= 0)  return ['ok' => false, 'error' => 'Invalid class_level'];

        if (!in_array($stat, ['active','inactive'], true)) $stat = 'active';

        /* Duplicate check (name + stream) */
        $stmt = mysqli_prepare(
            $conn,
            "SELECT class_id FROM classes WHERE class_name = ? AND IFNULL(stream, '') = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'ss', $name, $str);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($exists) return ['ok' => false, 'error' => "Duplicate class: $name $str"];

        if ($dry_run) return ['ok' => true, 'error' => null];

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO classes (class_name, class_level, stream, status) VALUES (?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'siss', $name, $level, $str, $stat);
        $ok = mysqli_stmt_execute($stmt);
        $err = $ok ? null : mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        return ['ok' => $ok, 'error' => $err];
    }

    return ['ok' => false, 'error' => 'Unknown target'];
}


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       PARSE + VALIDATE (preview)
    ------------------------------------------------------------- */
    if ($action === 'preview') {

        $target = trim($_POST['target'] ?? '');
        if (!isset($import_targets[$target])) {
            json_response(['success' => false, 'message' => 'Invalid import target.']);
        }

        if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'No file uploaded.']);
        }

        $file = $_FILES['csv'];

        if ($file['size'] > 5 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'File must be under 5 MB.']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'], true)) {
            json_response(['success' => false, 'message' => 'Only .csv or .txt allowed.']);
        }

        $parsed = parseCsv($file['tmp_name'], 5000);

        if (!empty($parsed['error'])) {
            json_response(['success' => false, 'message' => $parsed['error']]);
        }

        /* Required column validation */
        $required = [];
        foreach ($import_targets[$target]['columns'] as $col => $meta) {
            if (!empty($meta[1])) $required[] = $col;
        }

        $missing_cols = [];
        foreach ($required as $rq) {
            if (!in_array($rq, $parsed['headers'], true)) $missing_cols[] = $rq;
        }

        if (!empty($missing_cols)) {
            json_response([
                'success' => false,
                'message' => 'Missing required column(s): ' . implode(', ', $missing_cols),
            ]);
        }

        /* Dry-run each row */
        $valid = [];
        $invalid = [];

        foreach ($parsed['rows'] as $i => $row) {
            $res = processRow($conn, $target, $row, true);
            $line = [
                'line' => $i + 2,
                'data' => $row,
                'error'=> $res['error'] ?? null,
            ];
            if ($res['ok']) $valid[] = $line;
            else $invalid[] = $line;

            /* Preview cap: keep memory low */
            if (count($valid) + count($invalid) >= 500) break;
        }

        json_response([
            'success' => true,
            'headers' => $parsed['headers'],
            'total'   => $parsed['count'],
            'valid'   => $valid,
            'invalid' => $invalid,
            'valid_count'   => count($valid),
            'invalid_count' => count($invalid),
        ]);
    }

    /* -------------------------------------------------------------
       EXECUTE IMPORT
    ------------------------------------------------------------- */
    if ($action === 'execute') {

        $target = trim($_POST['target'] ?? '');
        if (!isset($import_targets[$target])) {
            json_response(['success' => false, 'message' => 'Invalid import target.']);
        }

        if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'No file uploaded.']);
        }

        $file = $_FILES['csv'];

        if ($file['size'] > 5 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'File must be under 5 MB.']);
        }

        $parsed = parseCsv($file['tmp_name'], 5000);
        if (!empty($parsed['error'])) {
            json_response(['success' => false, 'message' => $parsed['error']]);
        }

        $inserted = 0;
        $failed   = 0;
        $errors   = [];

        mysqli_begin_transaction($conn);

        try {
            foreach ($parsed['rows'] as $i => $row) {
                $res = processRow($conn, $target, $row, false);

                if ($res['ok']) {
                    $inserted++;
                } else {
                    $failed++;
                    if (count($errors) < 20) {
                        $errors[] = 'Line ' . ($i + 2) . ': ' . ($res['error'] ?? 'Unknown error');
                    }
                }
            }

            mysqli_commit($conn);

            /* Log to audit trail if table exists */
            if (tableExists($conn, 'audit_log')) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                $desc = "Imported $inserted $target row(s); $failed failed";
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO audit_log (user_id, role, action, target_type, description, ip_address, user_agent)
                     VALUES (?, ?, 'import.execute', ?, ?, ?, ?)"
                );
                if ($stmt) {
                    $role = $_SESSION['role'] ?? 'admin';
                    mysqli_stmt_bind_param($stmt, 'isssss',
                        $user_id, $role, $target, $desc, $ip, $ua);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }

            json_response([
                'success'  => true,
                'message'  => "Imported {$inserted} row(s). {$failed} failed.",
                'inserted' => $inserted,
                'failed'   => $failed,
                'errors'   => $errors,
            ]);

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            json_response([
                'success' => false,
                'message' => 'Import failed: ' . $ex->getMessage(),
            ]);
        }
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   SAMPLE CSV DOWNLOADS
   ========================================================================= */

if (!empty($_GET['sample'])) {

    $target = $_GET['sample'];
    if (!isset($import_targets[$target])) {
        http_response_code(404);
        exit('Unknown sample.');
    }

    $columns = array_keys($import_targets[$target]['columns']);
    $sample  = $import_targets[$target]['sample'];

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $target . '_sample.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    fputcsv($out, array_map(fn($c) => $sample[$c] ?? '', $columns));
    fclose($out);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Bulk Import | PSRMS Admin</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
          crossorigin="anonymous" referrerpolicy="no-referrer">

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
            --orange: #9a7422;
            --orange-bg: #faf5e8;
            --blue: #2f5d8f;
            --blue-bg: #eaf1fa;
            --purple: #5a4a8f;
            --purple-bg: #f0eefa;
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

        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* HEADER */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            line-height: 1.2;
        }

        .page-title h1 i { color: var(--gold); font-size: 22px; flex-shrink: 0; }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 6px;
        }

        /* STEP INDICATOR */
        .steps {
            display: flex;
            gap: 8px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .step {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 30px;
            background: var(--white);
            border: 1px solid var(--border);
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            transition: .15s ease;
        }

        .step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #eef0f5;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10.5px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .step.active {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }
        .step.active .step-num {
            background: var(--gold);
            color: var(--navy);
        }

        .step.done {
            background: var(--green-bg);
            border-color: #cfe5d7;
            color: var(--green);
        }
        .step.done .step-num {
            background: var(--green);
            color: var(--white);
        }

        /* PANEL */
        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .panel-header {
            padding: 16px 22px;
            background: #fcfcfa;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-header h2 {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .panel-header h2 i { color: var(--gold); font-size: 14px; }

        .panel-body { padding: 22px; }

        /* TARGET GRID */
        .target-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
        }

        .target-card {
            background: #fcfcfd;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: .15s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .target-card:hover {
            border-color: var(--gold);
            background: var(--white);
        }

        .target-card.active {
            border-color: var(--navy);
            background: #fbf8ee;
        }

        .target-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            margin-bottom: 12px;
        }

        .target-icon.blue   { background: var(--blue-bg);    color: var(--blue); }
        .target-icon.green  { background: var(--green-bg);   color: var(--green); }
        .target-icon.orange { background: var(--orange-bg);  color: var(--orange); }
        .target-icon.purple { background: var(--purple-bg);  color: var(--purple); }
        .target-icon.gold   { background: var(--gold-light); color: var(--navy); }

        .target-name {
            color: var(--navy);
            font-size: 13.5px;
            font-weight: 750;
            margin-bottom: 4px;
        }

        .target-desc {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
        }

        /* FORM */
        .form-group { margin-bottom: 16px; }
        .form-group:last-child { margin-bottom: 0; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

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
            transition: .15s ease;
            -webkit-appearance: none;
        }

        input[type="file"].form-control {
            padding: 10px 12px;
            height: auto;
            line-height: 1.4;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .form-help {
            color: var(--muted);
            font-size: 11px;
            margin-top: 6px;
            line-height: 1.5;
        }

        /* BTNS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 0 22px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: .15s ease;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover:not(:disabled) { background: var(--navy-dark); }

        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover:not(:disabled) { background: var(--gold-light); }

        .btn-green { background: var(--green); color: var(--white); }
        .btn-green:hover:not(:disabled) { background: #2f5c42; }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { border-color: var(--gold); }

        .btn .spinner {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }
        .btn.loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            margin-top: 20px;
        }

        /* STATS BAR */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-chip {
            background: #fcfcfd;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .stat-chip .ico {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .stat-chip .ico.blue   { background: var(--blue-bg);  color: var(--blue); }
        .stat-chip .ico.green  { background: var(--green-bg); color: var(--green); }
        .stat-chip .ico.red    { background: var(--red-bg);   color: var(--red); }

        .stat-chip .num {
            color: var(--navy);
            font-size: 16px;
            font-weight: 800;
            line-height: 1;
        }

        .stat-chip .lbl {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-top: 3px;
        }

        /* PREVIEW TABLE */
        .preview-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--border);
            border-radius: 10px;
            max-height: 420px;
            overflow-y: auto;
        }

        table.preview-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
            font-size: 12px;
        }

        table.preview-table thead th {
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1;
        }

        table.preview-table tbody td {
            padding: 9px 12px;
            border-bottom: 1px solid #f0f1f3;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 11.5px;
            color: var(--text);
            white-space: nowrap;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.preview-table tbody tr.valid { background: rgba(62,118,85,.03); }
        table.preview-table tbody tr.invalid { background: rgba(155,71,71,.04); }
        table.preview-table tbody tr:last-child td { border-bottom: none; }

        .row-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 6px;
            font-size: 11px;
        }
        .row-status.ok  { background: var(--green-bg); color: var(--green); }
        .row-status.err { background: var(--red-bg);   color: var(--red); }

        .row-error {
            color: var(--red);
            font-size: 10.5px;
            font-family: inherit;
            white-space: normal;
            max-width: 260px;
            line-height: 1.4;
        }

        /* SUCCESS PANEL */
        .success-panel {
            background: linear-gradient(135deg, var(--green-bg) 0%, #e4f0e7 100%);
            border: 1px solid #cfe5d7;
            border-radius: 12px;
            padding: 28px;
            text-align: center;
        }

        .success-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: var(--white);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            box-shadow: 0 8px 24px rgba(62,118,85,.15);
        }

        .success-title {
            color: var(--green);
            font-size: 18px;
            font-weight: 750;
            margin-bottom: 6px;
        }

        .success-text {
            color: var(--text);
            font-size: 13px;
            line-height: 1.55;
            margin-bottom: 20px;
        }

        .success-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ERROR BOX */
        .error-box {
            background: var(--red-bg);
            border: 1px solid #efd2d2;
            border-left: 3px solid var(--red);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 12px;
            color: var(--red);
            line-height: 1.55;
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }

        .error-box i { margin-top: 2px; flex-shrink: 0; }

        .error-list {
            margin: 6px 0 0 18px;
            padding: 0;
            font-size: 11.5px;
        }

        .error-list li { margin-bottom: 4px; }

        /* HELP BOX */
        .help-box {
            background: #f4f8fc;
            border: 1px solid #d5e4f0;
            border-left: 3px solid var(--blue);
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 12.5px;
            color: var(--blue);
            line-height: 1.6;
            margin-bottom: 18px;
        }

        .help-box strong { color: var(--navy); }

        .help-cols {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .help-col {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 3px 9px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 10.5px;
            color: var(--navy);
            font-weight: 600;
        }

        .help-col.req {
            background: var(--gold-light);
            border-color: var(--gold);
            color: var(--navy);
        }

        /* TOASTS */
        .toast-wrap {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-left: 3px solid var(--green);
            border-radius: 9px;
            box-shadow: 0 10px 30px rgba(16,24,43,.12);
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            min-width: 260px;
            max-width: 380px;
            pointer-events: auto;
            animation: slideIn .25s ease;
        }

        .toast i { color: var(--green); font-size: 14px; flex-shrink: 0; }
        .toast.error { border-left-color: var(--red); }
        .toast.error i { color: var(--red); }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }

        /* RESPONSIVE */
        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-title h1 i { font-size: 18px; }
            .page-title p  { font-size: 12px; }

            .steps { gap: 6px; }
            .step { padding: 7px 11px; font-size: 11.5px; }
            .step-num { width: 20px; height: 20px; font-size: 10px; }

            .panel-header { padding: 14px 16px; }
            .panel-header h2 { font-size: 13px; }

            .panel-body { padding: 18px; }

            .target-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .target-card { padding: 14px; }
            .target-icon { width: 36px; height: 36px; font-size: 15px; margin-bottom: 10px; }
            .target-name { font-size: 13px; }
            .target-desc { font-size: 10.5px; }

            .stats-bar { grid-template-columns: 1fr; gap: 8px; }
            .stat-chip { padding: 10px 12px; }

            .form-control { height: 46px; font-size: 14px; }

            .btn-row { flex-direction: column; }
            .btn-row .btn { width: 100%; }

            .preview-wrapper { max-height: 340px; }

            .success-actions { flex-direction: column; }
            .success-actions .btn { width: 100%; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .target-grid { grid-template-columns: 1fr; }

            .step { padding: 6px 10px; font-size: 11px; }
            .step .step-num { width: 18px; height: 18px; font-size: 9.5px; }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast { min-width: auto; max-width: 100%; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .toast-wrap {
                    top: max(10px, env(safe-area-inset-top));
                    right: max(10px, env(safe-area-inset-right));
                }
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Bulk Import';
$topbar_subtitle = 'Import CSV data';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-file-import"></i> Bulk Import</h1>
            <p>Import students, teachers, parents, subjects, or classes from a CSV file.</p>
        </div>
    </div>

    <!-- STEP INDICATOR -->
    <div class="steps" id="stepIndicator">
        <div class="step active" data-step="1">
            <span class="step-num">1</span>
            Choose type
        </div>
        <div class="step" data-step="2">
            <span class="step-num">2</span>
            Upload CSV
        </div>
        <div class="step" data-step="3">
            <span class="step-num">3</span>
            Preview &amp; confirm
        </div>
    </div>

    <!-- STEP 1: CHOOSE TARGET -->
    <section class="panel" id="step1">
        <div class="panel-header">
            <h2><i class="fa-solid fa-layer-group"></i> What would you like to import?</h2>
        </div>

        <div class="panel-body">
            <div class="target-grid">
                <?php foreach ($import_targets as $key => $t): ?>
                    <a href="#" class="target-card" data-target="<?php echo e($key); ?>">
                        <div class="target-icon <?php echo e($t['color']); ?>">
                            <i class="fa-solid <?php echo e($t['icon']); ?>"></i>
                        </div>
                        <div class="target-name"><?php echo e($t['label']); ?></div>
                        <div class="target-desc"><?php echo e($t['desc']); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- STEP 2: UPLOAD -->
    <section class="panel" id="step2" style="display:none;">
        <div class="panel-header">
            <h2 id="uploadTitle"><i class="fa-solid fa-cloud-arrow-up"></i> Upload CSV</h2>
            <a href="#" class="btn btn-ghost" id="changeTargetBtn" style="min-height:36px;padding:0 14px;font-size:12px;">
                <i class="fa-solid fa-arrow-left"></i> Change type
            </a>
        </div>

        <div class="panel-body">

            <div class="help-box" id="helpBox">
                <strong>Required and optional columns:</strong>
                <div class="help-cols" id="helpCols"></div>

                <div style="margin-top:12px;">
                    <a href="#" class="btn btn-ghost" id="sampleDownloadBtn" style="min-height:36px;padding:0 14px;font-size:12px;">
                        <i class="fa-solid fa-download"></i> Download sample CSV
                    </a>
                </div>
            </div>

            <form id="uploadForm" onsubmit="return false;">
                <div class="form-group">
                    <label>CSV File <span style="color:var(--red);">*</span></label>
                    <input type="file" id="csvFile" name="csv" class="form-control" accept=".csv,.txt" required>
                    <div class="form-help">
                        Max 5 MB · Up to 5,000 rows per file. The first row must be the column headers.
                    </div>
                </div>

                <div class="btn-row">
                    <button type="button" class="btn btn-primary" id="previewBtn">
                        <span class="spinner"></span>
                        <i class="fa-solid fa-eye"></i> Preview &amp; Validate
                    </button>
                </div>
            </form>

        </div>
    </section>

    <!-- STEP 3: PREVIEW -->
    <section class="panel" id="step3" style="display:none;">
        <div class="panel-header">
            <h2><i class="fa-solid fa-clipboard-check"></i> Preview &amp; Confirm</h2>
            <a href="#" class="btn btn-ghost" id="backToUploadBtn" style="min-height:36px;padding:0 14px;font-size:12px;">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>

        <div class="panel-body">

            <div id="previewError" class="error-box" style="display:none;"></div>

            <div class="stats-bar" id="previewStats">
                <div class="stat-chip">
                    <div class="ico blue"><i class="fa-solid fa-list-ol"></i></div>
                    <div>
                        <div class="num" id="statTotal">0</div>
                        <div class="lbl">Total Rows</div>
                    </div>
                </div>
                <div class="stat-chip">
                    <div class="ico green"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="num" id="statValid">0</div>
                        <div class="lbl">Valid</div>
                    </div>
                </div>
                <div class="stat-chip">
                    <div class="ico red"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div>
                        <div class="num" id="statInvalid">0</div>
                        <div class="lbl">Invalid</div>
                    </div>
                </div>
            </div>

            <div class="preview-wrapper" id="previewWrapper">
                <!-- Preview table injected here -->
            </div>

            <div class="btn-row">
                <button type="button" class="btn btn-green" id="importBtn">
                    <span class="spinner"></span>
                    <i class="fa-solid fa-check"></i>
                    <span id="importBtnLabel">Import Valid Rows</span>
                </button>
                <a href="import.php" class="btn btn-ghost">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </a>
            </div>

        </div>
    </section>

    <!-- SUCCESS -->
    <section class="panel" id="successPanel" style="display:none;">
        <div class="panel-body">
            <div class="success-panel">
                <div class="success-icon"><i class="fa-solid fa-check"></i></div>
                <div class="success-title" id="successTitle">Import Complete</div>
                <div class="success-text" id="successText"></div>
                <div class="success-actions">
                    <a href="import.php" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i> Import More
                    </a>
                    <a href="dashboard.php" class="btn btn-ghost">
                        <i class="fa-solid fa-house"></i> Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </section>

</main>

<div class="toast-wrap" id="toastWrap"></div>

<script>
/* =========================================================================
   DATA
   ========================================================================= */
const IMPORT_TARGETS = <?php
    $simple = [];
    foreach ($import_targets as $k => $t) {
        $simple[$k] = [
            'label'   => $t['label'],
            'columns' => $t['columns'],
        ];
    }
    echo json_encode($simple, JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

let currentTarget = null;
let currentFile   = null;


/* =========================================================================
   TOASTS
   ========================================================================= */
function showToast(msg, type = 'success', timeout = 3000) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : '');
    el.innerHTML = '<i class="fa-solid ' +
        (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') +
        '"></i><span>' + msg + '</span>';
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s, transform .25s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}


/* =========================================================================
   STEP MANAGEMENT
   ========================================================================= */
function setStep(step) {
    document.querySelectorAll('.steps .step').forEach(el => {
        const n = parseInt(el.dataset.step, 10);
        el.classList.remove('active', 'done');
        if (n < step) el.classList.add('done');
        else if (n === step) el.classList.add('active');
    });

    document.getElementById('step1').style.display         = step === 1 ? 'block' : 'none';
    document.getElementById('step2').style.display         = step === 2 ? 'block' : 'none';
    document.getElementById('step3').style.display         = step === 3 ? 'block' : 'none';
    document.getElementById('successPanel').style.display  = step === 4 ? 'block' : 'none';
}


/* =========================================================================
   STEP 1: CHOOSE TARGET
   ========================================================================= */
document.querySelectorAll('.target-card').forEach(card => {
    card.addEventListener('click', e => {
        e.preventDefault();
        const key = card.dataset.target;
        if (!IMPORT_TARGETS[key]) return;

        currentTarget = key;
        currentFile   = null;

        /* Update upload panel */
        const target = IMPORT_TARGETS[key];
        document.getElementById('uploadTitle').innerHTML =
            '<i class="fa-solid fa-cloud-arrow-up"></i> Upload ' + target.label + ' CSV';

        /* Sample download link */
        document.getElementById('sampleDownloadBtn').href =
            'import.php?sample=' + key;

        /* Help columns */
        const helpCols = document.getElementById('helpCols');
        helpCols.innerHTML = '';
        Object.entries(target.columns).forEach(([col, meta]) => {
            const tag = document.createElement('span');
            tag.className = 'help-col' + (meta[1] ? ' req' : '');
            tag.textContent = col + (meta[1] ? ' *' : '');
            tag.title = meta[0] + (meta[1] ? ' (required)' : ' (optional)');
            helpCols.appendChild(tag);
        });

        /* Reset file input */
        document.getElementById('csvFile').value = '';

        setStep(2);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});

document.getElementById('changeTargetBtn')?.addEventListener('click', e => {
    e.preventDefault();
    currentTarget = null;
    currentFile = null;
    setStep(1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

document.getElementById('backToUploadBtn')?.addEventListener('click', e => {
    e.preventDefault();
    setStep(2);
    window.scrollTo({ top: 0, behavior: 'smooth' });
});


/* =========================================================================
   STEP 2: PREVIEW
   ========================================================================= */
document.getElementById('previewBtn').addEventListener('click', async function () {
    if (!currentTarget) {
        showToast('Please choose an import type first.', 'error');
        return;
    }

    const fileInput = document.getElementById('csvFile');
    if (!fileInput.files || !fileInput.files[0]) {
        showToast('Please select a CSV file.', 'error');
        return;
    }

    const file = fileInput.files[0];

    if (file.size > 5 * 1024 * 1024) {
        showToast('File must be under 5 MB.', 'error');
        return;
    }

    currentFile = file;

    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    const fd = new FormData();
    fd.set('ajax_action', 'preview');
    fd.set('target', currentTarget);
    fd.set('csv', file);

    try {
        const res = await fetch('import.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Preview failed.', 'error');
            return;
        }

        /* Populate stats */
        document.getElementById('statTotal').textContent   = json.total;
        document.getElementById('statValid').textContent   = json.valid_count;
        document.getElementById('statInvalid').textContent = json.invalid_count;

        /* Render preview table */
        renderPreview(json);

        /* Import button label */
        const importBtnLabel = document.getElementById('importBtnLabel');
        if (json.valid_count === 0) {
            importBtnLabel.textContent = 'No valid rows to import';
            document.getElementById('importBtn').disabled = true;
        } else {
            importBtnLabel.textContent = 'Import ' + json.valid_count + ' Valid Rows';
            document.getElementById('importBtn').disabled = false;
        }

        setStep(3);
        window.scrollTo({ top: 0, behavior: 'smooth' });

    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});


/* =========================================================================
   RENDER PREVIEW TABLE
   ========================================================================= */
function renderPreview(json) {
    const headers = json.headers;
    const rows    = [];

    json.valid.forEach(r   => rows.push({ ...r, ok: true  }));
    json.invalid.forEach(r => rows.push({ ...r, ok: false }));

    /* Sort by line number */
    rows.sort((a, b) => a.line - b.line);

    /* Cap display to 200 rows for performance */
    const cap = 200;
    const shown = rows.slice(0, cap);

    let html = '<table class="preview-table"><thead><tr>';
    html += '<th style="width:60px;">Line</th>';
    html += '<th style="width:50px;"></th>';
    headers.forEach(h => {
        html += '<th>' + escapeHtml(h) + '</th>';
    });
    html += '<th style="width:200px;">Status</th>';
    html += '</tr></thead><tbody>';

    shown.forEach(r => {
        const rowClass = r.ok ? 'valid' : 'invalid';
        html += '<tr class="' + rowClass + '">';
        html += '<td>' + r.line + '</td>';
        html += '<td><span class="row-status ' + (r.ok ? 'ok' : 'err') + '">' +
                '<i class="fa-solid ' + (r.ok ? 'fa-check' : 'fa-xmark') + '"></i>' +
                '</span></td>';

        headers.forEach(h => {
            let v = r.data[h] ?? '';
            if (v === null) v = '';
            html += '<td title="' + escapeHtml(String(v)) + '">' + escapeHtml(String(v)) + '</td>';
        });

        html += '<td>';
        if (r.ok) {
            html += '<span style="color:var(--green);font-weight:700;font-size:11px;">✓ Ready</span>';
        } else {
            html += '<div class="row-error">' + escapeHtml(r.error || 'Invalid') + '</div>';
        }
        html += '</td>';
        html += '</tr>';
    });

    html += '</tbody></table>';

    if (rows.length > cap) {
        html += '<div style="padding:12px 16px;text-align:center;font-size:11.5px;color:var(--muted);background:#fcfcfa;border-top:1px solid var(--border);">' +
                'Showing first ' + cap + ' of ' + rows.length + ' rows' +
                '</div>';
    }

    document.getElementById('previewWrapper').innerHTML = html;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}


/* =========================================================================
   STEP 3: EXECUTE IMPORT
   ========================================================================= */
document.getElementById('importBtn').addEventListener('click', async function () {
    if (!currentTarget || !currentFile) {
        showToast('Session lost. Please upload again.', 'error');
        return;
    }

    if (!confirm('This will insert the valid rows into the database. Continue?')) {
        return;
    }

    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');

    const fd = new FormData();
    fd.set('ajax_action', 'execute');
    fd.set('target', currentTarget);
    fd.set('csv', currentFile);

    try {
        const res = await fetch('import.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Import failed.', 'error');
            return;
        }

        /* Success screen */
        const label = IMPORT_TARGETS[currentTarget].label;
        document.getElementById('successTitle').textContent =
            json.failed > 0 ? 'Import Completed with Warnings' : 'Import Complete';

        let text = '<strong>' + json.inserted + '</strong> ' + label + ' imported successfully.';
        if (json.failed > 0) {
            text += ' <strong>' + json.failed + '</strong> rows could not be imported.';
        }
        document.getElementById('successText').innerHTML = text;

        setStep(4);
        window.scrollTo({ top: 0, behavior: 'smooth' });

        if (json.failed > 0 && json.errors && json.errors.length) {
            /* Show first few errors in a toast */
            showToast(json.errors[0], 'error', 6000);
        }

    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = original;
    }
});


/* =========================================================================
   MOBILE SIDEBAR — handled by includes/topbar.php
   ========================================================================= */
</script>

</body>
</html>