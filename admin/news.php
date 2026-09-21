<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';


/* =========================================================================
   HELPERS
   ========================================================================= */

function redirect_with_flash(string $type, string $message): void
{
    $_SESSION['news_flash'] = ['type' => $type, 'message' => $message];
    header('Location: news.php');
    exit;
}

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
   AUTO-CREATE news TABLE IF MISSING
   ========================================================================= */

if (!tableExists($conn, 'news')) {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS news (
            id         INT(11) NOT NULL AUTO_INCREMENT,
            title      VARCHAR(255) NOT NULL,
            category   VARCHAR(50) DEFAULT 'general',
            news_date  DATE NOT NULL,
            excerpt    TEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_news_date (news_date),
            KEY idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


/* =========================================================================
   CATEGORIES
   ========================================================================= */

$categories = [
    'general'    => 'General',
    'academic'   => 'Academic',
    'sports'     => 'Sports',
    'events'     => 'Events',
    'announcement' => 'Announcement',
    'achievement' => 'Achievement',
    'notice'     => 'Notice',
    'other'      => 'Other',
];


/* =========================================================================
   HANDLE POST
   ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CREATE
    ------------------------------------------------------------- */
    if ($action === 'create') {

        $title     = trim($_POST['title']     ?? '');
        $category  = $_POST['category']       ?? 'general';
        $news_date = trim($_POST['news_date'] ?? '');
        $excerpt   = trim($_POST['excerpt']   ?? '');

        $errors = [];

        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors[] = 'Title cannot exceed 255 characters.';
        }

        if ($news_date === '') {
            $errors[] = 'News date is required.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $news_date)) {
            $errors[] = 'News date is not valid.';
        }

        if (!isset($categories[$category])) {
            $category = 'general';
        }

        if (!empty($errors)) {
            $_SESSION['news_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: news.php');
            exit;
        }

        $excerpt_param = $excerpt !== '' ? $excerpt : null;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO news (title, category, news_date, excerpt) VALUES (?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'ssss', $title, $category, $news_date, $excerpt_param);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'News created successfully.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not create: ' . $err);
    }


    /* -------------------------------------------------------------
       UPDATE
    ------------------------------------------------------------- */
    if ($action === 'update') {

        $id        = (int) ($_POST['id'] ?? 0);
        $title     = trim($_POST['title']     ?? '');
        $category  = $_POST['category']       ?? 'general';
        $news_date = trim($_POST['news_date'] ?? '');
        $excerpt   = trim($_POST['excerpt']   ?? '');

        $errors = [];

        if ($id <= 0) $errors[] = 'Invalid entry.';

        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors[] = 'Title cannot exceed 255 characters.';
        }

        if ($news_date === '') {
            $errors[] = 'News date is required.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $news_date)) {
            $errors[] = 'News date is not valid.';
        }

        if (!isset($categories[$category])) {
            $category = 'general';
        }

        if (!empty($errors)) {
            $_SESSION['news_flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
            header('Location: news.php');
            exit;
        }

        $excerpt_param = $excerpt !== '' ? $excerpt : null;

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE news SET title = ?, category = ?, news_date = ?, excerpt = ? WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ssssi', $title, $category, $news_date, $excerpt_param, $id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'News updated.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not update: ' . $err);
    }


    /* -------------------------------------------------------------
       DELETE
    ------------------------------------------------------------- */
    if ($action === 'delete') {

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) redirect_with_flash('error', 'Invalid entry.');

        $stmt = mysqli_prepare($conn, "DELETE FROM news WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirect_with_flash('success', 'News deleted.');
        }

        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        redirect_with_flash('error', 'Could not delete: ' . $err);
    }
}


/* =========================================================================
   FLASH
   ========================================================================= */

$flash = $_SESSION['news_flash'] ?? null;
unset($_SESSION['news_flash']);


/* =========================================================================
   FILTERS
   ========================================================================= */

$search          = trim($_GET['q'] ?? '');
$category_filter = $_GET['category'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(title LIKE ? OR excerpt LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

if ($category_filter !== '' && isset($categories[$category_filter])) {
    $where[]  = "category = ?";
    $params[] = $category_filter;
    $types   .= 's';
}

$sql = "SELECT id, title, category, news_date, excerpt, created_at FROM news";

if ($where) $sql .= " WHERE " . implode(' AND ', $where);

$sql .= " ORDER BY news_date DESC, id DESC";

$news = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $news[] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   STATS
   ========================================================================= */

$stats = ['total' => 0, 'this_month' => 0];
$per_category = [];

$res = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(MONTH(news_date) = MONTH(CURDATE()) AND YEAR(news_date) = YEAR(CURDATE())) AS this_month
     FROM news"
);

if ($res && $row = mysqli_fetch_assoc($res)) {
    $stats['total']      = (int) $row['total'];
    $stats['this_month'] = (int) $row['this_month'];
}

$res = mysqli_query($conn, "SELECT category, COUNT(*) AS c FROM news GROUP BY category");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $per_category[$row['category']] = (int) $row['c'];
    }
}

$has_filters = ($search !== '' || ($category_filter !== '' && isset($categories[$category_filter])));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>News | PSRMS</title>

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
            --purple: #5a4a8f;
            --purple-bg: #f0eefa;
            --pink: #8f3a6a;
            --pink-bg: #fbeef5;
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

        /* LAYOUT */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content { margin-left: 78px; }
        }

        /* HEADER */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-title h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-title p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 42px;
            padding: 0 16px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover { background: var(--navy-dark); }
        .btn-primary:active { transform: scale(.98); }

        .btn-ghost {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { border-color: var(--gold); }

        /* ALERTS */
        .alert {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px 20px;
        }

        .stat-card .label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .stat-card .value {
            color: var(--navy);
            font-size: 24px;
            font-weight: 750;
            margin-top: 6px;
        }

        /* FILTER */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-toggle {
            display: none;
            width: 100%;
            background: none;
            border: none;
            padding: 0 0 14px;
            margin-bottom: 4px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            color: var(--navy);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            text-align: left;
            justify-content: space-between;
            align-items: center;
            -webkit-tap-highlight-color: transparent;
        }

        .filter-toggle .chev {
            color: var(--gold);
            font-size: 12px;
            transition: transform .25s ease;
            display: inline-block;
        }

        .filter-panel.collapsed .filter-toggle .chev {
            transform: rotate(-90deg);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
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

        /* RESULTS BAR */
        .results-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .results-bar h2 { color: var(--navy); font-size: 15px; }
        .results-count { color: var(--muted); font-size: 11.5px; }

        /* =========================================================
           NEWS GRID
        ========================================================= */
        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 18px;
        }

        .news-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            transition: .2s ease;
        }

        .news-card:hover {
            transform: translateY(-3px);
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 12px 28px rgba(16,24,43,.08);
        }

        .news-top {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .news-cat {
            display: inline-block;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: var(--blue-bg);
            color: var(--blue);
        }

        .news-cat.academic     { background: var(--green-bg);  color: var(--green); }
        .news-cat.sports       { background: var(--orange-bg); color: var(--orange); }
        .news-cat.events       { background: var(--purple-bg); color: var(--purple); }
        .news-cat.announcement { background: var(--blue-bg);   color: var(--blue); }
        .news-cat.achievement  { background: var(--pink-bg);   color: var(--pink); }
        .news-cat.notice       { background: var(--gold-light);color: var(--navy); }
        .news-cat.general      { background: #f0efec;          color: #7a7a72; }
        .news-cat.other        { background: #f0efec;          color: #7a7a72; }

        .news-date {
            color: var(--muted);
            font-size: 11.5px;
            font-weight: 600;
        }

        .news-title {
            color: var(--navy);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.35;
            margin-bottom: 10px;
            overflow-wrap: anywhere;
        }

        .news-excerpt {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            flex: 1;
            margin-bottom: 16px;
            overflow-wrap: anywhere;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .news-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding-top: 14px;
            border-top: 1px solid #f0f1f3;
        }

        .icon-btn {
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 6px;
            min-height: 34px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            color: var(--navy);
            transition: .15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .icon-btn:hover { border-color: var(--gold); color: var(--gold); }
        .icon-btn:active { transform: scale(.96); }

        .icon-btn.danger { color: var(--red); border-color: #efd2d2; }
        .icon-btn.danger:hover { background: var(--red-bg); border-color: var(--red); }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 60px 20px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--muted);
            font-size: 12.5px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
        }

        .empty h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        /* =========================================================
           MODAL
        ========================================================= */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16,24,43,.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .2s ease;
        }

        .modal-backdrop.open { display: flex; opacity: 1; }

        .modal {
            background: var(--white);
            border-radius: 14px;
            width: 100%;
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1;
        }

        .modal-header h2 { color: var(--navy); font-size: 15px; font-weight: 700; }

        .modal-close {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            border: none;
            border-radius: 8px;
            color: var(--muted);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .modal-close:hover { background: #e5e7eb; color: var(--navy); }

        .modal-body { padding: 22px; }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid var(--border);
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        /* FORM */
        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-group label .req { color: var(--red); margin-left: 2px; }

        .form-control {
            width: 100%;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 12px;
            font-family: inherit;
            font-size: 13.5px;
            background: #fcfcfd;
            color: var(--text);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 110px;
            padding: 12px;
            resize: vertical;
            line-height: 1.55;
        }

        .form-control:focus {
            border-color: var(--gold);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-grid .full { grid-column: 1 / -1; }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .page-header .btn {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
            .stat-card  { padding: 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card .label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-control,
            .btn { min-height: 46px; font-size: 14px; }

            .news-grid { grid-template-columns: 1fr; gap: 12px; }

            .news-card { padding: 18px; }

            .news-actions .icon-btn { min-height: 42px; font-size: 12px; }

            /* Modal — bottom sheet */
            .modal-backdrop { padding: 12px; align-items: flex-end; }
            .modal {
                max-width: 100%;
                border-radius: 16px 16px 0 0;
            }
            .modal-header { padding: 16px 18px; }
            .modal-body { padding: 18px; }
            .modal-footer {
                flex-direction: column-reverse;
                padding: 14px 18px;
            }
            .modal-footer .btn { width: 100%; }

            .form-grid { grid-template-columns: 1fr; gap: 12px; }
            .form-grid .full { grid-column: auto; }
        }

        @media (max-width: 550px) {
            .main-content { padding: calc(var(--topbar-h) + 14px) 14px 24px; }
            .page-title h1 { font-size: 19px; }
            .page-title p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }
            .stat-card  { padding: 12px; }
            .stat-card .value { font-size: 19px; }

            .news-title { font-size: 15px; }
            .news-excerpt { font-size: 12.5px; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card .value { font-size: 18px; }

            .news-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .modal-footer { padding-bottom: max(14px, env(safe-area-inset-bottom)); }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'News';
$topbar_subtitle = 'Manage school news & announcements';
include '../includes/topbar.php';
?>

<?php include 'admin_sidebar.php'; ?>

<main class="main-content with-topbar">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-title">
            <h1>News &amp; Announcements</h1>
            <p>Manage news posts that appear on the school website.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            + Add News
        </button>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Posts</div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">This Month</div>
            <div class="value"><?php echo number_format($stats['this_month']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Announcements</div>
            <div class="value"><?php echo number_format($per_category['announcement'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Academic</div>
            <div class="value"><?php echo number_format($per_category['academic'] ?? 0); ?></div>
        </div>
    </div>

    <!-- FILTERS -->
    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="news.php" class="filter-form">

            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="q" class="filter-control"
                       placeholder="Title or content…"
                       value="<?php echo e($search); ?>">
            </div>

            <div class="filter-group">
                <label>Category</label>
                <select name="category" class="filter-control">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?php echo e($key); ?>"
                            <?php echo $category_filter === $key ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>

            <?php if ($has_filters): ?>
                <a href="news.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>

        </form>
    </section>

    <!-- RESULTS -->
    <div class="results-bar">
        <h2>News Posts</h2>
        <span class="results-count">
            <?php echo number_format(count($news)); ?> post(s)
        </span>
    </div>

    <!-- NEWS GRID -->
    <?php if (empty($news)): ?>

        <div class="empty">
            <div class="empty-icon">📰</div>
            <h3>No news posts found</h3>
            <p>
                <?php if ($has_filters): ?>
                    Try clearing the filters.
                <?php else: ?>
                    Click <strong>Add News</strong> to publish your first post.
                <?php endif; ?>
            </p>
        </div>

    <?php else: ?>

        <div class="news-grid">
            <?php foreach ($news as $item):
                $cat_key   = isset($categories[$item['category']]) ? $item['category'] : 'general';
                $cat_label = $categories[$cat_key];
                $ts        = strtotime($item['news_date']);
                $date_fmt  = $ts ? date('M j, Y', $ts) : $item['news_date'];
            ?>
                <div class="news-card">

                    <div class="news-top">
                        <span class="news-cat <?php echo e($cat_key); ?>">
                            <?php echo e($cat_label); ?>
                        </span>
                        <span class="news-date"><?php echo e($date_fmt); ?></span>
                    </div>

                    <div class="news-title"><?php echo e($item['title']); ?></div>

                    <?php if (!empty($item['excerpt'])): ?>
                        <div class="news-excerpt"><?php echo e($item['excerpt']); ?></div>
                    <?php endif; ?>

                    <div class="news-actions">
                        <button type="button" class="icon-btn"
                            onclick='openEditModal(<?php echo json_encode([
                                "id"        => (int)$item["id"],
                                "title"     => $item["title"],
                                "category"  => $item["category"],
                                "news_date" => $item["news_date"],
                                "excerpt"   => $item["excerpt"],
                            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            Edit
                        </button>

                        <form method="POST" style="display:contents;"
                              onsubmit="return confirm('Delete this news post permanently?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                            <button type="submit" class="icon-btn danger">Delete</button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</main>


<!-- =========================================================
     CREATE MODAL
========================================================= -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST" action="news.php" autocomplete="off">

            <div class="modal-header">
                <h2>Add News</h2>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="form-group">
                    <label>Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-control"
                           maxlength="255" placeholder="e.g. School Closed for Mid-Term Break" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" class="form-control">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo e($key); ?>">
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>News Date <span class="req">*</span></label>
                        <input type="date" name="news_date" class="form-control"
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Excerpt / Short Description</label>
                    <textarea name="excerpt" class="form-control"
                              placeholder="Short summary of the news…"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Publish News</button>
            </div>

            <input type="hidden" name="action" value="create">
        </form>
    </div>
</div>


<!-- =========================================================
     EDIT MODAL
========================================================= -->
<div class="modal-backdrop" id="editModal">
    <div class="modal">
        <form method="POST" action="news.php" autocomplete="off">

            <div class="modal-header">
                <h2>Edit News</h2>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>

            <div class="modal-body">

                <div class="form-group">
                    <label>Title <span class="req">*</span></label>
                    <input type="text" name="title" id="edit_title"
                           class="form-control" maxlength="255" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="edit_category" class="form-control">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo e($key); ?>">
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>News Date <span class="req">*</span></label>
                        <input type="date" name="news_date" id="edit_news_date"
                               class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Excerpt / Short Description</label>
                    <textarea name="excerpt" id="edit_excerpt"
                              class="form-control"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>

            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
        </form>
    </div>
</div>


<script>
/* =========================================================
   MODAL HELPERS
========================================================= */
function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function openEditModal(data) {
    document.getElementById('edit_id').value         = data.id        || '';
    document.getElementById('edit_title').value      = data.title     || '';
    document.getElementById('edit_category').value   = data.category  || 'general';
    document.getElementById('edit_news_date').value  = data.news_date || '';
    document.getElementById('edit_excerpt').value    = data.excerpt   || '';

    document.getElementById('editModal').classList.add('open');
    document.body.classList.add('no-scroll');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('no-scroll');
    }
}

document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) closeModal(bd.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open')
            .forEach(bd => closeModal(bd.id));
    }
});


/* =========================================================
   FILTER PANEL COLLAPSE
========================================================= */
(function () {
    const filterPanel  = document.getElementById('filterPanel');
    const filterToggle = document.getElementById('filterToggle');
    if (!filterPanel || !filterToggle) return;

    const mq              = window.matchMedia('(max-width: 800px)');
    const hasActiveFilter = <?php echo $has_filters ? 'true' : 'false'; ?>;

    function syncFilterState() {
        if (mq.matches) {
            filterPanel.classList.toggle('collapsed', !hasActiveFilter);
        } else {
            filterPanel.classList.remove('collapsed');
        }
    }

    syncFilterState();
    mq.addEventListener
        ? mq.addEventListener('change', syncFilterState)
        : mq.addListener(syncFilterState);

    filterToggle.addEventListener('click', () => {
        if (!mq.matches) return;
        filterPanel.classList.toggle('collapsed');
    });
})();
</script>

</body>
</html>