<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../includes/db.php';
}

$sidebar_user_id = (int) ($_SESSION['user_id'] ?? 0);
$sidebar_name    = 'Parent';

if ($sidebar_user_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $sidebar_user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $sidebar_name = trim($row['first_name'] . ' ' . $row['last_name']);
        }
        mysqli_stmt_close($stmt);
    }
}

$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<style>
    .parent-sidebar {
        position: fixed; top: 0; left: 0;
        width: 250px; height: 100dvh;
        background: #17233c;
        color: #fff;
        display: flex; flex-direction: column;
        z-index: 1080;
        overflow-y: auto;
        transition: transform .25s cubic-bezier(.4,0,.2,1);
    }

    .ps-brand {
        padding: 22px 22px 18px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .ps-brand h1 { font-size: 16px; font-weight: 800; }
    .ps-brand h1 span { color: #e2c65a; }
    .ps-brand p { font-size: 9px; color: rgba(255,255,255,.5); margin-top: 3px; text-transform: uppercase; letter-spacing: .6px; }

    .ps-user {
        padding: 18px 22px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        display: flex; align-items: center; gap: 12px;
    }
    .ps-avatar {
        width: 40px; height: 40px;
        border-radius: 50%;
        background: #c9a227;
        color: #17233c;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; font-weight: 800;
    }
    .ps-user-info { min-width: 0; flex: 1; }
    .ps-user-name { color: #fff; font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ps-user-role {
        display: inline-block; margin-top: 4px;
        font-size: 9px; font-weight: 700;
        padding: 3px 8px; border-radius: 20px;
        background: rgba(47, 93, 143, .25);
        color: #9cc1e8;
        text-transform: uppercase;
        letter-spacing: .4px;
    }

    .ps-nav { flex: 1; padding: 14px 0 20px; }
    .ps-nav-section {
        padding: 14px 22px 6px;
        color: rgba(255,255,255,.35);
        font-size: 9px; font-weight: 750;
        text-transform: uppercase; letter-spacing: 1px;
    }
    .ps-nav a {
        display: flex; align-items: center; gap: 11px;
        padding: 11px 22px;
        color: rgba(255,255,255,.72);
        text-decoration: none;
        font-size: 12px; font-weight: 600;
        border-left: 3px solid transparent;
        transition: .15s ease;
    }
    .ps-nav a:hover { background: rgba(255,255,255,.05); color: #fff; }
    .ps-nav a.active {
        background: rgba(201,162,39,.10);
        color: #e2c65a;
        border-left-color: #c9a227;
    }
    .ps-nav a .ps-icon { width: 16px; height: 16px; flex-shrink: 0; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }

    @media (max-width: 800px) {
        .parent-sidebar {
            width: 280px;
            max-width: 85vw;
            transform: translateX(-100%);
            box-shadow: 5px 0 25px rgba(0,0,0,.25);
        }
        .parent-sidebar.open,
        body.sidebar-mobile-open .parent-sidebar { transform: translateX(0); }

        .ps-nav a { padding: 13px 22px; font-size: 13px; min-height: 46px; }

        @supports (padding: max(0px)) {
            .ps-nav { padding-bottom: max(20px, env(safe-area-inset-bottom)); }
        }
    }
</style>

<aside class="parent-sidebar" id="parentSidebar">

    <div class="ps-brand">
        <h1>PSRMS <span>Portal</span></h1>
        <p>Parent Access</p>
    </div>

    <div class="ps-user">
        <div class="ps-avatar">
            <?php echo htmlspecialchars(strtoupper(substr($sidebar_name ?: 'P', 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="ps-user-info">
            <div class="ps-user-name"><?php echo htmlspecialchars($sidebar_name, ENT_QUOTES, 'UTF-8'); ?></div>
            <span class="ps-user-role">Parent</span>
        </div>
    </div>

    <nav class="ps-nav">

        <div class="ps-nav-section">Main</div>

        <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
            Dashboard
        </a>

        <a href="children.php" class="<?php echo $current_page === 'children.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M14 20c0-2 2-4 4-4s3 1 3 3"/></svg>
            My Children
        </a>

        <a href="results.php" class="<?php echo $current_page === 'results.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><path d="M4 20V6M10 20V10M16 20v-7M22 20H2"/></svg>
            Results
        </a>

        <a href="attendance.php" class="<?php echo $current_page === 'attendance.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 11h16"/><path d="M9 15l2 2 4-4"/></svg>
            Attendance
        </a>

        <div class="ps-nav-section">School</div>

        <a href="announcements.php" class="<?php echo $current_page === 'announcements.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><path d="M4 10v4h3l5 4V6L7 10H4z"/><path d="M17 8a5 5 0 0 1 0 8"/></svg>
            Announcements
        </a>

        <a href="events.php" class="<?php echo $current_page === 'events.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
            Events
        </a>

        <a href="gallery.php" class="<?php echo $current_page === 'gallery.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="M21 16l-5-5-9 9"/></svg>
            Gallery
        </a>

        <div class="ps-nav-section">Account</div>

        <a href="profile.php" class="<?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
            <svg class="ps-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            My Profile
        </a>

    </nav>

</aside>