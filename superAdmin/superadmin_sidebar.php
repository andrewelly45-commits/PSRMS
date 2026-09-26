<?php
/* =========================================================================
   SUPER ADMIN SIDEBAR
   Include from any file inside /superAdmin/
   ========================================================================= */

$sa_current = basename($_SERVER['PHP_SELF'] ?? '');

function sa_active(string ...$files): string {
    global $sa_current;
    return in_array($sa_current, $files, true) ? ' active' : '';
}
?>
<style>
    /* =========================================================
       SUPER ADMIN SIDEBAR — RESPONSIVE
    ========================================================= */
    .sa-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: 250px;
        background: linear-gradient(180deg, #10182b 0%, #17233c 100%);
        color: #cfd4dc;
        z-index: 1080;
        overflow-y: auto;
        transition: transform .25s ease;
        scrollbar-width: thin;
    }

    .sa-sidebar::-webkit-scrollbar { width: 6px; }
    .sa-sidebar::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.12);
        border-radius: 6px;
    }

    .sa-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 20px 20px 22px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        position: sticky;
        top: 0;
        background: rgba(16,24,43,.92);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        z-index: 2;
    }

    .sa-brand-logo {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: var(--gold, #c9a227);
        color: #10182b;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        font-weight: 800;
        flex-shrink: 0;
    }

    .sa-brand-text { min-width: 0; }

    .sa-brand-title {
        color: #ffffff;
        font-size: 13.5px;
        font-weight: 800;
        letter-spacing: .3px;
    }

    .sa-brand-sub {
        color: #c9a227;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .8px;
        margin-top: 2px;
    }

    .sa-nav {
        padding: 14px 12px 30px;
    }

    .sa-section-label {
        color: #6b7386;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        padding: 14px 12px 6px;
    }

    .sa-nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 14px;
        border-radius: 9px;
        color: #cfd4dc;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 3px;
        transition: .15s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .sa-nav a:hover {
        background: rgba(255,255,255,.06);
        color: #ffffff;
    }

    .sa-nav a.active {
        background: linear-gradient(90deg, #c9a227 0%, #e2c65a 100%);
        color: #10182b;
        font-weight: 750;
        box-shadow: 0 6px 14px rgba(201,162,39,.18);
    }

    .sa-nav a.active i { color: #10182b; }

    .sa-nav a i {
        width: 18px;
        text-align: center;
        font-size: 14px;
        color: #c9a227;
        flex-shrink: 0;
    }

    .sa-nav a .sa-badge {
        margin-left: auto;
        background: rgba(201,162,39,.18);
        color: #e2c65a;
        font-size: 10px;
        font-weight: 800;
        padding: 3px 8px;
        border-radius: 20px;
        letter-spacing: .4px;
    }

    .sa-nav a.active .sa-badge {
        background: rgba(16,24,43,.18);
        color: #10182b;
    }

    /* Divider */
    .sa-divider {
        height: 1px;
        background: rgba(255,255,255,.08);
        margin: 12px 6px;
    }

    /* Logout */
    .sa-nav a.sa-logout {
        color: #f3b3b3;
    }
    .sa-nav a.sa-logout i { color: #f3b3b3; }
    .sa-nav a.sa-logout:hover {
        background: rgba(155,71,71,.15);
        color: #ffdada;
    }

    /* =========================================================
       MOBILE DRAWER
    ========================================================= */
    @media (max-width: 800px) {

        .sa-sidebar {
            transform: translateX(-100%);
            width: 280px;
            max-width: 85vw;

            /* Safe-area insets for notched phones */
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            padding-left: env(safe-area-inset-left);
        }

        .sa-sidebar.open {
            transform: translateX(0);
            box-shadow: 6px 0 40px rgba(0,0,0,.5);
        }

        .sa-brand {
            padding: 16px 18px 18px;
        }

        .sa-brand-logo {
            width: 34px;
            height: 34px;
            font-size: 15px;
        }

        .sa-brand-title { font-size: 13px; }
        .sa-brand-sub   { font-size: 10px; }

        .sa-nav {
            padding: 10px 10px 30px;
        }

        .sa-nav a {
            padding: 13px 14px;
            font-size: 13.5px;
            gap: 14px;
            margin-bottom: 2px;
            /* Bigger tap target on mobile */
            min-height: 48px;
        }

        .sa-nav a i {
            font-size: 15px;
            width: 20px;
        }

        .sa-section-label {
            padding: 12px 12px 6px;
            font-size: 9.5px;
        }
    }

    /* Extra small phones */
    @media (max-width: 400px) {
        .sa-sidebar {
            width: 260px;
        }
        .sa-brand { padding: 14px 16px 16px; }
        .sa-nav a {
            padding: 12px 12px;
            font-size: 13px;
        }
    }

    /* Landscape phones — reduce vertical padding */
    @media (max-height: 500px) and (max-width: 900px) {
        .sa-brand { padding: 12px 16px 14px; }
        .sa-brand-logo { width: 32px; height: 32px; font-size: 14px; }
        .sa-nav { padding: 8px 10px 20px; }
        .sa-nav a {
            padding: 10px 12px;
            min-height: 42px;
        }
        .sa-section-label {
            padding: 8px 12px 4px;
        }
    }

    /* Reduced motion */
    @media (prefers-reduced-motion: reduce) {
        .sa-sidebar {
            transition: none;
        }
    }
</style>

<aside class="sa-sidebar" id="saSidebar">
    <div class="sa-brand">
        <div class="sa-brand-logo">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div class="sa-brand-text">
            <div class="sa-brand-title">PSRMS</div>
            <div class="sa-brand-sub">Super Admin</div>
        </div>
    </div>

    <nav class="sa-nav">

        <div class="sa-section-label">System</div>

        <a href="dashboard.php" class="<?php echo sa_active('dashboard.php'); ?>">
            <i class="fa-solid fa-gauge-high"></i>
            Dashboard
        </a>

        <a href="users.php" class="<?php echo sa_active('users.php'); ?>">
            <i class="fa-solid fa-users-gear"></i>
            User Management
        </a>

        <a href="roles.php" class="<?php echo sa_active('roles.php'); ?>">
            <i class="fa-solid fa-user-shield"></i>
            Roles &amp; Permissions
        </a>

        <a href="audit_log.php" class="<?php echo sa_active('audit_log.php'); ?>">
            <i class="fa-solid fa-clipboard-list"></i>
            Audit Log
        </a>

        <div class="sa-divider"></div>

        <div class="sa-section-label">Data</div>

        <a href="backup.php" class="<?php echo sa_active('backup.php'); ?>">
            <i class="fa-solid fa-database"></i>
            Backup &amp; Restore
        </a>

        <a href="import.php" class="<?php echo sa_active('import.php'); ?>">
            <i class="fa-solid fa-file-import"></i>
            Bulk Import
        </a>

        <div class="sa-divider"></div>

        <div class="sa-section-label">System</div>

        <a href="settings.php" class="<?php echo sa_active('settings.php'); ?>">
            <i class="fa-solid fa-sliders"></i>
            System Settings
        </a>

        <a href="school_info.php" class="<?php echo sa_active('school_info.php'); ?>">
            <i class="fa-solid fa-school"></i>
            School Info
        </a>

        <div class="sa-divider"></div>

        <a href="../profile.php">
            <i class="fa-solid fa-user-gear"></i>
            My Profile
        </a>

        <a href="../auth/logout.php" class="sa-logout">
            <i class="fa-solid fa-right-from-bracket"></i>
            Log Out
        </a>

    </nav>
</aside>

<script>
/* =========================================================================
   SIDEBAR — AUTO-CLOSE ON LINK TAP (mobile only)
   The drawer itself is opened/closed by includes/topbar.php
   ========================================================================= */
(function () {
    "use strict";

    const sidebar = document.getElementById('saSidebar');
    if (!sidebar) return;

    /* When a nav link is tapped on mobile, close the drawer so the
       next page loads without the overlay stuck open. */
    sidebar.querySelectorAll('.sa-nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 800) {
                /* Let the navigation proceed, but remove the open state
                   so the transition starts before the page unloads. */
                sidebar.classList.remove('open');

                const overlay = document.getElementById('sidebarOverlay');
                if (overlay) overlay.classList.remove('open');

                document.body.classList.remove(
                    'sidebar-mobile-open',
                    'no-scroll'
                );
            }
        });
    });
})();
</script>