<?php
require_once '../auth/auth_check.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if (!$user_id) { header("Location: ../login.php"); exit; }

/* -------------------------------------------------------------------------
   Load teacher + role
------------------------------------------------------------------------- */
$stmt = mysqli_prepare(
    $conn,
    "SELECT t.teacher_id, t.assignment_type, u.first_name, u.middle_name, u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.user_id = ? AND t.employment_status = 'active'
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$teacher) { die("Teacher profile not found."); }

$teacher_id   = (int) $teacher['teacher_id'];
$teacher_role = $teacher['assignment_type'] ?: 'teacher';
$teacher_name = trim($teacher['first_name'] . ' ' . ($teacher['middle_name'] ?? '') . ' ' . $teacher['last_name']);

/* -------------------------------------------------------------------------
   Determine scope: which class(es) can this user see?
------------------------------------------------------------------------- */
$scope_classes   = [];   // [class_id => label]
$is_class_teacher = false;
$my_class_id      = 0;   // class the teacher is class teacher of

/* Active academic year */
$active_year_id = 0;
$r = mysqli_query($conn, "SELECT academic_year_id FROM academic_years WHERE status='active' ORDER BY year DESC LIMIT 1");
if ($r && $row = mysqli_fetch_assoc($r)) $active_year_id = (int)$row['academic_year_id'];

/* 1) Is this user a class teacher? */
$sql = "SELECT ct.class_id, c.class_name, c.stream
        FROM class_teachers ct
        INNER JOIN classes c ON c.class_id = ct.class_id
        WHERE ct.teacher_id = ? AND ct.status = 'active' AND c.status = 'active'";
$params = [$teacher_id]; $types = 'i';
if ($active_year_id > 0) { $sql .= " AND ct.academic_year_id = ?"; $params[] = $active_year_id; $types .= 'i'; }
$sql .= " LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($row) {
    $is_class_teacher = true;
    $my_class_id      = (int)$row['class_id'];
    $scope_classes[$my_class_id] = $row['class_name'] . (!empty($row['stream']) ? ' - ' . $row['stream'] : '');
}

/* 2) Headteacher / academic master → all classes */
if (in_array($teacher_role, ['headteacher', 'academic'], true)) {
    $r = mysqli_query($conn, "SELECT class_id, class_name, stream FROM classes WHERE status='active' ORDER BY class_level, class_name");
    while ($c = mysqli_fetch_assoc($r)) {
        $scope_classes[(int)$c['class_id']] = $c['class_name'] . (!empty($c['stream']) ? ' - ' . $c['stream'] : '');
    }
}

/* 3) Normal subject teacher → classes they teach (via teacher_subjects) */
if ($teacher_role === 'teacher' && !$is_class_teacher) {
    $has_ts = false;
    $r = mysqli_query($conn, "SHOW TABLES LIKE 'teacher_subjects'");
    if ($r && mysqli_num_rows($r) > 0) $has_ts = true;

    if ($has_ts) {
        $sql = "SELECT DISTINCT c.class_id, c.class_name, c.stream
                FROM teacher_subjects ts
                INNER JOIN classes c ON c.class_id = ts.class_id
                WHERE ts.teacher_id = ? AND c.status = 'active'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $teacher_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        while ($c = mysqli_fetch_assoc($r)) {
            $scope_classes[(int)$c['class_id']] = $c['class_name'] . (!empty($c['stream']) ? ' - ' . $c['stream'] : '');
        }
        mysqli_stmt_close($stmt);
    }
}

/* 4) If normal teacher has no subject assignments, fall back to all classes (read-only) */
if ($teacher_role === 'teacher' && !$is_class_teacher && empty($scope_classes)) {
    $r = mysqli_query($conn, "SELECT class_id, class_name, stream FROM classes WHERE status='active' ORDER BY class_level, class_name");
    while ($c = mysqli_fetch_assoc($r)) {
        $scope_classes[(int)$c['class_id']] = $c['class_name'] . (!empty($c['stream']) ? ' - ' . $c['stream'] : '');
    }
}

/* -------------------------------------------------------------------------
   Optional class filter from GET (validated against scope)
------------------------------------------------------------------------- */
$filter_class_id = (int)($_GET['class_id'] ?? 0);
if ($filter_class_id > 0 && !isset($scope_classes[$filter_class_id])) {
    $filter_class_id = 0; // reject out-of-scope request
}

$allowed_ids = array_keys($scope_classes);
if (empty($allowed_ids)) {
    // nothing to show
    $students = [];
    $total_students = 0;
} else {
    /* Build WHERE */
    $where  = [];
    $params = [];
    $types  = '';

    if ($filter_class_id > 0) {
        $where[]  = "s.class_id = ?";
        $params[] = $filter_class_id;
        $types   .= 'i';
    } else {
        $ph = implode(',', array_fill(0, count($allowed_ids), '?'));
        $where[]  = "s.class_id IN ($ph)";
        foreach ($allowed_ids as $cid) { $params[] = $cid; $types .= 'i'; }
    }

    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $where[]  = "(s.full_name LIKE ? OR s.admission_no LIKE ?)";
        $like     = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
        $types   .= 'ss';
    }

    $sql = "SELECT
                s.student_id, s.admission_no, s.full_name, s.gender,
                s.date_of_birth, s.admission_date, s.status, s.photo,
                c.class_name, c.stream,
                GROUP_CONCAT(DISTINCT CONCAT(u.first_name,' ',IFNULL(u.middle_name,''),' ',u.last_name) SEPARATOR ', ') AS parent_names,
                GROUP_CONCAT(DISTINCT u.phone SEPARATOR ', ') AS parent_phones
            FROM students s
            LEFT JOIN classes c        ON c.class_id = s.class_id
            LEFT JOIN parent_children pc ON pc.student_id = s.student_id
            LEFT JOIN parents p        ON p.parent_id = pc.parent_id
            LEFT JOIN users u          ON u.user_id = p.user_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.student_id
            ORDER BY c.class_level ASC, s.full_name ASC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $students = [];
    while ($row = mysqli_fetch_assoc($res)) $students[] = $row;
    mysqli_stmt_close($stmt);
    $total_students = count($students);
}

$scope_label = $is_class_teacher
    ? 'My Class Students'
    : ($teacher_role === 'headteacher'
        ? 'All Students (Headteacher View)'
        : ($teacher_role === 'academic'
            ? 'All Students (Academic View)'
            : 'Students in My Classes'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Students | PSRMS</title>

    <style>
        * { box-sizing: border-box; }

        :root {
            --navy: #17233c;
            --navy-dark: #10182b;
            --gold: #c9a227;
            --gold-light: #e2c65a;
            --cream: #f5f7fa;
            --white: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --green: #19713b;
            --green-bg: #e7f6ed;
            --sidebar-w: 250px;
            --topbar-h: 64px;
        }

        html, body { overflow-x: hidden; }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
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

        .mobile-topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 58px;
            background: var(--navy);
            color: var(--white);
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 1100;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }

        .mobile-topbar .brand { font-size: 14px; font-weight: 800; letter-spacing: .5px; }
        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px; height: 40px; border: none;
            background: rgba(255,255,255,.08);
            border-radius: 6px; display: flex;
            flex-direction: column; align-items: center;
            justify-content: center; gap: 4px;
            cursor: pointer; padding: 0;
        }
        .hamburger span {
            display: block; width: 18px; height: 2px;
            background: var(--white); border-radius: 2px;
            transition: .2s ease;
        }
        .hamburger.active span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .hamburger.active span:nth-child(2) { opacity: 0; }
        .hamburger.active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050; opacity: 0;
            transition: opacity .25s ease;
        }
        .sidebar-overlay.open { display: block; opacity: 1; }

        /* Page */
        .page-container { max-width: 1100px; margin: 0 auto; }

        .page-header {
            display: flex; justify-content: space-between;
            align-items: center; gap: 20px;
            margin-bottom: 25px; flex-wrap: wrap;
        }
        .page-title h1 { margin: 0; font-size: 28px; color: var(--navy); }
        .page-title p  { margin: 7px 0 0; color: var(--muted); font-size: 13px; }

        .add-btn {
            background: var(--navy); color: white;
            text-decoration: none; padding: 12px 20px;
            border-radius: 9px; font-weight: 600;
            font-size: 13.5px; white-space: nowrap;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .add-btn:hover { background: var(--navy-dark); }

        .role-banner {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; margin-bottom: 20px;
            background: #fffbe9; border: 1px solid #f0e0a8;
            border-radius: 10px; font-size: 13px;
            color: #7a5a00; font-weight: 600;
        }
        .role-banner .icon {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--gold); color: var(--navy);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; flex-shrink: 0;
        }

        .filters {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            align-items: end;
            box-shadow: 0 5px 20px rgba(0,0,0,.03);
        }
        .filters label {
            display: block; font-size: 10.5px; font-weight: 700;
            color: var(--navy); margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: .4px;
        }
        .filters input, .filters select {
            width: 100%; height: 42px;
            border: 1px solid #d7dce2; border-radius: 8px;
            padding: 0 12px; font-size: 13.5px; font-family: inherit;
            outline: none; background: #fcfcfd;
        }
        .filters input:focus, .filters select:focus {
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(23,32,51,.08);
            background: #fff;
        }
        .filters .btn-row { display: flex; gap: 8px; }
        .filters button {
            height: 42px; padding: 0 18px;
            background: var(--navy); color: white;
            border: none; border-radius: 8px;
            font-family: inherit; font-size: 13.5px;
            font-weight: 700; cursor: pointer;
        }
        .filters a.clear {
            height: 42px; padding: 0 16px;
            background: var(--white); color: var(--navy);
            border: 1px solid var(--border);
            border-radius: 8px; text-decoration: none;
            display: inline-flex; align-items: center;
            justify-content: center; font-weight: 700;
            font-size: 13.5px;
        }

        .table-card {
            background: white; border-radius: 14px;
            overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,.05);
        }
        .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        table {
            width: 100%; border-collapse: collapse;
            min-width: 900px;
        }
        th {
            background: #f8fafc; text-align: left;
            padding: 15px; font-size: 12px;
            color: var(--muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: .4px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        td {
            padding: 15px; border-bottom: 1px solid #edf0f2;
            vertical-align: middle; font-size: 13.5px;
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfcfd; }

        .student-info {
            display: flex; align-items: center;
            gap: 12px; min-width: 0;
        }
        .student-photo {
            width: 42px; height: 42px; border-radius: 50%;
            object-fit: cover; background: #e9edf1; flex-shrink: 0;
        }
        .student-placeholder {
            width: 42px; height: 42px; border-radius: 50%;
            background: var(--navy); color: var(--gold-light);
            display: flex; align-items: center;
            justify-content: center; font-weight: bold; flex-shrink: 0;
        }
        .student-name { font-weight: 600; color: var(--text); }
        .admission { color: var(--muted); font-size: 12.5px; margin-top: 3px; }

        .status {
            display: inline-block; padding: 5px 10px;
            border-radius: 20px; font-size: 11px;
            font-weight: 700; text-transform: capitalize;
            letter-spacing: .3px;
        }
        .status-active   { background: var(--green-bg); color: var(--green); }
        .status-inactive { background: #f1f2f4; color: #666; }

        .parent-name { font-weight: 500; color: var(--text); }
        .parent-phone { color: var(--muted); font-size: 12px; margin-top: 3px; }

        .class-chip {
            display: inline-block;
            background: #eef2f7; color: var(--navy);
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }

        .empty {
            text-align: center; padding: 60px 20px;
            color: var(--muted);
        }
        .empty h3 { color: #374151; margin-bottom: 8px; font-size: 16px; }

        /* Mobile cards */
        .student-cards { display: none; }
        .student-card {
            padding: 16px; border-bottom: 1px solid var(--border);
        }
        .student-card:last-child { border-bottom: none; }
        .student-card-top {
            display: flex; align-items: center;
            gap: 12px; margin-bottom: 12px;
        }
        .student-card-meta {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 10px 12px; font-size: 12.5px;
            margin-bottom: 12px;
        }
        .student-card-meta .k {
            color: var(--muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: .3px;
            font-size: 10px; margin-bottom: 3px;
        }
        .student-card-meta .v {
            color: var(--text); font-weight: 600;
            overflow-wrap: anywhere;
        }
        .student-card-meta .full { grid-column: 1 / -1; }
        .status-row { display: flex; justify-content: flex-end; }

        @media (max-width: 900px) {
            .mobile-topbar { display: flex; }
            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 40px;
            }
            body.sidebar-collapsed .main-content { margin-left: 0; }
        }

        @media (max-width: 700px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 40px; }
            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 22px; }
            .add-btn { width: 100%; text-align: center; padding: 14px 20px; }

            .filters {
                grid-template-columns: 1fr;
                padding: 14px;
            }
            .filters .btn-row { width: 100%; }
            .filters .btn-row > * { flex: 1; }
            .filters input, .filters select,
            .filters button, .filters a.clear {
                min-height: 46px;
                font-size: 15px;
            }

            .table-wrapper { display: none; }
            .student-cards { display: block; }
        }

        @media (max-width: 400px) {
            .page-title h1 { font-size: 19px; }
            .student-card-meta { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                }
            }
        }
    </style>
</head>
<body>

<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Teacher</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Students';
$topbar_subtitle = $scope_label;
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-container">

        <div class="page-header">
            <div class="page-title">
                <h1>Students</h1>
                <p><?= htmlspecialchars($scope_label) ?></p>
            </div>

            <?php if ($is_class_teacher): ?>
                <a href="add_student.php" class="add-btn">+ Add Student</a>
            <?php endif; ?>
        </div>

        <?php if (!$is_class_teacher && $teacher_role === 'teacher'): ?>
            <div class="role-banner">
                <div class="icon">i</div>
                <div>
                    You are viewing students in your assigned classes.
                    Only <strong>class teachers</strong> can add new students.
                    Contact the class teacher or headteacher if a student needs to be registered.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($is_class_teacher): ?>
            <div class="role-banner" style="background:#eef6f0;border-color:#cfe5d7;color:#2f6b47;">
                <div class="icon" style="background:#2f6b47;color:#fff;">✓</div>
                <div>
                    You are the <strong>class teacher</strong>.
                    You can add new students using the <em>+ Add Student</em> button.
                </div>
            </div>
        <?php endif; ?>

        <!-- FILTERS -->
        <form method="GET" class="filters">
            <div>
                <label>Search</label>
                <input
                    type="text"
                    name="search"
                    placeholder="Name or admission number..."
                    value="<?= htmlspecialchars($search ?? '') ?>"
                >
            </div>

            <div>
                <label>Class</label>
                <select name="class_id">
                    <option value="0">All my classes</option>
                    <?php foreach ($scope_classes as $cid => $clabel): ?>
                        <option value="<?= (int)$cid ?>" <?= $filter_class_id === (int)$cid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($clabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="btn-row">
                <button type="submit">Filter</button>
                <?php if (!empty($search) || $filter_class_id > 0): ?>
                    <a href="students.php" class="clear">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- LIST -->
        <div class="table-card">

            <?php if ($total_students > 0): ?>

                <!-- DESKTOP TABLE -->
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Gender</th>
                                <th>Date of Birth</th>
                                <th>Parent / Guardian</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $i => $s): ?>
                                <?php $status = strtolower($s['status'] ?? ''); ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <div class="student-info">
                                            <?php if (!empty($s['photo'])): ?>
                                                <img src="../uploads/students/<?= htmlspecialchars($s['photo']) ?>" class="student-photo" alt="">
                                            <?php else: ?>
                                                <div class="student-placeholder"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                                                <div class="admission"><?= htmlspecialchars($s['admission_no']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="class-chip">
                                            <?= htmlspecialchars($s['class_name'] . (!empty($s['stream']) ? ' - ' . $s['stream'] : '')) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($s['gender']) ?></td>
                                    <td>
                                        <?php if (!empty($s['date_of_birth'])): ?>
                                            <?= date('d M Y', strtotime($s['date_of_birth'])) ?>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['parent_names'])): ?>
                                            <div class="parent-name"><?= htmlspecialchars($s['parent_names']) ?></div>
                                            <?php if (!empty($s['parent_phones'])): ?>
                                                <div class="parent-phone"><?= htmlspecialchars($s['parent_phones']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">No parent linked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status status-<?= htmlspecialchars($status) ?>">
                                            <?= htmlspecialchars($s['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- MOBILE CARDS -->
                <div class="student-cards">
                    <?php foreach ($students as $s): ?>
                        <?php $status = strtolower($s['status'] ?? ''); ?>
                        <div class="student-card">
                            <div class="student-card-top">
                                <?php if (!empty($s['photo'])): ?>
                                    <img src="../uploads/students/<?= htmlspecialchars($s['photo']) ?>" class="student-photo" alt="">
                                <?php else: ?>
                                    <div class="student-placeholder"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                                <?php endif; ?>
                                <div style="min-width:0;flex:1;">
                                    <div class="student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                                    <div class="admission"><?= htmlspecialchars($s['admission_no']) ?></div>
                                </div>
                            </div>

                            <div class="student-card-meta">
                                <div>
                                    <div class="k">Class</div>
                                    <div class="v">
                                        <span class="class-chip">
                                            <?= htmlspecialchars($s['class_name'] . (!empty($s['stream']) ? ' - ' . $s['stream'] : '')) ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <div class="k">Gender</div>
                                    <div class="v"><?= htmlspecialchars($s['gender']) ?></div>
                                </div>
                                <div>
                                    <div class="k">Date of Birth</div>
                                    <div class="v">
                                        <?php if (!empty($s['date_of_birth'])): ?>
                                            <?= date('d M Y', strtotime($s['date_of_birth'])) ?>
                                        <?php else: ?>-<?php endif; ?>
                                    </div>
                                </div>
                                <div class="full">
                                    <div class="k">Parent / Guardian</div>
                                    <div class="v">
                                        <?php if (!empty($s['parent_names'])): ?>
                                            <?= htmlspecialchars($s['parent_names']) ?>
                                            <?php if (!empty($s['parent_phones'])): ?>
                                                <div class="parent-phone"><?= htmlspecialchars($s['parent_phones']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">No parent linked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="status-row">
                                <span class="status status-<?= htmlspecialchars($status) ?>">
                                    <?= htmlspecialchars($s['status']) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="empty">
                    <h3>No students found</h3>
                    <?php if (!empty($search)): ?>
                        <p>No student matched "<strong><?= htmlspecialchars($search) ?></strong>".</p>
                    <?php elseif ($is_class_teacher): ?>
                        <p>No students have been added to your class yet.</p>
                        <p style="margin-top:12px;">
                            <a href="add_student.php" class="add-btn" style="display:inline-block;">+ Add first student</a>
                        </p>
                    <?php else: ?>
                        <p>No students found in your assigned classes.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

    </div>

</main>

<script>
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');
    const s = document.querySelector('.teacher-sidebar, .admin-sidebar, #sidebar, .sidebar');
    if (s) s.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}
function closeSidebar() {
    sidebarOverlay.classList.remove('open');
    const s = document.querySelector('.teacher-sidebar, .admin-sidebar, #sidebar, .sidebar');
    if (s) s.classList.remove('open');
    if (hamburgerBtn) hamburgerBtn.classList.remove('active');
    document.body.classList.remove('no-scroll');
}
if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', () => {
        if (sidebarOverlay.classList.contains('open')) closeSidebar();
        else openSidebar();
    });
}
if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });
</script>

</body>
</html>