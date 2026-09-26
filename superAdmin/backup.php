<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('super_admin');

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

function humanSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}

function backupDir(): string {
    $dir = __DIR__ . '/../backups/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function logAuditLocal(
    mysqli $conn,
    int $user_id,
    string $role,
    string $action,
    ?string $target_type = null,
    ?int $target_id = null,
    ?string $description = null
): void {
    if (!tableExists($conn, 'audit_log')) return;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO audit_log
            (user_id, role, action, target_type, target_id, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) return;

    mysqli_stmt_bind_param(
        $stmt, 'isssisss',
        $user_id, $role, $action, $target_type, $target_id, $description, $ip, $ua
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   ENSURE backups TABLE + FOLDER
   ========================================================================= */

if (!tableExists($conn, 'backups')) {
    mysqli_query(
        $conn,
        "CREATE TABLE backups (
            backup_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename    VARCHAR(255) NOT NULL,
            size_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            table_count INT NOT NULL DEFAULT 0,
            row_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_by  INT NULL,
            notes       VARCHAR(255) NULL,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (backup_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

$backup_dir = backupDir();
if (!is_writable($backup_dir)) {
    @chmod($backup_dir, 0755);
}


/* =========================================================================
   GET TABLE LIST
   ========================================================================= */

$all_tables = [];
$r = mysqli_query($conn, "SHOW TABLES");
if ($r) {
    while ($row = mysqli_fetch_row($r)) {
        $all_tables[] = $row[0];
    }
    sort($all_tables);
}


/* =========================================================================
   BUILD SQL DUMP
   ========================================================================= */

function buildSqlDump(mysqli $conn, array $tables): array {
    $sql = "";
    $row_count = 0;

    $sql .= "-- PSRMS Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Tables: " . count($tables) . "\n";
    $sql .= "--\n\n";

    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql .= "SET AUTOCOMMIT = 0;\n";
    $sql .= "START TRANSACTION;\n";
    $sql .= "SET time_zone = \"+00:00\";\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {

        /* Table structure */
        $safe_table = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW CREATE TABLE `$safe_table`");
        if (!$res) continue;

        $create = mysqli_fetch_assoc($res);
        $create_sql = $create['Create Table'] ?? '';

        $sql .= "-- --------------------------------------------------------\n";
        $sql .= "-- Table structure for `$table`\n";
        $sql .= "-- --------------------------------------------------------\n\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create_sql . ";\n\n";

        /* Table data */
        $res = mysqli_query($conn, "SELECT * FROM `$safe_table`");
        if (!$res) continue;

        $num_rows = mysqli_num_rows($res);

        if ($num_rows > 0) {
            $sql .= "-- Dumping data for table `$table`\n";

            $batch = [];
            $columns = null;

            while ($row = mysqli_fetch_assoc($res)) {
                if ($columns === null) {
                    $columns = array_keys($row);
                }

                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . mysqli_real_escape_string($conn, $value) . "'";
                    }
                }
                $batch[] = '(' . implode(', ', $values) . ')';
                $row_count++;

                /* Flush every 100 rows */
                if (count($batch) >= 100) {
                    $col_list = '`' . implode('`, `', $columns) . '`';
                    $sql .= "INSERT INTO `$table` ($col_list) VALUES\n" . implode(",\n", $batch) . ";\n";
                    $batch = [];
                }
            }

            /* Flush remainder */
            if (!empty($batch)) {
                $col_list = '`' . implode('`, `', $columns) . '`';
                $sql .= "INSERT INTO `$table` ($col_list) VALUES\n" . implode(",\n", $batch) . ";\n";
            }

            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $sql .= "COMMIT;\n";

    return [
        'sql'       => $sql,
        'row_count' => $row_count,
        'size'      => strlen($sql),
    ];
}


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       CREATE BACKUP
    ------------------------------------------------------------- */
    if ($action === 'create_backup') {

        $mode         = $_POST['mode']   ?? 'all';   // 'all' or 'selected'
        $selected     = $_POST['tables'] ?? [];
        $notes        = trim($_POST['notes'] ?? '');
        $download_now = !empty($_POST['download_now']);

        if (!is_array($selected)) $selected = [];

        if ($mode === 'selected') {
            $tables = array_values(array_intersect($all_tables, $selected));
        } else {
            $tables = $all_tables;
        }

        if (empty($tables)) {
            json_response(['success' => false, 'message' => 'No tables selected.']);
        }

        $dump = buildSqlDump($conn, $tables);
        $sql  = $dump['sql'];

        $filename = 'psrms_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
        $filepath = $backup_dir . $filename;

        if (file_put_contents($filepath, $sql) === false) {
            json_response(['success' => false, 'message' => 'Could not write backup file. Check folder permissions.']);
        }

        $size = filesize($filepath);

        /* Save record */
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO backups (filename, size_bytes, table_count, row_count, created_by, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt, 'siiiis',
                $filename, $size, count($tables), (int)$dump['row_count'], $user_id, $notes
            );
            mysqli_stmt_execute($stmt);
            $backup_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        } else {
            $backup_id = 0;
        }

        logAuditLocal(
            $conn, $user_id, $_SESSION['role'] ?? 'super_admin',
            'backup.create', 'backup', $backup_id,
            "Created backup ({$mode}, " . count($tables) . " tables, {$dump['row_count']} rows)"
        );

        json_response([
            'success'   => true,
            'message'   => 'Backup created successfully.',
            'backup_id' => $backup_id,
            'filename'  => $filename,
            'size'      => humanSize($size),
            'size_raw'  => $size,
            'tables'    => count($tables),
            'rows'      => (int)$dump['row_count'],
            'download_url' => 'backup.php?download=' . urlencode($filename),
            'download_now' => $download_now,
        ]);
    }

    /* -------------------------------------------------------------
       DELETE BACKUP
    ------------------------------------------------------------- */
    if ($action === 'delete_backup') {

        $backup_id = (int)($_POST['backup_id'] ?? 0);
        if ($backup_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid backup.']);
        }

        /* Fetch filename */
        $stmt = mysqli_prepare($conn, "SELECT filename FROM backups WHERE backup_id = ? LIMIT 1");
        $filename = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $backup_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($r)) {
                $filename = $row['filename'];
            }
            mysqli_stmt_close($stmt);
        }

        if (!$filename) {
            json_response(['success' => false, 'message' => 'Backup not found.']);
        }

        /* Delete file */
        $path = $backup_dir . $filename;
        if (is_file($path)) @unlink($path);

        /* Delete record */
        $stmt = mysqli_prepare($conn, "DELETE FROM backups WHERE backup_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $backup_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        logAuditLocal(
            $conn, $user_id, $_SESSION['role'] ?? 'super_admin',
            'backup.delete', 'backup', $backup_id,
            "Deleted backup: $filename"
        );

        json_response([
            'success' => true,
            'message' => 'Backup deleted.',
        ]);
    }

    /* -------------------------------------------------------------
       RESTORE FROM BACKUP
    ------------------------------------------------------------- */
    if ($action === 'restore_backup') {

        $backup_id = (int)($_POST['backup_id'] ?? 0);
        $confirm   = trim($_POST['confirm_text'] ?? '');

        if ($confirm !== 'RESTORE') {
            json_response(['success' => false, 'message' => 'Please type RESTORE to confirm.']);
        }

        if ($backup_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid backup.']);
        }

        /* Fetch filename */
        $stmt = mysqli_prepare($conn, "SELECT filename FROM backups WHERE backup_id = ? LIMIT 1");
        $filename = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $backup_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($r)) {
                $filename = $row['filename'];
            }
            mysqli_stmt_close($stmt);
        }

        if (!$filename || !is_file($backup_dir . $filename)) {
            json_response(['success' => false, 'message' => 'Backup file missing on server.']);
        }

        $sql = file_get_contents($backup_dir . $filename);
        if ($sql === false || $sql === '') {
            json_response(['success' => false, 'message' => 'Could not read backup file.']);
        }

        /* Split into statements (naive but works for our dumps) */
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $in_string = false;
        $string_char = '';

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($in_string) {
                $buffer .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $buffer .= $sql[$i + 1];
                    $i++;
                    continue;
                }
                if ($c === $string_char) $in_string = false;
                continue;
            }

            if ($c === "'" || $c === '"') {
                $in_string = true;
                $string_char = $c;
                $buffer .= $c;
                continue;
            }

            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                /* Skip to end of line */
                while ($i < $len && $sql[$i] !== "\n") $i++;
                continue;
            }

            if ($c === ';') {
                $stmt_text = trim($buffer);
                if ($stmt_text !== '') $statements[] = $stmt_text;
                $buffer = '';
                continue;
            }

            $buffer .= $c;
        }

        if (trim($buffer) !== '') $statements[] = trim($buffer);

        /* Execute */
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");

        $executed = 0;
        $errors   = [];

        foreach ($statements as $stmt_sql) {
            if (trim($stmt_sql) === '') continue;

            /* Skip comments */
            $first_line = strtok($stmt_sql, "\n");
            if (strpos(trim($first_line), '--') === 0) continue;

            if (!mysqli_query($conn, $stmt_sql)) {
                $errors[] = mysqli_error($conn);
                if (count($errors) >= 5) break;
            } else {
                $executed++;
            }
        }

        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");

        logAuditLocal(
            $conn, $user_id, $_SESSION['role'] ?? 'super_admin',
            'backup.restore', 'backup', $backup_id,
            "Restored from backup: $filename ($executed statements)"
        );

        if (!empty($errors)) {
            json_response([
                'success'  => false,
                'message'  => 'Restore finished with ' . count($errors) . ' error(s).',
                'executed' => $executed,
                'errors'   => $errors,
            ]);
        }

        json_response([
            'success'  => true,
            'message'  => "Restore completed. {$executed} statement(s) executed.",
            'executed' => $executed,
        ]);
    }

    /* -------------------------------------------------------------
       UPLOAD + RESTORE FROM UPLOAD
    ------------------------------------------------------------- */
    if ($action === 'upload_restore') {

        $confirm = trim($_POST['confirm_text'] ?? '');
        if ($confirm !== 'RESTORE') {
            json_response(['success' => false, 'message' => 'Please type RESTORE to confirm.']);
        }

        if (empty($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'No file uploaded.']);
        }

        $file = $_FILES['sql_file'];

        if ($file['size'] > 50 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'File must be under 50 MB.']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            json_response(['success' => false, 'message' => 'Only .sql files are allowed.']);
        }

        $sql = file_get_contents($file['tmp_name']);
        if ($sql === false || $sql === '') {
            json_response(['success' => false, 'message' => 'Empty file.']);
        }

        /* Save as a backup record first */
        $filename = 'uploaded_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
        $filepath = $backup_dir . $filename;
        file_put_contents($filepath, $sql);

        $size = filesize($filepath);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO backups (filename, size_bytes, table_count, row_count, created_by, notes)
             VALUES (?, ?, 0, 0, ?, 'Uploaded & restored')"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sii', $filename, $size, $user_id);
            mysqli_stmt_execute($stmt);
            $backup_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        } else {
            $backup_id = 0;
        }

        /* Reuse the same executor */
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $in_string = false;
        $string_char = '';

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($in_string) {
                $buffer .= $c;
                if ($c === '\\' && $i + 1 < $len) { $buffer .= $sql[$i + 1]; $i++; continue; }
                if ($c === $string_char) $in_string = false;
                continue;
            }

            if ($c === "'" || $c === '"') {
                $in_string = true;
                $string_char = $c;
                $buffer .= $c;
                continue;
            }

            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") $i++;
                continue;
            }

            if ($c === ';') {
                $stmt_text = trim($buffer);
                if ($stmt_text !== '') $statements[] = $stmt_text;
                $buffer = '';
                continue;
            }

            $buffer .= $c;
        }
        if (trim($buffer) !== '') $statements[] = trim($buffer);

        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $executed = 0;
        $errors   = [];

        foreach ($statements as $stmt_sql) {
            if (trim($stmt_sql) === '') continue;
            $first_line = strtok($stmt_sql, "\n");
            if (strpos(trim($first_line), '--') === 0) continue;

            if (!mysqli_query($conn, $stmt_sql)) {
                $errors[] = mysqli_error($conn);
                if (count($errors) >= 5) break;
            } else {
                $executed++;
            }
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");

        logAuditLocal(
            $conn, $user_id, $_SESSION['role'] ?? 'super_admin',
            'backup.upload', 'backup', $backup_id,
            "Uploaded & restored: {$file['name']} ($executed statements)"
        );

        if (!empty($errors)) {
            json_response([
                'success'  => false,
                'message'  => 'Restore finished with errors.',
                'executed' => $executed,
                'errors'   => $errors,
            ]);
        }

        json_response([
            'success'  => true,
            'message'  => "Restore completed. {$executed} statement(s) executed.",
            'executed' => $executed,
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   DOWNLOAD BACKUP (GET)
   ========================================================================= */

if (!empty($_GET['download'])) {

    $filename = basename($_GET['download']);
    $path     = $backup_dir . $filename;

    if (!is_file($path)) {
        http_response_code(404);
        exit('Backup not found.');
    }

    /* Verify it's in our DB */
    $stmt = mysqli_prepare($conn, "SELECT backup_id FROM backups WHERE filename = ? LIMIT 1");
    $found = false;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $filename);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $found = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
    }

    if (!$found) {
        http_response_code(404);
        exit('Backup not registered.');
    }

    logAuditLocal(
        $conn, $user_id, $_SESSION['role'] ?? 'super_admin',
        'backup.download', null, null,
        "Downloaded backup: $filename"
    );

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($path);
    exit;
}


/* =========================================================================
   LOAD BACKUP HISTORY
   ========================================================================= */

$backups = [];
$r = mysqli_query(
    $conn,
    "SELECT
        b.backup_id, b.filename, b.size_bytes, b.table_count, b.row_count,
        b.notes, b.created_at,
        u.first_name, u.middle_name, u.last_name
     FROM backups b
     LEFT JOIN users u ON u.user_id = b.created_by
     ORDER BY b.created_at DESC
     LIMIT 100"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $backups[] = $row;
    }
}


/* =========================================================================
   STATS
   ========================================================================= */

$stats = [
    'total_backups' => count($backups),
    'total_size'    => 0,
    'last_backup'   => null,
    'tables'        => count($all_tables),
];

foreach ($backups as $b) {
    $stats['total_size'] += (int)$b['size_bytes'];
}
if (!empty($backups)) {
    $stats['last_backup'] = $backups[0]['created_at'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Backup &amp; Restore | Super Admin</title>

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

        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: .15s ease;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 6px 18px rgba(23,35,60,.05);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            background: var(--blue-bg);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .stat-icon.gold  { background: var(--gold-light); color: var(--navy); }
        .stat-icon.green { background: var(--green-bg);   color: var(--green); }
        .stat-icon.orange{ background: var(--orange-bg);  color: var(--orange); }

        .stat-body { min-width: 0; }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 20px;
            font-weight: 750;
            margin-top: 3px;
            line-height: 1.15;
        }

        /* GRID 2COL */
        .grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* PANEL */
        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
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

        .panel-header .meta {
            font-size: 11.5px;
            color: var(--muted);
        }

        .panel-body { padding: 22px; }

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
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 70px;
            padding: 10px 12px;
            resize: vertical;
            line-height: 1.5;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* RADIO PILLS */
        .radio-row {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .radio-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 14px;
            border-radius: 30px;
            border: 1px solid var(--border);
            background: var(--white);
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--navy);
            transition: .15s ease;
            user-select: none;
        }

        .radio-pill input { display: none; }

        .radio-pill:hover { border-color: var(--gold); }

        .radio-pill.on {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }

        .radio-pill .dot {
            width: 12px;
            height: 12px;
            border: 2px solid #cfd4dc;
            border-radius: 50%;
        }

        .radio-pill.on .dot {
            border-color: var(--gold-light);
            background: radial-gradient(circle, var(--gold-light) 40%, transparent 45%);
        }

        /* TABLES PICKER */
        .tables-picker {
            max-height: 260px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfcfd;
            padding: 6px;
        }

        .table-check {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 12.5px;
            transition: .1s;
        }

        .table-check:hover { background: #f2f4f8; }

        .table-check input {
            width: 16px;
            height: 16px;
            accent-color: var(--navy);
            cursor: pointer;
        }

        .table-check-name {
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            color: var(--navy);
            font-size: 12px;
            font-weight: 600;
        }

        .picker-toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .picker-btn {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 5px 11px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            color: var(--navy);
            cursor: pointer;
            transition: .15s ease;
        }

        .picker-btn:hover { border-color: var(--gold); }

        /* CHECKBOX */
        .checkbox-line {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            color: var(--navy);
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            margin-top: 6px;
        }

        .checkbox-line input {
            width: 16px; height: 16px;
            accent-color: var(--gold);
            cursor: pointer;
        }

        /* BTNS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 0 20px;
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

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover:not(:disabled) { border-color: var(--gold); }

        .btn-danger { background: var(--red); color: var(--white); }
        .btn-danger:hover:not(:disabled) { background: #863a3a; }

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

        .btn-block { width: 100%; }

        /* BACKUP LIST */
        .backup-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 22px;
            border-bottom: 1px solid #f0f1f3;
            transition: .15s ease;
        }

        .backup-item:last-child { border-bottom: none; }

        .backup-item:hover { background: #fbfbf8; }

        .backup-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--gold-light);
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .backup-info { flex: 1; min-width: 0; }

        .backup-name {
            color: var(--navy);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 4px;
            overflow-wrap: anywhere;
        }

        .backup-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 14px;
            font-size: 10.5px;
            color: var(--muted);
        }

        .backup-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .backup-meta span i {
            color: var(--gold);
            font-size: 9.5px;
        }

        .backup-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--navy);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: .15s ease;
            text-decoration: none;
        }

        .icon-btn:hover { border-color: var(--gold); }

        .icon-btn.danger { color: var(--red); }
        .icon-btn.danger:hover { border-color: var(--red); background: var(--red-bg); }

        .icon-btn.success { color: var(--green); }
        .icon-btn.success:hover { border-color: var(--green); background: var(--green-bg); }

        /* EMPTY */
        .empty {
            padding: 50px 24px;
            text-align: center;
            color: var(--muted);
        }
        .empty-icon {
            width: 60px; height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .empty h3 { color: var(--navy); font-size: 14.5px; margin-bottom: 5px; }
        .empty p { font-size: 12px; line-height: 1.55; }

        /* WARNING BOX */
        .warn-box {
            background: #fdf5dd;
            border: 1px solid #ecd9a8;
            border-left: 3px solid var(--orange);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 12px;
            color: var(--orange);
            line-height: 1.55;
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }
        .warn-box i { margin-top: 2px; font-size: 14px; flex-shrink: 0; }

        /* MODAL */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(16,24,43,.55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-backdrop.open { display: flex; }

        .modal {
            background: var(--white);
            border-radius: 14px;
            width: 100%;
            max-width: 520px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.3);
            animation: pop .2s ease-out;
        }

        @keyframes pop {
            from { transform: scale(.96); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 1;
            border-radius: 14px 14px 0 0;
        }

        .modal-header h3 {
            color: var(--navy);
            font-size: 15px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i { color: var(--gold); }

        .modal-close {
            width: 34px; height: 34px;
            border: none;
            background: #f3f4f6;
            border-radius: 8px;
            color: var(--muted);
            font-size: 16px;
            cursor: pointer;
        }
        .modal-close:hover { background: #e5e7eb; color: var(--navy); }

        .modal-body { padding: 22px; }

        .modal-footer {
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            background: #fafaf8;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            border-radius: 0 0 14px 14px;
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
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2col  { grid-template-columns: 1fr; }
        }

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

            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 12px; gap: 10px; }
            .stat-icon { width: 36px; height: 36px; font-size: 14px; }
            .stat-card .value { font-size: 17px; }
            .stat-card .label { font-size: 9px; }

            .panel-header { padding: 14px 16px; }
            .panel-header h2 { font-size: 13px; }

            .panel-body { padding: 18px; }

            .backup-item { padding: 12px 16px; gap: 11px; }
            .backup-icon { width: 36px; height: 36px; font-size: 14px; }
            .backup-name { font-size: 11.5px; }
            .backup-actions { gap: 4px; }
            .icon-btn { width: 32px; height: 32px; font-size: 12px; }

            .form-control { height: 46px; font-size: 14px; }

            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal { border-radius: 16px 16px 0 0; max-width: 100%; max-height: 92vh; }
            .modal-header { border-radius: 16px 16px 0 0; }
            .modal-footer { flex-direction: column-reverse; padding: 14px 18px; }
            .modal-footer .btn { width: 100%; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title h1 i { font-size: 16px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  {
                padding: 11px;
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            .stat-icon { width: 32px; height: 32px; font-size: 13px; }
            .stat-card .value { font-size: 16px; }

            .backup-item { padding: 11px 14px; }

            .radio-row { flex-direction: column; }
            .radio-pill { width: 100%; }

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
$topbar_title    = 'Backup & Restore';
$topbar_subtitle = 'Super Admin';
include '../includes/topbar.php';
?>

<?php include 'superadmin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-database"></i> Backup &amp; Restore</h1>
            <p>Export your database as a SQL file, restore from a previous backup, or upload a new one.</p>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-box-archive"></i>
            </div>
            <div class="stat-body">
                <div class="label">Backups</div>
                <div class="value"><?php echo number_format($stats['total_backups']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fa-solid fa-hard-drive"></i>
            </div>
            <div class="stat-body">
                <div class="label">Total Size</div>
                <div class="value"><?php echo humanSize($stats['total_size']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold">
                <i class="fa-solid fa-table-list"></i>
            </div>
            <div class="stat-body">
                <div class="label">Database Tables</div>
                <div class="value"><?php echo number_format($stats['tables']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div class="stat-body">
                <div class="label">Last Backup</div>
                <div class="value" style="font-size:14px;">
                    <?php
                    echo $stats['last_backup']
                        ? e(date('M j, Y', strtotime($stats['last_backup'])))
                        : '—';
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 2-COLUMN LAYOUT -->
    <div class="grid-2col">

        <!-- CREATE BACKUP -->
        <section class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-cloud-arrow-down"></i> Create New Backup</h2>
            </div>

            <div class="panel-body">

                <div class="radio-row">
                    <label class="radio-pill on" data-mode="all">
                        <input type="radio" name="backup_mode" value="all" checked>
                        <span class="dot"></span>
                        Full database
                    </label>
                    <label class="radio-pill" data-mode="selected">
                        <input type="radio" name="backup_mode" value="selected">
                        <span class="dot"></span>
                        Select tables
                    </label>
                </div>

                <div id="tablesSection" style="display:none; margin-bottom:16px;">
                    <div class="picker-toolbar">
                        <button type="button" class="picker-btn" onclick="selectAllTables(true)">Select all</button>
                        <button type="button" class="picker-btn" onclick="selectAllTables(false)">Clear</button>
                    </div>

                    <div class="tables-picker">
                        <?php foreach ($all_tables as $t): ?>
                            <label class="table-check">
                                <input type="checkbox" class="table-cb" value="<?php echo e($t); ?>" checked>
                                <span class="table-check-name"><?php echo e($t); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Notes (optional)</label>
                    <input type="text" id="backupNotes" class="form-control"
                           maxlength="255" placeholder="e.g. Before end-of-term grading">
                </div>

                <label class="checkbox-line">
                    <input type="checkbox" id="downloadNow">
                    Download immediately after creating
                </label>

                <button type="button" class="btn btn-primary btn-block" id="createBackupBtn" style="margin-top:20px;">
                    <span class="spinner"></span>
                    <i class="fa-solid fa-circle-plus"></i> Create Backup
                </button>
            </div>
        </section>

        <!-- UPLOAD + RESTORE -->
        <section class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-cloud-arrow-up"></i> Restore from File</h2>
            </div>

            <div class="panel-body">

                <div class="warn-box">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        Restoring will <strong>overwrite</strong> existing tables. Always create a fresh
                        backup before restoring from an upload. Only <strong>.sql</strong> files up to 50 MB are supported.
                    </div>
                </div>

                <div class="form-group">
                    <label>SQL File</label>
                    <input type="file" id="sqlFile" class="form-control" accept=".sql" style="padding: 10px 12px; height: auto;">
                </div>

                <div class="form-group">
                    <label>Type <code>RESTORE</code> to confirm</label>
                    <input type="text" id="uploadConfirm" class="form-control" placeholder="RESTORE" autocomplete="off">
                </div>

                <button type="button" class="btn btn-danger btn-block" id="uploadRestoreBtn" style="margin-top:20px;">
                    <span class="spinner"></span>
                    <i class="fa-solid fa-upload"></i> Upload &amp; Restore
                </button>
            </div>
        </section>

    </div>

    <!-- BACKUP HISTORY -->
    <section class="panel">
        <div class="panel-header">
            <h2><i class="fa-solid fa-list"></i> Backup History</h2>
            <span class="meta">
                <?php echo number_format($stats['total_backups']); ?>
                backup<?php echo $stats['total_backups'] === 1 ? '' : 's'; ?>
            </span>
        </div>

        <?php if (empty($backups)): ?>

            <div class="empty">
                <div class="empty-icon"><i class="fa-solid fa-box-open"></i></div>
                <h3>No backups yet</h3>
                <p>Create your first backup using the panel above.</p>
            </div>

        <?php else: ?>

            <?php foreach ($backups as $b):
                $creator = 'System';
                if (!empty($b['first_name'])) {
                    $creator = trim($b['first_name'] . ' ' . $b['last_name']);
                }
            ?>
                <div class="backup-item">
                    <div class="backup-icon">
                        <i class="fa-solid fa-file-code"></i>
                    </div>

                    <div class="backup-info">
                        <div class="backup-name"><?php echo e($b['filename']); ?></div>
                        <div class="backup-meta">
                            <span><i class="fa-solid fa-weight-hanging"></i> <?php echo humanSize((int)$b['size_bytes']); ?></span>
                            <span><i class="fa-solid fa-table-list"></i> <?php echo (int)$b['table_count']; ?> tables</span>
                            <span><i class="fa-solid fa-rows"></i> <?php echo number_format((int)$b['row_count']); ?> rows</span>
                            <span><i class="fa-solid fa-user"></i> <?php echo e($creator); ?></span>
                            <span><i class="fa-solid fa-calendar"></i> <?php echo e(date('M j, Y · g:i A', strtotime($b['created_at']))); ?></span>
                            <?php if (!empty($b['notes'])): ?>
                                <span><i class="fa-solid fa-note-sticky"></i> <?php echo e($b['notes']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="backup-actions">
                        <a class="icon-btn success"
                           href="backup.php?download=<?php echo urlencode($b['filename']); ?>"
                           title="Download">
                            <i class="fa-solid fa-download"></i>
                        </a>

                        <button type="button" class="icon-btn restore-btn"
                                data-backup-id="<?php echo (int)$b['backup_id']; ?>"
                                data-backup-name="<?php echo e($b['filename']); ?>"
                                title="Restore">
                            <i class="fa-solid fa-rotate-left"></i>
                        </button>

                        <button type="button" class="icon-btn danger delete-btn"
                                data-backup-id="<?php echo (int)$b['backup_id']; ?>"
                                data-backup-name="<?php echo e($b['filename']); ?>"
                                title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>


<!-- RESTORE CONFIRM MODAL -->
<div class="modal-backdrop" id="restoreModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> Restore Database?</h3>
            <button type="button" class="modal-close" onclick="closeModal('restoreModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">
            <div class="warn-box" style="margin-bottom:16px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    This will <strong>overwrite</strong> your current database with the contents of
                    <strong id="restoreFileName">backup.sql</strong>. This cannot be undone.
                </div>
            </div>

            <div class="form-group">
                <label>Type <code>RESTORE</code> to confirm</label>
                <input type="text" id="restoreConfirm" class="form-control" placeholder="RESTORE" autocomplete="off">
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('restoreModal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmRestoreBtn">
                <span class="spinner"></span>
                <i class="fa-solid fa-rotate-left"></i> Restore
            </button>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>


<script>
/* =========================================================================
   TOASTS
   ========================================================================= */
function showToast(message, type = 'success', timeout = 3200) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : '');
    el.innerHTML = '<i class="fa-solid ' +
        (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') +
        '"></i><span>' + message + '</span>';
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s, transform .25s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}


/* =========================================================================
   MODE PILLS
   ========================================================================= */
document.querySelectorAll('.radio-pill').forEach(pill => {
    pill.addEventListener('click', () => {
        document.querySelectorAll('.radio-pill').forEach(p => p.classList.remove('on'));
        pill.classList.add('on');
        pill.querySelector('input').checked = true;

        const mode = pill.dataset.mode;
        document.getElementById('tablesSection').style.display =
            (mode === 'selected') ? 'block' : 'none';
    });
});

function selectAllTables(state) {
    document.querySelectorAll('.table-cb').forEach(cb => cb.checked = state);
}


/* =========================================================================
   CREATE BACKUP
   ========================================================================= */
document.getElementById('createBackupBtn').addEventListener('click', async function () {
    const btn = this;
    const original = btn.innerHTML;

    const mode = document.querySelector('.radio-pill.on').dataset.mode;
    const notes = document.getElementById('backupNotes').value.trim();
    const downloadNow = document.getElementById('downloadNow').checked;

    const selected = [];
    if (mode === 'selected') {
        document.querySelectorAll('.table-cb:checked').forEach(cb => selected.push(cb.value));
        if (selected.length === 0) {
            showToast('Please select at least one table.', 'error');
            return;
        }
    }

    btn.disabled = true;
    btn.classList.add('loading');
    btn.innerHTML = '<span class="spinner"></span> Creating backup...';

    const fd = new FormData();
    fd.set('ajax_action', 'create_backup');
    fd.set('mode', mode);
    fd.set('notes', notes);
    if (downloadNow) fd.set('download_now', '1');
    selected.forEach(t => fd.append('tables[]', t));

    try {
        const res = await fetch('backup.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Could not create backup.', 'error');
            return;
        }

        showToast(json.message, 'success');

        if (json.download_url) {
            /* Trigger download */
            window.location.href = json.download_url;
        }

        setTimeout(() => window.location.reload(), 900);

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
   UPLOAD RESTORE
   ========================================================================= */
document.getElementById('uploadRestoreBtn').addEventListener('click', async function () {
    const fileInput = document.getElementById('sqlFile');
    const confirmInput = document.getElementById('uploadConfirm');

    if (!fileInput.files.length) {
        showToast('Please select a .sql file.', 'error');
        return;
    }

    if (confirmInput.value.trim() !== 'RESTORE') {
        showToast('Type RESTORE to confirm.', 'error');
        return;
    }

    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');
    btn.innerHTML = '<span class="spinner"></span> Restoring...';

    const fd = new FormData();
    fd.set('ajax_action', 'upload_restore');
    fd.set('confirm_text', 'RESTORE');
    fd.set('sql_file', fileInput.files[0]);

    try {
        const res = await fetch('backup.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Restore failed.', 'error');
            return;
        }

        showToast(json.message, 'success');
        setTimeout(() => window.location.reload(), 1000);

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
   MODAL HELPERS
   ========================================================================= */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.classList.add('no-scroll');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) closeModal(bd.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(bd => closeModal(bd.id));
    }
});


/* =========================================================================
   RESTORE FROM EXISTING BACKUP
   ========================================================================= */
let restoreBackupId = 0;

document.addEventListener('click', e => {
    const btn = e.target.closest('.restore-btn');
    if (!btn) return;

    restoreBackupId = parseInt(btn.dataset.backupId, 10);
    document.getElementById('restoreFileName').textContent = btn.dataset.backupName || 'backup.sql';
    document.getElementById('restoreConfirm').value = '';
    openModal('restoreModal');
});

document.getElementById('confirmRestoreBtn').addEventListener('click', async function () {
    if (!restoreBackupId) return;

    const confirm = document.getElementById('restoreConfirm').value.trim();
    if (confirm !== 'RESTORE') {
        showToast('Type RESTORE to confirm.', 'error');
        return;
    }

    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('loading');
    btn.innerHTML = '<span class="spinner"></span> Restoring...';

    const fd = new FormData();
    fd.set('ajax_action', 'restore_backup');
    fd.set('backup_id', restoreBackupId);
    fd.set('confirm_text', 'RESTORE');

    try {
        const res = await fetch('backup.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Restore failed.', 'error');
            return;
        }

        showToast(json.message, 'success');
        closeModal('restoreModal');
        setTimeout(() => window.location.reload(), 1000);

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
   DELETE BACKUP
   ========================================================================= */
document.addEventListener('click', async e => {
    const btn = e.target.closest('.delete-btn');
    if (!btn) return;

    const id = parseInt(btn.dataset.backupId, 10);
    const name = btn.dataset.backupName || 'this backup';

    if (!confirm('Delete "' + name + '"? This cannot be undone.')) {
        return;
    }

    const fd = new FormData();
    fd.set('ajax_action', 'delete_backup');
    fd.set('backup_id', id);

    try {
        const res = await fetch('backup.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Could not delete.', 'error');
            return;
        }

        showToast(json.message, 'success');
        setTimeout(() => window.location.reload(), 700);

    } catch (err) {
        console.error(err);
        showToast('Network error.', 'error');
    }
});


/* =========================================================================
   MOBILE SIDEBAR — handled by includes/topbar.php
   ========================================================================= */
</script>

</body>
</html>