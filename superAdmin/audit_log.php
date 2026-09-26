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

function timeAgo(string $datetime): string {
    $ts   = strtotime($datetime);
    if (!$ts) return '—';
    $diff = time() - $ts;

    if ($diff < 60)          return 'just now';
    if ($diff < 3600)        return floor($diff / 60) . 'm ago';
    if ($diff < 86400)       return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)      return floor($diff / 86400) . 'd ago';
    if ($diff < 2592000)     return floor($diff / 604800) . 'w ago';
    return date('M j, Y', $ts);
}

function actionMeta(string $action): array {
    /* Returns [icon, color, label] */
    $map = [
        'login'            => ['fa-right-to-bracket',    'green',  'Login'],
        'login_failed'     => ['fa-triangle-exclamation','red',    'Failed Login'],
        'logout'           => ['fa-right-from-bracket',  'muted',  'Logout'],
        'user.create'      => ['fa-user-plus',           'green',  'User Created'],
        'user.update'      => ['fa-user-pen',            'blue',   'User Updated'],
        'user.delete'      => ['fa-user-minus',          'red',    'User Deleted'],
        'user.role_change' => ['fa-user-shield',         'purple', 'Role Changed'],
        'user.password'    => ['fa-key',                 'orange', 'Password Changed'],
        'user.photo'       => ['fa-image',               'blue',   'Photo Uploaded'],
        'profile.update'   => ['fa-user-gear',           'blue',   'Profile Updated'],
        'role.update'      => ['fa-shield-halved',       'purple', 'Permission Toggled'],
        'role.reset'       => ['fa-rotate-left',         'orange', 'Role Reset'],
        'settings.update'  => ['fa-sliders',             'blue',   'Settings Updated'],
        'backup.create'    => ['fa-database',            'gold',   'Backup Created'],
        'audit.clear'      => ['fa-broom',               'orange', 'Logs Cleared'],
        'result.enter'     => ['fa-file-pen',            'blue',   'Marks Entered'],
        'result.update'    => ['fa-file-pen',            'orange', 'Marks Updated'],
        'subject.create'   => ['fa-book',                'green',  'Subject Created'],
        'subject.update'   => ['fa-book',                'blue',   'Subject Updated'],
        'subject.delete'   => ['fa-book',                'red',    'Subject Deleted'],
    ];

    if (isset($map[$action])) return $map[$action];

    return ['fa-circle-info', 'muted', ucwords(str_replace(['.', '_'], ' ', $action))];
}


/* =========================================================================
   AUTO-CREATE audit_log TABLE
   ========================================================================= */

if (!tableExists($conn, 'audit_log')) {
    mysqli_query(
        $conn,
        "CREATE TABLE audit_log (
            log_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT NULL,
            role        VARCHAR(50) NULL,
            action      VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NULL,
            target_id   INT NULL,
            description VARCHAR(500) NULL,
            ip_address  VARCHAR(45) NULL,
            user_agent  VARCHAR(255) NULL,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id),
            KEY idx_user   (user_id),
            KEY idx_action (action),
            KEY idx_date   (created_at),
            KEY idx_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}


/* =========================================================================
   AUDIT LOG HELPER (for other pages to use)
   ========================================================================= */

if (!function_exists('logAudit')) {
    function logAudit(
        mysqli $conn,
        int $user_id,
        string $role,
        string $action,
        ?string $target_type = null,
        ?int $target_id = null,
        ?string $description = null
    ): void {
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
}


/* =========================================================================
   AJAX ROUTER
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {

    $action = $_POST['ajax_action'];

    /* -------------------------------------------------------------
       CLEAR OLD LOGS
    ------------------------------------------------------------- */
    if ($action === 'clear_logs') {

        $days = max(1, min(3650, (int)($_POST['days'] ?? 90)));

        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'Could not prepare.']);
        }

        mysqli_stmt_bind_param($stmt, 'i', $days);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        /* Log the action itself */
        logAudit(
            $conn,
            $user_id,
            $_SESSION['role'] ?? 'super_admin',
            'audit.clear',
            null, null,
            "Cleared {$deleted} log(s) older than {$days} days"
        );

        json_response([
            'success' => true,
            'message' => "Cleared {$deleted} log entry" . ($deleted === 1 ? '' : 's') . '.',
            'deleted' => $deleted,
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.']);
}


/* =========================================================================
   FILTERS
   ========================================================================= */

$filter_search = trim($_GET['q']      ?? '');
$filter_action = trim($_GET['action'] ?? '');
$filter_role   = trim($_GET['role']   ?? '');
$filter_days   = (int)($_GET['days']  ?? 30);
$filter_page   = max(1, (int)($_GET['page'] ?? 1));

$per_page = 25;
$offset   = ($filter_page - 1) * $per_page;

$valid_roles  = ['super_admin', 'admin', 'academic', 'teacher', 'parent'];
$valid_ranges = [1, 7, 30, 90, 365];

if (!in_array($filter_days, $valid_ranges, true)) {
    $filter_days = 30;
}


/* =========================================================================
   BUILD QUERY
   ---------------------------------------------------------------------------
   All column names are qualified with `l.` because the logs query
   LEFT JOINs the users table, which also has a `created_at` column.
   ========================================================================= */

$where  = ["l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
$params = [$filter_days];
$types  = 'i';

if ($filter_search !== '') {
    $where[] = "(l.description LIKE ? OR l.ip_address LIKE ? OR l.action LIKE ?)";
    $like    = '%' . $filter_search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

if ($filter_action !== '') {
    $where[] = "l.action = ?";
    $params[] = $filter_action;
    $types   .= 's';
}

if ($filter_role !== '' && in_array($filter_role, $valid_roles, true)) {
    $where[] = "l.role = ?";
    $params[] = $filter_role;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);


/* =========================================================================
   TOTAL COUNT
   ========================================================================= */

$total_count = 0;

$count_sql = "SELECT COUNT(*) AS c FROM audit_log l WHERE $where_sql";
$stmt = mysqli_prepare($conn, $count_sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($r)) {
        $total_count = (int)$row['c'];
    }
    mysqli_stmt_close($stmt);
}

$total_pages = max(1, (int)ceil($total_count / $per_page));
if ($filter_page > $total_pages) $filter_page = $total_pages;
$offset = ($filter_page - 1) * $per_page;


/* =========================================================================
   FETCH LOGS
   ========================================================================= */

$logs = [];

$logs_sql = "
    SELECT
        l.log_id, l.user_id, l.role, l.action,
        l.target_type, l.target_id, l.description,
        l.ip_address, l.user_agent, l.created_at,
        u.first_name, u.middle_name, u.last_name, u.profile_pic
    FROM audit_log l
    LEFT JOIN users u ON u.user_id = l.user_id
    WHERE $where_sql
    ORDER BY l.created_at DESC, l.log_id DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = mysqli_prepare($conn, $logs_sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $logs[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   DISTINCT ACTIONS (for filter dropdown)
   ========================================================================= */

$actions_list = [];
$r = mysqli_query(
    $conn,
    "SELECT DISTINCT action FROM audit_log ORDER BY action ASC LIMIT 100"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $actions_list[] = $row['action'];
    }
}


/* =========================================================================
   STATS (no join → no ambiguity)
   ========================================================================= */

$stats = ['total' => 0, 'today' => 0, 'week' => 0, 'failed' => 0];

$r = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(DATE(created_at) = CURDATE()) AS today,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS week,
        SUM(action = 'login_failed') AS failed
     FROM audit_log"
);
if ($r && $row = mysqli_fetch_assoc($r)) {
    $stats['total']  = (int)$row['total'];
    $stats['today']  = (int)$row['today'];
    $stats['week']   = (int)$row['week'];
    $stats['failed'] = (int)$row['failed'];
}


/* =========================================================================
   QUERY STRING BUILDER (for pagination)
   ========================================================================= */

function qs(array $overrides = []): string {
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return http_build_query($base);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Audit Log | Super Admin</title>

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
        .stat-icon.red   { background: var(--red-bg);     color: var(--red); }

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

        /* FILTER PANEL */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }

        .filter-control {
            width: 100%;
            height: 44px;
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
            background-color: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* BTNS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
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

        /* TABLE PANEL */
        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .table-header {
            padding: 16px 22px;
            background: #fcfcfa;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .table-header h2 {
            color: var(--navy);
            font-size: 14px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .table-header h2 i { color: var(--gold); font-size: 14px; }

        .table-header .meta {
            font-size: 11.5px;
            color: var(--muted);
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        table.data-table thead th {
            background: #fafaf8;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .7px;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        table.data-table tbody td {
            padding: 12px 14px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
        }

        table.data-table tbody tr:last-child td { border-bottom: none; }
        table.data-table tbody tr:hover { background: #fbfbf8; }

        /* USER CELL */
        .user-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-name {
            color: var(--navy);
            font-size: 12.5px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .user-role {
            color: var(--muted);
            font-size: 10.5px;
            text-transform: capitalize;
        }

        /* ACTION BADGE */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 750;
            white-space: nowrap;
        }

        .action-badge i { font-size: 10px; }

        .action-badge.green   { background: var(--green-bg);   color: var(--green); }
        .action-badge.blue    { background: var(--blue-bg);    color: var(--blue); }
        .action-badge.red     { background: var(--red-bg);     color: var(--red); }
        .action-badge.orange  { background: var(--orange-bg);  color: var(--orange); }
        .action-badge.purple  { background: var(--purple-bg);  color: var(--purple); }
        .action-badge.gold    { background: var(--gold-light); color: var(--navy); }
        .action-badge.muted   { background: #eef0f5;           color: var(--muted); }

        /* DESCRIPTION */
        .log-desc {
            color: var(--text);
            font-size: 12.5px;
            line-height: 1.5;
            overflow-wrap: anywhere;
            max-width: 380px;
        }

        .log-desc .target {
            display: inline-block;
            background: #f3f5f9;
            color: var(--navy);
            padding: 1px 7px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 700;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            margin-left: 4px;
        }

        /* IP + TIME */
        .ip-cell {
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            color: var(--muted);
            font-size: 11px;
        }

        .time-cell {
            color: var(--navy);
            font-weight: 650;
            font-size: 12px;
        }

        .time-cell small {
            display: block;
            color: var(--muted);
            font-size: 10px;
            font-weight: 500;
            margin-top: 2px;
        }

        /* MOBILE CARDS */
        .card-list { display: none; }

        .log-card {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f1f3;
        }

        .log-card:last-child { border-bottom: none; }

        .log-card-top {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            margin-bottom: 8px;
        }

        .log-card-body { flex: 1; min-width: 0; }

        .log-card-desc {
            color: var(--navy);
            font-size: 13px;
            font-weight: 650;
            line-height: 1.5;
            margin-top: 8px;
            overflow-wrap: anywhere;
        }

        .log-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #f0f1f3;
            font-size: 11px;
            color: var(--muted);
        }

        .log-card-meta span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .log-card-meta span i {
            color: var(--gold);
            font-size: 10px;
        }

        /* PAGINATION */
        .pagination {
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            background: #fcfcfa;
        }

        .pagination-info {
            font-size: 12px;
            color: var(--muted);
        }

        .pagination-controls {
            display: flex;
            gap: 5px;
            align-items: center;
            flex-wrap: wrap;
        }

        .page-link {
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: var(--white);
            color: var(--navy);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            transition: .15s ease;
        }

        .page-link:hover { border-color: var(--gold); color: var(--gold); }

        .page-link.current {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }

        .page-link.disabled {
            opacity: .4;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* EMPTY */
        .empty {
            padding: 60px 24px;
            text-align: center;
            color: var(--muted);
        }

        .empty-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 6px; }
        .empty p { font-size: 12.5px; line-height: 1.55; }

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

        /* RESPONSIVE — TABLET */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        /* RESPONSIVE — MOBILE */
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

            .filter-form { grid-template-columns: 1fr; gap: 12px; }
            .filter-control { height: 46px; font-size: 14px; }
            .filter-form .btn { width: 100%; min-height: 46px; }

            .table-header { padding: 14px 16px; }
            .table-header h2 { font-size: 13px; }

            .table-wrapper { display: none; }
            .card-list { display: block; }

            .pagination {
                padding: 14px 16px;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .pagination-controls { justify-content: center; }
            .pagination-info { text-align: center; }
        }

        /* RESPONSIVE — SMALL MOBILE */
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

            .log-card { padding: 12px 14px; }
            .log-card-desc { font-size: 12.5px; }

            .page-link {
                min-width: 34px;
                height: 34px;
                font-size: 11.5px;
                padding: 0 8px;
            }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast { min-width: auto; max-width: 100%; }
        }

        /* SAFE AREA */
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

        /* REDUCED MOTION */
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
$topbar_title    = 'Audit Log';
$topbar_subtitle = 'Super Admin';
include '../includes/topbar.php';
?>

<?php include 'superadmin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-clipboard-list"></i> Audit Log</h1>
            <p>Complete history of system activity — logins, changes, and administrative actions.</p>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-database"></i>
            </div>
            <div class="stat-body">
                <div class="label">Total Entries</div>
                <div class="value"><?php echo number_format($stats['total']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fa-solid fa-calendar-day"></i>
            </div>
            <div class="stat-body">
                <div class="label">Today</div>
                <div class="value"><?php echo number_format($stats['today']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold">
                <i class="fa-solid fa-calendar-week"></i>
            </div>
            <div class="stat-body">
                <div class="label">This Week</div>
                <div class="value"><?php echo number_format($stats['week']); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="stat-body">
                <div class="label">Failed Logins</div>
                <div class="value"><?php echo number_format($stats['failed']); ?></div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" action="audit_log.php" class="filter-panel">
        <div class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="Description, IP, or action..."
                       value="<?php echo e($filter_search); ?>">
            </div>

            <div class="filter-group">
                <label>Action</label>
                <select name="action" class="filter-control">
                    <option value="">All Actions</option>
                    <?php foreach ($actions_list as $a):
                        $meta = actionMeta($a);
                    ?>
                        <option value="<?php echo e($a); ?>" <?php echo $filter_action === $a ? 'selected' : ''; ?>>
                            <?php echo e($meta[2]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Role</label>
                <select name="role" class="filter-control">
                    <option value="">All Roles</option>
                    <?php foreach ($valid_roles as $r): ?>
                        <option value="<?php echo e($r); ?>" <?php echo $filter_role === $r ? 'selected' : ''; ?>>
                            <?php echo e(ucwords(str_replace('_', ' ', $r))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Time Range</label>
                <select name="days" class="filter-control">
                    <option value="1"   <?php echo $filter_days === 1   ? 'selected' : ''; ?>>Last 24 hours</option>
                    <option value="7"   <?php echo $filter_days === 7   ? 'selected' : ''; ?>>Last 7 days</option>
                    <option value="30"  <?php echo $filter_days === 30  ? 'selected' : ''; ?>>Last 30 days</option>
                    <option value="90"  <?php echo $filter_days === 90  ? 'selected' : ''; ?>>Last 90 days</option>
                    <option value="365" <?php echo $filter_days === 365 ? 'selected' : ''; ?>>Last year</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>

            <?php if ($filter_search || $filter_action || $filter_role || $filter_days !== 30): ?>
                <a href="audit_log.php" class="btn btn-ghost">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>

        </div>
    </form>

    <!-- LOG TABLE -->
    <section class="table-panel">

        <div class="table-header">
            <h2>
                <i class="fa-solid fa-list"></i>
                Activity Log
            </h2>
            <span class="meta">
                <?php echo number_format($total_count); ?>
                entr<?php echo $total_count === 1 ? 'y' : 'ies'; ?>
                · Page <?php echo $filter_page; ?> of <?php echo $total_pages; ?>
            </span>
        </div>

        <?php if (empty($logs)): ?>

            <div class="empty">
                <div class="empty-icon"><i class="fa-solid fa-inbox"></i></div>
                <h3>No log entries found</h3>
                <p>Try adjusting the filters or expanding the time range.</p>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:180px;">User</th>
                            <th style="width:170px;">Action</th>
                            <th>Description</th>
                            <th style="width:130px;">IP Address</th>
                            <th style="width:130px;">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $meta = actionMeta($log['action']);

                            $user_display = 'System';
                            if (!empty($log['first_name'])) {
                                $user_display = trim(
                                    $log['first_name'] . ' ' .
                                    ($log['middle_name'] ? $log['middle_name'] . ' ' : '') .
                                    $log['last_name']
                                );
                            } elseif (!empty($log['user_id'])) {
                                $user_display = 'User #' . (int)$log['user_id'];
                            }

                            $initials = '<i class="fa-solid fa-robot"></i>';
                            if (!empty($log['first_name'])) {
                                $initials = strtoupper(
                                    mb_substr($log['first_name'], 0, 1) .
                                    mb_substr($log['last_name'], 0, 1)
                                );
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar">
                                            <?php if (!empty($log['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $log['profile_pic'])): ?>
                                                <img src="../uploads/users/<?php echo e($log['profile_pic']); ?>" alt="">
                                            <?php else: ?>
                                                <?php echo $initials; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="user-name"><?php echo e($user_display); ?></div>
                                            <?php if (!empty($log['role'])): ?>
                                                <div class="user-role"><?php echo e(str_replace('_', ' ', $log['role'])); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="action-badge <?php echo e($meta[1]); ?>">
                                        <i class="fa-solid <?php echo e($meta[0]); ?>"></i>
                                        <?php echo e($meta[2]); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="log-desc">
                                        <?php echo e($log['description'] ?: '—'); ?>
                                        <?php if (!empty($log['target_type']) && !empty($log['target_id'])): ?>
                                            <span class="target">
                                                <?php echo e($log['target_type']); ?>#<?php echo (int)$log['target_id']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="ip-cell"><?php echo e($log['ip_address'] ?: '—'); ?></span>
                                </td>
                                <td>
                                    <div class="time-cell">
                                        <?php echo e(timeAgo($log['created_at'])); ?>
                                        <small><?php echo e(date('M j, Y · g:i A', strtotime($log['created_at']))); ?></small>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($logs as $log):
                    $meta = actionMeta($log['action']);

                    $user_display = 'System';
                    if (!empty($log['first_name'])) {
                        $user_display = trim(
                            $log['first_name'] . ' ' .
                            ($log['middle_name'] ? $log['middle_name'] . ' ' : '') .
                            $log['last_name']
                        );
                    } elseif (!empty($log['user_id'])) {
                        $user_display = 'User #' . (int)$log['user_id'];
                    }

                    $initials = '<i class="fa-solid fa-robot"></i>';
                    if (!empty($log['first_name'])) {
                        $initials = strtoupper(
                            mb_substr($log['first_name'], 0, 1) .
                            mb_substr($log['last_name'], 0, 1)
                        );
                    }
                ?>
                    <div class="log-card">

                        <div class="log-card-top">
                            <div class="user-avatar">
                                <?php if (!empty($log['profile_pic']) && is_file(__DIR__ . '/../uploads/users/' . $log['profile_pic'])): ?>
                                    <img src="../uploads/users/<?php echo e($log['profile_pic']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo $initials; ?>
                                <?php endif; ?>
                            </div>

                            <div class="log-card-body">
                                <div class="user-name"><?php echo e($user_display); ?></div>
                                <?php if (!empty($log['role'])): ?>
                                    <div class="user-role"><?php echo e(str_replace('_', ' ', $log['role'])); ?></div>
                                <?php endif; ?>
                            </div>

                            <span class="action-badge <?php echo e($meta[1]); ?>">
                                <i class="fa-solid <?php echo e($meta[0]); ?>"></i>
                                <?php echo e($meta[2]); ?>
                            </span>
                        </div>

                        <?php if (!empty($log['description'])): ?>
                            <div class="log-card-desc">
                                <?php echo e($log['description']); ?>
                                <?php if (!empty($log['target_type']) && !empty($log['target_id'])): ?>
                                    <span class="target">
                                        <?php echo e($log['target_type']); ?>#<?php echo (int)$log['target_id']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="log-card-meta">
                            <span>
                                <i class="fa-solid fa-clock"></i>
                                <?php echo e(date('M j, Y · g:i A', strtotime($log['created_at']))); ?>
                            </span>
                            <?php if (!empty($log['ip_address'])): ?>
                                <span>
                                    <i class="fa-solid fa-network-wired"></i>
                                    <?php echo e($log['ip_address']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

            <!-- PAGINATION -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing
                        <strong><?php echo number_format($offset + 1); ?></strong>–<strong><?php echo number_format(min($offset + $per_page, $total_count)); ?></strong>
                        of <strong><?php echo number_format($total_count); ?></strong>
                    </div>

                    <div class="pagination-controls">
                        <?php if ($filter_page > 1): ?>
                            <a href="?<?php echo e(qs(['page' => $filter_page - 1])); ?>" class="page-link">
                                <i class="fa-solid fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link disabled"><i class="fa-solid fa-chevron-left"></i></span>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $filter_page - 2);
                        $end   = min($total_pages, $filter_page + 2);

                        if ($start > 1) {
                            echo '<a href="?' . e(qs(['page' => 1])) . '" class="page-link">1</a>';
                            if ($start > 2) echo '<span class="page-link disabled">…</span>';
                        }

                        for ($p = $start; $p <= $end; $p++):
                        ?>
                            <?php if ($p === $filter_page): ?>
                                <span class="page-link current"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo e(qs(['page' => $p])); ?>" class="page-link"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php
                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) echo '<span class="page-link disabled">…</span>';
                            echo '<a href="?' . e(qs(['page' => $total_pages])) . '" class="page-link">' . $total_pages . '</a>';
                        }
                        ?>

                        <?php if ($filter_page < $total_pages): ?>
                            <a href="?<?php echo e(qs(['page' => $filter_page + 1])); ?>" class="page-link">
                                <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link disabled"><i class="fa-solid fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<div class="toast-wrap" id="toastWrap"></div>


<script>
/* =========================================================================
   TOASTS
   ========================================================================= */
function showToast(message, type = 'success', timeout = 3000) {
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
   MOBILE SIDEBAR — handled by includes/topbar.php
   ========================================================================= */
</script>

</body>
</html>