<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);


/* =========================================================================
   HELPERS
   ========================================================================= */

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['events_flash'] = ['type' => $type, 'message' => $message];
    header('Location: events.php');
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


/* =========================================================================
   AUTO-CREATE events TABLE IF MISSING
   ========================================================================= */

if (!tableExists($conn, 'events')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS events (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            title       VARCHAR(255) NOT NULL,
            event_date  DATE NOT NULL,
            event_time  TIME DEFAULT NULL,
            location    VARCHAR(255) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_date (event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $title       = trim($_POST['title']       ?? '');
        $event_date  = trim($_POST['event_date']  ?? '');
        $event_time  = trim($_POST['event_time']  ?? '');
        $location    = trim($_POST['location']    ?? '');
        $description = trim($_POST['description'] ?? '');

        $errors = [];

        if ($title === '') {
            $errors[] = 'Event title is required.';
        } elseif (strlen($title) > 255) {
            $errors[] = 'Title cannot exceed 255 characters.';
        }

        if ($event_date === '') {
            $errors[] = 'Event date is required.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $event_date)) {
            $errors[] = 'Event date is not valid.';
        }

        if ($event_time !== '' && !DateTime::createFromFormat('H:i', $event_time) && !DateTime::createFromFormat('H:i:s', $event_time)) {
            $errors[] = 'Event time is not valid.';
        }

        if ($location !== '' && strlen($location) > 255) {
            $errors[] = 'Location cannot exceed 255 characters.';
        }

        if (!empty($errors)) {
            $_SESSION['events_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: events.php');
            exit;
        }

        $time_param = $event_time !== '' ? $event_time : null;
        $loc_param  = $location   !== '' ? $location   : null;
        $desc_param = $description !== '' ? $description : null;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO events (title, event_date, event_time, location, description)
             VALUES (?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'sssss',
            $title,
            $event_date,
            $time_param,
            $loc_param,
            $desc_param
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Event created successfully.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not create event: ' . $err);
    }


    /* -------------------------------------------------------------
       UPDATE
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $id          = (int) ($_POST['id'] ?? 0);
        $title       = trim($_POST['title']       ?? '');
        $event_date  = trim($_POST['event_date']  ?? '');
        $event_time  = trim($_POST['event_time']  ?? '');
        $location    = trim($_POST['location']    ?? '');
        $description = trim($_POST['description'] ?? '');

        $errors = [];

        if ($id <= 0) $errors[] = 'Invalid event.';

        if ($title === '') {
            $errors[] = 'Event title is required.';
        } elseif (strlen($title) > 255) {
            $errors[] = 'Title cannot exceed 255 characters.';
        }

        if ($event_date === '') {
            $errors[] = 'Event date is required.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $event_date)) {
            $errors[] = 'Event date is not valid.';
        }

        if ($event_time !== '' && !DateTime::createFromFormat('H:i', $event_time) && !DateTime::createFromFormat('H:i:s', $event_time)) {
            $errors[] = 'Event time is not valid.';
        }

        if ($location !== '' && strlen($location) > 255) {
            $errors[] = 'Location cannot exceed 255 characters.';
        }

        if (!empty($errors)) {
            $_SESSION['events_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: events.php');
            exit;
        }

        $time_param = $event_time !== '' ? $event_time : null;
        $loc_param  = $location   !== '' ? $location   : null;
        $desc_param = $description !== '' ? $description : null;

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE events
             SET title = ?, event_date = ?, event_time = ?, location = ?, description = ?
             WHERE id = ?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'sssssi',
            $title,
            $event_date,
            $time_param,
            $loc_param,
            $desc_param,
            $id
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Event updated.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not update event: ' . $err);
    }


    /* -------------------------------------------------------------
       DELETE
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) redirect_with_flash('error', 'Invalid event.');

        $stmt = mysqli_prepare($conn, "DELETE FROM events WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Event deleted.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not delete: ' . $err);
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */

$flash = $_SESSION['events_flash'] ?? null;
unset($_SESSION['events_flash']);


/* =========================================================================
   FILTERS
   ========================================================================= */

$search  = trim($_GET['q'] ?? '');
$filter  = $_GET['filter'] ?? '';   /* upcoming / past / all */

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(title LIKE ? OR location LIKE ? OR description LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

if ($filter === 'upcoming') {
    $where[] = "event_date >= CURDATE()";
} elseif ($filter === 'past') {
    $where[] = "event_date < CURDATE()";
}

$sql = "SELECT id, title, event_date, event_time, location, description, created_at
        FROM events";

if ($where) $sql .= " WHERE " . implode(' AND ', $where);

if ($filter === 'past') {
    $sql .= " ORDER BY event_date DESC, event_time DESC";
} else {
    $sql .= " ORDER BY event_date ASC, event_time ASC";
}

$events = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $events[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   STATS
   ========================================================================= */

$stats = ['total' => 0, 'upcoming' => 0, 'past' => 0, 'this_month' => 0];

$res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(event_date >= CURDATE()) AS upcoming_total,
        SUM(event_date < CURDATE())  AS past_total,
        SUM(MONTH(event_date) = MONTH(CURDATE()) AND YEAR(event_date) = YEAR(CURDATE())) AS this_month_total
     FROM events"
);

if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['total']      = (int) $row['total'];
    $stats['upcoming']   = (int) $row['upcoming_total'];
    $stats['past']       = (int) $row['past_total'];
    $stats['this_month'] = (int) $row['this_month_total'];
}

$has_filters = ($search !== '' || $filter !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Events | PSRMS</title>

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

        /* LAYOUT */
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
            font-size: 12.5px;
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
            -webkit-tap-highlight-color: transparent;
        }

        .filter-toggle .chev {
            color: var(--gold);
            font-size: 12px;
            transition: transform .25s ease;
            display: inline-block;
        }

        .filter-panel.collapsed .filter-toggle .chev {
            transform: rotate(-90deg);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
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
            -webkit-appearance: none;
            appearance: none;
        }

        select.filter-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* RESULTS BAR */
        .results-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .results-bar h2 { color: var(--navy); font-size: 15px; }
        .results-count { color: var(--muted); font-size: 11.5px; }

        /* =========================================================
           EVENTS LIST (desktop rows)
        ========================================================= */
        .events-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .event-row {
            display: grid;
            grid-template-columns: 80px 1fr auto;
            gap: 20px;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            transition: .15s ease;
        }

        .event-row:last-child { border-bottom: none; }
        .event-row:hover { background: #fdfcf8; }

        /* Date block */
        .event-date-block {
            width: 64px;
            height: 64px;
            border-radius: 10px;
            background: var(--navy);
            color: var(--white);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            line-height: 1;
        }

        .event-date-block .day {
            font-size: 22px;
            font-weight: 800;
        }

        .event-date-block .month {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gold-light);
            margin-top: 4px;
        }

        .event-date-block.past {
            background: #b9bfc9;
        }
        .event-date-block.past .month { color: #f0f1f3; }

        /* Body */
        .event-body { min-width: 0; }

        .event-title {
            color: var(--navy);
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 6px;
            overflow-wrap: anywhere;
        }

        .event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            font-size: 12px;
            color: var(--muted);
        }

        .event-meta span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .event-desc {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 8px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }

        .event-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-left: 8px;
        }

        .badge-upcoming { background: var(--green-bg); color: var(--green); }
        .badge-today    { background: var(--gold);     color: var(--navy); }
        .badge-past     { background: #f0efec;         color: #7a7a72; }

        /* Actions */
        .event-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 34px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        .icon-btn.danger { color: var(--red); border-color: #efd2d2; }
        .icon-btn.danger:hover { background: var(--red-bg); border-color: var(--red); }

        /* Empty */
        .empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
        }

        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        /* =========================================================
           MODAL
        ========================================================= */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16,24,43,.55);
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
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
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

        /* FORM */
        .form-group { margin-bottom: 16px; }

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
            -webkit-appearance: none;
            appearance: none;
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

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-grid .full { grid-column: 1 / -1; }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
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

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 14px; }

            /* Events stack as cards */
            .event-row {
                grid-template-columns: 1fr;
                gap: 14px;
                padding: 16px;
            }

            .event-date-block {
                width: 100%;
                height: auto;
                padding: 8px 0;
                flex-direction: row;
                gap: 8px;
            }

            .event-date-block .day { font-size: 18px; }
            .event-date-block .month { font-size: 10px; margin-top: 0; }

            .event-actions {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .event-actions .icon-btn { width: 100%; min-height: 42px; font-size: 12px; }

            /* Modal — bottom sheet */
            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal {
                max-width: 100%;
                border-radius: 16px 16px 0 0;
            }
            .modal-header { padding: 16px 18px; }
            .modal-body { padding: 18px; }
            .modal-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
            }
            .modal-footer .btn { width: 100%; }

            .form-grid { grid-template-columns: 1fr; gap: 12px; }
            .form-grid .full { grid-column: auto; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 19px; }

            .event-title { font-size: 14px; }
            .event-meta  { font-size: 11.5px; }
            .event-desc  { font-size: 12px; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card .value { font-size: 18px; }
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
$topbar_title    = 'Events';
$topbar_subtitle = 'Manage school events';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Events</h1>
            <p>Manage upcoming and past school events.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            + Add Event
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
            <div class="label">Total Events</div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Upcoming</div>
            <div class="value"><?php echo number_format($stats['upcoming']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Past</div>
            <div class="value"><?php echo number_format($stats['past']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">This Month</div>
            <div class="value"><?php echo number_format($stats['this_month']); ?></div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="events.php" class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="Title, location or description…"
                       value="<?php echo e($search); ?>">
            </div>

            <div class="filter-group">
                <label>Show</label>
                <select name="filter" class="filter-control">
                    <option value="">All events</option>
                    <option value="upcoming" <?php echo $filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming only</option>
                    <option value="past"     <?php echo $filter === 'past'     ? 'selected' : ''; ?>>Past only</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="events.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>

        </form>
    </section>

    <!-- RESULTS -->
    <div class="results-bar">
        <h2>Event List</h2>
        <span class="results-count">
            <?php echo number_format(count($events)); ?> event(s)
        </span>
    </div>

    <!-- EVENTS LIST -->
    <div class="events-card">

        <?php if (empty($events)): ?>

            <div class="empty">
                <div class="empty-icon">📅</div>
                <h3>No events found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        Try clearing the filters.
                    <?php else: ?>
                        Click <strong>Add Event</strong> to create your first one.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <?php foreach ($events as $ev):
                $ts       = strtotime($ev['event_date']);
                $day      = $ts ? date('d', $ts)  : '--';
                $month    = $ts ? date('M', $ts)  : '--';

                $today    = date('Y-m-d');
                $is_today = ($ev['event_date'] === $today);
                $is_past  = ($ev['event_date'] <  $today);

                $badge = $is_today
                    ? '<span class="event-badge badge-today">Today</span>'
                    : ($is_past
                        ? '<span class="event-badge badge-past">Past</span>'
                        : '<span class="event-badge badge-upcoming">Upcoming</span>');

                $time_fmt = !empty($ev['event_time']) ? date('g:i A', strtotime($ev['event_time'])) : '';
                $date_fmt = $ts ? date('l, F j, Y', $ts) : $ev['event_date'];
            ?>
                <div class="event-row">

                    <div class="event-date-block <?php echo $is_past ? 'past' : ''; ?>">
                        <span class="day"><?php echo $day; ?></span>
                        <span class="month"><?php echo strtoupper($month); ?></span>
                    </div>

                    <div class="event-body">

                        <div class="event-title">
                            <?php echo e($ev['title']); ?>
                            <?php echo $badge; ?>
                        </div>

                        <div class="event-meta">
                            <span>📅 <?php echo e($date_fmt); ?></span>
                            <?php if ($time_fmt): ?>
                                <span>⏰ <?php echo e($time_fmt); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ev['location'])): ?>
                                <span>📍 <?php echo e($ev['location']); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($ev['description'])): ?>
                            <div class="event-desc">
                                <?php
                                $desc = $ev['description'];
                                echo e(mb_strlen($desc) > 160 ? mb_substr($desc, 0, 160) . '…' : $desc);
                                ?>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="event-actions">
                        <button type="button" class="icon-btn"
                            onclick='openEditModal(<?php echo json_encode($ev, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            Edit
                        </button>

                        <form method="POST" style="display:contents;"
                              onsubmit="return confirm('Delete this event permanently?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>">
                            <button type="submit" class="icon-btn danger">Delete</button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</main>


<!-- =========================================================
     CREATE MODAL
========================================================= -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST" action="events.php" autocomplete="off">

            <div class="modal-header">
                <h2>Add Event</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="form-group">
                    <label>Event Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-control"
                           maxlength="255" placeholder="e.g. Prize Giving Day" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Event Date <span class="req">*</span></label>
                        <input type="date" name="event_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Event Time</label>
                        <input type="time" name="event_time" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control"
                           maxlength="255" placeholder="e.g. Main Hall">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control"
                              placeholder="Optional details about the event…"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Create Event</button>
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
        <form method="POST" action="events.php" autocomplete="off">

            <div class="modal-header">
                <h2>Edit Event</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="form-group">
                    <label>Event Title <span class="req">*</span></label>
                    <input type="text" name="title" id="edit_title"
                           class="form-control" maxlength="255" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Event Date <span class="req">*</span></label>
                        <input type="date" name="event_date" id="edit_event_date"
                               class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Event Time</label>
                        <input type="time" name="event_time" id="edit_event_time"
                               class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" id="edit_location"
                           class="form-control" maxlength="255">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description"
                              class="form-control"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>

            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
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
    document.getElementById('edit_id').value          = data.id          || '';
    document.getElementById('edit_title').value       = data.title       || '';
    document.getElementById('edit_event_date').value  = data.event_date  || '';
    document.getElementById('edit_location').value    = data.location    || '';
    document.getElementById('edit_description').value = data.description || '';

    /* Normalise time to HH:MM for <input type="time"> */
    let t = data.event_time || '';
    if (t.length > 5) t = t.substring(0, 5);   /* "14:30:00" → "14:30" */
    document.getElementById('edit_event_time').value = t;

    document.getElementById('editModal').classList.add('open');
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
        document.querySelectorAll('.modal-backdrop.open')
            .forEach(bd => closeModal(bd.id));
    }
});


/* =========================================================
   FILTER PANEL COLLAPSE
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