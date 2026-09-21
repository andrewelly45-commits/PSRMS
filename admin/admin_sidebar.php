<?php
$current_page = basename($_SERVER['PHP_SELF']);

/*
|--------------------------------------------------------------------------
| HELPER: active link
|--------------------------------------------------------------------------
*/
function navActive($page, $current) {
    return $page === $current ? 'active' : '';
}
?>

<aside class="sidebar admin-sidebar" id="adminSidebar">

    <!-- BRAND -->
    <div class="sidebar-brand">
        <div class="brand-mark">PS</div>
        <div class="brand-text">
            <strong>PSRMS</strong>
            <span>School Management</span>
        </div>
    </div>


    <!-- NAVIGATION -->
    <nav class="sidebar-nav" aria-label="Main navigation">

        <div class="nav-section">Main</div>

        <a href="dashboard.php"
           class="nav-item <?php echo navActive('dashboard.php', $current_page); ?>">
            <span class="nav-icon">⌂</span>
            <span>Dashboard</span>
        </a>


        <div class="nav-section">School Management</div>

        <a href="students.php"
           class="nav-item <?php echo navActive('students.php', $current_page); ?>">
            <span class="nav-icon">S</span>
            <span>Students</span>
        </a>

        <a href="teachers.php"
           class="nav-item <?php echo navActive('teachers.php', $current_page); ?>">
            <span class="nav-icon">T</span>
            <span>Teachers</span>
        </a>

        <a href="classes.php"
           class="nav-item <?php echo navActive('classes.php', $current_page); ?>">
            <span class="nav-icon">C</span>
            <span>Classes</span>
        </a>

        <a href="subjects.php"
           class="nav-item <?php echo navActive('subjects.php', $current_page); ?>">
            <span class="nav-icon">B</span>
            <span>Subjects</span>
        </a>

        <a href="parents.php"
           class="nav-item <?php echo navActive('parents.php', $current_page); ?>">
            <span class="nav-icon">P</span>
            <span>Parents</span>
        </a>


        <div class="nav-section">Academics</div>

        <a href="academic_years.php"
           class="nav-item <?php echo navActive('academic_years.php', $current_page); ?>">
            <span class="nav-icon">Y</span>
            <span>Academic Years</span>
        </a>

        <a href="results.php"
           class="nav-item <?php echo navActive('results.php', $current_page); ?>">
            <span class="nav-icon">R</span>
            <span>Results</span>
        </a>

        <a href="reports.php"
           class="nav-item <?php echo navActive('reports.php', $current_page); ?>">
            <span class="nav-icon">P</span>
            <span>Reports</span>
        </a>


        <div class="nav-section">System</div>

        <a href="news.php"
           class="nav-item <?php echo navActive('news.php', $current_page); ?>">
            <span class="nav-icon">N</span>
            <span>News</span>
        </a>

        <a href="gallery.php"
           class="nav-item <?php echo navActive('gallery.php', $current_page); ?>">
            <span class="nav-icon">G</span>
            <span>Gallery</span>
        </a>

        <a href="system_history.php"
           class="nav-item <?php echo navActive('system_history.php', $current_page); ?>">
            <span class="nav-icon">H</span>
            <span>System History</span>
        </a>

        <a href="events.php"
           class="nav-item <?php echo navActive('events.php', $current_page); ?>">
            <span class="nav-icon">⚙</span>
            <span>Events</span>
        </a>

    </nav>

</aside>


<style>
    /* =========================================================
       SIDEBAR — BASE
    ========================================================= */
    .sidebar {
        --sb-bg:            #0f172a;
        --sb-bg-soft:       #111c33;
        --sb-border:        rgba(255, 255, 255, 0.06);
        --sb-text:          #cbd5e1;
        --sb-text-strong:   #f1f5f9;
        --sb-muted:         #64748b;
        --sb-accent:        #6366f1;
        --sb-accent-soft:   rgba(99, 102, 241, 0.15);
        --sb-hover:         rgba(255, 255, 255, 0.05);
        --sb-danger:        #f87171;

        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 1080;

        display: flex;
        flex-direction: column;

        width: 250px;
        height: 100dvh;

        background: var(--sb-bg);
        border-right: 1px solid var(--sb-border);
        color: var(--sb-text);

        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;

        -webkit-overflow-scrolling: touch;
        transition: transform .28s cubic-bezier(.4, 0, .2, 1),
                    width .25s ease;
    }

    .sidebar a,
    .sidebar a:hover,
    .sidebar a:focus,
    .sidebar a:active,
    .sidebar a:visited {
        text-decoration: none;
    }

    /* =========================================================
       BRAND
    ========================================================= */
    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 12px;

        height: 68px;
        padding: 0 20px;
        flex-shrink: 0;

        border-bottom: 1px solid var(--sb-border);
        background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent);
    }

    .brand-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        width: 38px;
        height: 38px;
        flex-shrink: 0;

        border-radius: 10px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;

        font-size: 14px;
        font-weight: 800;
        letter-spacing: 0.02em;

        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
    }

    .brand-text {
        display: flex;
        flex-direction: column;
        line-height: 1.2;
        min-width: 0;
    }

    .brand-text strong {
        font-size: 15px;
        font-weight: 700;
        color: var(--sb-text-strong);
        letter-spacing: 0.02em;
    }

    .brand-text span {
        font-size: 11px;
        color: var(--sb-muted);
        font-weight: 500;
    }

    /* =========================================================
       NAV CONTAINER
    ========================================================= */
    .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 16px 12px 20px;
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,0.1) transparent;
        -webkit-overflow-scrolling: touch;
    }

    /* =========================================================
       SECTION LABELS
    ========================================================= */
    .nav-section {
        padding: 18px 12px 8px;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--sb-muted);
        user-select: none;
    }

    .nav-section:first-child { padding-top: 6px; }

    /* =========================================================
       NAV ITEMS
    ========================================================= */
    .nav-item,
    .logout-item {
        position: relative;
        display: flex;
        align-items: center;
        gap: 12px;

        min-height: 42px;
        padding: 0 12px;
        margin: 2px 0;

        border-radius: 9px;

        color: var(--sb-text);
        font-size: 13.5px;
        font-weight: 500;

        transition: background .15s ease, color .15s ease;
    }

    .nav-item:hover,
    .logout-item:hover {
        background: var(--sb-hover);
        color: var(--sb-text-strong);
    }

    .nav-item:focus-visible,
    .logout-item:focus-visible {
        outline: 2px solid var(--sb-accent);
        outline-offset: -2px;
    }

    /* ACTIVE STATE */
    .nav-item.active {
        background: var(--sb-accent-soft);
        color: #fff;
        font-weight: 600;
    }

    .nav-item.active::before {
        content: "";
        position: absolute;
        left: -12px;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 22px;
        background: var(--sb-accent);
        border-radius: 0 3px 3px 0;
    }

    /* =========================================================
       ICONS
    ========================================================= */
    .nav-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        width: 22px;
        height: 22px;
        flex-shrink: 0;

        font-size: 14px;
        font-weight: 700;
        color: var(--sb-muted);

        transition: color .15s ease;
    }

    .nav-item:hover .nav-icon,
    .logout-item:hover .nav-icon { color: var(--sb-text-strong); }

    .nav-item.active .nav-icon { color: var(--sb-accent); }

    /* =========================================================
       BOTTOM / LOGOUT
    ========================================================= */
    .sidebar-bottom {
        flex-shrink: 0;
        padding: 12px;
        border-top: 1px solid var(--sb-border);
        background: var(--sb-bg-soft);
    }

    .logout-item { color: var(--sb-text); }

    .logout-item:hover {
        background: rgba(248, 113, 113, 0.1);
        color: var(--sb-danger);
    }

    .logout-item:hover .nav-icon { color: var(--sb-danger); }

    /* =========================================================
       COLLAPSED (desktop)
    ========================================================= */
    .sidebar.is-collapsed { width: 72px; }

    .sidebar.is-collapsed .brand-text,
    .sidebar.is-collapsed .nav-section,
    .sidebar.is-collapsed .nav-item span:not(.nav-icon),
    .sidebar.is-collapsed .logout-item span:not(.nav-icon) {
        display: none;
    }

    .sidebar.is-collapsed .nav-item,
    .sidebar.is-collapsed .logout-item {
        justify-content: center;
        padding: 0;
    }

    /* =========================================================
       SCROLLBAR
    ========================================================= */
    .sidebar-nav::-webkit-scrollbar { width: 6px; }
    .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, .08);
        border-radius: 3px;
    }
    .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, .15);
    }

    /* =========================================================
       OVERLAY (mobile) — dim the page behind the drawer
    ========================================================= */
    .sidebar-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .45);
        z-index: 1070;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s ease;
    }

    .sidebar-overlay.open,
    body.sidebar-mobile-open .sidebar-overlay {
        opacity: 1;
        pointer-events: auto;
    }

    /* =========================================================
       MOBILE — drawer behaviour
    ========================================================= */
    @media (max-width: 800px) {

        .sidebar {
            width: 280px;
            max-width: 85vw;
            transform: translateX(-100%);
            box-shadow: 5px 0 25px rgba(0, 0, 0, .25);
            will-change: transform;
        }

        /* Both class conventions are supported */
        .sidebar.open,
        body.sidebar-mobile-open .sidebar {
            transform: translateX(0);
        }

        @supports (padding: max(0px)) {
            .sidebar-bottom {
                padding-bottom: max(14px, env(safe-area-inset-bottom));
            }
        }

        .nav-item,
        .logout-item {
            min-height: 46px;
            font-size: 13.5px;
        }

        .nav-section {
            padding-top: 16px;
            padding-bottom: 10px;
        }
    }

    /* Overlay is only needed on mobile */
    @media (min-width: 801px) {
        .sidebar-overlay { display: none; }
    }

    /* =========================================================
       SMALL PHONES
    ========================================================= */
    @media (max-width: 480px) {
        .sidebar { width: 260px; }
        .sidebar-brand { padding: 0 18px; }
        .sidebar-nav { padding: 14px 10px; }

        .nav-item,
        .logout-item {
            padding: 0 10px;
            gap: 10px;
        }
    }

    /* =========================================================
       LANDSCAPE PHONES
    ========================================================= */
    @media (max-height: 500px) and (max-width: 900px) {
        .sidebar-brand { height: 60px; }

        .nav-item,
        .logout-item { min-height: 40px; }

        .nav-section { padding: 8px 12px 4px; }
    }
</style>