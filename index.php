<?php
include 'includes/db.php';


/*
|--------------------------------------------------------------------------
| FETCH SCHOOL SETTINGS
|--------------------------------------------------------------------------
*/

$school = [
    'school_name' => 'Primary School',
    'address'     => '',
    'phone'       => '',
    'phone1'      => '',
    'phone2'      => '',
    'email'       => '',
    'logo'        => '',
];

$query = "
    SELECT
        setting_id,
        school_name,
        address,
        phone,
        phone1,
        phone2,
        email,
        logo
    FROM school_settings
    ORDER BY setting_id ASC
    LIMIT 1
";

$result = @mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {
    $school = mysqli_fetch_assoc($result);
}

/*
|--------------------------------------------------------------------------
| SAFE OUTPUT
|--------------------------------------------------------------------------
*/

$school_name = htmlspecialchars($school['school_name'] ?? 'Primary School');
$address     = htmlspecialchars($school['address']     ?? '');
$phone       = htmlspecialchars($school['phone']       ?? '');
$phone1      = htmlspecialchars($school['phone1']      ?? '');
$phone2      = htmlspecialchars($school['phone2']      ?? '');
$email       = htmlspecialchars($school['email']       ?? '');

/*
|--------------------------------------------------------------------------
| SCHOOL LOGO
|--------------------------------------------------------------------------
*/

$logo = '';
if (!empty($school['logo'])) {
    $logo = htmlspecialchars($school['logo']);
}

/*
|--------------------------------------------------------------------------
| FETCH STATISTICS (optional)
|--------------------------------------------------------------------------
*/

$stats = [
    ['label' => 'Students Enrolled',  'value' => '0'],
    ['label' => 'Exam Pass Rate',     'value' => '0'],
    ['label' => 'Years of Excellence','value' => '0'],
    ['label' => 'Expert Educators',   'value' => '0'],
];

$statsQuery = "SELECT label, value FROM school_stats ORDER BY id ASC LIMIT 4";
$statsResult = @mysqli_query($conn, $statsQuery);

if ($statsResult && mysqli_num_rows($statsResult) > 0) {
    $stats = [];
    while ($row = mysqli_fetch_assoc($statsResult)) {
        $stats[] = [
            'label' => htmlspecialchars($row['label']),
            'value' => htmlspecialchars($row['value']),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| FETCH NEWS (optional)
|--------------------------------------------------------------------------
*/

$news = [];
$newsQuery = "
    SELECT id, title, category, news_date, excerpt
    FROM news
    ORDER BY news_date DESC
    LIMIT 3
";
$newsResult = @mysqli_query($conn, $newsQuery);

if ($newsResult && mysqli_num_rows($newsResult) > 0) {
    while ($row = mysqli_fetch_assoc($newsResult)) {
        $news[] = [
            'id'       => (int) $row['id'],
            'title'    => htmlspecialchars($row['title']),
            'category' => htmlspecialchars($row['category'] ?? 'general'),
            'date'     => htmlspecialchars($row['news_date'] ?? ''),
            'excerpt'  => htmlspecialchars($row['excerpt'] ?? ''),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| FETCH EVENTS (optional)
|--------------------------------------------------------------------------
*/

$events = [];
$eventsQuery = "
    SELECT id, title, event_date, event_time, location, description
    FROM events
    WHERE event_date >= CURDATE()
    ORDER BY event_date ASC
    LIMIT 4
";
$eventsResult = @mysqli_query($conn, $eventsQuery);

if ($eventsResult && mysqli_num_rows($eventsResult) > 0) {
    while ($row = mysqli_fetch_assoc($eventsResult)) {
        $events[] = [
            'id'          => (int) $row['id'],
            'title'       => htmlspecialchars($row['title']),
            'date'        => htmlspecialchars($row['event_date']),
            'time'        => htmlspecialchars($row['event_time'] ?? ''),
            'location'    => htmlspecialchars($row['location'] ?? ''),
            'description' => htmlspecialchars($row['description'] ?? ''),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| FETCH INSTITUTIONS / SCHOOL LEVELS (optional)
|--------------------------------------------------------------------------
*/

$institutions = [];
$instQuery = "
    SELECT id, name, description, link
    FROM institutions
    ORDER BY id ASC
    LIMIT 6
";
$instResult = @mysqli_query($conn, $instQuery);

if ($instResult && mysqli_num_rows($instResult) > 0) {
    while ($row = mysqli_fetch_assoc($instResult)) {
        $institutions[] = [
            'name'        => htmlspecialchars($row['name']),
            'description' => htmlspecialchars($row['description'] ?? ''),
            'link'        => htmlspecialchars($row['link'] ?? '#'),
        ];
    }
}

if (empty($institutions)) {
    $institutions = [
        ['name' => 'Kindergarten 1', 'description' => 'Play-based early childhood foundation.', 'link' => '#'],
        ['name' => 'Kindergarten 2', 'description' => 'Structured learning for young minds.', 'link' => '#'],
        ['name' => 'Standard 1 – 3', 'description' => 'Lower primary literacy and numeracy.', 'link' => '#'],
        ['name' => 'Standard 4 – 5', 'description' => 'Middle primary skill development.', 'link' => '#'],
        ['name' => 'Standard 6',     'description' => 'Upper primary preparation.', 'link' => '#'],
        ['name' => 'Standard 7',     'description' => 'Final primary year and PSLE prep.', 'link' => '#'],
    ];
}


/*
|--------------------------------------------------------------------------
| FETCH GALLERY (KUMBUKUMBU)
|--------------------------------------------------------------------------
*/

$gallery = [];
$galleryCategories = [];

$galleryQuery = "
    SELECT id, title, category, image, description, taken_on
    FROM gallery
    ORDER BY taken_on DESC, id DESC
    LIMIT 24
";
$galleryResult = @mysqli_query($conn, $galleryQuery);

if ($galleryResult && mysqli_num_rows($galleryResult) > 0) {
    while ($row = mysqli_fetch_assoc($galleryResult)) {
        $cat = strtolower(trim($row['category'] ?? 'general'));

        $gallery[] = [
          'id'          => (int) $row['id'],
          'title'       => htmlspecialchars($row['title']),
          'category'    => htmlspecialchars($cat),
          'image'       => 'uploads/gallery/' . htmlspecialchars($row['image']),
          'description' => htmlspecialchars($row['description'] ?? ''),
          'taken_on'    => htmlspecialchars($row['taken_on'] ?? ''),
        ]; 

        $galleryCategories[$cat] = true;
    }
}

$galleryCategories = array_keys($galleryCategories);
sort($galleryCategories);


/*
|--------------------------------------------------------------------------
| HERO BACKGROUND
|--------------------------------------------------------------------------
*/
$heroBg = 'assets/images/school-background.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $school_name; ?> | Primary School Management System</title>
    <meta name="description" content="Primary School Records Management System for managing students, teachers, attendance, academic results and parent access.">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy: #17233c;
            --navy-dark: #0c1425;
            --gold: #c9a227;
            --gold-light: #e4c85a;
            --cream: #f7f5ef;
            --white: #ffffff;
            --text: #263044;
            --muted: #6c7484;
            --border: #e7e8ec;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: var(--white);
            color: var(--text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        a { text-decoration: none; color: inherit; }

        img { max-width: 100%; display: block; }

        /* =========================================================
           NAVBAR
        ========================================================= */
        .navbar {
            width: 100%;
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 7%;
            background: rgba(255, 255, 255, 0.98);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(8px);
        }

        .brand { display: flex; align-items: center; gap: 13px; }

        .brand-logo {
            width: 70px; height: 70px;
            border-radius: 12px;
            overflow: hidden;
            background: transparent;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .brand-logo img { width: 100%; height: 100%; object-fit: cover; }

        .brand-mark { color: var(--gold-light); font-weight: 800; font-size: 15px; letter-spacing: 1px; }

        .brand-text strong { display: block; color: var(--navy); font-size: 16px; letter-spacing: .4px; }
        .brand-text span { display: block; color: var(--muted); font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; }

        .nav-links { display: flex; align-items: center; gap: 30px; }
        .nav-links a { color: var(--text); font-size: 14px; font-weight: 500; transition: .25s; }
        .nav-links a:hover { color: var(--gold); }

        .nav-login {
            padding: 11px 21px;
            border: 1px solid var(--navy);
            color: var(--navy) !important;
            border-radius: 7px;
        }
        .nav-login:hover { background: var(--navy); color: var(--white) !important; }

        .nav-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            flex-direction: column;
            gap: 5px;
        }
        .nav-toggle span {
            width: 26px; height: 3px; background: var(--navy); border-radius: 2px;
        }

        /* =========================================================
           GALLERY (KUMBUKUMBU)
        ========================================================= */
        .gallery { background: var(--white); }

        .gallery-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 35px;
        }

        .filter-btn {
            padding: 8px 18px;
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            cursor: pointer;
            transition: .25s ease;
            text-transform: capitalize;
            font-family: inherit;
        }

        .filter-btn:hover { border-color: var(--gold); color: var(--gold); }

        .filter-btn.active {
            background: var(--navy);
            color: var(--white);
            border-color: var(--navy);
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }

        .gallery-item {
            position: relative;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            border-radius: 10px;
            cursor: pointer;
            background: var(--cream);
            transition: .3s ease;
        }

        .gallery-item:nth-child(6n + 1) {
            grid-column: span 2;
            grid-row: span 2;
            aspect-ratio: 1 / 1;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .5s ease;
        }

        .gallery-item:hover img { transform: scale(1.08); }

        .gallery-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(
                to top,
                rgba(12, 20, 37, .88) 0%,
                rgba(12, 20, 37, .3) 55%,
                transparent 100%
            );
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 18px;
            opacity: 0;
            transition: opacity .3s ease;
        }

        .gallery-item:hover .gallery-overlay { opacity: 1; }

        .gallery-overlay h4 {
            color: var(--white);
            font-size: 15px;
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .gallery-overlay span {
            color: var(--gold-light);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            font-weight: 700;
        }

        .gallery-zoom {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255,255,255,.9);
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            opacity: 0;
            transform: scale(.7);
            transition: .3s ease;
        }

        .gallery-item:hover .gallery-zoom { opacity: 1; transform: scale(1); }

        /* =========================================================
           LIGHTBOX
        ========================================================= */
        .lightbox {
            position: fixed;
            inset: 0;
            background: rgba(8, 12, 22, .95);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            opacity: 0;
            transition: opacity .25s ease;
            backdrop-filter: blur(6px);
        }

        .lightbox.open { display: flex; opacity: 1; }

        .lightbox-inner {
            max-width: 1000px;
            width: 100%;
            position: relative;
            text-align: center;
        }

        .lightbox img {
            max-width: 100%;
            max-height: 78vh;
            border-radius: 10px;
            box-shadow: 0 25px 60px rgba(0,0,0,.5);
            margin: 0 auto;
        }

        .lightbox-caption { margin-top: 18px; color: var(--white); }
        .lightbox-caption h3 { font-size: 18px; margin-bottom: 5px; }
        .lightbox-caption p  { color: #b6bfcf; font-size: 13.5px; }

        .lightbox-close,
        .lightbox-prev,
        .lightbox-next {
            position: absolute;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: var(--white);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: .25s ease;
            z-index: 2;
            font-family: inherit;
        }

        .lightbox-close:hover,
        .lightbox-prev:hover,
        .lightbox-next:hover {
            background: var(--gold);
            color: var(--navy-dark);
            border-color: var(--gold);
        }

        .lightbox-close { top: -55px; right: 0; }
        .lightbox-prev  { left: -60px; top: 50%; transform: translateY(-50%); }
        .lightbox-next  { right: -60px; top: 50%; transform: translateY(-50%); }

        /* =========================================================
           HERO
        ========================================================= */
        .hero {
            min-height: calc(100vh - 78px);
            background:
                linear-gradient(
                    90deg,
                    rgba(8, 17, 33, 0.92) 0%,
                    rgba(14, 27, 48, 0.82) 45%,
                    rgba(14, 27, 48, 0.55) 100%
                ),
                url("<?php echo $heroBg; ?>");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: var(--white);
            display: flex;
            align-items: center;
            padding: 90px 7%;
            position: relative;
            overflow: hidden;
        }

        .hero-content { max-width: 850px; position: relative; z-index: 2; }

        .school-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border: 1px solid rgba(255,255,255,.25);
            background: rgba(255,255,255,.08);
            border-radius: 30px;
            color: var(--gold-light);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.7px;
            margin-bottom: 24px;
            backdrop-filter: blur(5px);
        }

        .school-badge::before {
            content: "";
            width: 7px; height: 7px;
            background: var(--gold);
            border-radius: 50%;
        }

        .hero h1 {
            font-size: clamp(38px, 6vw, 72px);
            line-height: 1.05;
            letter-spacing: -2px;
            margin-bottom: 22px;
            font-weight: 750;
        }

        .hero h1 span { color: var(--gold-light); }

        .hero-description {
            max-width: 700px;
            color: #e1e5ee;
            font-size: 17px;
            line-height: 1.8;
            margin-bottom: 34px;
        }

        .hero-buttons { display: flex; flex-wrap: wrap; gap: 14px; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 25px;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 650;
            transition: .25s ease;
            cursor: pointer;
            border: none;
            font-family: inherit;
        }

        .btn-primary { background: var(--gold); color: var(--navy-dark); }
        .btn-primary:hover { background: var(--gold-light); transform: translateY(-2px); }

        .btn-outline {
            border: 1px solid rgba(255,255,255,.4);
            color: var(--white);
            background: rgba(255,255,255,.03);
        }
        .btn-outline:hover { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.7); }

        /* =========================================================
           SECTION BASE
        ========================================================= */
        .section { padding: 90px 7%; }
        .section-label {
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 11px;
            font-weight: 750;
            margin-bottom: 12px;
        }
        .section-heading { max-width: 720px; margin-bottom: 50px; }
        .section-heading h2 {
            color: var(--navy);
            font-size: clamp(28px, 4vw, 38px);
            line-height: 1.2;
            margin-bottom: 15px;
        }
        .section-heading p { color: var(--muted); font-size: 15px; }

        /* =========================================================
           STATS BAND
        ========================================================= */
        .stats-band {
            background: var(--navy);
            color: var(--white);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            padding: 0;
        }
        .stat-item {
            padding: 40px 20px;
            text-align: center;
            border-right: 1px solid rgba(255,255,255,.08);
        }
        .stat-item:last-child { border-right: none; }
        .stat-value {
            font-size: clamp(28px, 4vw, 44px);
            font-weight: 800;
            color: var(--gold-light);
            line-height: 1;
            margin-bottom: 10px;
        }
        .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #c3cbd9;
        }

        /* =========================================================
           INSTITUTIONS
        ========================================================= */
        .institutions { background: var(--cream); }
        .institution-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }
        .institution-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            transition: .25s ease;
            display: flex;
            flex-direction: column;
        }
        .institution-card:hover {
            transform: translateY(-5px);
            border-color: rgba(201,162,39,.45);
            box-shadow: 0 15px 35px rgba(23,35,60,.08);
        }
        .institution-card h3 {
            color: var(--navy);
            font-size: 19px;
            margin-bottom: 10px;
        }
        .institution-card p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            flex: 1;
            margin-bottom: 20px;
        }
        .institution-link {
            color: var(--gold);
            font-weight: 650;
            font-size: 13px;
            letter-spacing: .5px;
        }
        .institution-link::after { content: " →"; }

        /* =========================================================
           NEWS
        ========================================================= */
        .news-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }
        .news-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: .25s ease;
            display: flex;
            flex-direction: column;
        }
        .news-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(23,35,60,.08);
        }
        .news-top {
            padding: 22px 24px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .news-cat {
            background: var(--cream);
            color: var(--gold);
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 750;
        }
        .news-card h3 {
            padding: 14px 24px 10px;
            color: var(--navy);
            font-size: 16px;
            line-height: 1.4;
        }
        .news-card p {
            padding: 0 24px 20px;
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.7;
            flex: 1;
        }
        .news-more {
            padding: 14px 24px;
            border-top: 1px solid var(--border);
            color: var(--gold);
            font-size: 13px;
            font-weight: 650;
        }

        /* =========================================================
           CTA
        ========================================================= */
        .cta {
            background:
                linear-gradient(90deg, rgba(8,17,33,.94), rgba(14,27,48,.85)),
                url("<?php echo $heroBg; ?>");
            background-size: cover;
            background-position: center;
            color: var(--white);
            text-align: center;
            padding: 100px 7%;
        }
        .cta h2 {
            font-size: clamp(28px, 4vw, 44px);
            margin-bottom: 16px;
        }
        .cta p {
            max-width: 620px;
            margin: 0 auto 30px;
            color: #d6dbe6;
            font-size: 15px;
        }

        /* =========================================================
           EVENTS
        ========================================================= */
        .events { background: var(--cream); }
        .event-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }
        .event-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 22px;
            display: flex;
            gap: 20px;
            align-items: flex-start;
            transition: .25s ease;
        }
        .event-card:hover { box-shadow: 0 12px 30px rgba(23,35,60,.07); }
        .event-date {
            min-width: 62px;
            text-align: center;
            background: var(--navy);
            color: var(--white);
            border-radius: 10px;
            padding: 10px 6px;
            flex-shrink: 0;
        }
        .event-date .d { font-size: 22px; font-weight: 800; line-height: 1; }
        .event-date .m { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold-light); }
        .event-body h3 { color: var(--navy); font-size: 16px; margin-bottom: 4px; }
        .event-body .meta { color: var(--muted); font-size: 12.5px; margin-bottom: 6px; }
        .event-body p { color: var(--muted); font-size: 13px; line-height: 1.6; }

        /* =========================================================
           CONTACT / FOOTER
        ========================================================= */
        footer {
            background: var(--navy-dark);
            color: #aab3c2;
            padding: 60px 7% 25px;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        .footer-grid h4 {
            color: var(--white);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 18px;
        }
        .footer-grid p, .footer-grid li {
            font-size: 13.5px;
            line-height: 1.9;
            list-style: none;
        }
        .footer-grid a:hover { color: var(--gold-light); }
        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,.08);
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 12px;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 992px) {
            .institution-grid,
            .news-grid { grid-template-columns: repeat(2, 1fr); }
            .footer-grid { grid-template-columns: 1fr 1fr; }
            .gallery-grid { grid-template-columns: repeat(3, 1fr); }
            .lightbox-prev { left: 5px; }
            .lightbox-next { right: 5px; }
        }

        @media (max-width: 768px) {
            .nav-toggle { display: flex; }
            .nav-links {
                position: absolute;
                top: 78px; left: 0; right: 0;
                background: var(--white);
                flex-direction: column;
                align-items: flex-start;
                gap: 0;
                padding: 0 7%;
                max-height: 0;
                overflow: hidden;
                transition: max-height .3s ease;
                border-bottom: 1px solid var(--border);
            }
            .nav-links.open { max-height: 400px; padding: 10px 7% 20px; }
            .nav-links a { padding: 12px 0; width: 100%; }
            .nav-login { margin-top: 8px; text-align: center; }

            .stats-band { grid-template-columns: repeat(2, 1fr); }
            .stat-item:nth-child(2) { border-right: none; }
            .stat-item:nth-child(1),
            .stat-item:nth-child(2) { border-bottom: 1px solid rgba(255,255,255,.08); }

            .institution-grid,
            .news-grid,
            .event-list { grid-template-columns: 1fr; }

            .gallery-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .gallery-item:nth-child(6n + 1) {
                grid-column: span 2;
                grid-row: span 1;
                aspect-ratio: 16 / 9;
            }
            .gallery-overlay {
                opacity: 1;
                background: linear-gradient(to top, rgba(12,20,37,.85), transparent 70%);
            }
            .gallery-overlay h4 { font-size: 13px; }
            .gallery-zoom { display: none; }

            .lightbox-close { top: 10px; right: 10px; }
            .lightbox-prev,
            .lightbox-next { width: 38px; height: 38px; }
            .lightbox img { max-height: 65vh; }

            .section { padding: 65px 6%; }
            .hero { padding: 70px 6%; min-height: auto; }
            .hero h1 { letter-spacing: -1px; }
            .hero-description { font-size: 15px; }

            .footer-grid { grid-template-columns: 1fr; gap: 25px; }
            .footer-bottom { flex-direction: column; text-align: center; }
        }

        @media (max-width: 480px) {
            .navbar { padding: 10px 5%; }
            .brand-text strong { font-size: 14px; }
            .stats-band { grid-template-columns: 1fr 1fr; }
            .btn { width: 100%; }
            .hero-buttons { flex-direction: column; }

            .gallery-filters { gap: 8px; }
            .filter-btn { padding: 7px 14px; font-size: 12px; }
            .lightbox-caption h3 { font-size: 15px; }
        }
    </style>
</head>
<body>

<!-- =========================================================
     NAVIGATION
========================================================= -->
<header class="navbar">
    <a href="index.php" class="brand">
        <div class="brand-logo">
            <?php if (!empty($logo)): ?>
                <img src="<?php echo $logo; ?>" alt="<?php echo $school_name; ?> Logo">
            <?php else: ?>
                <span class="brand-mark">PS</span>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <strong><?php echo $school_name; ?></strong>
        </div>
    </a>

    <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation">
        <span></span><span></span><span></span>
    </button>

    <nav class="nav-links" id="navLinks">
        <a href="#institutions">Our Schools</a>
        <a href="#news">News</a>
        <a href="#gallery">Gallery</a>
        <a href="#events">Events</a>
        <a href="#contact">Contact</a>
        <a href="login.php" class="nav-login">Login</a>
    </nav>
</header>

<!-- =========================================================
     HERO
========================================================= -->
<section class="hero">
    <div class="hero-content">
        <div class="school-badge">Welcome to <?php echo $school_name; ?></div>

        <h1>
            Nurturing Minds,<br>
            <span>Building Futures.</span>
        </h1>

        <p class="hero-description">
            <?php echo $school_name; ?> uses a centralized school records
            management system designed to manage students, teachers, academic
            performance, attendance and parent access from Kindergarten
            through Standard Seven.
        </p>

        <div class="hero-buttons">
            <a href="login.php" class="btn btn-primary">Parent &amp; Staff Login</a>
            <a href="#institutions" class="btn btn-outline">Explore Our School</a>
        </div>
    </div>
</section>

<!-- =========================================================
     STATS BAND
========================================================= -->
<section class="stats-band">
    <?php foreach ($stats as $stat): ?>
        <div class="stat-item">
            <div class="stat-value"><?php echo $stat['value']; ?></div>
            <div class="stat-label"><?php echo $stat['label']; ?></div>
        </div>
    <?php endforeach; ?>
</section>

<!-- INSTITUTIONS / SCHOOL LEVELS -->
<section class="section institutions" id="institutions">
    <div class="section-heading">
        <div class="section-label">01 — Our School</div>
        <h2>Tailored Education for Every Stage</h2>
        <p>From early childhood foundations to advanced primary preparation, we provide the environment your child needs to thrive.</p>
    </div>

    <div class="institution-grid">
        <?php foreach ($institutions as $inst): ?>
            <div class="institution-card">
                <h3><?php echo $inst['name']; ?></h3>
                <p><?php echo $inst['description']; ?></p>
                <a href="<?php echo $inst['link']; ?>" class="institution-link">Explore School</a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- =========================================================
     NEWS
========================================================= -->
<?php if (!empty($news)): ?>
<section class="section" id="news">
    <div class="section-heading">
        <div class="section-label">02 — Updates &amp; News</div>
        <h2>Happening at <?php echo $school_name; ?></h2>
    </div>

    <div class="news-grid">
        <?php foreach ($news as $item): ?>
            <article class="news-card">
                <div class="news-top">
                    <span class="news-cat"><?php echo $item['category']; ?></span>
                    <span><?php echo $item['date']; ?></span>
                </div>
                <h3><?php echo $item['title']; ?></h3>
                <p><?php echo $item['excerpt']; ?></p>
                <a href="news.php?id=<?php echo $item['id']; ?>" class="news-more">Read more →</a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- =========================================================
     CTA
========================================================= -->
<section class="cta">
    <h2>Invest in Your Child's Bright Future</h2>
    <p>Application forms for the current academic year are now available for all classes from Kindergarten to Standard Seven.</p>
    <a href="admissions.php" class="btn btn-primary">Admissions Open</a>
</section>

<!-- =========================================================
     GALLERY / KUMBUKUMBU
========================================================= -->
<?php if (!empty($gallery)): ?>
<section class="section gallery" id="gallery">
    <div class="section-heading">
        <div class="section-label">Memory</div>
        <h2>School Memories &amp; Gallery</h2>
        <p>Moments from our classrooms, events, sports and school trips — captured throughout the year.</p>
    </div>

    <?php if (count($galleryCategories) > 1): ?>
    <div class="gallery-filters">
        <button class="filter-btn active" data-filter="all">All</button>
        <?php foreach ($galleryCategories as $cat): ?>
            <button class="filter-btn" data-filter="<?php echo $cat; ?>">
                <?php echo ucfirst($cat); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="gallery-grid" id="galleryGrid">
        <?php foreach ($gallery as $i => $item): ?>
            <div
                class="gallery-item"
                data-category="<?php echo $item['category']; ?>"
                data-index="<?php echo $i; ?>"
            >
                <img
                    src="<?php echo $item['image']; ?>"
                    alt="<?php echo $item['title']; ?>"
                    loading="lazy"
                >
                <div class="gallery-zoom">+</div>
                <div class="gallery-overlay">
                    <span><?php echo ucfirst($item['category']); ?></span>
                    <h4><?php echo $item['title']; ?></h4>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox">
    <div class="lightbox-inner">
        <button class="lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>
        <button class="lightbox-prev" id="lightboxPrev" aria-label="Previous">&#10094;</button>
        <button class="lightbox-next" id="lightboxNext" aria-label="Next">&#10095;</button>
        <img src="" alt="" id="lightboxImg">
        <div class="lightbox-caption">
            <h3 id="lightboxTitle"></h3>
            <p id="lightboxDesc"></p>
        </div>
    </div>
</div>

<script>
    // Store gallery data for lightbox
    const galleryData = <?php echo json_encode($gallery); ?>;

    const galleryItems = document.querySelectorAll('.gallery-item');
    const lightbox     = document.getElementById('lightbox');
    const lightboxImg  = document.getElementById('lightboxImg');
    const lightboxTtl  = document.getElementById('lightboxTitle');
    const lightboxDsc  = document.getElementById('lightboxDesc');
    const lbClose      = document.getElementById('lightboxClose');
    const lbPrev       = document.getElementById('lightboxPrev');
    const lbNext       = document.getElementById('lightboxNext');

    let currentItems = [...galleryItems];
    let currentIndex = 0;

    // Open lightbox
    galleryItems.forEach(item => {
        item.addEventListener('click', () => {
            const visible = [...document.querySelectorAll('.gallery-item')]
                .filter(el => el.style.display !== 'none');
            currentItems = visible;
            currentIndex = visible.indexOf(item);
            showLightbox(currentIndex);
        });
    });

    function showLightbox(index) {
        const item = currentItems[index];
        if (!item) return;
        const data = galleryData[item.dataset.index];
        lightboxImg.src      = data.image;
        lightboxImg.alt      = data.title;
        lightboxTtl.textContent = data.title;
        lightboxDsc.textContent = data.description || data.taken_on || '';
        lightbox.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('open');
        document.body.style.overflow = '';
    }

    function navigate(dir) {
        if (currentItems.length === 0) return;
        currentIndex = (currentIndex + dir + currentItems.length) % currentItems.length;
        showLightbox(currentIndex);
    }

    lbClose.addEventListener('click', closeLightbox);
    lbPrev.addEventListener('click', () => navigate(-1));
    lbNext.addEventListener('click', () => navigate(1));

    lightbox.addEventListener('click', e => {
        if (e.target === lightbox) closeLightbox();
    });

    document.addEventListener('keydown', e => {
        if (!lightbox.classList.contains('open')) return;
        if (e.key === 'Escape')     closeLightbox();
        if (e.key === 'ArrowLeft')  navigate(-1);
        if (e.key === 'ArrowRight') navigate(1);
    });

    // Category filter
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const filter = btn.dataset.filter;
            galleryItems.forEach(item => {
                const match = filter === 'all' || item.dataset.category === filter;
                item.style.display = match ? '' : 'none';
            });
        });
    });
</script>
<?php endif; ?>

<!-- =========================================================
     EVENTS
========================================================= -->
<?php if (!empty($events)): ?>
<section class="section events" id="events">
    <div class="section-heading">
        <div class="section-label">School Calendar</div>
        <h2>Upcoming Events</h2>
    </div>

    <div class="event-list">
        <?php foreach ($events as $ev): ?>
            <?php
                $ts   = strtotime($ev['date']);
                $day  = $ts ? date('d', $ts) : '';
                $mon  = $ts ? date('M', $ts) : '';
                $full = $ts ? date('l, F j, Y', $ts) : $ev['date'];
            ?>
            <div class="event-card">
                <div class="event-date">
                    <div class="d"><?php echo $day; ?></div>
                    <div class="m"><?php echo $mon; ?></div>
                </div>
                <div class="event-body">
                    <h3><?php echo $ev['title']; ?></h3>
                    <div class="meta">
                        <?php echo $full; ?>
                        <?php if (!empty($ev['time'])): ?> · <?php echo $ev['time']; ?><?php endif; ?>
                        <?php if (!empty($ev['location'])): ?> · <?php echo $ev['location']; ?><?php endif; ?>
                    </div>
                    <?php if (!empty($ev['description'])): ?>
                        <p><?php echo $ev['description']; ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- =========================================================
     CONTACT / FOOTER
========================================================= -->
<footer id="contact">
    <div class="footer-grid">
        <div>
            <h4><?php echo $school_name; ?></h4>
            <p>
                A school records management system built to keep
                student, teacher and academic information organized and
                accessible.
            </p>
        </div>

        <div>
            <h4>Quick Links</h4>
            <ul>
                <li><a href="#institutions">Our Schools</a></li>
                <li><a href="#news">News</a></li>
                <li><a href="#gallery">Gallery</a></li>
                <li><a href="#events">Events</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>

        <div>
            <h4>Contact</h4>
            <ul>
                <?php if (!empty($address)): ?><li><?php echo $address; ?></li><?php endif; ?>
                <?php if (!empty($phone)):  ?><li>📞 <?php echo $phone;  ?></li><?php endif; ?>
                <?php if (!empty($phone1)): ?><li>📞 <?php echo $phone1; ?></li><?php endif; ?>
                <?php if (!empty($phone2)): ?><li>📞 <?php echo $phone2; ?></li><?php endif; ?>
                <?php if (!empty($email)):  ?><li>✉ <?php echo $email;  ?></li><?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <p>© <?php echo date('Y'); ?> <strong><?php echo $school_name; ?></strong>. All rights reserved.</p>
        <p>Secure · Organized · Connected</p>
    </div>
</footer>

<script>
    // Mobile nav toggle
    const navToggle = document.getElementById('navToggle');
    const navLinks  = document.getElementById('navLinks');

    if (navToggle) {
        navToggle.addEventListener('click', () => {
            navLinks.classList.toggle('open');
        });
    }

    // Close mobile nav on link click
    document.querySelectorAll('#navLinks a').forEach(link => {
        link.addEventListener('click', () => navLinks.classList.remove('open'));
    });
</script>

</body>
</html>