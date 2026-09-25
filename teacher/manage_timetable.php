<?php
session_start();

require_once '../auth/auth_check.php';
requireRole('teacher');

require_once '../includes/db.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);

error_reporting(E_ALL);
ini_set('display_errors', 1);


/* =========================================================================
   HELPERS
   ========================================================================= */

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $t): bool {
    $safe = mysqli_real_escape_string($conn, $t);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $r && mysqli_num_rows($r) > 0;
}

function redirect_with_flash(string $type, string $message): void {
    $_SESSION['tt_flash'] = ['type' => $type, 'message' => $message];
    header('Location: manage_timetable.php');
    exit;
}

function minutes_to_time(int $minutes): string {
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d:00', $h, $m);
}

function hhmm(?string $time): string {
    if (!$time) return '';
    return substr($time, 0, 5);
}


/* =========================================================================
   AUTH
   ========================================================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT t.teacher_id, t.assignment_type,
            u.first_name, u.last_name
     FROM teachers t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.user_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$me) die('Teacher profile not found.');

$my_role = $me['assignment_type'] ?? '';
$my_name = trim($me['first_name'] . ' ' . $me['last_name']);

if (!in_array($my_role, ['academic', 'headteacher'], true)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><title>Access Denied</title>
    <style>body{font-family:Arial;background:#f4f6f8;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;}
    .box{background:#fff;padding:40px;border-radius:14px;max-width:500px;text-align:center;box-shadow:0 12px 30px rgba(0,0,0,.08);}
    .box h2{color:#a12626;margin:0 0 12px;} .box p{color:#4b5563;margin:0 0 20px;}
    .box a{display:inline-block;padding:11px 20px;background:#17233c;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;}</style>
    </head><body><div class="box">
    <h2>Access Denied</h2>
    <p>Only the <strong>Academic Master</strong> or <strong>Headteacher</strong> can build the school timetable.</p>
    <a href="dashboard.php">Back to Dashboard</a>
    </div></body></html>
    <?php
    exit;
}


/* =========================================================================
   ENSURE timetable TABLE
   ========================================================================= */

function ensureTimetableSchema(mysqli $conn): void
{
    if (!tableExists($conn, 'timetable')) {
        mysqli_query(
            $conn,
            "CREATE TABLE timetable (
                id               INT(11) NOT NULL AUTO_INCREMENT,
                class_id         INT(11) NOT NULL,
                subject_id       INT(11) NULL,
                teacher_id       INT(11) NULL,
                academic_year_id INT(11) NOT NULL,
                term_id          INT(11) NOT NULL,
                day_of_week      ENUM('Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL,
                period_no        TINYINT(2) NOT NULL,
                start_time       TIME NULL,
                end_time         TIME NULL,
                is_break         TINYINT(1) DEFAULT 0,
                break_label      VARCHAR(30) NULL,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_slot (class_id, academic_year_id, term_id, day_of_week, period_no),
                KEY idx_teacher (teacher_id),
                KEY idx_subject (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return;
    }

    $existing = [];
    $res = mysqli_query($conn, "SHOW COLUMNS FROM timetable");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $existing[strtolower($row['Field'])] = $row;
        }
    }

    $additions = [
        'subject_id'   => "ALTER TABLE timetable ADD COLUMN subject_id INT(11) NULL AFTER class_id",
        'teacher_id'   => "ALTER TABLE timetable ADD COLUMN teacher_id INT(11) NULL AFTER subject_id",
        'term_id'      => "ALTER TABLE timetable ADD COLUMN term_id INT(11) NOT NULL AFTER academic_year_id",
        'start_time'   => "ALTER TABLE timetable ADD COLUMN start_time TIME NULL AFTER period_no",
        'end_time'     => "ALTER TABLE timetable ADD COLUMN end_time TIME NULL AFTER start_time",
        'is_break'     => "ALTER TABLE timetable ADD COLUMN is_break TINYINT(1) DEFAULT 0 AFTER end_time",
        'break_label'  => "ALTER TABLE timetable ADD COLUMN break_label VARCHAR(30) NULL AFTER is_break",
        'created_at'   => "ALTER TABLE timetable ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($additions as $col => $sql) {
        if (!isset($existing[$col])) @mysqli_query($conn, $sql);
    }

    foreach (['subject_id', 'teacher_id'] as $col) {
        if (isset($existing[$col]) && strtoupper((string)$existing[$col]['Null']) === 'NO') {
            @mysqli_query($conn, "ALTER TABLE timetable MODIFY COLUMN {$col} INT(11) NULL");
        }
    }

    if (isset($existing['day_of_week'])) {
        $type = strtolower((string)$existing['day_of_week']['Type']);
        if (strpos($type, "'sat'") === false || strpos($type, "'mon'") === false) {
            @mysqli_query(
                $conn,
                "ALTER TABLE timetable
                 MODIFY COLUMN day_of_week ENUM('Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL"
            );
        }
    }
}

ensureTimetableSchema($conn);


/* =========================================================================
   ACTIVE ACADEMIC YEAR + TERM
   ========================================================================= */

$active_year    = null;
$active_year_id = 0;

$res = mysqli_query(
    $conn,
    "SELECT academic_year_id, year FROM academic_years
     WHERE status = 'active' ORDER BY year DESC LIMIT 1"
);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $active_year    = $row;
    $active_year_id = (int)$row['academic_year_id'];
}

$active_term      = null;
$active_term_id   = 0;
$active_term_name = '';

if ($active_year_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT term_id, term_name FROM terms
         WHERE academic_year_id = ? AND status = 'active'
         ORDER BY FIELD(term_name,'Term 1','Term 2','Term 3') ASC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $active_year_id);
    mysqli_stmt_execute($stmt);
    $active_term = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($active_term) {
        $active_term_id   = (int)$active_term['term_id'];
        $active_term_name = $active_term['term_name'];
    }
}


/* =========================================================================
   LOAD CLASSES
   ========================================================================= */

$classes = [];
$res = mysqli_query(
    $conn,
    "SELECT class_id, class_name, stream, class_level
     FROM classes
     WHERE status = 'active'
     ORDER BY class_level ASC, class_name ASC, stream ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $row['label'] = $row['class_name'] . ($row['stream'] ? ' - ' . $row['stream'] : '');
        $classes[(int)$row['class_id']] = $row;
    }
}


/* =========================================================================
   LOAD SUBJECTS — MASTER LIST (used for lookups by id)
   ========================================================================= */

$subjects = [];
$res = mysqli_query(
    $conn,
    "SELECT subject_id, subject_name, subject_type FROM subjects
     ORDER BY subject_name ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $subjects[(int)$row['subject_id']] = $row;
    }
}


/* =========================================================================
   LOAD CLASS → SUBJECTS  (STRICTLY from class_subjects) — SOURCE OF TRUTH
   ========================================================================= */

$subjects_by_class = [];   // [class_id => [subject_id => subject_row]]

$res = mysqli_query(
    $conn,
    "SELECT cs.class_id, cs.subject_id,
            s.subject_name, s.subject_type
     FROM class_subjects cs
     INNER JOIN subjects s ON s.subject_id = cs.subject_id
     ORDER BY cs.class_id ASC, s.subject_name ASC"
);

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $cid = (int)$row['class_id'];
        $sid = (int)$row['subject_id'];

        if (!isset($subjects_by_class[$cid])) $subjects_by_class[$cid] = [];

        $subjects_by_class[$cid][$sid] = [
            'subject_id'   => $sid,
            'subject_name' => $row['subject_name'],
            'subject_type' => $row['subject_type'] ?? 'academic',
        ];
    }
    mysqli_free_result($res);
}


/* =========================================================================
   LOAD TEACHER ASSIGNMENTS  →  teacher per [class_id][subject_id]
   ========================================================================= */

$assign_by_class_subject = [];  // [class_id][subject_id] = [teacher rows...]

if ($active_year_id > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT ta.class_id, ta.subject_id, ta.teacher_id,
                u.first_name, u.middle_name, u.last_name
         FROM teacher_assignments ta
         INNER JOIN teachers t ON t.teacher_id = ta.teacher_id
         INNER JOIN users    u ON u.user_id    = t.user_id
         WHERE ta.academic_year_id = ?
           AND ta.status = 'active'
           AND u.status = 'active'"
    );
    mysqli_stmt_bind_param($stmt, 'i', $active_year_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['full_name'] = trim(
            $row['first_name'] . ' ' .
            (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') .
            $row['last_name']
        );
        $assign_by_class_subject
            [(int)$row['class_id']]
            [(int)$row['subject_id']][] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   HANDLE POST
   ========================================================================= */

$flash = $_SESSION['tt_flash'] ?? null;
unset($_SESSION['tt_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------------
       CLEAR
    ------------------------------------------------------------- */
    if ($action === 'clear') {

        if (!$active_year || !$active_term) {
            redirect_with_flash('error', 'No active academic year/term.');
        }

        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM timetable WHERE academic_year_id = ? AND term_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $active_year_id, $active_term_id);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        redirect_with_flash('success', "Cleared {$deleted} timetable slot(s).");
    }


    /* =============================================================
       AUTOMATIC GENERATION
       Subjects come ONLY from class_subjects.
       Teachers come from teacher_assignments.
       No repeats in the same day.
       When subjects run out OR no teacher is free → slot = Free.
       ============================================================= */
    if ($action === 'auto_generate') {

        if (!$active_year || !$active_term) {
            redirect_with_flash('error', 'No active academic year/term.');
        }

        $days_input   = $_POST['days'] ?? ['Mon','Tue','Wed','Thu','Fri'];
        $days_allowed = ['Mon','Tue','Wed','Thu','Fri','Sat'];
        $days = array_values(array_intersect($days_allowed, (array)$days_input));
        if (empty($days)) {
            redirect_with_flash('error', 'Select at least one day.');
        }

        $day_start_s = trim($_POST['day_start'] ?? '08:00');
        $day_end_s   = trim($_POST['day_end']   ?? '16:00');
        $lesson_min  = max(15, min(120, (int)($_POST['lesson_min'] ?? 40)));

        if (!preg_match('/^(\d{1,2}):(\d{2})/', $day_start_s, $ms)) {
            redirect_with_flash('error', 'Invalid school day start time.');
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $day_end_s, $me)) {
            redirect_with_flash('error', 'Invalid school day end time.');
        }

        $day_start_min = ((int)$ms[1]) * 60 + (int)$ms[2];
        $day_end_min   = ((int)$me[1]) * 60 + (int)$me[2];

        if ($day_end_min <= $day_start_min) {
            redirect_with_flash('error', 'School day end must be after the start.');
        }

        $break1_after = max(0, (int)($_POST['break1_after'] ?? 0));
        $break1_min   = max(0, (int)($_POST['break1_min']   ?? 0));
        $break1_label = trim($_POST['break1_label'] ?? 'Break');

        $break2_after = max(0, (int)($_POST['break2_after'] ?? 0));
        $break2_min   = max(0, (int)($_POST['break2_min']   ?? 0));
        $break2_label = trim($_POST['break2_label'] ?? 'Lunch');

        $breaks_after = [];
        if ($break1_min > 0 && $break1_after > 0) {
            $breaks_after[$break1_after] = ['min' => $break1_min, 'label' => $break1_label ?: 'Break'];
        }
        if ($break2_min > 0 && $break2_after > 0) {
            $breaks_after[$break2_after] = ['min' => $break2_min, 'label' => $break2_label ?: 'Lunch'];
        }
        ksort($breaks_after);

        $slots       = [];
        $cursor      = $day_start_min;
        $period_no   = 1;
        $lesson_idx  = 0;
        $safety      = 0;

        while ($cursor < $day_end_min && $safety < 200) {
            $safety++;

            if ($lesson_idx > 0 && isset($breaks_after[$lesson_idx])) {
                $bdur = $breaks_after[$lesson_idx]['min'];
                $bend = $cursor + $bdur;
                if ($bend <= $day_end_min) {
                    $slots[] = [
                        'no'    => $period_no++,
                        'start' => $cursor,
                        'end'   => $bend,
                        'break' => true,
                        'label' => $breaks_after[$lesson_idx]['label'],
                    ];
                    $cursor = $bend;
                    unset($breaks_after[$lesson_idx]);
                    continue;
                }
            }

            $lend = $cursor + $lesson_min;
            if ($lend > $day_end_min) break;

            $slots[] = [
                'no'    => $period_no++,
                'start' => $cursor,
                'end'   => $lend,
                'break' => false,
                'label' => '',
            ];
            $cursor = $lend;
            $lesson_idx++;
        }

        if (empty($slots)) {
            redirect_with_flash('error', 'Could not fit any periods in the given window.');
        }

        $lesson_count = 0;
        foreach ($slots as $s) if (!$s['break']) $lesson_count++;
        if ($lesson_count === 0) {
            redirect_with_flash('error', 'No lessons fit — extend the day or shorten the lesson.');
        }

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM timetable WHERE academic_year_id = ? AND term_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $active_year_id, $active_term_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $busy = [];

            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO timetable
                    (class_id, subject_id, teacher_id, academic_year_id, term_id,
                     day_of_week, period_no, start_time, end_time, is_break, break_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$ins) throw new Exception("Failed to prepare insert: " . mysqli_error($conn));

            $inserted        = 0;
            $skipped_classes = 0;
            $free_slots      = 0;

            foreach ($classes as $class_id => $c) {

                /* ✅ Subjects for this class come ONLY from class_subjects */
                $class_subject_rows = $subjects_by_class[$class_id] ?? [];
                $subject_ids        = array_keys($class_subject_rows);

                if (empty($subject_ids)) {
                    $skipped_classes++;
                    continue;
                }

                $cursor_i = 0;   // rotation pointer across days

                foreach ($days as $day) {

                    $used_today = [];   // prevent repeating a subject in the same day

                    foreach ($slots as $slot) {

                        $is_break   = $slot['break'] ? 1 : 0;
                        $subject_id = null;
                        $teacher_id = null;
                        $label      = $slot['label'];

                        if (!$is_break) {

                            $total  = count($subject_ids);
                            $picked = null;

                            /* Try every subject starting from the cursor */
                            for ($k = 0; $k < $total; $k++) {
                                $sid = (int)$subject_ids[($cursor_i + $k) % $total];

                                /* Skip subjects already used today */
                                if (!empty($used_today[$sid])) continue;

                                $candidates = $assign_by_class_subject[$class_id][$sid] ?? [];

                                foreach ($candidates as $cand) {
                                    $tid = (int)$cand['teacher_id'];
                                    if (empty($busy[$tid][$day][$slot['no']])) {
                                        $picked = ['subject_id' => $sid, 'teacher_id' => $tid];
                                        $cursor_i = ($cursor_i + $k + 1) % $total;
                                        break 2;
                                    }
                                }
                            }

                            if ($picked) {
                                $subject_id = $picked['subject_id'];
                                $teacher_id = $picked['teacher_id'];
                                $used_today[$subject_id] = true;
                                $busy[$teacher_id][$day][$slot['no']] = true;
                            } else {
                                /* No subject left today OR no teacher free → Free slot */
                                $free_slots++;
                            }
                        }

                        $start_time = minutes_to_time($slot['start']);
                        $end_time   = minutes_to_time($slot['end']);

                        mysqli_stmt_bind_param(
                            $ins,
                            'iiiiisissis',
                            $class_id,
                            $subject_id,
                            $teacher_id,
                            $active_year_id,
                            $active_term_id,
                            $day,
                            $slot['no'],
                            $start_time,
                            $end_time,
                            $is_break,
                            $label
                        );

                        if (mysqli_stmt_execute($ins)) {
                            $inserted++;
                        } else {
                            throw new Exception(
                                "Insert failed (class {$class_id}, {$day}, P{$slot['no']}): "
                                . mysqli_stmt_error($ins)
                            );
                        }
                    }
                }
            }

            mysqli_stmt_close($ins);
            mysqli_commit($conn);

            $msg = "Timetable generated: {$inserted} slots across "
                 . (count($classes) - $skipped_classes) . " class(es), "
                 . "{$lesson_count} lesson" . ($lesson_count === 1 ? '' : 's') . " per day.";

            if ($free_slots > 0) {
                $msg .= " {$free_slots} slot(s) left free (no subject/teacher available).";
            }
            if ($skipped_classes > 0) {
                $msg .= " {$skipped_classes} class(es) skipped — no subjects in class_subjects.";
            }

            redirect_with_flash('success', $msg);

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Generation failed: ' . $ex->getMessage());
        }
    }


    /* =============================================================
       MANUAL save
       ============================================================= */
    if ($action === 'save_manual') {

        if (!$active_year || !$active_term) {
            redirect_with_flash('error', 'No active academic year/term.');
        }

        $class_id = (int)($_POST['class_id'] ?? 0);
        if (!isset($classes[$class_id])) {
            redirect_with_flash('error', 'Invalid class.');
        }

        $grid = $_POST['slot'] ?? [];
        if (empty($grid) || !is_array($grid)) {
            redirect_with_flash('error', 'No grid submitted.');
        }

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare(
                $conn,
                "DELETE FROM timetable
                 WHERE class_id = ? AND academic_year_id = ? AND term_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'iii',
                $class_id, $active_year_id, $active_term_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO timetable
                    (class_id, subject_id, teacher_id, academic_year_id, term_id,
                     day_of_week, period_no, start_time, end_time, is_break, break_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$ins) throw new Exception("Failed to prepare insert: " . mysqli_error($conn));

            $inserted = 0;

            foreach ($grid as $day => $periods) {
                if (!in_array($day, ['Mon','Tue','Wed','Thu','Fri','Sat'], true)) continue;

                foreach ($periods as $period_no => $cell) {
                    $period_no  = (int)$period_no;
                    $subject_id = (int)($cell['subject_id'] ?? 0) ?: null;
                    $teacher_id = (int)($cell['teacher_id'] ?? 0) ?: null;
                    $is_break   = (int)!empty($cell['is_break']);
                    $label      = trim((string)($cell['label'] ?? ''));
                    $start      = trim((string)($cell['start'] ?? ''));
                    $end        = trim((string)($cell['end']   ?? ''));

                    if ($start !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) $start = '';
                    if ($end   !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end))   $end   = '';

                    /* Auto-fill teacher from teacher_assignments when not set */
                    if ($subject_id && !$teacher_id) {
                        $candidates = $assign_by_class_subject[$class_id][$subject_id] ?? [];
                        if (!empty($candidates)) {
                            $teacher_id = (int)$candidates[0]['teacher_id'];
                        }
                    }

                    mysqli_stmt_bind_param(
                        $ins, 'iiiiisissis',
                        $class_id, $subject_id, $teacher_id,
                        $active_year_id, $active_term_id,
                        $day, $period_no, $start, $end,
                        $is_break, $label
                    );

                    if (mysqli_stmt_execute($ins)) $inserted++;
                }
            }
            mysqli_stmt_close($ins);
            mysqli_commit($conn);
            redirect_with_flash('success', "Saved {$inserted} slots for this class.");

        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            redirect_with_flash('error', 'Save failed: ' . $ex->getMessage());
        }
    }
}


/* =========================================================================
   LOAD SAVED TIMETABLE FOR MANUAL EDITING (single class)
   ========================================================================= */

$sel_class_id = (int)($_GET['class_id'] ?? 0);
if ($sel_class_id === 0 && !empty($classes)) {
    $sel_class_id = (int) array_key_first($classes);
}
if ($sel_class_id > 0 && !isset($classes[$sel_class_id])) $sel_class_id = 0;

$saved_by_day = [];

if ($sel_class_id && $active_year_id && $active_term_id) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT day_of_week, period_no, subject_id, teacher_id,
                start_time, end_time, is_break, break_label
         FROM timetable
         WHERE class_id = ? AND academic_year_id = ? AND term_id = ?
         ORDER BY FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), period_no ASC"
    );
    mysqli_stmt_bind_param($stmt, 'iii',
        $sel_class_id, $active_year_id, $active_term_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $saved_by_day[$row['day_of_week']][(int)$row['period_no']] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   LOAD SCHOOL TIMETABLE (all classes)
   ========================================================================= */

$school_tt     = [];
$teacher_names = [];

if ($active_year_id && $active_term_id) {

    $res = mysqli_query($conn,
        "SELECT t.teacher_id, u.first_name, u.middle_name, u.last_name
         FROM teachers t
         INNER JOIN users u ON u.user_id = t.user_id"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $teacher_names[(int)$row['teacher_id']] = trim(
                $row['first_name'] . ' ' .
                (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') .
                $row['last_name']
            );
        }
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT class_id, day_of_week, period_no, subject_id, teacher_id,
                start_time, end_time, is_break, break_label
         FROM timetable
         WHERE academic_year_id = ? AND term_id = ?
         ORDER BY class_id ASC,
                  FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat') ASC,
                  period_no ASC"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $active_year_id, $active_term_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $school_tt
            [(int)$row['class_id']]
            [$row['day_of_week']]
            [(int)$row['period_no']] = $row;
    }
    mysqli_stmt_close($stmt);
}


/* =========================================================================
   STATS
   ========================================================================= */

$total_slots = 0;
if ($active_year_id && $active_term_id) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS c FROM timetable WHERE academic_year_id = ? AND term_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $active_year_id, $active_term_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $total_slots = (int)($r['c'] ?? 0);
}

$days_full = ['Mon','Tue','Wed','Thu','Fri'];
$days_full_map = [
    'Mon' => 'Monday',
    'Tue' => 'Tuesday',
    'Wed' => 'Wednesday',
    'Thu' => 'Thursday',
    'Fri' => 'Friday',
    'Sat' => 'Saturday',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#17233c">
    <title>Timetable Builder | PSRMS</title>

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

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .page-title h1 { color: var(--navy); font-size: 25px; font-weight: 700; }
        .page-title p  { color: var(--muted); font-size: 12.5px; margin-top: 5px; }

        .badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 15px; border-radius: 20px;
            font-size: 11.5px; font-weight: 750;
            background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green);
        }
        .badge.gold { background: var(--gold); border-color: var(--gold-light); color: var(--navy); }
        .badge.missing { background: var(--orange-bg); border-color: #ecd9a8; color: var(--orange); }

        .alert {
            border-radius: 8px; padding: 12px 15px; margin-bottom: 18px;
            font-size: 12.5px; font-weight: 600; line-height: 1.55;
        }
        .alert.success { background: var(--green-bg); border: 1px solid #cfe5d7; color: var(--green); }
        .alert.error   { background: var(--red-bg);   border: 1px solid #efd2d2; color: var(--red); }

        .mode-switch {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
            margin-bottom: 22px;
        }
        .mode-btn {
            background: var(--white); border: 2px solid var(--border);
            border-radius: 12px; padding: 18px 22px; cursor: pointer;
            display: flex; align-items: center; gap: 14px;
            font-family: inherit; text-align: left; transition: .15s ease;
        }
        .mode-btn:hover { border-color: var(--gold); }
        .mode-btn.active { border-color: var(--navy); background: var(--navy); color: #fff; }
        .mode-icon {
            width: 42px; height: 42px; border-radius: 10px;
            background: #eef2f7; color: var(--navy);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 800; flex-shrink: 0;
        }
        .mode-btn.active .mode-icon { background: var(--gold); color: var(--navy); }
        .mode-title { font-size: 14px; font-weight: 750; margin-bottom: 3px; }
        .mode-sub   { font-size: 11.5px; opacity: .75; }
        .mode-btn.active .mode-sub { opacity: .85; color: #cfd4dc; }

        .panel {
            background: var(--white); border: 1px solid var(--border);
            border-radius: 12px; padding: 22px; margin-bottom: 20px;
        }
        .panel h2 {
            color: var(--navy); font-size: 15px; font-weight: 750;
            margin-bottom: 8px;
        }
        .panel .sub {
            color: var(--muted); font-size: 12.5px; margin-bottom: 18px;
            line-height: 1.55;
        }

        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .form-grid.three { grid-template-columns: repeat(3, 1fr); }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { min-width: 0; }
        .form-group label {
            display: block; color: var(--navy); font-size: 10.5px;
            font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
            margin-bottom: 6px;
        }
        .form-control {
            width: 100%; height: 44px; border: 1px solid var(--border);
            border-radius: 8px; padding: 0 12px; font-family: inherit;
            font-size: 13.5px; background: #fcfcfd; color: var(--text);
            outline: none;
        }
        .form-control:focus {
            border-color: var(--gold); background: var(--white);
            box-shadow: 0 0 0 3px rgba(201,162,39,.12);
        }

        .days-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .day-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 14px; border-radius: 20px;
            background: #fff; border: 1px solid var(--border);
            font-size: 12.5px; font-weight: 700; cursor: pointer;
            user-select: none;
        }
        .day-pill input { display: none; }
        .day-pill.checked { background: var(--navy); color: #fff; border-color: var(--navy); }
        .day-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: #cfd4dc; }
        .day-pill.checked .dot { background: var(--gold); }

        .break-block {
            background: #fafbfd; border: 1px solid var(--border);
            border-radius: 10px; padding: 14px; margin-top: 12px;
        }
        .break-block h3 {
            font-size: 12px; color: var(--navy); margin-bottom: 10px;
            display: flex; align-items: center; gap: 8px;
        }
        .break-block h3 .pin {
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--gold); color: var(--navy);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800;
        }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 7px; min-height: 46px; padding: 0 20px;
            border: none; border-radius: 8px;
            font-family: inherit; font-size: 13px; font-weight: 700;
            cursor: pointer; text-decoration: none; white-space: nowrap;
            transition: .15s ease;
        }
        .btn-primary { background: var(--navy); color: var(--white); }
        .btn-primary:hover { background: var(--navy-dark); }
        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover { background: var(--gold-light); }
        .btn-danger { background: var(--red); color: #fff; }
        .btn-danger:hover { background: #8a3d3d; }
        .btn-ghost { background: #fff; color: var(--navy); border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--gold); }

        .action-row {
            display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px;
        }

        .class-picker {
            display: flex; gap: 12px; align-items: end; margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .class-picker .sel-wrap { flex: 1; min-width: 200px; }

        .tt-grid-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; border: 1px solid var(--border); }
        .tt-grid {
            width: 100%;
            min-width: 900px;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
        }
        .tt-grid th, .tt-grid td {
            padding: 8px 10px;
            border-right: 1px solid #f0f1f3;
            border-bottom: 1px solid #f0f1f3;
            font-size: 12px;
            vertical-align: top;
        }
        .tt-grid th:last-child, .tt-grid td:last-child { border-right: none; }
        .tt-grid thead th {
            background: #fafaf8;
            color: var(--muted);
            font-size: 10px;
            font-weight: 750;
            letter-spacing: .6px;
            text-transform: uppercase;
            text-align: center;
            position: sticky;
            top: 0;
        }
        .tt-grid .row-head {
            background: #fafaf8;
            color: var(--navy);
            font-weight: 750;
            text-align: center;
            font-size: 11.5px;
            white-space: nowrap;
            width: 110px;
        }
        .tt-grid .row-head small {
            display: block;
            font-size: 9.5px;
            color: var(--muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .cell {
            display: flex; flex-direction: column; gap: 6px;
        }
        .cell select {
            width: 100%;
            height: 34px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0 8px;
            font-family: inherit;
            font-size: 11.5px;
            background: #fff;
            outline: none;
        }
        .cell select:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(201,162,39,.12);
        }

        .cell.break-cell {
            text-align: center;
            font-size: 11px;
            font-weight: 750;
            color: var(--orange);
            background: var(--orange-bg);
            border-radius: 6px;
            padding: 8px;
        }

        .tt-cell-view {
            padding: 6px 8px;
            border-radius: 6px;
            background: #f5f7fb;
            border-left: 3px solid var(--blue);
            min-height: 44px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 2px;
        }
        .tt-cell-view .subj {
            font-size: 11.5px;
            font-weight: 750;
            color: var(--navy);
            line-height: 1.25;
        }
        .tt-cell-view .teach {
            font-size: 10px;
            color: var(--muted);
            font-weight: 600;
            line-height: 1.25;
        }

        .tt-cell-view.is-break {
            background: var(--orange-bg);
            border-left-color: var(--orange);
            text-align: center;
            color: var(--orange);
            font-weight: 750;
            font-size: 11px;
        }
        .tt-cell-view.is-empty {
            background: #fafbfd;
            border-left-color: #d5d9e0;
            color: #b0b6c1;
            text-align: center;
            font-style: italic;
            font-size: 10.5px;
        }

        .school-tt {
            display: grid;
            grid-template-columns: 1fr;
            gap: 22px;
        }
        .class-tt-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .class-tt-card header {
            background: var(--navy);
            color: #fff;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .class-tt-card header h3 {
            font-size: 14px;
            font-weight: 750;
            letter-spacing: .3px;
        }
        .class-tt-card header .meta {
            font-size: 11px;
            opacity: .85;
            font-weight: 600;
        }
        .class-tt-card .tt-grid-wrap { border-radius: 0; border: none; }

        .empty-state {
            padding: 60px 20px; text-align: center; color: var(--muted);
            font-size: 13px;
            background: var(--white); border: 1px solid var(--border); border-radius: 10px;
        }
        .empty-state .icon {
            width: 60px; height: 60px; margin: 0 auto 14px;
            border-radius: 50%; background: #f3f2ed; color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
        }
        .empty-state h3 { color: var(--navy); font-size: 15px; margin-bottom: 5px; }

        @media (max-width: 900px) {
            .mode-switch { grid-template-columns: 1fr; }
            .form-grid,
            .form-grid.three { grid-template-columns: 1fr 1fr; }
            .form-grid .full { grid-column: 1 / -1; }
        }

        @media (max-width: 800px) {
            .main-content {
                margin-left: 0;
                padding: calc(var(--topbar-h) + 20px) 16px 30px;
            }
            body.sidebar-collapsed .main-content { margin-left: 0; }

            .page-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .page-title h1 { font-size: 21px; }
            .page-title p  { font-size: 12px; }

            .panel { padding: 18px; }

            .form-grid,
            .form-grid.three { grid-template-columns: 1fr; gap: 12px; }

            .btn { min-height: 46px; font-size: 14px; width: 100%; }
            .action-row .btn { width: 100%; }
        }

        @media (max-width: 500px) {
            .day-pill { padding: 8px 12px; font-size: 12px; }
            .class-tt-card header { padding: 12px 14px; }
            .class-tt-card header h3 { font-size: 13px; }
        }

        @media (max-width: 800px) {
            @supports (padding: max(0px)) {
                .main-content {
                    padding-left:  max(14px, env(safe-area-inset-left));
                    padding-right: max(14px, env(safe-area-inset-right));
                    padding-bottom: max(30px, env(safe-area-inset-bottom));
                }
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
$topbar_title    = 'Timetable Builder';
$topbar_subtitle = 'Build the school timetable';
include '../includes/topbar.php';
?>

<?php include 'teacher_sidebar.php'; ?>

<main class="main-content with-topbar">

    <div class="page-header">
        <div class="page-title">
            <h1>Timetable Builder</h1>
            <p>Create the whole school timetable manually, or generate it automatically.</p>
        </div>

        <div class="badges">
            <?php if ($active_year): ?>
                <span class="badge">Year: <?php echo e($active_year['year']); ?></span>
            <?php else: ?>
                <span class="badge missing">No active year</span>
            <?php endif; ?>

            <?php if ($active_term): ?>
                <span class="badge gold">Term: <?php echo e($active_term_name); ?></span>
            <?php else: ?>
                <span class="badge missing">No active term</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (!$active_year || !$active_term): ?>

        <div class="alert error">
            The timetable needs an active academic year AND an active term.
            Set them in <strong>Academic Years</strong> first.
        </div>

    <?php elseif (empty($classes)): ?>

        <div class="empty-state">
            <div class="icon">🏫</div>
            <h3>No active classes</h3>
            <p>Add classes first before building a timetable.</p>
        </div>

    <?php else: ?>

        <div class="mode-switch">
            <button type="button" class="mode-btn active" id="modeBtnManual"
                    onclick="setMode('manual')">
                <div class="mode-icon">✍️</div>
                <div>
                    <div class="mode-title">Manual</div>
                    <div class="mode-sub">Pick subject &amp; teacher for each slot, class by class.</div>
                </div>
            </button>

            <button type="button" class="mode-btn" id="modeBtnAuto"
                    onclick="setMode('auto')">
                <div class="mode-icon">⚡</div>
                <div>
                    <div class="mode-title">Automatic</div>
                    <div class="mode-sub">Enter times &amp; breaks — we build the whole timetable for you.</div>
                </div>
            </button>
        </div>


        <!-- AUTOMATIC MODE -->
        <section class="panel" id="panelAuto" style="display:none;">
            <h2>Automatic Timetable Generation</h2>
            <p class="sub">
                Set the school day window and lesson length. The generator will fit as many
                periods as possible into that window, inserting your breaks at the right place.
                Each class's subjects come <strong>only</strong> from its <strong>class_subjects</strong> list.
                No subject is repeated on the same day.
            </p>

            <form method="POST" action="manage_timetable.php" id="autoForm"
                  onsubmit="return confirm('This will REPLACE the existing timetable for the current term. Continue?');">
                <input type="hidden" name="action" value="auto_generate">

                <div class="form-grid three">
                    <div class="form-group">
                        <label>School Day Starts</label>
                        <input type="time" name="day_start" class="form-control"
                               value="08:00" required>
                    </div>

                    <div class="form-group">
                        <label>School Day Ends</label>
                        <input type="time" name="day_end" class="form-control"
                               value="16:00" required>
                    </div>

                    <div class="form-group">
                        <label>Lesson Duration (min)</label>
                        <input type="number" name="lesson_min" class="form-control"
                               min="15" max="120" value="40" required>
                    </div>
                </div>

                <div style="margin-top:18px;">
                    <label style="display:block;color:var(--navy);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                        Days of the week
                    </label>
                    <div class="days-row">
                        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                            <label class="day-pill <?php echo in_array($d, $days_full, true) ? 'checked' : ''; ?>">
                                <input type="checkbox" name="days[]" value="<?php echo $d; ?>"
                                    <?php echo in_array($d, $days_full, true) ? 'checked' : ''; ?>>
                                <span class="dot"></span>
                                <?php echo e($days_full_map[$d]); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="break-block" style="margin-top:18px;">
                    <h3><span class="pin">1</span>Morning Break (set duration to 0 to skip)</h3>
                    <div class="form-grid three">
                        <div class="form-group">
                            <label>After Lesson #</label>
                            <input type="number" name="break1_after" class="form-control"
                                   min="0" max="20" value="3">
                        </div>
                        <div class="form-group">
                            <label>Duration (min)</label>
                            <input type="number" name="break1_min" class="form-control"
                                   min="0" max="90" value="20">
                        </div>
                        <div class="form-group">
                            <label>Label</label>
                            <input type="text" name="break1_label" class="form-control"
                                   value="Break" maxlength="30">
                        </div>
                    </div>
                </div>

                <div class="break-block">
                    <h3><span class="pin">2</span>Lunch Break (set duration to 0 to skip)</h3>
                    <div class="form-grid three">
                        <div class="form-group">
                            <label>After Lesson #</label>
                            <input type="number" name="break2_after" class="form-control"
                                   min="0" max="20" value="6">
                        </div>
                        <div class="form-group">
                            <label>Duration (min)</label>
                            <input type="number" name="break2_min" class="form-control"
                                   min="0" max="120" value="40">
                        </div>
                        <div class="form-group">
                            <label>Label</label>
                            <input type="text" name="break2_label" class="form-control"
                                   value="Lunch" maxlength="30">
                        </div>
                    </div>
                </div>

                <div style="font-size:12px;color:var(--muted);margin-top:14px;line-height:1.55;">
                    <strong>Tip:</strong> Set both breaks to 0 for a morning-only session,
                    or set the end time to <strong>14:30</strong> for a half-day.
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-gold">⚡ Generate Timetable</button>
                </div>
            </form>

            <div class="action-row" style="border-top:1px dashed var(--border); padding-top:16px; margin-top:16px;">
                <form method="POST" action="manage_timetable.php"
                      onsubmit="return confirm('Clear the entire timetable for this term?');"
                      style="display:inline;">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-danger">🗑 Clear Whole Timetable</button>
                </form>
                <span style="font-size:12px;color:var(--muted);align-self:center;">
                    Currently: <strong><?php echo number_format($total_slots); ?></strong> slots stored.
                </span>
            </div>
        </section>


        <!-- MANUAL MODE -->
        <section class="panel" id="panelManual">
            <h2>Manual Timetable Builder</h2>
            <p class="sub">
                Pick a class, then fill each day's periods. Only subjects assigned to that
                class via <strong>class_subjects</strong> are shown in the dropdown.
            </p>

            <form method="GET" action="manage_timetable.php" class="class-picker">
                <div class="sel-wrap">
                    <label style="display:block;color:var(--navy);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                        Class
                    </label>
                    <select name="class_id" class="form-control"
                            onchange="this.form.submit()">
                        <?php foreach ($classes as $cid => $c): ?>
                            <option value="<?php echo $cid; ?>"
                                <?php echo $sel_class_id === $cid ? 'selected' : ''; ?>>
                                <?php echo e($c['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Load</button>
                </div>
            </form>

            <?php if ($sel_class_id && !empty($saved_by_day)): ?>
                <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">
                    This class already has a saved timetable — editing will overwrite it.
                </div>
            <?php endif; ?>

            <?php if ($sel_class_id): ?>
                <form method="POST" action="manage_timetable.php">
                    <input type="hidden" name="action" value="save_manual">
                    <input type="hidden" name="class_id" value="<?php echo $sel_class_id; ?>">

                    <div class="tt-grid-wrap">
                        <table class="tt-grid">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <?php foreach ($days_full as $d): ?>
                                        <th><?php echo e($days_full_map[$d]); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $max_period = 8;
                                if (!empty($saved_by_day)) {
                                    foreach ($saved_by_day as $day => $periods) {
                                        foreach (array_keys($periods) as $p) {
                                            if ($p > $max_period) $max_period = $p;
                                        }
                                    }
                                }

                                for ($p = 1; $p <= $max_period; $p++):
                                ?>
                                    <tr>
                                        <td class="row-head">Period <?php echo $p; ?></td>
                                        <?php foreach ($days_full as $d):
                                            $cell     = $saved_by_day[$d][$p] ?? null;
                                            $is_break = $cell && (int)$cell['is_break'] === 1;
                                            $start    = $cell['start_time'] ?? '';
                                            $end      = $cell['end_time']   ?? '';
                                        ?>
                                            <td>
                                                <?php if ($is_break): ?>

                                                    <div class="cell break-cell">
                                                        <?php echo e($cell['break_label'] ?: 'Break'); ?><br>
                                                        <small><?php echo e(hhmm($start)); ?> – <?php echo e(hhmm($end)); ?></small>
                                                        <input type="hidden" name="slot[<?php echo $d; ?>][<?php echo $p; ?>][is_break]" value="1">
                                                        <input type="hidden" name="slot[<?php echo $d; ?>][<?php echo $p; ?>][label]" value="<?php echo e($cell['break_label'] ?? 'Break'); ?>">
                                                        <input type="hidden" name="slot[<?php echo $d; ?>][<?php echo $p; ?>][start]" value="<?php echo e($start); ?>">
                                                        <input type="hidden" name="slot[<?php echo $d; ?>][<?php echo $p; ?>][end]"   value="<?php echo e($end); ?>">
                                                    </div>

                                                <?php else: ?>

                                                    <div class="cell">
                                                        <select name="slot[<?php echo $d; ?>][<?php echo $p; ?>][subject_id]">
                                                            <option value="">— Free —</option>
                                                            <?php
                                                            /* ✅ Subjects come ONLY from class_subjects */
                                                            $class_subjects = $subjects_by_class[$sel_class_id] ?? [];
                                                            foreach ($class_subjects as $sid => $subj):
                                                                $selected = $cell && (int)$cell['subject_id'] === (int)$sid;
                                                            ?>
                                                                <option value="<?php echo (int)$sid; ?>"
                                                                    <?php echo $selected ? 'selected' : ''; ?>>
                                                                    <?php echo e($subj['subject_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>

                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="action-row">
                        <button type="submit" class="btn btn-primary">Save This Class</button>
                    </div>
                </form>
            <?php endif; ?>

        </section>


        <!-- SCHOOL TIMETABLE VIEW -->
        <section class="panel" id="panelSchool">
            <h2>School Timetable — All Classes</h2>
            <p class="sub">
                Below is the timetable for every class for
                <strong><?php echo e($active_term_name); ?></strong>
                · <strong><?php echo e($active_year['year']); ?></strong>.
            </p>

            <?php if (empty($school_tt)): ?>

                <div class="empty-state">
                    <div class="icon">📭</div>
                    <h3>No timetable yet</h3>
                    <p>Use the Manual or Automatic mode above to create one.</p>
                </div>

            <?php else: ?>

                <div class="school-tt">

                    <?php foreach ($classes as $cid => $c):

                        if (!isset($school_tt[$cid])) continue;

                        $grid = $school_tt[$cid];

                        $class_max_period = 0;
                        foreach ($grid as $day => $periods) {
                            foreach (array_keys($periods) as $p) {
                                if ($p > $class_max_period) $class_max_period = $p;
                            }
                        }

                        $reference_day = isset($grid['Mon']) ? 'Mon' : array_key_first($grid);
                        $period_labels = [];
                        foreach (($grid[$reference_day] ?? []) as $p => $row) {
                            $period_labels[$p] = [
                                'start' => hhmm($row['start_time']),
                                'end'   => hhmm($row['end_time']),
                                'break' => (int)$row['is_break'] === 1,
                                'label' => $row['break_label'] ?? '',
                            ];
                        }
                    ?>
                        <div class="class-tt-card">

                            <header>
                                <h3><?php echo e($c['label']); ?></h3>
                                <span class="meta">
                                    <?php echo count($grid); ?> day<?php echo count($grid) === 1 ? '' : 's'; ?>
                                    · <?php echo $class_max_period; ?> periods
                                </span>
                            </header>

                            <div class="tt-grid-wrap">
                                <table class="tt-grid">
                                    <thead>
                                        <tr>
                                            <th>Period</th>
                                            <?php foreach ($days_full as $d): ?>
                                                <th><?php echo e($days_full_map[$d]); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($p = 1; $p <= $class_max_period; $p++): ?>
                                            <tr>
                                                <td class="row-head">
                                                    Period <?php echo $p; ?>
                                                    <?php if (isset($period_labels[$p])): ?>
                                                        <small>
                                                            <?php echo e($period_labels[$p]['start']); ?>
                                                            –
                                                            <?php echo e($period_labels[$p]['end']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>

                                                <?php foreach ($days_full as $d):
                                                    $row = $grid[$d][$p] ?? null;
                                                ?>
                                                    <td>
                                                        <?php if (!$row): ?>

                                                            <div class="tt-cell-view is-empty">—</div>

                                                        <?php elseif ((int)$row['is_break'] === 1): ?>

                                                            <div class="tt-cell-view is-break">
                                                                <?php echo e($row['break_label'] ?: 'Break'); ?>
                                                            </div>

                                                        <?php elseif (empty($row['subject_id'])): ?>

                                                            <div class="tt-cell-view is-empty">Free</div>

                                                        <?php else:
                                                            $subj_name = $subjects[(int)$row['subject_id']]['subject_name'] ?? 'Subject';
                                                            $teach_id  = (int)($row['teacher_id'] ?? 0);
                                                            $teach_nm  = $teacher_names[$teach_id] ?? '';
                                                        ?>
                                                            <div class="tt-cell-view">
                                                                <div class="subj"><?php echo e($subj_name); ?></div>
                                                                <?php if ($teach_nm !== ''): ?>
                                                                    <div class="teach"><?php echo e($teach_nm); ?></div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>


<script>
/* MODE SWITCH */
function setMode(mode) {
    const btnManual = document.getElementById('modeBtnManual');
    const btnAuto   = document.getElementById('modeBtnAuto');
    const panelMan  = document.getElementById('panelManual');
    const panelAuto = document.getElementById('panelAuto');

    if (mode === 'auto') {
        btnAuto.classList.add('active');
        btnManual.classList.remove('active');
        panelAuto.style.display = '';
        panelMan.style.display  = 'none';
    } else {
        btnManual.classList.add('active');
        btnAuto.classList.remove('active');
        panelManual.style.display = '';
        panelAuto.style.display   = 'none';
    }
    try { localStorage.setItem('tt_mode', mode); } catch (e) {}
}

(function () {
    let saved = 'manual';
    try { saved = localStorage.getItem('tt_mode') || 'manual'; } catch (e) {}
    setMode(saved === 'auto' ? 'auto' : 'manual');
})();

/* DAY PILLS */
document.querySelectorAll('.day-pill').forEach(function (pill) {
    const input = pill.querySelector('input');
    const sync  = () => pill.classList.toggle('checked', input.checked);
    input.addEventListener('change', sync);
    sync();
});
</script>

</body>
</html>