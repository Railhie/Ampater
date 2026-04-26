<?php
// ── vote_submit_clas.php — handles CLAS Council ballot submission ──
session_start();
header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}
if (($_SESSION['role'] ?? '') === 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Admins cannot vote.']);
    exit;
}

// DB
define('DB_HOST', 'localhost');
define('DB_NAME', 'suffratech');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Ensure has_voted_clas column exists
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN has_voted_clas TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
}

// Ensure Prime Minister is CLAS-only (safety migration)
try {
    $pdo->exec("UPDATE positions SET election_type='clas' WHERE LOWER(name) LIKE '%prime minister%' AND (election_type='general' OR election_type IS NULL OR election_type='')");
} catch (Exception $e) {
}

$studentId = (int)$_SESSION['user_id'];

// Get student record — check approval AND has_voted_clas
$student = $pdo->prepare("SELECT approval_status, has_voted_clas FROM students WHERE id = :id");
$student->execute([':id' => $studentId]);
$student = $student->fetch();

if (!$student) {
    echo json_encode(['ok' => false, 'error' => 'Student record not found.']);
    exit;
}
if ($student['approval_status'] !== 'Approved') {
    echo json_encode(['ok' => false, 'error' => 'Your account is not approved for voting yet.']);
    exit;
}
if ((int)$student['has_voted_clas'] === 1) {
    echo json_encode(['ok' => false, 'error' => 'You have already submitted your CLAS ballot.']);
    exit;
}

// Check election status
$election = $pdo->query("SELECT * FROM clas_election_settings LIMIT 1")->fetch();
if (!$election || $election['status'] !== 'Ongoing') {
    echo json_encode(['ok' => false, 'error' => 'The CLAS election is not currently open.']);
    exit;
}
if (!empty($election['ballot_locked'])) {
    echo json_encode(['ok' => false, 'error' => 'The CLAS ballot is currently locked.']);
    exit;
}

// Read JSON body
$body    = file_get_contents('php://input');
$payload = json_decode($body, true);
if (!isset($payload['votes']) || !is_array($payload['votes']) || empty($payload['votes'])) {
    echo json_encode(['ok' => false, 'error' => 'No votes received.']);
    exit;
}

// Cast keys and values to int
$rawVotes = $payload['votes'];
$votes = [];
foreach ($rawVotes as $posId => $candId) {
    $votes[(int)$posId] = (int)$candId;
}

// Validate all CLAS positions have exactly one candidate selected
$clasPositions = $pdo->query(
    "SELECT id FROM positions WHERE is_active = 1 AND election_type = 'clas'"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($clasPositions as $posId) {
    if (!isset($votes[(int)$posId])) {
        echo json_encode(['ok' => false, 'error' => 'Please select a candidate for every CLAS position.']);
        exit;
    }
}

// Validate each submitted position is actually a CLAS position
foreach ($votes as $posId => $candId) {
    $posCheck = $pdo->prepare(
        "SELECT id FROM positions WHERE id = :pid AND is_active = 1 AND election_type = 'clas'"
    );
    $posCheck->execute([':pid' => $posId]);
    if (!$posCheck->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CLAS position detected. Please refresh and try again.']);
        exit;
    }

    $check = $pdo->prepare(
        "SELECT id FROM candidates WHERE id = :cid AND position_id = :pid AND is_active = 1"
    );
    $check->execute([':cid' => $candId, ':pid' => $posId]);
    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid candidate selection detected.']);
        exit;
    }
}

// Begin transaction
try {
    $pdo->beginTransaction();

    // Ensure clas_votes table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS clas_votes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_db_id INT NOT NULL,
        position_id INT NOT NULL,
        candidate_id INT NOT NULL,
        voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_pos (student_db_id, position_id)
    )");

    $insertVote = $pdo->prepare(
        "INSERT INTO clas_votes (student_db_id, position_id, candidate_id) VALUES (:sid, :pid, :cid)"
    );
    $updateCount = $pdo->prepare(
        "UPDATE candidates SET vote_count = vote_count + 1 WHERE id = :cid"
    );

    foreach ($votes as $posId => $candId) {
        $insertVote->execute([':sid' => $studentId, ':pid' => $posId, ':cid' => $candId]);
        $updateCount->execute([':cid' => $candId]);
    }

    // Mark student as having voted in CLAS
    $pdo->prepare("UPDATE students SET has_voted_clas = 1 WHERE id = :id")
        ->execute([':id' => $studentId]);

    $pdo->commit();

    $_SESSION['has_voted_clas'] = 1;
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Failed to record vote. Please try again.']);
}

function logUserAction(string $action, string $details, int $studentDbId = 0): void {}
