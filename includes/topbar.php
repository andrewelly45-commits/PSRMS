<?php
/*
|--------------------------------------------------------------------------
| SHARED TOPBAR — Admin / Teacher / Parent / Super Admin
|--------------------------------------------------------------------------
| Requires:
|   - $_SESSION['user_id']
|   - $conn (mysqli)
|
| Optional (set before include):
|   - $topbar_title   (string)
|   - $topbar_actions (string, raw HTML for extra buttons)
|   - $topbar_subtitle (string)
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../includes/db.php';
}

$topbar_user_id = (int) ($_SESSION['user_id'] ?? 0);

$topbar_first    = 'User';
$topbar_last     = '';
$topbar_role     = 'user';
$topbar_photo    = '';
$topbar_initials = 'U';

if ($topbar_user_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT first_name, last_name, role, profile_pic
         FROM users WHERE user_id = ? LIMIT 1"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $topbar_user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($res)) {
            $topbar_first = $row['first_name'] ?: 'User';
            $topbar_last  = $row['last_name']  ?: '';
            $topbar_role  = $row['role']       ?: 'user';
            $topbar_photo = $row['profile_pic'] ?: '';
        }
        mysqli_stmt_close($stmt);
    }
}

$topbar_initials = strtoupper(
    mb_substr($topbar_first, 0, 1) . mb_substr($topbar_last, 0, 1)
);
if (trim($topbar_initials) === '') {
    $topbar_initials = 'U';
}

$topbar_role_label = [
    'super_admin' => 'Super Admin',
    'admin'       => 'Admin',
    'academic'    => 'Academic',
    'teacher'     => 'Teacher',
    'parent'      => 'Parent',
][$topbar_role] ?? ucfirst($topbar_role);

$topbar_subtitle = $topbar_subtitle ?? $topbar_role_label;


/* =========================================================================
   SHARED PROFILE URL
   ========================================================================= */

$script_path = $_SERVER['PHP_SELF'] ?? '';

$project_folder = 'PSRMS';
$parts          = explode('/', trim($script_path, '/'));
$root_index     = array_search($project_folder, $parts, true);

if ($root_index !== false) {
    $project_root = '/' . implode('/', array_slice($parts, 0, $root_index + 1));
    $profile_url  = $project_root . '/profile.php';
    $logout_url   = $project_root . '/auth/logout.php';
} else {
    $profile_url = '../profile.php';
    $logout_url  = '../auth/logout.php';
}


/* =========================================================================
   PROFILE PHOTO URL
   ========================================================================= */

$topbar_photo_url = '';
if ($topbar_photo) {
    if ($root_index !== false) {
        $topbar_photo_url = $project_root . '/uploads/users/' .
            htmlspecialchars($topbar_photo, ENT_QUOTES, 'UTF-8');
    } else {
        $topbar_photo_url = '../uploads/users/' .
            htmlspecialchars($topbar_photo, ENT_QUOTES, 'UTF-8');
    }
}

?>
<style>
    /* =========================================================
       CSS VARIABLES
       ========================================================= */
    :root {
        --topbar-h: 64px;
        --topbar-sidebar-w: 250px;
        --topbar-sidebar-collapsed: 78px;
        --topbar-navy: #17233c;
        --topbar-gold: #e2c65a;
        --topbar-border: #e3e6eb;
        --topbar-muted: #747d8e;
        --topbar-text: #263044;
    }

    /* TOPBAR */
    .app-topbar {
        position: fixed;
        top: 0;
        left: var(--topbar-sidebar-w);
        right: 0;
        height: var(--topbar-h);
        z-index: 1090;

        background: #ffffff;
        border-bottom: 1px solid var(--topbar-border);

        display: flex;
        align-items: center;
        justify-content: space-between;

        padding: 0 20px;

        font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;

        transition: left .25s ease;
    }

    @media (min-width: 801px) {
        body.sidebar-collapsed .app-topbar {
            left: var(--topbar-sidebar-collapsed);
        }
    }

    /* LEFT */
    .app-topbar-left {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
        flex: 1;
    }

    .app-topbar-titles {
        min-width: 0;
        flex: 1;
    }

    .app-topbar-title {
        color: var(--topbar-navy);
        font-size: 15px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .app-topbar-subtitle {
        color: var(--topbar-muted);
        font-size: 11px;
        font-weight: 500;
        margin-top: 1px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* HAMBURGER */
    .app-hamburger {
        display: none;
        width: 40px;
        height: 40px;
        flex-shrink: 0;

        background: var(--topbar-navy);
        border: none;
        border-radius: 8px;

        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;

        cursor: pointer;
        padding: 0;

        -webkit-tap-highlight-color: transparent;
        transition: background .15s ease;
    }

    .app-hamburger:active { background: #0b1220; }

    .app-hamburger span {
        display: block;
        width: 18px;
        height: 2px;
        background: #ffffff;
        border-radius: 2px;
        transition: transform .25s ease, opacity .2s ease;
    }

    .app-hamburger.active span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
    .app-hamburger.active span:nth-child(2) { opacity: 0; }
    .app-hamburger.active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

    /* RIGHT */
    .app-topbar-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .app-user { position: relative; }

    .app-user-btn {
        display: flex;
        align-items: center;
        gap: 10px;

        background: transparent;
        border: 1px solid transparent;
        border-radius: 30px;

        padding: 4px 12px 4px 4px;

        cursor: pointer;
        font-family: inherit;

        transition: .15s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .app-user-btn:hover {
        background: #f7f8fa;
        border-color: var(--topbar-border);
    }

    .app-user-btn:active { background: #eef0f3; }

    .app-user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        flex-shrink: 0;
        object-fit: cover;
        border: 1.5px solid var(--topbar-border);
    }

    .app-user-initials {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        flex-shrink: 0;

        background: var(--topbar-navy);
        color: var(--topbar-gold);

        display: flex;
        align-items: center;
        justify-content: center;

        font-size: 12px;
        font-weight: 800;
        letter-spacing: .5px;
    }

    .app-user-info {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        line-height: 1.15;
        min-width: 0;
    }

    .app-user-name {
        color: var(--topbar-navy);
        font-size: 12.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .app-user-role {
        color: var(--topbar-muted);
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .app-caret {
        color: var(--topbar-muted);
        font-size: 10px;
        margin-left: 2px;
        transition: transform .2s ease;
    }

    .app-user-btn[aria-expanded="true"] .app-caret {
        transform: rotate(180deg);
    }

    /* DROPDOWN */
    .app-user-menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;

        min-width: 220px;
        max-width: calc(100vw - 24px);

        background: #ffffff;
        border: 1px solid var(--topbar-border);
        border-radius: 10px;
        box-shadow: 0 12px 30px rgba(16, 24, 43, .12);

        padding: 8px;

        display: none;
        z-index: 1200;

        animation: topbarPop .16s ease-out;
    }

    .app-user-menu.open { display: block; }

    @keyframes topbarPop {
        from { transform: translateY(-6px); opacity: 0; }
        to   { transform: translateY(0);    opacity: 1; }
    }

    .app-user-menu .menu-header {
        padding: 10px 12px 12px;
        border-bottom: 1px solid #eef0f3;
        margin-bottom: 6px;
    }

    .app-user-menu .menu-header .hname {
        color: var(--topbar-navy);
        font-size: 13px;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .app-user-menu .menu-header .hrole {
        color: var(--topbar-muted);
        font-size: 11px;
        margin-top: 2px;
    }

    .app-user-menu a,
    .app-user-menu button {
        display: flex;
        align-items: center;
        gap: 10px;

        width: 100%;
        padding: 11px 12px;

        background: transparent;
        border: none;
        border-radius: 7px;

        color: var(--topbar-text);
        text-decoration: none;
        text-align: left;

        font-family: inherit;
        font-size: 12.5px;
        font-weight: 600;

        cursor: pointer;
        transition: .12s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .app-user-menu a:hover,
    .app-user-menu button:hover {
        background: #f7f8fa;
        color: var(--topbar-navy);
    }

    .app-user-menu .menu-icon {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        opacity: .65;
    }

    .app-user-menu .menu-divider {
        height: 1px;
        background: #eef0f3;
        margin: 6px 4px;
    }

    .app-user-menu .logout-item { color: #9b4747; }
    .app-user-menu .logout-item:hover {
        background: #faf0f0;
        color: #9b4747;
    }

    /* OVERLAY */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .45);
        z-index: 1050;
        opacity: 0;
        transition: opacity .25s ease;
    }

    .sidebar-overlay.open {
        display: block;
        opacity: 1;
    }

    @media (min-width: 801px) {
        .sidebar-overlay { display: none !important; }
    }

    /* CONTENT PADDING */
    @media (min-width: 801px) {
        .main-content.with-topbar {
            padding-top: calc(var(--topbar-h) + 30px);
        }
    }

    /* MOBILE */
    @media (max-width: 800px) {

        :root { --topbar-h: 58px; }

        .app-topbar {
            left: 0;
            height: var(--topbar-h);
            padding: 0 12px;
            transition: none;

            padding-left:  max(12px, env(safe-area-inset-left));
            padding-right: max(12px, env(safe-area-inset-right));
        }

        body.sidebar-collapsed .app-topbar { left: 0; }

        .app-hamburger { display: flex; }

        .app-topbar-title    { font-size: 13.5px; }
        .app-topbar-subtitle { display: none; }

        .app-user-info { display: none; }
        .app-caret     { display: none; }

        .app-user-btn  { padding: 2px; }
        .app-user-btn:hover { background: transparent; border-color: transparent; }

        .app-user-avatar,
        .app-user-initials {
            width: 34px;
            height: 34px;
            font-size: 11px;
        }

        .app-user-menu {
            right: 0;
            min-width: 200px;
            max-width: calc(100vw - 20px);
        }

        .app-user-menu a,
        .app-user-menu button {
            padding: 13px 12px;
            font-size: 13px;
        }

        .main-content.with-topbar {
            padding-top: calc(var(--topbar-h) + 16px);
        }
    }

    @media (max-width: 400px) {
        .app-topbar-title { font-size: 12.5px; }

        .app-user-avatar,
        .app-user-initials {
            width: 32px;
            height: 32px;
            font-size: 10.5px;
        }
    }

    @media (max-height: 500px) and (max-width: 900px) {
        :root { --topbar-h: 52px; }

        .app-topbar { height: 52px; }

        .app-user-avatar,
        .app-user-initials { width: 32px; height: 32px; }
    }
</style>


<header class="app-topbar" id="appTopbar">

    <!-- LEFT -->
    <div class="app-topbar-left">

        <button
            type="button"
            class="app-hamburger"
            id="appHamburgerBtn"
            aria-label="Toggle menu"
            aria-expanded="false"
        >
            <span></span><span></span><span></span>
        </button>

        <div class="app-topbar-titles">
            <div class="app-topbar-title">
                <?php echo htmlspecialchars($topbar_title ?? 'Dashboard', ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="app-topbar-subtitle">
                <?php echo htmlspecialchars($topbar_subtitle, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

    </div>


    <!-- RIGHT -->
    <div class="app-topbar-right">

        <?php echo $topbar_actions ?? ''; ?>

        <div class="app-user" id="appUserMenuWrap">

            <button
                type="button"
                class="app-user-btn"
                id="appUserBtn"
                aria-haspopup="true"
                aria-expanded="false"
            >
                <?php if ($topbar_photo_url): ?>
                    <img
                        src="<?php echo $topbar_photo_url; ?>"
                        alt=""
                        class="app-user-avatar"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                    >
                    <div class="app-user-initials" style="display:none;">
                        <?php echo htmlspecialchars($topbar_initials, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php else: ?>
                    <div class="app-user-initials">
                        <?php echo htmlspecialchars($topbar_initials, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="app-user-info">
                    <span class="app-user-name">
                        <?php echo htmlspecialchars(trim($topbar_first . ' ' . $topbar_last), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="app-user-role">
                        <?php echo htmlspecialchars($topbar_role_label, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <span class="app-caret">▼</span>
            </button>

            <div class="app-user-menu" id="appUserMenu">

                <div class="menu-header">
                    <div class="hname">
                        <?php echo htmlspecialchars(trim($topbar_first . ' ' . $topbar_last), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="hrole">
                        <?php echo htmlspecialchars($topbar_role_label, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>

                <a href="<?php echo htmlspecialchars($profile_url, ENT_QUOTES, 'UTF-8'); ?>">
                    <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>
                    </svg>
                    My Profile
                </a>

                <div class="menu-divider"></div>

                <a href="<?php echo htmlspecialchars($logout_url, ENT_QUOTES, 'UTF-8'); ?>" class="logout-item">
                    <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M10 17l5-5-5-5"/>
                        <path d="M15 12H3"/>
                        <path d="M21 4v16h-8"/>
                    </svg>
                    Log Out
                </a>

            </div>
        </div>
    </div>

</header>


<script>
(function () {
    "use strict";

    /* =========================================================
       USER DROPDOWN
       ========================================================= */
    const userBtn  = document.getElementById('appUserBtn');
    const userMenu = document.getElementById('appUserMenu');
    const userWrap = document.getElementById('appUserMenuWrap');

    function closeUserMenu() {
        if (!userMenu) return;
        userMenu.classList.remove('open');
        if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
    }

    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = userMenu.classList.toggle('open');
            userBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (userWrap && !userWrap.contains(e.target)) closeUserMenu();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeUserMenu();
        });
    }

    /* =========================================================
       MOBILE DRAWER — works for ALL role sidebars
       ========================================================= */
    const hamburger = document.getElementById('appHamburgerBtn');
    const overlay   = document.getElementById('sidebarOverlay');

    /* Includes: admin, teacher, parent, super admin (sa-sidebar) */
    const SIDEBAR_SELECTOR = [
        '.admin-sidebar',
        '.teacher-sidebar',
        '.parent-sidebar',
        '.sa-sidebar',
        '#adminSidebar',
        '#parentSidebar',
        '#saSidebar',
        '#sidebar',
        '.sidebar'
    ].join(', ');

    function getSidebar() {
        return document.querySelector(SIDEBAR_SELECTOR);
    }

    function openSidebar() {
        document.body.classList.add('no-scroll', 'sidebar-mobile-open');
        if (overlay) overlay.classList.add('open');
        const sidebar = getSidebar();
        if (sidebar) sidebar.classList.add('open');
        if (hamburger) {
            hamburger.classList.add('active');
            hamburger.setAttribute('aria-expanded', 'true');
        }
    }

    function closeSidebar() {
        document.body.classList.remove('sidebar-mobile-open');
        if (overlay) overlay.classList.remove('open');
        const sidebar = getSidebar();
        if (sidebar) sidebar.classList.remove('open');
        if (hamburger) {
            hamburger.classList.remove('active');
            hamburger.setAttribute('aria-expanded', 'false');
        }
        if (!document.querySelector('.modal-backdrop.open')) {
            document.body.classList.remove('no-scroll');
        }
    }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            if (document.body.classList.contains('sidebar-mobile-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    /* Auto-close on nav link tap (mobile only) */
    document.querySelectorAll(
        '.sidebar .nav-item, ' +
        '.admin-sidebar a, ' +
        '.teacher-sidebar a, ' +
        '.parent-sidebar a, ' +
        '.sa-sidebar a'
    ).forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 800) closeSidebar();
        });
    });

    /* Auto-close when resizing to desktop */
    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (window.innerWidth > 800) closeSidebar();
        }, 120);
    });

    /* Expose for pages that want them */
    window.openTopbarSidebar  = openSidebar;
    window.closeTopbarSidebar = closeSidebar;
})();
</script>