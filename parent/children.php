<?php

session_start();

require_once '../includes/db.php';
require_once '../auth/auth_check.php';

/*
|--------------------------------------------------------------------------
| Parent Authentication
|--------------------------------------------------------------------------
| Only logged-in parents can view this page.
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Verify this is a parent account
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.phone,
        u.status,
        p.parent_id,
        p.occupation,
        p.address
     FROM users u
     INNER JOIN parents p ON p.user_id = u.user_id
     WHERE u.user_id = ?
       AND u.role = 'parent'
     LIMIT 1"
);

if (!$stmt) {
    die("Failed to prepare parent query: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$parent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$parent) {
    header("Location: ../login.php");
    exit;
}

$parent_id   = (int) $parent['parent_id'];
$parent_name = trim(
    $parent['first_name'] . ' ' .
    ($parent['middle_name'] ?? '') . ' ' .
    $parent['last_name']
);

/*
|--------------------------------------------------------------------------
| Fetch children linked to this parent
|--------------------------------------------------------------------------
| Includes class teacher name + phone for each child's class.
|--------------------------------------------------------------------------
*/

$children = [];

$sql = "
    SELECT
        pc.id,
        pc.relationship,
        pc.is_primary_guardian,

        s.student_id,
        s.admission_no,
        s.full_name,
        s.gender,
        s.date_of_birth,
        s.admission_date,
        s.photo,
        s.status AS student_status,

        c.class_id,
        c.class_name,
        c.class_level,
        c.stream,

        /* Class teacher of the child's class (active, most recent) */
        (
            SELECT TRIM(CONCAT(
                tu.first_name, ' ',
                IFNULL(tu.middle_name, ''), ' ',
                tu.last_name
            ))
            FROM class_teachers ct
            INNER JOIN teachers t ON t.teacher_id = ct.teacher_id
            INNER JOIN users tu   ON tu.user_id  = t.user_id
            WHERE ct.class_id = c.class_id
              AND ct.status   = 'active'
            ORDER BY ct.assigned_at DESC
            LIMIT 1
        ) AS class_teacher_name,

        /* Class teacher's phone */
        (
            SELECT tu.phone
            FROM class_teachers ct
            INNER JOIN teachers t ON t.teacher_id = ct.teacher_id
            INNER JOIN users tu   ON tu.user_id  = t.user_id
            WHERE ct.class_id = c.class_id
              AND ct.status   = 'active'
            ORDER BY ct.assigned_at DESC
            LIMIT 1
        ) AS class_teacher_phone

    FROM parent_children pc

    INNER JOIN students s ON s.student_id = pc.student_id
    LEFT JOIN classes  c  ON c.class_id   = s.class_id

    WHERE pc.parent_id = ?

    ORDER BY
        c.class_level ASC,
        s.full_name   ASC
";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $parent_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $children[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$total_children = count($children);

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta name="theme-color" content="#17233c">

    <title>My Children | PSRMS</title>

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
            --orange: #9a7422;
            --orange-bg: #faf5e8;
            --red: #a12626;
            --red-bg: #fff0f0;
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

        /* =========================================================
           MOBILE TOPBAR
        ========================================================== */
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

        .mobile-topbar .brand {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px;
            height: 40px;
            border: none;
            background: rgba(255,255,255,.08);
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            padding: 0;
        }

        .hamburger span {
            display: block;
            width: 18px;
            height: 2px;
            background: var(--white);
            border-radius: 2px;
            transition: .2s ease;
        }

        .hamburger.active span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .hamburger.active span:nth-child(2) { opacity: 0; }
        .hamburger.active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050;
            opacity: 0;
            transition: opacity .25s ease;
        }

        .sidebar-overlay.open { display: block; opacity: 1; }

        /* =========================================================
           PAGE
        ========================================================== */
        .page-wrapper {
            max-width: 1100px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            margin: 0;
            font-size: 28px;
            color: var(--navy);
        }

        .page-title p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: var(--navy);
            color: #fff;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: .3px;
        }

        .count-badge strong {
            color: var(--gold-light);
            font-size: 15px;
        }

        /* =========================================================
           CHILD CARD
        ========================================================== */
        .children-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 18px;
        }

        .child-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,.04);
            transition: .2s ease;
        }

        .child-card:hover {
            box-shadow: 0 8px 28px rgba(0,0,0,.08);
            transform: translateY(-2px);
        }

        .child-card-header {
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid #f1f3f6;
        }

        .child-avatar {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            overflow: hidden;
        }

        .child-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .child-info {
            min-width: 0;
            flex: 1;
        }

        .child-name {
            font-size: 16px;
            font-weight: 750;
            color: var(--navy);
            margin-bottom: 3px;
            overflow-wrap: anywhere;
        }

        .child-admission {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }

        .child-card-body {
            padding: 16px 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
        }

        .child-meta-item {
            min-width: 0;
        }

        .child-meta-item .k {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .child-meta-item .v {
            font-size: 13.5px;
            font-weight: 650;
            color: var(--text);
            overflow-wrap: anywhere;
        }

        .child-meta-item.full {
            grid-column: 1 / -1;
        }

        /* Pills */
        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .pill.active      { background: var(--green-bg);  color: var(--green); }
        .pill.inactive    { background: #f1f2f4;          color: #666; }
        .pill.graduated   { background: #eaf1fa;          color: #2f5d8f; }
        .pill.transferred { background: var(--orange-bg); color: var(--orange); }

        .rel-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .3px;
            background: #eef2f7;
            color: var(--navy);
        }

        .primary-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .3px;
            background: var(--gold);
            color: var(--navy);
        }

        /* =========================================================
           TEACHER & PHONE CHIPS
        ========================================================== */
        .teacher-chip,
        .phone-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        .teacher-chip {
            background: #eef2f7;
            color: var(--navy);
        }

        .phone-chip {
            background: var(--green-bg);
            color: var(--green);
            text-decoration: none;
            border: 1px solid #cfe5d7;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .phone-chip:hover {
            background: #d7eee1;
            border-color: var(--green);
        }

        .teacher-chip svg,
        .phone-chip svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .child-card-footer {
            padding: 12px 20px 18px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .child-card-footer .btn-link {
            flex: 1;
            min-width: 130px;
            text-align: center;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid var(--border);
            color: var(--navy);
            background: var(--white);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .child-card-footer .btn-link:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .child-card-footer .btn-link.primary {
            background: var(--navy);
            color: #fff;
            border-color: var(--navy);
        }

        .child-card-footer .btn-link.primary:hover {
            background: var(--navy-dark);
            color: #fff;
            border-color: var(--navy-dark);
        }

        /* =========================================================
           EMPTY
        ========================================================== */
        .empty-state {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 60px 24px;
            text-align: center;
        }

        .empty-state .icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #f1f5fa;
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: 800;
        }

        .empty-state h3 {
            margin: 0 0 10px;
            color: var(--navy);
            font-size: 18px;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.6;
            max-width: 420px;
            margin-left: auto;
            margin-right: auto;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================== */
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
            .page-title p  { font-size: 12.5px; }

            .count-badge { justify-content: center; }

            .children-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .child-card-header { padding: 16px; }
            .child-avatar { width: 56px; height: 56px; font-size: 20px; }
            .child-name { font-size: 15px; }

            .child-card-body { padding: 14px 16px; gap: 10px 12px; }

            .child-card-footer {
                padding: 12px 16px 16px;
                flex-direction: column;
            }

            .child-card-footer .btn-link { width: 100%; min-width: 0; }
        }

        @media (max-width: 400px) {
            .page-title h1 { font-size: 19px; }
            .child-card-body { grid-template-columns: 1fr; }
            .child-meta-item.full { grid-column: auto; }
            .child-avatar { width: 50px; height: 50px; font-size: 18px; }

            .teacher-chip,
            .phone-chip {
                font-size: 11.5px;
                padding: 5px 10px;
                white-space: normal;
            }
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

<!-- =========================================================
     MOBILE TOPBAR
========================================================== -->
<div class="mobile-topbar">
    <div class="brand">PSRMS <span>Parent</span></div>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'My Children';
$topbar_subtitle = 'Students linked to your account';
include '../includes/topbar.php';
?>

<?php include 'parent_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-wrapper">

        <!-- PAGE HEADER -->
        <div class="page-header">

            <div class="page-title">
                <h1>My Children</h1>
                <p>All students linked to your parent account.</p>
            </div>

            <div class="count-badge">
                <strong><?= $total_children ?></strong>
                Child<?= $total_children === 1 ? '' : 'ren' ?>
            </div>

        </div>


        <?php if ($total_children > 0): ?>

            <!-- CHILDREN GRID -->
            <div class="children-grid">

                <?php foreach ($children as $c):

                    $initial = strtoupper(mb_substr($c['full_name'], 0, 1));
                    $status  = strtolower($c['student_status'] ?? '');
                ?>

                    <div class="child-card">

                        <!-- HEADER -->
                        <div class="child-card-header">

                            <div class="child-avatar">
                                <?php if (!empty($c['photo'])): ?>
                                    <img
                                        src="../uploads/students/<?= e($c['photo']) ?>"
                                        alt="<?= e($c['full_name']) ?>"
                                    >
                                <?php else: ?>
                                    <?= e($initial) ?>
                                <?php endif; ?>
                            </div>

                            <div class="child-info">

                                <div class="child-name">
                                    <?= e($c['full_name']) ?>
                                </div>

                                <div class="child-admission">
                                    <?= e($c['admission_no']) ?>
                                </div>

                            </div>

                        </div>

                        <!-- BODY -->
                        <div class="child-card-body">

                            <div class="child-meta-item">
                                <div class="k">Class</div>
                                <div class="v">
                                    <?php if (!empty($c['class_name'])): ?>
                                        <?= e($c['class_name']) ?>
                                        <?php if (!empty($c['stream'])): ?>
                                            - <?= e($c['stream']) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">Not assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="child-meta-item">
                                <div class="k">Gender</div>
                                <div class="v"><?= e($c['gender']) ?></div>
                            </div>

                            <div class="child-meta-item">
                                <div class="k">Date of Birth</div>
                                <div class="v">
                                    <?php if (!empty($c['date_of_birth'])): ?>
                                        <?= date('d M Y', strtotime($c['date_of_birth'])) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </div>
                            </div>

                            <div class="child-meta-item">
                                <div class="k">Admission Date</div>
                                <div class="v">
                                    <?php if (!empty($c['admission_date'])): ?>
                                        <?= date('d M Y', strtotime($c['admission_date'])) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </div>
                            </div>

                            <!-- CLASS TEACHER (NAME + PHONE) -->
                            <div class="child-meta-item full">
                                <div class="k">Class Teacher</div>
                                <div class="v">
                                    <?php if (!empty($c['class_teacher_name'])): ?>

                                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">

                                            <span class="teacher-chip">

                                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                                    <circle cx="12" cy="8" r="3.2"/>
                                                    <path d="M4.5 20c0-3.6 3.4-6 7.5-6s7.5 2.4 7.5 6"/>
                                                </svg>

                                                <?= e($c['class_teacher_name']) ?>

                                            </span>

                                            <?php if (!empty($c['class_teacher_phone'])): ?>

                                                <a
                                                    href="tel:<?= e($c['class_teacher_phone']) ?>"
                                                    class="phone-chip"
                                                >
                                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                                        <path d="M22 16.9v3a2 2 0 0 1-2.2 2
                                                                 19.8 19.8 0 0 1-8.6-3.1
                                                                 19.5 19.5 0 0 1-6-6
                                                                 19.8 19.8 0 0 1-3.1-8.7
                                                                 2 2 0 0 1 2-2.2h3
                                                                 a2 2 0 0 1 2 1.7
                                                                 c.1.9.3 3.6.8 5.2
                                                                 a2 2 0 0 1-.5 2.1L8.1 10.9
                                                                 a16 16 0 0 0 6 6l1-1.1
                                                                 a2 2 0 0 1 2.1-.4
                                                                 c1.6.5 3.3.7 5.2.8
                                                                 a2 2 0 0 1 1.6 2z"/>
                                                    </svg>
                                                    <?= e($c['class_teacher_phone']) ?>
                                                </a>

                                            <?php else: ?>

                                                <span style="color:#9ca3af;font-size:12px;">
                                                    Phone not available
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    <?php else: ?>

                                        <span style="color:#9ca3af;">Not assigned</span>

                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="child-meta-item full">
                                <div class="k">Relationship</div>
                                <div class="v">
                                    <span class="rel-pill">
                                        <?= e($c['relationship'] ?? 'Guardian') ?>
                                    </span>
                                    <?php if ((int)$c['is_primary_guardian'] === 1): ?>
                                        <span class="primary-tag">★ Primary</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="child-meta-item full">
                                <div class="k">Status</div>
                                <div class="v">
                                    <span class="pill <?= e($status) ?>">
                                        <?= e($c['student_status']) ?>
                                    </span>
                                </div>
                            </div>

                        </div>

                        <!-- FOOTER -->
                        <div class="child-card-footer">

                            <a
                                href="child.php?student_id=<?= (int)$c['student_id'] ?>"
                                class="btn-link primary"
                            >
                                View Details
                            </a>

                            <a
                                href="results.php?student_id=<?= (int)$c['student_id'] ?>"
                                class="btn-link"
                            >
                                View Results
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="icon">👨‍👩‍👧</div>
                <h3>No children linked yet</h3>
                <p>
                    Your parent account is not yet linked to any student.
                    Please contact the school administration or the class
                    teacher who registered your child.
                </p>
            </div>

        <?php endif; ?>

    </div>

</main>


<script>
/* =========================================================================
   MOBILE SIDEBAR
   ========================================================================= */

const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    document.body.classList.add('no-scroll');
    sidebarOverlay.classList.add('open');

    const sidebar = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (sidebar) sidebar.classList.add('open');

    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}

function closeSidebar() {
    sidebarOverlay.classList.remove('open');

    const sidebar = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (sidebar) sidebar.classList.remove('open');

    if (hamburgerBtn) hamburgerBtn.classList.remove('active');

    document.body.classList.remove('no-scroll');
}

if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', function () {
        if (sidebarOverlay.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
});

window.addEventListener('resize', function () {
    if (window.innerWidth > 900) closeSidebar();
});
</script>

</body>

</html>