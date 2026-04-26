<?php
// ── results_api.php — SSC General Election live results ─────────
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
    // Election meta
    $election = $pdo->query("SELECT status FROM election_settings LIMIT 1")->fetch();
    $status   = $election['status'] ?? 'Not Started';

    // Stats
    $votes_cast    = (int)$pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM votes")->fetchColumn();
    $total_voters  = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();

    // Positions (General SSC only — exclude CLAS / Prime Minister)
    $positions = $pdo->query(
        "SELECT id, name FROM positions
         WHERE is_active = 1
           AND (election_type = 'general' OR election_type IS NULL OR election_type = '')
           AND LOWER(name) NOT LIKE '%prime minister%'
         ORDER BY sort_order, id"
    )->fetchAll();

    $result = [];
    foreach ($positions as $pos) {
        // Total votes for this position
        $totalVotes = (int)$pdo->prepare(
            "SELECT COUNT(*) FROM votes WHERE position_id = :pid"
        )->execute([':pid' => $pos['id']]) ? 0 : 0;
        $tvStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE position_id = :pid");
        $tvStmt->execute([':pid' => $pos['id']]);
        $totalVotes = (int)$tvStmt->fetchColumn();

        // Candidates with vote counts
        $cands = $pdo->prepare(
            "SELECT c.id, c.full_name AS name, c.vote_count AS votes
             FROM candidates c
             WHERE c.position_id = :pid AND c.is_active = 1
             ORDER BY c.vote_count DESC, c.full_name ASC"
        );
        $cands->execute([':pid' => $pos['id']]);
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
    echo json_encode(['ok' => false, 'error' => 'Failed to load results: ' . $e->getMessage()]);
}
