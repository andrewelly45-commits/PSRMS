<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';


/* =========================================================================
   HELPERS
   ========================================================================= */

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['ay_flash'] = ['type' => $type, 'message' => $message];
    header('Location: academic_years.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $t): bool
{
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}

const DEFAULT_TERMS = ['Term 1', 'Term 2', 'Term 3'];

/**
 * Ensure the 3 default terms exist for a given academic year.
 */
function ensure_terms_for_year(mysqli $conn, int $academic_year_id): int
{
    if ($academic_year_id <= 0) return 0;

    $existing = [];
    $stmt = mysqli_prepare($conn, "SELECT term_name FROM terms WHERE academic_year_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $academic_year_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $existing[$row['term_name']] = true;
    }
    mysqli_stmt_close($stmt);

    $inserted = 0;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO terms (academic_year_id, term_name, status) VALUES (?, ?, 'closed')"
    );
    foreach (DEFAULT_TERMS as $t) {
        if (isset($existing[$t])) continue;
        mysqli_stmt_bind_param($stmt, 'is', $academic_year_id, $t);
        if (mysqli_stmt_execute($stmt)) $inserted++;
    }
    mysqli_stmt_close($stmt);

    return $inserted;
}


/* =========================================================================
   AUTO-CREATE academic_years + terms TABLES (defensive)
   ========================================================================= */

if (!tableExists($conn, 'academic_years')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS academic_years (
            academic_year_id INT(11) NOT NULL AUTO_INCREMENT,
            year             INT(4) NOT NULL,
            status           ENUM('active','closed') DEFAULT 'active',
            created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (academic_year_id),
            UNIQUE KEY uniq_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if (!tableExists($conn, 'terms')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS terms (
            term_id          INT(11) NOT NULL AUTO_INCREMENT,
            academic_year_id INT(11) NOT NULL,
            term_name        ENUM('Term 1','Term 2','Term 3') NOT NULL,
            start_date       DATE DEFAULT NULL,
            end_date         DATE DEFAULT NULL,
            status           ENUM('active','closed') DEFAULT 'active',
            PRIMARY KEY (term_id),
            UNIQUE KEY uniq_year_term (academic_year_id, term_name),
            KEY idx_year (academic_year_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE ACADEMIC YEAR (+ auto-insert its 3 terms)
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $year   = $_POST['year'] ?? '';
        $status = $_POST['status'] ?? 'active';

        $errors = [];

        if ($year === '' || !ctype_digit((string)$year)) {
            $errors[] = 'Year must be a valid 4-digit number.';
        } else {
            $year = (int) $year;
            if ($year < 2000 || $year > 2100) {
                $errors[] = 'Year must be between 2000 and 2100.';
            }
        }

        if (!in_array($status, ['active', 'closed'], true)) $status = 'active';

        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT academic_year_id FROM academic_years WHERE year = ? LIMIT 1"
            );
            mysqli_stmt_bind_param($stmt, 'i', $year);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $errors[] = 'This academic year already exists.';
            }
            mysqli_stmt_close($stmt);
        }

        if (!empty($errors)) {
            $_SESSION['ay_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: academic_years.php');
            exit;
        }

        mysqli_begin_transaction($conn);

        try {
            if ($status === 'active') {
                mysqli_query($conn, "UPDATE academic_years SET status = 'closed' WHERE status = 'active'");
            }

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO academic_years (year, status) VALUES (?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'is', $year, $status);
            mysqli_stmt_execute($stmt);
            $new_id = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $inserted_terms = ensure_terms_for_year($conn, $new_id);

            mysqli_commit($conn);

            redirect_with_flash(
                'success',
                "Academic year {$year} created with {$inserted_terms} default terms."
            );
        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not create year: ' . $ex->getMessage());
        }
    }


    /* -------------------------------------------------------------
       UPDATE ACADEMIC YEAR
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $id     = (int) ($_POST['academic_year_id'] ?? 0);
        $year   = $_POST['year'] ?? '';
        $status = $_POST['status'] ?? 'active';

        $errors = [];

        if ($id <= 0) $errors[] = 'Invalid year.';

        if ($year === '' || !ctype_digit((string)$year)) {
            $errors[] = 'Year must be a valid 4-digit number.';
        } else {
            $year = (int) $year;
            if ($year < 2000 || $year > 2100) {
                $errors[] = 'Year must be between 2000 and 2100.';
            }
        }

        if (!in_array($status, ['active', 'closed'], true)) $status = 'active';

        if (empty($errors)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT academic_year_id FROM academic_years
                 WHERE year = ? AND academic_year_id <> ? LIMIT 1"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $year, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $errors[] = 'Another academic year already uses this number.';
            }
            mysqli_stmt_close($stmt);
        }

        if (!empty($errors)) {
            $_SESSION['ay_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: academic_years.php');
            exit;
        }

        mysqli_begin_transaction($conn);

        try {
            if ($status === 'active') {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE academic_years SET status = 'closed'
                     WHERE status = 'active' AND academic_year_id <> ?"
                );
                mysqli_stmt_bind_param($stmt, 'i', $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE academic_years SET year = ?, status = ? WHERE academic_year_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'isi', $year, $status, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            ensure_terms_for_year($conn, $id);

            mysqli_commit($conn);
            redirect_with_flash('success', "Academic year {$year} updated.");
        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not update year: ' . $ex->getMessage());
        }
    }


    /* -------------------------------------------------------------
       ACTIVATE ACADEMIC YEAR
    ------------------------------------------------------------- */
    if ($action === 'activate') {

        $id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($id <= 0) redirect_with_flash('error', 'Invalid year.');

        mysqli_query($conn, "UPDATE academic_years SET status = 'closed' WHERE status = 'active'");

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE academic_years SET status = 'active' WHERE academic_year_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected > 0) {
            ensure_terms_for_year($conn, $id);
            redirect_with_flash('success', 'Academic year set as active.');
        }
        redirect_with_flash('error', 'Could not activate year.');
    }


    /* -------------------------------------------------------------
       CLOSE ACADEMIC YEAR (also closes its terms)
    ------------------------------------------------------------- */
    if ($action === 'close') {

        $id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($id <= 0) redirect_with_flash('error', 'Invalid year.');

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "UPDATE academic_years SET status = 'closed' WHERE academic_year_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE terms SET status = 'closed' WHERE academic_year_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            redirect_with_flash('success', 'Academic year and its terms closed.');
        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not close year.');
        }
    }


    /* -------------------------------------------------------------
       DELETE ACADEMIC YEAR (+ its terms)
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($id <= 0) redirect_with_flash('error', 'Invalid year.');

        $stmt = mysqli_prepare($conn, "SELECT status FROM academic_years WHERE academic_year_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) redirect_with_flash('error', 'Year not found.');
        if ($row['status'] === 'active') {
            redirect_with_flash('error', 'Cannot delete the active academic year. Close it first.');
        }

        if (tableExists($conn, 'teacher_assignments')) {
            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM teacher_assignments WHERE academic_year_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ((int)($r['c'] ?? 0) > 0) {
                redirect_with_flash('error', 'Cannot delete — teacher assignments are linked to this year.');
            }
        }

        if (tableExists($conn, 'results')) {
            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM results WHERE academic_year_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ((int)($r['c'] ?? 0) > 0) {
                redirect_with_flash('error', 'Cannot delete — results are linked to this year.');
            }
        }

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "DELETE FROM terms WHERE academic_year_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM academic_years WHERE academic_year_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            redirect_with_flash('success', 'Academic year and its terms deleted.');
        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not delete year.');
        }
    }


    /* -------------------------------------------------------------
       SET CURRENT TERM
       -------------------------------------------------------------
       - One dropdown chooses the term the school is currently in.
       - Dates are entered for that term.
       - Saving makes it ACTIVE and closes all other terms for that year.
    ------------------------------------------------------------- */
    if ($action === 'set_current_term') {

        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);
        $term_name        = trim($_POST['term_name']        ?? '');
        $start_date       = trim($_POST['start_date']       ?? '');
        $end_date         = trim($_POST['end_date']         ?? '');

        if ($academic_year_id <= 0) {
            redirect_with_flash('error', 'Invalid academic year.');
        }

        if (!in_array($term_name, DEFAULT_TERMS, true)) {
            redirect_with_flash('error', 'Please choose a valid term.');
        }

        /* Normalize dates */
        $start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : null;
        $end_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)   ? $end_date   : null;

        if ($start_date && $end_date && $end_date < $start_date) {
            redirect_with_flash('error', 'End date must be after the start date.');
        }

        /* Make sure all 3 terms exist */
        ensure_terms_for_year($conn, $academic_year_id);

        mysqli_begin_transaction($conn);

        try {

            /* Close every term of this year */
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE terms SET status = 'closed' WHERE academic_year_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'i', $academic_year_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            /* Set the chosen term active + its dates */
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE terms
                 SET status = 'active', start_date = ?, end_date = ?
                 WHERE academic_year_id = ? AND term_name = ?"
            );
            mysqli_stmt_bind_param(
                $stmt, 'ssis',
                $start_date, $end_date, $academic_year_id, $term_name
            );
            mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            if ($affected === 0) {
                throw new Exception('The chosen term could not be updated.');
            }

            mysqli_commit($conn);

            redirect_with_flash(
                'success',
                "Current term set to {$term_name}."
            );

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Could not set current term: ' . $ex->getMessage());
        }
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */
$flash = $_SESSION['ay_flash'] ?? null;
unset($_SESSION['ay_flash']);


/* =========================================================================
   SEARCH
   ========================================================================= */
$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search !== '' && ctype_digit($search)) {
    $where[]  = "year = ?";
    $params[] = (int) $search;
    $types   .= 'i';
}

if (in_array($status_filter, ['active', 'closed'], true)) {
    $where[]  = "status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

$sql = "SELECT academic_year_id, year, status, created_at FROM academic_years";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY year DESC";

$years = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $years[] = $r;
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   LOAD TERMS FOR ALL DISPLAYED YEARS + FIND "CURRENT" TERM PER YEAR
   ========================================================================= */

$terms_by_year     = [];   /* [year_id => [term rows]] */
$current_by_year   = [];   /* [year_id => term row] (the active one) */

if (!empty($years)) {

    $year_ids     = array_column($years, 'academic_year_id');
    $placeholders = implode(',', array_fill(0, count($year_ids), '?'));
    $types        = str_repeat('i', count($year_ids));

    $stmt = mysqli_prepare(
        $conn,
        "SELECT term_id, academic_year_id, term_name, start_date, end_date, status
         FROM terms
         WHERE academic_year_id IN ($placeholders)
         ORDER BY FIELD(term_name, 'Term 1','Term 2','Term 3')"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$year_ids);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) {
            $yid = (int)$r['academic_year_id'];
            $terms_by_year[$yid][] = $r;

            if ($r['status'] === 'active' && !isset($current_by_year[$yid])) {
                $current_by_year[$yid] = $r;
            }
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   STATS
   ========================================================================= */
$stats = ['total' => 0, 'active' => 0, 'closed' => 0, 'terms' => 0];

$res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_total,
        SUM(status = 'closed') AS closed_total
     FROM academic_years"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['total']  = (int) $row['total'];
    $stats['active'] = (int) $row['active_total'];
    $stats['closed'] = (int) $row['closed_total'];
}

$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM terms");
if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['terms'] = (int) $row['c'];
}

$has_filters = ($search !== '' || $status_filter !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Academic Years | PSRMS</title>

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
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 { color: var(--navy); font-size: 25px; font-weight: 700; }
        .page-title p  { color: var(--muted); font-size: 12.5px; margin-top: 5px; }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 42px;
            padding: 0 16px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12px;
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
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { border-color: var(--gold); }

        /* ALERTS */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

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
            padding: 18px 20px;
        }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 24px;
            font-weight: 750;
            margin-top: 6px;
        }

        /* FILTER */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-toggle {
            display: none;
            width: 100%;
            background: none;
            border: none;
            padding: 0 0 14px;
            margin-bottom: 4px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            color: var(--navy);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            text-align: left;
            justify-content: space-between;
            align-items: center;
        }

        .filter-toggle .chev {
            color: var(--gold);
            font-size: 12px;
            transition: transform .25s ease;
        }
        .filter-panel.collapsed .filter-toggle .chev { transform: rotate(-90deg); }

        .filter-form {
            display: grid;
            grid-template-columns: 1.6fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .filter-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* TABLE */
        .table-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        thead th {
            text-align: left;
            padding: 13px 16px;
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 12.5px;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfbf8; }

        .year-number {
            color: var(--navy);
            font-size: 15px;
            font-weight: 750;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }

        /* Current term cell */
        .current-term {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            background: var(--green-bg);
            border: 1px solid #cfe5d7;
            color: var(--green);
            font-size: 11.5px;
            font-weight: 750;
            white-space: nowrap;
        }
        .current-term .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 3px rgba(62,118,85,.18);
        }
        .current-term .dates {
            color: #2f6b47;
            font-weight: 600;
            font-size: 10.5px;
        }

        .no-current-term {
            color: var(--muted);
            font-style: italic;
            font-size: 11.5px;
        }

        /* Status */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .status::before {
            content: "";
            width: 5px; height: 5px; border-radius: 50%;
        }

        .status-active   { color: var(--green);  background: var(--green-bg); }
        .status-active::before { background: var(--green); }

        .status-closed   { color: var(--orange); background: var(--orange-bg); }
        .status-closed::before { background: var(--orange); }

        /* Actions */
        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 32px;
            padding: 0 11px;
            font-family: inherit;
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
        }

        .icon-btn:hover { border-color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        .icon-btn.primary {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }
        .icon-btn.primary:hover {
            background: var(--navy-dark);
            border-color: var(--navy-dark);
        }

        .icon-btn.term {
            background: var(--gold);
            color: var(--navy);
            border-color: var(--gold);
        }
        .icon-btn.term:hover {
            background: var(--gold-light);
            border-color: var(--gold-light);
        }

        .icon-btn.danger { color: var(--red); border-color: #efd2d2; }
        .icon-btn.danger:hover { background: var(--red-bg); border-color: var(--red); }

        /* Empty */
        .empty {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty h3 { color: var(--navy); font-size: 14px; margin-bottom: 5px; }

        /* MOBILE CARDS */
        .card-list { display: none; }

        .year-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .year-card:last-child { border-bottom: none; }

        .year-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f0f1f3;
        }

        .year-card-meta {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }

        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 3px;
        }

        .meta-item .v {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
            word-wrap: break-word;
        }

        .year-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .year-card-actions .icon-btn,
        .year-card-actions form,
        .year-card-actions form button {
            width: 100%;
            min-height: 42px;
            font-size: 12px;
        }

        /* MODAL */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16, 24, 43, .55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .2s ease;
        }

        .modal-backdrop.open { display: flex; opacity: 1; }

        .modal {
            background: var(--white);
            border-radius: 14px;
            width: 100%;
            max-width: 500px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
            animation: pop .18s ease-out;
        }

        @keyframes pop {
            from { transform: scale(.96); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1;
        }

        .modal-header h2 { color: var(--navy); font-size: 15px; font-weight: 700; }

        .modal-close {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            border: none;
            border-radius: 8px;
            color: var(--muted);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }

        .modal-close:hover { background: #e5e7eb; color: var(--navy); }

        .modal-body { padding: 22px; }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .required { color: var(--red); }

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
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
            -webkit-appearance: none;
            appearance: none;
        }

        .date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .hint-box {
            padding: 12px 14px;
            background: #eef6f0;
            border: 1px solid #cfe5d7;
            border-radius: 10px;
            color: #3e7655;
            font-size: 12.5px;
            font-weight: 600;
            line-height: 1.55;
            margin-bottom: 4px;
        }

        .hint-box.warn {
            background: var(--orange-bg);
            border-color: #ecd9a8;
            color: var(--orange);
        }

        /* Responsive */
        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {
            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }
            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .page-header .btn {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 13.5px; }

            .table-wrapper { display: none; }
            .card-list { display: block; }

            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal {
                max-width: 100%;
                max-height: 92vh;
                border-radius: 16px 16px 0 0;
            }
            .modal-header { padding: 16px 18px; }
            .modal-body   { padding: 18px; }
            .modal-footer { padding: 14px 18px; flex-direction: column-reverse; }
            .modal-footer .btn { width: 100%; }

            .date-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 19px; }
            .year-card-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .modal-footer { padding-bottom: max(14px, env(safe-area-inset-bottom)); }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Academic Years';
$topbar_subtitle = 'Manage school years & the current term';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Academic Years</h1>
            <p>Each year has 3 terms. Use <strong>Terms</strong> to set which term the school is currently in.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            + Add Academic Year
        </button>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Years</div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo number_format($stats['active']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Closed</div>
            <div class="value"><?php echo number_format($stats['closed']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Terms</div>
            <div class="value"><?php echo number_format($stats['terms']); ?></div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="academic_years.php" class="filter-form">
            <div class="filter-group">
                <label>Search by Year</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="e.g. 2025"
                       value="<?php echo e($search); ?>">
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="academic_years.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- TABLE / CARDS -->
    <div class="table-card">

        <?php if (empty($years)): ?>

            <div class="empty">
                <h3>No academic years found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        Try clearing the filters.
                    <?php else: ?>
                        Click <strong>Add Academic Year</strong> to create the first one.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Status</th>
                            <th>Current Term</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($years as $y):
                        $yid     = (int)$y['academic_year_id'];
                        $current = $current_by_year[$yid] ?? null;

                        $current_dates = '';
                        if ($current && (!empty($current['start_date']) || !empty($current['end_date']))) {
                            $s = $current['start_date'] ? date('M j', strtotime($current['start_date'])) : '—';
                            $e = $current['end_date']   ? date('M j, Y', strtotime($current['end_date'])) : '—';
                            $current_dates = $s . ' → ' . $e;
                        }
                    ?>
                        <tr>
                            <td><span class="year-number"><?php echo (int)$y['year']; ?></span></td>

                            <td>
                                <span class="status status-<?php echo e($y['status']); ?>">
                                    <?php echo e($y['status']); ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($current): ?>
                                    <span class="current-term">
                                        <span class="dot"></span>
                                        <?php echo e($current['term_name']); ?>
                                        <?php if ($current_dates): ?>
                                            <span class="dates">· <?php echo e($current_dates); ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="no-current-term">No term set</span>
                                <?php endif; ?>
                            </td>

                            <td><?php echo e(date('M j, Y', strtotime($y['created_at']))); ?></td>

                            <td>
                                <div class="actions">

                                    <button type="button" class="icon-btn term"
                                        onclick='openTermsModal(<?php echo json_encode([
                                            "year_id"        => $yid,
                                            "year"           => (int)$y["year"],
                                            "current_term"   => $current["term_name"] ?? "",
                                            "start_date"     => $current["start_date"] ?? "",
                                            "end_date"       => $current["end_date"]   ?? "",
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        Terms
                                    </button>

                                    <?php if ($y['status'] !== 'active'): ?>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Set <?php echo (int)$y['year']; ?> as the active year? The current active year will be closed.');">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="academic_year_id" value="<?php echo $yid; ?>">
                                            <button type="submit" class="icon-btn primary">Activate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Close this academic year and its terms?');">
                                            <input type="hidden" name="action" value="close">
                                            <input type="hidden" name="academic_year_id" value="<?php echo $yid; ?>">
                                            <button type="submit" class="icon-btn">Close</button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" class="icon-btn"
                                        onclick='openEditModal(<?php echo json_encode($y, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        Edit
                                    </button>

                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete this academic year permanently? Its terms will also be removed.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="academic_year_id" value="<?php echo $yid; ?>">
                                        <button type="submit" class="icon-btn danger">Delete</button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($years as $y):
                    $yid     = (int)$y['academic_year_id'];
                    $current = $current_by_year[$yid] ?? null;

                    $current_dates = '';
                    if ($current && (!empty($current['start_date']) || !empty($current['end_date']))) {
                        $s = $current['start_date'] ? date('M j', strtotime($current['start_date'])) : '—';
                        $e = $current['end_date']   ? date('M j, Y', strtotime($current['end_date'])) : '—';
                        $current_dates = $s . ' → ' . $e;
                    }
                ?>
                    <div class="year-card">

                        <div class="year-card-top">
                            <span class="year-number"><?php echo (int)$y['year']; ?></span>
                            <span class="status status-<?php echo e($y['status']); ?>">
                                <?php echo e($y['status']); ?>
                            </span>
                        </div>

                        <div class="year-card-meta">
                            <div class="meta-item">
                                <span class="k">Current Term</span>
                                <?php if ($current): ?>
                                    <span class="current-term">
                                        <span class="dot"></span>
                                        <?php echo e($current['term_name']); ?>
                                        <?php if ($current_dates): ?>
                                            <span class="dates">· <?php echo e($current_dates); ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="no-current-term">No term set</span>
                                <?php endif; ?>
                            </div>

                            <div class="meta-item">
                                <span class="k">Created</span>
                                <span class="v"><?php echo e(date('M j, Y', strtotime($y['created_at']))); ?></span>
                            </div>
                        </div>

                        <div class="year-card-actions">

                            <button type="button" class="icon-btn term"
                                    onclick='openTermsModal(<?php echo json_encode([
                                        "year_id"      => $yid,
                                        "year"         => (int)$y["year"],
                                        "current_term" => $current["term_name"] ?? "",
                                        "start_date"   => $current["start_date"] ?? "",
                                        "end_date"     => $current["end_date"]   ?? "",
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Terms
                            </button>

                            <?php if ($y['status'] !== 'active'): ?>
                                <form method="POST" onsubmit="return confirm('Activate this year?');">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="academic_year_id" value="<?php echo $yid; ?>">
                                    <button type="submit" class="icon-btn primary">Activate</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" onsubmit="return confirm('Close this year?');">
                                    <input type="hidden" name="action" value="close">
                                    <input type="hidden" name="academic_year_id" value="<?php echo $yid; ?>">
                                    <button type="submit" class="icon-btn">Close</button>
                                </form>
                            <?php endif; ?>

                            <button type="button" class="icon-btn"
                                onclick='openEditModal(<?php echo json_encode($y, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Edit
                            </button>

                            <form method="POST" onsubmit="return confirm('Delete this year and its terms?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="academic_year_id" value="<?php echo $yid; ?>">
                                <button type="submit" class="icon-btn danger">Delete</button>
                            </form>

                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</main>


<!-- =========================================================
     CREATE MODAL
========================================================= -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST" action="academic_years.php">
            <div class="modal-header">
                <h2>Add Academic Year</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">✕</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label>Year <span class="required">*</span></label>
                    <input type="number" name="year" class="form-control"
                           min="2000" max="2100" step="1"
                           placeholder="e.g. 2025" required>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>

                <div class="hint-box">
                    Three default terms (<strong>Term 1</strong>, <strong>Term 2</strong>,
                    <strong>Term 3</strong>) will be created automatically. Use the
                    <em>Terms</em> button afterwards to choose which term the school is
                    currently in.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Year</button>
            </div>

            <input type="hidden" name="action" value="create">
        </form>
    </div>
</div>


<!-- =========================================================
     EDIT MODAL
========================================================= -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <form method="POST" action="academic_years.php">
            <div class="modal-header">
                <h2>Edit Academic Year</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label>Year <span class="required">*</span></label>
                    <input type="number" name="year" id="edit_year" class="form-control"
                           min="2000" max="2100" step="1" required>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="active">Active</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>

            <input type="hidden" name="action" value="update">
            <input type="hidden" name="academic_year_id" id="edit_id">
        </form>
    </div>
</div>


<!-- =========================================================
     TERMS MODAL (current term selector + dates)
========================================================= -->
<div class="modal-backdrop" id="termsModal">
    <div class="modal">
        <form method="POST" action="academic_years.php" id="termsForm">

            <div class="modal-header">
                <h2 id="termsModalTitle">Set Current Term</h2>
                <button type="button" class="modal-close" onclick="closeModal('termsModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="hint-box" id="termsHint">
                    Choose the term the school is currently in, add its start and end dates,
                    then save. The chosen term becomes <strong>active</strong> and all others
                    for this year are closed.
                </div>

                <div class="form-group">
                    <label>Current Term <span class="required">*</span></label>
                    <select name="term_name" id="term_name" class="form-control" required>
                        <option value="Term 1">Term 1</option>
                        <option value="Term 2">Term 2</option>
                        <option value="Term 3">Term 3</option>
                    </select>
                </div>

                <div class="date-row">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" id="term_start_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" id="term_end_date" class="form-control">
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('termsModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save &amp; Activate</button>
            </div>

            <input type="hidden" name="action" value="set_current_term">
            <input type="hidden" name="academic_year_id" id="terms_year_id">
        </form>
    </div>
</div>


<script>
/* =========================================================
   MODAL HELPERS
========================================================= */
function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openEditModal(data) {
    document.getElementById('edit_id').value     = data.academic_year_id || '';
    document.getElementById('edit_year').value   = data.year             || '';
    document.getElementById('edit_status').value = data.status           || 'active';

    document.getElementById('editModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openTermsModal(data) {
    document.getElementById('terms_year_id').value = data.year_id;

    document.getElementById('termsModalTitle').textContent =
        'Set Current Term — ' + data.year;

    /* Prefill dropdown with current term if present */
    const termName = data.current_term || 'Term 1';
    document.getElementById('term_name').value = termName;

    document.getElementById('term_start_date').value = data.start_date || '';
    document.getElementById('term_end_date').value   = data.end_date   || '';

    /* Update the hint message based on whether a term is already set */
    const hint = document.getElementById('termsHint');
    if (data.current_term) {
        hint.className = 'hint-box warn';
        hint.innerHTML = 'This year is currently in <strong>' +
            escapeHtml(data.current_term) + '</strong>. Choose another term to switch. ' +
            'The chosen term becomes <strong>active</strong> and the others are closed.';
    } else {
        hint.className = 'hint-box';
        hint.innerHTML = 'No term is set yet for this year. Choose the term the school ' +
            'is currently in, add its start and end dates, then save.';
    }

    document.getElementById('termsModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function escapeHtml(v) {
    return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
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
        document.querySelectorAll('.modal-backdrop.open')
            .forEach(bd => closeModal(bd.id));
    }
});


/* =========================================================
   FILTER PANEL COLLAPSE (mobile only)
========================================================= */
(function () {
    const filterPanel  = document.getElementById('filterPanel');
    const filterToggle = document.getElementById('filterToggle');
    if (!filterPanel || !filterToggle) return;

    const mq              = window.matchMedia('(max-width: 800px)');
    const hasActiveFilter = <?php echo $has_filters ? 'true' : 'false'; ?>;

    function syncFilterState() {
        if (mq.matches) {
            filterPanel.classList.toggle('collapsed', !hasActiveFilter);
        } else {
            filterPanel.classList.remove('collapsed');
        }
    }
    syncFilterState();
    mq.addEventListener
        ? mq.addEventListener('change', syncFilterState)
        : mq.addListener(syncFilterState);

    filterToggle.addEventListener('click', () => {
        if (!mq.matches) return;
        filterPanel.classList.toggle('collapsed');
    });
})();
</script>

</body>
</html>