<?php
// ── user.php — Student portal controller ────────────────────────

// ── DB ──────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'suffratech');
define('DB_USER', 'root');
define('DB_PASS', '');

function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function logUserAction(string $action, string $details, int $studentDbId = 0): void
{
    try {
        db()->prepare(
            "INSERT INTO audit_log(category, action, details, admin_id, created_at)
             VALUES ('Student', :a, :d, :sid, NOW())"
        )->execute([':a' => $action, ':d' => $details, ':sid' => $studentDbId]);
    } catch (Exception $e) {
    }
}

function avatarInitials(string $name): string
{
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0], 0, 1) . substr($parts[1] ?? $parts[0], 0, 1));
}

// ── Session & Auth ──────────────────────────────────────────────
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin.php');
    exit;
}

// ── Handle Logout ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $logStudentId  = $_SESSION['user_id'] ?? 0;
    $logStudentSid = $_SESSION['student_id'] ?? '—';
    $logName       = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    try {
        db()->prepare(
            "INSERT INTO audit_log(category, action, details, admin_id, created_at)
             VALUES ('Student', 'Logout', :d, :sid, NOW())"
        )->execute([':d' => "Student {$logStudentSid} ({$logName}) logged out.", ':sid' => $logStudentId]);
    } catch (Exception $e) {
    }
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$pdo = db();

// ── Ensure schema columns exist ──────────────────────────────────
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN has_voted_clas TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE positions ADD COLUMN election_type VARCHAR(20) NOT NULL DEFAULT 'general'");
} catch (Exception $e) {
}
try {
    $pdo->exec("DELETE FROM positions WHERE name='Course Representative' AND election_type='clas'");
} catch (Exception $e) {
}
// Ensure Prime Minister is CLAS-only — move any SSC/general/unset instances to clas
try {
    $pdo->exec("UPDATE positions SET election_type='clas' WHERE LOWER(name) LIKE '%prime minister%' AND (election_type='general' OR election_type IS NULL OR election_type='')");
} catch (Exception $e) {
}
// Ensure students table has a photo column for profile pictures
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN profile_photo VARCHAR(255) NOT NULL DEFAULT ''");
} catch (Exception $e) {
}

// ── Ensure vote_user_reviews table exists ────────────────────────
// (mirrors vote_user_review.php so the table is always ready)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vote_user_reviews (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        student_db_id   INT NOT NULL,
        student_name    VARCHAR(120) NOT NULL,
        student_id_str  VARCHAR(30)  NOT NULL DEFAULT '',
        rating          TINYINT(1)   NOT NULL DEFAULT 5,
        tags            VARCHAR(255) NOT NULL DEFAULT '',
        review_text     TEXT         NOT NULL,
        election_type   VARCHAR(20)  NOT NULL DEFAULT 'general',
        created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student (student_db_id),
        INDEX idx_created (created_at)
    )");
} catch (Exception $e) {
}

// ── Load student record ──────────────────────────────────────────
$studentStmt = $pdo->prepare("SELECT * FROM students WHERE id = :id");
$studentStmt->execute([':id' => $_SESSION['user_id']]);
/** @var array<string,mixed>|false $studentRow */
$studentRow = $studentStmt->fetch();

if ($studentRow) {
    $_SESSION['has_voted']      = (int)$studentRow['has_voted'];
    $_SESSION['has_voted_clas'] = (int)$studentRow['has_voted_clas'];
}

$user = [
    'first_name'     => $studentRow['first_name']    ?? ($_SESSION['first_name'] ?? 'Juan'),
    'last_name'      => $studentRow['last_name']     ?? ($_SESSION['last_name']  ?? 'Dela Cruz'),
    'student_id'     => $studentRow['student_id']    ?? ($_SESSION['student_id'] ?? '2026-0001'),
    'dob'            => $studentRow['birthdate']     ?? ($_SESSION['dob']        ?? ''),
    'email'          => $studentRow['email']         ?? ($_SESSION['email']      ?? ''),
    'phone'          => $studentRow['phone']         ?? ($_SESSION['phone']      ?? ''),
    'program'        => $studentRow['program']       ?? ($_SESSION['program']    ?? ''),
    'section'        => $studentRow['section']       ?? ($_SESSION['section']    ?? ''),
    'profile_photo'  => $studentRow['profile_photo'] ?? ($_SESSION['profile_photo'] ?? ''),
    'role'           => 'Student Voter',
    'has_voted'      => (bool)($_SESSION['has_voted']      ?? false),
    'has_voted_clas' => (bool)($_SESSION['has_voted_clas'] ?? false),
];

$initials  = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
$full_name = h($user['first_name'] . ' ' . $user['last_name']);

// ── Check if student has already reviewed (to pre-disable form) ──
$reviewedGeneral = false;
$reviewedClas    = false;
try {
    $rvCheck = $pdo->prepare(
        "SELECT election_type FROM vote_user_reviews WHERE student_db_id = :sid"
    );
    $rvCheck->execute([':sid' => (int)$_SESSION['user_id']]);
    foreach ($rvCheck->fetchAll() as $rv) {
        if ($rv['election_type'] === 'general') $reviewedGeneral = true;
        if ($rv['election_type'] === 'clas')    $reviewedClas    = true;
    }
} catch (Exception $e) {
}

// ── Archive helper ───────────────────────────────────────────────
function archiveIfEnded(PDO $pdo, string $type): void
{
    // Use whitelisted table names to satisfy static analysis (no user input here)
    $table  = $type === 'clas' ? 'clas_election_settings' : 'election_settings';
    $vtable = $type === 'clas' ? 'clas_votes'             : 'votes';

    $elStmt = $pdo->query("SELECT * FROM $table LIMIT 1");
    if (!$elStmt) return;
    /** @var array<string,mixed>|false $el */
    $el = $elStmt->fetch();
    if (!$el) return;

    $newSt = $el['status'];
    if ($el['start_dt'] && $el['end_dt']) {
        $now = new DateTime();
        $s   = new DateTime($el['start_dt']);
        $e   = new DateTime($el['end_dt']);
        if ($now >= $s && $now < $e)  $newSt = 'Ongoing';
        elseif ($now >= $e)           $newSt = 'Ended';
        else                          $newSt = 'Not Started';
    }
    if ($newSt !== $el['status']) {
        $pdo->prepare("UPDATE $table SET status=:s")->execute([':s' => $newSt]);
        if ($newSt === 'Ended' && $el['status'] === 'Ongoing') {
            $posStmt = $pdo->query("SELECT p.*,
                (SELECT c.full_name FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner,
                (SELECT c.vote_count FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner_votes
                FROM positions p WHERE p.is_active=1 AND p.election_type='{$type}' ORDER BY p.sort_order");
            $pos = $posStmt ? $posStmt->fetchAll() : [];
            $vcStmt = $pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM $vtable");
            $vc = $vcStmt ? (int)$vcStmt->fetchColumn() : 0;
            $tvStmt = $pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'");
            $tv = $tvStmt ? (int)$tvStmt->fetchColumn() : 0;
            $pdo->prepare(
                "INSERT INTO election_history (election_type,title,start_dt,end_dt,total_voters,votes_cast,archived_at,results_json)
                 VALUES (:et,:t,:s,:e,:tv,:vc,NOW(),:rj)"
            )->execute([
                ':et' => $type,
                ':t'  => $el['title'],
                ':s'  => $el['start_dt'],
                ':e'  => $el['end_dt'],
                ':tv' => $tv,
                ':vc' => $vc,
                ':rj' => json_encode(['positions' => $pos, 'votes_cast' => $vc, 'total_voters' => $tv]),
            ]);
        }
    }
}

try {
    archiveIfEnded($pdo, 'general');
} catch (Exception $e) {
}
try {
    archiveIfEnded($pdo, 'clas');
} catch (Exception $e) {
}

// ── Election settings ────────────────────────────────────────────
$elStmtGen = $pdo->query("SELECT * FROM election_settings LIMIT 1");
$election = ($elStmtGen ? $elStmtGen->fetch() : false)
    ?: ['title' => 'SSC Election 2026', 'status' => 'Not Started', 'ballot_locked' => 0, 'start_dt' => null, 'end_dt' => null];

$elStmtClas = $pdo->query("SELECT * FROM clas_election_settings LIMIT 1");
$clasElection = ($elStmtClas ? $elStmtClas->fetch() : false)
    ?: ['title' => 'CLAS Council Election 2026', 'status' => 'Not Started', 'ballot_locked' => 0, 'start_dt' => null, 'end_dt' => null];

// ── Login notification ────────────────────────────────────────────
$showLoginNotif  = false;
$loginNotifType  = '';
$loginNotifMsg   = '';
$loginNotifIcon  = '';
$loginNotifColor = '';
$loginNotifBtn   = '';

if (!isset($_SESSION['login_notif_shown'])) {
    $_SESSION['login_notif_shown'] = true;
    $showLoginNotif = true;

    if ($user['has_voted'] && $user['has_voted_clas']) {
        $loginNotifType  = 'voted';
        $loginNotifIcon  = '✅';
        $loginNotifColor = '#10b981';
        $loginNotifMsg   = 'You have <strong>already voted</strong> in both elections. Thank you for participating!';
        $loginNotifBtn   = 'View Results';
    } elseif ($election['status'] === 'Ongoing' || $clasElection['status'] === 'Ongoing') {
        $loginNotifType  = 'ongoing';
        $loginNotifIcon  = '🗳️';
        $loginNotifColor = '#10b981';
        $active = [];
        if ($election['status'] === 'Ongoing' && !$user['has_voted'])          $active[] = 'SSC General';
        if ($clasElection['status'] === 'Ongoing' && !$user['has_voted_clas']) $active[] = 'CLAS Council';
        if ($active) {
            $loginNotifMsg = '<strong>🎉 Voting is now open!</strong><br>Active elections: <strong>' . implode(', ', $active) . '</strong>. Cast your vote now!';
            $loginNotifBtn = 'Vote Now';
        } else {
            $loginNotifType  = 'voted';
            $loginNotifIcon  = '✅';
            $loginNotifColor = '#10b981';
            $loginNotifMsg   = 'All active elections have been voted on. Thank you!';
            $loginNotifBtn   = 'View Results';
        }
    } elseif ($election['status'] === 'Ended' || $clasElection['status'] === 'Ended') {
        $loginNotifType  = 'ended';
        $loginNotifIcon  = '🏁';
        $loginNotifColor = '#ef4444';
        $loginNotifMsg   = 'One or more elections have <strong>ended</strong>. You can now view the final results.';
        $loginNotifBtn   = 'View Results';
    } else {
        $loginNotifType  = 'not_started';
        $loginNotifIcon  = '⏳';
        $loginNotifColor = '#f59e0b';
        $loginNotifMsg   = 'Elections have <strong>not started</strong> yet. Please check back later.';
        if (!empty($election['start_dt'])) {
            $loginNotifMsg .= '<br><span style="font-size:12.5px;margin-top:4px;display:block;opacity:.75">🕐 General opens: <strong>' . date('M d, Y · h:i A', strtotime($election['start_dt'])) . '</strong></span>';
        }
        $loginNotifBtn = 'Got it';
    }
}

// ── Load General positions + candidates ──────────────────────────
// Exclude Prime Minister from SSC ballot — it belongs to CLAS only
$generalPositions    = $pdo->query("SELECT id, name FROM positions WHERE is_active=1 AND (election_type='general' OR election_type IS NULL OR election_type='') AND LOWER(name) NOT LIKE '%prime minister%' ORDER BY sort_order")->fetchAll();
$generalPosWithCands = [];
foreach ($generalPositions as $pos) {
    $cands = $pdo->prepare("SELECT id, full_name, motto, photo FROM candidates WHERE position_id=:pid AND is_active=1 ORDER BY id");
    $cands->execute([':pid' => $pos['id']]);
    $generalPosWithCands[] = ['id' => $pos['id'], 'name' => $pos['name'], 'candidates' => $cands->fetchAll()];
}

// ── Load CLAS positions + candidates ────────────────────────────
$clasPositions    = $pdo->query("SELECT id, name FROM positions WHERE is_active=1 AND election_type='clas' ORDER BY sort_order")->fetchAll();
$clasPosWithCands = [];
foreach ($clasPositions as $pos) {
    $cands = $pdo->prepare("SELECT id, full_name, motto, photo FROM candidates WHERE position_id=:pid AND is_active=1 ORDER BY id");
    $cands->execute([':pid' => $pos['id']]);
    $clasPosWithCands[] = ['id' => $pos['id'], 'name' => $pos['name'], 'candidates' => $cands->fetchAll()];
}

// ── Election Stats ────────────────────────────────────────────────
$stmtTv = $pdo->query("SELECT COUNT(*) FROM students");
$total_voters    = $stmtTv ? (int)$stmtTv->fetchColumn() : 0;
$stmtVc = $pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM votes");
$votes_cast      = $stmtVc ? (int)$stmtVc->fetchColumn() : 0;
$stmtCv = $pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM clas_votes");
$clas_votes_cast = $stmtCv ? (int)$stmtCv->fetchColumn() : 0;
$stmtVr = $pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'");
$verified_voters = $stmtVr ? (int)$stmtVr->fetchColumn() : 0;

// ── Handle Profile Save ───────────────────────────────────────────
$save_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_profile') {
    $first   = trim($_POST['first_name']  ?? '');
    $last    = trim($_POST['last_name']   ?? '');
    $phone   = trim($_POST['phone']       ?? '');
    $sid     = trim($_POST['student_id']  ?? '');
    $dob     = trim($_POST['dob']         ?? '');
    $email   = trim($_POST['email']       ?? '');
    $program = trim($_POST['program']     ?? '');
    $section = strtoupper(trim($_POST['section'] ?? ''));
    $pass    = $_POST['password']         ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // ── Handle profile photo upload ──────────────────────────────
    $newPhoto = $user['profile_photo']; // keep existing by default
    if (!empty($_FILES['profile_photo']['name'])) {
        $file     = $_FILES['profile_photo'];
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize  = 3 * 1024 * 1024; // 3 MB
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $save_message = 'error:Photo upload failed (error code ' . $file['error'] . ').';
        } elseif (!in_array($mimeType, $allowed)) {
            $save_message = 'error:Only JPG, PNG, GIF, or WebP images are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $save_message = 'error:Photo must be under 3 MB.';
        } else {
            $uploadDir = 'uploads/students/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'student_' . $_SESSION['user_id'] . '_' . time() . '.' . strtolower($ext);
            $dest     = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Delete old photo if it exists
                if ($newPhoto && file_exists($newPhoto)) {
                    @unlink($newPhoto);
                }
                $newPhoto = $dest;
            } else {
                $save_message = 'error:Could not save photo. Check folder permissions.';
            }
        }
    }

    if (!$save_message) {
        if (!$first || !$last) {
            $save_message = 'error:First and last name are required.';
        } elseif ($phone && !preg_match('/^09\d{9}$/', $phone)) {
            $save_message = 'error:Phone must be in format 09xxxxxxxxx.';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $save_message = 'error:Please enter a valid email address.';
        } elseif ($pass && $pass !== $confirm) {
            $save_message = 'error:Passwords do not match.';
        } elseif ($pass && strlen($pass) < 6) {
            $save_message = 'error:Password must be at least 6 characters.';
        } else {
            if ($pass) {
                $pdo->prepare("UPDATE students SET first_name=:fn,last_name=:ln,phone=:ph,student_id=:sid,birthdate=:bd,email=:em,program=:prog,section=:sec,password_hash=:pw,profile_photo=:pp WHERE id=:id")
                    ->execute([':fn' => $first, ':ln' => $last, ':ph' => $phone, ':sid' => $sid, ':bd' => $dob, ':em' => $email, ':prog' => $program, ':sec' => $section, ':pw' => password_hash($pass, PASSWORD_DEFAULT), ':pp' => $newPhoto, ':id' => $_SESSION['user_id']]);
            } else {
                $pdo->prepare("UPDATE students SET first_name=:fn,last_name=:ln,phone=:ph,student_id=:sid,birthdate=:bd,email=:em,program=:prog,section=:sec,profile_photo=:pp WHERE id=:id")
                    ->execute([':fn' => $first, ':ln' => $last, ':ph' => $phone, ':sid' => $sid, ':bd' => $dob, ':em' => $email, ':prog' => $program, ':sec' => $section, ':pp' => $newPhoto, ':id' => $_SESSION['user_id']]);
            }
            $_SESSION['first_name']    = $first;
            $_SESSION['last_name']     = $last;
            $_SESSION['program']       = $program;
            $_SESSION['section']       = $section;
            $_SESSION['profile_photo'] = $newPhoto;
            $user['first_name']    = $first;
            $user['last_name']     = $last;
            $user['phone']         = $phone;
            $user['student_id']    = $sid;
            $user['dob']           = $dob;
            $user['email']         = $email;
            $user['program']       = $program;
            $user['section']       = $section;
            $user['profile_photo'] = $newPhoto;
            $full_name             = h($first . ' ' . $last);
            $initials              = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
            $save_message          = 'success:Profile updated successfully!';
            logUserAction('Profile Update', "Student {$sid} ({$first} {$last}) updated their profile.", (int)$_SESSION['user_id']);
        }
    }
}

// ── Pass review status to view ────────────────────────────────────
// user_view.php can use $reviewedGeneral / $reviewedClas to pre-disable
// the submit button if the student already left a review.

// ── Render view ───────────────────────────────────────────────────
require 'user_view.php';