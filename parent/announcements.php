<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('parent');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $t): bool
{
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}


/* =========================================================================
   LOAD PARENT
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id,
        u.first_name,
        u.middle_name,
        u.last_name,
        p.parent_id
     FROM users u
     INNER JOIN parents p ON p.user_id = u.user_id
     WHERE u.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$parent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$parent) {
    die('Parent profile not found.');
}

$parent_id   = (int) $parent['parent_id'];
$parent_name = trim(
    $parent['first_name'] . ' ' .
    ($parent['middle_name'] ? $parent['middle_name'] . ' ' : '') .
    $parent['last_name']
);


/* =========================================================================
   FILTERS
   ========================================================================= */

$search          = trim($_GET['q'] ?? '');
$category_filter = trim($_GET['category'] ?? '');


/* =========================================================================
   LOAD ANNOUNCEMENTS
   ---------------------------------------------------------------------------
   Expects a table `announcements` with at least:
     id, title, content, category, audience, is_pinned, published_at, created_at

   Audience column (optional) may hold: 'all' | 'parents' | 'teachers' | 'students'
   ========================================================================= */

$announcements = [];
$has_table     = tableExists($conn, 'announcements');

if ($has_table) {

    $where  = [];
    $params = [];
    $types  = '';

    /* Only show items addressed to parents or to everyone */
    $where[] = "(audience IS NULL OR audience = '' OR audience IN ('all', 'parents'))";

    /* Only show published announcements */
    $where[] = "(published_at IS NULL OR published_at <= NOW())";

    if ($search !== '') {
        $where[]  = "(title LIKE ? OR content LIKE ?)";
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }

    if ($category_filter !== '') {
        $where[]  = "category = ?";
        $params[] = $category_filter;
        $types   .= 's';
    }

    $sql = "
        SELECT
            id,
            title,
            content,
            category,
            is_pinned,
            published_at,
            created_at
        FROM announcements
        WHERE " . implode(' AND ', $where) . "
        ORDER BY is_pinned DESC, COALESCE(published_at, created_at) DESC
        LIMIT 100
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($params) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $announcements[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}


/* =========================================================================
   LOAD DISTINCT CATEGORIES FOR THE FILTER
   ========================================================================= */

$categories = [];

if ($has_table) {
    $res = mysqli_query(
        $conn,
        "SELECT DISTINCT category
         FROM announcements
         WHERE category IS NOT NULL AND category <> ''
         ORDER BY category ASC"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $categories[] = $row['category'];
        }
    }
}


/* =========================================================================
   CATEGORY VISUAL MAP
   ========================================================================= */

function category_icon(string $cat): string
{
    $cat = strtolower($cat);
    return match (true) {
        str_contains($cat, 'event')          => '📅',
        str_contains($cat, 'holiday')        => '🌴',
        str_contains($cat, 'exam')           => '📝',
        str_contains($cat, 'result')         => '📊',
        str_contains($cat, 'fee')            => '💳',
        str_contains($cat, 'meeting')        => '👥',
        str_contains($cat, 'health')         => '🏥',
        str_contains($cat, 'sport')          => '⚽',
        str_contains($cat, 'emergency')      => '🚨',
        str_contains($cat, 'general')        => '📢',
        default                              => '📢',
    };
}

function category_class(string $cat): string
{
    $cat = strtolower($cat);
    return match (true) {
        str_contains($cat, 'event')     => 'blue',
        str_contains($cat, 'holiday')   => 'green',
        str_contains($cat, 'exam')      => 'orange',
        str_contains($cat, 'result')    => 'gold',
        str_contains($cat, 'fee')       => 'red',
        str_contains($cat, 'meeting')   => 'blue',
        str_contains($cat, 'emergency') => 'red',
        default                         => 'gold',
    };
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

    <title>Announcements | PSRMS</title>

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
            --red: #9b4747;
            --red-bg: #fbefef;
            --green: #3e7655;
            --green-bg: #eef6f0;
            --orange: #9a7422;
            --orange-bg: #faf5e8;
            --blue: #2f5d8f;
            --blue-bg: #eaf1fa;
            --sidebar-w: 250px;
            --topbar-h: 64px;
        }

        html, body { overflow-x: hidden; }

        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;
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
            font-size: 14px; font-weight: 800; letter-spacing: .5px;
        }
        .mobile-topbar .brand span { color: var(--gold-light); }

        .hamburger {
            width: 40px; height: 40px;
            border: none;
            background: rgba(255,255,255,.08);
            border-radius: 6px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 4px; cursor: pointer; padding: 0;
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
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1050; opacity: 0;
            transition: opacity .25s ease;
        }
        .sidebar-overlay.open { display: block; opacity: 1; }

        /* =========================================================
           PAGE HEADER
        ========================================================== */
        .page-header { margin-bottom: 22px; }

        .page-header h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
            line-height: 1.25;
        }

        .page-header p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        /* =========================================================
           FILTERS
        ========================================================== */
        .filters {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .filter-control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }

        select.filter-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        .filter-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .btn-filter {
            height: 42px;
            padding: 0 22px;
            background: var(--navy);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-filter:hover { background: var(--navy-dark); }

        .clear-link {
            display: inline-flex;
            align-items: center;
            height: 42px;
            padding: 0 14px;
            margin-left: 8px;
            color: var(--muted);
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--white);
        }
        .clear-link:hover { color: var(--navy); border-color: var(--gold); }

        /* =========================================================
           ANNOUNCEMENT CARD
        ========================================================== */
        .ann-list {
            display: grid;
            gap: 14px;
        }

        .ann-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            transition: .2s ease;
        }

        .ann-card:hover {
            border-color: rgba(201,162,39,.45);
            box-shadow: 0 10px 25px rgba(23,35,60,.05);
        }

        .ann-card.pinned {
            border-left: 4px solid var(--gold);
            background: #fffdf5;
        }

        .ann-top {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid #f0f1f3;
        }

        .ann-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .ann-icon.gold   { background: var(--gold-light); color: var(--navy); }
        .ann-icon.blue   { background: var(--blue-bg);    color: var(--blue); }
        .ann-icon.green  { background: var(--green-bg);   color: var(--green); }
        .ann-icon.orange { background: var(--orange-bg);  color: var(--orange); }
        .ann-icon.red    { background: var(--red-bg);     color: var(--red); }

        .ann-head { flex: 1; min-width: 0; }

        .ann-title {
            font-size: 15px;
            font-weight: 750;
            color: var(--navy);
            margin-bottom: 6px;
            overflow-wrap: anywhere;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pin-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 20px;
            background: var(--gold);
            color: var(--navy);
        }

        .ann-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 11px;
            color: var(--muted);
        }

        .ann-category {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 750;
            letter-spacing: .4px;
            text-transform: uppercase;
            background: #f0f3f8;
            color: var(--navy);
        }

        .ann-body {
            padding: 16px 20px 18px;
            font-size: 13.5px;
            line-height: 1.65;
            color: var(--text);
            overflow-wrap: anywhere;
        }

        .ann-body p { margin-bottom: 10px; }
        .ann-body p:last-child { margin-bottom: 0; }

        .ann-footer {
            padding: 10px 20px 14px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            border-top: 1px solid #f0f1f3;
            background: #fbfbf8;
        }

        .btn-view {
            padding: 8px 16px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid var(--border);
            color: var(--navy);
            background: var(--white);
            text-decoration: none;
            transition: .15s ease;
        }
        .btn-view:hover { border-color: var(--gold); color: var(--gold); }

        /* =========================================================
           EMPTY
        ========================================================== */
        .empty {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty-icon {
            width: 60px; height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; font-weight: 800;
        }

        .empty h3 {
            color: var(--navy);
            font-size: 15px;
            margin-bottom: 6px;
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

            .page-header h1 { font-size: 21px; }
            .page-header p  { font-size: 12px; }

            .filters {
                grid-template-columns: 1fr;
                padding: 14px;
                gap: 10px;
            }

            .filter-control,
            .btn-filter {
                min-height: 46px;
                font-size: 15px;
            }

            .filters .btn-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .clear-link {
                height: auto;
                justify-content: center;
                margin-left: 0;
                padding: 12px;
            }

            .ann-top { padding: 14px 16px; gap: 12px; }
            .ann-icon { width: 42px; height: 42px; font-size: 18px; }
            .ann-title { font-size: 14px; }
            .ann-body  { padding: 14px 16px 16px; font-size: 13px; }
            .ann-footer { padding: 10px 16px 14px; }
            .btn-view { flex: 1; text-align: center; }
        }

        @media (max-width: 400px) {
            .page-header h1 { font-size: 19px; }
            .ann-icon { width: 38px; height: 38px; font-size: 17px; }
            .ann-title { font-size: 13.5px; }
            .ann-body  { font-size: 12.5px; }
        }

        @media (max-width: 900px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(40px, env(safe-area-inset-bottom));
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
$topbar_title    = 'Announcements';
$topbar_subtitle = 'School updates';
include '../includes/topbar.php';
?>

<?php include 'parent_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-header">
        <h1>Announcements</h1>
        <p>Latest news and updates from the school.</p>
    </div>


    <?php if (!$has_table): ?>

        <div class="empty">
            <div class="empty-icon">📢</div>
            <h3>Announcements not available</h3>
            <p>The school has not published any announcements yet.</p>
        </div>

    <?php else: ?>

        <!-- FILTERS -->
        <form method="GET" action="" class="filters">

            <div class="filter-group">
                <label for="q">Search</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    class="filter-control"
                    placeholder="Search by title or content…"
                    value="<?php echo e($search); ?>"
                >
            </div>

            <div class="filter-group">
                <label for="category">Category</label>
                <select name="category" id="category" class="filter-control">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option
                            value="<?php echo e($cat); ?>"
                            <?php echo $category_filter === $cat ? 'selected' : ''; ?>
                        >
                            <?php echo e(ucfirst($cat)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="btn-row" style="display:flex;gap:8px;align-items:end;">
                <button type="submit" class="btn-filter">Apply</button>

                <?php if ($search !== '' || $category_filter !== ''): ?>
                    <a href="announcements.php" class="clear-link">Clear</a>
                <?php endif; ?>
            </div>

        </form>


        <!-- ANNOUNCEMENTS -->
        <?php if (empty($announcements)): ?>

            <div class="empty">
                <div class="empty-icon">📭</div>
                <h3>No announcements found</h3>
                <p>
                    <?php if ($search !== '' || $category_filter !== ''): ?>
                        Try adjusting your filters or clearing them.
                    <?php else: ?>
                        Check back later for school updates.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <div class="ann-list">
                <?php foreach ($announcements as $a):
                    $cat       = $a['category'] ?? 'general';
                    $icon      = category_icon($cat);
                    $cls       = category_class($cat);
                    $is_pinned = !empty($a['is_pinned']);
                    $ts        = strtotime($a['published_at'] ?? $a['created_at'] ?? 'now');

                    /* Truncate body for preview */
                    $body = trim((string)$a['content']);
                    $preview = mb_strlen($body) > 320
                        ? mb_substr($body, 0, 320) . '…'
                        : $body;
                ?>
                    <article class="ann-card <?php echo $is_pinned ? 'pinned' : ''; ?>">

                        <div class="ann-top">

                            <div class="ann-icon <?php echo e($cls); ?>">
                                <?php echo $icon; ?>
                            </div>

                            <div class="ann-head">

                                <h3 class="ann-title">
                                    <?php echo e($a['title']); ?>

                                    <?php if ($is_pinned): ?>
                                        <span class="pin-badge">★ Pinned</span>
                                    <?php endif; ?>
                                </h3>

                                <div class="ann-meta">

                                    <?php if (!empty($cat)): ?>
                                        <span class="ann-category">
                                            <?php echo $icon; ?>
                                            <?php echo e(ucfirst($cat)); ?>
                                        </span>
                                    <?php endif; ?>

                                    <span>
                                        <?php echo e(date('M j, Y', $ts)); ?>
                                    </span>

                                    <?php
                                        $diff = time() - $ts;
                                        if ($diff < 86400 && $diff >= 0):
                                    ?>
                                        <span style="color:var(--gold);font-weight:700;">
                                            New
                                        </span>
                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                        <div class="ann-body">
                            <?php echo nl2br(e($preview)); ?>
                        </div>

                        <?php if (mb_strlen($body) > 320): ?>
                            <div class="ann-footer">
                                <a href="announcement.php?id=<?php echo (int)$a['id']; ?>" class="btn-view">
                                    Read More →
                                </a>
                            </div>
                        <?php endif; ?>

                    </article>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    <?php endif; ?>

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
    const s = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
    if (s) s.classList.add('open');
    if (hamburgerBtn) hamburgerBtn.classList.add('active');
}
function closeSidebar() {
    sidebarOverlay.classList.remove('open');
    const s = document.querySelector('.parent-sidebar, .admin-sidebar, .teacher-sidebar, #sidebar, .sidebar');
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