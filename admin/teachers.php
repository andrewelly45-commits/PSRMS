<?php

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function full_name(array $row): string
{
    $parts = array_filter([
        $row['first_name']  ?? '',
        $row['middle_name'] ?? '',
        $row['last_name']   ?? '',
    ], fn($v) => trim((string)$v) !== '');

    return trim(implode(' ', $parts));
}

/*
|--------------------------------------------------------------------------
| Search & Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

/*
|--------------------------------------------------------------------------
| Get Teachers
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        t.teacher_id,
        t.user_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.employment_status,
        t.assignment_type,
        t.created_at,

        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.gender,
        u.phone,
        u.profile_pic

    FROM teachers t

    INNER JOIN users u
        ON t.user_id = u.user_id

    WHERE u.role = 'teacher'
";

$params = [];
$types  = '';

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            u.first_name  LIKE ?
            OR u.middle_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR t.employee_no LIKE ?
            OR t.qualification LIKE ?
            OR t.specialization LIKE ?
        )
    ";

    $search_value = '%' . $search . '%';

    for ($i = 0; $i < 8; $i++) {
        $params[] = $search_value;
        $types   .= 's';
    }
}

/*
|--------------------------------------------------------------------------
| Employment Status
|--------------------------------------------------------------------------
*/

if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {

    $sql .= " AND t.employment_status = ? ";
    $params[] = $status;
    $types   .= 's';
}

/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

$sql .= " ORDER BY u.first_name ASC ";

/*
|--------------------------------------------------------------------------
| Execute Teacher Query
|--------------------------------------------------------------------------
*/

$teachers = [];

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {

    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $teachers[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_teachers    = 0;
$active_teachers   = 0;
$inactive_teachers = 0;

$result = mysqli_query($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(employment_status = 'active')   AS active_total,
        SUM(employment_status = 'inactive') AS inactive_total
    FROM teachers
");

if ($result) {
    $stats = mysqli_fetch_assoc($result);
    $total_teachers    = (int)($stats['total']          ?? 0);
    $active_teachers   = (int)($stats['active_total']   ?? 0);
    $inactive_teachers = (int)($stats['inactive_total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Assigned Class Count
|--------------------------------------------------------------------------
*/

$assigned_classes = 0;

$class_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'teacher_class'");

if ($class_table_check && mysqli_num_rows($class_table_check) > 0) {

    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM teacher_class");

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $assigned_classes = (int)($row['total'] ?? 0);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Teachers | PSRMS</title>

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
            --green: #3e7655;
            --red: #9b4747;
            --orange: #9a7422;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
            -webkit-text-size-adjust: 100%;
        }

        /* MAIN CONTENT */
        .main-content {
            margin-left: 255px;
            padding: 108px 30px 40px;
            transition: margin-left .25s ease;
        }

        /* PAGE HEADER */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-heading p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .add-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 17px;
            background: var(--navy);
            color: var(--white);
            border-radius: 7px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 650;
            transition: .2s ease;
            border: none;
            cursor: pointer;
        }

        .add-button:hover { background: var(--navy-dark); transform: translateY(-1px); }
        .add-button:active { transform: translateY(0); }

        .add-icon { color: var(--gold-light); font-size: 16px; }

        /* STATISTICS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 19px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .stat-value {
            color: var(--navy);
            font-size: 26px;
            font-weight: 750;
            margin-top: 7px;
        }

        .stat-line {
            width: 23px;
            height: 2px;
            background: var(--gold);
            margin-top: 12px;
        }

        /* FILTER */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.6fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .filter-control {
            width: 100%;
            height: 40px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fcfcfd;
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            outline: none;
        }

        .filter-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,.08);
        }

        .filter-button {
            height: 40px;
            padding: 0 18px;
            border: none;
            border-radius: 6px;
            background: var(--navy);
            color: var(--white);
            cursor: pointer;
            font-family: inherit;
            font-size: 11px;
            font-weight: 650;
        }

        .filter-button:hover { background: var(--navy-dark); }

        .reset-button {
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--white);
            color: var(--muted);
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
        }

        .reset-button:hover { color: var(--navy); border-color: #c8ccd3; }

        /* TABLE */
        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow: hidden;
        }

        .table-header {
            padding: 17px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-header h2 { color: var(--navy); font-size: 14px; }
        .table-count { color: var(--muted); font-size: 10px; }

        .table-wrapper { width: 100%; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        thead { background: #fafaf8; }

        th {
            padding: 12px 15px;
            text-align: left;
            color: #737c8c;
            font-size: 9px;
            font-weight: 750;
            letter-spacing: .7px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f1f3;
            color: var(--text);
            font-size: 11px;
            vertical-align: middle;
        }

        tbody tr:hover { background: #fdfcf8; }
        tbody tr:last-child td { border-bottom: none; }

        /* TEACHER CELL */
        .teacher-cell { display: flex; align-items: center; gap: 10px; }

        .teacher-photo {
            width: 37px; height: 37px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e5e1d2;
        }

        .teacher-placeholder {
            width: 37px; height: 37px;
            border-radius: 50%;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        .teacher-name { color: var(--navy); font-weight: 650; }
        .teacher-id { color: var(--muted); font-size: 9px; margin-top: 2px; }

        .assignment-badge {
            display: inline-block;
            margin-top: 3px;
            padding: 2px 6px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            border-radius: 10px;
            background: #eef1f7;
            color: var(--navy);
        }

        .email, .phone { color: var(--muted); }
        .employee-number { color: var(--navy); font-weight: 650; }
        .qualification { color: var(--text); }
        .specialization { color: var(--text); font-weight: 600; }

        /* STATUS */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 700;
        }

        .status::before {
            content: "";
            width: 5px; height: 5px;
            border-radius: 50%;
        }

        .status-active { color: var(--green); background: #eef6f0; }
        .status-active::before { background: var(--green); }

        .status-inactive { color: var(--orange); background: #faf5e8; }
        .status-inactive::before { background: var(--orange); }

        /* ACTIONS */
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .action-btn {
            min-width: 34px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            background: var(--white);
            color: var(--navy);
            text-decoration: none;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .action-btn:hover { border-color: var(--gold); color: var(--gold); }
        .action-btn:active { transform: scale(.96); background: #faf7ee; }

        .action-btn.primary { background: var(--navy); color: var(--white); border-color: var(--navy); }
        .action-btn.primary:hover { background: var(--navy-dark); color: var(--white); border-color: var(--navy-dark); }

        /* EMPTY */
        .empty-state { padding: 55px 20px; text-align: center; }

        .empty-icon {
            width: 50px; height: 50px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 750;
        }

        .empty-state h3 { color: var(--navy); font-size: 14px; margin-bottom: 5px; }
        .empty-state p { color: var(--muted); font-size: 11px; }

        /* =========================================================
           RESPONSIVE — MOBILE CARD VIEW
           ========================================================= */
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
            .filter-form .filter-button,
            .filter-form .reset-button { grid-column: auto; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: 92px 14px 90px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 12px;
            }

            .page-heading h1 { font-size: 21px; }
            .page-heading p { font-size: 11px; }

            .add-button {
                width: 100%;
                justify-content: center;
                padding: 13px 17px;
                font-size: 13px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card { padding: 14px; }
            .stat-value { font-size: 22px; }

            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-button,
            .reset-button {
                width: 100%;
                height: 44px;
                font-size: 13px;
            }

            /* Turn the table into stacked cards */
            .table-wrapper { overflow-x: visible; }

            table { min-width: 0; width: 100%; display: block; }
            thead { display: none; }
            tbody { display: block; }
            tbody tr {
                display: block;
                background: var(--white);
                border-bottom: 1px solid var(--border);
                padding: 14px 14px 6px;
                margin: 0;
            }
            tbody tr:last-child { border-bottom: none; }

            td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
                padding: 7px 0;
                border-bottom: 1px dashed #f0f1f3;
                font-size: 12px;
                text-align: right;
            }
            tbody tr td:last-child { border-bottom: none; }

            td::before {
                content: attr(data-label);
                font-size: 9px;
                font-weight: 750;
                letter-spacing: .6px;
                text-transform: uppercase;
                color: var(--muted);
                text-align: left;
                flex: 0 0 90px;
                padding-top: 3px;
            }

            td[data-label="Teacher"] {
                display: block;
                text-align: left;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--border);
            }
            td[data-label="Teacher"]::before { display: none; }

            .teacher-cell { justify-content: flex-start; }
            .teacher-photo, .teacher-placeholder { width: 44px; height: 44px; font-size: 15px; }

            .actions {
                justify-content: flex-end;
                width: 100%;
                gap: 6px;
                padding-top: 4px;
            }

            .action-btn {
                flex: 1;
                min-width: 0;
                height: 40px;
                font-size: 11px;
            }

            .table-header { padding: 14px 16px; }
            .table-header h2 { font-size: 13px; }
        }

        @media (max-width: 420px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-value { font-size: 20px; }
            td::before { flex: 0 0 72px; font-size: 8px; }
            td { font-size: 11px; }
        }

        /* COLLAPSED SIDEBAR */
        body.sidebar-collapsed .main-content { margin-left: 78px; }
        @media (max-width: 800px) {
            body.sidebar-collapsed .main-content { margin-left: 0; }
        }
    </style>
</head>

<body>

<?php include 'admin_header.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<main class="main-content">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-heading">
            <h1>Teachers</h1>
            <p>Manage teachers, teaching assignments and class responsibilities.</p>
        </div>

        <button
            type="button"
            class="add-button"
            data-action="add"
            data-url="add_teacher.php"
        >
            <span class="add-icon">+</span>
            Add Teacher
        </button>
    </div>

    <!-- STATISTICS -->
    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Teachers</div>
            <div class="stat-value"><?php echo number_format($total_teachers); ?></div>
            <div class="stat-line"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Active Teachers</div>
            <div class="stat-value"><?php echo number_format($active_teachers); ?></div>
            <div class="stat-line"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Inactive</div>
            <div class="stat-value"><?php echo number_format($inactive_teachers); ?></div>
            <div class="stat-line"></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Class Assignments</div>
            <div class="stat-value"><?php echo number_format($assigned_classes); ?></div>
            <div class="stat-line"></div>
        </div>
    </section>

    <!-- FILTER -->
    <section class="filter-panel">
        <form method="GET" action="teachers.php" class="filter-form">

            <div class="filter-group">
                <label>Search Teacher</label>
                <input
                    type="text"
                    name="search"
                    class="filter-control"
                    placeholder="Name, email, phone, employee no. or specialization..."
                    value="<?php echo e($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label>Employment Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Employment Statuses</option>
                    <option value="active"   <?php echo $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="filter-button">Search</button>
            <a href="teachers.php" class="reset-button">Reset</a>
        </form>
    </section>

    <!-- TEACHERS TABLE -->
    <section class="table-panel">

        <div class="table-header">
            <h2>Teacher Records</h2>
            <span class="table-count">
                <?php echo number_format(count($teachers)); ?> teacher(s)
            </span>
        </div>

        <?php if (!empty($teachers)): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Teacher</th>
                            <th>Employee No.</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Qualification</th>
                            <th>Specialization</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php
                    $number = 1;

                    foreach ($teachers as $teacher):

                        $name = full_name($teacher);
                        if ($name === '') {
                            $name = 'Teacher #' . $teacher['teacher_id'];
                        }

                        $initial = strtoupper(substr($name, 0, 1));

                        $teacher_status = strtolower($teacher['employment_status'] ?? '');
                        if (!in_array($teacher_status, ['active', 'inactive'], true)) {
                            $teacher_status = 'inactive';
                        }

                        $assignment_type = $teacher['assignment_type'] ?? 'teacher';
                        $tid = (int)$teacher['teacher_id'];
                    ?>
                        <tr>

                            <!-- NUMBER -->
                            <td data-label="No."><?php echo $number++; ?></td>

                            <!-- TEACHER -->
                            <td data-label="Teacher">
                                <div class="teacher-cell">
                                    <?php if (!empty($teacher['profile_pic'])): ?>
                                        <img
                                            src="../uploads/<?php echo e($teacher['profile_pic']); ?>"
                                            alt="Teacher"
                                            class="teacher-photo"
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                        >
                                        <div class="teacher-placeholder" style="display:none;"><?php echo e($initial); ?></div>
                                    <?php else: ?>
                                        <div class="teacher-placeholder"><?php echo e($initial); ?></div>
                                    <?php endif; ?>

                                    <div>
                                        <div class="teacher-name"><?php echo e($name); ?></div>
                                        <div class="teacher-id">Teacher ID: <?php echo $tid; ?></div>
                                        <?php if ($assignment_type !== 'teacher'): ?>
                                            <span class="assignment-badge"><?php echo e($assignment_type); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- EMPLOYEE NUMBER -->
                            <td data-label="Employee No.">
                                <span class="employee-number"><?php echo e($teacher['employee_no'] ?: '—'); ?></span>
                            </td>

                            <!-- EMAIL -->
                            <td data-label="Email">
                                <span class="email"><?php echo e($teacher['email'] ?: '—'); ?></span>
                            </td>

                            <!-- PHONE -->
                            <td data-label="Phone">
                                <span class="phone"><?php echo e($teacher['phone'] ?: '—'); ?></span>
                            </td>

                            <!-- QUALIFICATION -->
                            <td data-label="Qualification">
                                <span class="qualification"><?php echo e($teacher['qualification'] ?: 'Not specified'); ?></span>
                            </td>

                            <!-- SPECIALIZATION -->
                            <td data-label="Specialization">
                                <span class="specialization"><?php echo e($teacher['specialization'] ?: 'Not specified'); ?></span>
                            </td>

                            <!-- STATUS -->
                            <td data-label="Status">
                                <span class="status status-<?php echo e($teacher_status); ?>">
                                    <?php echo ucfirst(e($teacher_status)); ?>
                                </span>
                            </td>

                            <!-- ACTIONS -->
                            <td data-label="Actions">
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-btn"
                                        data-action="view"
                                        data-url="view_teacher.php?id=<?php echo $tid; ?>"
                                    >View</button>

                                    <button
                                        type="button"
                                        class="action-btn"
                                        data-action="edit"
                                        data-url="edit_teacher.php?id=<?php echo $tid; ?>"
                                    >Edit</button>

                                    <button
                                        type="button"
                                        class="action-btn primary"
                                        data-action="assign"
                                        data-url="assign_teacher.php?id=<?php echo $tid; ?>"
                                    >Assign</button>
                                </div>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="empty-icon">T</div>
                <h3>No teachers found</h3>
                <p>There are no teacher records matching your search criteria.</p>
            </div>

        <?php endif; ?>

    </section>

</main>

<script>
/* =========================================================
   SIDEBAR TOGGLE (kept for compatibility)
   ========================================================= */
function toggleSidebar() {
    document.body.classList.toggle('sidebar-collapsed');
}

/* =========================================================
   ACTION BUTTON DELEGATION
   Handles: Add, View, Edit, Assign (and any future data-action)
   ========================================================= */
document.addEventListener('click', function (event) {
    const btn = event.target.closest('[data-action]');
    if (!btn) return;

    const action = btn.dataset.action;
    const url    = btn.dataset.url;

    // Prevent double taps on mobile
    if (btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    setTimeout(() => { btn.dataset.busy = '0'; }, 400);

    switch (action) {

        case 'add':
            // Extend here: open modal, or navigate
            if (url) window.location.href = url;
            break;

        case 'view':
            if (url) window.location.href = url;
            break;

        case 'edit':
            if (url) window.location.href = url;
            break;

        case 'assign':
            if (url) window.location.href = url;
            break;

        default:
            // Fallback: navigate if a URL exists
            if (url) window.location.href = url;
    }
});

/* =========================================================
   OPTIONAL: keyboard shortcut — press "/" to focus search
   ========================================================= */
document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) {
        const search = document.querySelector('input[name="search"]');
        if (search) {
            e.preventDefault();
            search.focus();
        }
    }
});
</script>

</body>
</html>