<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Load current user's teacher record
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "SELECT
        t.teacher_id,
        t.assignment_type,
        u.first_name,
        u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$me || $me['assignment_type'] !== 'headteacher') {
    http_response_code(403);
    die('Access denied. Only the Headteacher can manage roles.');
}

$head_teacher_id = (int) $me['teacher_id'];


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['roles_flash'] = ['type' => $type, 'message' => $message];
    header('Location: manage_roles.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$role_labels = [
    'teacher'     => 'Teacher',
    'academic'    => 'Academic Master',
    'headteacher' => 'Headteacher',
];

$role_colors = [
    'teacher'     => 'teacher',
    'academic'    => 'academic',
    'headteacher' => 'headteacher',
];


/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action    = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['teacher_id'] ?? 0);

    if ($target_id <= 0) {
        redirect_with_flash('error', 'Invalid teacher selected.');
    }

    if ($target_id === $head_teacher_id) {
        redirect_with_flash('error', 'You cannot change your own role.');
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT teacher_id, assignment_type
         FROM teachers
         WHERE teacher_id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $target_id);
    mysqli_stmt_execute($stmt);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$target) {
        redirect_with_flash('error', 'Teacher not found.');
    }

    if ($target['assignment_type'] === 'headteacher') {
        redirect_with_flash('error', 'Cannot change the Headteacher\'s role.');
    }

    /* ---------------- Assign Academic Master ---------------- */
    if ($action === 'assign_academic') {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT t.teacher_id, u.first_name, u.last_name
             FROM teachers t
             INNER JOIN users u ON u.user_id = t.user_id
             WHERE t.assignment_type = 'academic'
               AND t.teacher_id <> ?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $target_id);
        mysqli_stmt_execute($stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($existing) {
            $name = trim($existing['first_name'] . ' ' . $existing['last_name']);
            redirect_with_flash(
                'error',
                'An Academic Master already exists (' . $name . '). Demote them first.'
            );
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE teachers
             SET assignment_type = 'academic',
                 role_assigned_by = ?,
                 role_assigned_at = NOW()
             WHERE teacher_id = ?
               AND assignment_type <> 'headteacher'"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $target_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Teacher promoted to Academic Master.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not assign role: ' . $err);
    }

    /* ---------------- Demote to Teacher ---------------- */
    if ($action === 'unassign') {

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE teachers
             SET assignment_type = 'teacher',
                 role_assigned_by = ?,
                 role_assigned_at = NOW()
             WHERE teacher_id = ?
               AND assignment_type <> 'headteacher'"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $target_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'Teacher demoted to regular teacher.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not demote: ' . $err);
    }

    redirect_with_flash('error', 'Unknown action.');
}


/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/
$flash = $_SESSION['roles_flash'] ?? null;
unset($_SESSION['roles_flash']);


/*
|--------------------------------------------------------------------------
| Search / Filter
|--------------------------------------------------------------------------
*/
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';

$where  = ["t.assignment_type <> 'headteacher'", "u.status = 'active'"];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(u.first_name LIKE ? OR u.middle_name LIKE ? OR u.last_name LIKE ? OR t.employee_no LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ssss';
}

if (in_array($filter, ['teacher', 'academic'], true)) {
    $where[]  = "t.assignment_type = ?";
    $params[] = $filter;
    $types   .= 's';
}

$sql = "
    SELECT
        t.teacher_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.assignment_type,
        t.role_assigned_at,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.gender
    FROM teachers t
    INNER JOIN users u ON u.user_id = t.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        FIELD(t.assignment_type, 'academic', 'teacher'),
        u.first_name ASC
";

$teachers = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['full_name'] = trim(
            $row['first_name'] . ' ' .
            ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );
        $teachers[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| Counts
|--------------------------------------------------------------------------
*/
$counts = [
    'teacher'  => 0,
    'academic' => 0,
];

$res = mysqli_query(
    $conn,
    "SELECT t.assignment_type, COUNT(*) AS c
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE u.status = 'active'
     GROUP BY t.assignment_type"
);

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        if (isset($counts[$r['assignment_type']])) {
            $counts[$r['assignment_type']] = (int)$r['c'];
        }
    }
}

$has_filters = ($search !== '' || $filter !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Manage Roles | PSRMS</title>

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

        /* =========================================================
           MAIN LAYOUT
        ========================================================= */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* =========================================================
           PAGE HEADER
        ========================================================= */
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

        /* =========================================================
           ALERTS
        ========================================================= */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* =========================================================
           STATS
        ========================================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        .stat-card .hint {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 4px;
        }

        /* =========================================================
           FILTER
        ========================================================= */
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
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        /* =========================================================
           BUTTONS
        ========================================================= */
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

        /* =========================================================
           TABLE
        ========================================================= */
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

        /* =========================================================
           ROLE PILL
        ========================================================= */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 750;
            padding: 5px 11px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }

        .pill::before {
            content: "";
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        .pill-teacher {
            background: var(--blue-bg);
            color: var(--blue);
        }
        .pill-teacher::before { background: var(--blue); }

        .pill-academic {
            background: var(--green-bg);
            color: var(--green);
        }
        .pill-academic::before { background: var(--green); }

        .pill-headteacher {
            background: var(--navy);
            color: var(--gold-light);
        }
        .pill-headteacher::before { background: var(--gold-light); }

        /* =========================================================
           TEACHER CELL
        ========================================================= */
        .teacher-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .teacher-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .teacher-name {
            color: var(--navy);
            font-weight: 700;
            font-size: 13px;
        }

        .teacher-meta {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 2px;
        }

        /* =========================================================
           ACTION BUTTONS
        ========================================================= */
        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .role-btn {
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
            -webkit-tap-highlight-color: transparent;
        }

        .role-btn:hover { border-color: var(--gold); }
        .role-btn:active { transform: scale(.96); }

        .role-btn.promote-academic {
            background: var(--green-bg);
            color: var(--green);
            border-color: #cfe5d7;
        }
        .role-btn.promote-academic:hover {
            background: #e0efe5;
            border-color: var(--green);
        }

        .role-btn.demote {
            background: var(--red-bg);
            color: var(--red);
            border-color: #efd2d2;
        }
        .role-btn.demote:hover {
            background: #f6dcdc;
            border-color: var(--red);
        }

        /* =========================================================
           EMPTY
        ========================================================= */
        .empty {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 14px;
            margin-bottom: 5px;
        }

        /* =========================================================
           MOBILE CARDS
        ========================================================= */
        .card-list { display: none; }

        .role-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .role-card:last-child { border-bottom: none; }

        .role-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .role-card-top .teacher-cell {
            align-items: flex-start;
            min-width: 0;
            flex: 1;
        }

        .role-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
            margin-bottom: 12px;
        }

        .meta-item { min-width: 0; }

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
            overflow-wrap: anywhere;
        }

        .role-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding-top: 12px;
            border-top: 1px solid #f0f1f3;
        }

        .role-card-actions .role-btn,
        .role-card-actions form,
        .role-card-actions form button {
            width: 100%;
            min-height: 42px;
            font-size: 12px;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 1100px) {
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; line-height: 1.45; }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }

            /* Collapsible filter */
            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 13.5px; }

            /* Table → cards */
            .table-wrapper { display: none; }
            .card-list { display: block; }

            .role-card-meta { grid-template-columns: 1fr; gap: 8px; }

            .role-card .teacher-avatar {
                width: 42px;
                height: 42px;
                font-size: 14px;
            }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 19px; }

            .role-card { padding: 14px; }

            .role-card-actions .role-btn,
            .role-card-actions form button { min-height: 44px; font-size: 12.5px; }
        }

        @media (max-width: 400px) {

            .stats-grid { grid-template-columns: 1fr; }

            .stat-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 14px;
            }

            .stat-card .label { order: 1; margin: 0; }
            .stat-card .value { order: 2; margin: 0; font-size: 20px; }
            .stat-card .hint  { display: none; }

            .role-card-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
            }
        }

        @media (max-height: 500px) and (max-width: 900px) {
            .main-content { padding-top: calc(var(--topbar-h) + 12px); }
        }
    </style>
</head>
<body>

<!-- Mobile drawer overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Manage Roles';
$topbar_subtitle = 'Assign Academic Master';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>Manage Teaching Roles</h1>
            <p>Assign or remove the Academic Master role.</p>
        </div>
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
            <div class="label">Teachers</div>
            <div class="value"><?php echo number_format($counts['teacher']); ?></div>
            <div class="hint">Normal teaching staff</div>
        </div>
        <div class="stat-card">
            <div class="label">Academic Master</div>
            <div class="value"><?php echo number_format($counts['academic']); ?></div>
            <div class="hint">Only one allowed</div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="manage_roles.php" class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input
                    type="text"
                    name="q"
                    class="filter-control"
                    placeholder="Name or employee number…"
                    value="<?php echo e($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label>Filter by Role</label>
                <select name="filter" class="filter-control">
                    <option value="">All roles</option>
                    <option value="teacher"  <?php echo $filter === 'teacher' ? 'selected' : ''; ?>>Teachers</option>
                    <option value="academic" <?php echo $filter === 'academic' ? 'selected' : ''; ?>>Academic Master</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="manage_roles.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>

        </form>
    </section>

    <!-- TABLE / CARDS -->
    <div class="table-card">

        <?php if (empty($teachers)): ?>

            <div class="empty">
                <h3>No teachers found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        Try clearing the filters.
                    <?php else: ?>
                        There are no teachers to manage.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Employee No.</th>
                            <th>Qualification</th>
                            <th>Specialization</th>
                            <th>Current Role</th>
                            <th>Assigned</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teachers as $t):
                        $initial = strtoupper(mb_substr($t['full_name'], 0, 1));
                        $role    = $t['assignment_type'];
                    ?>
                        <tr>
                            <td>
                                <div class="teacher-cell">
                                    <div class="teacher-avatar"><?php echo e($initial); ?></div>
                                    <div>
                                        <div class="teacher-name"><?php echo e($t['full_name']); ?></div>
                                        <div class="teacher-meta"><?php echo e($t['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo e($t['employee_no'] ?: '—'); ?></td>
                            <td><?php echo e($t['qualification'] ?: '—'); ?></td>
                            <td><?php echo e($t['specialization'] ?: '—'); ?></td>
                            <td>
                                <span class="pill pill-<?php echo e($role_colors[$role] ?? 'teacher'); ?>">
                                    <?php echo e($role_labels[$role] ?? $role); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $t['role_assigned_at']
                                    ? e(date('M j, Y', strtotime($t['role_assigned_at'])))
                                    : '—'; ?>
                            </td>
                            <td>
                                <div class="actions">

                                    <?php if ($role !== 'academic'): ?>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Assign this teacher as Academic Master?');">
                                            <input type="hidden" name="action" value="assign_academic">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$t['teacher_id']; ?>">
                                            <button type="submit" class="role-btn promote-academic">
                                                Make Academic Master
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($role !== 'teacher'): ?>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Demote this teacher back to a normal role?');">
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$t['teacher_id']; ?>">
                                            <button type="submit" class="role-btn demote">
                                                Demote
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($teachers as $t):
                    $initial = strtoupper(mb_substr($t['full_name'], 0, 1));
                    $role    = $t['assignment_type'];
                ?>
                    <div class="role-card">

                        <div class="role-card-top">
                            <div class="teacher-cell">
                                <div class="teacher-avatar"><?php echo e($initial); ?></div>
                                <div style="min-width:0;">
                                    <div class="teacher-name"><?php echo e($t['full_name']); ?></div>
                                    <div class="teacher-meta"><?php echo e($t['email']); ?></div>
                                </div>
                            </div>
                            <span class="pill pill-<?php echo e($role_colors[$role] ?? 'teacher'); ?>">
                                <?php echo e($role_labels[$role] ?? $role); ?>
                            </span>
                        </div>

                        <div class="role-card-meta">
                            <div class="meta-item">
                                <span class="k">Employee No.</span>
                                <span class="v"><?php echo e($t['employee_no'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Qualification</span>
                                <span class="v"><?php echo e($t['qualification'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Specialization</span>
                                <span class="v"><?php echo e($t['specialization'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Assigned</span>
                                <span class="v">
                                    <?php echo $t['role_assigned_at']
                                        ? e(date('M j, Y', strtotime($t['role_assigned_at'])))
                                        : '—'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="role-card-actions">
                            <?php if ($role !== 'academic'): ?>
                                <form method="POST"
                                      onsubmit="return confirm('Assign this teacher as Academic Master?');">
                                    <input type="hidden" name="action" value="assign_academic">
                                    <input type="hidden" name="teacher_id" value="<?php echo (int)$t['teacher_id']; ?>">
                                    <button type="submit" class="role-btn promote-academic">
                                        Make Academic Master
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($role !== 'teacher'): ?>
                                <form method="POST"
                                      onsubmit="return confirm('Demote this teacher back to a normal role?');">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="teacher_id" value="<?php echo (int)$t['teacher_id']; ?>">
                                    <button type="submit" class="role-btn demote">
                                        Demote to Teacher
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</main>


<script>
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