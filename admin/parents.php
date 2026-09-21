<?php

require_once '../auth/auth_check.php';
requireRole('admin');

require_once '../includes/db.php';

/*
|--------------------------------------------------------------------------
| Search & Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

/*
|--------------------------------------------------------------------------
| Get all students (for the link dropdown)
|--------------------------------------------------------------------------
*/

$students = [];
$res = mysqli_query(
    $conn,
    "SELECT student_id, full_name, registration_no, class_id
     FROM students
     WHERE status = 'active'
     ORDER BY full_name ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $students[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Build Parent Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.parent_id,
        p.user_id,
        p.occupation,
        p.address,
        p.created_at,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.email,
        u.phone,
        u.status,
        u.profile_pic
    FROM parents p
    INNER JOIN users u ON u.user_id = p.user_id
    WHERE 1=1
";

$params = [];
$types  = '';

if ($search !== '') {
    $sql .= "
        AND (
            u.first_name    LIKE ?
            OR u.middle_name LIKE ?
            OR u.last_name   LIKE ?
            OR u.email       LIKE ?
            OR u.phone       LIKE ?
            OR p.occupation  LIKE ?
        )
    ";
    $val      = '%' . $search . '%';
    $params[] = $val; $params[] = $val; $params[] = $val;
    $params[] = $val; $params[] = $val; $params[] = $val;
    $types   .= 'ssssss';
}

if ($status !== '') {
    $sql .= " AND u.status = ? ";
    $params[] = $status;
    $types   .= 's';
}

$sql .= " ORDER BY p.created_at DESC, u.first_name ASC";

$stmt    = mysqli_prepare($conn, $sql);
$parents = [];

if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $parents[] = $row;
    }
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| Attach linked students to each parent
|--------------------------------------------------------------------------
*/

$parent_ids = array_column($parents, 'parent_id');
$links_by_parent = [];

if (!empty($parent_ids)) {
    $placeholders = implode(',', array_fill(0, count($parent_ids), '?'));
    $types_in     = str_repeat('i', count($parent_ids));

    $linkSql = "
        SELECT
            sp.id,
            sp.parent_id,
            sp.student_id,
            sp.relationship,
            sp.is_primary_guardian,
            s.full_name AS student_name,
            s.registration_no
        FROM student_parents sp
        INNER JOIN students s ON s.student_id = sp.student_id
        WHERE sp.parent_id IN ($placeholders)
        ORDER BY sp.is_primary_guardian DESC, s.full_name ASC
    ";

    $stmt = mysqli_prepare($conn, $linkSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types_in, ...$parent_ids);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($r)) {
            $links_by_parent[(int) $row['parent_id']][] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

foreach ($parents as &$p) {
    $p['links'] = $links_by_parent[(int) $p['parent_id']] ?? [];
}
unset($p);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_parents    = 0;
$active_parents   = 0;
$inactive_parents = 0;
$this_month       = 0;

$result = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(u.status = 'active')   AS active_total,
        SUM(u.status = 'inactive') AS inactive_total,
        SUM(MONTH(p.created_at) = MONTH(CURRENT_DATE())
            AND YEAR(p.created_at) = YEAR(CURRENT_DATE())) AS this_month
     FROM parents p
     INNER JOIN users u ON u.user_id = p.user_id"
);

if ($result) {
    $stats            = mysqli_fetch_assoc($result);
    $total_parents    = (int) ($stats['total'] ?? 0);
    $active_parents   = (int) ($stats['active_total'] ?? 0);
    $inactive_parents = (int) ($stats['inactive_total'] ?? 0);
    $this_month       = (int) ($stats['this_month'] ?? 0);
}

$has_filters = ($search !== '' || $status !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10182b">
    <title>Parents | PSRMS</title>

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
            --green: #3e7655;
            --red: #9b4747;
            --orange: #9a7422;
            --sidebar-w: 255px;
            --sidebar-collapsed: 78px;
            --topbar-h: 78px;
        }

        html, body { -webkit-text-size-adjust: 100%; }

        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            background: var(--cream);
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
        }

        /* =================================================
           MAIN CONTENT
        ================================================= */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: calc(var(--topbar-h) + 30px) 30px 40px;
            transition: margin-left .25s ease;
            overflow-x: hidden;
        }

        @media (min-width: 801px) {
            body.sidebar-collapsed .main-content {
                margin-left: var(--sidebar-collapsed);
            }
        }

        /* =================================================
           PAGE HEADER
        ================================================= */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: var(--navy);
            font-size: 25px;
            font-weight: 700;
        }

        .page-heading p {
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 5px;
        }

        .add-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 17px;
            background: var(--navy);
            color: var(--white);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 650;
            transition: .2s ease;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .add-button:hover {
            background: var(--navy-dark);
            transform: translateY(-1px);
        }

        .add-button:active { transform: scale(.98); }

        .add-icon {
            color: var(--gold-light);
            font-size: 16px;
            line-height: 1;
        }

        /* =================================================
           STATISTICS
        ================================================= */
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
            padding: 19px;
            transition: .25s ease;
            min-width: 0;
        }

        .stat-card:hover {
            border-color: rgba(201,162,39,.4);
            box-shadow: 0 8px 20px rgba(23,35,60,.05);
        }

        .stat-label {
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .stat-value {
            color: var(--navy);
            font-size: 26px;
            font-weight: 700;
            margin-top: 7px;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }

        .stat-line {
            width: 23px;
            height: 2px;
            background: var(--gold);
            margin-top: 12px;
        }

        /* =================================================
           FILTER PANEL
        ================================================= */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
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

        .filter-group { min-width: 0; }

        .filter-group label {
            display: block;
            color: var(--navy);
            font-size: 10.5px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .filter-control {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: .2s ease;
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
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
            background-color: var(--white);
        }

        .filter-button {
            height: 42px;
            padding: 0 22px;
            border: none;
            border-radius: 8px;
            background: var(--navy);
            color: var(--white);
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 650;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .filter-button:hover { background: var(--navy-dark); }
        .filter-button:active { transform: scale(.98); }

        .reset-button {
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: .2s ease;
        }

        .reset-button:hover {
            color: var(--navy);
            border-color: #c8ccd3;
        }

        /* =================================================
           RESULTS BAR
        ================================================= */
        .results-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .results-bar h2 {
            color: var(--navy);
            font-size: 15px;
        }

        .results-count {
            color: var(--muted);
            font-size: 11.5px;
        }

        /* =================================================
           TABLE
        ================================================= */
        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        thead { background: #fafaf8; }

        th {
            padding: 13px 15px;
            text-align: left;
            color: #737c8c;
            font-size: 9.5px;
            font-weight: 750;
            letter-spacing: .7px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        td {
            padding: 13px 15px;
            border-bottom: 1px solid #f0f1f3;
            color: var(--text);
            font-size: 12.5px;
            vertical-align: middle;
        }

        tbody tr:hover { background: #fdfcf8; }
        tbody tr:last-child td { border-bottom: none; }

        /* =================================================
           PARENT CELL
        ================================================= */
        .parent-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .parent-photo,
        .parent-placeholder {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
        }

        .parent-photo { border: 1px solid #e5e1d2; }

        .parent-placeholder {
            background: var(--navy);
            color: var(--gold-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        .parent-name {
            font-weight: 650;
            color: var(--navy);
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .parent-id {
            color: var(--muted);
            font-size: 10px;
            margin-top: 2px;
        }

        .email { color: var(--navy); font-weight: 600; }
        .phone { color: var(--muted); }
        .occupation { color: var(--text); font-weight: 600; }

        /* =================================================
           LINKED STUDENTS CHIPS
        ================================================= */
        .student-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            max-width: 260px;
        }

        .student-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 600;
            background: #eef0f5;
            color: var(--navy);
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .student-chip.primary {
            background: #faf5e8;
            color: var(--orange);
        }

        .student-chip .star {
            color: var(--gold);
            font-size: 10px;
        }

        .no-links {
            color: var(--muted);
            font-size: 11px;
            font-style: italic;
        }

        /* =================================================
           STATUS
        ================================================= */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status::before {
            content: "";
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        .status-active     { color: var(--green);  background: #eef6f0; }
        .status-active::before { background: var(--green); }

        .status-inactive   { color: var(--orange); background: #faf5e8; }
        .status-inactive::before { background: var(--orange); }

        .status-suspended  { color: var(--red);    background: #faf0f0; }
        .status-suspended::before { background: var(--red); }

        /* =================================================
           ACTIONS
        ================================================= */
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        .action-btn {
            min-height: 32px;
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--white);
            color: var(--navy);
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .action-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .action-btn:active { transform: scale(.95); }

        .action-btn.danger {
            color: var(--red);
            border-color: #e8d5d5;
        }

        .action-btn.danger:hover {
            background: #faf0f0;
            border-color: var(--red);
            color: var(--red);
        }

        /* =================================================
           MOBILE CARD LIST
        ================================================= */
        .card-list { display: none; }

        .parent-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .parent-card:last-child { margin-bottom: 0; }

        .parent-card-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f0f1f3;
        }

        .parent-card-top .parent-name { font-size: 14px; }
        .parent-card-top .parent-id   { font-size: 10.5px; }

        .parent-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
            margin-bottom: 14px;
        }

        .meta-item { min-width: 0; }

        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .meta-item .v {
            color: var(--text);
            font-size: 12.5px;
            font-weight: 600;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .meta-item.full { grid-column: 1 / -1; }

        .parent-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px solid #f0f1f3;
        }

        .parent-card-actions .action-btn {
            min-height: 40px;
            font-size: 12px;
            justify-content: center;
        }

        /* =================================================
           EMPTY STATE
        ================================================= */
        .empty-state {
            padding: 55px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f2ed;
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
        }

        .empty-state h3 {
            color: var(--navy);
            font-size: 14.5px;
            margin-bottom: 5px;
        }

        .empty-state p {
            color: var(--muted);
            font-size: 12px;
        }

        /* =================================================
           MODAL
        ================================================= */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(16, 24, 43, .55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);

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
            box-shadow: 0 20px 60px rgba(16, 24, 43, .35);

            width: 100%;
            max-width: 720px;
            max-height: 92vh;
            overflow-y: auto;

            transform: scale(.96);
            transition: transform .2s ease;
        }

        .modal-backdrop.open .modal { transform: scale(1); }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1;
            border-radius: 14px 14px 0 0;
        }

        .modal-header h3 {
            color: var(--navy);
            font-size: 16px;
            font-weight: 700;
        }

        .modal-close {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
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
            -webkit-tap-highlight-color: transparent;
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
            border-radius: 0 0 14px 14px;
        }

        /* =================================================
           FORM
        ================================================= */
        .form-section {
            margin-bottom: 22px;
            padding-bottom: 22px;
            border-bottom: 1px dashed var(--border);
        }

        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--navy);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 14px;
        }

        .form-section-title::before {
            content: "";
            width: 3px;
            height: 14px;
            background: var(--gold);
            border-radius: 2px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-group { min-width: 0; }
        .form-group.full { grid-column: 1 / -1; }

        .form-group label {
            display: block;
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-group label .req {
            color: var(--red);
            margin-left: 2px;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fcfcfd;
            color: var(--text);
            font-family: inherit;
            font-size: 13.5px;
            outline: none;
            transition: .2s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        textarea.form-control {
            height: auto;
            min-height: 90px;
            padding: 10px 12px;
            resize: vertical;
            line-height: 1.5;
        }

        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23747d8e' stroke-width='2'%3e%3cpolyline points='6 9 12 15 18 9'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            padding-right: 34px;
        }

        .form-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
            background-color: var(--white);
        }

        .form-control.error {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(155,71,71,.1);
        }

        .field-error {
            display: none;
            color: var(--red);
            font-size: 11px;
            margin-top: 5px;
        }

        .field-error.show { display: block; }

        /* =================================================
           STUDENT LINKS (dynamic rows)
        ================================================= */
        .link-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 12px;
        }

        .link-row {
            display: grid;
            grid-template-columns: 1.6fr 1fr auto auto;
            gap: 8px;
            align-items: end;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfcfd;
        }

        .link-row .form-group label { font-size: 10.5px; }
        .link-row .form-control { height: 40px; font-size: 13px; }

        .primary-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 40px;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            cursor: pointer;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--muted);
            user-select: none;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }

        .primary-toggle input { display: none; }

        .primary-toggle .dot {
            width: 14px;
            height: 14px;
            border: 2px solid #cfd4dc;
            border-radius: 50%;
            transition: .15s ease;
        }

        .primary-toggle.on {
            color: var(--orange);
            border-color: var(--gold);
            background: #faf5e8;
        }

        .primary-toggle.on .dot {
            border-color: var(--gold);
            background: radial-gradient(circle, var(--gold) 40%, transparent 45%);
        }

        .link-remove {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            color: var(--red);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            -webkit-tap-highlight-color: transparent;
        }

        .link-remove:hover {
            background: #faf0f0;
            border-color: var(--red);
        }

        .add-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 14px;
            background: var(--white);
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--navy);
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            font-weight: 650;
            -webkit-tap-highlight-color: transparent;
        }

        .add-link-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
            background: #fdfcf8;
        }

        .no-links-hint {
            color: var(--muted);
            font-size: 12px;
            padding: 10px 0;
            font-style: italic;
        }

        /* =================================================
           BUTTONS
        ================================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 20px;
            border: 1px solid transparent;
            border-radius: 9px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 650;
            transition: .2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover:not(:disabled) { background: var(--navy-dark); }

        .btn-ghost {
            background: var(--white);
            color: var(--muted);
            border-color: var(--border);
        }
        .btn-ghost:hover:not(:disabled) { color: var(--navy); border-color: #c8ccd3; }

        .btn-danger { background: var(--red); color: var(--white); }
        .btn-danger:hover:not(:disabled) { background: #863a3a; }

        .btn .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: var(--white);
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }

        .btn.loading .spinner { display: inline-block; }
        .btn.loading .btn-label { opacity: .7; }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* =================================================
           DELETE CONFIRM
        ================================================= */
        .confirm-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #faf0f0;
            color: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
        }

        .confirm-text { text-align: center; }

        .confirm-text h3 {
            color: var(--navy);
            font-size: 17px;
            margin-bottom: 6px;
        }

        .confirm-text p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .confirm-text strong { color: var(--navy); font-weight: 700; }

        /* =================================================
           TOASTS
        ================================================= */
        .toast-wrap {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-left: 3px solid var(--green);
            border-radius: 9px;
            box-shadow: 0 10px 30px rgba(16, 24, 43, .12);
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            min-width: 260px;
            max-width: 380px;
            pointer-events: auto;
            animation: slideIn .25s ease;
        }

        .toast.error { border-left-color: var(--red); }
        .toast.info  { border-left-color: var(--gold); }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }

        /* =================================================
           RESPONSIVE
        ================================================= */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 800px) {

            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }

            body.sidebar-collapsed .main-content { margin-left: 0; }
            body.sidebar-collapsed .topbar        { left: 0; }

            .page-header {
                align-items: stretch;
                flex-direction: column;
                gap: 14px;
                margin-bottom: 18px;
            }

            .page-heading h1 { font-size: 21px; }
            .page-heading p  { font-size: 12px; line-height: 1.45; }

            .add-button {
                justify-content: center;
                min-height: 46px;
                width: 100%;
                font-size: 13px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card { padding: 15px; }
            .stat-value { font-size: 22px; }
            .stat-label { font-size: 9px; }

            .filter-toggle { display: flex; }
            .filter-panel { padding: 14px; margin-bottom: 14px; }
            .filter-panel.collapsed .filter-form { display: none; }
            .filter-form { grid-template-columns: 1fr; gap: 12px; }

            .filter-group label { font-size: 11px; margin-bottom: 6px; }

            .filter-control {
                height: 46px;
                font-size: 14px;
                border-radius: 9px;
            }

            .filter-button,
            .reset-button {
                width: 100%;
                height: 46px;
                font-size: 13.5px;
                border-radius: 9px;
            }

            .results-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
                margin-bottom: 12px;
            }

            .results-bar h2 { font-size: 14px; }
            .results-count { font-size: 11px; }

            .table-panel .table-wrapper { display: none; }

            .card-list { display: block; padding: 12px; }

            .parent-card {
                padding: 14px;
                margin-bottom: 10px;
                border-radius: 12px;
            }

            .parent-card-top {
                gap: 11px;
                padding-bottom: 12px;
                margin-bottom: 12px;
            }

            .parent-card-top .parent-name { font-size: 13.5px; }
            .parent-card-top .parent-id   { font-size: 10px; }

            .parent-card-meta {
                grid-template-columns: 1fr 1fr;
                gap: 10px 12px;
                margin-bottom: 12px;
            }

            .meta-item .k { font-size: 9px; }
            .meta-item .v { font-size: 12.5px; }

            .parent-card-actions {
                gap: 8px;
                padding-top: 12px;
            }

            .parent-card-actions .action-btn {
                min-height: 42px;
                font-size: 12px;
            }

            .parent-card .parent-photo,
            .parent-card .parent-placeholder {
                width: 42px;
                height: 42px;
                font-size: 13px;
            }

            .modal-backdrop { padding: 12px; align-items: flex-end; }

            .modal {
                max-width: 100%;
                max-height: 92vh;
                border-radius: 16px 16px 0 0;
                transform: translateY(20px);
            }

            .modal-backdrop.open .modal { transform: translateY(0); }

            .modal-header { padding: 16px 18px; border-radius: 16px 16px 0 0; }
            .modal-body   { padding: 18px; }
            .modal-footer { padding: 14px 18px; border-radius: 0; }

            .form-grid { grid-template-columns: 1fr; gap: 12px; }

            .link-row {
                grid-template-columns: 1fr;
                padding: 12px;
                gap: 10px;
            }

            .link-row .link-remove {
                width: 100%;
                height: 42px;
            }

            .modal-footer .btn { flex: 1; }
        }

        @media (max-width: 550px) {

            :root { --topbar-h: 66px; }

            .main-content {
                padding: calc(var(--topbar-h) + 16px) 14px 24px;
            }

            .page-heading h1 { font-size: 19px; }
            .page-heading p  { font-size: 11.5px; }

            .stats-grid { gap: 8px; }

            .stat-card { padding: 13px; border-radius: 9px; }

            .stat-value { font-size: 20px; }
            .stat-label { font-size: 8.5px; letter-spacing: .7px; }
            .stat-line  { margin-top: 9px; }

            .filter-panel { padding: 12px; }

            .parent-card-meta { grid-template-columns: 1fr; gap: 8px; }

            .parent-card-actions {
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }

            .parent-card-actions .action-btn {
                min-height: 40px;
                font-size: 11.5px;
                padding: 0 8px;
            }

            .student-chips { max-width: 100%; }

            .toast-wrap { top: 10px; right: 10px; left: 10px; }
            .toast      { min-width: auto; }
        }

        @media (max-width: 400px) {

            .stats-grid { grid-template-columns: 1fr; gap: 8px; }

            .stat-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 14px;
            }

            .stat-label { order: 1; margin: 0; }
            .stat-value { order: 2; margin: 0; font-size: 20px; }
            .stat-line  { display: none; }

            .page-heading h1 { font-size: 18px; }

            .parent-card { padding: 12px; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(24px, env(safe-area-inset-bottom));
                }
                .modal-footer {
                    padding-bottom: max(14px, env(safe-area-inset-bottom));
                }
            }
        }

        @media (max-height: 500px) and (max-width: 900px) {
            .main-content { padding-top: calc(var(--topbar-h) + 12px); }
            .stats-grid   { margin-bottom: 12px; }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<main class="main-content">

    <div class="page-header">
        <div class="page-heading">
            <h1>Parents</h1>
            <p>Manage parent and guardian accounts and their linked students.</p>
        </div>
        <button type="button" class="add-button" id="openAddBtn">
            <span class="add-icon">+</span>
            Add Parent
        </button>
    </div>

    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Parents</div>
            <div class="stat-value"><?php echo number_format($total_parents); ?></div>
            <div class="stat-line"></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active</div>
            <div class="stat-value"><?php echo number_format($active_parents); ?></div>
            <div class="stat-line"></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Inactive</div>
            <div class="stat-value"><?php echo number_format($inactive_parents); ?></div>
            <div class="stat-line"></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">New This Month</div>
            <div class="stat-value"><?php echo number_format($this_month); ?></div>
            <div class="stat-line"></div>
        </div>
    </section>

    <section class="filter-panel" id="filterPanel">
        <button type="button" class="filter-toggle" id="filterToggle">
            <span>Search &amp; Filters</span>
            <span class="chev">▼</span>
        </button>

        <form method="GET" action="parents.php" class="filter-form">
            <div class="filter-group">
                <label>Search Parent</label>
                <input type="text" name="search" class="filter-control"
                       placeholder="Name, email, phone or occupation..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-control">
                    <option value="">All Statuses</option>
                    <option value="active"    <?php echo $status === 'active'    ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive"  <?php echo $status === 'inactive'  ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>

            <button type="submit" class="filter-button">Search</button>
            <a href="parents.php" class="reset-button">Reset</a>
        </form>
    </section>

    <div class="results-bar">
        <h2>Parent Records</h2>
        <span class="results-count" id="resultsCount">
            <?php echo number_format(count($parents)); ?> record(s)
        </span>
    </div>

    <section class="table-panel" id="recordsPanel">

        <?php if (!empty($parents)): ?>

            <!-- DESKTOP TABLE -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Parent</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Occupation</th>
                            <th>Linked Students</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="parentsTableBody">
                    <?php
                    $number = 1;
                    foreach ($parents as $parent):
                        $full_name = trim(
                            ($parent['first_name'] ?? '') . ' ' .
                            ($parent['middle_name'] ?? '') . ' ' .
                            ($parent['last_name'] ?? '')
                        );
                        if ($full_name === '') { $full_name = 'Unnamed Parent'; }

                        $initial      = strtoupper(mb_substr($parent['first_name'] ?: $full_name, 0, 1));
                        $status_lower = strtolower($parent['status'] ?? 'inactive');
                    ?>
                        <tr data-parent-id="<?php echo (int) $parent['parent_id']; ?>">
                            <td><?php echo $number++; ?></td>

                            <td>
                                <div class="parent-cell">
                                    <?php if (!empty($parent['profile_pic'])): ?>
                                        <img src="../uploads/users/<?php echo htmlspecialchars($parent['profile_pic']); ?>"
                                             alt="<?php echo htmlspecialchars($full_name); ?>"
                                             class="parent-photo" loading="lazy">
                                    <?php else: ?>
                                        <div class="parent-placeholder"><?php echo $initial; ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="parent-name"><?php echo htmlspecialchars($full_name); ?></div>
                                        <div class="parent-id">Parent ID: <?php echo (int) $parent['parent_id']; ?></div>
                                    </div>
                                </div>
                            </td>

                            <td><span class="email"><?php echo htmlspecialchars($parent['email'] ?: '—'); ?></span></td>
                            <td><span class="phone"><?php echo htmlspecialchars($parent['phone'] ?: '—'); ?></span></td>
                            <td><span class="occupation"><?php echo htmlspecialchars($parent['occupation'] ?: '—'); ?></span></td>

                            <td>
                                <?php if (!empty($parent['links'])): ?>
                                    <div class="student-chips">
                                        <?php foreach ($parent['links'] as $link):
                                            $is_primary = !empty($link['is_primary_guardian']);
                                        ?>
                                            <span class="student-chip <?php echo $is_primary ? 'primary' : ''; ?>">
                                                <?php if ($is_primary): ?>
                                                    <span class="star">★</span>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($link['student_name']); ?>
                                                (<?php echo htmlspecialchars($link['relationship']); ?>)
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="no-links">No linked students</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="status status-<?php echo htmlspecialchars($status_lower); ?>">
                                    <?php echo ucfirst(htmlspecialchars($status_lower)); ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button type="button" class="action-btn edit-btn"
                                        data-parent='<?php echo htmlspecialchars(json_encode([
                                            "parent_id"   => (int) $parent["parent_id"],
                                            "user_id"     => (int) $parent["user_id"],
                                            "first_name"  => $parent["first_name"]  ?? "",
                                            "middle_name" => $parent["middle_name"] ?? "",
                                            "last_name"   => $parent["last_name"]   ?? "",
                                            "email"       => $parent["email"]       ?? "",
                                            "phone"       => $parent["phone"]       ?? "",
                                            "occupation"  => $parent["occupation"]  ?? "",
                                            "address"     => $parent["address"]     ?? "",
                                            "status"      => $parent["status"]      ?? "active",
                                            "links"       => $parent["links"]       ?? [],
                                        ]), ENT_QUOTES, "UTF-8"); ?>'
                                    >Edit</button>

                                    <button type="button" class="action-btn danger delete-btn"
                                        data-parent-id="<?php echo (int) $parent["parent_id"]; ?>"
                                        data-parent-name="<?php echo htmlspecialchars($full_name); ?>"
                                    >Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="card-list" id="parentsCardList">
                <?php foreach ($parents as $parent):
                    $full_name = trim(
                        ($parent['first_name'] ?? '') . ' ' .
                        ($parent['middle_name'] ?? '') . ' ' .
                        ($parent['last_name'] ?? '')
                    );
                    if ($full_name === '') { $full_name = 'Unnamed Parent'; }

                    $initial      = strtoupper(mb_substr($parent['first_name'] ?: $full_name, 0, 1));
                    $status_lower = strtolower($parent['status'] ?? 'inactive');
                ?>
                    <div class="parent-card" data-parent-id="<?php echo (int) $parent['parent_id']; ?>">

                        <div class="parent-card-top">
                            <?php if (!empty($parent['profile_pic'])): ?>
                                <img src="../uploads/users/<?php echo htmlspecialchars($parent['profile_pic']); ?>"
                                     alt="<?php echo htmlspecialchars($full_name); ?>"
                                     class="parent-photo" loading="lazy">
                            <?php else: ?>
                                <div class="parent-placeholder"><?php echo $initial; ?></div>
                            <?php endif; ?>
                            <div style="min-width:0;flex:1;">
                                <div class="parent-name"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="parent-id">Parent ID: <?php echo (int) $parent['parent_id']; ?></div>
                            </div>
                            <span class="status status-<?php echo htmlspecialchars($status_lower); ?>">
                                <?php echo ucfirst(htmlspecialchars($status_lower)); ?>
                            </span>
                        </div>

                        <div class="parent-card-meta">
                            <div class="meta-item">
                                <span class="k">Email</span>
                                <span class="v"><?php echo htmlspecialchars($parent['email'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Phone</span>
                                <span class="v"><?php echo htmlspecialchars($parent['phone'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Occupation</span>
                                <span class="v"><?php echo htmlspecialchars($parent['occupation'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="k">Registered</span>
                                <span class="v">
                                    <?php echo !empty($parent['created_at']) ? date('d M Y', strtotime($parent['created_at'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="meta-item full">
                                <span class="k">Address</span>
                                <span class="v"><?php echo htmlspecialchars($parent['address'] ?: '—'); ?></span>
                            </div>
                            <div class="meta-item full">
                                <span class="k">Linked Students</span>
                                <span class="v">
                                    <?php if (!empty($parent['links'])): ?>
                                        <div class="student-chips">
                                            <?php foreach ($parent['links'] as $link):
                                                $is_primary = !empty($link['is_primary_guardian']);
                                            ?>
                                                <span class="student-chip <?php echo $is_primary ? 'primary' : ''; ?>">
                                                    <?php if ($is_primary): ?>
                                                        <span class="star">★</span>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($link['student_name']); ?>
                                                    (<?php echo htmlspecialchars($link['relationship']); ?>)
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-links">No linked students</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <div class="parent-card-actions">
                            <button type="button" class="action-btn edit-btn"
                                data-parent='<?php echo htmlspecialchars(json_encode([
                                    "parent_id"   => (int) $parent["parent_id"],
                                    "user_id"     => (int) $parent["user_id"],
                                    "first_name"  => $parent["first_name"]  ?? "",
                                    "middle_name" => $parent["middle_name"] ?? "",
                                    "last_name"   => $parent["last_name"]   ?? "",
                                    "email"       => $parent["email"]       ?? "",
                                    "phone"       => $parent["phone"]       ?? "",
                                    "occupation"  => $parent["occupation"]  ?? "",
                                    "address"     => $parent["address"]     ?? "",
                                    "status"      => $parent["status"]      ?? "active",
                                    "links"       => $parent["links"]       ?? [],
                                ]), ENT_QUOTES, "UTF-8"); ?>'
                            >Edit</button>

                            <button type="button" class="action-btn danger delete-btn"
                                data-parent-id="<?php echo (int) $parent["parent_id"]; ?>"
                                data-parent-name="<?php echo htmlspecialchars($full_name); ?>"
                            >Delete</button>

                            <a href="view_parent.php?id=<?php echo (int) $parent['parent_id']; ?>"
                               class="action-btn">View</a>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="empty-icon">P</div>
                <h3>No parents found</h3>
                <p>
                    <?php if ($has_filters): ?>
                        No records match your search. Try clearing the filters.
                    <?php else: ?>
                        There are no parent records yet. Add your first parent to get started.
                    <?php endif; ?>
                </p>
            </div>

        <?php endif; ?>

    </section>

</main>

<!-- =========================================================
     ADD / EDIT MODAL
========================================================= -->
<div class="modal-backdrop" id="parentModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="parentModalTitle">
        <div class="modal-header">
            <h3 id="parentModalTitle">Add Parent</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">✕</button>
        </div>

        <form id="parentForm" autocomplete="off">
            <input type="hidden" name="parent_id" id="parent_id" value="">
            <input type="hidden" name="user_id"   id="user_id"   value="">

            <div class="modal-body">

                <!-- SECTION: PERSONAL INFO -->
                <div class="form-section">
                    <div class="form-section-title">Personal Information</div>
                    <div class="form-grid">

                        <div class="form-group">
                            <label for="first_name">First Name <span class="req">*</span></label>
                            <input type="text" id="first_name" name="first_name" class="form-control" required maxlength="100">
                            <div class="field-error" data-error-for="first_name"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control" maxlength="100">
                            <div class="field-error" data-error-for="middle_name"></div>
                        </div>

                        <div class="form-group">
                            <label for="last_name">Last Name <span class="req">*</span></label>
                            <input type="text" id="last_name" name="last_name" class="form-control" required maxlength="100">
                            <div class="field-error" data-error-for="last_name"></div>
                        </div>

                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <div class="field-error" data-error-for="status"></div>
                        </div>

                    </div>
                </div>

                <!-- SECTION: CONTACT -->
                <div class="form-section">
                    <div class="form-section-title">Contact &amp; Account</div>
                    <div class="form-grid">

                        <div class="form-group">
                            <label for="email">Email <span class="req">*</span></label>
                            <input type="email" id="email" name="email" class="form-control" required maxlength="150">
                            <div class="field-error" data-error-for="email"></div>
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone <span class="req">*</span></label>
                            <input type="tel" id="phone" name="phone" class="form-control" required maxlength="30">
                            <div class="field-error" data-error-for="phone"></div>
                        </div>

                        <div class="form-group">
                            <label for="occupation">Occupation</label>
                            <input type="text" id="occupation" name="occupation" class="form-control" maxlength="150">
                            <div class="field-error" data-error-for="occupation"></div>
                        </div>

                        <div class="form-group" id="passwordGroup">
                            <label for="password">Password <span class="req" id="passwordReq">*</span></label>
                            <input type="password" id="password" name="password" class="form-control" minlength="6" maxlength="100">
                            <div class="field-error" data-error-for="password"></div>
                        </div>

                        <div class="form-group full">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="3"></textarea>
                            <div class="field-error" data-error-for="address"></div>
                        </div>

                    </div>
                </div>

                <!-- SECTION: LINKED STUDENTS -->
                <div class="form-section">
                    <div class="form-section-title">Linked Students</div>

                    <div class="link-list" id="linkList">
                        <!-- dynamic rows injected here -->
                    </div>

                    <div class="no-links-hint" id="noLinksHint">No students linked yet.</div>

                    <button type="button" class="add-link-btn" id="addLinkBtn">
                        + Add Student Link
                    </button>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveParentBtn">
                    <span class="spinner"></span>
                    <span class="btn-label">Save Parent</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     DELETE CONFIRM MODAL
========================================================= -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal" style="max-width:420px;" role="dialog" aria-modal="true">
        <div class="modal-body">
            <div class="confirm-icon">!</div>
            <div class="confirm-text">
                <h3>Delete Parent?</h3>
                <p>
                    You are about to delete <strong id="deleteParentName">this parent</strong>.<br>
                    This will also remove all student links. This action cannot be undone.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                <span class="spinner"></span>
                <span class="btn-label">Delete</span>
            </button>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<!-- =========================================================
     STUDENTS DATA (JSON) — used by the link rows
========================================================= -->
<script>
    window.ALL_STUDENTS = <?php
        echo json_encode(array_map(function ($s) {
            return [
                'student_id'      => (int) $s['student_id'],
                'full_name'       => $s['full_name'],
                'registration_no' => $s['registration_no'] ?? '',
            ];
        }, $students), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
</script>

<script>
/* =========================================================
   TOASTS
========================================================= */
function showToast(message, type = 'success', timeout = 3200) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = message;
    wrap.appendChild(el);

    setTimeout(() => {
        el.style.transition = 'opacity .25s ease, transform .25s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}

/* =========================================================
   MODAL HELPERS
========================================================= */
const openModal  = id => document.getElementById(id).classList.add('open');
const closeModal = id => document.getElementById(id).classList.remove('open');

document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
        btn.closest('.modal-backdrop').classList.remove('open');
    });
});

document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => {
        if (e.target === bd) bd.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(bd => bd.classList.remove('open'));
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
    mq.addEventListener ? mq.addEventListener('change', syncFilterState) : mq.addListener(syncFilterState);

    filterToggle.addEventListener('click', () => {
        if (!mq.matches) return;
        filterPanel.classList.toggle('collapsed');
    });
})();

/* =========================================================
   STUDENT LINK ROWS
========================================================= */
const linkList   = document.getElementById('linkList');
const noLinkHint = document.getElementById('noLinksHint');
const addLinkBtn = document.getElementById('addLinkBtn');

const RELATIONSHIPS = ['Father', 'Mother', 'Guardian', 'Other'];

function studentOptions(selectedId) {
    let html = '<option value="">— Select student —</option>';
    for (const s of window.ALL_STUDENTS) {
        const sel = String(s.student_id) === String(selectedId) ? ' selected' : '';
        const label = s.full_name + (s.registration_no ? ' · ' + s.registration_no : '');
        html += `<option value="${s.student_id}"${sel}>${escapeHtml(label)}</option>`;
    }
    return html;
}

function relationshipOptions(selected) {
    return RELATIONSHIPS.map(r =>
        `<option value="${r}"${r === selected ? ' selected' : ''}>${r}</option>`
    ).join('');
}

function createLinkRow(data = {}) {
    const row = document.createElement('div');
    row.className = 'link-row';

    row.innerHTML = `
        <div class="form-group">
            <label>Student</label>
            <select class="form-control link-student">
                ${studentOptions(data.student_id || '')}
            </select>
        </div>

        <div class="form-group">
            <label>Relationship</label>
            <select class="form-control link-relationship">
                ${relationshipOptions(data.relationship || 'Guardian')}
            </select>
        </div>

        <label class="primary-toggle ${data.is_primary_guardian ? 'on' : ''}" title="Mark as primary guardian">
            <input type="checkbox" class="link-primary" ${data.is_primary_guardian ? 'checked' : ''}>
            <span class="dot"></span>
            <span>Primary</span>
        </label>

        <button type="button" class="link-remove" title="Remove link">✕</button>
    `;

    // Toggle "on" class for primary
    const primaryInput = row.querySelector('.link-primary');
    const primaryLabel = row.querySelector('.primary-toggle');
    primaryInput.addEventListener('change', () => {
        primaryLabel.classList.toggle('on', primaryInput.checked);
        if (primaryInput.checked) enforceSinglePrimary(row);
    });

    row.querySelector('.link-remove').addEventListener('click', () => {
        row.remove();
        refreshLinkHint();
    });

    linkList.appendChild(row);
    refreshLinkHint();
    return row;
}

function enforceSinglePrimary(activeRow) {
    // Only one primary allowed across all link rows
    document.querySelectorAll('.link-row').forEach(r => {
        if (r !== activeRow) {
            const cb = r.querySelector('.link-primary');
            const lb = r.querySelector('.primary-toggle');
            if (cb.checked) {
                cb.checked = false;
                lb.classList.remove('on');
            }
        }
    });
}

function refreshLinkHint() {
    const has = linkList.querySelectorAll('.link-row').length > 0;
    noLinkHint.style.display = has ? 'none' : '';
}

function collectLinks() {
    const links = [];
    linkList.querySelectorAll('.link-row').forEach(row => {
        const student_id  = row.querySelector('.link-student').value;
        const relationship = row.querySelector('.link-relationship').value;
        const is_primary  = row.querySelector('.link-primary').checked ? 1 : 0;

        if (!student_id) return; // skip empty rows

        links.push({
            student_id: parseInt(student_id, 10),
            relationship,
            is_primary_guardian: is_primary,
        });
    });
    return links;
}

addLinkBtn.addEventListener('click', () => createLinkRow());

/* =========================================================
   ADD / EDIT
========================================================= */
const parentModal   = document.getElementById('parentModal');
const parentForm    = document.getElementById('parentForm');
const saveBtn       = document.getElementById('saveParentBtn');
const modalTitle    = document.getElementById('parentModalTitle');
const passwordGrp   = document.getElementById('passwordGroup');
const passwordReq   = document.getElementById('passwordReq');
const passwordInput = document.getElementById('password');

const FIELDS = ['first_name','middle_name','last_name','email','phone','occupation','address','status','password'];

function clearErrors() {
    FIELDS.forEach(f => {
        const input = document.getElementById(f);
        if (input) input.classList.remove('error');
        const err = document.querySelector(`[data-error-for="${f}"]`);
        if (err) { err.textContent = ''; err.classList.remove('show'); }
    });
}

function showErrors(errors) {
    Object.entries(errors).forEach(([field, msg]) => {
        const input = document.getElementById(field);
        if (input) input.classList.add('error');
        const err = document.querySelector(`[data-error-for="${field}"]`);
        if (err) { err.textContent = msg; err.classList.add('show'); }
    });
}

function resetParentForm() {
    parentForm.reset();
    document.getElementById('parent_id').value = '';
    document.getElementById('user_id').value   = '';
    linkList.innerHTML = '';
    refreshLinkHint();
    clearErrors();
}

function openAddModal() {
    resetParentForm();
    modalTitle.textContent = 'Add Parent';
    passwordReq.style.display = '';
    passwordInput.required = true;
    openModal('parentModal');
    setTimeout(() => document.getElementById('first_name').focus(), 100);
}

function openEditModal(data) {
    resetParentForm();
    modalTitle.textContent = 'Edit Parent';
    passwordReq.style.display = 'none';
    passwordInput.required = false;

    document.getElementById('parent_id').value   = data.parent_id   || '';
    document.getElementById('user_id').value     = data.user_id     || '';
    document.getElementById('first_name').value  = data.first_name  || '';
    document.getElementById('middle_name').value = data.middle_name || '';
    document.getElementById('last_name').value   = data.last_name   || '';
    document.getElementById('email').value       = data.email       || '';
    document.getElementById('phone').value       = data.phone       || '';
    document.getElementById('occupation').value  = data.occupation  || '';
    document.getElementById('address').value     = data.address     || '';
    document.getElementById('status').value      = data.status      || 'active';

    // Render existing links
    if (Array.isArray(data.links)) {
        data.links.forEach(l => {
            createLinkRow({
                student_id: l.student_id,
                relationship: l.relationship || 'Guardian',
                is_primary_guardian: l.is_primary_guardian ? 1 : 0,
            });
        });
    }

    openModal('parentModal');
}

document.getElementById('openAddBtn').addEventListener('click', openAddModal);

document.addEventListener('click', e => {
    const editBtn = e.target.closest('.edit-btn');
    if (!editBtn) return;
    try {
        openEditModal(JSON.parse(editBtn.dataset.parent));
    } catch (err) {
        console.error(err);
        showToast('Could not open parent details.', 'error');
    }
});

/* =========================================================
   SAVE
========================================================= */
saveBtn.addEventListener('click', e => e.preventDefault());

parentForm.addEventListener('submit', async e => {
    e.preventDefault();
    clearErrors();

    const isEdit = document.getElementById('parent_id').value !== '';
    const errors = {};

    const firstName = document.getElementById('first_name').value.trim();
    const lastName  = document.getElementById('last_name').value.trim();
    const email     = document.getElementById('email').value.trim();
    const phone     = document.getElementById('phone').value.trim();
    const password  = passwordInput.value;

    if (!firstName) errors.first_name = 'First name is required.';
    if (!lastName)  errors.last_name  = 'Last name is required.';

    if (!email) {
        errors.email = 'Email is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.email = 'Enter a valid email address.';
    }

    if (!phone) errors.phone = 'Phone number is required.';

    if (!isEdit && password.length < 6) {
        errors.password = 'Password must be at least 6 characters.';
    }
    if (isEdit && password.length > 0 && password.length < 6) {
        errors.password = 'Password must be at least 6 characters.';
    }

    if (Object.keys(errors).length) {
        showErrors(errors);
        showToast('Please fix the highlighted fields.', 'error');
        return;
    }

    const links = collectLinks();

    const fd = new FormData(parentForm);
    fd.set('links', JSON.stringify(links));

    saveBtn.classList.add('loading');
    saveBtn.disabled = true;

    try {
        const res = await fetch('save_parent.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            if (json.errors) showErrors(json.errors);
            showToast(json.message || 'Could not save parent.', 'error');
            return;
        }

        showToast(json.message || 'Parent saved successfully.', 'success');

        if (json.parent) {
            upsertParentRow(json.parent);
            upsertParentCard(json.parent);
        }
        updateResultsCount();
        closeModal('parentModal');

    } catch (err) {
        console.error(err);
        showToast('Network error. Please try again.', 'error');
    } finally {
        saveBtn.classList.remove('loading');
        saveBtn.disabled = false;
    }
});

/* =========================================================
   DELETE
========================================================= */
let parentToDelete = null;

document.addEventListener('click', e => {
    const btn = e.target.closest('.delete-btn');
    if (!btn) return;

    parentToDelete = {
        id:   btn.dataset.parentId,
        name: btn.dataset.parentName
    };

    document.getElementById('deleteParentName').textContent = parentToDelete.name;
    openModal('deleteModal');
});

const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

confirmDeleteBtn.addEventListener('click', async () => {
    if (!parentToDelete) return;

    const fd = new FormData();
    fd.append('parent_id', parentToDelete.id);

    confirmDeleteBtn.classList.add('loading');
    confirmDeleteBtn.disabled = true;

    try {
        const res = await fetch('delete_parent.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const json = await res.json();

        if (!json.success) {
            showToast(json.message || 'Could not delete parent.', 'error');
            return;
        }

        showToast('Parent deleted successfully.', 'success');
        removeParentRow(parentToDelete.id);
        removeParentCard(parentToDelete.id);
        updateResultsCount();
        closeModal('deleteModal');
        parentToDelete = null;

    } catch (err) {
        console.error(err);
        showToast('Network error. Please try again.', 'error');
    } finally {
        confirmDeleteBtn.classList.remove('loading');
        confirmDeleteBtn.disabled = false;
    }
});

/* =========================================================
   DOM UPDATERS
========================================================= */
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}
const escapeAttr = escapeHtml;

function initials(first, last, fallback = '') {
    const a = (first || fallback || '').trim().charAt(0);
    const b = (last  || '').trim().charAt(0);
    return (a + b).toUpperCase() || 'P';
}

function fullName(p) {
    return [p.first_name, p.middle_name, p.last_name]
        .filter(Boolean).join(' ').trim() || 'Unnamed Parent';
}

function photoHTML(p) {
    if (p.profile_pic) {
        return `<img src="../uploads/users/${escapeAttr(p.profile_pic)}" alt="${escapeAttr(fullName(p))}" class="parent-photo" loading="lazy">`;
    }
    return `<div class="parent-placeholder">${escapeHtml(initials(p.first_name, p.last_name, fullName(p)))}</div>`;
}

function linksChipsHTML(links) {
    if (!links || !links.length) return '<span class="no-links">No linked students</span>';

    return '<div class="student-chips">' + links.map(l => {
        const isPrimary = !!l.is_primary_guardian;
        return `<span class="student-chip ${isPrimary ? 'primary' : ''}">
            ${isPrimary ? '<span class="star">★</span>' : ''}
            ${escapeHtml(l.student_name || '')}
            (${escapeHtml(l.relationship || 'Guardian')})
        </span>`;
    }).join('') + '</div>';
}

function parentRowHTML(p, index) {
    const status = (p.status || 'inactive').toLowerCase();
    const data   = escapeAttr(JSON.stringify({
        parent_id: p.parent_id, user_id: p.user_id,
        first_name: p.first_name || '', middle_name: p.middle_name || '', last_name: p.last_name || '',
        email: p.email || '', phone: p.phone || '',
        occupation: p.occupation || '', address: p.address || '',
        status: p.status || 'active', links: p.links || [],
    }));

    return `
        <td>${index}</td>
        <td>
            <div class="parent-cell">
                ${photoHTML(p)}
                <div>
                    <div class="parent-name">${escapeHtml(fullName(p))}</div>
                    <div class="parent-id">Parent ID: ${p.parent_id}</div>
                </div>
            </div>
        </td>
        <td><span class="email">${escapeHtml(p.email || '—')}</span></td>
        <td><span class="phone">${escapeHtml(p.phone || '—')}</span></td>
        <td><span class="occupation">${escapeHtml(p.occupation || '—')}</span></td>
        <td>${linksChipsHTML(p.links)}</td>
        <td>
            <span class="status status-${escapeHtml(status)}">
                ${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}
            </span>
        </td>
        <td>
            <div class="actions">
                <button type="button" class="action-btn edit-btn" data-parent='${data}'>Edit</button>
                <button type="button" class="action-btn danger delete-btn"
                        data-parent-id="${p.parent_id}"
                        data-parent-name="${escapeAttr(fullName(p))}">Delete</button>
            </div>
        </td>
    `;
}

function parentCardHTML(p) {
    const status = (p.status || 'inactive').toLowerCase();
    const data   = escapeAttr(JSON.stringify({
        parent_id: p.parent_id, user_id: p.user_id,
        first_name: p.first_name || '', middle_name: p.middle_name || '', last_name: p.last_name || '',
        email: p.email || '', phone: p.phone || '',
        occupation: p.occupation || '', address: p.address || '',
        status: p.status || 'active', links: p.links || [],
    }));
    const created = p.created_at ? new Date(p.created_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) : '—';

    return `
        <div class="parent-card-top">
            ${photoHTML(p)}
            <div style="min-width:0;flex:1;">
                <div class="parent-name">${escapeHtml(fullName(p))}</div>
                <div class="parent-id">Parent ID: ${p.parent_id}</div>
            </div>
            <span class="status status-${escapeHtml(status)}">
                ${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}
            </span>
        </div>

        <div class="parent-card-meta">
            <div class="meta-item"><span class="k">Email</span><span class="v">${escapeHtml(p.email || '—')}</span></div>
            <div class="meta-item"><span class="k">Phone</span><span class="v">${escapeHtml(p.phone || '—')}</span></div>
            <div class="meta-item"><span class="k">Occupation</span><span class="v">${escapeHtml(p.occupation || '—')}</span></div>
            <div class="meta-item"><span class="k">Registered</span><span class="v">${created}</span></div>
            <div class="meta-item full"><span class="k">Address</span><span class="v">${escapeHtml(p.address || '—')}</span></div>
            <div class="meta-item full"><span class="k">Linked Students</span><span class="v">${linksChipsHTML(p.links)}</span></div>
        </div>

        <div class="parent-card-actions">
            <button type="button" class="action-btn edit-btn" data-parent='${data}'>Edit</button>
            <button type="button" class="action-btn danger delete-btn"
                    data-parent-id="${p.parent_id}"
                    data-parent-name="${escapeAttr(fullName(p))}">Delete</button>
            <a href="view_parent.php?id=${p.parent_id}" class="action-btn">View</a>
        </div>
    `;
}

function upsertParentRow(p) {
    const tbody = document.getElementById('parentsTableBody');
    if (!tbody) return;
    const existing = tbody.querySelector(`tr[data-parent-id="${p.parent_id}"]`);
    if (existing) existing.remove();

    const tr = document.createElement('tr');
    tr.dataset.parentId = p.parent_id;
    const count = tbody.querySelectorAll('tr').length + 1;
    tr.innerHTML = parentRowHTML(p, count);
    tbody.prepend(tr);
    reindexRows();
}

function upsertParentCard(p) {
    const list = document.getElementById('parentsCardList');
    if (!list) return;
    const existing = list.querySelector(`.parent-card[data-parent-id="${p.parent_id}"]`);
    if (existing) existing.remove();

    const card = document.createElement('div');
    card.className = 'parent-card';
    card.dataset.parentId = p.parent_id;
    card.innerHTML = parentCardHTML(p);
    list.prepend(card);
}

function removeParentRow(id) {
    const row = document.querySelector(`#parentsTableBody tr[data-parent-id="${id}"]`);
    if (row) row.remove();
    reindexRows();
}

function removeParentCard(id) {
    const card = document.querySelector(`#parentsCardList .parent-card[data-parent-id="${id}"]`);
    if (card) card.remove();
}

function reindexRows() {
    document.querySelectorAll('#parentsTableBody tr').forEach((tr, i) => {
        const first = tr.querySelector('td');
        if (first) first.textContent = i + 1;
    });
}

function updateResultsCount() {
    const count = document.querySelectorAll('#parentsTableBody tr').length;
    const el = document.getElementById('resultsCount');
    if (el) el.textContent = count.toLocaleString() + ' record(s)';
}
</script>

</body>
</html>