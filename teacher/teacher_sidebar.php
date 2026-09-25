<?php
/*
|--------------------------------------------------------------------------
| Teacher Sidebar
|--------------------------------------------------------------------------
| Role-aware sidebar for teachers, academic masters and headteachers.
| Requires:
|   - $_SESSION['user_id']
|   - $conn (mysqli)
|
| Hides the "Attendance" link for anyone who is not a class teacher.
| Adds "Add Student" link for class teachers only.
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../includes/db.php';
}

$sidebar_user_id = (int) ($_SESSION['user_id'] ?? 0);

$sidebar_role       = 'teacher';
$sidebar_name       = 'Teacher';
$sidebar_emp        = '';
$sidebar_teacher_id = 0;
$is_class_teacher   = false;


/* =========================================================================
   HELPERS
   ========================================================================= */

if (!function_exists('ts_table_exists')) {
    function ts_table_exists(mysqli $conn, string $t): bool
    {
        $safe = mysqli_real_escape_string($conn, $t);
        $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
        return $r && mysqli_num_rows($r) > 0;
    }
}


/* =========================================================================
   LOAD TEACHER INFO
   ========================================================================= */

if ($sidebar_user_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            t.teacher_id,
            t.employee_no,
            t.assignment_type,
            u.first_name,
            u.last_name
         FROM users u
         INNER JOIN teachers t ON t.user_id = u.user_id
         WHERE u.user_id = ?
         LIMIT 1"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $sidebar_user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($res)) {
            $sidebar_role       = $row['assignment_type'] ?: 'teacher';
            $sidebar_name       = trim($row['first_name'] . ' ' . $row['last_name']);
            $sidebar_emp        = $row['employee_no'] ?? '';
            $sidebar_teacher_id = (int) $row['teacher_id'];
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   CHECK IF THE TEACHER IS A CLASS TEACHER
   ========================================================================= */

if ($sidebar_teacher_id > 0 && ts_table_exists($conn, 'class_teachers')) {

    $active_year_id = 0;
    if (ts_table_exists($conn, 'academic_years')) {
        $r = mysqli_query(
            $conn,
            "SELECT academic_year_id FROM academic_years
             WHERE status = 'active' ORDER BY year DESC LIMIT 1"
        );
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $active_year_id = (int) $row['academic_year_id'];
        }
    }

    $sql = "
        SELECT 1
        FROM class_teachers
        WHERE teacher_id = ?
          AND status = 'active'
    ";

    $params = [$sidebar_teacher_id];
    $types  = 'i';

    if ($active_year_id > 0) {
        $sql .= " AND academic_year_id = ?";
        $params[] = $active_year_id;
        $types   .= 'i';
    }

    $sql .= " LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $is_class_teacher = (mysqli_stmt_num_rows($stmt) > 0);
        mysqli_stmt_close($stmt);
    }
}


/* Role label for the badge */
$sidebar_role_label = [
    'teacher'     => 'Teacher',
    'academic'    => 'Academic Master',
    'headteacher' => 'Headteacher',
][$sidebar_role] ?? 'Teacher';


/* Current page for active state */
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>

<style>
    /* =========================================================
       TEACHER SIDEBAR
    ========================================================== */
    .teacher-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 250px;
        height: 100vh;
        height: 100dvh;
        background: var(--navy, #17233c);
        color: #ffffff;
        display: flex;
        flex-direction: column;
        z-index: 1080;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        transition: transform .25s cubic-bezier(.4, 0, .2, 1);
    }

    .teacher-sidebar::-webkit-scrollbar { width: 6px; }
    .teacher-sidebar::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.12);
        border-radius: 3px;
    }

    /* Brand */
    .ts-brand {
        padding: 22px 22px 18px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        flex-shrink: 0;
    }
    .ts-brand h1 {
        font-size: 16px;
        font-weight: 800;
        letter-spacing: .5px;
        color: #ffffff;
    }
    .ts-brand h1 span { color: var(--gold-light, #e2c65a); }
    .ts-brand p {
        font-size: 9px;
        color: rgba(255,255,255,.5);
        margin-top: 3px;
        letter-spacing: .6px;
        text-transform: uppercase;
    }

    /* User */
    .ts-user {
        padding: 18px 22px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }
    .ts-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--gold, #c9a227);
        color: var(--navy, #17233c);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 800;
        flex-shrink: 0;
    }
    .ts-user-info { min-width: 0; flex: 1; }
    .ts-user-name {
        color: #ffffff;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ts-user-role {
        display: inline-block;
        margin-top: 4px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
        padding: 3px 8px;
        border-radius: 20px;
    }
    .ts-user-role.teacher     { background: rgba(47, 93, 143, .25);  color: #9cc1e8; }
    .ts-user-role.academic    { background: rgba(57, 113, 84, .25);  color: #9bd4b4; }
    .ts-user-role.headteacher { background: rgba(201, 162, 39, .22); color: var(--gold-light, #e2c65a); }

    /* Nav */
    .ts-nav {
        flex: 1;
        padding: 14px 0 20px;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .ts-nav-section {
        padding: 14px 22px 6px;
        color: rgba(255,255,255,.35);
        font-size: 9px;
        font-weight: 750;
        letter-spacing: 1px;
        text-transform: uppercase;
        user-select: none;
    }
    .ts-nav a {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 11px 22px;
        color: rgba(255,255,255,.72);
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        border-left: 3px solid transparent;
        transition: .15s ease;
        -webkit-tap-highlight-color: transparent;
    }
    .ts-nav a:hover { background: rgba(255,255,255,.05); color: #ffffff; }
    .ts-nav a.active {
        background: rgba(201,162,39,.10);
        color: var(--gold-light, #e2c65a);
        border-left-color: var(--gold, #c9a227);
    }
    .ts-nav a .ts-icon {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        stroke: currentColor;
        fill: none;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    /* Small "add" pill inside nav */
    .ts-nav a .ts-add-badge {
        margin-left: auto;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .4px;
        text-transform: uppercase;
        background: var(--gold, #c9a227);
        color: var(--navy, #17233c);
        padding: 2px 7px;
        border-radius: 20px;
    }

    /* Small "build" pill for admin-only tools */
    .ts-nav a .ts-build-badge {
        margin-left: auto;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .4px;
        text-transform: uppercase;
        background: rgba(255,255,255,.12);
        color: var(--gold-light, #e2c65a);
        padding: 2px 7px;
        border-radius: 20px;
    }

    /* Mobile */
    @media (max-width: 800px) {
        .teacher-sidebar {
            width: 280px;
            max-width: 85vw;
            transform: translateX(-100%);
            box-shadow: 5px 0 25px rgba(0,0,0,.25);
            will-change: transform;
        }
        .teacher-sidebar.open,
        body.sidebar-mobile-open .teacher-sidebar { transform: translateX(0); }

        .ts-nav a {
            padding: 13px 22px;
            font-size: 13px;
            min-height: 46px;
        }

        .ts-nav-section {
            padding-top: 16px;
            padding-bottom: 10px;
            font-size: 9.5px;
        }

        .ts-user { padding: 16px 20px; }
        .ts-brand { padding: 20px 20px 16px; }

        @supports (padding: max(0px)) {
            .ts-nav { padding-bottom: max(20px, env(safe-area-inset-bottom)); }
        }
    }

    @media (max-width: 480px) {
        .teacher-sidebar { width: 260px; }
        .ts-brand { padding: 18px 18px 14px; }
        .ts-user  { padding: 14px 18px; }
        .ts-nav a { padding: 12px 18px; }
        .ts-nav-section { padding-left: 18px; padding-right: 18px; }
    }

    @media (max-height: 500px) and (max-width: 900px) {
        .ts-brand { padding: 14px 20px 12px; }
        .ts-user  { padding: 12px 20px; }
        .ts-nav a { min-height: 40px; padding: 10px 20px; }
        .ts-nav-section { padding: 8px 20px 4px; }
    }
</style>


<aside class="teacher-sidebar" id="teacherSidebar">

    <!-- BRAND -->
    <div class="ts-brand">
        <h1>PSRMS <span>Portal</span></h1>
        <p>Teacher Workspace</p>
    </div>


    <!-- NAV -->
    <nav class="ts-nav">

        <!-- MAIN -->
        <div class="ts-nav-section">Main</div>

        <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            <svg class="ts-icon" viewBox="0 0 24 24">
                <path d="M3 11l9-8 9 8"/>
                <path d="M5 10v10h14V10"/>
            </svg>
            Dashboard
        </a>

        <!-- TEACHING -->
        <?php if ($sidebar_role === 'teacher' || $sidebar_role === 'academic'): ?>
            <a href="my_classes.php" class="<?php echo $current_page === 'my_classes.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 6h16v12H4z"/>
                    <path d="M8 10h8M8 14h5"/>
                </svg>
                My Classes
            </a>

            <a href="subjects.php" class="<?php echo $current_page === 'subjects.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 5h9a3 3 0 0 1 3 3v11H7a3 3 0 0 1-3-3z"/>
                    <path d="M20 5h-4v14h4z"/>
                </svg>
                Subjects
            </a>

            <a href="students.php" class="<?php echo $current_page === 'students.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <circle cx="9" cy="8" r="3"/>
                    <circle cx="17" cy="9" r="2.5"/>
                    <path d="M3 20c0-3 3-5 6-5s6 2 6 5"/>
                    <path d="M14 20c0-2 2-4 4-4s3 1 3 3"/>
                </svg>
                Students
            </a>

            <?php if ($is_class_teacher): ?>
            <a href="add_student.php" class="<?php echo $current_page === 'add_student.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <circle cx="9" cy="8" r="3"/>
                    <path d="M3 20c0-3 3-5 6-5s6 2 6 5"/>
                    <path d="M19 8v6M16 11h6"/>
                </svg>
                Add Student
                <span class="ts-add-badge">New</span>
            </a>

            <a href="attendance.php" class="<?php echo $current_page === 'attendance.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <rect x="4" y="5" width="16" height="16" rx="2"/>
                    <path d="M8 3v4M16 3v4M4 11h16"/>
                    <path d="M9 15l2 2 4-4"/>
                </svg>
                Attendance
            </a>
            <?php endif; ?>

            <a href="results.php" class="<?php echo $current_page === 'results.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 20V6M10 20V10M16 20v-7M22 20H2"/>
                </svg>
                Results
            </a>

            <a href="timetable.php" class="<?php echo $current_page === 'timetable.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="16" rx="2"/>
                    <path d="M8 3v4M16 3v4M3 11h18"/>
                </svg>
                My Timetable
            </a>
        <?php endif; ?>


        <!-- ACADEMIC MASTER EXTRA -->
        <?php if ($sidebar_role === 'academic'): ?>
            <div class="ts-nav-section">Academic Master</div>

            <!-- NEW: Timetable Builder for the whole school -->
            <a href="manage_timetable.php"
               class="<?php echo $current_page === 'manage_timetable.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="16" rx="2"/>
                    <path d="M8 3v4M16 3v4M3 11h18"/>
                    <path d="M8 15h3v3H8z"/>
                </svg>
                Timetable Builder
                <span class="ts-build-badge">Admin</span>
            </a>

            <a href="academic_overview.php" class="<?php echo $current_page === 'academic_overview.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M3 12l9-8 9 8"/>
                    <path d="M6 10v10h12V10"/>
                    <path d="M10 20v-5h4v5"/>
                </svg>
                Academic Overview
            </a>

            <a href="school_results.php" class="<?php echo $current_page === 'school_results' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M6 3h9l3 3v15H6z"/>
                    <path d="M9 8h6M9 12h6M9 16h4"/>
                </svg>
                Exams
            </a>

            <a href="teacher_performance.php" class="<?php echo $current_page === 'teacher_performance.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 20V6M10 20V10M16 20v-7M22 20H2"/>
                    <circle cx="10" cy="4" r="2"/>
                </svg>
                Teacher Performance
            </a>
        <?php endif; ?>


        <!-- HEADTEACHER EXTRA -->
        <?php if ($sidebar_role === 'headteacher'): ?>
            <div class="ts-nav-section">Headteacher</div>

            <a href="manage_roles.php" class="<?php echo $current_page === 'manage_roles.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>
                    <path d="M17 4l2 2-4 4"/>
                </svg>
                Assign Roles
            </a>

            <a href="all_teachers.php" class="<?php echo $current_page === 'all_teachers.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <circle cx="9" cy="8" r="3"/>
                    <circle cx="17" cy="9" r="2.5"/>
                    <path d="M3 20c0-3 3-5 6-5s6 2 6 5"/>
                    <path d="M14 20c0-2 2-4 4-4s3 1 3 3"/>
                </svg>
                All Teachers
            </a>

            <a href="manage_class_teachers.php" class="<?php echo $current_page === 'manage_class_teachers.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 6h16v12H4z"/>
                    <path d="M9 3v4M15 3v4"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                Class Teachers
            </a>

            <a href="manage_assignments.php" class="<?php echo $current_page === 'manage_assignments.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 5h9a3 3 0 0 1 3 3v11H7a3 3 0 0 1-3-3z"/>
                    <path d="M20 5h-4v14h4z"/>
                    <path d="M8 10h5"/>
                </svg>
                Manage Assignments
            </a>

            <a href="school_overview.php" class="<?php echo $current_page === 'school_overview.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M3 21V10l9-7 9 7v11"/>
                    <path d="M9 21v-6h6v6"/>
                </svg>
                School Overview
            </a>

            <a href="announcements.php" class="<?php echo $current_page === 'announcements.php' ? 'active' : ''; ?>">
                <svg class="ts-icon" viewBox="0 0 24 24">
                    <path d="M4 10v4h3l5 4V6L7 10H4z"/>
                    <path d="M17 8a5 5 0 0 1 0 8"/>
                </svg>
                Announcements
            </a>
        <?php endif; ?>

    </nav>

</aside>


<script>
/* Backwards-compatible helper */
window.toggleTeacherSidebar = function () {
    const sidebar = document.getElementById('teacherSidebar');
    if (!sidebar) return;

    sidebar.classList.toggle('open');
    const isOpen = sidebar.classList.contains('open');
    document.body.classList.classList.toggle('sidebar-mobile-open', isOpen);
    document.body.classList.toggle('no-scroll', isOpen);

    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) overlay.classList.toggle('open', isOpen);
};
</script>