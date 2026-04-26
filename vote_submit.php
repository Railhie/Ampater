<?php
// ── vote_submit.php  (AJAX endpoint) ────────────────────────────
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

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

try {
    $pdo = db();

    // ── Ensure Prime Minister is always CLAS-only (safety migration) ──
    try {
        $pdo->exec("UPDATE positions SET election_type='clas' WHERE LOWER(name) LIKE '%prime minister%' AND (election_type='general' OR election_type IS NULL OR election_type='')");
    } catch (Exception $e) {
    }

    // 1. Check election is Ongoing and ballot not locked
    $el = $pdo->query("SELECT status, ballot_locked FROM election_settings LIMIT 1")->fetch();
    if (!$el || $el['status'] !== 'Ongoing') {
        echo json_encode(['ok' => false, 'error' => 'Election is not currently active.']);
        exit;
    }
    if ($el['ballot_locked']) {
        echo json_encode(['ok' => false, 'error' => 'The ballot is locked. Voting is closed.']);
        exit;
    }

    // 2. Get student DB row
    $sid = $_SESSION['user_id'];  // students.id PK stored in session
    $student = $pdo->prepare("SELECT id, approval_status, has_voted FROM students WHERE id = :id");
    $student->execute([':id' => $sid]);
    $student = $student->fetch();

    if (!$student) {
        echo json_encode(['ok' => false, 'error' => 'Student record not found.']);
        exit;
    }
    if ($student['approval_status'] !== 'Approved') {
        echo json_encode(['ok' => false, 'error' => 'Your account is not approved yet.']);
        exit;
    }
    if ($student['has_voted']) {
        echo json_encode(['ok' => false, 'error' => 'You have already voted.']);
        exit;
    }

    // 3. Parse votes from POST  { votes: { "1": "5", "2": "8", ... } }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $rawVotes = $data['votes'] ?? [];

    if (empty($rawVotes)) {
        echo json_encode(['ok' => false, 'error' => 'No votes received.']);
        exit;
    }

    // Cast both keys and values to int
    $votes = [];
    foreach ($rawVotes as $pid => $cid) {
        $votes[(int)$pid] = (int)$cid;
    }

    // 4. Validate every active GENERAL (non-PM) position has a selection
    $positions = $pdo->query(
        "SELECT id, name FROM positions
         WHERE is_active = 1
           AND (election_type = 'general' OR election_type IS NULL OR election_type = '')
           AND LOWER(name) NOT LIKE '%prime minister%'"
    )->fetchAll();

    foreach ($positions as $pos) {
        $pid = (int)$pos['id'];
        if (empty($votes[$pid])) {
            echo json_encode(['ok' => false, 'error' => 'Please select a candidate for: ' . $pos['name']]);
            exit;
        }
    }

    // 5. Validate each submitted candidate ID belongs to the given GENERAL position and is active
    //    (reject any attempt to submit a Prime Minister / CLAS position via this endpoint)
    foreach ($votes as $pid => $cid) {
        // Confirm this position is actually a general position (not CLAS / PM)
        $posCheck = $pdo->prepare(
            "SELECT id FROM positions
             WHERE id = :pid
               AND is_active = 1
               AND (election_type = 'general' OR election_type IS NULL OR election_type = '')
               AND LOWER(name) NOT LIKE '%prime minister%'"
        );
        $posCheck->execute([':pid' => $pid]);
        if (!$posCheck->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Invalid position in SSC ballot. Please refresh and try again.']);
            exit;
        }

        $check = $pdo->prepare(
            "SELECT id FROM candidates WHERE id = :cid AND position_id = :pid AND is_active = 1"
        );
        $check->execute([':cid' => $cid, ':pid' => $pid]);
        if (!$check->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Invalid candidate selection.']);
            exit;
        }
    }

    // 6. Insert votes in a transaction
    $pdo->beginTransaction();
    try {
        $insVote  = $pdo->prepare("INSERT INTO votes (student_db_id, position_id, candidate_id) VALUES (:sid, :pid, :cid)");
        $updCount = $pdo->prepare("UPDATE candidates SET vote_count = vote_count + 1 WHERE id = :cid");

        foreach ($votes as $pid => $cid) {
            $insVote->execute([':sid' => $student['id'], ':pid' => $pid, ':cid' => $cid]);
            $updCount->execute([':cid' => $cid]);
        }

        $pdo->prepare("UPDATE students SET has_voted = 1 WHERE id = :id")->execute([':id' => $student['id']]);
        $_SESSION['has_voted'] = 1;

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Vote recording failed: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

function logUserAction(string $action, string $details, int $studentDbId = 0): void {}
