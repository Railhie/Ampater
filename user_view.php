<?php
// ══════════════════════════════════════════════════════════════
//  FEEDBACK API — early-exit JSON handler
//  Table: vote_user_reviews  (created by user.php)
//  GET  ?api=reviews → load all reviews
//  POST application/json → submit a new review
// ══════════════════════════════════════════════════════════════
$_fbIsPost = $_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
$_fbIsGet  = $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['api'] ?? '') === 'reviews';

if ($_fbIsPost || $_fbIsGet) {
    header('Content-Type: application/json');

    // Start session when called directly via AJAX (not through user.php include)
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
        exit;
    }

    // Standalone DB fallback — db() only exists when included via user.php.
    // When this file is fetched directly via AJAX, define it here.
    if (!function_exists('db')) {
        function db(): PDO
        {
            static $_pdo = null;
            if (!$_pdo) {
                $_pdo = new PDO(
                    'mysql:host=localhost;dbname=suffratech;charset=utf8mb4',
                    'root',
                    '',
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            }
            return $_pdo;
        }
    }

    $pdo = db();
    $studentDbId  = (int) $_SESSION['user_id'];
    $studentName  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $studentIdStr = $_SESSION['student_id'] ?? '';

    // ── GET: load all reviews ────────────────────────────────────
    if ($_fbIsGet) {
        $stmt = $pdo->query("
            SELECT id, election_type, rating, tags, review_text, created_at, student_name
            FROM vote_user_reviews
            ORDER BY created_at DESC
        ");
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($reviews as &$r) {
            $r['rating'] = (int) $r['rating'];
            // ── Attach admin reply from feedback table if matched ──
            $r['admin_reply'] = null;
            try {
                $replyStmt = $pdo->prepare("
                    SELECT reply FROM feedback
                    WHERE name = :n
                      AND rating = :rt
                      AND reply IS NOT NULL AND reply != ''
                    ORDER BY created_at DESC LIMIT 1
                ");
                $replyStmt->execute([':n' => $r['student_name'], ':rt' => $r['rating']]);
                $found = $replyStmt->fetchColumn();
                if ($found) $r['admin_reply'] = $found;
            } catch (Exception $_e) {
            }
        }
        unset($r);
        $total = count($reviews);
        $avg   = $total ? round(array_sum(array_column($reviews, 'rating')) / $total, 1) : null;
        echo json_encode(['ok' => true, 'reviews' => $reviews, 'avg_rating' => $avg, 'total' => $total]);
        exit;
    }

    // ── POST: submit a review ────────────────────────────────────
    $body         = json_decode(file_get_contents('php://input'), true) ?? [];
    $rating       = isset($body['rating'])    ? (int) $body['rating'] : 0;
    $tags         = trim($body['tags']        ?? '');
    $reviewText   = trim($body['review_text'] ?? '');
    $electionType = $body['election_type']    ?? 'general';

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['ok' => false, 'error' => 'Invalid rating.']);
        exit;
    }
    if (strlen($reviewText) < 3) {
        echo json_encode(['ok' => false, 'error' => 'Comment is too short.']);
        exit;
    }
    if (!in_array($electionType, ['general', 'clas'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid election type.']);
        exit;
    }

    // Check for duplicate review
    $chk = $pdo->prepare("SELECT id FROM vote_user_reviews WHERE student_db_id = ? AND election_type = ? LIMIT 1");
    $chk->execute([$studentDbId, $electionType]);
    if ($chk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'You have already reviewed this election.']);
        exit;
    }

    $pdo->prepare("
        INSERT INTO vote_user_reviews (student_db_id, student_name, student_id_str, rating, tags, review_text, election_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$studentDbId, $studentName, $studentIdStr, $rating, $tags, $reviewText, $electionType]);

    // ── Also mirror to admin feedback table so admins can see & reply ──
    $category = ($electionType === 'clas') ? 'CLAS Council Election' : 'General SSC Election';
    $fullMsg   = $reviewText . ($tags ? ' [Tags: ' . $tags . ']' : '');
    try {
        $pdo->prepare("
            INSERT INTO feedback (name, category, message, rating, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ")->execute([$studentName ?: $studentIdStr, $category, $fullMsg, $rating]);
    } catch (Exception $_e) { /* feedback table may not exist yet — silently skip */
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  RESULTS API  — GET ?api=live_results&type=general|clas
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['api'] ?? '') === 'live_results') {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Not authenticated.']); exit; }
    if (!function_exists('db')) {
        function db(): PDO {
            static $_pdo = null;
            if (!$_pdo) {
                $_pdo = new PDO('mysql:host=localhost;dbname=suffratech;charset=utf8mb4','root','',
                    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            }
            return $_pdo;
        }
    }
    $pdo  = db();
    $type = in_array($_GET['type'] ?? '', ['general','clas'], true) ? $_GET['type'] : 'general';
    $elTable = $type === 'clas' ? 'clas_election_settings' : 'election_settings';
    $elRow   = $pdo->query("SELECT status FROM $elTable LIMIT 1")->fetch();
    $status  = $elRow['status'] ?? 'Not Started';
    $typeFilter = $type === 'clas'
        ? "election_type='clas'"
        : "(election_type='general' OR election_type IS NULL OR election_type='') AND LOWER(name) NOT LIKE '%prime minister%'";
    $posStmt = $pdo->query("SELECT id, name FROM positions WHERE is_active=1 AND $typeFilter ORDER BY sort_order");
    $positions = [];
    $totalVotes = 0;
    foreach ($posStmt->fetchAll() as $pos) {
        $cStmt = $pdo->prepare("SELECT id, full_name, motto, photo, vote_count FROM candidates WHERE position_id=:pid AND is_active=1 ORDER BY vote_count DESC, id ASC");
        $cStmt->execute([':pid' => $pos['id']]);
        $cands = $cStmt->fetchAll();
        $posTotal = array_sum(array_column($cands, 'vote_count'));
        $totalVotes += $posTotal;
        $positions[] = [
            'id'        => (int)$pos['id'],
            'name'      => $pos['name'],
            'total'     => (int)$posTotal,
            'candidates'=> array_map(function($c) use($posTotal) {
                $v = (int)$c['vote_count'];
                return [
                    'id'    => (int)$c['id'],
                    'name'  => $c['full_name'],
                    'motto' => $c['motto'] ?? '',
                    'photo' => $c['photo'] ?? '',
                    'votes' => $v,
                    'pct'   => $posTotal > 0 ? round($v/$posTotal*100,1) : 0,
                ];
            }, $cands),
        ];
    }
    echo json_encode(['ok'=>true,'status'=>$status,'type'=>$type,'positions'=>$positions,'total_votes'=>$totalVotes]);
    exit;
}
if (!function_exists('elStatusClass')) {
    function elStatusClass(string $s): string
    {
        return match ($s) {
            'Ongoing' => 'ongoing',
            'Ended' => 'ended',
            default => 'ns'
        };
    }
}
if (!function_exists('bsBadge')) {
    function bsBadge(string $s): string
    {
        return match ($s) {
            'Ongoing' => 'bg-success',
            'Ended'   => 'bg-secondary',
            default   => 'bg-warning text-dark',
        };
    }
}

$genStatus   = $election['status']     ?? 'Not Started';
$clasStatus  = $clasElection['status'] ?? 'Not Started';

// CLAS-eligible programs
$clasPrograms = [
    'BS MATHEMATICS',
    'BS PSYCHOLOGY',
    'BA POLITICAL SCIENCE',
    'BA COMMUNICATION',
    'BA BEHAVIORAL SCIENCE',
    'BS INFORMATION TECHNOLOGY',
    'BSIT',
    'BS INFORMATION SYSTEMS',
    'BSIS',
    'BS ENTERTAINMENT & MULTIMEDIA COMPUTING',
    'BSEMC',
    'BS COMPUTER SCIENCE',
    'BSCS',
    'BPA PUBLIC ADMINISTRATION',
    'BPA',
];
$userProgram      = strtoupper(trim($user['program'] ?? ''));
$isClasEligible   = false;
foreach ($clasPrograms as $_cp) {
    if (str_contains($userProgram, strtoupper($_cp))) {
        $isClasEligible = true;
        break;
    }
}

// Results visibility: shown only while Ongoing, OR within 1 hour after end_dt
$_now = time();
$genEndTs  = !empty($election['end_dt'])     ? strtotime($election['end_dt'])     : 0;
$clasEndTs = !empty($clasElection['end_dt']) ? strtotime($clasElection['end_dt']) : 0;
$genResultsVisible  = ($genStatus  === 'Ongoing') || ($genStatus  === 'Ended' && $genEndTs  && ($_now - $genEndTs)  < 3600);
$clasResultsVisible = ($clasStatus === 'Ongoing') || ($clasStatus === 'Ended' && $clasEndTs && ($_now - $clasEndTs) < 3600);
// Minutes remaining in the 1-hour window (for JS countdown)
$genExpirySecondsLeft  = ($genStatus  === 'Ended' && $genEndTs)  ? max(0, 3600 - ($_now - $genEndTs))  : 0;
$clasExpirySecondsLeft = ($clasStatus === 'Ended' && $clasEndTs) ? max(0, 3600 - ($_now - $clasEndTs)) : 0;

$showVoteBadge = (
    ($genStatus  === 'Ongoing' && !$user['has_voted']) ||
    ($clasStatus === 'Ongoing' && !$user['has_voted_clas'])
);

$generalPositionNames = array_column($generalPosWithCands, 'name');
$clasPositionNames    = array_column($clasPosWithCands,    'name');

$profileMsgType = '';
$profileMsgText = '';
if ($save_message) {
    [$profileMsgType, $profileMsgText] = explode(':', $save_message, 2);
}

$turnoutPct = ($verified_voters > 0)
    ? round($votes_cast / $verified_voters * 100, 1) : 0;
// $reviewedGeneral and $reviewedClas are set by user.php
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuffraTech — Voting System</title>
    <link rel="icon" type="image/png" href="data:image/png;base64,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">
    <link rel="shortcut icon" type="image/png" href="data:image/png;base64,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">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="user_extra.css">

    <style>
        :root {
            --sidebar-w: 272px;
            --brand: #16a34a;
            --brand-dark: #15803d;
            --brand-deeper: #14532d;
            --brand-light: #bbf7d0;
            --brand-glow: rgba(22, 163, 74, .18);
            --accent: #10b981;
            --purple: #6366f1;
            --surface: #ffffff;
            --bg: #f0f4f8;
            --border: #e2e8f0;
            --text: #0f172a;
            --text-muted: #64748b;
            --radius: 14px;
            --radius-lg: 20px;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, .06);
            --shadow: 0 6px 28px rgba(0, 0, 0, .1);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, .14);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

       body {
    font-family: 'DM Sans', sans-serif;
    background: transparent; /* or remove this line entirely */
    background-image: radial-gradient(ellipse at 80% 0%, rgba(16, 163, 74, .07) 0%, transparent 55%),
        radial-gradient(ellipse at 10% 100%, rgba(99, 102, 241, .05) 0%, transparent 50%);
    color: var(--text);
    min-height: 100vh;
         }

        /* ═══ SIDEBAR ═══════════════════════════════════════════ */
        .sf-sidebar {
            width: var(--sidebar-w);
            min-height: 100vh;
            background: linear-gradient(175deg, #0f4c24 0%, #166534 30%, #15803d 65%, #16a34a 100%);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            padding: 28px 14px 20px;
            z-index: 1000;
            box-shadow: 6px 0 36px rgba(0, 0, 0, .22);
            overflow: hidden;
        }

        .sf-sidebar::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, rgba(255, 255, 255, .08) 1px, transparent 1px);
            background-size: 20px 20px;
            pointer-events: none;
        }

        .sf-sidebar::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 200px;
            background: linear-gradient(0deg, rgba(0, 0, 0, .18) 0%, transparent 100%);
            pointer-events: none;
        }

        /* Logo */
        .sf-logo-wrap {
            position: relative;
            margin-bottom: 6px;
        }

        .sf-logo {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            font-weight: 800;
            color: #fff;
            letter-spacing: 1px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sf-logo-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .15);
            border: 1.5px solid rgba(255, 255, 255, .25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .sf-logo span {
            color: #a7f3d0;
        }

        .sf-logo-sub {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 3px;
            color: #6ee7b7;
            text-transform: uppercase;
            margin-bottom: 26px;
            margin-top: 4px;
            position: relative;
            padding-left: 2px;
        }

        /* Nav */
        .sf-nav-label {
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 2.5px;
            color: rgba(255, 255, 255, .35);
            text-transform: uppercase;
            padding: 0 10px;
            margin: 16px 0 4px;
            position: relative;
        }

        .sf-nav-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 14px;
            border: none;
            border-radius: 12px;
            background: transparent;
            color: rgba(255, 255, 255, .65);
            font-family: 'DM Sans', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all .18s;
            text-align: left;
            margin-bottom: 2px;
            position: relative;
        }

        .sf-nav-btn i {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            transition: all .18s;
        }

        .sf-nav-btn:hover {
            background: rgba(255, 255, 255, .12);
            color: #fff;
            transform: translateX(2px);
        }

        .sf-nav-btn:hover i {
            background: rgba(255, 255, 255, .18);
        }

        .sf-nav-btn.active {
            background: rgba(255, 255, 255, .18);
            color: #fff;
            box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, .22), 0 4px 14px rgba(0, 0, 0, .15);
        }

        .sf-nav-btn.active i {
            background: rgba(255, 255, 255, .25);
            color: #fff;
        }

        .sf-nav-btn.active::before {
            content: '';
            position: absolute;
            left: -2px;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 20px;
            background: #a7f3d0;
            border-radius: 0 3px 3px 0;
            box-shadow: 0 0 8px rgba(167, 243, 208, .6);
        }

        .sf-nav-btn .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fca5a5;
            margin-left: auto;
            animation: blink 1.4s infinite;
            flex-shrink: 0;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .3
            }
        }

        /* User card */
        .sf-user-card {
            background: rgba(255, 255, 255, .12);
            border: 1.5px solid rgba(255, 255, 255, .18);
            border-radius: 14px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            position: relative;
            backdrop-filter: blur(4px);
        }

        .sf-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #bbf7d0, #4ade80);
            color: #14532d;
            font-family: 'Playfair Display', serif;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 2px solid rgba(255, 255, 255, .35);
        }

        .sf-uname {
            font-size: 13px;
            font-weight: 700;
            color: #fff;
        }

        .sf-urole {
            font-size: 10.5px;
            color: #86efac;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 1px;
        }

        .sf-urole::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #4ade80;
            box-shadow: 0 0 6px #4ade80;
        }

        /* ═══ MAIN ═══════════════════════════════════════════════ */
        .sf-main {
            margin-left: var(--sidebar-w);
            padding: 40px 40px 80px;
            min-height: 100vh;
        }

        /* Page header */
        .sf-page-header {
            margin-bottom: 28px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .sf-page-title {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 800;
            color: var(--text);
            margin: 0;
            line-height: 1.1;
        }

        .sf-page-sub {
            color: var(--text-muted);
            font-size: 13.5px;
            margin: 5px 0 0;
            font-weight: 500;
        }

        /* ═══ STAT CARDS ════════════════════════════════════════ */
        .stat-card {
            border-radius: var(--radius-lg);
            padding: 24px 22px 20px;
            border: none;
            position: relative;
            overflow: hidden;
            transition: transform .2s, box-shadow .2s;
            cursor: default;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            right: -24px;
            top: -24px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            right: 20px;
            bottom: -12px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .08);
        }

        .stat-pink {
            background: linear-gradient(135deg, #db2777, #ec4899 60%, #f472b6);
        }

        .stat-orange {
            background: linear-gradient(135deg, #ea580c, #f97316 60%, #fb923c);
        }

        .stat-teal {
            background: linear-gradient(135deg, #0d9488, #14b8a6 60%, #2dd4bf);
        }

        .stat-val {
            font-family: 'Playfair Display', serif;
            font-size: 46px;
            font-weight: 800;
            color: #fff;
            line-height: 1;
            margin: 6px 0 4px;
        }

        .stat-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .75);
            margin-bottom: 2px;
        }

        .stat-note {
            font-size: 12px;
            color: rgba(255, 255, 255, .65);
            margin-top: 2px;
        }

        .stat-icon {
            position: absolute;
            right: 20px;
            bottom: 16px;
            font-size: 38px;
            color: rgba(255, 255, 255, .2);
        }

        /* ═══ ELECTION OVERVIEW CARDS ══════════════════════════ */
        .el-ov-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow .2s, transform .2s;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .el-ov-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--brand), #4ade80);
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .el-ov-clas::before {
            background: linear-gradient(90deg, var(--purple), #a78bfa);
        }

        .el-ov-card:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .el-ov-title {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--brand);
            margin-bottom: 8px;
        }

        .el-ov-clas .el-ov-title {
            color: var(--purple);
        }

        /* ═══ GENERAL CARDS ═════════════════════════════════════ */
        .sf-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            height: 100%;
        }

        .sf-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ═══ CANDIDATE CARDS ═══════════════════════════════════ */
        /* ═══ CANDIDATE SCROLL ROW ══════════════════════════════ */
        .cand-scroll-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 12px;
            overflow-x: auto;
            padding: 4px 2px 12px;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .cand-scroll-row::-webkit-scrollbar { height: 5px; }
        .cand-scroll-row::-webkit-scrollbar-track { background: transparent; }
        .cand-scroll-row::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
        .cand-slot { flex: 0 0 auto; scroll-snap-align: start; }

        /* ── CARD ──────────────────────────────────────────────── */
        .candidate-card {
            position: relative;
            width: 120px;
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: 18px;
            padding: 14px 10px 12px;
            text-align: center;
            cursor: pointer;
            transition: border-color .18s, box-shadow .18s, transform .18s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .candidate-card:hover {
            border-color: var(--brand);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px var(--brand-glow);
        }
        .candidate-card.selected {
            border-color: var(--brand);
            background: linear-gradient(160deg, #f0fdf4 0%, #dcfce7 100%);
            box-shadow: 0 0 0 3px rgba(22,163,74,.18), 0 6px 20px rgba(22,163,74,.14);
        }
        .clas-card.selected {
            border-color: var(--purple);
            background: linear-gradient(160deg, #eef2ff 0%, #e0e7ff 100%);
            box-shadow: 0 0 0 3px rgba(99,102,241,.2), 0 6px 20px rgba(99,102,241,.14);
        }

        /* ── CHECKMARK ─────────────────────────────────────────── */
        .checkmark {
            position: absolute;
            top: 8px; right: 8px;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--brand);
            color: #fff;
            font-size: 11px;
            display: none;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            box-shadow: 0 2px 8px rgba(22,163,74,.4);
        }
        .candidate-card.selected .checkmark { display: flex; }
        .clas-card.selected .checkmark { background: var(--purple); box-shadow: 0 2px 8px rgba(99,102,241,.4); }

        /* ── PHOTO / AVATAR ────────────────────────────────────── */
        .cand-photo-wrap {
            width: 64px; height: 64px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(0,0,0,.12);
            border: 2.5px solid rgba(255,255,255,.8);
        }
        .cand-photo-wrap img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .candidate-avatar {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #bbf7d0, #4ade80);
            color: #14532d;
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(22,163,74,.25);
        }
        .clas-card .candidate-avatar {
            background: linear-gradient(135deg, #c7d2fe, #818cf8);
            color: #3730a3;
            box-shadow: 0 3px 10px rgba(99,102,241,.25);
        }
        .candidate-card.selected .cand-photo-wrap { border-color: var(--brand); }
        .clas-card.selected .cand-photo-wrap { border-color: var(--purple); }

        /* ── NAME / PARTY ──────────────────────────────────────── */
        .candidate-name {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.25;
            word-break: break-word;
            hyphens: auto;
        }
        .candidate-party {
            font-size: 10px;
            color: var(--text-muted);
            line-height: 1.2;
            word-break: break-word;
        }

        /* ═══ POSITION HEADER ═══════════════════════════════════ */
        .pos-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            border-left: 4px solid var(--brand);
            padding-left: 12px;
            margin-bottom: 16px;
        }

        .clas-pos-header {
            border-left-color: var(--purple) !important;
        }

        /* ═══ VOTE SUBMIT ════════════════════════════════════════ */
        .vote-submit-wrap {
            text-align: center;
            padding: 32px 0 8px;
        }

        /* ═══ AVAILABLE ELECTION CARDS ══════════════════════════ */
        .av-election-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 26px 24px 22px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow .2s, transform .2s;
            position: relative;
            overflow: hidden;
        }

        .av-election-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--brand), #4ade80);
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .av-clas-card::before {
            background: linear-gradient(90deg, var(--purple), #a78bfa);
        }

        .av-election-card.av-clickable {
            cursor: pointer;
        }

        .av-election-card.av-clickable:hover {
            box-shadow: var(--shadow);
            transform: translateY(-3px);
        }

        .av-election-card.av-locked {
            cursor: default;
            opacity: .7;
        }

        .av-card-title {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .av-card-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 14px;
        }

        .av-card-status {
            font-size: 13px;
            font-weight: 600;
        }

        .av-status-ongoing {
            color: #16a34a;
        }

        .av-status-ended {
            color: #dc2626;
        }

        .av-status-voted {
            color: #16a34a;
        }

        .av-status-ns {
            color: #d97706;
        }

        /* ═══ FEEDBACK ══════════════════════════════════════════ */
        .fb-form-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px;
            box-shadow: var(--shadow-sm);
        }

        .fb-reviews-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
        }

        .star {
            font-size: 30px;
            cursor: pointer;
            opacity: .3;
            transition: opacity .15s, transform .12s;
        }

        .star:hover,
        .star.lit {
            opacity: 1;
            transform: scale(1.15);
        }

        .ftag {
            border: 1.5px solid var(--border);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--surface);
            cursor: pointer;
            transition: all .15s;
        }

        .ftag:hover {
            border-color: var(--brand);
            color: var(--brand);
            background: #f0fdf4;
        }

        .ftag.active {
            background: #f0fdf4;
            border-color: var(--brand);
            color: var(--brand);
        }

        .rv-item {
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }

        .rv-item:last-child {
            border-bottom: none;
        }

        .rv-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .rv-badge-ssc {
            background: #dcfce7;
            color: #15803d;
        }

        .rv-badge-clas {
            background: #e0e7ff;
            color: #4338ca;
        }

        .rv-reply-box {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border-left: 3px solid var(--brand);
            border-radius: 0 10px 10px 0;
            padding: 10px 14px;
            margin-top: 8px;
            font-size: 12.5px;
            color: #166534;
        }

        .fb-avg-wrap {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 1.5px solid #bbf7d0;
            border-radius: var(--radius);
            padding: 16px 18px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .fb-avg-num {
            font-family: 'Playfair Display', serif;
            font-size: 44px;
            font-weight: 800;
            color: var(--brand-deeper);
            line-height: 1;
            flex-shrink: 0;
        }

        /* ═══ PROFILE ═══════════════════════════════════════════ */
        .profile-av {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #bbf7d0, #4ade80);
            color: #14532d;
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 18px rgba(22, 163, 74, .25);
        }

        /* ═══ MODALS & TOAST ════════════════════════════════════ */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .sf-modal {
            background: var(--surface);
            border-radius: 24px;
            padding: 38px 34px 30px;
            max-width: 480px;
            width: 92%;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 28px 80px rgba(0, 0, 0, .22);
            text-align: center;
            position: relative;
            z-index: 10000;
            animation: modalPop .35s cubic-bezier(.34, 1.56, .64, 1);
        }

        .sf-modal-body {
            overflow-y: auto;
            flex: 1 1 auto;
            padding-right: 4px;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .sf-modal-body::-webkit-scrollbar { width: 5px; }
        .sf-modal-body::-webkit-scrollbar-track { background: transparent; }
        .sf-modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

        @keyframes modalPop {
            from {
                transform: scale(.85) translateY(24px);
                opacity: 0;
            }

            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        .sf-modal-icon {
            font-size: 52px;
            margin-bottom: 14px;
        }

        .sf-modal-title {
            font-size: 21px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 8px;
        }

        .sf-modal-sub {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.65;
            margin-bottom: 24px;
        }

        .sf-modal-actions {
            display: flex;
            gap: 10px;
        }

        .btn-full {
            flex: 1;
        }

        .sf-toast {
            position: fixed;
            bottom: 28px;
            right: 28px;
            background: #0f172a;
            color: #fff;
            padding: 14px 20px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .28);
            transform: translateY(80px);
            opacity: 0;
            transition: transform .3s, opacity .3s;
            z-index: 99999;
            pointer-events: none;
            border-left: 4px solid var(--brand);
        }

        .sf-toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* ═══ VOTE PANELS ════════════════════════════════════════ */
        .vote-panel {
            display: none;
        }

        .vote-panel.active {
            display: block;
        }

        /* ═══ ANIMATIONS ════════════════════════════════════════ */
        @keyframes tabPulse {

            0%,
            100% {
                box-shadow: 0 0 0 2px rgba(16, 185, 129, .25)
            }

            50% {
                box-shadow: 0 0 0 6px rgba(16, 185, 129, .06)
            }
        }

        @keyframes pulseNotif {

            0%,
            100% {
                box-shadow: 0 0 0 2px rgba(16, 185, 129, .3)
            }

            50% {
                box-shadow: 0 0 0 7px rgba(16, 185, 129, .08)
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        .section {
            animation: fadeIn .28s ease;
        }

        /* ═══ PROGRESS / TABLE ══════════════════════════════════ */
        .progress {
            background: #e2e8f0;
            border-radius: 10px;
        }

        .table th {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            color: var(--text-muted);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(22, 163, 74, .15);
        }

        /* ═══ HOME PAGE IMPROVEMENTS ════════════════════════════ */
        .home-greeting {
            background: linear-gradient(135deg, #0f4c24 0%, #15803d 50%, #16a34a 100%);
            border-radius: var(--radius-lg);
            padding: 28px 30px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            color: #fff;
        }

        .home-greeting::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, rgba(255, 255, 255, .07) 1px, transparent 1px);
            background-size: 18px 18px;
            pointer-events: none;
        }

        .home-greeting-title {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
            position: relative;
        }

        .home-greeting-sub {
            font-size: 14px;
            color: rgba(255, 255, 255, .75);
            margin-top: 6px;
            position: relative;
        }

        .home-greeting-emoji {
            position: absolute;
            right: 28px;
            bottom: 16px;
            font-size: 64px;
            opacity: .18;
            pointer-events: none;
        }

        /* ═══ MOBILE ════════════════════════════════════════════ */
        .sf-hamburger {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1100;
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: 10px;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            box-shadow: var(--shadow);
        }

        .sf-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .42);
            z-index: 999;
        }

        .sf-overlay.active {
            display: block;
        }

        @media (max-width: 992px) {
            .sf-hamburger {
                display: flex;
            }

            .sf-sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sf-sidebar.sb-open {
                transform: translateX(0);
            }

            .sf-main {
                margin-left: 0;
                padding: 76px 18px 60px;
            }
        }

        @media (max-width: 576px) {
            .sf-page-title {
                font-size: 26px;
            }

            .stat-val {
                font-size: 36px;
            }
        }

        /* ═══ LIVE RESULTS — ENHANCED UI ══════════════════════════════ */

        /* Pulse dot next to "Auto-updating" */
        .live-pulse-dot {
            display: inline-block; width: 9px; height: 9px;
            background: #22c55e; border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(34,197,94,.5);
            animation: livePulse 1.8s ease-in-out infinite;
        }
        @keyframes livePulse {
            0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.45); }
            70%  { box-shadow: 0 0 0 8px rgba(34,197,94,0); }
            100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
        }

        /* Election switcher buttons */
        .res-election-switcher {
            display: flex; gap: 10px; margin-bottom: 22px; flex-wrap: wrap;
        }
        .res-election-btn {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 14px; border: 2px solid #e2e8f0;
            background: #fff; cursor: pointer; font-size: 14px; font-weight: 600;
            color: #64748b; transition: all .2s ease; flex: 1; min-width: 160px;
        }
        .res-election-btn.active {
            background: linear-gradient(135deg,#16a34a,#15803d);
            border-color: #15803d; color: #fff;
            box-shadow: 0 4px 16px rgba(22,163,74,.3);
        }
        .res-election-btn.clas.active {
            background: linear-gradient(135deg,#6366f1,#4f46e5);
            border-color: #4f46e5;
            box-shadow: 0 4px 16px rgba(99,102,241,.3);
        }
        .res-election-icon { font-size: 18px; }
        .res-election-label { flex: 1; text-align: left; }
        .res-election-badge { font-size: 10px !important; }

        /* Expiry banner */
        .res-expiry-banner {
            display: flex; align-items: center; gap: 10px;
            background: #fffbeb; border: 1.5px solid #fbbf24;
            border-radius: 12px; padding: 10px 16px;
            font-size: 13.5px; color: #92400e; margin-bottom: 16px;
        }

        /* Loading state */
        .res-loading {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; padding: 60px 20px; color: #94a3b8; font-size: 15px;
        }
        .res-spinner {
            width: 22px; height: 22px; border: 3px solid #e2e8f0;
            border-top-color: #16a34a; border-radius: 50%;
            animation: spin .7s linear infinite; display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Leaders Summary Card */
        .leaders-summary {
            background: linear-gradient(135deg,#f0fdf4,#dcfce7);
            border: 1.5px solid #86efac; border-radius: 18px;
            padding: 18px 20px; margin-bottom: 16px;
        }
        .leaders-summary.clas {
            background: linear-gradient(135deg,#eef2ff,#e0e7ff);
            border-color: #a5b4fc;
        }
        .leaders-summary-title {
            font-size: 10px; font-weight: 800; letter-spacing: .1em;
            text-transform: uppercase; color: #16a34a; margin-bottom: 14px;
            display: flex; align-items: center; gap: 6px;
        }
        .leaders-summary.clas .leaders-summary-title { color: #4f46e5; }
        .leaders-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 8px;
        }
        .leader-chip {
            background: rgba(255,255,255,.85);
            backdrop-filter: blur(8px);
            border-radius: 12px;
            padding: 10px 13px;
            border: 1.5px solid rgba(255,255,255,.9);
            display: flex; flex-direction: column; gap: 3px;
            box-shadow: 0 1px 6px rgba(0,0,0,.06);
            transition: transform .15s, box-shadow .15s;
        }
        .leader-chip:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,.1); }
        .leaders-summary.clas .leader-chip { border-color: rgba(165,180,252,.3); }
        .leader-chip-pos {
            font-size: 9.5px; color: #64748b; font-weight: 700;
            text-transform: uppercase; letter-spacing: .05em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .leader-chip-name {
            font-size: 13px; font-weight: 800; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .leader-chip-votes {
            font-size: 11px; color: #16a34a; font-weight: 700;
        }
        .leaders-summary.clas .leader-chip-votes { color: #4f46e5; }

        /* ── Position nav bar ─────────────────────────────────── */
        .position-pills-wrap {
            background: linear-gradient(135deg,#f8fafc,#fff);
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            margin-bottom: 22px;
            padding: 6px 8px;
            scrollbar-width: none;
            -ms-overflow-style: none;
            box-shadow: 0 2px 12px rgba(0,0,0,.07), inset 0 1px 0 rgba(255,255,255,.9);
        }
        .position-pills-wrap::-webkit-scrollbar { display: none; }

        .pills-label {
            font-size: 10px; font-weight: 800; color: #94a3b8;
            letter-spacing: .08em; text-transform: uppercase;
            padding: 6px 12px 6px 6px; white-space: nowrap; flex-shrink: 0;
            border-right: 1.5px solid #e2e8f0; margin-right: 6px;
            align-self: center;
        }

        .pos-pill {
            padding: 7px 15px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            background: transparent;
            border: none;
            color: #64748b;
            cursor: pointer;
            transition: all .18s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .pos-pill:hover { background: #f1f5f9; color: #1e293b; }
        .pos-pill.active-pos-pill {
            background: linear-gradient(135deg,#16a34a,#15803d);
            color: #fff;
            box-shadow: 0 3px 10px rgba(22,163,74,.35);
        }

        /* ── CLAS Dropdown ─────────────────────────────────────── */
        .pos-dropdown {
            flex: 1;
            padding: 8px 14px;
            border-radius: 11px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            outline: none;
            transition: border-color .18s, box-shadow .18s;
            font-family: 'DM Sans', sans-serif;
        }
        .pos-dropdown:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
        .pos-dropdown.clas-dropdown:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.13); }

        /* ── Global UI upgrades ──────────────────────────────── */
        .stat-card { transition: transform .22s cubic-bezier(.34,1.56,.64,1), box-shadow .22s !important; }
        .stat-card:hover { transform: translateY(-6px) scale(1.01) !important; }

        .el-ov-card, .sf-card { transition: box-shadow .2s, transform .2s !important; }
        .el-ov-card:hover, .sf-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,.12) !important; transform: translateY(-2px) !important; }

        .candidate-card { border-radius: 20px !important; box-shadow: 0 3px 12px rgba(0,0,0,.08) !important; transition: border-color .18s, box-shadow .2s, transform .2s !important; }
        .candidate-card:hover { transform: translateY(-5px) scale(1.02) !important; box-shadow: 0 10px 28px rgba(22,163,74,.18) !important; }
        .clas-card:hover { box-shadow: 0 10px 28px rgba(99,102,241,.18) !important; }

        .res-election-btn { border-radius: 16px !important; transition: all .22s ease !important; }
        .res-election-btn:hover:not(.active) { background: #f8fafc !important; border-color: #cbd5e1 !important; transform: translateY(-1px); }
        .res-election-btn.active { box-shadow: 0 5px 18px rgba(22,163,74,.32) !important; }
        .res-election-btn.clas.active { box-shadow: 0 5px 18px rgba(99,102,241,.32) !important; }

        .lb-position-card { border-radius: 18px !important; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.06) !important; transition: box-shadow .2s !important; }
        .lb-position-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.1) !important; }

        .leaders-summary { border-radius: 20px !important; box-shadow: 0 3px 14px rgba(22,163,74,.1); }
        .leaders-summary.clas { box-shadow: 0 3px 14px rgba(99,102,241,.1); }

        .leader-chip { border-radius: 14px !important; transition: transform .16s, box-shadow .16s; }
        .leader-chip:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }

        .sf-modal { border-radius: 28px !important; box-shadow: 0 32px 80px rgba(0,0,0,.25) !important; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        @keyframes sectionSlideIn { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        .section { animation: sectionSlideIn .32s cubic-bezier(.22,1,.36,1); }

        /* ── Position result block (wraps each position from user.js) ── */
        .res-position-block {
            background: #fff; border-radius: 18px;
            border: 1.5px solid #e2e8f0;
            margin-bottom: 14px; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            transition: box-shadow .2s;
        }
        .res-position-block:hover { box-shadow: 0 4px 18px rgba(0,0,0,.1); }

        .res-position-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; cursor: pointer; user-select: none;
            border-bottom: 1.5px solid #f1f5f9; background: #fafafa;
            transition: background .15s;
        }
        .res-position-header:hover { background: #f1f5f9; }
        .res-position-header-left {
            display: flex; align-items: center; gap: 10px;
        }
        .res-position-icon {
            width: 34px; height: 34px; border-radius: 10px;
            background: linear-gradient(135deg,#dcfce7,#bbf7d0);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .res-position-block.clas-block .res-position-icon {
            background: linear-gradient(135deg,#e0e7ff,#c7d2fe);
        }
        .res-position-name {
            font-size: 14px; font-weight: 700; color: #0f172a;
        }
        .res-position-total {
            font-size: 12px; color: #94a3b8; margin-top: 1px;
        }
        .res-position-chevron {
            font-size: 13px; color: #94a3b8;
            transition: transform .25s ease;
        }
        .res-position-block.collapsed .res-position-chevron {
            transform: rotate(-90deg);
        }

        .res-position-body {
            padding: 14px 18px; display: flex; flex-direction: column; gap: 10px;
        }
        .res-position-block.collapsed .res-position-body {
            display: none;
        }

        /* Candidate result row */
        .res-cand-row {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px; border-radius: 12px;
            border: 1.5px solid #f1f5f9; background: #fafafa;
            transition: all .2s ease; position: relative; overflow: hidden;
        }
        .res-cand-row.leading {
            background: linear-gradient(135deg,#f0fdf4,#dcfce7);
            border-color: #86efac;
        }
        .res-position-block.clas-block .res-cand-row.leading {
            background: linear-gradient(135deg,#eef2ff,#e0e7ff);
            border-color: #a5b4fc;
        }
        .res-cand-avatar {
            width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg,#bbf7d0,#4ade80);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 15px; color: #14532d;
            overflow: hidden; border: 2px solid rgba(255,255,255,.8);
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
        }
        .res-cand-avatar img { width:100%;height:100%;object-fit:cover; }
        .res-position-block.clas-block .res-cand-avatar {
            background: linear-gradient(135deg,#c7d2fe,#818cf8);
            color: #3730a3;
        }
        .res-cand-info { flex: 1; min-width: 0; }
        .res-cand-name {
            font-size: 13.5px; font-weight: 700; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .res-cand-bar-wrap {
            height: 7px; background: #e2e8f0; border-radius: 99px;
            margin-top: 5px; overflow: hidden;
        }
        .res-cand-bar {
            height: 100%; border-radius: 99px;
            background: linear-gradient(90deg,#22c55e,#16a34a);
            transition: width .6s cubic-bezier(.22,1,.36,1);
        }
        .res-position-block.clas-block .res-cand-bar {
            background: linear-gradient(90deg,#818cf8,#6366f1);
        }
        .res-cand-row.leading .res-cand-bar { background: linear-gradient(90deg,#16a34a,#15803d); }
        .res-cand-right {
            display: flex; flex-direction: column; align-items: flex-end; gap: 3px;
            flex-shrink: 0;
        }
        .res-cand-votes {
            font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1;
        }
        .res-cand-pct {
            font-size: 11px; color: #64748b; font-weight: 500;
        }
        .res-leading-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #16a34a; color: #fff; font-size: 10px; font-weight: 700;
            letter-spacing: .05em; text-transform: uppercase;
            padding: 2px 8px; border-radius: 99px; margin-top: 3px;
        }
        .res-position-block.clas-block .res-leading-badge {
            background: #6366f1;
        }

        /* Archived state */
        .res-archived-state {
            text-align: center; padding: 60px 20px;
        }
        .res-archived-icon { font-size: 64px; margin-bottom: 16px; }
        .res-archived-state h2 {
            font-family: 'Playfair Display',serif; font-weight: 700;
            font-size: 22px; margin-bottom: 10px;
        }
        .res-archived-state p {
            color: #64748b; max-width: 420px; margin: 0 auto 16px; line-height: 1.7; font-size: 14px;
        }
        .res-archived-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 10px; font-size: 13.5px; font-weight: 600;
        }
        .res-archived-badge.green { background:#f0fdf4;border:1.5px solid #86efac;color:#166534; }
        .res-archived-badge.purple { background:#ede9fe;border:1.5px solid #a78bfa;color:#3730a3; }

        /* Results main container scroll anchor */
        .results-main-container { scroll-margin-top: 80px; }

        /* ── LEADERBOARD CARD STYLES ──────────────────────────────── */
        .lb-position-card {
            background: #fff;
            border-radius: 20px;
            border: 1.5px solid #e2e8f0;
            margin-bottom: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,.05);
            transition: box-shadow .2s, transform .2s;
            scroll-margin-top: 80px;
        }
        .lb-position-card:hover { box-shadow: 0 6px 28px rgba(0,0,0,.09); }
        .lb-position-card.lb-collapsed .lb-pos-body { display: none; }

        .lb-pos-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            background: #fafbfc;
            border-bottom: 1.5px solid #f1f5f9;
        }
        .lb-pos-icon {
            width: 40px; height: 40px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }
        .lb-pos-meta { flex: 1; min-width: 0; }
        .lb-pos-name {
            font-size: 15px; font-weight: 800; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            letter-spacing: -.01em;
        }
        .lb-pos-sub { font-size: 11.5px; color: #94a3b8; margin-top: 2px; font-weight: 500; }
        .lb-collapse-btn {
            background: #f1f5f9; border: none; border-radius: 8px;
            font-size: 11px; font-weight: 700; color: #64748b;
            padding: 5px 10px; cursor: pointer; white-space: nowrap;
            transition: background .15s;
        }
        .lb-collapse-btn:hover { background: #e2e8f0; }

        .lb-pos-body { padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; }

        /* Candidate row */
        .lb-cand-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1.5px solid #f1f5f9;
            background: #fafafa;
            transition: background .15s, box-shadow .15s;
        }
        .lb-cand-row:hover { background: #f8fafc; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
        .lb-cand-row.lb-leader {
            border-width: 2px;
        }

        .lb-rank {
            font-size: 20px; width: 30px; text-align: center; flex-shrink: 0; line-height: 1;
        }

        .lb-avatar {
            width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg,#bbf7d0,#4ade80);
            color: #14532d; font-weight: 800; font-size: 14px;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
        }
        .lb-avatar img { width:100%; height:100%; object-fit:cover; }
        .lb-avatar span { display:flex; align-items:center; justify-content:center; width:100%; height:100%; }

        .lb-info { flex: 1; min-width: 0; }
        .lb-name {
            font-size: 13.5px; font-weight: 700; color: #0f172a;
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .lb-leading-tag {
            font-size: 9px; font-weight: 800; letter-spacing: .07em;
            text-transform: uppercase; color: #fff;
            padding: 3px 8px; border-radius: 99px;
        }
        .lb-motto {
            font-size: 11px; color: #94a3b8; margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .lb-bar-wrap {
            height: 6px; background: #e2e8f0; border-radius: 99px;
            margin-top: 8px; overflow: hidden;
        }
        .lb-bar {
            height: 100%; border-radius: 99px;
            transition: width .8s cubic-bezier(.22,1,.36,1);
        }

        .lb-stats {
            display: flex; flex-direction: column; align-items: flex-end;
            flex-shrink: 0; min-width: 52px; gap: 2px;
        }
        .lb-votes {
            font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1;
        }
        .lb-pct { font-size: 11.5px; color: #94a3b8; font-weight: 600; margin-top: 2px; }

        .lb-empty {
            text-align: center; padding: 24px; color: #94a3b8; font-size: 13px;
        }

        .pills-label-REMOVED {
            font-size: 12px; font-weight: 700; color: #94a3b8;
            align-self: center; white-space: nowrap; flex-shrink: 0;
        }

        @media (max-width: 600px) {
            .lb-cand-row { padding: 10px 10px; gap: 8px; }
            .lb-avatar { width: 38px; height: 38px; font-size: 12px; }
            .lb-votes { font-size: 18px; }
            .lb-rank { font-size: 16px; width: 26px; }
            .leaders-summary-grid { grid-template-columns: repeat(auto-fill,minmax(130px,1fr)); }
        }
    </style>
    </style>
</head>

<body>

    <!-- Mobile hamburger -->
    <button class="sf-hamburger" id="sbHamburger" onclick="toggleSidebar()" aria-label="Menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="sf-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

    <!-- ══ SIDEBAR ══ -->
    <aside class="sf-sidebar">
        <div class="sf-logo-wrap">
            <div class="sf-logo">
                <img src="suffra.png" alt="Suffra Logo"
                    style="width:44px;height:44px;object-fit:contain;mix-blend-mode:screen;flex-shrink:0;filter:drop-shadow(0 0 6px rgba(74,222,128,.45));">
                SUFFRA<span>TECH</span>
            </div>
        </div>
        <div class="sf-logo-sub">E-Voting Platform</div>

        <div class="sf-nav-label">Main</div>
        <button class="sf-nav-btn active" data-page="home" onclick="showPage('home',this)">
            <i class="bi bi-house-fill"></i> Home
        </button>
        <button class="sf-nav-btn" data-page="vote" onclick="showPage('vote',this)">
            <i class="bi bi-check2-square"></i> Vote
            <?php if ($showVoteBadge): ?><div class="dot ms-auto"></div><?php endif; ?>
        </button>
        <button class="sf-nav-btn" data-page="results" onclick="showPage('results',this)">
            <i class="bi bi-bar-chart-fill"></i> Results
        </button>

        <div class="sf-nav-label">Account</div>
        <button class="sf-nav-btn" data-page="feedback" onclick="showPage('feedback',this)">
            <i class="bi bi-chat-left-text"></i> Feedback
        </button>
        <button class="sf-nav-btn" data-page="profile" onclick="showPage('profile',this)">
            <i class="bi bi-person-circle"></i> Edit Details
        </button>

        <div class="flex-grow-1"></div>

        <div class="sf-user-card">
            <?php if (!empty($user['profile_photo']) && file_exists($user['profile_photo'])): ?>
                <img id="sidebarAvatarImg" src="<?= h($user['profile_photo']) ?>" alt="Avatar"
                    style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #bbf7d0">
            <?php else: ?>
                <div class="sf-avatar"><?= h($initials) ?></div>
                <img id="sidebarAvatarImg" src="" alt="" style="display:none;width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #bbf7d0">
            <?php endif; ?>
            <div style="overflow:hidden">
                <div class="sf-uname" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $full_name ?></div>
                <div class="sf-urole">Active Voter</div>
            </div>
        </div>
        <button style="width:100%;padding:10px 14px;border-radius:12px;border:1.5px solid rgba(255,255,255,.25);background:rgba(255,255,255,.08);color:rgba(255,255,255,.8);font-family:'DM Sans',sans-serif;font-size:13.5px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .18s;position:relative;z-index:1" onmouseover="this.style.background='rgba(239,68,68,.25)';this.style.borderColor='rgba(239,68,68,.4)';this.style.color='#fff'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.borderColor='rgba(255,255,255,.25)';this.style.color='rgba(255,255,255,.8)'" onclick="confirmLogout()">
            <i class="bi bi-box-arrow-right"></i> Sign Out
        </button>
    </aside>

    <!-- ══ MAIN ══ -->
    <main class="sf-main">

        <!-- ── HOME ── -->
        <div id="home" class="section">
            <!-- Greeting banner -->
            <div class="home-greeting mb-4">
                <div class="home-greeting-title">Welcome back, <?= h($user['first_name']) ?>! 👋</div>
                <div class="home-greeting-sub">
                    <?php if ($user['has_voted'] && $user['has_voted_clas']): ?>
                        You've voted in both elections. Thank you for participating! 🎉
                    <?php elseif ($genStatus === 'Ongoing' || $clasStatus === 'Ongoing'): ?>
                        An election is open right now — don't miss your chance to vote!
                    <?php else: ?>
                        Here's your current voting overview for <?= date('Y') ?>.
                    <?php endif; ?>
                </div>
                <div class="home-greeting-emoji">🗳️</div>
            </div>

            <!-- Stat cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card stat-pink">
                        <div class="stat-label">Total Voters</div>
                        <div class="stat-val" id="totalVoters"><?= number_format($total_voters) ?></div>
                        <div class="stat-note">Registered this cycle</div>
                        <i class="bi bi-people-fill stat-icon"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card stat-orange">
                        <div class="stat-label">General Votes Cast</div>
                        <div class="stat-val" id="votesCast"><?= number_format($votes_cast) ?></div>
                        <div class="stat-note">Live count</div>
                        <i class="bi bi-check2-square stat-icon"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card stat-teal">
                        <div class="stat-label">CLAS Votes Cast</div>
                        <div class="stat-val" id="clasVotesCast"><?= number_format($clas_votes_cast) ?></div>
                        <div class="stat-note">Live count</div>
                        <i class="bi bi-mortarboard-fill stat-icon"></i>
                    </div>
                </div>
            </div>

            <!-- Election overview cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="el-ov-card">
                        <div class="el-ov-title"><i class="bi bi-check2-square me-1"></i>General Election</div>
                        <div class="fw-bold mb-2" style="font-size:15px"><?= h($election['title'] ?? 'General Election 2026') ?></div>
                        <span class="badge <?= bsBadge($genStatus) ?> rounded-pill mb-2">
                            <?php if ($genStatus === 'Ongoing'): ?>
                                <span class="me-1" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#fff;animation:tabPulse 1.5s infinite"></span>
                            <?php endif; ?>
                            <?= h($genStatus) ?>
                        </span>
                        <div style="font-size:13px" class="mt-1">
                            <?php if ($user['has_voted']): ?>
                                <span class="text-success fw-semibold">✅ You have voted.</span>
                            <?php elseif ($genStatus === 'Ongoing'): ?>
                                <span class="text-danger fw-semibold">⚡ Vote now before it closes!</span>
                            <?php elseif ($genStatus === 'Ended'): ?>
                                <span class="text-secondary">Election has ended.</span>
                            <?php else: ?>
                                <span class="text-secondary">You have not voted yet.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="el-ov-card el-ov-clas">
                        <div class="el-ov-title"><i class="bi bi-mortarboard-fill me-1"></i>CLAS Council Election</div>
                        <div class="fw-bold mb-2" style="font-size:15px"><?= h($clasElection['title'] ?? 'CLAS Council Election 2026') ?></div>
                        <span class="badge <?= bsBadge($clasStatus) ?> rounded-pill mb-2">
                            <?php if ($clasStatus === 'Ongoing'): ?>
                                <span class="me-1" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#fff;animation:tabPulse 1.5s infinite"></span>
                            <?php endif; ?>
                            <?= h($clasStatus) ?>
                        </span>
                        <div style="font-size:13px" class="mt-1">
                            <?php if (!$isClasEligible): ?>
                                <span class="text-danger fw-semibold">🚫 Your program is not eligible for CLAS voting.</span>
                            <?php elseif ($user['has_voted_clas']): ?>
                                <span class="text-success fw-semibold">✅ You have voted.</span>
                            <?php elseif ($clasStatus === 'Ongoing'): ?>
                                <span class="text-danger fw-semibold">⚡ Vote now before it closes!</span>
                            <?php elseif ($clasStatus === 'Ended'): ?>
                                <span class="text-secondary">Election has ended.</span>
                            <?php else: ?>
                                <span class="text-secondary">You have not voted yet.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timeline + Quick Actions -->
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card border h-100">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3"><i class="bi bi-clock text-success me-2"></i>Election Timeline</h6>
                            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:14px">
                                <span class="fw-semibold">General Turnout</span>
                                <span class="fw-bold text-success" id="turnoutPct"><?= $turnoutPct ?>%</span>
                            </div>
                            <div class="progress mb-3" style="height:10px;border-radius:10px">
                                <div class="progress-bar bg-success" id="turnoutBar"
                                    style="width:<?= $turnoutPct ?>%;border-radius:10px" role="progressbar"></div>
                            </div>
                            <hr class="my-3">
                            <table class="table table-bordered table-hover align-middle mb-0" style="font-size:13.5px">
                                <thead class="table-light">
                                    <tr>
                                        <th>Election</th>
                                        <th>Status</th>
                                        <th>Your Vote</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>General</td>
                                        <td><span class="badge <?= bsBadge($genStatus) ?>"><?= h($genStatus) ?></span></td>
                                        <td>
                                            <?php if ($user['has_voted']): ?>
                                                <span class="badge bg-success">Voted ✓</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>CLAS Council</td>
                                        <td><span class="badge <?= bsBadge($clasStatus) ?>"><?= h($clasStatus) ?></span></td>
                                        <td>
                                            <?php if ($user['has_voted_clas']): ?>
                                                <span class="badge bg-success">Voted ✓</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border h-100">
                        <div class="card-body d-flex flex-column">
                            <h6 class="fw-bold mb-3"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Quick Actions</h6>
                            <div class="d-grid gap-2 mb-3">
                                <button class="btn btn-success" onclick="navTo('vote')">
                                    <i class="bi bi-check2-square me-2"></i>Cast Your Vote Now
                                </button>
                                <button class="btn btn-outline-success" onclick="navTo('results')">
                                    <i class="bi bi-bar-chart me-2"></i>View Live Results
                                </button>
                            </div>
                            <div class="alert alert-success py-3 mb-0 mt-auto" style="font-size:13.5px">
                                <div class="fw-bold mb-1">📋 Your Status</div>
                                <div class="status-dynamic">
                                    <?php if ($user['has_voted'] && $user['has_voted_clas']): ?>
                                        ✅ You have <strong>voted in both elections</strong>. Thank you!
                                    <?php elseif ($user['has_voted']): ?>
                                        ✅ General voted. <?= $clasStatus === 'Ongoing' ? '⚡ <strong>Cast your CLAS ballot!</strong>' : 'CLAS not open yet.' ?>
                                    <?php elseif ($user['has_voted_clas']): ?>
                                        ✅ CLAS voted. <?= $genStatus === 'Ongoing' ? '⚡ <strong>Cast your General ballot!</strong>' : 'General not open yet.' ?>
                                    <?php elseif ($genStatus === 'Ongoing' || $clasStatus === 'Ongoing'): ?>
                                        ⚡ You have <strong>not yet voted</strong>. Cast your ballot before the deadline!
                                    <?php else: ?>
                                        ⏳ No elections are currently open.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── VOTE ── -->
        <div id="vote" class="section" style="display:none">

            <!-- ══ ELECTION PICKER SCREEN ══ -->
            <div id="electionPickerScreen">
                <h1 class="sf-page-title" style="text-align:center;font-family:'Playfair Display',serif;font-size:28px;font-weight:800;letter-spacing:.5px;margin-bottom:6px">AVAILABLE ELECTIONS</h1>
                <p class="sf-page-sub" style="text-align:center;margin-bottom:28px">Click an election to cast your vote.</p>

                <div class="row g-3 justify-content-center" style="max-width:700px;margin:0 auto">
                    <!-- SSC Card -->
                    <div class="col-sm-6">
                        <?php
                        $sscClickable = ($genStatus === 'Ongoing' && !$user['has_voted']) || $user['has_voted'];
                        $sscOnclick   = $sscClickable ? "enterVoting('general')" : '';
                        ?>
                        <div class="av-election-card <?= $sscClickable ? 'av-clickable' : 'av-locked' ?>"
                            <?= $sscOnclick ? "onclick=\"{$sscOnclick}\"" : '' ?>>
                            <div class="av-card-title"><?= h($election['title'] ?? 'Student Council 2026') ?></div>
                            <div class="av-card-sub">Vote for your leaders</div>
                            <div class="av-card-status">
                                <?php if ($user['has_voted']): ?>
                                    <span class="av-status-voted">✓ Already Voted</span>
                                <?php elseif ($genStatus === 'Ongoing'): ?>
                                    <span class="av-status-ongoing">Ongoing</span>
                                <?php elseif ($genStatus === 'Ended'): ?>
                                    <span class="av-status-ended">Ended</span>
                                <?php else: ?>
                                    <span class="av-status-ns">Not Started</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- CLAS Card -->
                    <div class="col-sm-6">
                        <?php
                        $clasClickable = $isClasEligible && (($clasStatus === 'Ongoing' && !$user['has_voted_clas']) || $user['has_voted_clas']);
                        $clasOnclick   = $clasClickable ? "enterVoting('clas')" : '';
                        ?>
                        <div class="av-election-card <?= $clasClickable ? 'av-clickable' : 'av-locked' ?>"
                            <?= $clasOnclick ? "onclick=\"{$clasOnclick}\"" : '' ?>>
                            <div class="av-card-title"><?= h($clasElection['title'] ?? 'CLAS Council 2026') ?></div>
                            <div class="av-card-sub">
                                <?php if (!$isClasEligible): ?>
                                    <span style="color:#ef4444;font-size:12px"><i class="bi bi-lock-fill me-1"></i>CLAS students only</span>
                                <?php else: ?>
                                    Vote for your leaders
                                <?php endif; ?>
                            </div>
                            <div class="av-card-status">
                                <?php if (!$isClasEligible): ?>
                                    <span class="av-status-ended" style="background:#fee2e2;color:#b91c1c">🚫 Not Eligible</span>
                                <?php elseif ($user['has_voted_clas']): ?>
                                    <span class="av-status-voted">✓ Already Voted</span>
                                <?php elseif ($clasStatus === 'Ongoing'): ?>
                                    <span class="av-status-ongoing">Ongoing</span>
                                <?php elseif ($clasStatus === 'Ended'): ?>
                                    <span class="av-status-ended">Ended</span>
                                <?php else: ?>
                                    <span class="av-status-ns">Not Started</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ VOTING SCREEN (hidden until election picked) ══ -->
            <div id="votingScreen" style="display:none">
                <div class="d-flex align-items-center gap-3 mb-1">
                    <button class="btn btn-sm btn-outline-secondary" onclick="backToElectionPicker()">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </button>
                    <h1 class="sf-page-title mb-0">Vote Candidates</h1>
                </div>
                <p class="sf-page-sub">Select one candidate per position. Your vote is confidential and cannot be changed after submission.</p>

                <!-- Election tabs (nav-pills) — hidden, used internally -->
                <ul class="nav nav-pills mb-4 gap-2" id="voteTabNav" style="display:none!important">
                    <li class="nav-item">
                        <button class="nav-link active d-flex align-items-center gap-2" id="tab-general" onclick="switchVoteTab('general')">
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $genStatus === 'Ongoing' ? '#fff' : 'rgba(255,255,255,.4)' ?>;display:inline-block"></span>
                            🗳️ General
                            <span class="badge <?= bsBadge($genStatus) ?> ms-1"><?= h($genStatus) ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link d-flex align-items-center gap-2" id="tab-clas"
                            style="background:transparent;color:#6366f1;border:1.5px solid #e2e8f0"
                            onclick="switchVoteTab('clas')">
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $clasStatus === 'Ongoing' ? '#6366f1' : '#cbd5e1' ?>;display:inline-block"></span>
                            🎓 CLAS Council
                            <span class="badge <?= bsBadge($clasStatus) ?> ms-1"><?= h($clasStatus) ?></span>
                        </button>
                    </li>
                </ul>

                <!-- ══ GENERAL PANEL ══ -->
                <div class="vote-panel active" id="panel-general">
                    <?php if ($user['has_voted']): ?>
                        <div class="text-center py-5">
                            <div class="mb-3" style="font-size:72px">🗳️</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif">General Vote Submitted!</h2>
                            <p class="text-muted mx-auto" style="max-width:380px;line-height:1.7">Your ballot has been securely recorded. Thank you for participating in <?= h($election['title'] ?? 'General Election 2026') ?>.</p>
                            <div class="d-flex gap-2 justify-content-center flex-wrap mt-4">
                                <?php if ($clasStatus === 'Ongoing' && !$user['has_voted_clas']): ?>
                                    <button class="btn btn-success" onclick="switchVoteTab('clas')">Vote in CLAS Election →</button>
                                <?php endif; ?>
                                <button class="btn btn-outline-success" onclick="navTo('results')">View Results</button>
                            </div>
                        </div>

                    <?php elseif ($genStatus !== 'Ongoing'): ?>
                        <div class="text-center py-5">
                            <div class="mb-3" style="font-size:72px">🗳️</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif">General Voting is <?= $genStatus === 'Ended' ? 'Closed' : 'Not Open Yet' ?></h2>
                            <p class="text-muted mx-auto" style="max-width:380px">
                                <?php if ($genStatus === 'Ended'): ?>
                                    The election has ended.
                                <?php else: ?>
                                    The General election has not started yet.
                                    <?php if (!empty($election['start_dt'])): ?>
                                        <br>Opens: <strong><?= date('M d, Y · h:i A', strtotime($election['start_dt'])) ?></strong>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($genStatus === 'Ended'): ?>
                                <button class="btn btn-success mt-3" onclick="navTo('results')">View Results</button>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <div id="voteContent">
                            <?php foreach ($generalPosWithCands as $pos): ?>
                                <div class="position-section mb-4">
                                    <div class="pos-header">
                                        <i class="bi bi-star-fill text-success"></i>
                                        <?= h($pos['name']) ?>
                                        <span class="badge bg-success-subtle text-success-emphasis ms-2" style="font-size:11px">Select 1</span>
                                    </div>
                                    <div class="cand-scroll-row" id="pos-group-<?= (int)$pos['id'] ?>">
                                        <?php foreach ($pos['candidates'] as $cand): ?>
                                            <?php $av = avatarInitials($cand['full_name']); ?>
                                            <div class="cand-slot">
                                                <div class="candidate-card"
                                                    data-position-id="<?= (int)$pos['id'] ?>"
                                                    data-candidate-id="<?= (int)$cand['id'] ?>"
                                                    onclick="selectCandidate(this)">
                                                    <div class="checkmark">✓</div>
                                                    <?php
                                                        $photoVal = $cand['photo'] ?? '';
                                                        if (!empty($photoVal)) {
                                                            // Handle both "filename.jpg" and "uploads/candidates/filename.jpg"
                                                            $photoSrc = str_starts_with($photoVal, 'uploads/') ? $photoVal : 'uploads/candidates/' . $photoVal;
                                                        } else {
                                                            $photoSrc = '';
                                                        }
                                                    ?>
                                                    <?php if (!empty($photoSrc)): ?>
                                                        <div class="cand-photo-wrap" id="wrap-gen-<?= (int)$cand['id'] ?>">
                                                            <img src="<?= h($photoSrc) ?>" alt="<?= h($cand['full_name']) ?>"
                                                                onerror="this.closest('.cand-photo-wrap').style.display='none';document.getElementById('av-gen-<?= (int)$cand['id'] ?>').style.display='flex';">
                                                        </div>
                                                        <div class="candidate-avatar" id="av-gen-<?= (int)$cand['id'] ?>" style="display:none"><?= h($av) ?></div>
                                                    <?php else: ?>
                                                        <div class="candidate-avatar"><?= h($av) ?></div>
                                                    <?php endif; ?>
                                                    <div class="candidate-name"><?= h($cand['full_name']) ?></div>
                                                    <?php if (!empty($cand['motto'])): ?>
                                                    <div class="candidate-party"><?= h($cand['motto']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($pos['candidates'])): ?>
                                            <p class="text-muted small" style="padding:8px 0">No candidates registered for this position.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="vote-submit-wrap">
                                <button class="btn btn-success btn-lg px-5" onclick="submitVote('general')">
                                    <i class="bi bi-check2-square me-2"></i>Submit General Ballot
                                </button>
                                <p class="text-muted mt-3" style="font-size:13px">Once submitted, your vote cannot be changed.</p>
                            </div>
                        </div>

                        <div id="voteSuccess" style="display:none;text-align:center;padding:60px 20px">
                            <div class="mb-3" style="font-size:72px">🗳️</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif">General Vote Submitted!</h2>
                            <p class="text-muted mx-auto" style="max-width:380px;line-height:1.7">Your ballot has been securely recorded.</p>
                            <div class="d-flex gap-2 justify-content-center mt-3">
                                <button class="btn btn-success" onclick="switchVoteTab('clas')">Vote in CLAS Election →</button>
                                <button class="btn btn-outline-success" onclick="navTo('results')">View Results</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ══ CLAS PANEL ══ -->
                <div class="vote-panel" id="panel-clas">
                    <?php if (!$isClasEligible): ?>
                        <div class="text-center py-5">
                            <div class="mb-3" style="font-size:72px">🚫</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif;color:#b91c1c">Not Eligible</h2>
                            <p class="text-muted mx-auto" style="max-width:420px;line-height:1.7">
                                The <strong>CLAS Council Election</strong> is exclusively for students enrolled in CLAS programs.<br><br>
                                <span style="font-size:13px">Eligible programs: BS Mathematics, BS Psychology, BA Political Science, BA Communication, BA Behavioral Science, BSIT, BSIS, BSEMC, BSCS, BPA Public Administration.</span>
                            </p>
                            <button class="btn btn-outline-secondary mt-3" onclick="backToElectionPicker()"><i class="bi bi-arrow-left me-1"></i>Go Back</button>
                        </div>

                    <?php elseif ($user['has_voted_clas']): ?>
                        <div class="text-center py-5">
                            <div class="mb-3" style="font-size:72px">🎓</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif">CLAS Vote Submitted!</h2>
                            <p class="text-muted">Your CLAS ballot has been recorded. Thank you!</p>
                            <button class="btn btn-outline-primary mt-3" onclick="navTo('results')">View Results</button>
                        </div>

                    <?php elseif ($clasStatus !== 'Ongoing'): ?>
                        <div class="text-center py-5">
                            <div class="mb-3" style="font-size:72px">🎓</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif">CLAS Voting is <?= $clasStatus === 'Ended' ? 'Closed' : 'Not Open Yet' ?></h2>
                            <p class="text-muted mx-auto" style="max-width:380px">
                                <?php if ($clasStatus === 'Ended'): ?>
                                    The CLAS election has ended.
                                <?php else: ?>
                                    The CLAS election is currently <strong>Not Started</strong>.
                                    <?php if (!empty($clasElection['start_dt'])): ?>
                                        <br>Opens: <strong><?= date('M d, Y · h:i A', strtotime($clasElection['start_dt'])) ?></strong>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($clasStatus === 'Ended'): ?>
                                <button class="btn btn-primary mt-3" onclick="navToClasResults()">View Results</button>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <div id="clasVoteContent">
                            <?php foreach ($clasPosWithCands as $pos): ?>
                                <div class="position-section mb-4">
                                    <div class="pos-header clas-pos-header">
                                        <i class="bi bi-mortarboard-fill" style="color:#6366f1"></i>
                                        <?= h($pos['name']) ?>
                                        <span class="badge ms-2" style="font-size:11px;background:#e0e7ff;color:#3730a3">Select 1</span>
                                    </div>
                                    <div class="cand-scroll-row" id="clas-pos-group-<?= (int)$pos['id'] ?>">
                                        <?php foreach ($pos['candidates'] as $cand): ?>
                                            <?php $av = avatarInitials($cand['full_name']); ?>
                                            <div class="cand-slot">
                                                <div class="candidate-card clas-card"
                                                    data-position-id="<?= (int)$pos['id'] ?>"
                                                    data-candidate-id="<?= (int)$cand['id'] ?>"
                                                    onclick="selectClasCandidate(this)">
                                                    <div class="checkmark">✓</div>
                                                    <?php
                                                        $photoValC = $cand['photo'] ?? '';
                                                        if (!empty($photoValC)) {
                                                            $photoSrcC = str_starts_with($photoValC, 'uploads/') ? $photoValC : 'uploads/candidates/' . $photoValC;
                                                        } else {
                                                            $photoSrcC = '';
                                                        }
                                                    ?>
                                                    <?php if (!empty($photoSrcC)): ?>
                                                        <div class="cand-photo-wrap" id="wrap-clas-<?= (int)$cand['id'] ?>">
                                                            <img src="<?= h($photoSrcC) ?>" alt="<?= h($cand['full_name']) ?>"
                                                                onerror="this.closest('.cand-photo-wrap').style.display='none';document.getElementById('av-clas-<?= (int)$cand['id'] ?>').style.display='flex';">
                                                        </div>
                                                        <div class="candidate-avatar" id="av-clas-<?= (int)$cand['id'] ?>" style="display:none"><?= h($av) ?></div>
                                                    <?php else: ?>
                                                        <div class="candidate-avatar"><?= h($av) ?></div>
                                                    <?php endif; ?>
                                                    <div class="candidate-name"><?= h($cand['full_name']) ?></div>
                                                    <?php if (!empty($cand['motto'])): ?>
                                                    <div class="candidate-party"><?= h($cand['motto']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($pos['candidates'])): ?>
                                            <p class="text-muted small" style="padding:8px 0">No candidates registered for this position.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="vote-submit-wrap">
                                <button class="btn btn-lg px-5" style="background:#6366f1;color:#fff;border:none" onclick="submitVote('clas')">
                                    <i class="bi bi-check2-square me-2"></i>Submit CLAS Ballot
                                </button>
                                <p class="text-muted mt-3" style="font-size:13px">Once submitted, your vote cannot be changed.</p>
                            </div>
                        </div>

                        <div id="clasVoteSuccess" style="display:none;text-align:center;padding:60px 20px">
                            <div class="mb-3" style="font-size:72px">🎓</div>
                            <h2 class="fw-bold" style="font-family:'Playfair Display',serif">CLAS Vote Submitted!</h2>
                            <button class="btn btn-outline-primary mt-3" onclick="navToClasResults()">View Results</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- /#votingScreen -->
        </div>

        <!-- ── RESULTS ── -->
        <div id="results" class="section" style="display:none">
            <h1 class="sf-page-title">🗳️ Live Results</h1>
            <p class="sf-page-sub" style="display:flex;align-items:center;gap:8px">
                <span class="live-pulse-dot"></span> Auto-updating every 5 seconds
            </p>

            <!-- Election Tabs -->
            <div class="res-election-switcher">
                <button class="res-election-btn active" id="results-tab-general" onclick="switchResultsElectionTab('general')">
                    <span class="res-election-icon">🗳️</span>
                    <span class="res-election-label">General</span>
                    <span class="badge <?= bsBadge($genStatus) ?> res-election-badge"><?= h($genStatus) ?></span>
                </button>
                <button class="res-election-btn clas" id="results-tab-clas" onclick="switchResultsElectionTab('clas')">
                    <span class="res-election-icon">🎓</span>
                    <span class="res-election-label">CLAS Council</span>
                    <span class="badge <?= bsBadge($clasStatus) ?> res-election-badge"><?= h($clasStatus) ?></span>
                </button>
            </div>

            <!-- ── General Results Panel ── -->
            <div id="results-panel-general" class="results-election-panel">
                <?php if ($genResultsVisible): ?>
                    <?php if ($genStatus === 'Ended' && $genExpirySecondsLeft > 0): ?>
                        <div class="res-expiry-banner">
                            <i class="bi bi-clock-history"></i>
                            Results hidden in <strong><span id="genResultsCountdown"><?= gmdate('i:s', $genExpirySecondsLeft) ?></span></strong>
                        </div>
                    <?php endif; ?>
                    <!-- Leaders summary injected here by JS enhancer -->
                    <div id="genLeadersSummary" class="leaders-summary" style="display:none"></div>
                    <!-- Position quick-jump pills injected here by JS enhancer -->
                    <div id="genPositionPills" class="position-pills-wrap" style="display:none"></div>
                    <div id="generalResultsContainer" class="results-main-container">
                        <div class="res-loading"><span class="res-spinner"></span> Loading results…</div>
                    </div>
                <?php else: ?>
                    <div class="res-archived-state">
                        <div class="res-archived-icon">📋</div>
                        <h2>Results Submitted to Admin</h2>
                        <p>The General Election results are no longer publicly available. They have been forwarded to the administration for official tallying.</p>
                        <div class="res-archived-badge green"><i class="bi bi-shield-check-fill"></i> Results secured &amp; archived by SuffraTech</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── CLAS Results Panel ── -->
            <div id="results-panel-clas" class="results-election-panel" style="display:none">
                <?php if ($clasResultsVisible): ?>
                    <?php if ($clasStatus === 'Ended' && $clasExpirySecondsLeft > 0): ?>
                        <div class="res-expiry-banner">
                            <i class="bi bi-clock-history"></i>
                            Results hidden in <strong><span id="clasResultsCountdown"><?= gmdate('i:s', $clasExpirySecondsLeft) ?></span></strong>
                        </div>
                    <?php endif; ?>
                    <!-- Leaders summary injected here by JS enhancer -->
                    <div id="clasLeadersSummary" class="leaders-summary clas" style="display:none"></div>
                    <!-- Position quick-jump pills injected here by JS enhancer -->
                    <div id="clasPositionPills" class="position-pills-wrap" style="display:none"></div>
                    <div id="clasResultsContainer" class="results-main-container">
                        <div class="res-loading"><span class="res-spinner"></span> Loading results…</div>
                    </div>
                <?php else: ?>
                    <div class="res-archived-state">
                        <div class="res-archived-icon">📋</div>
                        <h2>Results Submitted to Admin</h2>
                        <p>The CLAS Council Election results are no longer publicly available. They have been forwarded to the administration for official tallying.</p>
                        <div class="res-archived-badge purple"><i class="bi bi-shield-check-fill"></i> Results secured &amp; archived by SuffraTech</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── FEEDBACK / REVIEWS ── -->
        <div id="feedback" class="section" style="display:none">
            <h1 class="sf-page-title"><i class="bi bi-chat-heart-fill me-2" style="color:var(--brand)"></i>Feedback &amp; Reviews</h1>
            <p class="sf-page-sub">Rate your voting experience and see what your fellow voters said — including admin replies.</p>

            <div class="row g-4" style="max-width:1000px">

                <!-- ── Submit Form ── -->
                <div class="col-lg-5">
                    <div class="fb-form-card">
                        <div class="fw-bold mb-1" style="font-size:16px"><i class="bi bi-pencil-square me-2 text-success"></i>Share Your Experience</div>
                        <p class="text-muted mb-4" style="font-size:12.5px">Your feedback helps improve the platform for everyone.</p>

                        <!-- Election picker -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" style="font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Which election?</label>
                            <div class="d-flex gap-2">
                                <button id="fbElGenBtn" class="btn ftag active flex-fill" onclick="setFbElection('general',this)">
                                    <i class="bi bi-check2-square me-1"></i>General
                                </button>
                                <button id="fbElClasBtn" class="btn ftag flex-fill" onclick="setFbElection('clas',this)">
                                    <i class="bi bi-mortarboard-fill me-1"></i>CLAS Council
                                </button>
                            </div>
                            <input type="hidden" id="fbElectionType" value="general">
                        </div>

                        <!-- Stars -->
                        <div class="mb-1">
                            <label class="form-label fw-semibold" style="font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Your Rating</label>
                        </div>
                        <div class="mb-1 d-flex gap-2" id="stars">
                            <span class="star" data-v="1" onclick="setRating(1)">⭐</span>
                            <span class="star" data-v="2" onclick="setRating(2)">⭐</span>
                            <span class="star" data-v="3" onclick="setRating(3)">⭐</span>
                            <span class="star" data-v="4" onclick="setRating(4)">⭐</span>
                            <span class="star" data-v="5" onclick="setRating(5)">⭐</span>
                        </div>
                        <div id="ratingHint" class="mb-4" style="font-size:12.5px;color:#94a3b8;min-height:18px">Click a star to rate</div>

                        <!-- Tags -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" style="font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">What went well?</label>
                            <div class="d-flex flex-wrap gap-2" id="fbTagsWrap">
                                <button class="btn ftag" onclick="toggleTag(this)" data-tag="Easy to use">Easy to use</button>
                                <button class="btn ftag" onclick="toggleTag(this)" data-tag="Fast &amp; responsive">Fast &amp; responsive</button>
                                <button class="btn ftag" onclick="toggleTag(this)" data-tag="Clear instructions">Clear instructions</button>
                                <button class="btn ftag" onclick="toggleTag(this)" data-tag="Secure feeling">Secure feeling</button>
                                <button class="btn ftag" onclick="toggleTag(this)" data-tag="Good design">Good design</button>
                            </div>
                        </div>

                        <!-- Comment -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" style="font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Additional Comments</label>
                            <textarea id="feedbackText" class="form-control" rows="4"
                                placeholder="Share your thoughts on the voting experience..." style="resize:none;border-radius:10px;font-size:13.5px"></textarea>
                        </div>

                        <div id="fbErrorMsg" class="text-danger mb-2" style="font-size:13px;display:none"></div>

                        <button class="btn btn-success w-100 py-2" id="fbSubmitBtn" onclick="submitFeedback()" style="border-radius:10px;font-weight:700;font-size:14px">
                            <i class="bi bi-send-fill me-2"></i>Submit Feedback
                        </button>
                        <div id="fbSuccessBanner" class="mt-3 p-3 rounded-3 text-center" style="display:none;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac">
                            <div style="font-size:28px">🎉</div>
                            <div class="fw-bold text-success">Thank you for your feedback!</div>
                            <div style="font-size:12.5px;color:#166534">Your review has been saved and sent to the admin.</div>
                        </div>
                    </div>
                </div>

                <!-- ── Reviews List ── -->
                <div class="col-lg-7">
                    <div class="fb-reviews-card">
                        <!-- Average rating summary -->
                        <div id="fbAvgWrap" class="fb-avg-wrap" style="display:none">
                            <div class="fb-avg-num" id="fbAvgNum">—</div>
                            <div class="flex-grow-1">
                                <div class="fw-bold" style="font-size:13.5px;color:#166534">Overall Rating</div>
                                <div id="fbAvgStars" style="font-size:18px;letter-spacing:2px"></div>
                                <div id="fbAvgTotal" style="font-size:12px;color:#64748b"></div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <div class="fw-bold" style="font-size:15px">Voter Reviews</div>
                                <div id="reviewsSummary" class="text-muted" style="font-size:12.5px">Loading…</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn ftag active" id="rvFilterAll" onclick="filterReviews('all',this)">All</button>
                                <button class="btn ftag" id="rvFilterGeneral" onclick="filterReviews('general',this)">General</button>
                                <button class="btn ftag" id="rvFilterClas" onclick="filterReviews('clas',this)">CLAS</button>
                            </div>
                        </div>

                        <div id="reviewsList" style="max-height:480px;overflow-y:auto;padding-right:4px">
                            <div class="text-center text-muted py-4" id="reviewsLoading">
                                <div class="spinner-border spinner-border-sm text-success me-2"></div>Loading reviews…
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        </div>

        <!-- ── PROFILE ── -->
        <div id="profile" class="section" style="display:none">
            <h1 class="sf-page-title">Edit Details</h1>
            <p class="sf-page-sub">Keep your voter information accurate and up to date.</p>
            <div class="card border" style="max-width:700px">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 pb-3 mb-4 border-bottom">
                        <!-- Clickable avatar / photo uploader -->
                        <div style="position:relative;flex-shrink:0;cursor:pointer" onclick="document.getElementById('profilePhotoInput').click()" title="Click to change photo">
                            <?php if (!empty($user['profile_photo']) && file_exists($user['profile_photo'])): ?>
                                <img id="profileAvatarImg" src="<?= h($user['profile_photo']) ?>" alt="Profile Photo"
                                    style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #bbf7d0">
                            <?php else: ?>
                                <div class="profile-av" id="profileAvatar"><?= h($initials) ?></div>
                                <img id="profileAvatarImg" src="" alt="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #bbf7d0;display:none">
                            <?php endif; ?>
                            <div style="position:absolute;bottom:2px;right:2px;width:24px;height:24px;background:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff">
                                <i class="bi bi-camera-fill" style="color:#fff;font-size:11px"></i>
                            </div>
                        </div>
                        <div>
                            <div class="fw-bold fs-5" id="profileDisplayName"><?= $full_name ?></div>
                            <div class="text-muted" style="font-size:13.5px">
                                Student ID: <span id="profileDisplayID"><?= h($user['student_id']) ?></span> · Verified Voter
                            </div>
                            <div class="text-muted mt-1" style="font-size:12px">
                                <i class="bi bi-camera me-1"></i>Click photo to upload a new picture (JPG/PNG/WebP, max 3 MB)
                            </div>
                            <?php if (!empty($user['program']) || !empty($user['section'])): ?>
                                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                                    <?php if (!empty($user['program'])): ?>
                                        <span class="badge rounded-pill" style="background:#d1fae5;color:#065f46;font-size:12px;font-weight:600">
                                            <i class="bi bi-mortarboard-fill me-1"></i><?= h($user['program']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($user['section'])): ?>
                                        <span class="badge rounded-pill" style="background:#e0e7ff;color:#3730a3;font-size:12px;font-weight:600">
                                            <i class="bi bi-grid-fill me-1"></i>Section <?= h($user['section']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" action="user.php" enctype="multipart/form-data" onsubmit="saveProfile(event)">
                        <input type="hidden" name="action" value="save_profile">
                        <!-- Hidden file input — triggered by clicking avatar -->
                        <input type="file" id="profilePhotoInput" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="previewProfilePhoto(this)">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">First Name</label>
                                <input id="inputFirstName" name="first_name" type="text" class="form-control" value="<?= h($user['first_name']) ?>" placeholder="First Name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Last Name</label>
                                <input id="inputLastName" name="last_name" type="text" class="form-control" value="<?= h($user['last_name']) ?>" placeholder="Last Name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input id="inputPhone" name="phone" type="text" class="form-control" value="<?= h($user['phone']) ?>" placeholder="09xxxxxxxxx">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Student ID</label>
                                <input id="inputStudentID" name="student_id" type="text" class="form-control" value="<?= h($user['student_id']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Date of Birth</label>
                                <input id="inputDOB" name="dob" type="date" class="form-control" value="<?= h($user['dob']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email Address</label>
                                <input id="inputEmail" name="email" type="email" class="form-control" value="<?= h($user['email']) ?>" placeholder="email@school.edu.ph">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Program / Course</label>
                                <?php
                                $programs = [
                                    'ABBS'       => 'ABBS — Bachelor of Arts in Behavioural Sciences',
                                    'BA COMM'    => 'BA COMM — Bachelor of Arts in Communication',
                                    'BA POS'     => 'BA POS — Bachelor of Arts in Political Science',
                                    'BECED'      => 'BECED — Bachelor of Early Childhood Education',
                                    'BPA'        => 'BPA — Bachelor of Public Administration',
                                    'BPA ECGE'   => 'BPA ECGE — BPA Evening Class for Govt Employees',
                                    'BS CPE'     => 'BS CPE — BS Computer Engineering',
                                    'BS CRIM'    => 'BS CRIM — Bachelor of Science in Criminology',
                                    'BS ECE'     => 'BS ECE — BS Electronics Engineering',
                                    'BS EE'      => 'BS EE — BS Electrical Engineering',
                                    'BS ENTREP'  => 'BS ENTREP — BS in Entrepreneurship',
                                    'BS IE'      => 'BS IE — BS Industrial Engineering',
                                    'BS MATH'    => 'BS MATH — BS in Mathematics',
                                    'BS PSY'     => 'BS PSY — BS in Psychology',
                                    'BSA'        => 'BSA — BS in Accountancy',
                                    'BSAIS'      => 'BSAIS — BS in Accounting Information Systems',
                                    'BSBA FMGT'  => 'BSBA FMGT — BSBA Major in Financial Management',
                                    'BSBA HRM'   => 'BSBA HRM — BSBA Major in Human Resource Management',
                                    'BSBA MKTG'  => 'BSBA MKTG — BSBA Major in Marketing Management',
                                    'BSCS'       => 'BSCS — BS in Computer Science',
                                    'BSE ENG'    => 'BSE ENG — BSEd Major in English',
                                    'BSE ENG-CHI' => 'BSE ENG-CHI — BSEd Major in English with Chinese',
                                    'BSE SCI'    => 'BSE SCI — BSEd Major in Science',
                                    'BSEMC'      => 'BSEMC — BS in Entertainment and Multimedia Computing',
                                    'BSHM'       => 'BSHM — BS in Hospitality Management',
                                    'BSIS'       => 'BSIS — BS in Information Systems',
                                    'BSISM'      => 'BSISM — BS in Industrial Security Management',
                                    'BSIT'       => 'BSIT — BS in Information Technology',
                                    'BSOAD'      => 'BSOAD — BS in Office Administration',
                                    'BSSW'       => 'BSSW — BS in Social Work',
                                    'BSTM'       => 'BSTM — BS in Tourism Management',
                                    'BTLED HE'   => 'BTLED HE — BTLEd Major in Home Economics',
                                    'CPE'        => 'CPE — Certificate in Professional Education',
                                    'DPA'        => 'DPA — Doctor in Public Administration',
                                    'MAED'       => 'MAED — MA in Education (Educational Management)',
                                    'MAT-EG'     => 'MAT-EG — MA in Teaching in the Early Grades',
                                    'MATS'       => 'MATS — MA in Teaching Science',
                                    'MBA'        => 'MBA — Master in Business Administration',
                                    'MPA'        => 'MPA — Master in Public Administration',
                                    'MSC'        => 'MSC — MS in Criminal Justice (Criminology)',
                                    'PHD'        => 'PHD — Doctor of Philosophy (Educational Management)',
                                ];
                                $currentProgram = $user['program'] ?? '';
                                ?>
                                <select id="inputProgram" name="program" class="form-select">
                                    <option value="">— Select program —</option>
                                    <?php foreach ($programs as $val => $label): ?>
                                        <option value="<?= h($val) ?>" <?= $currentProgram === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Section</label>
                                <input id="inputSection" name="section" type="text" class="form-control"
                                    value="<?= h($user['section'] ?? '') ?>"
                                    placeholder="e.g. 1A, 2B"
                                    maxlength="10">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">New Password</label>
                                <input id="inputPassword" name="password" type="password" class="form-control" placeholder="Leave blank to keep current">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Confirm Password</label>
                                <input id="inputConfirmPassword" name="confirm_password" type="password" class="form-control" placeholder="Re-enter new password">
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-success flex-fill">
                                    <i class="bi bi-floppy me-2"></i>Save Changes
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="cancelProfile()">Cancel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>

    <!-- ══ MODALS (JS-controlled via .modal-overlay.active) ══ -->
    <div class="modal-overlay" id="logoutModal">
        <div class="sf-modal">
            <div class="sf-modal-icon">👋</div>
            <div class="sf-modal-title">Logging out?</div>
            <div class="sf-modal-sub">You'll need to verify your credentials to access SuffraTech again.</div>
            <div class="sf-modal-actions">
                <button class="btn btn-outline-secondary btn-full" onclick="closeModal('logoutModal')">Stay Logged In</button>
                <button class="btn btn-danger btn-full" onclick="doLogout()">Yes, Logout</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="voteConfirmModal">
        <div class="sf-modal">
            <div class="sf-modal-body">
                <div class="sf-modal-icon" id="voteConfirmIcon">🗳️</div>
                <div class="sf-modal-title" id="voteConfirmTitle">Confirm Your Ballot</div>
                <div class="sf-modal-sub" id="voteConfirmText">Please review your selections. Once submitted, your vote cannot be changed.</div>
            </div>
            <div class="sf-modal-actions" style="flex-shrink:0;padding-top:16px;border-top:1px solid var(--border,#e2e8f0);margin-top:8px">
                <button class="btn btn-outline-secondary btn-full" onclick="closeModal('voteConfirmModal')">Review Again</button>
                <button class="btn btn-success btn-full" id="confirmVoteBtn" onclick="finalizeVote()">Confirm &amp; Submit</button>
            </div>
        </div>
    </div>

    <!-- Login notification modal -->
    <?php if ($showLoginNotif): ?>
        <div class="modal-overlay active" id="electionNotifModal">
            <div class="sf-modal" style="max-width:420px">
                <div class="sf-modal-icon" style="font-size:52px"><?= $loginNotifIcon ?></div>
                <div class="sf-modal-title">Election Update</div>

                <?php if ($loginNotifType === 'ongoing'): ?>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 mb-3 rounded-pill" style="background:#d1fae5;border:1.5px solid #6ee7b7">
                        <span style="width:8px;height:8px;border-radius:50%;background:#10b981;display:inline-block;animation:pulseNotif 1.5s infinite"></span>
                        <span class="fw-bold text-success" style="font-size:12px">VOTING OPEN</span>
                    </div>
                <?php elseif ($loginNotifType === 'ended'): ?>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 mb-3 rounded-pill" style="background:#fee2e2;border:1.5px solid #fca5a5">
                        <span class="fw-bold text-danger" style="font-size:12px">ELECTION ENDED</span>
                    </div>
                <?php elseif ($loginNotifType === 'voted'): ?>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 mb-3 rounded-pill" style="background:#d1fae5;border:1.5px solid #6ee7b7">
                        <span class="fw-bold text-success" style="font-size:12px">VOTED ✓</span>
                    </div>
                <?php else: ?>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 mb-3 rounded-pill" style="background:#fef9c3;border:1.5px solid #fde047">
                        <span class="fw-bold" style="font-size:12px;color:#92400e">NOT STARTED</span>
                    </div>
                <?php endif; ?>

                <div class="sf-modal-sub"><?= $loginNotifMsg ?></div>
                <div class="d-flex flex-column gap-2">
                    <?php if ($loginNotifType === 'ongoing'): ?>
                        <button class="btn btn-success w-100" onclick="closeNotifModal('vote')">🗳️ <?= h($loginNotifBtn) ?></button>
                        <button class="btn btn-outline-secondary w-100" onclick="closeNotifModal('home')">View Dashboard</button>
                    <?php elseif (in_array($loginNotifType, ['voted', 'ended'])): ?>
                        <button class="btn btn-success w-100" onclick="closeNotifModal('results')">📊 <?= h($loginNotifBtn) ?></button>
                        <button class="btn btn-outline-secondary w-100" onclick="closeNotifModal('home')">View Dashboard</button>
                    <?php else: ?>
                        <button class="btn btn-success w-100" onclick="closeNotifModal('home')"><?= h($loginNotifBtn) ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="modal-overlay" id="electionNotifModal" style="display:none"></div>
    <?php endif; ?>

    <!-- Toast -->
    <div class="sf-toast" id="toast">
        <span id="toastIcon">✅</span>
        <span id="toastMsg">Action completed.</span>
    </div>

    <!-- Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js"></script>

    <!-- PHP → JS bridge variables (needed by user.js at runtime) -->
    <script>
        window.SUFFRA_GENERAL_NAMES = <?= json_encode($generalPositionNames) ?>;
        window.SUFFRA_CLAS_NAMES = <?= json_encode($clasPositionNames) ?>;
        window.SUFFRA_HAS_VOTED = <?= json_encode((bool)$user['has_voted']) ?>;
        window.SUFFRA_HAS_VOTED_CLAS = <?= json_encode((bool)$user['has_voted_clas']) ?>;
        window.SUFFRA_GEN_STATUS = <?= json_encode($genStatus) ?>;
        window.SUFFRA_CLAS_STATUS = <?= json_encode($clasStatus) ?>;
        window.SUFFRA_REVIEWED_GENERAL = <?= json_encode((bool)($reviewedGeneral ?? false)) ?>;
        window.SUFFRA_REVIEWED_CLAS = <?= json_encode((bool)($reviewedClas    ?? false)) ?>;

        // Ensure the election notification modal always closes before navigating,
        // even if user.js defines its own version (this runs first as a safe fallback).
        function closeNotifModal(dest) {
            var overlay = document.getElementById('electionNotifModal');
            if (overlay) {
                overlay.classList.remove('active');
                overlay.style.display = 'none';
            }
            if (dest === 'vote') {
                // Let user.js navTo handle navigation if available, otherwise scroll to vote
                if (typeof navTo === 'function') { navTo('vote'); }
                else { var el = document.getElementById('vote'); if (el) el.scrollIntoView(); }
            } else if (dest === 'results') {
                if (typeof navTo === 'function') { navTo('results'); }
                else { var el2 = document.getElementById('results'); if (el2) el2.scrollIntoView(); }
            }
            // 'home' — just close, no navigation needed
        }
    </script>

    <!-- user.js — contains ALL application logic -->
    <script src="user.js"></script>

    <!-- Sidebar toggle (mobile) -->
    <script>
        function toggleSidebar() {
            const sb = document.querySelector('.sf-sidebar');
            const ov = document.getElementById('sbOverlay');
            sb.classList.toggle('sb-open');
            ov.classList.toggle('active');
        }

        function closeSidebar() {
            document.querySelector('.sf-sidebar').classList.remove('sb-open');
            document.getElementById('sbOverlay').classList.remove('active');
        }

        // ── Render avg rating in feedback tab ──
        window._origLoadReviews = null;
        document.addEventListener('DOMContentLoaded', function() {
            // Patch renderReviews to show avg
            const origBuildReviewHTML = window.buildReviewHTML;
            // Hook into the reviews loaded callback if exposed
            const observer = new MutationObserver(function() {
                const summary = document.getElementById('reviewsSummary');
                if (summary && summary.textContent && !summary.textContent.includes('Loading')) {
                    observer.disconnect();
                }
            });
            const el = document.getElementById('reviewsSummary');
            if (el) observer.observe(el, {
                childList: true,
                subtree: true,
                characterData: true
            });
        });

        // Patch: when reviews load, show avg rating bar
        // AFTER (fixed — IIFE prevents redeclaration, guard prevents double-patching)
(function () {
    if (window.__suffraTechFetchPatched) return;
    window.__suffraTechFetchPatched = true;
    var _nativeFetch = window.fetch;
    window.fetch = function (url, opts) {
        var p = _nativeFetch.apply(this, arguments);
        if (typeof url === 'string' && url.includes('api=reviews')) {
            p.then(function (r) { return r.clone().json(); })
             .then(function (data) {
                if (data && data.ok && data.avg_rating != null) {
                    var wrap  = document.getElementById('fbAvgWrap');
                    var num   = document.getElementById('fbAvgNum');
                    var stars = document.getElementById('fbAvgStars');
                    var tot   = document.getElementById('fbAvgTotal');
                    if (wrap) {
                        num.textContent    = data.avg_rating;
                        var full           = Math.round(data.avg_rating);
                        stars.textContent  = '⭐'.repeat(full) + '☆'.repeat(5 - full);
                        tot.textContent    = data.total + ' review' + (data.total !== 1 ? 's' : '');
                        wrap.style.display = 'flex';
                    }
                }
            }).catch(function () {});
        }
        return p;
    };
})();
    </script>

    <!-- PHP → JS: profile save result toast (runs after user.js is loaded) -->
    <?php if ($profileMsgType): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                <?php if ($profileMsgType === 'success'): ?>
                    navTo('profile');
                <?php endif; ?>
                showToast(
                    '<?= $profileMsgType === 'success' ? '✅' : '⚠️' ?>',
                    '<?= addslashes($profileMsgText) ?>'
                );
            });
        </script>
    <?php endif; ?>

    <!-- ══ Results 1-hour expiry countdown & auto-hide ══ -->
    <script>
        (function() {
            // Seconds remaining injected from PHP (0 = already expired or still ongoing)
            var GEN_SECS_LEFT = <?= (int)$genExpirySecondsLeft ?>;
            var CLAS_SECS_LEFT = <?= (int)$clasExpirySecondsLeft ?>;

            function fmtTime(s) {
                var m = Math.floor(s / 60);
                var sec = s % 60;
                return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
            }

            function hideResultsPanel(type) {
                var container = document.getElementById(type === 'gen' ? 'results-panel-general' : 'results-panel-clas');
                if (!container) return;
                container.innerHTML =
                    '<div class="text-center py-5">' +
                    '<div class="mb-3" style="font-size:72px">📋</div>' +
                    '<h2 class="fw-bold" style="font-family:\'Playfair Display\',serif">Results Submitted to Admin</h2>' +
                    '<p class="text-muted mx-auto" style="max-width:420px;line-height:1.7">' +
                    'The ' + (type === 'gen' ? 'General' : 'CLAS Council') + ' Election results are no longer publicly available.<br>' +
                    'They have been forwarded to the administration for official tallying.' +
                    '</p>' +
                    '<div class="mt-3 p-3 rounded-3 d-inline-flex align-items-center gap-2" ' +
                    'style="background:' + (type === 'gen' ? '#f0fdf4;border:1.5px solid #86efac;color:#166534' : '#ede9fe;border:1.5px solid #a78bfa;color:#3730a3') + ';font-size:13.5px">' +
                    '<i class="bi bi-shield-check-fill"></i> Results secured &amp; archived by SuffraTech' +
                    '</div>' +
                    '</div>';
            }

            function startCountdown(secsLeft, spanId, type) {
                if (secsLeft <= 0) return;
                var remaining = secsLeft;
                var el = document.getElementById(spanId);
                if (el) el.textContent = fmtTime(remaining);

                var timer = setInterval(function() {
                    remaining--;
                    var el2 = document.getElementById(spanId);
                    if (el2) el2.textContent = fmtTime(remaining);
                    if (remaining <= 0) {
                        clearInterval(timer);
                        hideResultsPanel(type);
                    }
                }, 1000);
            }

            document.addEventListener('DOMContentLoaded', function() {
                if (GEN_SECS_LEFT > 0) startCountdown(GEN_SECS_LEFT, 'genResultsCountdown', 'gen');
                if (CLAS_SECS_LEFT > 0) startCountdown(CLAS_SECS_LEFT, 'clasResultsCountdown', 'clas');
            });
        })();
    </script>
    <!-- ══ Custom Live Results Renderer ══ -->
    <script>
    (function () {
        var REFRESH_MS = 5000;
        var _timers = {};
        var _activePos = { general: null, clas: null };

        function h(s) {
            return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function initials(name) {
            return (name||'').split(' ').filter(Boolean).slice(0,2).map(function(w){return w[0].toUpperCase();}).join('');
        }
        function photoSrc(photo) {
            if (!photo) return '';
            return photo.indexOf('uploads/') === 0 ? photo : 'uploads/candidates/' + photo;
        }

        function renderResults(data, type) {
            var isClas   = type === 'clas';
            var accent   = isClas ? '#6366f1' : '#16a34a';
            var accentBg = isClas ? '#eef2ff' : '#f0fdf4';
            var accentBd = isClas ? '#a5b4fc' : '#86efac';
            var containerId = isClas ? 'clasResultsContainer' : 'generalResultsContainer';
            var summaryId   = isClas ? 'clasLeadersSummary'   : 'genLeadersSummary';
            var pillsId     = isClas ? 'clasPositionPills'     : 'genPositionPills';
            var container   = document.getElementById(containerId);
            if (!container) return;

            var positions = data.positions || [];
            if (!positions.length) {
                container.innerHTML = '<div class="res-loading">No position data available yet.</div>';
                return;
            }

            // ── "Who's Winning" summary ──────────────────────────
            var summaryEl = document.getElementById(summaryId);
            if (summaryEl) {
                var chips = positions.map(function(pos) {
                    var leader = (pos.candidates||[])[0];
                    if (!leader) return '';
                    return '<div class="leader-chip">' +
                        '<div class="leader-chip-pos">' + h(pos.name) + '</div>' +
                        '<div class="leader-chip-name">' + h(leader.name) + '</div>' +
                        '<div class="leader-chip-votes" style="color:'+accent+'">▲ ' + leader.votes + ' vote' + (leader.votes!==1?'s':'') + '</div>' +
                    '</div>';
                }).join('');
                summaryEl.innerHTML = '<div class="leaders-summary-title" style="color:'+accent+'">🏆 Who\'s currently winning</div><div class="leaders-summary-grid">' + chips + '</div>';
                summaryEl.style.display = 'none';
            }

            // ── Position pills (General) / Dropdown (CLAS) — only build once ──
            var pillsEl = document.getElementById(pillsId);
            if (pillsEl && !pillsEl.dataset.built) {

                if (isClas) {
                    // ── CLAS: compact dropdown (avoids 3-row pill wrap) ──
                    var options = positions.map(function(pos, i) {
                        var sel = ((_activePos[type] === null && i === 0) || _activePos[type] === pos.id) ? ' selected' : '';
                        return '<option value="' + pos.id + '"' + sel + '>' + h(pos.name) + '</option>';
                    }).join('');
                    pillsEl.innerHTML =
                        '<span class="pills-label">Jump to:</span>' +
                        '<select class="pos-dropdown clas-dropdown">' + options + '</select>';
                    pillsEl.style.display = 'flex';
                    pillsEl.style.alignItems = 'center';
                    pillsEl.dataset.built = '1';

                    pillsEl.querySelector('.pos-dropdown').addEventListener('change', function() {
                        var posid = parseInt(this.value);
                        _activePos[type] = posid;
                        var block = container.querySelector('[data-posid="' + posid + '"]');
                        if (block) block.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });

                } else {
                    // ── General: scrollable pill buttons ──
                    var pills = '<span class="pills-label">Jump to:</span>' +
                    positions.map(function(pos, i) {
                        var isActive = (_activePos[type] === null && i === 0) || _activePos[type] === pos.id;
                        return '<button class="pos-pill' + (isActive ? ' active-pos-pill' : '') + '" ' +
                            'data-posid="' + pos.id + '" data-type="' + type + '">' + h(pos.name) + '</button>';
                    }).join('');
                    pillsEl.innerHTML = pills;
                    pillsEl.style.display = 'flex';
                    pillsEl.dataset.built = '1';
                    pillsEl.querySelectorAll('.pos-pill').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            _activePos[type] = parseInt(btn.dataset.posid);
                            pillsEl.querySelectorAll('.pos-pill').forEach(function(b){ b.classList.remove('active-pos-pill'); });
                            btn.classList.add('active-pos-pill');
                            var pillsRect = pillsEl.getBoundingClientRect();
                            var btnRect   = btn.getBoundingClientRect();
                            if (btnRect.left < pillsRect.left + 40 || btnRect.right > pillsRect.right - 40) {
                                pillsEl.scrollBy({ left: btnRect.left - pillsRect.left - 80, behavior: 'smooth' });
                            }
                            var block = container.querySelector('[data-posid="' + btn.dataset.posid + '"]');
                            if (block) block.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        });
                    });
                }

                if (_activePos[type] === null && positions.length) _activePos[type] = positions[0].id;
            }

            // ── Position blocks — smart diff (no full re-render = no jumpy bars) ──
            var isFirstRender = !container.querySelector('.lb-position-card');

            positions.forEach(function(pos, pi) {
                var cands    = pos.candidates || [];
                var maxVotes = cands.length ? Math.max.apply(null, cands.map(function(c){return c.votes;})) : 0;
                var posTotal = pos.total || 0;
                var posIcons = ['👑','⭐','🏅','🎖️','🔖'];
                var icon     = posIcons[pi] || '🔖';

                var existingCard = container.querySelector('#pos-block-' + pos.id);

                if (!existingCard) {
                    // ── First time: build full card HTML ──────────────
                    var candsHtml = '';
                    cands.forEach(function(cand, ci) {
                        var isLeader = ci === 0 && maxVotes > 0;
                        var barWidth = cand.pct || 0;
                        var rankLabel = ci === 0 ? '🥇' : ci === 1 ? '🥈' : ci === 2 ? '🥉' : '#'+(ci+1);
                        var photo = photoSrc(cand.photo);
                        var av    = initials(cand.name);
                        candsHtml += '<div class="lb-cand-row' + (isLeader ? ' lb-leader' : '') + '" ' +
                            'data-candid="' + cand.id + '" style="' +
                            (isLeader ? 'background:'+accentBg+';border-color:'+accentBd+';' : '') + '">' +
                            '<div class="lb-rank">' + rankLabel + '</div>' +
                            '<div class="lb-avatar" style="' + (isClas ? 'background:linear-gradient(135deg,#c7d2fe,#818cf8);color:#3730a3' : '') + '">' +
                                (photo ? '<img src="'+h(photo)+'" alt="'+h(cand.name)+'" onerror="this.style.display=\'none\';this.nextSibling.style.display=\'flex\'">' : '') +
                                '<span style="' + (photo ? 'display:none' : '') + '">' + h(av) + '</span>' +
                            '</div>' +
                            '<div class="lb-info">' +
                                '<div class="lb-name">' + h(cand.name) + (isLeader ? '<span class="lb-leading-tag" style="background:'+accent+'">▲ Leading</span>' : '') + '</div>' +
                                (cand.motto ? '<div class="lb-motto">' + h(cand.motto) + '</div>' : '') +
                                '<div class="lb-bar-wrap"><div class="lb-bar" data-target="' + barWidth + '" style="width:0%;background:' +
                                    (isLeader ? accent : (isClas ? '#c7d2fe' : '#bbf7d0')) + ';opacity:' + (isLeader ? '1' : '.7') + '"></div></div>' +
                            '</div>' +
                            '<div class="lb-stats">' +
                                '<div class="lb-votes">' + cand.votes + '</div>' +
                                '<div class="lb-pct">' + barWidth + '%</div>' +
                            '</div>' +
                        '</div>';
                    });
                    if (!cands.length) candsHtml = '<div class="lb-empty">No candidates for this position yet.</div>';

                    var cardEl = document.createElement('div');
                    cardEl.className = 'lb-position-card lb-collapsed';
                    cardEl.id = 'pos-block-' + pos.id;
                    cardEl.setAttribute('data-posid', pos.id);
                    cardEl.innerHTML =
                        '<div class="lb-pos-header" style="border-left:4px solid '+accent+'">' +
                            '<div class="lb-pos-icon" style="background:'+accentBg+';color:'+accent+'">' + icon + '</div>' +
                            '<div class="lb-pos-meta">' +
                                '<div class="lb-pos-name">' + h(pos.name) + '</div>' +
                                '<div class="lb-pos-sub" id="pos-sub-'+pos.id+'">' + posTotal + ' total vote' + (posTotal!==1?'s':'') + ' · ' + cands.length + ' candidate' + (cands.length!==1?'s':'') + '</div>' +
                            '</div>' +
                            '<button class="lb-collapse-btn" onclick="(function(b){var card=b.closest(\'.lb-position-card\');card.classList.toggle(\'lb-collapsed\');b.textContent=card.classList.contains(\'lb-collapsed\')?\'▶ Show\':\'▼ Hide\';})(this)">▶ Show</button>' +
                        '</div>' +
                        '<div class="lb-pos-body">' + candsHtml + '</div>';
                    container.appendChild(cardEl);

                    // animate bars in after paint
                    requestAnimationFrame(function() {
                        requestAnimationFrame(function() {
                            cardEl.querySelectorAll('.lb-bar').forEach(function(bar) {
                                bar.style.width = bar.dataset.target + '%';
                            });
                        });
                    });

                } else {
                    // ── Subsequent renders: only patch changed values ──
                    var subEl = existingCard.querySelector('#pos-sub-' + pos.id);
                    if (subEl) subEl.textContent = posTotal + ' total vote' + (posTotal!==1?'s':'') + ' · ' + cands.length + ' candidate' + (cands.length!==1?'s':'');

                    cands.forEach(function(cand) {
                        var row = existingCard.querySelector('[data-candid="' + cand.id + '"]');
                        if (!row) return;
                        var bar  = row.querySelector('.lb-bar');
                        var votesEl = row.querySelector('.lb-votes');
                        var pctEl   = row.querySelector('.lb-pct');
                        var newW = (cand.pct || 0) + '%';
                        if (bar  && bar.style.width !== newW)   bar.style.width = newW;
                        if (votesEl && votesEl.textContent !== String(cand.votes)) votesEl.textContent = cand.votes;
                        if (pctEl   && pctEl.textContent   !== (cand.pct||0)+'%') pctEl.textContent   = (cand.pct||0) + '%';
                    });
                }
            });

            // ── Attach observer AFTER DOM is built — only once ──────
            if (pillsEl && !window._posObserver?.[type]) {
                if (!window._posObserver) window._posObserver = {};
                var obs = new IntersectionObserver(function(entries) {
                    var best = null;
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            if (!best || entry.intersectionRatio > best.intersectionRatio) best = entry;
                        }
                    });
                    if (!best) return;
                    var posid = best.target.dataset.posid;
                    if (!posid || _activePos[type] === parseInt(posid)) return;
                    _activePos[type] = parseInt(posid);
                    pillsEl.querySelectorAll('.pos-pill').forEach(function(b){ b.classList.remove('active-pos-pill'); });
                    var pill = pillsEl.querySelector('.pos-pill[data-posid="' + posid + '"]');
                    if (pill) {
                        pill.classList.add('active-pos-pill');
                        // Only scroll the pill bar itself (not the page)
                        var pillsRect = pillsEl.getBoundingClientRect();
                        var pillRect  = pill.getBoundingClientRect();
                        if (pillRect.left < pillsRect.left + 40 || pillRect.right > pillsRect.right - 40) {
                            pillsEl.scrollBy({ left: pillRect.left - pillsRect.left - 80, behavior: 'smooth' });
                        }
                    }
                }, { threshold: [0, 0.1, 0.25, 0.5], rootMargin: '-55px 0px -40% 0px' });
                container.querySelectorAll('.lb-position-card[data-posid]').forEach(function(card) {
                    obs.observe(card);
                });
                window._posObserver[type] = obs;
            } else if (pillsEl && window._posObserver?.[type]) {
                // Re-observe any newly added cards
                container.querySelectorAll('.lb-position-card[data-posid]').forEach(function(card) {
                    window._posObserver[type].observe(card);
                });
            }
        }

        function fetchAndRender(type) {
            var containerId = type === 'clas' ? 'clasResultsContainer' : 'generalResultsContainer';
            var container   = document.getElementById(containerId);
            if (!container) return;
            // Only fetch if panel is visible
            var panel = document.getElementById('results-panel-' + type);
            if (!panel || panel.style.display === 'none') return;

            fetch('user_view.php?api=live_results&type=' + type, {credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    if (data.ok) renderResults(data, type);
                })
                .catch(function(){});
        }

        function startPolling(type) {
            if (_timers[type]) clearInterval(_timers[type]);
            fetchAndRender(type);
            _timers[type] = setInterval(function(){ fetchAndRender(type); }, REFRESH_MS);
        }

        // Hook into election tab switching
        var _origSwitch = window.switchResultsElectionTab;
        window.switchResultsElectionTab = function(type) {
            // Call original if it exists
            if (typeof _origSwitch === 'function') _origSwitch(type);
            // Update our election switcher buttons
            document.querySelectorAll('.res-election-btn').forEach(function(b){ b.classList.remove('active'); });
            var activeBtn = document.getElementById('results-tab-' + type);
            if (activeBtn) activeBtn.classList.add('active');
            // Show/hide panels
            var genPanel  = document.getElementById('results-panel-general');
            var clasPanel = document.getElementById('results-panel-clas');
            if (genPanel)  genPanel.style.display  = (type === 'general') ? '' : 'none';
            if (clasPanel) clasPanel.style.display = (type === 'clas')    ? '' : 'none';
            startPolling(type);
        };

        // Start on DOMContentLoaded when results page is shown
        document.addEventListener('DOMContentLoaded', function() {
            // Watch for results section becoming visible
            var resultsSection = document.getElementById('results');
            if (!resultsSection) return;
            var resultObs = new MutationObserver(function() {
                if (resultsSection.style.display !== 'none') {
                    startPolling('general');
                }
            });
            resultObs.observe(resultsSection, { attributes: true, attributeFilter: ['style'] });

            // If already visible on load
            if (resultsSection.style.display !== 'none') {
                startPolling('general');
            }
        });

        // Also hook navTo
        var _origNavTo = window.navTo;
        window.navTo = function(page) {
            if (typeof _origNavTo === 'function') _origNavTo(page);
            if (page === 'results') {
                setTimeout(function(){ startPolling('general'); }, 150);
            }
        };
    })();
    </script>
</body>

</html>