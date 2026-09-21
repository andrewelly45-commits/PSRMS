<?php

session_start();

require_once '../auth/auth_check.php';
requireRole('teacher'); // teachers, academics, and headteachers all pass

require_once '../includes/db.php';

$user_id = (int) $_SESSION['user_id'];


/* =========================================================================
   Load current teacher profile
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.phone,
        u.gender,
        t.teacher_id,
        t.employee_no,
        t.qualification,
        t.specialization,
        t.employment_status,
        t.assignment_type,
        t.created_at
     FROM users u
     INNER JOIN teachers t ON t.user_id = u.user_id
     WHERE u.user_id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$me = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$me) {
    die('Teacher profile not found.');
}

$is_headteacher = ($me['assignment_type'] === 'headteacher');
$is_academic    = ($me['assignment_type'] === 'academic');

$full_name = trim(
    $me['first_name'] . ' ' .
    ($me['middle_name'] ? $me['middle_name'] . ' ' : '') .
    $me['last_name']
);

$role_label = [
    'teacher'     => 'Teacher',
    'academic'    => 'Academic Master',
    'headteacher' => 'Headteacher',
][$me['assignment_type']] ?? 'Teacher';


/* =========================================================================
   HEADTEACHER ACTIONS: assign / unassign Academic Master
   ========================================================================= */

$flash = '';
$flash_type = '';

if ($is_headteacher && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action      = $_POST['action'] ?? '';
    $target_id   = (int) ($_POST['teacher_id'] ?? 0);

    if ($action === 'make_academic' && $target_id > 0) {

        /* Only promote a normal 'teacher' to 'academic' */
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE teachers
             SET assignment_type = 'academic',
                 role_assigned_by = ?,
                 role_assigned_at = NOW()
             WHERE teacher_id = ?
               AND assignment_type = 'teacher'"
        );

        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $target_id);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected > 0) {
            $flash = 'Teacher promoted to Academic Master.';
            $flash_type = 'success';
        } else {
            $flash = 'Could not promote — the teacher may already hold a role.';
            $flash_type = 'error';
        }

    } elseif ($action === 'remove_academic' && $target_id > 0) {

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE teachers
             SET assignment_type = 'teacher',
                 role_assigned_by = ?,
                 role_assigned_at = NOW()
             WHERE teacher_id = ?
               AND assignment_type = 'academic'"
        );

        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $target_id);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected > 0) {
            $flash = 'Academic Master role removed.';
            $flash_type = 'success';
        } else {
            $flash = 'Could not remove the role.';
            $flash_type = 'error';
        }
    }
}


/* =========================================================================
   HEADTEACHER: load all teachers for the manage table
   ========================================================================= */

$teachers_list = [];
$academics_list = [];

if ($is_headteacher) {

    $res = mysqli_query(
        $conn,
        "SELECT
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
         WHERE t.assignment_type <> 'headteacher'
         ORDER BY
            FIELD(t.assignment_type, 'academic', 'teacher'),
            u.first_name ASC"
    );

    while ($row = mysqli_fetch_assoc($res)) {
        $row['full_name'] = trim(
            $row['first_name'] . ' ' .
            ($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );

        if ($row['assignment_type'] === 'academic') {
            $academics_list[] = $row;
        } else {
            $teachers_list[] = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?> Dashboard | PSRMS</title>

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
            --border: #e1e4e9;
            --red: #a04b4b;
            --red-bg: #fbefef;
            --green: #397154;
            --green-bg: #edf6f0;
            --blue: #2f5d8f;
            --blue-bg: #eaf1fa;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 255px;
            padding: 105px 30px 40px;
        }

        .page-header { margin-bottom: 25px; }

        .page-header h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 750;
        }

        .page-header p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .role-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 750;
            padding: 4px 12px;
            border-radius: 20px;
            margin-top: 10px;
            letter-spacing: .4px;
            text-transform: uppercase;
        }

        .role-badge.teacher     { background: var(--blue-bg);  color: var(--blue); }
        .role-badge.academic    { background: var(--green-bg); color: var(--green); }
        .role-badge.headteacher { background: var(--navy);     color: var(--gold-light); }

        /* Alerts */
        .alert {
            border-radius: 7px;
            padding: 12px 15px;
            margin-bottom: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 20px;
        }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 22px;
            font-weight: 750;
            margin-top: 8px;
        }

        /* Section card */
        .section-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow: hidden;
            margin-bottom: 22px;
        }

        .section-card-header {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            background: #fcfcfa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .section-card-header h2 {
            color: var(--navy);
            font-size: 13px;
            font-weight: 750;
        }

        .section-card-header p {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .section-card-body { padding: 22px; }

        /* Profile */
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px 24px;
        }

        .profile-item .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 5px;
        }

        .profile-item .value {
            color: var(--text);
            font-size: 12px;
            font-weight: 600;
        }

        /* Table */
        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        thead th {
            text-align: left;
            padding: 12px 16px;
            background: #fafaf8;
            color: var(--muted);
            font-size: 10px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            font-size: 11px;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfbf8; }

        .name-cell strong { color: var(--navy); font-weight: 750; }
        .name-cell span { display: block; color: var(--muted); font-size: 10px; margin-top: 2px; }

        .pill {
            display: inline-block;
            font-size: 9px;
            font-weight: 750;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .pill.teacher     { background: var(--blue-bg);  color: var(--blue); }
        .pill.academic    { background: var(--green-bg); color: var(--green); }
        .pill.headteacher { background: var(--navy);     color: var(--gold-light); }

        /* Buttons */
        .btn {
            border: none;
            border-radius: 6px;
            padding: 7px 13px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
            white-space: nowrap;
        }

        .btn-promote { background: var(--navy); color: var(--white); }
        .btn-promote:hover { background: var(--navy-dark); }

        .btn-demote  { background: var(--red-bg); color: var(--red); }
        .btn-demote:hover { background: #f6dcdc; }

        .empty-note {
            padding: 22px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media (max-width: 900px) {
            .main-content { margin-left: 0; padding: 100px 18px 30px; }
        }

        @media (max-width: 650px) {
            .profile-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>

<?php include 'teacher_sidebar.php'; ?>
<?php include '../includes/topbar.php'; ?>

<main class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <h1>Welcome, <?php echo htmlspecialchars($me['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>
            <?php if ($is_headteacher): ?>
                You are signed in as the school Headteacher.
            <?php elseif ($is_academic): ?>
                You are signed in as the Academic Master.
            <?php else: ?>
                Here is your teaching overview for today.
            <?php endif; ?>
        </p>
        <span class="role-badge <?php echo htmlspecialchars($me['assignment_type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>


    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo htmlspecialchars($flash_type, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>


    <!-- =========================================================
         STATS
    ========================================================== -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Employee No</div>
            <div class="value"><?php echo htmlspecialchars($me['employee_no'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <div class="stat-card">
            <div class="label">Specialization</div>
            <div class="value" style="font-size:15px;">
                <?php echo htmlspecialchars($me['specialization'] ?: '—', ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="label">Employment Status</div>
            <div class="value" style="font-size:15px;">
                <?php echo htmlspecialchars(ucfirst($me['employment_status']), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <?php if ($is_headteacher): ?>
            <div class="stat-card">
                <div class="label">Academic Masters</div>
                <div class="value"><?php echo count($academics_list); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Teachers</div>
                <div class="value"><?php echo count($teachers_list) + count($academics_list); ?></div>
            </div>
        <?php else: ?>
            <div class="stat-card">
                <div class="label">Member Since</div>
                <div class="value" style="font-size:15px;">
                    <?php echo htmlspecialchars(date('M j, Y', strtotime($me['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>


    <!-- =========================================================
         PROFILE (everyone sees)
    ========================================================== -->
    <div class="section-card">
        <div class="section-card-header">
            <h2>My Profile</h2>
        </div>
        <div class="section-card-body">
            <div class="profile-grid">
                <div class="profile-item">
                    <div class="label">Full Name</div>
                    <div class="value"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-item">
                    <div class="label">Gender</div>
                    <div class="value"><?php echo htmlspecialchars(ucfirst($me['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-item">
                    <div class="label">Email</div>
                    <div class="value"><?php echo htmlspecialchars($me['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-item">
                    <div class="label">Phone</div>
                    <div class="value"><?php echo htmlspecialchars($me['phone'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-item">
                    <div class="label">Qualification</div>
                    <div class="value"><?php echo htmlspecialchars($me['qualification'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-item">
                    <div class="label">Assignment</div>
                    <div class="value"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>
    </div>


    <?php if ($is_headteacher): ?>

        <!-- =========================================================
             HEADTEACHER ONLY: Manage Academic Masters
        ========================================================== -->
        <div class="section-card">
            <div class="section-card-header">
                <div>
                    <h2>Academic Masters</h2>
                </div>
            </div>

            <?php if (empty($academics_list)): ?>
                <div class="empty-note">No Academic Master has been assigned yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Employee No</th>
                                <th>Qualification</th>
                                <th>Specialization</th>
                                <th>Assigned On</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($academics_list as $row): ?>
                                <tr>
                                    <td class="name-cell">
                                        <strong><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['employee_no'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['qualification'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['specialization'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                        echo $row['role_assigned_at']
                                            ? htmlspecialchars(date('M j, Y', strtotime($row['role_assigned_at'])), ENT_QUOTES, 'UTF-8')
                                            : '—';
                                        ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Remove the Academic Master role from this teacher?');">
                                            <input type="hidden" name="action" value="remove_academic">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$row['teacher_id']; ?>">
                                            <button type="submit" class="btn btn-demote">Remove Role</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>


        <!-- =========================================================
             HEADTEACHER ONLY: All Teachers (can be promoted)
        ========================================================== -->
        <div class="section-card">
            <div class="section-card-header">
                <div>
                    <h2>All Teachers</h2>
                </div>
            </div>

            <?php if (empty($teachers_list)): ?>
                <div class="empty-note">No teachers available to promote.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Employee No</th>
                                <th>Qualification</th>
                                <th>Specialization</th>
                                <th>Current Role</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers_list as $row): ?>
                                <tr>
                                    <td class="name-cell">
                                        <strong><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['employee_no'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['qualification'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['specialization'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="pill <?php echo htmlspecialchars($row['assignment_type'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($row['assignment_type'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Promote this teacher to Academic Master?');">
                                            <input type="hidden" name="action" value="make_academic">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$row['teacher_id']; ?>">
                                            <button type="submit" class="btn btn-promote">Make Academic Master</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($is_academic): ?>

        <!-- =========================================================
             ACADEMIC MASTER VIEW
        ========================================================== -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Academic Master Workspace</h2>
            </div>
            <div class="section-card-body">
                <p style="color: var(--muted); font-size: 11px; line-height: 1.6;">
                    As Academic Master you oversee academic performance across the school.
                    Add links here to <strong>manage subjects</strong>, <strong>review exam results</strong>,
                    and <strong>monitor teacher performance</strong> once those modules are built.
                </p>
            </div>
        </div>

    <?php else: ?>

        <!-- =========================================================
             NORMAL TEACHER VIEW
        ========================================================== -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>My Teaching</h2>
            </div>
            <div class="section-card-body">
                <p style="color: var(--muted); font-size: 11px; line-height: 1.6;">
                    Welcome to your teacher workspace. Add links here to
                    <strong>my classes</strong>, <strong>enter marks</strong>,
                    <strong>mark attendance</strong>, and <strong>view timetable</strong>
                    once those modules are built.
                </p>
            </div>
        </div>

    <?php endif; ?>

</main>

</body>
</html>