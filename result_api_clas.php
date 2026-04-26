<?php
// ── result_api_clas.php — CLAS Council live results ─────────────
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=suffratech;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

try {
    // Ensure clas_votes table exists (graceful fallback)
    $pdo->exec("CREATE TABLE IF NOT EXISTS clas_votes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_db_id INT NOT NULL,
        position_id INT NOT NULL,
        candidate_id INT NOT NULL,
        voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_pos (student_db_id, position_id)
    )");

    // Election meta
    $election = $pdo->query("SELECT status FROM clas_election_settings LIMIT 1")->fetch();
    $status   = $election['status'] ?? 'Not Started';

    // Stats
    $votes_cast   = (int)$pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM clas_votes")->fetchColumn();
    $total_voters = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();

    // Positions — CLAS only (including Prime Minister)
    $positions = $pdo->query(
        "SELECT id, name FROM positions
         WHERE is_active = 1 AND election_type = 'clas'
         ORDER BY sort_order, id"
    )->fetchAll();

    $result = [];
    foreach ($positions as $pos) {
        // Candidates with vote counts (using clas_votes for accurate tally)
        $cands = $pdo->prepare(
            "SELECT c.id, c.full_name AS name,
                    COALESCE(
                        (SELECT COUNT(*) FROM clas_votes cv WHERE cv.candidate_id = c.id AND cv.position_id = :pid),
                        0
                    ) AS votes
             FROM candidates c
             WHERE c.position_id = :pid2 AND c.is_active = 1
             ORDER BY votes DESC, c.full_name ASC"
        );
        $cands->execute([':pid' => $pos['id'], ':pid2' => $pos['id']]);
        $candidates = $cands->fetchAll();

        // Calculate percentages
        $posTotal = array_sum(array_column($candidates, 'votes'));
        foreach ($candidates as &$c) {
            $c['votes'] = (int)$c['votes'];
            $c['pct']   = $posTotal > 0 ? round($c['votes'] / $posTotal * 100, 1) : 0;
        }
        unset($c);

        $result[] = [
            'position'   => $pos['name'],
            'candidates' => $candidates,
        ];
    }

    echo json_encode([
        'ok'           => true,
        'status'       => $status,
        'votes_cast'   => $votes_cast,
        'total_voters' => $total_voters,
        'positions'    => $result,
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to load CLAS results: ' . $e->getMessage()]);
}
