<?php
if (!isset($user)) {
    $user = function_exists('currentUser') ? currentUser() : [];
}

$h_first  = $user['first_name']  ?? '';
$h_middle = $user['middle_name'] ?? '';
$h_last   = $user['last_name']   ?? '';

$h_full = trim($h_first . ' ' . $h_middle . ' ' . $h_last);
if ($h_full === '') { $h_full = 'Administrator'; }

$h_initials = strtoupper(
    mb_substr($h_first, 0, 1) . mb_substr($h_last, 0, 1)
);
if ($h_initials === '') { $h_initials = 'AD'; }

$h_pic  = $user['profile_pic'] ?? '';
$h_role = $user['role'] ?? 'admin';
?>

<header class="topbar">

    <div class="topbar-left">
        <button
            class="sidebar-toggle"
            id="sidebarToggle"
            type="button"
            aria-label="Toggle sidebar"
            aria-controls="adminSidebar"
            aria-expanded="false"
        >
            <span class="toggle-bars" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </button>

        <div class="page-title-wrap">
            <div class="page-title">Admin Dashboard</div>
            <div class="page-subtitle">PSRMS · School Records</div>
        </div>
    </div>

    <div class="topbar-user">

        <div class="user-info">
            <strong><?php echo htmlspecialchars($h_full); ?></strong>
            <span><?php echo htmlspecialchars($h_role); ?></span>
        </div>

        <?php if (!empty($h_pic)): ?>
            <img
                src="<?php echo htmlspecialchars($h_pic); ?>"
                alt="<?php echo htmlspecialchars($h_full); ?>"
                class="profile-image"
            >
        <?php else: ?>
            <div class="profile-placeholder" aria-hidden="true">
                <?php echo htmlspecialchars($h_initials); ?>
            </div>
        <?php endif; ?>

    </div>

</header>

<style>
    /* =========================================================
       TOPBAR SHELL
    ========================================================= */
    .topbar {
        position: fixed;
        top: 0;
        right: 0;
        left: var(--sidebar-w, 255px);
        z-index: 900;

        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;

        height: var(--topbar-h, 68px);
        padding: 0 24px;

        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);

        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        transition: left .25s ease;
    }

    /* Desktop collapsed state */
    @media (min-width: 801px) {
        body.sidebar-collapsed .topbar {
            left: var(--sidebar-collapsed, 78px);
        }
    }

    /* =========================================================
       LEFT SIDE
    ========================================================= */
    .topbar-left {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
        flex: 1;
    }

    /* ---------- Toggle button ---------- */
    .sidebar-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        width: 42px;
        height: 42px;
        padding: 0;
        flex-shrink: 0;

        color: #374151;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        cursor: pointer;

        transition: background .15s ease, color .15s ease, transform .15s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .sidebar-toggle:hover {
        background: #e5e7eb;
        color: #111827;
    }

    .sidebar-toggle:active {
        transform: scale(0.94);
    }

    .sidebar-toggle:focus-visible {
        outline: 2px solid #6366f1;
        outline-offset: 2px;
    }

    /* Animated hamburger bars */
    .toggle-bars {
        display: inline-flex;
        flex-direction: column;
        justify-content: space-between;

        width: 18px;
        height: 14px;
    }

    .toggle-bars span {
        display: block;
        width: 100%;
        height: 2px;
        border-radius: 2px;
        background: currentColor;
        transition: transform .2s ease, opacity .2s ease;
    }

    /* Optional: turn into "X" when sidebar is open on mobile */
    body.sidebar-mobile-open .toggle-bars span:nth-child(1) {
        transform: translateY(6px) rotate(45deg);
    }
    body.sidebar-mobile-open .toggle-bars span:nth-child(2) {
        opacity: 0;
    }
    body.sidebar-mobile-open .toggle-bars span:nth-child(3) {
        transform: translateY(-6px) rotate(-45deg);
    }

    /* ---------- Page title ---------- */
    .page-title-wrap {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .page-title {
        font-size: 16px;
        font-weight: 700;
        color: #111827;
        line-height: 1.2;

        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .page-subtitle {
        font-size: 12px;
        font-weight: 500;
        color: #6b7280;
        line-height: 1.3;
        letter-spacing: 0.02em;

        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* =========================================================
       RIGHT SIDE — USER
    ========================================================= */
    .topbar-user {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
        padding-left: 16px;
        border-left: 1px solid #f0f1f3;
    }

    .user-info {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        text-align: right;
        line-height: 1.25;
        min-width: 0;
    }

    .user-info strong {
        font-size: 14px;
        font-weight: 600;
        color: #111827;

        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .user-info span {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6b7280;
    }

    /* =========================================================
       AVATAR
    ========================================================= */
    .profile-image,
    .profile-placeholder {
        width: 42px;
        height: 42px;
        flex-shrink: 0;
        border-radius: 50%;
        object-fit: cover;

        border: 2px solid #ffffff;
        box-shadow: 0 0 0 1px #e5e7eb, 0 2px 6px rgba(16, 24, 40, 0.08);
    }

    .profile-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #ffffff;

        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.02em;
        user-select: none;
    }

    /* =========================================================
       RESPONSIVE — TABLET
    ========================================================= */
    @media (max-width: 900px) {

        .topbar {
            padding: 0 20px;
            gap: 12px;
        }

        .user-info strong {
            max-width: 140px;
            font-size: 13px;
        }

        .user-info span {
            font-size: 10px;
        }
    }

    /* =========================================================
       RESPONSIVE — MOBILE (drawer mode)
    ========================================================= */
    @media (max-width: 800px) {

        .topbar {
            left: 0;                /* full width when sidebar is a drawer */
            height: 64px;
            padding: 0 14px;
            gap: 10px;
        }

        .topbar-left { gap: 10px; }

        .page-title    { font-size: 15px; }
        .page-subtitle { font-size: 11px; }

        /* Slightly bigger tap target on touch */
        .sidebar-toggle {
            width: 44px;
            height: 44px;
        }

        /* Safe-area (notch + home bar) */
        @supports (padding: max(0px)) {
            .topbar {
                padding-left:  max(14px, env(safe-area-inset-left));
                padding-right: max(14px, env(safe-area-inset-right));
            }
        }
    }

    /* =========================================================
       RESPONSIVE — SMALL PHONES
    ========================================================= */
    @media (max-width: 550px) {

        .topbar {
            height: 60px;
            padding: 0 12px;
        }

        /* Hide the name/role text — keep the avatar */
        .user-info { display: none; }

        .topbar-user {
            padding-left: 0;
            border-left: none;
        }

        .profile-image,
        .profile-placeholder {
            width: 36px;
            height: 36px;
            font-size: 12px;
        }

        .page-title    { font-size: 14px; }
        .page-subtitle { font-size: 10px; }

        .sidebar-toggle {
            width: 42px;
            height: 42px;
            border-radius: 9px;
        }
    }

    /* =========================================================
       RESPONSIVE — VERY SMALL (iPhone SE, etc.)
    ========================================================= */
    @media (max-width: 380px) {

        .topbar {
            height: 56px;
            padding: 0 10px;
            gap: 8px;
        }

        .page-title    { font-size: 13px; }
        .page-subtitle { display: none; }   /* keep it clean */

        .profile-image,
        .profile-placeholder {
            width: 32px;
            height: 32px;
            font-size: 11px;
            border-width: 1.5px;
        }

        .sidebar-toggle {
            width: 40px;
            height: 40px;
        }

        .toggle-bars {
            width: 16px;
            height: 12px;
        }
    }

    /* =========================================================
       LANDSCAPE PHONES — reclaim vertical space
    ========================================================= */
    @media (max-height: 500px) and (max-width: 900px) {

        .topbar {
            height: 54px;
        }

        .page-subtitle { display: none; }

        .profile-image,
        .profile-placeholder {
            width: 32px;
            height: 32px;
        }
    }
</style>