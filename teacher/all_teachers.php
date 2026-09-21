<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Verify current user is Headteacher
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "SELECT t.teacher_id, t.assignment_type
     FROM teachers t
     WHERE t.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$me || $me['assignment_type'] !== 'headteacher') {
    http_response_code(403);
    die('Access denied. Headteacher only.');
}

$head_teacher_id = (int) $me['teacher_id'];


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
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
| Filters
|--------------------------------------------------------------------------
*/
$search = trim($_GET['q'] ?? '');
$role   = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';

$where  = ["u.role = 'teacher'"];
$params = [];
$types  = '';

/* Search by name / email / employee no / specialization */
if ($search !== '') {
    $where[]  = "(
        u.first_name LIKE ?
        OR u.middle_name LIKE ?
        OR u.last_name LIKE ?
        OR u.email LIKE ?
        OR u.phone LIKE ?
        OR t.employee_no LIKE ?
        OR t.qualification LIKE ?
        OR t.specialization LIKE ?
    )";
    $like     = '%' . $search . '%';
    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
        $types   .= 's';
    }
}

/* Role filter */
if (in_array($role, ['teacher', 'academic', 'headteacher'], true)) {
    $where[]  = "t.assignment_type = ?";
    $params[] = $role;
    $types   .= 's';
}

/* Status filter — active / inactive / suspended */
if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
    $where[]  = "u.status = ?";
    $params[] = $status;
    $types   .= 's';
}

$sql = "
    SELECT
        t.teacher_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.employment_status,
        t.assignment_type,
        t.role_assigned_at,
        t.created_at,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.phone,
        u.gender,
        u.status AS user_status,
        u.profile_pic
    FROM teachers t
    INNER JOIN users u ON u.user_id = t.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        FIELD(t.assignment_type, 'headteacher', 'academic', 'teacher'),
        u.first_name ASC, u.last_name ASC
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
| Stats (whole school, ignores filters)
|--------------------------------------------------------------------------
*/
$stats = [
    'total'         => 0,
    'active'        => 0,
    'inactive'      => 0,
    'teachers'      => 0,
    'academics'     => 0,
    'headteachers'  => 0,
];

$res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(u.status = 'active')                             AS active_total,
        SUM(u.status = 'inactive')                           AS inactive_total,
        SUM(t.assignment_type = 'teacher')                   AS teacher_total,
        SUM(t.assignment_type = 'academic')                  AS academic_total,
        SUM(t.assignment_type = 'headteacher')               AS headteacher_total
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE u.role = 'teacher'"
);

if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['total']        = (int) $row['total'];
    $stats['active']       = (int) $row['active_total'];
    $stats['inactive']     = (int) $row['inactive_total'];
    $stats['teachers']     = (int) $row['teacher_total'];
    $stats['academics']    = (int) $row['academic_total'];
    $stats['headteachers'] = (int) $row['headteacher_total'];
}

$has_filters = ($search !== '' || $role !== '' || $status !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>All Teachers | PSRMS</title>

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
           STATS
        ========================================================= */
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

        .stat-card .hint {
            color: var(--muted);
            font-size: 10.5px;
            margin-top: 4px;
        }

        /* =========================================================
           FILTER PANEL
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
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group { min-width: 0; }

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
            min-width: 950px;
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
           TEACHER CELL
        ========================================================= */
        .teacher-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .teacher-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
            border: 1.5px solid var(--border);
        }

        .teacher-initials {
            width: 40px;
            height: 40px;
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
           PILLS
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
           STATUS
        ========================================================= */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status::before {
            content: "";
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        .status-active     { color: var(--green);  background: var(--green-bg); }
        .status-active::before { background: var(--green); }

        .status-inactive   { color: var(--orange); background: var(--orange-bg); }
        .status-inactive::before { background: var(--orange); }

        .status-suspended  { color: var(--red);    background: var(--red-bg); }
        .status-suspended::before { background: var(--red); }

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

        .teacher-card {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .teacher-card:last-child { border-bottom: none; }

        .teacher-card-top {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid #f0f1f3;
        }

        .teacher-card-top .teacher-avatar,
        .teacher-card-top .teacher-initials {
            width: 46px;
            height: 46px;
            font-size: 15px;
        }

        .teacher-card-top-info {
            min-width: 0;
            flex: 1;
        }

        .teacher-card-top-info .teacher-name {
            font-size: 14px;
        }

        .teacher-card-pills {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
            flex-shrink: 0;
        }

        .teacher-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
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

        .meta-item.full { grid-column: 1 / -1; }

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 1100px) {
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
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

            .teacher-card-meta { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 550px) {

            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }

            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 19px; }
            .stat-card .hint  { font-size: 10px; }

            .teacher-card-meta { grid-template-columns: 1fr; gap: 8px; }

            .teacher-card { padding: 14px; }
        }

        @media (max-width: 400px) {

            .stats-grid { grid-template-columns: 1fr 1fr; }

            .stat-card .value { font-size: 18px; }
            .stat-card .hint  { display: none; }

            .teacher-card-top { flex-direction: column; }
            .teacher-card-pills {
                flex-direction: row;
                align-items: center;
                align-self: flex-start;
            }
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
$topbar_title    = 'All Teachers';
$topbar_subtitle = 'School teaching staff directory';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>All Teachers</h1>
            <p>Complete directory of teaching staff in the school.</p>
        </div>

        <a href="manage_roles.php" class="btn btn-primary">
            Manage Roles →
        </a>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Staff</div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
            <div class="hint">All teaching accounts</div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value"><?php echo number_format($stats['active']); ?></div>
            <div class="hint">Currently working</div>
        </div>
        <div class="stat-card">
            <div class="label">Academic Masters</div>
            <div class="value"><?php echo number_format($stats['academics']); ?></div>
            <div class="hint">Only one allowed</div>
        </div>
        <div class="stat-card">
            <div class="label">Teachers</div>
            <div class="value"><?php echo number_format($stats['teachers']); ?></div>
            <div class="hint">Regular teaching staff</div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="all_teachers.php" class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input
                    type="text"
                    name="q"
                    class="filter-control"
                    placeholder="Name, email, phone, employee no, subject…"
                    value="<?php echo e($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label>Role</label>
                <select name="role" class="filter-control">
                    <option value="">All roles</option>
                    <option value="headteacher" <?php echo $role === 'headteacher' ? 'selected' : ''; ?>>Headteacher</option>
                    <option value="academic"    <?php echo $role === 'academic'    ? 'selected' : ''; ?>>Academic Master</option>
                    <option value="teacher"     <?php echo $role === 'teacher'     ? 'selected' : ''; ?>>Teacher</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All statuses</option>
                    <option value="active"    <?php echo $status === 'active'    ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive"  <?php echo $status === 'inactive'  ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="all_teachers.php" class="btn btn-ghost">Clear</a>
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
                        There are no teachers registered yet.
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
                            <th>Gender</th>
                            <th>Qualification</th>
                            <th>Specialization</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teachers as $t):
                        $initial = strtoupper(mb_substr($t['full_name'], 0, 1));
                        $role_key = $t['assignment_type'];
                        $ustatus  = strtolower($t['user_status'] ?: 'inactive');
                    ?>
                        <tr>
                            <td>
                                <div class="teacher-cell">
                                    <?php if (!empty($t['profile_pic'])): ?>
                                        <img
                                            src="../uploads/users/<?php echo e($t['profile_pic']); ?>"
                                            alt=""
                                            class="teacher-avatar"
                                            loading="lazy"
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                        >
                                        <div class="teacher-initials" style="display:none;"><?php echo e($initial); ?></div>
                                    <?php else: ?>
                                        <div class="teacher-initials"><?php echo e($initial); ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="teacher-name"><?php echo e($t['full_name']); ?></div>
                                        <div class="teacher-meta"><?php echo e($t['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo e($t['employee_no'] ?: '—'); ?></td>
                            <td><?php echo e(ucfirst($t['gender'] ?: '—')); ?></td>
                            <td><?php echo e($t['qualification'] ?: '—'); ?></td>
                            <td><?php echo e($t['specialization'] ?: '—'); ?></td>
                            <td>
                                <span class="pill pill-<?php echo e($role_colors[$role_key] ?? 'teacher'); ?>">
                                    <?php echo e($role_labels[$role_key] ?? $role_key); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status status-<?php echo e($ustatus); ?>">
                                    <?php echo e(ucfirst($ustatus)); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list">
                <?php foreach ($teachers as $t):
                    $initial  = strtoupper(mb_substr($t['full_name'], 0, 1));
                    $role_key = $t['assignment_type'];
                    $ustatus  = strtolower($t['user_status'] ?: 'inactive');
                ?>
                    <div class="teacher-card">

                        <div class="teacher-card-top">
                            <?php if (!empty($t['profile_pic'])): ?>
                                <img
                                    src="../uploads/users/<?php echo e($t['profile_pic']); ?>"
                                    alt=""
                                    class="teacher-avatar"
                                    loading="lazy"
                                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                >
                                <div class="teacher-initials" style="display:none;"><?php echo e($initial); ?></div>
                            <?php else: ?>
                                <div class="teacher-initials"><?php echo e($initial); ?></div>
                            <?php endif; ?>

                            <div class="teacher-card-top-info">
                                <div class="teacher-name"><?php echo e($t['full_name']); ?></div>
                                <div class="teacher-meta"><?php echo e($t['email']); ?></div>
                            </div>

                            <div class="teacher-card-pills">
                                <span class="pill pill-<?php echo e($role_colors[$role_key] ?? 'teacher'); ?>">
                                    <?php echo e($role_labels[$role_key] ?? $role_key); ?>
                                </span>
                                <span class="status status-<?php echo e($ustatus); ?>">
                                    <?php echo e(ucfirst($ustatus)); ?>
                                </span>
                            </div>
                        </div>

                        <div class="teacher-card-meta">
                            <div class="meta-item">
                                <span class="k">Employee No.</span>
                                <span class="v"><?php echo e($t['employee_no'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Gender</span>
                                <span class="v"><?php echo e(ucfirst($t['gender'] ?: '—')); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Qualification</span>
                                <span class="v"><?php echo e($t['qualification'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Specialization</span>
                                <span class="v"><?php echo e($t['specialization'] ?: '—'); ?></span>
                            </div>
                            <?php if (!empty($t['phone'])): ?>
                                <div class="meta-item full">
                                    <span class="k">Phone</span>
                                    <span class="v"><?php echo e($t['phone']); ?></span>
                                </div>
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