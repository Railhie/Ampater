<?php
// ════════════════════════════════════════════════════════════
//  SuffraTech — Admin Panel  (+ Election History + CLAS Vote)
// ════════════════════════════════════════════════════════════
session_start();

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
function logAction(string $cat, string $action, string $details): void
{
  try {
    db()->prepare("INSERT INTO audit_log(category,action,details,admin_id,created_at) VALUES(:c,:a,:d,:aid,NOW())")
      ->execute([':c' => $cat, ':a' => $action, ':d' => $details, ':aid' => $_SESSION['admin_id'] ?? 0]);
  } catch (Exception $e) {
  }
}
function calcAge(string $bd): int
{
  return (int)(new DateTime($bd))->diff(new DateTime())->y;
}

/**
 * Returns the category of the most recently logged audit event.
 * Shown in the Audit Log stat card as "Latest Category".
 * e.g. "Student", "Election", "Voters" — or "—" if the log is empty.
 */
function getLatestAuditCategory(): string
{
  try {
    $cat = db()
      ->query("SELECT category FROM audit_log ORDER BY created_at DESC LIMIT 1")
      ->fetchColumn();
    return $cat ?: '—';
  } catch (Exception $e) {
    return '—';
  }
}

if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: login.php');
  exit;
}

try {
  $pdo = db();

  // ── Core tables ────────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(60) NOT NULL UNIQUE, full_name VARCHAR(120) NOT NULL, email VARCHAR(120) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
  if ($adminCount === 0) {
    $pdo->prepare("INSERT INTO admins (username,full_name,email,password) VALUES (?,?,?,?)")
      ->execute(['admin', 'System Administrator', 'admin@suffratech.edu.ph', password_hash('admin1234', PASSWORD_BCRYPT)]);
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS students (id INT AUTO_INCREMENT PRIMARY KEY, student_id VARCHAR(30) NOT NULL UNIQUE, first_name VARCHAR(60) NOT NULL, last_name VARCHAR(60) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL UNIQUE, phone VARCHAR(15) DEFAULT NULL, birthdate DATE DEFAULT NULL, password_hash VARCHAR(255) NOT NULL, approval_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending', has_voted TINYINT(1) NOT NULL DEFAULT 0, has_voted_clas TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS positions (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, election_type VARCHAR(20) NOT NULL DEFAULT 'general') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $posCount = (int)$pdo->query("SELECT COUNT(*) FROM positions")->fetchColumn();
  if ($posCount === 0) {
    $pdo->exec("INSERT INTO positions (name,sort_order,election_type) VALUES
      ('President',1,'general'),('Vice President',2,'general'),('Secretary',3,'general'),
      ('Treasurer',4,'general'),('Auditor',5,'general'),('P.I.O',6,'general'),
      ('Prime Minister',1,'clas'),('Deputy Prime Minister',2,'clas'),
      ('General Secretary',3,'clas'),('Chief Financial Officer',4,'clas'),
      ('General Auditor',5,'clas'),('Ministerial Press Relations Officer',6,'clas'),
      ('Course Representative',7,'clas'),
      ('BPA Representative',8,'clas'),('BSIS Representative',9,'clas'),
      ('BSIT Representative',10,'clas'),('BSCS Representative',11,'clas'),
      ('BS Mathematics Representative',12,'clas'),('BS Psychology Representative',13,'clas'),
      ('AB Political Science Representative',14,'clas'),('BA Communications Representative',15,'clas'),
      ('BS EMC Representative',16,'clas'),
      ('Executive Committee',17,'clas'),
      ('Marketing Executive Committee',18,'clas'),
      ('Program Executive Committee',19,'clas'),
      ('Logistics Executive Committee',20,'clas')");
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS candidates (id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(120) NOT NULL, motto TEXT DEFAULT NULL, position_id INT NOT NULL, vote_count INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS votes (id INT AUTO_INCREMENT PRIMARY KEY, student_db_id INT NOT NULL, position_id INT NOT NULL, candidate_id INT NOT NULL, voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (student_db_id) REFERENCES students(id) ON DELETE CASCADE, FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE, UNIQUE KEY unique_vote (student_db_id,position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS election_settings (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) DEFAULT 'Election 2026', status ENUM('Not Started','Ongoing','Ended') DEFAULT 'Not Started', start_dt DATETIME NULL, end_dt DATETIME NULL, ballot_locked TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── CLAS election settings ──────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS clas_election_settings (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) DEFAULT 'CLAS Council Election 2026', status ENUM('Not Started','Ongoing','Ended') DEFAULT 'Not Started', start_dt DATETIME NULL, end_dt DATETIME NULL, ballot_locked TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $clasElCount = (int)$pdo->query("SELECT COUNT(*) FROM clas_election_settings")->fetchColumn();
  if ($clasElCount === 0) {
    $pdo->exec("INSERT INTO clas_election_settings (title,status) VALUES ('CLAS Council Election 2026','Not Started')");
  }

  // ── CLAS votes ──────────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS clas_votes (id INT AUTO_INCREMENT PRIMARY KEY, student_db_id INT NOT NULL, position_id INT NOT NULL, candidate_id INT NOT NULL, voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (student_db_id) REFERENCES students(id) ON DELETE CASCADE, FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE, UNIQUE KEY unique_clas_vote (student_db_id,position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── Election History ────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS election_history (id INT AUTO_INCREMENT PRIMARY KEY, election_type VARCHAR(20) NOT NULL DEFAULT 'general', title VARCHAR(200) NOT NULL, start_dt DATETIME NULL, end_dt DATETIME NULL, total_voters INT DEFAULT 0, votes_cast INT DEFAULT 0, archived_at DATETIME DEFAULT NOW(), results_json LONGTEXT DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, category VARCHAR(50), action VARCHAR(100), details TEXT, admin_id INT, created_at DATETIME DEFAULT NOW()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120), category VARCHAR(80), message TEXT, rating TINYINT, reply TEXT, is_read TINYINT(1) DEFAULT 0, created_at DATETIME DEFAULT NOW()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── Safe column migrations ──────────────────────────────────
  try {
    $pdo->exec("ALTER TABLE students ADD COLUMN has_voted_clas TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Exception $e) {
  }
  try {
    $pdo->exec("ALTER TABLE positions ADD COLUMN election_type VARCHAR(20) NOT NULL DEFAULT 'general'");
  } catch (Exception $e) {
  }
  try {
    $pdo->exec("ALTER TABLE candidates ADD COLUMN photo VARCHAR(300) DEFAULT NULL");
  } catch (Exception $e) {
  }
  try {
    $pdo->exec("ALTER TABLE candidates ADD COLUMN program VARCHAR(120) DEFAULT NULL");
  } catch (Exception $e) {
  }
  try {
    $pdo->exec("ALTER TABLE candidates ADD COLUMN section VARCHAR(60) DEFAULT NULL");
  } catch (Exception $e) {
  }
  // ── Sync CLAS positions to canonical list ──────────────────
  try {
    $canonicalClas = [
      1  => 'Prime Minister',
      2  => 'Deputy Prime Minister',
      3  => 'General Secretary',
      4  => 'Chief Financial Officer',
      5  => 'General Auditor',
      6  => 'Ministerial Press Relations Officer',
      7  => 'Course Representative',
      8  => 'BPA Representative',
      9  => 'BSIS Representative',
      10 => 'BSIT Representative',
      11 => 'BSCS Representative',
      12 => 'BS Mathematics Representative',
      13 => 'BS Psychology Representative',
      14 => 'AB Political Science Representative',
      15 => 'BA Communications Representative',
      16 => 'BS EMC Representative',
      17 => 'Executive Committee',
      18 => 'Marketing Executive Committee',
      19 => 'Program Executive Committee',
      20 => 'Logistics Executive Committee',
    ];
    foreach ($canonicalClas as $order => $name) {
      $exists = (int)$pdo->prepare("SELECT COUNT(*) FROM positions WHERE name=:n AND election_type='clas'")->execute([':n' => $name]) ? (int)$pdo->query("SELECT COUNT(*) FROM positions WHERE name=" . $pdo->quote($name) . " AND election_type='clas'")->fetchColumn() : 0;
      if ($exists === 0) {
        $pdo->prepare("INSERT INTO positions (name,is_active,sort_order,election_type) VALUES (:n,1,:o,'clas')")->execute([':n' => $name, ':o' => $order]);
      } else {
        $pdo->prepare("UPDATE positions SET sort_order=:o, is_active=1 WHERE name=:n AND election_type='clas'")->execute([':o' => $order, ':n' => $name]);
      }
    }
    // Deactivate any old CLAS positions not in the canonical list
    $canonicalNames = array_values($canonicalClas);
    $placeholders   = implode(',', array_fill(0, count($canonicalNames), '?'));
    $pdo->prepare("UPDATE positions SET is_active=0 WHERE election_type='clas' AND name NOT IN ($placeholders)")->execute($canonicalNames);
  } catch (Exception $e) {
  }
  if (!is_dir(__DIR__ . '/uploads/candidates')) {
    @mkdir(__DIR__ . '/uploads/candidates', 0755, true);
  }
} catch (Exception $e) {
  die('<div style="font-family:sans-serif;background:#fef2f2;border:2px solid #fca5a5;color:#dc2626;padding:24px;margin:40px auto;max-width:700px;border-radius:12px"><h2>Database Setup Error</h2><p>' . $e->getMessage() . '</p></div>');
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php');
  exit;
}

// ── Live-Results AJAX API (no page reload needed) ──────────
if (isset($_GET['api']) && $_GET['api'] === 'live_results') {
  header('Content-Type: application/json');
  try {
    $apiPos = db()->query("SELECT * FROM positions WHERE is_active=1 AND election_type='general' ORDER BY sort_order")->fetchAll();
    $apiOut = [];
    foreach ($apiPos as $ap) {
      $acs = db()->prepare("SELECT c.full_name,c.vote_count,ROUND(c.vote_count/NULLIF((SELECT SUM(vote_count) FROM candidates WHERE position_id=:pid2 AND is_active=1),0)*100,1) AS pct FROM candidates c WHERE c.position_id=:pid AND c.is_active=1 ORDER BY c.vote_count DESC");
      $acs->execute([':pid' => $ap['id'], ':pid2' => $ap['id']]);
      $apiOut[] = ['position' => $ap['name'], 'candidates' => $acs->fetchAll()];
    }
    $avc = (int)db()->query("SELECT COUNT(DISTINCT student_db_id) FROM votes")->fetchColumn();
    $atv = (int)db()->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();
    echo json_encode(['positions' => $apiOut, 'votes_cast' => $avc, 'total_voters' => $atv, 'pct' => $atv > 0 ? round($avc / $atv * 100, 1) : 0, 'last_updated' => date('h:i:s A')]);
  } catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}
// ── Results Expiry Status API (called by user_view.php to check 1-hour window) ──
if (isset($_GET['api']) && $_GET['api'] === 'results_expiry') {
  header('Content-Type: application/json');
  try {
    $now = time();
    $genEl  = db()->query("SELECT status, end_dt FROM election_settings LIMIT 1")->fetch();
    $clasEl = db()->query("SELECT status, end_dt FROM clas_election_settings LIMIT 1")->fetch();

    $genEndTs  = ($genEl  && $genEl['end_dt'])  ? strtotime($genEl['end_dt'])  : 0;
    $clasEndTs = ($clasEl && $clasEl['end_dt']) ? strtotime($clasEl['end_dt']) : 0;

    $genStatus  = $genEl['status']  ?? 'Not Started';
    $clasStatus = $clasEl['status'] ?? 'Not Started';

    $genVisible  = ($genStatus  === 'Ongoing') || ($genStatus  === 'Ended' && $genEndTs  && ($now - $genEndTs)  < 3600);
    $clasVisible = ($clasStatus === 'Ongoing') || ($clasStatus === 'Ended' && $clasEndTs && ($now - $clasEndTs) < 3600);

    $genSecsLeft  = ($genStatus  === 'Ended' && $genEndTs)  ? max(0, 3600 - ($now - $genEndTs))  : 0;
    $clasSecsLeft = ($clasStatus === 'Ended' && $clasEndTs) ? max(0, 3600 - ($now - $clasEndTs)) : 0;

    echo json_encode([
      'ok'               => true,
      'gen_visible'      => $genVisible,
      'clas_visible'     => $clasVisible,
      'gen_secs_left'    => $genSecsLeft,
      'clas_secs_left'   => $clasSecsLeft,
      'gen_status'       => $genStatus,
      'clas_status'      => $clasStatus,
    ]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// ── Archive Trigger API (called by user_view.php when 1-hour window expires) ──
// Ensures results are archived in election_history even if admin page was never loaded
if (isset($_GET['api']) && $_GET['api'] === 'archive_results') {
  header('Content-Type: application/json');
  try {
    $archived = [];

    // General
    $genEl = db()->query("SELECT * FROM election_settings LIMIT 1")->fetch();
    if ($genEl && $genEl['status'] === 'Ended') {
      $chk = db()->prepare("SELECT COUNT(*) FROM election_history WHERE title=:t AND election_type='general' AND DATE(archived_at)=CURDATE()");
      $chk->execute([':t' => $genEl['title']]);
      if (!(int)$chk->fetchColumn()) {
        $positions = db()->query("SELECT p.*,(SELECT c.full_name FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner,(SELECT c.vote_count FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner_votes FROM positions p WHERE p.is_active=1 AND p.election_type='general' ORDER BY p.sort_order")->fetchAll();
        $vc = (int)db()->query("SELECT COUNT(DISTINCT student_db_id) FROM votes")->fetchColumn();
        $tv = (int)db()->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();
        db()->prepare("INSERT INTO election_history (election_type,title,start_dt,end_dt,total_voters,votes_cast,archived_at,results_json) VALUES ('general',:t,:s,:e,:tv,:vc,NOW(),:rj)")
          ->execute([':t' => $genEl['title'], ':s' => $genEl['start_dt'], ':e' => $genEl['end_dt'], ':tv' => $tv, ':vc' => $vc, ':rj' => json_encode(['positions' => $positions, 'votes_cast' => $vc, 'total_voters' => $tv])]);
        logAction('Election', 'Archived', 'General election auto-archived after 1-hour user window');
        $archived[] = 'general';
      }
    }

    // CLAS
    $clasEl = db()->query("SELECT * FROM clas_election_settings LIMIT 1")->fetch();
    if ($clasEl && $clasEl['status'] === 'Ended') {
      $chk = db()->prepare("SELECT COUNT(*) FROM election_history WHERE title=:t AND election_type='clas' AND DATE(archived_at)=CURDATE()");
      $chk->execute([':t' => $clasEl['title']]);
      if (!(int)$chk->fetchColumn()) {
        $cPos = db()->query("SELECT p.*,(SELECT c.full_name FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner,(SELECT c.vote_count FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner_votes FROM positions p WHERE p.is_active=1 AND p.election_type='clas' ORDER BY p.sort_order")->fetchAll();
        $vc = (int)db()->query("SELECT COUNT(DISTINCT student_db_id) FROM clas_votes")->fetchColumn();
        $tv = (int)db()->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();
        db()->prepare("INSERT INTO election_history (election_type,title,start_dt,end_dt,total_voters,votes_cast,archived_at,results_json) VALUES ('clas',:t,:s,:e,:tv,:vc,NOW(),:rj)")
          ->execute([':t' => $clasEl['title'], ':s' => $clasEl['start_dt'], ':e' => $clasEl['end_dt'], ':tv' => $tv, ':vc' => $vc, ':rj' => json_encode(['positions' => $cPos, 'votes_cast' => $vc, 'total_voters' => $tv])]);
        logAction('Election', 'Archived', 'CLAS election auto-archived after 1-hour user window');
        $archived[] = 'clas';
      }
    }

    echo json_encode(['ok' => true, 'archived' => $archived]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// ── Student search API for candidate autocomplete ──────────
if (isset($_GET['api']) && $_GET['api'] === 'search_students') {
  header('Content-Type: application/json');
  $q = '%' . trim($_GET['q'] ?? '') . '%';
  try {
    $rows = db()->prepare("SELECT id, student_id, first_name, last_name, email FROM students WHERE approval_status='Approved' AND (CONCAT(first_name,' ',last_name) LIKE :q OR student_id LIKE :q2) ORDER BY first_name, last_name LIMIT 10");
    $rows->execute([':q' => $q, ':q2' => $q]);
    echo json_encode($rows->fetchAll());
  } catch (Exception $e) {
    echo json_encode([]);
  }
  exit;
}

$_SESSION['admin_id']       = $_SESSION['admin_id']       ?? $_SESSION['user_id'];
$_SESSION['admin_name']     = $_SESSION['admin_name']      ?? ($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
$_SESSION['admin_username'] = $_SESSION['admin_username']  ?? $_SESSION['student_id'];
$_SESSION['admin_email']    = $_SESSION['admin_email']     ?? $_SESSION['email'];

// ── Auto-update GENERAL election status ────────────────────
try {
  $autoEl = db()->query("SELECT * FROM election_settings LIMIT 1")->fetch();
  if ($autoEl) {
    $now = $now2 = new DateTime();
    $start = $autoEl['start_dt'] ? new DateTime($autoEl['start_dt']) : null;
    $end = $autoEl['end_dt'] ? new DateTime($autoEl['end_dt']) : null;
    $curSt = $autoEl['status'];
    $newSt = $curSt;
    if ($start && $end) {
      if ($now >= $start && $now < $end) {
        $newSt = 'Ongoing';
      } elseif ($now >= $end) {
        $newSt = 'Ended';
      } else {
        $newSt = 'Not Started';
      }
    } elseif ($start && !$end) {
      if ($now >= $start) {
        $newSt = 'Ongoing';
      } else {
        $newSt = 'Not Started';
      }
    } elseif (!$start && $end) {
      if ($now >= $end) {
        $newSt = 'Ended';
      }
    }
    if ($newSt !== $curSt) {
      db()->prepare("UPDATE election_settings SET status=:s")->execute([':s' => $newSt]);
      logAction('Election', 'Auto Status', "General: '{$curSt}'→'{$newSt}'");
    }

    // ── Archive when election just ended ─────────────────────
    if ($newSt === 'Ended' && $curSt === 'Ongoing') {
      $archiveStmt = db()->prepare("SELECT COUNT(*) FROM election_history WHERE title=:t AND election_type='general' AND DATE(archived_at)=CURDATE()");
      $archiveStmt->execute([':t' => $autoEl['title']]);
      $alreadyArchived = (int)$archiveStmt->fetchColumn();
      if (!$alreadyArchived) {
        $positions2 = db()->query("SELECT p.*,(SELECT c.full_name FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner,(SELECT c.vote_count FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner_votes FROM positions p WHERE p.is_active=1 AND p.election_type='general' ORDER BY p.sort_order")->fetchAll();
        $vc2 = (int)db()->query("SELECT COUNT(DISTINCT student_db_id) FROM votes")->fetchColumn();
        $tv2 = (int)db()->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();
        $histJson = json_encode(['positions' => $positions2, 'votes_cast' => $vc2, 'total_voters' => $tv2]);
        db()->prepare("INSERT INTO election_history (election_type,title,start_dt,end_dt,total_voters,votes_cast,archived_at,results_json) VALUES ('general',:t,:s,:e,:tv,:vc,NOW(),:rj)")
          ->execute([':t' => $autoEl['title'], ':s' => $autoEl['start_dt'], ':e' => $autoEl['end_dt'], ':tv' => $tv2, ':vc' => $vc2, ':rj' => $histJson]);
        logAction('Election', 'Archived', 'General election archived: ' . $autoEl['title']);

        // ── Auto-deactivate general candidates so they no longer
        //    appear on the ballot. Votes + history are untouched. ──
        $deactCount = db()->exec(
          "UPDATE candidates SET is_active=0
           WHERE position_id IN (
             SELECT id FROM positions WHERE election_type='general'
           ) AND is_active=1"
        );
        if ($deactCount > 0) {
          logAction('Election', 'Auto Deactivate', "General: {$deactCount} candidate(s) deactivated after election ended.");
        }

        // ── Reset has_voted so every voter can participate in the next election ──
        db()->exec("UPDATE students SET has_voted=0");
        logAction('Election', 'Auto Reset Votes', "General: voter has_voted flags reset for next election.");
      }
    }
  }
} catch (Exception $e) {
}

// ── Auto-update CLAS election status ───────────────────────
try {
  $clasEl = db()->query("SELECT * FROM clas_election_settings LIMIT 1")->fetch();
  if ($clasEl) {
    $now = new DateTime();
    $cs = $clasEl['start_dt'] ? new DateTime($clasEl['start_dt']) : null;
    $ce = $clasEl['end_dt'] ? new DateTime($clasEl['end_dt']) : null;
    $cCurSt = $clasEl['status'];
    $cNewSt = $cCurSt;
    if ($cs && $ce) {
      if ($now >= $cs && $now < $ce) {
        $cNewSt = 'Ongoing';
      } elseif ($now >= $ce) {
        $cNewSt = 'Ended';
      } else {
        $cNewSt = 'Not Started';
      }
    } elseif ($cs && !$ce) {
      if ($now >= $cs) {
        $cNewSt = 'Ongoing';
      } else {
        $cNewSt = 'Not Started';
      }
    } elseif (!$cs && $ce) {
      if ($now >= $ce) {
        $cNewSt = 'Ended';
      }
    }
    if ($cNewSt !== $cCurSt) {
      db()->prepare("UPDATE clas_election_settings SET status=:s")->execute([':s' => $cNewSt]);
      logAction('Election', 'Auto Status', "CLAS: '{$cCurSt}'→'{$cNewSt}'");
    }

    // ── Archive CLAS when just ended ─────────────────────────
    if ($cNewSt === 'Ended' && $cCurSt === 'Ongoing') {
      $clasArchiveStmt = db()->prepare("SELECT COUNT(*) FROM election_history WHERE title=:t AND election_type='clas' AND DATE(archived_at)=CURDATE()");
      $clasArchiveStmt->execute([':t' => $clasEl['title']]);
      $clasAlreadyArchived = (int)$clasArchiveStmt->fetchColumn();
      if (!$clasAlreadyArchived) {
        $cPos = db()->query("SELECT p.*,(SELECT c.full_name FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner,(SELECT c.vote_count FROM candidates c WHERE c.position_id=p.id AND c.is_active=1 ORDER BY c.vote_count DESC LIMIT 1) AS winner_votes FROM positions p WHERE p.is_active=1 AND p.election_type='clas' ORDER BY p.sort_order")->fetchAll();
        $cVc = (int)db()->query("SELECT COUNT(DISTINCT student_db_id) FROM clas_votes")->fetchColumn();
        $cTv = (int)db()->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();
        $cJson = json_encode(['positions' => $cPos, 'votes_cast' => $cVc, 'total_voters' => $cTv]);
        db()->prepare("INSERT INTO election_history (election_type,title,start_dt,end_dt,total_voters,votes_cast,archived_at,results_json) VALUES ('clas',:t,:s,:e,:tv,:vc,NOW(),:rj)")
          ->execute([':t' => $clasEl['title'], ':s' => $clasEl['start_dt'], ':e' => $clasEl['end_dt'], ':tv' => $cTv, ':vc' => $cVc, ':rj' => $cJson]);
        logAction('Election', 'Archived', 'CLAS election archived: ' . $clasEl['title']);

        // ── Auto-deactivate CLAS candidates so they no longer
        //    appear on the ballot. Votes + history are untouched. ──
        $cDeactCount = db()->exec(
          "UPDATE candidates SET is_active=0
           WHERE position_id IN (
             SELECT id FROM positions WHERE election_type='clas'
           ) AND is_active=1"
        );
        if ($cDeactCount > 0) {
          logAction('Election', 'Auto Deactivate', "CLAS: {$cDeactCount} candidate(s) deactivated after election ended.");
        }

        // ── Reset has_voted_clas so every voter can participate in the next CLAS election ──
        db()->exec("UPDATE students SET has_voted_clas=0");
        logAction('Election', 'Auto Reset Votes', "CLAS: voter has_voted_clas flags reset for next election.");
      }
    }
  }
} catch (Exception $e) {
}

$postAction = $_POST['action'] ?? '';
$section    = $_GET['section'] ?? 'overview';
$msg = $err = '';

if ($postAction) {
  try {
    $pdo = db();

    // ── Voter CRUD ────────────────────────────────────────────
    if ($postAction === 'save_voter') {
      $id = (int)($_POST['id'] ?? 0);
      $sid = trim($_POST['student_id'] ?? '');
      $fn = trim($_POST['first_name'] ?? '');
      $ln = trim($_POST['last_name'] ?? '');
      $em = trim($_POST['email'] ?? '');
      $bd = $_POST['birthdate'] ?? '';
      $st = $_POST['status'] ?? 'Pending';
      if (!$sid || !$fn || !$em) {
        $err = 'Student ID, first name and email are required.';
      } elseif ($id) {
        $pdo->prepare("UPDATE students SET student_id=:sid,first_name=:fn,last_name=:ln,email=:em,birthdate=:bd,approval_status=:st WHERE id=:id")->execute([':sid' => $sid, ':fn' => $fn, ':ln' => $ln, ':em' => $em, ':bd' => $bd, ':st' => $st, ':id' => $id]);
        logAction('Voters', 'Update', 'Updated: ' . $sid);
        $msg = 'Voter updated.';
      } else {
        $pw = password_hash('changeme123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO students(student_id,first_name,last_name,email,birthdate,password_hash,approval_status,has_voted,has_voted_clas,created_at) VALUES(:sid,:fn,:ln,:em,:bd,:pw,:st,0,0,NOW())")->execute([':sid' => $sid, ':fn' => $fn, ':ln' => $ln, ':em' => $em, ':bd' => $bd, ':pw' => $pw, ':st' => $st]);
        logAction('Voters', 'Add', 'Added: ' . $sid);
        $msg = 'Voter added. Default password: changeme123';
      }
      $section = 'voters';
    }
    if ($postAction === 'delete_voter') {
      $id = (int)($_POST['id'] ?? 0);
      $row = $pdo->prepare("SELECT student_id FROM students WHERE id=:id");
      $row->execute([':id' => $id]);
      $row = $row->fetch();
      $pdo->prepare("DELETE FROM students WHERE id=:id")->execute([':id' => $id]);
      logAction('Voters', 'Delete', 'Deleted: ' . ($row['student_id'] ?? ''));
      $msg = 'Voter deleted.';
      $section = 'voters';
    }
    if ($postAction === 'approve_voter') {
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare("UPDATE students SET approval_status='Approved' WHERE id=:id")->execute([':id' => $id]);
      $row = $pdo->prepare("SELECT student_id FROM students WHERE id=:id");
      $row->execute([':id' => $id]);
      $row = $row->fetch();
      logAction('Registration', 'Approve', 'Approved: ' . ($row['student_id'] ?? ''));
      $msg = 'Voter approved.';
      $section = 'voters';
    }
    if ($postAction === 'reject_voter') {
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare("UPDATE students SET approval_status='Rejected' WHERE id=:id")->execute([':id' => $id]);
      $row = $pdo->prepare("SELECT student_id FROM students WHERE id=:id");
      $row->execute([':id' => $id]);
      $row = $row->fetch();
      logAction('Registration', 'Reject', 'Rejected: ' . ($row['student_id'] ?? ''));
      $msg = 'Voter rejected.';
      $section = 'voters';
    }

    // ── Candidate CRUD ────────────────────────────────────────
    if ($postAction === 'save_candidate') {
      $id = (int)($_POST['id'] ?? 0);
      $nm = trim($_POST['full_name'] ?? '');
      $pos = trim($_POST['position'] ?? '');
      $bio = trim($_POST['motto'] ?? '');
      $etype = trim($_POST['election_type'] ?? 'general');
      $prog = trim($_POST['program'] ?? '');
      $sect = trim($_POST['section'] ?? '');
      if (!$nm || !$pos) {
        $err = 'Name and position are required.';
      } else {
        // ── Validate: position must belong to the chosen election type ──
        $posTypeChk = $pdo->prepare("SELECT election_type FROM positions WHERE name=:n AND is_active=1 LIMIT 1");
        $posTypeChk->execute([':n' => $pos]);
        $posTypeRow = $posTypeChk->fetch();
        if ($posTypeRow && $posTypeRow['election_type'] !== $etype) {
          $err = 'Position "' . h($pos) . '" belongs to the ' . strtoupper($posTypeRow['election_type']) . ' election, not ' . strtoupper($etype) . '. Please select the correct election type.';
        }
      }
      if (!$err && $nm && $pos) {
        // Handle photo upload
        $photoPath = null;
        $photoErr = '';
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
          $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
          if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $uploadDir = __DIR__ . '/uploads/candidates/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fname = 'cand_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fname)) {
              $photoPath = 'uploads/candidates/' . $fname;
            } else {
              $photoErr = 'Failed to save photo.';
            }
          } else {
            $photoErr = 'Photo must be JPG, PNG, GIF, or WEBP.';
          }
        }
        if ($photoErr) {
          $err = $photoErr;
        } else {
          $ps = $pdo->prepare("SELECT id FROM positions WHERE name=:n LIMIT 1");
          $ps->execute([':n' => $pos]);
          $pid = $ps->fetchColumn();
          if (!$pid) {
            $pdo->prepare("INSERT INTO positions(name,is_active,sort_order,election_type) VALUES(:n,1,99,:et)")->execute([':n' => $pos, ':et' => $etype]);
            $pid = $pdo->lastInsertId();
          }

          // ── CLAS: enforce year/section uniqueness rules ──────────
          if ($etype === 'clas') {
            if (!$sect) {
              $err = 'Section is required for all CLAS positions (e.g. 1A, 2B).';
            } else {
              $isRep = stripos($pos, 'Representative') !== false;
              if ($isRep) {
                // Program Representatives → 1 candidate per section per position
                $sectSql = "SELECT c.full_name FROM candidates c
                            JOIN positions p ON c.position_id = p.id
                            WHERE p.name = :pname AND p.election_type = 'clas'
                            AND c.section = :sect AND c.is_active = 1"
                  . ($id ? " AND c.id != :cid" : "");
                $sectChk = $pdo->prepare($sectSql);
                $sectParams = [':pname' => $pos, ':sect' => $sect];
                if ($id) $sectParams[':cid'] = $id;
                $sectChk->execute($sectParams);
                $existing = $sectChk->fetch();
                if ($existing) {
                  $err = "Section '{$sect}' already has an active candidate ({$existing['full_name']}) for {$pos}. Only 1 candidate per section is allowed for Representative positions.";
                }
              } else {
                // Council positions → 1 candidate per year level per position
                $year = substr(trim($sect), 0, 1);
                $sectSql = "SELECT c.full_name FROM candidates c
                            JOIN positions p ON c.position_id = p.id
                            WHERE p.name = :pname AND p.election_type = 'clas'
                            AND LEFT(c.section, 1) = :yr AND c.is_active = 1"
                  . ($id ? " AND c.id != :cid" : "");
                $sectChk = $pdo->prepare($sectSql);
                $sectParams = [':pname' => $pos, ':yr' => $year];
                if ($id) $sectParams[':cid'] = $id;
                $sectChk->execute($sectParams);
                $existing = $sectChk->fetch();
                if ($existing) {
                  $err = "Year {$year} already has an active candidate ({$existing['full_name']}) running for {$pos}. Only 1 candidate per year level is allowed for council positions.";
                }
              }
            }
          }

          if (!$err) {
            if ($id) {
              if ($photoPath) {
                $oldRow = $pdo->prepare("SELECT photo FROM candidates WHERE id=:id");
                $oldRow->execute([':id' => $id]);
                $oldPhoto = $oldRow->fetchColumn();
                if ($oldPhoto && file_exists(__DIR__ . '/' . $oldPhoto)) @unlink(__DIR__ . '/' . $oldPhoto);
                $pdo->prepare("UPDATE candidates SET full_name=:n,motto=:b,position_id=:pid,photo=:ph,program=:prog,section=:sect WHERE id=:id")->execute([':n' => $nm, ':b' => $bio, ':pid' => $pid, ':ph' => $photoPath, ':prog' => $prog, ':sect' => $sect, ':id' => $id]);
              } else {
                $pdo->prepare("UPDATE candidates SET full_name=:n,motto=:b,position_id=:pid,program=:prog,section=:sect WHERE id=:id")->execute([':n' => $nm, ':b' => $bio, ':pid' => $pid, ':prog' => $prog, ':sect' => $sect, ':id' => $id]);
              }
              logAction('Candidates', 'Update', 'Updated: ' . $nm);
              $msg = 'Candidate updated.';
            } else {
              $pdo->prepare("INSERT INTO candidates(full_name,motto,position_id,vote_count,is_active,photo,program,section) VALUES(:n,:b,:pid,0,1,:ph,:prog,:sect)")->execute([':n' => $nm, ':b' => $bio, ':pid' => $pid, ':ph' => $photoPath, ':prog' => $prog, ':sect' => $sect]);
              logAction('Candidates', 'Add', 'Added: ' . $nm);
              $msg = 'Candidate added.';
            }
          }
        }
      }
      $section = 'candidates';
    }
    if ($postAction === 'delete_candidate') {
      $id = (int)($_POST['id'] ?? 0);
      $row = $pdo->prepare("SELECT full_name, photo FROM candidates WHERE id=:id");
      $row->execute([':id' => $id]);
      $row = $row->fetch();
      if ($row && !empty($row['photo']) && file_exists(__DIR__ . '/' . $row['photo'])) {
        @unlink(__DIR__ . '/' . $row['photo']);
      }
      $pdo->prepare("DELETE FROM candidates WHERE id=:id")->execute([':id' => $id]);
      logAction('Candidates', 'Delete', 'Deleted: ' . ($row['full_name'] ?? ''));
      $msg = 'Candidate deleted.';
      $section = 'candidates';
    }

    // ── General Election settings ─────────────────────────────
    if ($postAction === 'save_election') {
      $title = trim($_POST['title'] ?? 'Election 2026');
      $status = $_POST['status'] ?? 'Not Started';
      $start = $_POST['start_dt'] ?? null;
      $end = $_POST['end_dt'] ?? null;
      $locked = isset($_POST['ballot_locked']) ? 1 : 0;
      if ($start && $end && strtotime($end) <= strtotime($start)) {
        $err = 'End must be after start.';
      } else {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM election_settings")->fetchColumn();
        $params = [':t' => $title, ':s' => $status, ':sd' => $start ?: null, ':ed' => $end ?: null, ':bl' => $locked];
        if ($exists) {
          $pdo->prepare("UPDATE election_settings SET title=:t,status=:s,start_dt=:sd,end_dt=:ed,ballot_locked=:bl")->execute($params);
        } else {
          $pdo->prepare("INSERT INTO election_settings(title,status,start_dt,end_dt,ballot_locked) VALUES(:t,:s,:sd,:ed,:bl)")->execute($params);
        }
        logAction('Election', 'Update', 'General: ' . $status);
        $msg = 'General election settings saved.';
      }
      $section = 'election-control';
    }

    // ── CLAS Election settings ────────────────────────────────
    if ($postAction === 'save_clas_election') {
      $title = trim($_POST['clas_title'] ?? 'CLAS Council Election 2026');
      $status = $_POST['clas_status'] ?? 'Not Started';
      $start = $_POST['clas_start_dt'] ?? null;
      $end = $_POST['clas_end_dt'] ?? null;
      $locked = isset($_POST['clas_ballot_locked']) ? 1 : 0;
      if ($start && $end && strtotime($end) <= strtotime($start)) {
        $err = 'End must be after start.';
      } else {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM clas_election_settings")->fetchColumn();
        $params = [':t' => $title, ':s' => $status, ':sd' => $start ?: null, ':ed' => $end ?: null, ':bl' => $locked];
        if ($exists) {
          $pdo->prepare("UPDATE clas_election_settings SET title=:t,status=:s,start_dt=:sd,end_dt=:ed,ballot_locked=:bl")->execute($params);
        } else {
          $pdo->prepare("INSERT INTO clas_election_settings(title,status,start_dt,end_dt,ballot_locked) VALUES(:t,:s,:sd,:ed,:bl)")->execute($params);
        }
        logAction('Election', 'Update', 'CLAS: ' . $status);
        $msg = 'CLAS election settings saved.';
      }
      $section = 'clas-election';
    }

    // ── Reset votes ───────────────────────────────────────────
    if ($postAction === 'reset_votes' && isset($_POST['confirm_reset'])) {
      $pdo->exec("DELETE FROM votes");
      $pdo->exec("UPDATE candidates SET vote_count=0 WHERE position_id IN (SELECT id FROM positions WHERE election_type='general')");
      $pdo->exec("UPDATE students SET has_voted=0");
      logAction('Election', 'Reset', 'General votes reset');
      $msg = 'General votes reset.';
      $section = 'election-control';
    }
    if ($postAction === 'reset_clas_votes' && isset($_POST['confirm_reset_clas'])) {
      $pdo->exec("DELETE FROM clas_votes");
      $pdo->exec("UPDATE candidates SET vote_count=0 WHERE position_id IN (SELECT id FROM positions WHERE election_type='clas')");
      $pdo->exec("UPDATE students SET has_voted_clas=0");
      logAction('Election', 'Reset', 'CLAS votes reset');
      $msg = 'CLAS votes reset.';
      $section = 'clas-election';
    }

    // ── Delete history entry ──────────────────────────────────
    if ($postAction === 'delete_history') {
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare("DELETE FROM election_history WHERE id=:id")->execute([':id' => $id]);
      logAction('Election', 'Delete History', 'Deleted history #' . $id);
      $msg = 'History entry deleted.';
      $section = 'election-history';
    }

    // ── Misc ──────────────────────────────────────────────────
    if ($postAction === 'clear_logs' && isset($_POST['confirm_clear'])) {
      $pdo->exec("DELETE FROM audit_log");
      logAction('Election', 'Clear', 'Audit cleared');
      $msg = 'Audit log cleared.';
      $section = 'audit-log';
    }
    if ($postAction === 'delete_log') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $pdo->prepare("DELETE FROM audit_log WHERE id=:id")->execute([':id' => $id]);
        logAction('Admin', 'Delete Log', 'Deleted audit entry #' . $id);
        $msg = 'Log entry deleted.';
      }
      $section = 'audit-log';
    }
    if ($postAction === 'bulk_delete_logs') {
      $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
      if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM audit_log WHERE id IN ($placeholders)")->execute($ids);
        logAction('Admin', 'Bulk Delete Log', 'Deleted ' . count($ids) . ' audit entries');
        $msg = count($ids) . ' log entries deleted.';
      }
      $section = 'audit-log';
    }
    if ($postAction === 'mark_read') {
      $pdo->prepare("UPDATE feedback SET is_read=1 WHERE id=:id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
      $msg = 'Marked as read.';
      $section = 'feedback';
    }
    if ($postAction === 'delete_feedback') {
      $pdo->prepare("DELETE FROM feedback WHERE id=:id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
      $msg = 'Feedback deleted.';
      $section = 'feedback';
    }
    if ($postAction === 'reply_feedback') {
      $id = (int)($_POST['id'] ?? 0);
      $reply = trim($_POST['reply'] ?? '');
      if (!$reply) {
        $err = 'Reply cannot be empty.';
      } else {
        $pdo->prepare("UPDATE feedback SET reply=:r,is_read=1 WHERE id=:id")->execute([':r' => $reply, ':id' => $id]);
        logAction('Feedback', 'Reply', 'Replied to #' . $id);
        $msg = 'Reply saved.';
      }
      $section = 'feedback';
    }
    if ($postAction === 'save_account') {
      $full = trim($_POST['full_name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $uname = trim($_POST['username'] ?? '');
      $pass = $_POST['new_password'] ?? '';
      $conf = $_POST['confirm_password'] ?? '';
      $aid = (int)$_SESSION['admin_id'];
      if (!$full || !$email || !$uname) {
        $err = 'Full name, email, and username are required.';
      } elseif ($pass && $pass !== $conf) {
        $err = 'Passwords do not match.';
      } elseif ($pass && strlen($pass) < 6) {
        $err = 'Password must be at least 6 characters.';
      } else {
        if ($pass) {
          $hashed = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
          $pdo->prepare("UPDATE admins SET full_name=:fn,email=:em,username=:un,password=:pw WHERE id=:id")->execute([':fn' => $full, ':em' => $email, ':un' => $uname, ':pw' => $hashed, ':id' => $aid]);
        } else {
          $pdo->prepare("UPDATE admins SET full_name=:fn,email=:em,username=:un WHERE id=:id")->execute([':fn' => $full, ':em' => $email, ':un' => $uname, ':id' => $aid]);
        }
        $_SESSION['admin_name'] = $full;
        $_SESSION['admin_username'] = $uname;
        $_SESSION['admin_email'] = $email;
        logAction('Admin', 'Account Update', 'Admin updated account');
        $msg = 'Account updated.';
      }
      $section = 'account';
    }
  } catch (Exception $e) {
    $err = 'Database error: ' . $e->getMessage();
  }
  if (!$err) {
    header('Location: admin.php?' . http_build_query(['section' => $section, 'msg' => $msg]));
    exit;
  }
}
if (empty($msg) && isset($_GET['msg'])) $msg = $_GET['msg'];

// ── Load data ───────────────────────────────────────────────
$pdo = db();
try {
  $total_voters = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
  $votes_cast = (int)$pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM votes")->fetchColumn();
  $clas_votes_cast = (int)$pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM clas_votes")->fetchColumn();
  $total_candidates = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE is_active=1")->fetchColumn();
  $pending_voters = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Pending'")->fetchColumn();
  $turnout = $total_voters > 0 ? round($votes_cast / $total_voters * 100) : 0;
  $election = $pdo->query("SELECT * FROM election_settings LIMIT 1")->fetch() ?: ['title' => 'Election 2026', 'status' => 'Not Started', 'start_dt' => '', 'end_dt' => '', 'ballot_locked' => 0];
  $clasElection = $pdo->query("SELECT * FROM clas_election_settings LIMIT 1")->fetch() ?: ['title' => 'CLAS Council Election 2026', 'status' => 'Not Started', 'start_dt' => '', 'end_dt' => '', 'ballot_locked' => 0];
  $admin_row = $pdo->prepare("SELECT * FROM admins WHERE id=:id");
  $admin_row->execute([':id' => $_SESSION['admin_id']]);
  $admin_row = $admin_row->fetch() ?: [];
} catch (Exception $e) {
  $total_voters = $votes_cast = $total_candidates = $turnout = $pending_voters = $clas_votes_cast = 0;
  $election = ['title' => '—', 'status' => '—', 'start_dt' => '', 'end_dt' => '', 'ballot_locked' => 0];
  $clasElection = ['title' => '—', 'status' => '—', 'start_dt' => '', 'end_dt' => '', 'ballot_locked' => 0];
  $admin_row = [];
}

// ── Load ALL positions grouped by election type for the modal dropdown ──
$allPositions = $pdo->query("SELECT id, name, election_type FROM positions WHERE is_active=1 ORDER BY election_type, sort_order")->fetchAll();
$generalPositionsList = array_filter($allPositions, fn($p) => $p['election_type'] === 'general');
$clasPositionsList    = array_filter($allPositions, fn($p) => $p['election_type'] === 'clas');

// ── Section-specific queries ────────────────────────────────
$voters = null;
if ($section === 'voters') {
  $qStr = '%' . trim($_GET['q'] ?? '') . '%';
  $sfilt = $_GET['status_filter'] ?? 'all';
  if ($sfilt !== 'all') {
    $sv = $pdo->prepare("SELECT id,student_id,first_name,last_name,email,birthdate,has_voted,has_voted_clas,approval_status,created_at FROM students WHERE approval_status=:st AND (student_id LIKE :q OR CONCAT(first_name,' ',last_name) LIKE :q2) ORDER BY (approval_status='Pending') DESC,id DESC");
    $sv->execute([':st' => $sfilt, ':q' => $qStr, ':q2' => $qStr]);
  } else {
    $sv = $pdo->prepare("SELECT id,student_id,first_name,last_name,email,birthdate,has_voted,has_voted_clas,approval_status,created_at FROM students WHERE student_id LIKE :q OR CONCAT(first_name,' ',last_name) LIKE :q2 ORDER BY (approval_status='Pending') DESC,id DESC");
    $sv->execute([':q' => $qStr, ':q2' => $qStr]);
  }
  $voters = $sv->fetchAll();
}

$candidates = null;
if ($section === 'candidates') {
  $q = '%' . trim($_GET['q'] ?? '') . '%';
  $etype = $_GET['etype'] ?? 'all';
  // show_inactive=1 means show ALL (active + inactive); default shows only active
  $showInactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';
  $activeFilter = $showInactive ? '' : ' AND c.is_active=1';
  if ($etype !== 'all') {
    $sc = $pdo->prepare("SELECT c.*,p.name AS position_name,p.election_type FROM candidates c LEFT JOIN positions p ON p.id=c.position_id WHERE (c.full_name LIKE :q OR p.name LIKE :q2) AND p.election_type=:et{$activeFilter} ORDER BY c.is_active DESC,p.sort_order,c.id");
    $sc->execute([':q' => $q, ':q2' => $q, ':et' => $etype]);
  } else {
    $sc = $pdo->prepare("SELECT c.*,p.name AS position_name,p.election_type FROM candidates c LEFT JOIN positions p ON p.id=c.position_id WHERE (c.full_name LIKE :q OR p.name LIKE :q2){$activeFilter} ORDER BY c.is_active DESC,p.election_type,p.sort_order,c.id");
    $sc->execute([':q' => $q, ':q2' => $q]);
  }
  $candidates = $sc->fetchAll();
}

$lr_positions = [];
$lr_last_voted = '—';
if ($section === 'live-results') {
  $positions = $pdo->query("SELECT * FROM positions WHERE is_active=1 AND election_type='general' ORDER BY sort_order")->fetchAll();
  foreach ($positions as $pos) {
    $cs = $pdo->prepare("SELECT c.id,c.full_name,c.motto,c.vote_count,ROUND(c.vote_count/NULLIF((SELECT SUM(vote_count) FROM candidates WHERE position_id=:pid2 AND is_active=1),0)*100,1) AS pct FROM candidates c WHERE c.position_id=:pid AND c.is_active=1 ORDER BY c.vote_count DESC");
    $cs->execute([':pid' => $pos['id'], ':pid2' => $pos['id']]);
    $lr_positions[] = ['position' => $pos['name'], 'candidates' => $cs->fetchAll()];
  }
  $lastVote = $pdo->query("SELECT MAX(voted_at) FROM votes")->fetchColumn();
  if ($lastVote) $lr_last_voted = date('M d, Y · h:i:s A', strtotime($lastVote));
  $refresh_interval = 10;
}

$clas_lr_positions = [];
$clas_lr_last_voted = '—';
if ($section === 'clas-election') {
  $cpositions = $pdo->query("SELECT * FROM positions WHERE is_active=1 AND election_type='clas' ORDER BY sort_order")->fetchAll();
  foreach ($cpositions as $pos) {
    $cs = $pdo->prepare("SELECT c.id,c.full_name,c.motto,c.vote_count,ROUND(c.vote_count/NULLIF((SELECT SUM(vote_count) FROM candidates WHERE position_id=:pid2 AND is_active=1),0)*100,1) AS pct FROM candidates c WHERE c.position_id=:pid AND c.is_active=1 ORDER BY c.vote_count DESC");
    $cs->execute([':pid' => $pos['id'], ':pid2' => $pos['id']]);
    $clas_lr_positions[] = ['position' => $pos['name'], 'candidates' => $cs->fetchAll()];
  }
  $lastClas = $pdo->query("SELECT MAX(voted_at) FROM clas_votes")->fetchColumn();
  if ($lastClas) $clas_lr_last_voted = date('M d, Y · h:i:s A', strtotime($lastClas));
}

$election_history = [];
if ($section === 'election-history') {
  $election_history = $pdo->query("SELECT * FROM election_history ORDER BY archived_at DESC")->fetchAll();
}

$audit_rows = [];
if ($section === 'audit-log') {
  $q = '%' . trim($_GET['q'] ?? '') . '%';
  $cat = $_GET['cat'] ?? 'all';
  if ($cat !== 'all') {
    $s = $pdo->prepare("SELECT * FROM audit_log WHERE category=:c AND (action LIKE :q OR details LIKE :q2) ORDER BY created_at DESC LIMIT 500");
    $s->execute([':c' => $cat, ':q' => $q, ':q2' => $q]);
  } else {
    $s = $pdo->prepare("SELECT * FROM audit_log WHERE action LIKE :q OR details LIKE :q2 ORDER BY created_at DESC LIMIT 500");
    $s->execute([':q' => $q, ':q2' => $q]);
  }
  $audit_rows = $s->fetchAll();
  if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Timestamp', 'Category', 'Action', 'Details']);
    foreach ($audit_rows as $i => $row) fputcsv($out, [$i + 1, $row['created_at'], $row['category'], $row['action'], $row['details']]);
    fclose($out);
    exit;
  }
}

$feedback_rows = [];
$fb_total = $fb_unread = $fb_read = 0;
$fb_filter = $_GET['fb'] ?? 'all';
$reply_target = null;
if ($section === 'feedback') {
  $all_fb = $pdo->query("SELECT * FROM feedback ORDER BY created_at DESC")->fetchAll();
  $fb_total = count($all_fb);
  $fb_unread = count(array_filter($all_fb, fn($f) => !$f['is_read']));
  $fb_read = $fb_total - $fb_unread;
  $feedback_rows = match ($fb_filter) {
    'unread' => array_filter($all_fb, fn($f) => !$f['is_read']),
    'read' => array_filter($all_fb, fn($f) => $f['is_read']),
    default => $all_fb
  };
  if (isset($_GET['reply'])) {
    $r = $pdo->prepare("SELECT * FROM feedback WHERE id=:id");
    $r->execute([':id' => (int)$_GET['reply']]);
    $reply_target = $r->fetch() ?: null;
  }
}

// ── Countdown info ──────────────────────────────────────────
function buildTimeInfo(array $el): array
{
  $timeInfo = '';
  $autoStatus = '';
  if ($el['start_dt'] || $el['end_dt']) {
    $now = new DateTime();
    $start = $el['start_dt'] ? new DateTime($el['start_dt']) : null;
    $end = $el['end_dt'] ? new DateTime($el['end_dt']) : null;
    if ($start && $end) {
      if ($now < $start) {
        $diff = $now->diff($start);
        $timeInfo = 'Starts in <strong>' . $diff->days . 'd ' . $diff->h . 'h ' . $diff->i . 'm</strong>';
        $autoStatus = 'not-started';
      } elseif ($now >= $start && $now < $end) {
        $diff = $now->diff($end);
        $timeInfo = 'Ends in <strong>' . $diff->days . 'd ' . $diff->h . 'h ' . $diff->i . 'm</strong>';
        $autoStatus = 'ongoing';
      } else {
        $nowTs = time();
        $endTs = $end->getTimestamp();
        $secsElapsed = $nowTs - $endTs;
        if ($secsElapsed < 3600) {
          $secsLeft = 3600 - $secsElapsed;
          $mLeft = floor($secsLeft / 60);
          $sLeft = $secsLeft % 60;
          $timeInfo = 'Voting has <strong>ended</strong>. &nbsp;🕐 Student results visible for <strong>' . $mLeft . 'm ' . $sLeft . 's</strong> more, then sent to admin.';
        } else {
          $timeInfo = 'Voting has <strong>ended</strong>. Results have been <strong>archived</strong> and are no longer visible to students.';
        }
        $autoStatus = 'ended';
      }
    }
  }
  return [$timeInfo, $autoStatus];
}
[$timeInfo, $autoStatus] = buildTimeInfo($election);
[$clasTimeInfo, $clasAutoStatus] = buildTimeInfo($clasElection);

$adminName = h($_SESSION['admin_name'] ?? 'Administrator');
$adminUser = h($_SESSION['admin_username'] ?? 'admin');
$nameParts = explode(' ', $_SESSION['admin_name'] ?? 'A A');
$initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1] ?? 'A', 0, 1));
$titles = ['overview' => 'Dashboard', 'voters' => 'Voters', 'candidates' => 'Candidates', 'election-control' => 'Election Control', 'clas-election' => 'CLAS Election', 'live-results' => 'Live Results', 'election-history' => 'Election History', 'audit-log' => 'Audit Log', 'feedback' => 'Feedback', 'account' => 'My Account'];
$pageTitle = $titles[$section] ?? 'Admin Panel';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <link rel="shortcut icon" type="image/x-icon" href="data:image/x-icon;base64,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">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>SuffraTech · <?= h($pageTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <script>
    if (localStorage.getItem('suffraDark') === '1') {
      document.documentElement.classList.add('dark-mode-early');
    }
  </script>
  <style>
    :root {
      --sidebar-w: 272px;
      --accent: #10b981;
      --accent-dark: #059669;
      --accent-light: #34d399;
      --accent-glow: rgba(16, 185, 129, .2);
      --sidebar-bg: #09090f;
      --sidebar-border: rgba(255, 255, 255, .07);
      --topbar-h: 64px;
      --font-body: 'Plus Jakarta Sans', sans-serif;
      --font-display: 'Space Grotesk', sans-serif;
      --clas: #6366f1;
      --clas-dark: #4f46e5;
      --surface: #ffffff;
      --surface-2: #f8fafc;
      --border: #e8ecf2;
      --text: #0f172a;
      --text-muted: #94a3b8;
      --radius-sm: 8px;
      --radius: 12px;
      --radius-lg: 16px;
      --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06), 0 1px 2px rgba(0, 0, 0, .04);
      --shadow: 0 4px 16px rgba(0, 0, 0, .08), 0 1px 3px rgba(0, 0, 0, .04);
      --shadow-lg: 0 12px 36px rgba(0, 0, 0, .12), 0 4px 12px rgba(0, 0, 0, .06);
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    body {
      font-family: var(--font-body);
      background: #eef2f8;
      background-image: radial-gradient(ellipse at 80% 0%, rgba(16, 185, 129, .07) 0%, transparent 50%),
        radial-gradient(ellipse at 20% 100%, rgba(99, 102, 241, .06) 0%, transparent 50%);
      color: var(--text);
      font-size: 14px;
      overflow-x: hidden;
      -webkit-font-smoothing: antialiased;
    }

    ::-webkit-scrollbar {
      width: 5px;
      height: 5px;
    }

    ::-webkit-scrollbar-track {
      background: transparent;
    }

    ::-webkit-scrollbar-thumb {
      background: rgba(0, 0, 0, .12);
      border-radius: 10px;
    }

    .st-sidebar {
      width: var(--sidebar-w);
      background: var(--sidebar-bg);
      background-image: linear-gradient(180deg, #09090f 0%, #0d1018 100%);
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      z-index: 1040;
      display: flex;
      flex-direction: column;
      overflow-y: hidden;
      box-shadow: 4px 0 24px rgba(0, 0, 0, .18);
    }

    .st-sidebar .sb-brand {
      flex-shrink: 0;
    }

    .st-sidebar .sb-nav-scroll {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
    }

    .st-sidebar .sb-nav-scroll::-webkit-scrollbar {
      width: 3px;
    }

    .st-sidebar .sb-nav-scroll::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, .06);
      border-radius: 4px;
    }



    .sb-brand {
      padding: 20px 22px 16px;
      border-bottom: 1px solid var(--sidebar-border);
    }

    .sb-logo-img-wrap {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .sb-logo-img {
      width: 48px;
      height: 48px;
      border-radius: 0;
      object-fit: contain;
      background: transparent;
      flex-shrink: 0;
      box-shadow: none;
      mix-blend-mode: screen;
      filter: drop-shadow(0 0 8px rgba(74, 222, 128, .5));
    }

    .sb-logo-text {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 800;
      letter-spacing: -.5px;
      line-height: 1;
    }

    .sb-logo-suffra {
      color: #fff;
    }

    .sb-logo-tech {
      color: var(--accent);
    }

    .sb-badge {
      display: inline-block;
      margin-top: 5px;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, .4);
      background: rgba(255, 255, 255, .07);
      border: 1px solid rgba(255, 255, 255, .1);
      padding: 3px 10px;
      border-radius: 20px;
    }

    .sb-section-label {
      font-size: 9px;
      font-weight: 800;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, .18);
      padding: 20px 22px 6px;
    }

    .sb-nav-link {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 14px;
      margin: 1px 10px;
      border-radius: 10px;
      color: rgba(255, 255, 255, .4);
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 500;
      transition: all .18s;
      position: relative;
    }

    .sb-nav-link:hover {
      background: rgba(255, 255, 255, .06);
      color: rgba(255, 255, 255, .82);
      transform: translateX(1px);
    }

    .sb-nav-link.active {
      background: linear-gradient(90deg, rgba(16, 185, 129, .2), rgba(16, 185, 129, .05));
      color: #34d399;
      font-weight: 600;
      border: 1px solid rgba(16, 185, 129, .16);
    }

    .sb-nav-link.active::before {
      content: '';
      position: absolute;
      left: -2px;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 20px;
      background: var(--accent);
      border-radius: 0 3px 3px 0;
      box-shadow: 0 0 8px var(--accent);
    }

    .sb-nav-link.clas-link.active {
      background: linear-gradient(90deg, rgba(99, 102, 241, .2), rgba(99, 102, 241, .05));
      color: #a5b4fc;
      border: 1px solid rgba(99, 102, 241, .16);
    }

    .sb-nav-link.clas-link.active::before {
      background: var(--clas);
      box-shadow: 0 0 8px var(--clas);
    }

    .sb-nav-link.hist-link.active {
      background: linear-gradient(90deg, rgba(245, 158, 11, .2), rgba(245, 158, 11, .05));
      color: #fcd34d;
      border: 1px solid rgba(245, 158, 11, .16);
    }

    .sb-nav-link.hist-link.active::before {
      background: #f59e0b;
      box-shadow: 0 0 8px #f59e0b;
    }

    .sb-icon {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      background: rgba(255, 255, 255, .04);
      flex-shrink: 0;
      transition: all .18s;
    }

    .sb-nav-link.active .sb-icon {
      background: rgba(16, 185, 129, .18);
      color: #34d399;
    }

    .sb-nav-link.clas-link.active .sb-icon {
      background: rgba(99, 102, 241, .18);
      color: #a5b4fc;
    }

    .sb-nav-link.hist-link.active .sb-icon {
      background: rgba(245, 158, 11, .18);
      color: #fcd34d;
    }

    .sb-nav-link:hover .sb-icon {
      background: rgba(255, 255, 255, .08);
      color: rgba(255, 255, 255, .8);
    }

    .sb-spacer {
      flex: 1;
      min-height: 24px;
    }

    .sb-footer {
      padding: 14px 12px 18px;
      border-top: 1px solid var(--sidebar-border);
    }

    .sb-user-card {
      display: flex;
      align-items: center;
      gap: 10px;
      background: rgba(255, 255, 255, .04);
      border: 1px solid rgba(255, 255, 255, .07);
      border-radius: 12px;
      padding: 10px 12px;
      margin-bottom: 6px;
      transition: background .15s;
    }

    .sb-user-card:hover {
      background: rgba(255, 255, 255, .07);
    }

    .sb-avatar {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--accent-dark), var(--accent-light));
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: var(--font-display);
      font-size: 13px;
      font-weight: 700;
      color: #fff;
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(16, 185, 129, .3);
    }

    .sb-user-name {
      font-size: 12.5px;
      font-weight: 600;
      color: rgba(255, 255, 255, .85);
    }

    .sb-user-role {
      font-size: 10px;
      color: rgba(255, 255, 255, .25);
      text-transform: uppercase;
      letter-spacing: .8px;
    }

    .sb-logout {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 9px;
      color: rgba(255, 255, 255, .28);
      text-decoration: none;
      font-size: 13px;
      font-weight: 500;
      transition: all .15s;
    }

    .sb-logout:hover {
      background: rgba(239, 68, 68, .12);
      color: #fca5a5;
    }

    .st-main {
      margin-left: var(--sidebar-w);
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    .st-topbar {
      height: var(--topbar-h);
      background: rgba(255, 255, 255, .85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(0, 0, 0, .07);
      padding: 0 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 1px 0 rgba(0, 0, 0, .05), 0 4px 20px rgba(0, 0, 0, .04);
    }

    .topbar-title {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -.3px;
    }

    .topbar-breadcrumb {
      font-size: 12.5px;
      color: #94a3b8;
      font-weight: 600;
      margin-top: 2px;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 13px;
      border-radius: 20px;
      font-size: 11.5px;
      font-weight: 600;
    }

    .status-pill.ongoing {
      background: #d1fae5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }

    .status-pill.ended {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    .status-pill.ns {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
    }

    .status-pill.clas-pill {
      background: #ede9fe;
      color: #4f46e5;
      border: 1px solid #c4b5fd;
    }

    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
    }

    .status-pill.ongoing .status-dot {
      background: #10b981;
      animation: pulse-dot 1.6s infinite;
    }

    .status-pill.ended .status-dot {
      background: #ef4444;
    }

    .status-pill.ns .status-dot {
      background: #94a3b8;
    }

    @keyframes pulse-dot {

      0%,
      100% {
        box-shadow: 0 0 0 2px rgba(16, 185, 129, .25);
      }

      50% {
        box-shadow: 0 0 0 5px rgba(16, 185, 129, .06);
      }
    }

    .live-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: linear-gradient(135deg, #fef3c7, #fff7ed);
      color: #92400e;
      border: 1px solid #fde68a;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .5px;
    }

    .live-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #f59e0b;
      animation: livepulse 1.4s infinite;
    }

    @keyframes livepulse {

      0%,
      100% {
        box-shadow: 0 0 0 2px rgba(245, 158, 11, .3);
      }

      50% {
        box-shadow: 0 0 0 5px rgba(245, 158, 11, .08);
      }
    }

    .st-content {
      padding: 28px 32px 60px;
      flex: 1;
    }

    /* ── Stat cards ── */
    .stat-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 22px 24px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: var(--shadow-sm);
      transition: transform .22s cubic-bezier(.34, 1.56, .64, 1), box-shadow .22s;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    }

    .stat-card:nth-child(1)::before {
      background: linear-gradient(90deg, #10b981, #34d399);
    }

    .stat-card:nth-child(2)::before {
      background: linear-gradient(90deg, #3b82f6, #60a5fa);
    }

    .stat-card:nth-child(3)::before {
      background: linear-gradient(90deg, #6366f1, #818cf8);
    }

    .stat-card:nth-child(4)::before {
      background: linear-gradient(90deg, #8b5cf6, #a78bfa);
    }

    .stat-card::after {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 90px;
      height: 90px;
      border-radius: 50%;
      opacity: .06;
      transform: translate(20px, -20px);
    }

    .stat-card:nth-child(1)::after {
      background: #10b981;
    }

    .stat-card:nth-child(2)::after {
      background: #3b82f6;
    }

    .stat-card:nth-child(3)::after {
      background: #6366f1;
    }

    .stat-card:nth-child(4)::after {
      background: #8b5cf6;
    }

    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }

    /* ── Live Results Stat Cards ── */
    .lr-stat-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-top: 3px solid #3b82f6;
      border-radius: var(--radius-lg);
      padding: 22px 24px;
      text-align: center;
      box-shadow: var(--shadow-sm);
      transition: transform .22s, box-shadow .22s;
    }

    .lr-stat-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-lg);
    }

    .lr-stat-num {
      font-family: var(--font-display);
      font-size: 34px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -1.5px;
      line-height: 1;
      transition: all .4s ease;
    }

    .lr-stat-lbl {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-muted);
      margin-top: 6px;
      text-transform: uppercase;
      letter-spacing: .8px;
    }

    .stat-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 21px;
      flex-shrink: 0;
    }

    .si-green {
      background: linear-gradient(135deg, #d1fae5, #a7f3d0);
      color: #059669;
    }

    .si-blue {
      background: linear-gradient(135deg, #dbeafe, #bfdbfe);
      color: #1d4ed8;
    }

    .si-orange {
      background: linear-gradient(135deg, #ffedd5, #fed7aa);
      color: #c2410c;
    }

    .si-purple {
      background: linear-gradient(135deg, #ede9fe, #ddd6fe);
      color: #7c3aed;
    }

    .si-indigo {
      background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
      color: #4338ca;
    }

    .si-amber {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      color: #92400e;
    }

    .stat-num {
      font-family: var(--font-display);
      font-size: 30px;
      font-weight: 700;
      line-height: 1;
      color: #0f172a;
      letter-spacing: -1px;
    }

    .stat-lbl {
      font-size: 12px;
      color: var(--text-muted);
      font-weight: 500;
      margin-top: 4px;
    }

    /* ── Cards ── */
    .st-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .st-card-header {
      padding: 16px 22px;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: linear-gradient(180deg, #fafbfc, #fff);
    }

    .st-card-title {
      font-family: var(--font-display);
      font-size: 14.5px;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .st-card-title i {
      color: var(--accent);
    }

    .st-card-title i.clas-icon {
      color: var(--clas);
    }

    .st-card-title i.hist-icon {
      color: #f59e0b;
    }

    .st-card-body {
      padding: 22px;
    }

    .el-strip {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 18px 24px;
      display: flex;
      align-items: center;
      gap: 28px;
      flex-wrap: wrap;
      box-shadow: var(--shadow-sm);
    }

    .el-divider {
      width: 1px;
      height: 36px;
      background: var(--border);
    }

    .el-lbl {
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--text-muted);
    }

    .el-val {
      font-size: 14px;
      font-weight: 600;
      color: #0f172a;
      margin-top: 3px;
    }

    /* ── Badges ── */
    .st-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600;
    }

    .stb-green {
      background: #dcfce7;
      color: #166534;
    }

    .stb-red {
      background: #fee2e2;
      color: #991b1b;
    }

    .stb-yellow {
      background: #fef3c7;
      color: #92400e;
    }

    .stb-blue {
      background: #dbeafe;
      color: #1e40af;
    }

    .stb-gray {
      background: #f1f5f9;
      color: #475569;
    }

    .stb-indigo {
      background: #e0e7ff;
      color: #4338ca;
    }

    .stb-amber {
      background: #fef3c7;
      color: #92400e;
    }

    /* ── Buttons ── */
    .btn-st-primary {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #fff;
      border: none;
      padding: 9px 18px;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 600;
      font-family: var(--font-body);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: all .18s;
      box-shadow: 0 2px 12px rgba(16, 185, 129, .35);
      text-decoration: none;
    }

    .btn-st-primary:hover {
      filter: brightness(1.08);
      transform: translateY(-1px);
      box-shadow: 0 5px 20px rgba(16, 185, 129, .4);
      color: #fff;
    }

    .btn-clas-primary {
      background: linear-gradient(135deg, #6366f1, #4f46e5);
      color: #fff;
      border: none;
      padding: 9px 18px;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 600;
      font-family: var(--font-body);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: all .18s;
      box-shadow: 0 2px 12px rgba(99, 102, 241, .35);
      text-decoration: none;
    }

    .btn-clas-primary:hover {
      filter: brightness(1.08);
      transform: translateY(-1px);
      color: #fff;
    }

    .btn-st-muted {
      background: #f8fafc;
      color: #475569;
      border: 1.5px solid #e2e8f0;
      padding: 8px 15px;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 600;
      font-family: var(--font-body);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: all .15s;
      text-decoration: none;
    }

    .btn-st-muted:hover {
      background: #eef2f8;
      color: #1e293b;
      border-color: #cbd5e1;
    }

    .btn-st-danger {
      background: #fee2e2;
      color: #991b1b;
      border: none;
      padding: 8px 15px;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 600;
      font-family: var(--font-body);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: all .15s;
    }

    .btn-st-danger:hover {
      background: #fecaca;
    }

    .btn-st-approve {
      background: #dcfce7;
      color: #166534;
      border: none;
      padding: 7px 13px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      font-family: var(--font-body);
      display: inline-flex;
      align-items: center;
      gap: 5px;
      cursor: pointer;
      transition: all .15s;
    }

    .btn-st-approve:hover {
      background: #bbf7d0;
    }

    .btn-st-reject {
      background: #fee2e2;
      color: #991b1b;
      border: none;
      padding: 7px 13px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      font-family: var(--font-body);
      display: inline-flex;
      align-items: center;
      gap: 5px;
      cursor: pointer;
      transition: all .15s;
    }

    .btn-st-reject:hover {
      background: #fecaca;
    }

    .btn-sm-icon {
      padding: 6px 10px;
      font-size: 12px;
    }

    /* ── Table ── */
    .st-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13.5px;
    }

    .st-table thead tr {
      border-bottom: 2px solid #f1f5f9;
    }

    .st-table th {
      padding: 11px 16px;
      text-align: left;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: var(--text-muted);
      white-space: nowrap;
      background: #fafbfc;
    }

    .st-table td {
      padding: 13px 16px;
      border-bottom: 1px solid #f8fafc;
      vertical-align: middle;
    }

    .st-table tbody tr:last-child td {
      border-bottom: none;
    }

    .st-table tbody tr {
      transition: background .1s;
    }

    .st-table tbody tr:hover {
      background: #f8fbff;
    }

    /* ── Form inputs ── */
    .st-input {
      width: 100%;
      padding: 9px 13px;
      border: 1.5px solid #e2e8f0;
      border-radius: 9px;
      font-size: 13.5px;
      font-family: var(--font-body);
      color: #0f172a;
      background: #f8fafc;
      outline: none;
      transition: border-color .15s, box-shadow .15s, background .15s;
    }

    .st-input:focus {
      border-color: var(--accent);
      background: #fff;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, .12);
    }

    .st-input.clas-input:focus {
      border-color: var(--clas);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, .12);
    }

    .st-label {
      font-size: 11.5px;
      font-weight: 600;
      color: #475569;
      margin-bottom: 5px;
      display: block;
    }

    .req {
      color: #ef4444;
    }

    /* ── Alerts / banners ── */
    .pending-alert {
      background: linear-gradient(90deg, #fef3c7, #fff7ed);
      border: 1px solid #fde68a;
      border-radius: 10px;
      padding: 12px 18px;
      font-size: 13px;
      font-weight: 600;
      color: #92400e;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .auto-banner {
      border-radius: 12px;
      padding: 16px 22px;
      display: flex;
      align-items: center;
      gap: 14px;
      font-size: 13.5px;
      font-weight: 500;
      margin-bottom: 18px;
      flex-wrap: wrap;
      justify-content: space-between;
    }

    .auto-banner.ns {
      background: #f8fafc;
      color: #475569;
      border: 1px solid #e2e8f0;
    }

    .auto-banner.on {
      background: linear-gradient(90deg, #d1fae5, #ecfdf5);
      color: #065f46;
      border: 1px solid #a7f3d0;
    }

    .auto-banner.end {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    .auto-banner.clas-on {
      background: linear-gradient(90deg, #ede9fe, #f5f3ff);
      color: #4338ca;
      border: 1px solid #c4b5fd;
    }

    .auto-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .auto-banner.ns .auto-dot {
      background: #94a3b8;
    }

    .auto-banner.on .auto-dot {
      background: #10b981;
      animation: pulse-dot 1.6s infinite;
    }

    .auto-banner.end .auto-dot {
      background: #ef4444;
    }

    .auto-banner.clas-on .auto-dot {
      background: #6366f1;
      animation: pulse-dot 1.6s infinite;
    }

    .schedule-preview {
      display: flex;
      gap: 0;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 20px;
    }

    .sched-col {
      flex: 1;
      padding: 16px 20px;
    }

    .sched-col+.sched-col {
      border-left: 1px solid var(--border);
    }

    .sched-lbl {
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--text-muted);
      margin-bottom: 4px;
    }

    .sched-val {
      font-size: 14px;
      font-weight: 600;
      color: #0f172a;
    }

    .sched-col.active {
      background: #f0fdf4;
    }

    .sched-col.active .sched-lbl {
      color: #059669;
    }

    .sched-col.clas-active {
      background: #f5f3ff;
    }

    .sched-col.clas-active .sched-lbl {
      color: #6366f1;
    }

    /* ── Live Results ── */
    .lr-position-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 22px 24px;
      box-shadow: var(--shadow-sm);
    }

    .lr-pos-title {
      font-family: var(--font-display);
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .lr-pos-title::before {
      content: '';
      display: block;
      width: 4px;
      height: 18px;
      background: linear-gradient(180deg, var(--accent), var(--accent-dark));
      border-radius: 3px;
    }

    .lr-pos-title.clas-pos::before {
      background: linear-gradient(180deg, var(--clas), var(--clas-dark));
    }

    .lr-bar-wrap {
      background: #f1f5f9;
      border-radius: 6px;
      height: 9px;
      overflow: hidden;
      margin-top: 6px;
    }

    .lr-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #059669, #34d399);
      border-radius: 6px;
      transition: width .8s cubic-bezier(.4, 0, .2, 1);
    }

    .lr-bar-fill.clas-bar {
      background: linear-gradient(90deg, #4f46e5, #818cf8);
    }

    .lr-rank {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 800;
      flex-shrink: 0;
    }

    .rank-gold {
      background: #fef3c7;
      color: #92400e;
    }

    .rank-silver {
      background: #f1f5f9;
      color: #475569;
    }

    .rank-bronze {
      background: #ffedd5;
      color: #c2410c;
    }

    .lr-leading {
      background: #fef3c7;
      color: #92400e;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 20px;
    }

    .lr-winner-badge {
      background: linear-gradient(135deg, #f59e0b, #fbbf24);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 9px;
      border-radius: 20px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .refresh-bar {
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      border-radius: 10px;
      padding: 12px 18px;
      font-size: 12.5px;
      color: #065f46;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
    }

    .countdown-bar {
      height: 5px;
      background: #d1fae5;
      border-radius: 5px;
      margin-top: 8px;
      overflow: hidden;
    }

    .countdown-fill {
      height: 100%;
      background: var(--accent);
      border-radius: 5px;
      transition: width 1s linear, background 0.5s ease;
    }

    /* ── Audit log tags ── */
    .al-tag {
      padding: 2px 9px;
      border-radius: 20px;
      font-size: 10.5px;
      font-weight: 600;
    }

    .al-voters {
      background: #dbeafe;
      color: #1e40af;
    }

    .al-candidates {
      background: #fce7f3;
      color: #9d174d;
    }

    .al-election {
      background: #d1fae5;
      color: #065f46;
    }

    .al-student {
      background: #ede9fe;
      color: #4338ca;
    }

    .al-feedback {
      background: #ffedd5;
      color: #c2410c;
    }

    .al-admin {
      background: #f1f5f9;
      color: #475569;
    }

    .al-registration {
      background: #dcfce7;
      color: #166534;
    }

    /* ── Feedback cards ── */
    .fb-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 20px 22px;
      box-shadow: var(--shadow-sm);
      transition: box-shadow .15s, transform .15s;
    }

    .fb-card:hover {
      box-shadow: var(--shadow);
      transform: translateY(-1px);
    }

    .fb-card.unread {
      border-left: 3px solid var(--accent);
    }

    .fb-stars {
      color: #f59e0b;
      font-size: 13px;
      letter-spacing: 1px;
    }

    .fb-reply-box {
      background: #f0fdf4;
      border: 1px solid #a7f3d0;
      border-radius: 9px;
      padding: 12px 16px;
      font-size: 13px;
      color: #065f46;
    }

    .st-empty {
      text-align: center;
      padding: 60px 24px;
      color: #94a3b8;
    }

    .st-empty-icon {
      font-size: 42px;
      opacity: .25;
      margin-bottom: 14px;
    }

    .st-empty-text {
      font-size: 14.5px;
      font-weight: 500;
    }

    /* ── Flash messages ── */
    .st-flash {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 13px 18px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 500;
      margin-bottom: 20px;
      animation: flashIn .3s ease;
    }

    @keyframes flashIn {
      from {
        opacity: 0;
        transform: translateY(-8px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .st-flash.ok {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
    }

    .st-flash.err {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    /* ── Account ── */
    .acc-avatar {
      width: 80px;
      height: 80px;
      border-radius: 20px;
      background: linear-gradient(135deg, var(--accent-dark), var(--accent-light));
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: var(--font-display);
      font-size: 28px;
      font-weight: 700;
      color: #fff;
      margin: 0 auto 12px;
      box-shadow: 0 6px 24px rgba(16, 185, 129, .35);
    }

    /* ── Modals ── */
    .st-modal .modal-content {
      border-radius: 18px;
      border: none;
      box-shadow: 0 24px 72px rgba(0, 0, 0, .2), 0 4px 16px rgba(0, 0, 0, .08);
    }

    .st-modal .modal-header {
      border-bottom: 1px solid #f1f5f9;
      padding: 20px 26px;
      background: linear-gradient(180deg, #fafbfc, #fff);
    }

    .st-modal .modal-title {
      font-family: var(--font-display);
      font-size: 15px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .st-modal .modal-title i {
      color: var(--accent);
    }

    .st-modal .modal-body {
      padding: 26px;
    }

    .st-modal .modal-footer {
      border-top: 1px solid #f1f5f9;
      padding: 16px 26px;
    }

    /* ── Page headers ── */
    .ph-title {
      font-family: var(--font-display);
      font-size: 24px;
      font-weight: 700;
      color: #0f172a;
    }

    .ph-sub {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 3px;
    }

    .nav-badge {
      margin-left: auto;
      background: #ef4444;
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      padding: 1px 7px;
      border-radius: 20px;
    }

    .text-muted-sm {
      color: var(--text-muted);
      font-size: 12.5px;
    }

    .fw-600 {
      font-weight: 600;
    }

    .form-divider {
      height: 1px;
      background: #f1f5f9;
      margin: 20px 0;
    }

    .danger-zone-card {
      border-color: #fecaca !important;
    }

    .danger-zone-card .st-card-title {
      color: #dc2626;
    }

    .danger-zone-card .st-card-title i {
      color: #dc2626;
    }

    .table-responsive-st {
      overflow-x: auto;
    }

    .st-check-label {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      font-size: 13.5px;
      margin-top: 16px;
    }

    .st-check-label input {
      accent-color: var(--accent);
    }

    /* ── Election History ── */
    .hist-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 24px 26px;
      box-shadow: var(--shadow-sm);
      transition: box-shadow .2s, transform .2s;
    }

    .hist-card:hover {
      box-shadow: var(--shadow-lg);
      transform: translateY(-2px);
    }

    .hist-card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 16px;
      gap: 12px;
    }

    .hist-title {
      font-family: var(--font-display);
      font-size: 15px;
      font-weight: 700;
      color: #0f172a;
    }

    .hist-meta {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 3px;
    }

    .hist-type-badge {
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .5px;
      text-transform: uppercase;
    }

    .htype-general {
      background: #d1fae5;
      color: #065f46;
    }

    .htype-clas {
      background: #ede9fe;
      color: #4338ca;
    }

    .hist-stats {
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }

    .hist-stat {
      text-align: center;
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 18px;
    }

    .hist-stat-num {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 700;
      color: #0f172a;
    }

    .hist-stat-lbl {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .winner-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 14px;
      border-radius: 10px;
      margin-bottom: 6px;
      background: #f8fafc;
      border: 1px solid var(--border);
      transition: background .15s;
    }

    .winner-row:hover {
      background: #f0fdf4;
    }

    .winner-pos {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .6px;
      text-transform: uppercase;
      color: var(--text-muted);
    }

    .winner-name {
      font-size: 13.5px;
      font-weight: 700;
      color: #0f172a;
    }

    .winner-votes {
      font-size: 12px;
      color: #475569;
    }

    /* ── CLAS section ── */
    .clas-section-header {
      background: linear-gradient(135deg, #eef2ff, #f5f3ff);
      border: 1px solid #c7d2fe;
      border-radius: var(--radius-lg);
      padding: 18px 22px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .clas-section-icon {
      width: 46px;
      height: 46px;
      border-radius: 13px;
      background: linear-gradient(135deg, #6366f1, #4f46e5);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: #fff;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(99, 102, 241, .3);
    }

    .clas-header-title {
      font-family: var(--font-display);
      font-size: 16px;
      font-weight: 700;
      color: #3730a3;
    }

    .clas-header-sub {
      font-size: 12.5px;
      color: #6366f1;
      margin-top: 2px;
    }

    .clas-overview-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: #6366f1;
    }

    /* ── Position dropdown ── */
    .pos-optgroup-general {
      font-weight: 700;
      color: #059669;
    }

    .pos-optgroup-clas {
      font-weight: 700;
      color: #4f46e5;
    }

    .pos-hint {
      font-size: 11px;
      color: var(--text-muted);
      margin-top: 4px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* ── Candidate photo ── */
    .cand-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid var(--border);
      flex-shrink: 0;
    }

    .cand-avatar-placeholder {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: #94a3b8;
      flex-shrink: 0;
    }

    .photo-preview {
      width: 74px;
      height: 74px;
      border-radius: 50%;
      object-fit: cover;
      border: 3px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f1f5f9;
      font-size: 28px;
      color: #cbd5e1;
      overflow: hidden;
      flex-shrink: 0;
    }

    .photo-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .upload-btn-wrap {
      position: relative;
    }

    .upload-btn-wrap input[type=file] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
      width: 100%;
    }

    /* ── Dark mode toggle ── */
    .dm-toggle {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      border: 1px solid #e2e8f0;
      background: #f8fafc;
      color: #64748b;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      cursor: pointer;
      transition: background .3s ease, color .3s ease, border-color .3s ease, transform .2s ease;
      flex-shrink: 0;
      position: relative;
      overflow: hidden;
    }

    .dm-toggle:hover {
      background: #eef2f8;
      color: #334155;
      border-color: #cbd5e1;
    }

    .dm-toggle:active {
      transform: scale(0.88) rotate(15deg);
    }

    .dm-toggle i {
      transition: transform .45s cubic-bezier(.4, 0, .2, 1), opacity .2s ease;
    }

    .dm-toggle.spinning i {
      transform: rotate(360deg);
      opacity: 0.4;
    }

    /* Smooth full-page dark/light transition */
    body,
    body * {
      transition: background-color .35s ease, color .25s ease, border-color .25s ease !important;
    }

    /* but keep transforms/animations responsive */
    body *:active {
      transition: transform .1s ease !important;
    }

    @keyframes dmRipple {
      0% {
        transform: scale(0);
        opacity: .5;
      }

      100% {
        transform: scale(4);
        opacity: 0;
      }
    }

    .dm-toggle::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: currentColor;
      transform: scale(0);
      opacity: 0;
      pointer-events: none;
    }

    .dm-toggle.ripple::after {
      animation: dmRipple .5s ease-out forwards;
    }

    /* ══ DARK MODE ══════════════════════════════════════════════ */
    /* Applied instantly via inline script in <head> to prevent flash */
    html.dark-mode-early body {
      background: #0d1117 !important;
    }

    body.dark-mode {
      --surface: #161b22;
      --surface-2: #1c2128;
      --border: #30363d;
      --text: #c9d1d9;
      --text-muted: #8b949e;
      background: #0d1117;
      background-image: radial-gradient(ellipse at 80% 0%, rgba(16, 185, 129, .05) 0%, transparent 50%),
        radial-gradient(ellipse at 20% 100%, rgba(99, 102, 241, .04) 0%, transparent 50%);
      color: #c9d1d9;
    }

    body.dark-mode .st-topbar {
      background: rgba(22, 27, 34, .9);
      backdrop-filter: blur(12px);
      border-bottom-color: #30363d;
    }

    body.dark-mode .topbar-title {
      color: #e6edf3;
    }

    body.dark-mode .topbar-breadcrumb {
      color: #8b949e;
    }

    body.dark-mode .st-content {
      background: transparent;
    }

    body.dark-mode .stat-card,
    body.dark-mode .lr-stat-card,
    body.dark-mode .st-card,
    body.dark-mode .el-strip,
    body.dark-mode .hist-card,
    body.dark-mode .lr-position-card,
    body.dark-mode .fb-card,
    body.dark-mode .pending-alert {
      background: #161b22;
      border-color: #30363d;
      color: #c9d1d9;
    }

    body.dark-mode .st-card-header {
      border-bottom-color: #30363d;
      background: linear-gradient(180deg, #1a1f27, #161b22);
    }

    body.dark-mode .el-divider {
      background: #30363d;
    }

    body.dark-mode .stat-num,
    body.dark-mode .lr-stat-num,
    body.dark-mode .st-card-title,
    body.dark-mode .hist-title,
    body.dark-mode .winner-name,
    body.dark-mode .ph-title,
    body.dark-mode .el-val,
    body.dark-mode .sched-val,
    body.dark-mode .lr-pos-title,
    body.dark-mode .hist-stat-num,
    body.dark-mode .topbar-title {
      color: #e6edf3;
    }

    body.dark-mode .stat-lbl,
    body.dark-mode .lr-stat-lbl,
    body.dark-mode .ph-sub,
    body.dark-mode .hist-meta,
    body.dark-mode .el-lbl,
    body.dark-mode .sched-lbl,
    body.dark-mode .hist-stat-lbl,
    body.dark-mode .winner-pos,
    body.dark-mode .winner-votes,
    body.dark-mode .st-label,
    body.dark-mode .pos-hint,
    body.dark-mode .st-empty {
      color: #8b949e;
    }

    body.dark-mode .st-table thead tr {
      border-bottom-color: #30363d;
    }

    body.dark-mode .st-table th {
      background: #1c2128;
      color: #8b949e;
      border-color: #30363d;
    }

    body.dark-mode .st-table td {
      border-bottom-color: #21262d;
      color: #c9d1d9;
    }

    body.dark-mode .st-table tbody tr:hover {
      background: #1c2128;
    }

    body.dark-mode .st-input,
    body.dark-mode .st-select {
      background: #1c2128;
      border-color: #30363d;
      color: #c9d1d9;
    }

    body.dark-mode .st-input:focus,
    body.dark-mode .st-select:focus {
      border-color: var(--accent);
      background: #1c2128;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, .15);
    }

    body.dark-mode .st-input::placeholder {
      color: #6e7681;
    }

    body.dark-mode .btn-st-muted {
      background: #21262d;
      color: #c9d1d9;
      border-color: #30363d;
    }

    body.dark-mode .btn-st-muted:hover {
      background: #30363d;
      color: #e6edf3;
    }

    body.dark-mode .btn-st-danger {
      background: #2d1212;
      color: #f85149;
    }

    body.dark-mode .btn-st-danger:hover {
      background: #3d1a1a;
    }

    body.dark-mode .btn-st-approve {
      background: #0d2d1a;
      color: #3fb950;
    }

    body.dark-mode .btn-st-approve:hover {
      background: #196c2e;
    }

    body.dark-mode .btn-st-reject {
      background: #2d1212;
      color: #f85149;
    }

    body.dark-mode .btn-st-reject:hover {
      background: #3d1a1a;
    }

    body.dark-mode .status-pill.ns {
      background: #1c2128;
      color: #8b949e;
      border-color: #30363d;
    }

    body.dark-mode .status-pill.ongoing {
      background: #0d2d1a;
      color: #3fb950;
      border-color: #196c2e;
    }

    body.dark-mode .status-pill.ended {
      background: #2d1212;
      color: #f85149;
      border-color: #6e1d1d;
    }

    body.dark-mode .status-pill.clas-pill {
      background: #1a1a3a;
      color: #818cf8;
      border-color: #3730a3;
    }

    body.dark-mode .stb-green {
      background: #0d2d1a;
      color: #3fb950;
    }

    body.dark-mode .stb-red {
      background: #2d1212;
      color: #f85149;
    }

    body.dark-mode .stb-yellow,
    body.dark-mode .stb-amber {
      background: #2d2000;
      color: #d29922;
    }

    body.dark-mode .stb-blue {
      background: #0c1a3a;
      color: #58a6ff;
    }

    body.dark-mode .stb-gray {
      background: #21262d;
      color: #8b949e;
    }

    body.dark-mode .stb-indigo {
      background: #1a1a3a;
      color: #818cf8;
    }

    body.dark-mode .auto-banner.ns {
      background: #1c2128;
      color: #8b949e;
      border-color: #30363d;
    }

    body.dark-mode .auto-banner.on {
      background: #0d2d1a;
      color: #3fb950;
      border-color: #196c2e;
    }

    body.dark-mode .auto-banner.end {
      background: #2d1212;
      color: #f85149;
      border-color: #6e1d1d;
    }

    body.dark-mode .auto-banner.clas-on {
      background: #1a1a3a;
      color: #818cf8;
      border-color: #3730a3;
    }

    body.dark-mode .schedule-preview {
      border-color: #30363d;
      background: #161b22;
    }

    body.dark-mode .sched-col {
      background: #161b22;
    }

    body.dark-mode .sched-col+.sched-col {
      border-left-color: #30363d;
    }

    body.dark-mode .sched-lbl {
      color: #8b949e;
    }

    body.dark-mode .sched-val {
      color: #e6edf3;
    }

    body.dark-mode .sched-col.active {
      background: #0d2d1a;
    }

    body.dark-mode .sched-col.active .sched-lbl {
      color: #3fb950;
    }

    body.dark-mode .sched-col.clas-active {
      background: #1a1a3a;
    }

    body.dark-mode .sched-col.clas-active .sched-lbl {
      color: #818cf8;
    }

    body.dark-mode .clas-section-header {
      background: linear-gradient(135deg, #1a1a3a, #1e1b4b);
      border-color: #3730a3;
    }

    body.dark-mode .clas-header-title {
      color: #a5b4fc;
    }

    body.dark-mode .clas-header-sub {
      color: #818cf8;
    }

    body.dark-mode .clas-overview-label {
      color: #818cf8;
    }

    body.dark-mode .danger-zone-card {
      border-color: #6e1d1d !important;
      background: #1a0a0a;
    }

    body.dark-mode .danger-zone-card .st-card-title {
      color: #f85149;
    }

    body.dark-mode .danger-zone-card .st-card-title i {
      color: #f85149;
    }

    body.dark-mode .form-divider {
      background: #30363d;
    }

    body.dark-mode .st-check-label {
      color: #c9d1d9;
    }

    body.dark-mode .st-input.clas-input:focus {
      border-color: #6366f1;
      box-shadow: 0 0 0 3px rgba(99, 102, 241, .2);
      background: #1c2128;
    }

    body.dark-mode .lr-bar-wrap {
      background: #21262d;
    }

    body.dark-mode .refresh-bar {
      background: #0d2d1a;
      border-color: #196c2e;
      color: #3fb950;
    }

    body.dark-mode .rank-gold {
      background: #2d2000;
      color: #d29922;
    }

    body.dark-mode .rank-silver {
      background: #21262d;
      color: #8b949e;
    }

    body.dark-mode .rank-bronze {
      background: #2d1a00;
      color: #e08a45;
    }

    body.dark-mode .lr-leading {
      background: #2d2000;
      color: #d29922;
    }

    body.dark-mode .al-voters {
      background: #0c1a3a;
      color: #58a6ff;
    }

    body.dark-mode .al-candidates {
      background: #2d0d2a;
      color: #e879a8;
    }

    body.dark-mode .al-election {
      background: #0d2d1a;
      color: #3fb950;
    }

    body.dark-mode .al-student {
      background: #1a1a3a;
      color: #818cf8;
    }

    body.dark-mode .al-feedback {
      background: #2d1a00;
      color: #e08a45;
    }

    body.dark-mode .al-admin {
      background: #21262d;
      color: #8b949e;
    }

    body.dark-mode .al-registration {
      background: #052e16;
      color: #4ade80;
    }

    body.dark-mode .fb-reply-box {
      background: #0d2d1a;
      border-color: #196c2e;
      color: #3fb950;
    }

    body.dark-mode .winner-row {
      background: #1c2128;
      border-color: #30363d;
    }

    body.dark-mode .winner-row:hover {
      background: #21262d;
    }

    body.dark-mode .hist-stat {
      background: #1c2128;
      border-color: #30363d;
    }

    body.dark-mode .htype-general {
      background: #0d2d1a;
      color: #3fb950;
    }

    body.dark-mode .htype-clas {
      background: #1a1a3a;
      color: #818cf8;
    }

    body.dark-mode .st-flash.ok {
      background: #0d2d1a;
      border-color: #196c2e;
      color: #3fb950;
    }

    body.dark-mode .st-flash.err {
      background: #2d1212;
      border-color: #6e1d1d;
      color: #f85149;
    }

    body.dark-mode .modal-content {
      background: #161b22 !important;
      color: #c9d1d9 !important;
    }

    body.dark-mode .st-modal .modal-header,
    body.dark-mode .st-modal .modal-footer {
      border-color: #30363d;
    }

    body.dark-mode .st-modal .modal-header {
      background: linear-gradient(180deg, #1c2128, #161b22);
    }

    body.dark-mode .st-modal .modal-title {
      color: #e6edf3;
    }

    body.dark-mode .modal-body label,
    body.dark-mode .modal-body p,
    body.dark-mode .modal-body small,
    body.dark-mode .modal-body span {
      color: #c9d1d9;
    }

    body.dark-mode .cand-avatar-placeholder {
      background: #30363d;
      color: #8b949e;
    }

    body.dark-mode .photo-preview {
      background: #21262d;
      border-color: #30363d;
      color: #6e7681;
    }

    body.dark-mode .pending-alert {
      background: #2d2000;
      border-color: #4d3800;
      color: #d29922;
    }

    body.dark-mode [style*="background:#fff"],
    body.dark-mode [style*="background: #fff"] {
      background: #161b22 !important;
      border-color: #30363d !important;
      color: #c9d1d9 !important;
    }

    body.dark-mode [style*="color:#0f172a"],
    body.dark-mode [style*="color: #0f172a"] {
      color: #e6edf3 !important;
    }

    body.dark-mode .dm-toggle {
      background: #1c2128;
      border-color: #30363d;
      color: #c9d1d9;
    }

    body.dark-mode .dm-toggle:hover {
      background: #30363d;
      color: #e6edf3;
    }

    body.dark-mode .acc-avatar {
      box-shadow: 0 6px 24px rgba(16, 185, 129, .25);
    }

    body.dark-mode .si-green {
      background: linear-gradient(135deg, #0d2d1a, #196c2e);
      color: #3fb950;
    }

    body.dark-mode .si-blue {
      background: linear-gradient(135deg, #0c1a3a, #1e3a6e);
      color: #58a6ff;
    }

    body.dark-mode .si-orange {
      background: linear-gradient(135deg, #2d1a00, #5a3400);
      color: #e08a45;
    }

    body.dark-mode .si-purple {
      background: linear-gradient(135deg, #2d1248, #4a1a70);
      color: #c084fc;
    }

    body.dark-mode .si-indigo {
      background: linear-gradient(135deg, #1a1a3a, #2d2d6a);
      color: #818cf8;
    }

    body.dark-mode .si-amber {
      background: linear-gradient(135deg, #2d2000, #4d3800);
      color: #d29922;
    }

    /* ══ AUDIT LOG CARDS (responsive) ══════════════════════════ */
    .audit-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: var(--shadow-sm);
      transition: box-shadow .15s;
    }

    .audit-card:hover {
      box-shadow: var(--shadow);
    }

    .audit-card-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }

    .audit-card-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .audit-num {
      font-size: 10px;
      font-weight: 700;
      color: var(--text-muted);
      min-width: 24px;
      text-align: right;
    }

    .audit-time {
      font-size: 11.5px;
      color: var(--text-muted);
      white-space: nowrap;
    }

    .audit-action {
      font-size: 13px;
      font-weight: 700;
      color: var(--text);
    }

    .audit-details {
      font-size: 12.5px;
      color: var(--text-muted);
      line-height: 1.5;
      padding-left: 2px;
    }

    body.dark-mode .audit-card {
      background: #161b22;
      border-color: #30363d;
    }

    body.dark-mode .audit-card:hover {
      box-shadow: 0 4px 16px rgba(0, 0, 0, .3);
    }

    body.dark-mode .audit-action {
      color: #e6edf3;
    }

    body.dark-mode .audit-details {
      color: #8b949e;
    }

    body.dark-mode .audit-time {
      color: #8b949e;
    }

    /* ══ RESPONSIVE BREAKPOINTS ══════════════════════════════════ */

    /* Mobile hamburger button */
    .sb-mobile-toggle {
      display: none;
      position: fixed;
      top: 14px;
      left: 14px;
      z-index: 1050;
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: var(--sidebar-bg);
      color: rgba(255, 255, 255, .7);
      border: 1px solid rgba(255, 255, 255, .1);
      align-items: center;
      justify-content: center;
      font-size: 18px;
      cursor: pointer;
      box-shadow: 0 2px 12px rgba(0, 0, 0, .3);
      transition: all .15s;
    }

    .sb-mobile-toggle:hover {
      background: #1c2128;
      color: #fff;
    }

    .sb-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .5);
      z-index: 1039;
      backdrop-filter: blur(2px);
    }

    /* Tablet (≤992px) */
    @media (max-width: 992px) {
      .st-sidebar {
        transform: translateX(-100%);
        transition: transform .25s cubic-bezier(.4, 0, .2, 1);
        z-index: 1045;
      }

      .st-sidebar.sb-open {
        transform: translateX(0);
        box-shadow: 8px 0 32px rgba(0, 0, 0, .35);
      }

      .sb-overlay.active {
        display: block;
      }

      .sb-mobile-toggle {
        display: flex;
      }

      .st-main {
        margin-left: 0;
      }

      .st-topbar {
        padding: 0 16px 0 62px;
      }

      .st-content {
        padding: 22px 18px 60px;
      }

      .status-pill {
        display: none;
      }

      .status-pill:last-of-type {
        display: inline-flex;
      }
    }

    /* Mobile (≤768px) */
    @media (max-width: 768px) {
      .st-content {
        padding: 16px 12px 60px;
      }

      .topbar-title {
        font-size: 15px;
      }

      .topbar-breadcrumb {
        display: none;
      }

      .status-pill {
        display: none;
      }

      .live-badge {
        font-size: 10px;
        padding: 4px 9px;
      }

      /* Stack page headers */
      .d-flex.align-items-start.justify-content-between {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px;
      }

      /* Stat cards: 2 per row on mobile */
      .col-md-3 {
        flex: 0 0 50%;
        max-width: 50%;
      }

      /* Hide table on mobile for voters/candidates — show stacked cards */
      .mobile-hidden {
        display: none !important;
      }

      .mobile-card-list {
        display: flex !important;
        flex-direction: column;
        gap: 10px;
      }

      /* Filters wrap nicely */
      .st-input[style*="max-width"] {
        max-width: 100% !important;
        width: 100% !important;
      }

      .st-input[style*="width:160px"] {
        width: 100% !important;
      }

      /* El-strip scroll on mobile */
      .el-strip {
        overflow-x: auto;
        flex-wrap: nowrap;
        gap: 16px;
      }

      /* Voter/Candidate table replace with cards on mobile */
      .table-responsive-st {
        border-radius: 0;
      }

      .ph-title {
        font-size: 20px;
      }

      .stat-num {
        font-size: 24px;
      }

      /* Modals full screen on mobile */
      .modal-dialog {
        margin: 8px;
      }

      .modal-dialog.modal-lg {
        max-width: calc(100vw - 16px);
      }
    }

    /* Small mobile (≤480px) */
    @media (max-width: 480px) {
      .col-md-3 {
        flex: 0 0 100%;
        max-width: 100%;
      }

      .d-flex.gap-2.flex-wrap {
        flex-direction: column;
      }

      .d-flex.gap-2.flex-wrap .btn-st-primary,
      .d-flex.gap-2.flex-wrap .btn-st-muted,
      .d-flex.gap-2.flex-wrap .btn-clas-primary {
        width: 100%;
        justify-content: center;
      }

      .st-topbar {
        gap: 6px;
      }
    }

    /* Audit log responsive: hide table on mobile, show cards */
    @media (max-width: 768px) {
      .audit-table-wrap {
        display: none !important;
      }

      .audit-cards-wrap {
        display: flex !important;
      }
    }

    @media (min-width: 769px) {
      .audit-cards-wrap {
        display: none !important;
      }

      .audit-table-wrap {
        display: block !important;
      }
    }
  </style>
</head>

<body>

  <div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
  <button class="sb-mobile-toggle" id="sbToggle" onclick="toggleSidebar()" title="Toggle menu">
    <i class="bi bi-list"></i>
  </button>

  <aside class="st-sidebar" id="stSidebar">
    <div class="sb-brand">
      <div class="sb-logo-img-wrap">
        <img src="suffra.png" class="sb-logo-img" alt="SuffraTech">
        <div>
          <div class="sb-logo-text">
            <span class="sb-logo-suffra">Suffra</span><span class="sb-logo-tech">Tech</span>
          </div>
          <span class="sb-badge">Admin Panel</span>
        </div>
      </div>
    </div>

    <div class="sb-nav-scroll">
      <div class="sb-section-label">Main</div>
      <?php
      $navItems = [
        'overview'         => ['bi-grid-fill',        'Dashboard', ''],
        'voters'           => ['bi-people-fill',       'Voters', ''],
        'candidates'       => ['bi-person-badge-fill', 'Candidates', ''],
        'election-control' => ['bi-sliders2',          'Election Control', ''],
        'live-results'     => ['bi-bar-chart-fill',    'Live Results', ''],
      ];
      foreach ($navItems as $k => [$icon, $label, $extra]):
      ?>
        <a class="sb-nav-link <?= $section === $k ? 'active' : '' ?>" href="admin.php?section=<?= $k ?>">
          <div class="sb-icon"><i class="bi <?= $icon ?>"></i></div>
          <?= $label ?>
          <?php if ($k === 'voters' && $pending_voters > 0): ?><span class="nav-badge"><?= $pending_voters ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>

      <div class="sb-section-label" style="margin-top:4px">CLAS</div>
      <a class="sb-nav-link clas-link <?= $section === 'clas-election' ? 'active' : '' ?>" href="admin.php?section=clas-election">
        <div class="sb-icon"><i class="bi bi-mortarboard-fill"></i></div>
        CLAS Election
      </a>

      <div class="sb-section-label" style="margin-top:4px">Records</div>
      <a class="sb-nav-link hist-link <?= $section === 'election-history' ? 'active' : '' ?>" href="admin.php?section=election-history">
        <div class="sb-icon"><i class="bi bi-archive-fill"></i></div>
        Election History
      </a>

      <div class="sb-section-label" style="margin-top:4px">System</div>
      <?php
      $sysItems = [
        'audit-log' => ['bi-clock-history',  'Audit Log'],
        'feedback'  => ['bi-chat-dots-fill', 'Feedback'],
        'account'   => ['bi-person-circle',  'My Account'],
      ];
      foreach ($sysItems as $k => [$icon, $label]):
      ?>
        <a class="sb-nav-link <?= $section === $k ? 'active' : '' ?>" href="admin.php?section=<?= $k ?>">
          <div class="sb-icon"><i class="bi <?= $icon ?>"></i></div>
          <?= $label ?>
        </a>
      <?php endforeach; ?>

      <div class="sb-spacer"></div>
      <!-- ── Version / system badge ── -->
      <div style="padding:10px 18px 0;text-align:center">
        <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.18);border-radius:8px;padding:6px 10px;font-size:10px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.35);text-transform:uppercase">
          SuffraTech Admin v2.0
        </div>
      </div>
    </div><!-- /.sb-nav-scroll -->
    <div class="sb-footer">
      <div class="sb-user-card">
        <div class="sb-avatar" style="background:linear-gradient(135deg,var(--accent-dark),var(--accent-light));box-shadow:0 3px 10px rgba(16,185,129,.4)"><?= $initials ?></div>
        <div style="overflow:hidden">
          <div class="sb-user-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $adminName ?></div>
          <div class="sb-user-role" style="display:flex;align-items:center;gap:4px"><span style="width:6px;height:6px;background:#10b981;border-radius:50%;display:inline-block;box-shadow:0 0 6px #10b981"></span>Administrator</div>
        </div>
      </div>
      <button class="sb-logout" type="button" onclick="openLogoutModal()">
        <i class="bi bi-box-arrow-right"></i> Sign Out
      </button>
    </div>
  </aside>

  <div class="st-main">
    <div class="st-topbar">
      <div class="d-flex align-items-center gap-3">
        <button class="btn-icon-mobile" id="sbToggleTopbar" onclick="toggleSidebar()" title="Toggle menu">
          <i class="bi bi-list"></i>
        </button>
        <div>
          <div class="topbar-title"><?= h($pageTitle) ?></div>
          <div class="topbar-breadcrumb"><span style="color:var(--accent);font-weight:700">SuffraTech</span> &rsaquo; <?= h($pageTitle) ?></div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <!-- Live clock -->
        <div id="topbar-clock" style="font-family:var(--font-display);font-size:12.5px;font-weight:700;color:var(--text-muted);letter-spacing:.5px;padding:4px 12px;background:var(--surface-2);border:1px solid var(--border);border-radius:20px;display:none"></div>
        <button class="dm-toggle" id="dmToggle" title="Toggle dark/light mode" onclick="toggleDarkMode()">
          <i class="bi bi-moon-fill" id="dmIcon"></i>
        </button>
        <?php if ($section === 'live-results'): ?>
        <?php endif; ?>
        <?php $statusClass = match ($election['status']) {
          'Ongoing' => 'ongoing',
          'Ended' => 'ended',
          default => 'ns'
        }; ?>
        <div class="status-pill <?= $statusClass ?>">
          <div class="status-dot"></div>General: <?= h($election['status']) ?>
        </div>
        <?php $clasStatusClass = match ($clasElection['status']) {
          'Ongoing' => 'ongoing',
          'Ended' => 'ended',
          default => 'ns'
        }; ?>
        <div class="status-pill clas-pill">
          <div class="status-dot" style="background:#6366f1"></div>CLAS: <?= h($clasElection['status']) ?>
        </div>
      </div>
    </div>

    <div class="st-content">
      <?php if ($msg): ?><div class="st-flash ok"><i class="bi bi-check-circle-fill"></i> <?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="st-flash err"><i class="bi bi-exclamation-triangle-fill"></i> <?= h($err) ?></div><?php endif; ?>

      <!-- ════ DASHBOARD ════════════════════════════════════════ -->
      <?php if ($section === 'overview'): ?>

        <!-- Premium Hero Banner -->
        <div style="
          background:linear-gradient(135deg,#09090f 0%,#0f1a1a 50%,#091a10 100%);
          border-radius:20px;
          padding:28px 32px;
          margin-bottom:24px;
          position:relative;
          overflow:hidden;
          box-shadow:0 8px 32px rgba(0,0,0,.18);
        ">
          <!-- glow orbs -->
          <div style="position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:radial-gradient(circle,rgba(16,185,129,.25),transparent 70%);pointer-events:none"></div>
          <div style="position:absolute;bottom:-60px;left:60px;width:180px;height:180px;background:radial-gradient(circle,rgba(99,102,241,.15),transparent 70%);pointer-events:none"></div>
          <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
            <div>
              <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);margin-bottom:6px">Welcome back</div>
              <div style="font-family:var(--font-display);font-size:26px;font-weight:800;color:#fff;line-height:1.1">
                <?= explode(' ', $adminName)[0] ?> <span style="color:var(--accent-light)">👋</span>
              </div>
              <div style="font-size:13px;color:rgba(255,255,255,.45);margin-top:6px">
                Managing <span style="color:rgba(255,255,255,.75);font-weight:600"><?= h($election['title']) ?></span>
                &nbsp;&amp;&nbsp;
                <span style="color:rgba(99,102,241,.9);font-weight:600"><?= h($clasElection['title']) ?></span>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
              <div style="font-family:var(--font-display);font-size:13px;font-weight:700;color:rgba(255,255,255,.3);letter-spacing:2px">SUFFRATECH</div>
              <div style="display:flex;gap:8px">
                <div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);border-radius:10px;padding:6px 14px;font-size:11.5px;font-weight:700;color:var(--accent)">
                  General: <?= h($election['status']) ?>
                </div>
                <div style="background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);border-radius:10px;padding:6px 14px;font-size:11.5px;font-weight:700;color:#818cf8">
                  CLAS: <?= h($clasElection['status']) ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-icon si-green"><i class="bi bi-people-fill"></i></div>
              <div>
                <div class="stat-num"><?= $total_voters ?></div>
                <div class="stat-lbl">Registered Voters</div>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-icon si-blue"><i class="bi bi-check2-square"></i></div>
              <div>
                <div class="stat-num"><?= $votes_cast ?></div>
                <div class="stat-lbl">General Votes Cast</div>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-icon si-indigo"><i class="bi bi-mortarboard-fill"></i></div>
              <div>
                <div class="stat-num"><?= $clas_votes_cast ?></div>
                <div class="stat-lbl">CLAS Votes Cast</div>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-icon si-purple"><i class="bi bi-trophy-fill"></i></div>
              <div>
                <div class="stat-num"><?= $total_candidates ?></div>
                <div class="stat-lbl">Active Candidates</div>
              </div>
            </div>
          </div>
        </div>
        <div class="mb-2" style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8">General Election</div>
        <div class="el-strip mb-3">
          <div>
            <div class="el-lbl">Title</div>
            <div class="el-val"><?= h($election['title']) ?></div>
          </div>
          <div class="el-divider"></div>
          <div>
            <div class="el-lbl">Status</div>
            <div class="el-val"><?php $bc = match ($election['status']) {
                                  'Ongoing' => 'stb-green',
                                  'Ended' => 'stb-red',
                                  default => 'stb-gray'
                                }; ?><span class="st-badge <?= $bc ?>"><?= h($election['status']) ?></span></div>
          </div>
          <?php if ($election['start_dt']): ?><div class="el-divider"></div>
            <div>
              <div class="el-lbl">Start</div>
              <div class="el-val"><?= h($election['start_dt']) ?></div>
            </div><?php endif; ?>
          <?php if ($election['end_dt']): ?><div class="el-divider"></div>
            <div>
              <div class="el-lbl">End</div>
              <div class="el-val"><?= h($election['end_dt']) ?></div>
            </div><?php endif; ?>
          <?php if ($timeInfo): ?><div class="el-divider"></div>
            <div>
              <div class="el-lbl">Schedule</div>
              <div class="el-val" style="font-size:13px"><?= $timeInfo ?></div>
            </div><?php endif; ?>
        </div>
        <div class="mb-2 clas-overview-label">CLAS Council Election</div>
        <div class="el-strip">
          <div>
            <div class="el-lbl">Title</div>
            <div class="el-val"><?= h($clasElection['title']) ?></div>
          </div>
          <div class="el-divider"></div>
          <div>
            <div class="el-lbl">Status</div>
            <div class="el-val"><?php $cbc = match ($clasElection['status']) {
                                  'Ongoing' => 'stb-indigo',
                                  'Ended' => 'stb-red',
                                  default => 'stb-gray'
                                }; ?><span class="st-badge <?= $cbc ?>"><?= h($clasElection['status']) ?></span></div>
          </div>
          <?php if ($clasElection['start_dt']): ?><div class="el-divider"></div>
            <div>
              <div class="el-lbl">Start</div>
              <div class="el-val"><?= h($clasElection['start_dt']) ?></div>
            </div><?php endif; ?>
          <?php if ($clasElection['end_dt']): ?><div class="el-divider"></div>
            <div>
              <div class="el-lbl">End</div>
              <div class="el-val"><?= h($clasElection['end_dt']) ?></div>
            </div><?php endif; ?>
          <?php if ($clasTimeInfo): ?><div class="el-divider"></div>
            <div>
              <div class="el-lbl">Schedule</div>
              <div class="el-val" style="font-size:13px"><?= $clasTimeInfo ?></div>
            </div><?php endif; ?>
        </div>

        <!-- ════ VOTERS ══════════════════════════════════════════ -->
      <?php elseif ($section === 'voters'): ?>
        <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
          <div>
            <div class="ph-title">Voters</div>
            <div class="ph-sub">Manage registered student voters.</div>
          </div>
          <button class="btn-st-primary" onclick="openVoterModal()"><i class="bi bi-person-plus-fill"></i> Add New Voter</button>
        </div>
        <?php if ($pending_voters > 0): ?><div class="pending-alert mb-3"><i class="bi bi-clock-fill"></i><strong><?= $pending_voters ?></strong> voter<?= $pending_voters > 1 ? 's' : '' ?> pending approval.</div><?php endif; ?>
        <form method="GET" action="admin.php" class="d-flex gap-2 flex-wrap mb-3">
          <input type="hidden" name="section" value="voters">
          <input class="st-input" style="max-width:300px" type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search by name or Student ID…">
          <select class="st-input" style="width:160px" name="status_filter">
            <?php foreach (['all' => 'All Status', 'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($_GET['status_filter'] ?? 'all') === $v ? ' selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn-st-muted" type="submit"><i class="bi bi-search"></i> Search</button>
          <?php if (!empty($_GET['q']) || !empty($_GET['status_filter'])): ?><a class="btn-st-muted" href="admin.php?section=voters"><i class="bi bi-x-lg"></i> Clear</a><?php endif; ?>
        </form>
        <div class="st-card">
          <?php if ($voters): ?>
            <div class="table-responsive-st">
              <table class="st-table">
                <thead>
                  <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Birthday</th>
                    <th>Registered</th>
                    <th>Status</th>
                    <th>General</th>
                    <th>CLAS</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($voters as $v): $age = $v['birthdate'] ? calcAge($v['birthdate']) : '—';
                    $bc = match ($v['approval_status'] ?? 'Pending') {
                      'Approved' => 'stb-green',
                      'Rejected' => 'stb-red',
                      default => 'stb-yellow'
                    }; ?>
                    <tr>
                      <td><strong><?= h($v['student_id']) ?></strong></td>
                      <td><?= h($v['first_name'] . ' ' . $v['last_name']) ?></td>
                      <td class="text-muted-sm"><?= h($v['email']) ?></td>
                      <td class="text-muted-sm"><?= $v['birthdate'] ? h($v['birthdate']) . ' (' . $age . 'y)' : '—' ?></td>
                      <td class="text-muted-sm"><?= h(date('M d, Y', strtotime($v['created_at']))) ?></td>
                      <td><span class="st-badge <?= $bc ?>"><?= h($v['approval_status'] ?? 'Pending') ?></span></td>
                      <td><?= $v['has_voted'] ? '<span class="st-badge stb-blue"><i class="bi bi-check-lg"></i> Voted</span>' : '<span class="text-muted-sm">—</span>' ?></td>
                      <td><?= $v['has_voted_clas'] ? '<span class="st-badge stb-indigo"><i class="bi bi-check-lg"></i> Voted</span>' : '<span class="text-muted-sm">—</span>' ?></td>
                      <td>
                        <div class="d-flex gap-1 flex-wrap">
                          <button class="btn-st-muted btn-sm-icon" onclick='openEditVoter(<?= $v["id"] ?>,<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'><i class="bi bi-pencil-fill"></i></button>
                          <?php if ($v['approval_status'] !== 'Approved'): ?><form method="POST" action="admin.php?section=voters" class="d-inline"><input type="hidden" name="action" value="approve_voter"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="btn-st-approve" type="submit"><i class="bi bi-check-lg"></i> Approve</button></form><?php endif; ?>
                          <?php if ($v['approval_status'] !== 'Rejected'): ?><form method="POST" action="admin.php?section=voters" class="d-inline" onsubmit="return confirmAction('Reject this voter?', 'Are you sure you want to reject this voter? This action can be undone.')"><input type="hidden" name="action" value="reject_voter"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="btn-st-reject" type="submit"><i class="bi bi-ban"></i> Reject</button></form><?php endif; ?>
                          <form method="POST" action="admin.php?section=voters" class="d-inline" onsubmit="return confirm('Delete voter <?= h($v['student_id']) ?>?')"><input type="hidden" name="action" value="delete_voter"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="btn-st-danger btn-sm-icon" type="submit"><i class="bi bi-trash-fill"></i></button></form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?><div class="st-empty">
              <div class="st-empty-icon"><i class="bi bi-people"></i></div>
              <div class="st-empty-text">No voters found.</div>
            </div><?php endif; ?>
        </div>
        <!-- Voter Modal -->
        <div class="modal fade st-modal" id="voterModal" tabindex="-1">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <div class="modal-title" id="voterModalTitle"><i class="bi bi-person-plus-fill"></i> Add New Voter</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <form method="POST" action="admin.php?section=voters">
                  <input type="hidden" name="action" value="save_voter">
                  <input type="hidden" name="id" id="voter_id" value="">
                  <div class="row g-3">
                    <div class="col-md-4"><label class="st-label">Student ID <span class="req">*</span></label><input class="st-input" type="text" name="student_id" id="voter_student_id" required></div>
                    <div class="col-md-4"><label class="st-label">First Name <span class="req">*</span></label><input class="st-input" type="text" name="first_name" id="voter_first_name" required></div>
                    <div class="col-md-4"><label class="st-label">Last Name</label><input class="st-input" type="text" name="last_name" id="voter_last_name"></div>
                    <div class="col-md-4"><label class="st-label">Email <span class="req">*</span></label><input class="st-input" type="email" name="email" id="voter_email" required></div>
                    <div class="col-md-4"><label class="st-label">Birthday</label><input class="st-input" type="date" name="birthdate" id="voter_birthdate"></div>
                    <div class="col-md-4"><label class="st-label">Status</label>
                      <select class="st-input" name="status" id="voter_status">
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                      </select>
                    </div>
                    <div class="col-12" id="voter_pw_note">
                      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 14px;font-size:12.5px;color:#166534"><i class="bi bi-info-circle-fill"></i> Default password: <strong>changeme123</strong></div>
                    </div>
                  </div>
                  <div class="modal-footer px-0 pb-0 mt-3">
                    <button class="btn-st-primary" type="submit"><i class="bi bi-save-fill"></i> <span id="voterSaveLabel">Add Voter</span></button>
                    <button type="button" class="btn-st-muted" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- ════ CANDIDATES ═══════════════════════════════════════ -->
      <?php elseif ($section === 'candidates'): ?>
        <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
          <div>
            <div class="ph-title">Candidates</div>
            <div class="ph-sub">Manage candidates for both General and CLAS elections.</div>
          </div>
          <button class="btn-st-primary" onclick="openCandidateModal()"><i class="bi bi-plus-circle-fill"></i> Add Candidate</button>
        </div>
        <form method="GET" action="admin.php" class="d-flex gap-2 flex-wrap mb-3">
          <input type="hidden" name="section" value="candidates">
          <input class="st-input" style="max-width:280px" type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search…">
          <select class="st-input" style="width:170px" name="etype">
            <option value="all" <?= ($_GET['etype'] ?? 'all') === 'all' ? ' selected' : '' ?>>All Elections</option>
            <option value="general" <?= ($_GET['etype'] ?? '') === 'general' ? ' selected' : '' ?>>General</option>
            <option value="clas" <?= ($_GET['etype'] ?? '') === 'clas' ? ' selected' : '' ?>>CLAS</option>
          </select>
          <label class="st-check-label d-flex align-items-center gap-1" style="font-size:13px;cursor:pointer">
            <input type="checkbox" name="show_inactive" value="1" <?= isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1' ? 'checked' : '' ?> onchange="this.form.submit()">
            <i class="bi bi-eye-slash" style="color:#94a3b8"></i> Show Inactive
          </label>
          <button class="btn-st-muted" type="submit"><i class="bi bi-search"></i> Search</button>
          <?php if (!empty($_GET['q']) || !empty($_GET['etype']) || !empty($_GET['show_inactive'])): ?><a class="btn-st-muted" href="admin.php?section=candidates"><i class="bi bi-x-lg"></i> Clear</a><?php endif; ?>
        </form>
        <div class="st-card">
          <?php if ($candidates): ?>
            <div class="table-responsive-st">
              <table class="st-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Election</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th>Platform</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th>Votes</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($candidates as $c): ?>
                    <tr style="<?= !$c['is_active'] ? 'opacity:0.55' : '' ?>">
                      <td class="text-muted-sm"><?= $c['id'] ?></td>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <?php if (!empty($c['photo']) && file_exists(__DIR__ . '/' . $c['photo'])): ?>
                            <img src="<?= h($c['photo']) ?>?v=<?= filemtime(__DIR__ . '/' . $c['photo']) ?>" class="cand-avatar" alt="">
                          <?php else: ?>
                            <div class="cand-avatar-placeholder"><i class="bi bi-person-fill"></i></div>
                          <?php endif; ?>
                          <strong><?= h($c['full_name']) ?></strong>
                        </div>
                      </td>
                      <td><?php $et = $c['election_type'] ?? 'general';
                          echo $et === 'clas' ? '<span class="st-badge stb-indigo"><i class="bi bi-mortarboard-fill"></i> CLAS</span>' : '<span class="st-badge stb-green">General</span>'; ?></td>
                      <td><span class="st-badge stb-blue"><?= h($c['position_name'] ?? '—') ?></span></td>
                      <td><?= $c['is_active'] ? '<span class="st-badge stb-green"><i class="bi bi-check-circle-fill"></i> Active</span>' : '<span class="st-badge" style="background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0"><i class="bi bi-slash-circle"></i> Inactive</span>' ?></td>
                      <td class="text-muted-sm"><?= h($c['motto'] ?? '—') ?></td>
                      <td class="text-muted-sm"><?= h($c['program'] ?? '—') ?></td>
                      <td class="text-muted-sm"><?= h($c['section'] ?? '—') ?></td>
                      <td><strong><?= (int)$c['vote_count'] ?></strong></td>
                      <td>
                        <div class="d-flex gap-1">
                          <button class="btn-st-muted btn-sm-icon" onclick='openEditCandidate(<?= $c["id"] ?>,<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'><i class="bi bi-pencil-fill"></i></button>
                          <form method="POST" action="admin.php?section=candidates" class="d-inline" onsubmit="return confirmAction('Delete?', 'Are you sure you want to permanently delete this? This cannot be undone.')"><input type="hidden" name="action" value="delete_candidate"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn-st-danger btn-sm-icon" type="submit"><i class="bi bi-trash-fill"></i></button></form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?><div class="st-empty">
              <div class="st-empty-icon"><i class="bi bi-person-badge"></i></div>
              <div class="st-empty-text">No candidates yet.</div>
            </div><?php endif; ?>
        </div>

        <!-- ══ CANDIDATE MODAL with position dropdown ══ -->
        <div class="modal fade st-modal" id="candidateModal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <div class="modal-title" id="candidateModalTitle"><i class="bi bi-plus-circle-fill"></i> Add New Candidate</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <form method="POST" action="admin.php?section=candidates" enctype="multipart/form-data" onsubmit="return validateCandForm(this)">
                  <input type="hidden" name="action" value="save_candidate">
                  <input type="hidden" name="id" id="cand_id" value="">
                  <div class="row g-3">

                    <!-- Election Type -->
                    <div class="col-12">
                      <label class="st-label">Election Type <span class="req">*</span></label>
                      <select class="st-input" name="election_type" id="cand_etype" onchange="filterPositionDropdown(this.value)">
                        <option value="general">🗳️ General Election</option>
                        <option value="clas">🎓 CLAS Council Election</option>
                      </select>
                    </div>

                    <!-- Student Search Autocomplete -->
                    <div class="col-12" id="student_search_wrap">
                      <label class="st-label">🔍 Search Registered Student <span style="font-size:10px;color:#94a3b8;font-weight:400">(type name or Student ID to auto-fill)</span></label>
                      <div style="position:relative">
                        <input class="st-input" type="text" id="student_search_input"
                          placeholder="Type student name or ID…"
                          autocomplete="off"
                          oninput="searchStudents(this.value)">
                        <div id="student_dropdown" style="
                          display:none;
                          position:absolute;
                          top:100%;left:0;right:0;
                          background:var(--surface);
                          border:1.5px solid var(--border);
                          border-top:none;
                          border-radius:0 0 10px 10px;
                          box-shadow:0 8px 24px rgba(0,0,0,.12);
                          z-index:9999;
                          max-height:220px;
                          overflow-y:auto;
                        "></div>
                      </div>
                      <div class="pos-hint mt-1"><i class="bi bi-info-circle"></i> Selecting a student auto-fills their name below. Fields can still be edited manually.</div>
                    </div>

                    <!-- Full Name -->
                    <div class="col-12">
                      <label class="st-label">Full Name <span class="req">*</span></label>
                      <input class="st-input" type="text" name="full_name" id="cand_name" placeholder="e.g. Juan Dela Cruz" required>
                    </div>

                    <!-- Position Dropdown -->
                    <div class="col-12">
                      <label class="st-label">Position <span class="req">*</span></label>
                      <input type="hidden" name="position" id="cand_position_hidden">
                      <select class="st-input" id="cand_position_select" onchange="document.getElementById('cand_position_hidden').value = this.value; updateSectionHint(this.value);">
                        <option value="">— Select a position —</option>
                      </select>
                      <div class="pos-hint"><i class="bi bi-info-circle"></i> Positions shown match the selected election type above.</div>
                    </div>

                    <!-- Platform / Bio -->
                    <div class="col-12">
                      <label class="st-label">Platform / Bio</label>
                      <input class="st-input" type="text" name="motto" id="cand_motto" placeholder="Short platform or motto (optional)">
                    </div>

                    <!-- Program & Section — CLAS only -->
                    <div id="cand_program_section_wrap" style="display:none" class="col-12">
                      <div class="row g-3">
                        <div class="col-md-6">
                          <label class="st-label">Program</label>
                          <select class="st-input" name="program" id="cand_program">
                            <option value="">— Select Program —</option>
                            <option value="BS Mathematics">BS Mathematics</option>
                            <option value="BS Psychology">BS Psychology</option>
                            <option value="BA Political Science">BA Political Science</option>
                            <option value="BA Communication">BA Communication</option>
                            <option value="BA Behavioral Science">BA Behavioral Science</option>
                            <option value="BS Information Technology (BSIT)">BS Information Technology (BSIT)</option>
                            <option value="BS Information Systems (BSIS)">BS Information Systems (BSIS)</option>
                            <option value="BS Entertainment &amp; Multimedia Computing (BSEMC)">BS Entertainment &amp; Multimedia Computing (BSEMC)</option>
                            <option value="BS Computer Science (BSCS)">BS Computer Science (BSCS)</option>
                            <option value="BS Public Administration (BPA)">BS Public Administration (BPA)</option>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="st-label" id="cand_section_label">Section</label>
                          <select class="st-input" name="section" id="cand_section">
                            <option value="">— Select Section —</option>
                            <optgroup label="1st Year">
                              <option value="1A">1A</option>
                              <option value="1B">1B</option>
                              <option value="1C">1C</option>
                            </optgroup>
                            <optgroup label="2nd Year">
                              <option value="2A">2A</option>
                              <option value="2B">2B</option>
                              <option value="2C">2C</option>
                            </optgroup>
                            <optgroup label="3rd Year">
                              <option value="3A">3A</option>
                              <option value="3B">3B</option>
                              <option value="3C">3C</option>
                            </optgroup>
                            <optgroup label="4th Year">
                              <option value="4A">4A</option>
                              <option value="4B">4B</option>
                              <option value="4C">4C</option>
                            </optgroup>
                          </select>
                          <div id="cand_section_hint" style="display:none;margin-top:5px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px;color:#1e40af">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Uniqueness rule:</strong> Representative positions = <strong>1 per section</strong>. Council positions = <strong>1 per year level</strong>.
                          </div>
                        </div>
                      </div><!-- /.row -->
                    </div><!-- /#cand_program_section_wrap -->

                    <!-- Candidate Photo -->
                    <div class="col-12">
                      <label class="st-label">Candidate Photo</label>
                      <div class="d-flex align-items-center gap-3">
                        <div class="photo-preview" id="cand_photo_preview">
                          <i class="bi bi-person-fill"></i>
                        </div>
                        <div class="flex-grow-1">
                          <input class="st-input" type="file" name="photo" id="cand_photo_input"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            onchange="previewCandPhoto(this)">
                          <div class="pos-hint mt-1"><i class="bi bi-info-circle"></i> JPG, PNG, GIF or WEBP. Leave blank to keep existing photo.</div>
                        </div>
                      </div>
                    </div>

                  </div>
                  <div class="modal-footer px-0 pb-0 mt-3">
                    <button class="btn-st-primary" type="submit"><i class="bi bi-save-fill"></i> <span id="candSaveLabel">Add Candidate</span></button>
                    <button type="button" class="btn-st-muted" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- ════ GENERAL ELECTION CONTROL ════════════════════════ -->
      <?php elseif ($section === 'election-control'): ?>
        <?php if ($election['start_dt'] || $election['end_dt']): ?>
          <div class="auto-banner <?= $autoStatus === 'ongoing' ? 'on' : ($autoStatus === 'ended' ? 'end' : 'ns') ?> mb-3">
            <div class="d-flex align-items-center gap-3">
              <div class="auto-dot"></div>
              <div>
                <div style="font-weight:700;font-size:14px">
                  <?php if ($autoStatus === 'ongoing'): ?>🟢 General Election is <strong>Ongoing</strong>
                  <?php elseif ($autoStatus === 'ended'): ?>🔴 General Election has <strong>Ended</strong>
                  <?php else: ?>⏳ General Election has <strong>Not Started</strong><?php endif; ?>
                </div>
                <div style="font-size:12.5px;margin-top:2px;opacity:.8"><?= $timeInfo ?></div>
              </div>
            </div>
            <div style="font-size:12px;opacity:.7"><i class="bi bi-info-circle me-1"></i>Status updates automatically.</div>
          </div>
          <?php if ($election['start_dt'] && $election['end_dt']): ?>
            <div class="schedule-preview mb-3">
              <div class="sched-col <?= $autoStatus === 'not-started' ? 'active' : '' ?>">
                <div class="sched-lbl">📅 Start</div>
                <div class="sched-val"><?= h(date('M d, Y · h:i A', strtotime($election['start_dt']))) ?></div>
              </div>
              <div class="sched-col <?= $autoStatus === 'ongoing' ? 'active' : '' ?>">
                <div class="sched-lbl">🗳️ Status</div>
                <div class="sched-val"><?= h($election['status']) ?></div>
              </div>
              <div class="sched-col <?= $autoStatus === 'ended' ? 'active' : '' ?>">
                <div class="sched-lbl">🏁 End</div>
                <div class="sched-val"><?= h(date('M d, Y · h:i A', strtotime($election['end_dt']))) ?></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="st-card mb-3">
          <div class="st-card-header">
            <div class="st-card-title"><i class="bi bi-sliders2"></i> General Election Settings</div>
          </div>
          <div class="st-card-body">
            <form method="POST" action="admin.php?section=election-control"><input type="hidden" name="action" value="save_election">
              <div class="row g-3">
                <div class="col-md-4"><label class="st-label">Election Title</label><input class="st-input" type="text" name="title" value="<?= h($election['title']) ?>"></div>
                <div class="col-md-4"><label class="st-label">📅 Start Date &amp; Time</label><input class="st-input" type="datetime-local" name="start_dt" min="2026-01-01T00:00" value="<?= h(str_replace(' ', 'T', $election['start_dt'] ?? '')) ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:4px">Voting opens automatically.</div>
                </div>
                <div class="col-md-4"><label class="st-label">🏁 End Date &amp; Time</label><input class="st-input" type="datetime-local" name="end_dt" min="2026-01-01T00:00" value="<?= h(str_replace(' ', 'T', $election['end_dt'] ?? '')) ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:4px">Voting closes automatically.</div>
                </div>
                <div class="col-md-4"><label class="st-label">Manual Override</label><select class="st-input" name="status"><?php foreach (['Not Started', 'Ongoing', 'Ended'] as $st): ?><option value="<?= $st ?>" <?= $election['status'] === $st ? ' selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="st-check-label"><input type="checkbox" name="ballot_locked" value="1" <?= $election['ballot_locked'] ? ' checked' : '' ?>><i class="bi bi-lock-fill" style="color:#94a3b8"></i> Lock Ballot</label></div>
              </div>
              <div class="form-divider"></div>
              <button class="btn-st-primary" type="submit"><i class="bi bi-save-fill"></i> Save General Election</button>
            </form>
          </div>
        </div>
        <div class="st-card danger-zone-card">
          <div class="st-card-header">
            <div class="st-card-title"><i class="bi bi-exclamation-triangle-fill"></i> Danger Zone — General</div>
          </div>
          <div class="st-card-body">
            <p class="text-muted-sm mb-3">Clears all general votes. <strong style="color:#dc2626">Cannot be undone.</strong></p>
            <button class="btn-st-danger" type="button" onclick="openResetModal('general')"><i class="bi bi-arrow-counterclockwise"></i> Reset General Votes</button>
            <form id="resetGeneralForm" method="POST" action="admin.php?section=election-control" style="display:none">
              <input type="hidden" name="action" value="reset_votes"><input type="hidden" name="confirm_reset" value="1">
            </form>
          </div>
        </div>

        <!-- ════ CLAS ELECTION ════════════════════════════════════ -->
      <?php elseif ($section === 'clas-election'): ?>
        <div class="clas-section-header">
          <div class="clas-section-icon">🎓</div>
          <div>
            <div class="clas-header-title">CLAS Council Election</div>
            <div class="clas-header-sub">College of Liberal Arts and Sciences — South Campus, UCC</div>
          </div>
        </div>
        <?php if ($clasElection['start_dt'] || $clasElection['end_dt']): ?>
          <div class="auto-banner <?= $clasAutoStatus === 'ongoing' ? 'clas-on' : ($clasAutoStatus === 'ended' ? 'end' : 'ns') ?> mb-3">
            <div class="d-flex align-items-center gap-3">
              <div class="auto-dot"></div>
              <div>
                <div style="font-weight:700;font-size:14px">
                  <?php if ($clasAutoStatus === 'ongoing'): ?>🟣 CLAS Election is <strong>Ongoing</strong>
                  <?php elseif ($clasAutoStatus === 'ended'): ?>🔴 CLAS Election has <strong>Ended</strong>
                  <?php else: ?>⏳ CLAS Election has <strong>Not Started</strong><?php endif; ?>
                </div>
                <div style="font-size:12.5px;margin-top:2px;opacity:.8"><?= $clasTimeInfo ?></div>
              </div>
            </div>
            <div style="font-size:12px;opacity:.7"><i class="bi bi-info-circle me-1"></i>Status updates automatically.</div>
          </div>
          <?php if ($clasElection['start_dt'] && $clasElection['end_dt']): ?>
            <div class="schedule-preview mb-3">
              <div class="sched-col <?= $clasAutoStatus === 'not-started' ? 'clas-active' : '' ?>">
                <div class="sched-lbl">📅 Start</div>
                <div class="sched-val"><?= h(date('M d, Y · h:i A', strtotime($clasElection['start_dt']))) ?></div>
              </div>
              <div class="sched-col <?= $clasAutoStatus === 'ongoing' ? 'clas-active' : '' ?>">
                <div class="sched-lbl">🗳️ Status</div>
                <div class="sched-val"><?= h($clasElection['status']) ?></div>
              </div>
              <div class="sched-col <?= $clasAutoStatus === 'ended' ? 'clas-active' : '' ?>">
                <div class="sched-lbl">🏁 End</div>
                <div class="sched-val"><?= h(date('M d, Y · h:i A', strtotime($clasElection['end_dt']))) ?></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="st-card mb-3">
          <div class="st-card-header">
            <div class="st-card-title"><i class="bi bi-mortarboard-fill clas-icon"></i> CLAS Election Settings</div>
          </div>
          <div class="st-card-body">
            <form method="POST" action="admin.php?section=clas-election"><input type="hidden" name="action" value="save_clas_election">
              <div class="row g-3">
                <div class="col-md-4"><label class="st-label">Election Title</label><input class="st-input clas-input" type="text" name="clas_title" value="<?= h($clasElection['title']) ?>"></div>
                <div class="col-md-4"><label class="st-label">📅 Start Date &amp; Time</label><input class="st-input clas-input" type="datetime-local" name="clas_start_dt" min="2026-01-01T00:00" value="<?= h(str_replace(' ', 'T', $clasElection['start_dt'] ?? '')) ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:4px">CLAS voting opens automatically.</div>
                </div>
                <div class="col-md-4"><label class="st-label">🏁 End Date &amp; Time</label><input class="st-input clas-input" type="datetime-local" name="clas_end_dt" min="2026-01-01T00:00" value="<?= h(str_replace(' ', 'T', $clasElection['end_dt'] ?? '')) ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:4px">CLAS voting closes. Results auto-archived.</div>
                </div>
                <div class="col-md-4"><label class="st-label">Manual Override</label><select class="st-input clas-input" name="clas_status"><?php foreach (['Not Started', 'Ongoing', 'Ended'] as $st): ?><option value="<?= $st ?>" <?= $clasElection['status'] === $st ? ' selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="st-check-label"><input type="checkbox" name="clas_ballot_locked" value="1" <?= $clasElection['ballot_locked'] ? ' checked' : '' ?> style="accent-color:#6366f1"><i class="bi bi-lock-fill" style="color:#94a3b8"></i> Lock CLAS Ballot</label></div>
              </div>
              <div class="form-divider"></div>
              <button class="btn-clas-primary" type="submit"><i class="bi bi-save-fill"></i> Save CLAS Election</button>
            </form>
          </div>
        </div>
        <?php $clas_total_votes = (int)$pdo->query("SELECT COUNT(DISTINCT student_db_id) FROM clas_votes")->fetchColumn(); ?>
        <div class="st-card mb-3">
          <div class="st-card-header">
            <div class="st-card-title"><i class="bi bi-bar-chart-fill clas-icon"></i> CLAS Live Results</div><span class="text-muted-sm"><i class="bi bi-people me-1"></i><?= $clas_total_votes ?> votes cast</span>
          </div>
          <div class="st-card-body">
            <?php if ($clas_lr_positions): ?>
              <div class="row g-3">
                <?php foreach ($clas_lr_positions as $pos): $rankClasses = ['rank-gold', 'rank-silver', 'rank-bronze']; ?>
                  <div class="col-md-6">
                    <div class="lr-position-card">
                      <div class="lr-pos-title clas-pos"><?= h($pos['position']) ?></div>
                      <?php if (empty($pos['candidates'])): ?><p class="text-muted-sm">No candidates.</p>
                        <?php else: foreach ($pos['candidates'] as $i => $c): $pct = (float)($c['pct'] ?? 0);
                          $rc = $rankClasses[$i] ?? 'rank-silver'; ?>
                          <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                              <span class="d-flex align-items-center gap-2"><span class="lr-rank <?= $rc ?>"><?= $i + 1 ?></span><span style="font-size:13.5px;font-weight:600"><?= h($c['full_name']) ?></span><?php if ($i === 0 && $c['vote_count'] > 0): ?><span class="lr-leading"><i class="bi bi-trophy-fill me-1"></i>Leading</span><?php endif; ?></span>
                              <span class="text-muted-sm"><?= number_format((int)$c['vote_count']) ?> — <?= $pct ?>%</span>
                            </div>
                            <div class="lr-bar-wrap">
                              <div class="lr-bar-fill clas-bar" style="width:<?= $pct ?>%"></div>
                            </div>
                          </div>
                      <?php endforeach;
                      endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?><p class="text-muted-sm">Add CLAS candidates to see results.</p><?php endif; ?>
          </div>
        </div>
        <div class="st-card danger-zone-card">
          <div class="st-card-header">
            <div class="st-card-title"><i class="bi bi-exclamation-triangle-fill"></i> Danger Zone — CLAS</div>
          </div>
          <div class="st-card-body">
            <p class="text-muted-sm mb-3">Clears all CLAS votes. <strong style="color:#dc2626">Cannot be undone.</strong></p>
            <button class="btn-st-danger" type="button" onclick="openResetModal('clas')"><i class="bi bi-arrow-counterclockwise"></i> Reset CLAS Votes</button>
            <form id="resetClasForm" method="POST" action="admin.php?section=clas-election" style="display:none">
              <input type="hidden" name="action" value="reset_clas_votes"><input type="hidden" name="confirm_reset_clas" value="1">
            </form>
          </div>
        </div>

        <!-- ════ LIVE RESULTS (General) ══════════════════════════ -->
      <?php elseif ($section === 'live-results'):
        $overall_pct = $total_voters > 0 ? round($votes_cast / $total_voters * 100, 1) : 0; ?>

        <!-- ── Live Results AJAX Shell ── -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
          <div>
            <div class="ph-title">Live Results</div>
            <div class="ph-sub">Votes update every <strong>10 seconds</strong> without a page reload.</div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <div class="live-badge">
              <div class="live-dot"></div>LIVE
            </div>
          </div>
        </div>

        <!-- progress bar -->
        <div class="countdown-bar mb-4">
          <div class="countdown-fill" id="cdFill" style="width:100%"></div>
        </div>

        <!-- last-updated row -->
        <div class="refresh-bar mb-3">
          <span><i class="bi bi-clock me-1"></i>Last updated: <strong id="lr-last-updated"><?= date('h:i:s A') ?></strong></span>
          <span id="lr-next-in" style="font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:4px;">Next refresh in <strong id="lr-countdown" style="font-size:14px;font-weight:800;color:#10b981;min-width:18px;display:inline-block;text-align:center;">10</strong><span style="color:#94a3b8">s</span></span>
        </div>

        <!-- stat cards (rendered by AJAX) -->
        <div class="row g-3 mb-4" id="lr-stats">
          <div class="col-md-4">
            <div class="lr-stat-card">
              <div class="lr-stat-num" id="lr-votes-cast"><?= number_format($votes_cast) ?></div>
              <div class="lr-stat-lbl">Votes Cast</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="lr-stat-card">
              <div class="lr-stat-num" id="lr-total-voters"><?= number_format($total_voters) ?></div>
              <div class="lr-stat-lbl">Registered Voters</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="lr-stat-card" style="border-top-color:var(--accent)">
              <div class="lr-stat-num" id="lr-turnout"><?= $overall_pct ?>%</div>
              <div class="lr-stat-lbl">Voter Turnout</div>
              <div style="margin-top:10px;height:6px;background:#f1f5f9;border-radius:10px;overflow:hidden">
                <div id="lr-turnout-bar" style="height:100%;width:<?= $overall_pct ?>%;background:linear-gradient(90deg,var(--accent-dark),var(--accent-light));border-radius:10px;transition:width .6s ease"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- position cards (rendered by AJAX) -->
        <div id="lr-positions-wrap">
          <?php if ($lr_positions): ?>
            <div class="row g-3">
              <?php foreach ($lr_positions as $pos): $rankClasses = ['rank-gold', 'rank-silver', 'rank-bronze']; ?>
                <div class="col-md-6">
                  <div class="lr-position-card">
                    <div class="lr-pos-title"><?= h($pos['position']) ?></div>
                    <?php if (empty($pos['candidates'])): ?><p class="text-muted-sm">No candidates.</p>
                      <?php else: foreach ($pos['candidates'] as $i => $c): $pct = (float)($c['pct'] ?? 0);
                        $rc = $rankClasses[$i] ?? 'rank-silver'; ?>
                        <div class="mb-3">
                          <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="d-flex align-items-center gap-2">
                              <span class="lr-rank <?= $rc ?>"><?= $i + 1 ?></span>
                              <span style="font-size:13.5px;font-weight:600"><?= h($c['full_name']) ?></span>
                              <?php if ($i === 0 && $c['vote_count'] > 0): ?><span class="lr-leading"><i class="bi bi-trophy-fill me-1"></i>Leading</span><?php endif; ?>
                            </span>
                            <span class="text-muted-sm"><?= number_format((int)$c['vote_count']) ?> — <?= $pct ?>%</span>
                          </div>
                          <div class="lr-bar-wrap">
                            <div class="lr-bar-fill" style="width:<?= $pct ?>%"></div>
                          </div>
                        </div>
                    <?php endforeach;
                    endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="st-card">
              <div class="st-empty">
                <div class="st-empty-icon"><i class="bi bi-bar-chart"></i></div>
                <div class="st-empty-text">No positions or candidates found.</div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <script>
          document.addEventListener('DOMContentLoaded', function() {
            var INTERVAL = 10,
              left = INTERVAL;
            var cdFill = document.getElementById('cdFill');
            var cdText = document.getElementById('lr-countdown');
            var nextInSpan = document.getElementById('lr-next-in');

            function updateTimerColor(remaining) {
              var pct = remaining / INTERVAL;
              var color, bgColor, fillColor;
              if (pct > 0.6) {
                color = '#065f46';
                bgColor = '#ecfdf5';
                fillColor = '#10b981';
              } else if (pct > 0.3) {
                color = '#92400e';
                bgColor = '#fffbeb';
                fillColor = '#f59e0b';
              } else {
                color = '#991b1b';
                bgColor = '#fef2f2';
                fillColor = '#ef4444';
              }
              if (cdFill) cdFill.style.background = fillColor;
              var refreshBar = document.querySelector('.refresh-bar');
              if (refreshBar) {
                refreshBar.style.background = bgColor;
                refreshBar.style.color = color;
                refreshBar.style.borderColor = fillColor + '66';
              }
              if (cdText) {
                cdText.style.color = fillColor;
                cdText.style.fontWeight = '800';
                cdText.style.fontSize = remaining <= 3 ? '14px' : '13px';
              }
            }

            function fetchLiveResults() {
              fetch('admin.php?api=live_results')
                .then(function(r) {
                  return r.json();
                })
                .then(function(d) {
                  if (d.error) return;
                  document.getElementById('lr-votes-cast').textContent = d.votes_cast.toLocaleString();
                  document.getElementById('lr-total-voters').textContent = d.total_voters.toLocaleString();
                  document.getElementById('lr-turnout').textContent = d.pct + '%';
                  document.getElementById('lr-turnout-bar').style.width = d.pct + '%';
                  document.getElementById('lr-last-updated').textContent = d.last_updated;
                  renderPositions(d.positions);
                  left = INTERVAL;
                })
                .catch(function() {});
            }

            function renderPositions(positions) {
              var rankClass = ['rank-gold', 'rank-silver', 'rank-bronze'];
              var html = '<div class="row g-3">';
              positions.forEach(function(pos) {
                html += '<div class="col-md-6"><div class="lr-position-card">';
                html += '<div class="lr-pos-title">' + esc(pos.position) + '</div>';
                if (!pos.candidates || !pos.candidates.length) {
                  html += '<p class="text-muted-sm">No candidates.</p>';
                } else {
                  pos.candidates.forEach(function(c, i) {
                    var pct = parseFloat(c.pct) || 0;
                    var rc = rankClass[i] || 'rank-silver';
                    var lead = (i === 0 && c.vote_count > 0) ? '<span class="lr-leading"><i class="bi bi-trophy-fill me-1"></i>Leading</span>' : '';
                    html += '<div class="mb-3">' +
                      '<div class="d-flex justify-content-between align-items-center mb-1">' +
                      '<span class="d-flex align-items-center gap-2">' +
                      '<span class="lr-rank ' + rc + '">' + (i + 1) + '</span>' +
                      '<span style="font-size:13.5px;font-weight:600">' + esc(c.full_name) + '</span>' +
                      lead + '</span>' +
                      '<span class="text-muted-sm">' + parseInt(c.vote_count).toLocaleString() + ' — ' + pct + '%</span>' +
                      '</div>' +
                      '<div class="lr-bar-wrap"><div class="lr-bar-fill" style="width:' + pct + '%"></div></div>' +
                      '</div>';
                  });
                }
                html += '</div></div>';
              });
              html += '</div>';
              document.getElementById('lr-positions-wrap').innerHTML = html;
            }

            function esc(s) {
              var d = document.createElement('div');
              d.textContent = s || '';
              return d.innerHTML;
            }

            // Countdown tick
            window.fetchLiveResults = fetchLiveResults;
            updateTimerColor(left);
            setInterval(function() {
              left--;
              if (left <= 0) {
                fetchLiveResults();
                left = INTERVAL;
              }
              if (cdFill) cdFill.style.width = Math.round(left / INTERVAL * 100) + '%';
              if (cdText) cdText.textContent = left;
              updateTimerColor(left);
            }, 1000);
          })();
        </script>

        <!-- ════ ELECTION HISTORY ════════════════════════════════ -->
      <?php elseif ($section === 'election-history'): ?>
        <div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
          <div>
            <div class="ph-title">Election History</div>
            <div class="ph-sub">Past elections are automatically archived when they end.</div>
          </div>
        </div>
        <?php if (empty($election_history)): ?>
          <div class="st-card">
            <div class="st-empty">
              <div class="st-empty-icon"><i class="bi bi-archive"></i></div>
              <div class="st-empty-text">No election history yet.</div>
              <div class="text-muted-sm mt-2">Elections are automatically archived here when they end.</div>
            </div>
          </div>
        <?php else: ?>
          <div class="d-flex flex-column gap-4">
            <?php foreach ($election_history as $hist):
              $results = $hist['results_json'] ? json_decode($hist['results_json'], true) : null;
              $positions_hist = $results['positions'] ?? [];
              $vc = $results['votes_cast'] ?? $hist['votes_cast'];
              $tv = $results['total_voters'] ?? $hist['total_voters'];
              $turnoutH = $tv > 0 ? round($vc / $tv * 100, 1) : 0;
              $etype = $hist['election_type'];
            ?>
              <div class="hist-card">
                <div class="hist-card-header">
                  <div>
                    <div class="hist-title"><?= h($hist['title']) ?></div>
                    <div class="hist-meta">Archived <?= h(date('M d, Y', strtotime($hist['archived_at']))) ?><?php if ($hist['start_dt'] && $hist['end_dt']): ?> &nbsp;·&nbsp; <?= h(date('M d, Y', strtotime($hist['start_dt']))) ?> → <?= h(date('M d, Y', strtotime($hist['end_dt']))) ?><?php endif; ?></div>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="hist-type-badge <?= $etype === 'clas' ? 'htype-clas' : 'htype-general' ?>"><?= $etype === 'clas' ? '🎓 CLAS' : '🗳️ General' ?></span>
                    <form method="POST" action="admin.php?section=election-history" class="d-inline" onsubmit="return confirmAction('Delete History Entry', 'Are you sure you want to delete this history entry? This cannot be undone.')">
                      <input type="hidden" name="action" value="delete_history"><input type="hidden" name="id" value="<?= $hist['id'] ?>">
                      <button class="btn-st-danger btn-sm-icon" type="submit"><i class="bi bi-trash-fill"></i></button>
                    </form>
                  </div>
                </div>
                <div class="hist-stats">
                  <div class="hist-stat">
                    <div class="hist-stat-num"><?= number_format($vc) ?></div>
                    <div class="hist-stat-lbl">Votes Cast</div>
                  </div>
                  <div class="hist-stat">
                    <div class="hist-stat-num"><?= number_format($tv) ?></div>
                    <div class="hist-stat-lbl">Registered</div>
                  </div>
                  <div class="hist-stat">
                    <div class="hist-stat-num"><?= $turnoutH ?>%</div>
                    <div class="hist-stat-lbl">Turnout</div>
                  </div>
                  <div class="hist-stat">
                    <div class="hist-stat-num"><?= count($positions_hist) ?></div>
                    <div class="hist-stat-lbl">Positions</div>
                  </div>
                </div>
                <?php if ($positions_hist): ?>
                  <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8;margin-bottom:10px"><i class="bi bi-trophy-fill me-1" style="color:#f59e0b"></i> Election Winners</div>
                  <div class="row g-2">
                    <?php foreach ($positions_hist as $ph): $winner = $ph['winner'] ?? null;
                      $wVotes = (int)($ph['winner_votes'] ?? 0); ?>
                      <div class="col-md-6 col-lg-4">
                        <div class="winner-row">
                          <div>
                            <div class="winner-pos"><?= h($ph['name'] ?? '') ?></div><?php if ($winner): ?><div class="winner-name"><?= h($winner) ?></div><?php else: ?><div class="text-muted-sm">No votes</div><?php endif; ?>
                          </div>
                          <?php if ($winner && $wVotes > 0): ?><div class="lr-winner-badge"><i class="bi bi-trophy-fill"></i><?= $wVotes ?> votes</div><?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?><p class="text-muted-sm">No position data available.</p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- ════ AUDIT LOG ════════════════════════════════════════ -->
      <?php elseif ($section === 'audit-log'): ?>
        <?php
        // ── Audit stats ──────────────────────────────────────
        $auditTotal   = (int)db()->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
        $auditToday   = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $auditWeek    = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        $auditLastCat = getLatestAuditCategory();
        ?>

        <!-- ══ AUDIT LOG HEADER BANNER ══ -->
        <div class="al-hero mb-4">
          <div class="al-hero-left">
            <div class="al-hero-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div>
              <div class="al-hero-title">Audit Log</div>
              <div class="al-hero-sub">Full trail of every admin action and system event.</div>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <?php if ($audit_rows): ?>
              <a class="al-btn-export" href="admin.php?section=audit-log&export=csv&q=<?= urlencode($_GET['q'] ?? '') ?>&cat=<?= urlencode($_GET['cat'] ?? 'all') ?>">
                <i class="bi bi-download"></i> Export CSV
              </a>
            <?php endif; ?>
            <form method="POST" action="admin.php?section=audit-log" onsubmit="return confirmAction('Clear All Logs', 'This will permanently delete ALL audit log entries. This cannot be undone.')" class="d-inline">
              <input type="hidden" name="action" value="clear_logs">
              <input type="hidden" name="confirm_clear" value="1">
              <button class="al-btn-danger" type="submit"><i class="bi bi-trash3-fill"></i> Clear All</button>
            </form>
          </div>
        </div>

        <!-- ══ STAT CARDS ══ -->
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="al2-stat-card">
              <div class="al2-stat-glow" style="--gc:#10b981"></div>
              <div class="al2-stat-icon" style="--ic:#10b981;--ib:rgba(16,185,129,.15)"><i class="bi bi-journal-text"></i></div>
              <div class="al2-stat-body">
                <div class="al2-stat-val"><?= number_format($auditTotal) ?></div>
                <div class="al2-stat-lbl">Total Entries</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="al2-stat-card">
              <div class="al2-stat-glow" style="--gc:#3b82f6"></div>
              <div class="al2-stat-icon" style="--ic:#3b82f6;--ib:rgba(59,130,246,.15)"><i class="bi bi-calendar-day-fill"></i></div>
              <div class="al2-stat-body">
                <div class="al2-stat-val"><?= $auditToday ?></div>
                <div class="al2-stat-lbl">Today</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="al2-stat-card">
              <div class="al2-stat-glow" style="--gc:#8b5cf6"></div>
              <div class="al2-stat-icon" style="--ic:#8b5cf6;--ib:rgba(139,92,246,.15)"><i class="bi bi-calendar-week-fill"></i></div>
              <div class="al2-stat-body">
                <div class="al2-stat-val"><?= $auditWeek ?></div>
                <div class="al2-stat-lbl">Last 7 Days</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="al2-stat-card">
              <div class="al2-stat-glow" style="--gc:#f59e0b"></div>
              <div class="al2-stat-icon" style="--ic:#f59e0b;--ib:rgba(245,158,11,.15)"><i class="bi bi-tag-fill"></i></div>
              <div class="al2-stat-body">
                <div class="al2-stat-val" style="font-size:<?= strlen($auditLastCat) > 8 ? '13px' : '22px' ?>"><?= h($auditLastCat) ?></div>
                <div class="al2-stat-lbl">Latest Category</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ TOOLBAR ══ -->
        <div class="al2-toolbar mb-4">
          <form method="GET" action="admin.php" class="al2-toolbar-form">
            <input type="hidden" name="section" value="audit-log">
            <div class="al2-search-wrap">
              <i class="bi bi-search al2-search-icon"></i>
              <input class="al2-search-input" type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search action or details…">
            </div>
            <div class="al2-pills-wrap">
              <?php foreach (
                [
                  'all'          => ['All',      'bi-grid-fill'],
                  'Registration' => ['Reg',      'bi-person-check-fill'],
                  'Voters'       => ['Voters',   'bi-people-fill'],
                  'Candidates'   => ['Cands',    'bi-award-fill'],
                  'Election'     => ['Election', 'bi-check2-square'],
                  'Feedback'     => ['Feedback', 'bi-chat-dots-fill'],
                  'Admin'        => ['Admin',    'bi-shield-fill'],
                ] as $v => [$lbl, $ico]
              ): ?>
                <button type="submit" name="cat" value="<?= $v ?>"
                  class="al2-pill <?= ($_GET['cat'] ?? 'all') === $v ? 'al2-pill-active' : '' ?>">
                  <i class="bi <?= $ico ?>"></i><?= $lbl ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="al2-perpage-wrap">
              <select class="al2-perpage" name="per_page" onchange="this.form.submit()">
                <?php foreach ([25, 50, 100, 200, 500] as $pp): ?>
                  <option value="<?= $pp ?>" <?= (int)($_GET['per_page'] ?? 50) === $pp ? 'selected' : '' ?>><?= $pp ?> / page</option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </div>

        <?php if ($audit_rows): ?>
          <?php
          // ── Pagination ──────────────────────────────────────
          $perPage    = max(10, min(500, (int)($_GET['per_page'] ?? 50)));
          $totalRows  = count($audit_rows);
          $totalPages = max(1, (int)ceil($totalRows / $perPage));
          $curPage    = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
          $offset     = ($curPage - 1) * $perPage;
          $pageRows   = array_slice($audit_rows, $offset, $perPage);

          // ── Category summary ─────────────────────────────────
          $catCounts = [];
          foreach ($audit_rows as $r) {
            $c = $r['category'] ?? 'Other';
            $catCounts[$c] = ($catCounts[$c] ?? 0) + 1;
          }
          arsort($catCounts);
          ?>

          <!-- Summary strip -->
          <div class="al2-summary-strip mb-3">
            <div class="al2-summary-left">
              <span class="al2-summary-total"><i class="bi bi-list-ul"></i><?= number_format($totalRows) ?> entries</span>
              <?php
              $catColors = ['election' => '#10b981', 'student' => '#3b82f6', 'voters' => '#6366f1', 'candidates' => '#ec4899', 'registration' => '#f97316', 'admin' => '#64748b', 'feedback' => '#f59e0b'];
              foreach (array_slice($catCounts, 0, 6, true) as $cat => $cnt):
                $col = $catColors[strtolower($cat)] ?? '#94a3b8';
              ?>
                <span class="al2-cat-chip" style="--cc:<?= $col ?>"><?= h($cat) ?> <b><?= $cnt ?></b></span>
              <?php endforeach; ?>
            </div>
            <span class="al2-page-info">Page <?= $curPage ?> of <?= $totalPages ?></span>
          </div>

          <!-- ── Bulk-action bar ── -->
          <div class="al2-bulk-bar" id="alBulkBar">
            <i class="bi bi-check2-circle"></i>
            <span id="alBulkCount">0 selected</span>
            <form method="POST" action="admin.php?section=audit-log" onsubmit="return alBulkSubmit(this)" class="d-inline">
              <input type="hidden" name="action" value="bulk_delete_logs">
              <input type="hidden" name="bulk_ids" id="alBulkIds">
              <button class="al2-bulk-del" type="submit"><i class="bi bi-trash3-fill"></i> Delete Selected</button>
            </form>
            <button class="al2-bulk-clear" onclick="alClearSel()"><i class="bi bi-x-lg"></i> Deselect</button>
          </div>

          <!-- ══ DESKTOP TABLE ══ -->
          <div class="al2-table-card audit-table-wrap">
            <div class="table-responsive">
              <table class="al2-table">
                <thead>
                  <tr>
                    <th class="al2-th-check"><input type="checkbox" id="alSelectAll" class="al2-checkbox" onchange="alToggleAll(this)"></th>
                    <th class="al2-th-num">#</th>
                    <th><i class="bi bi-clock-history me-1"></i>Timestamp</th>
                    <th>Category</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th class="al2-th-del"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pageRows as $i => $row):
                    $cat = strtolower($row['category'] ?? '');
                    $globalIdx = $offset + $i + 1;
                    $dt = strtotime($row['created_at'] ?? 'now');
                    $catIconMap = ['voters' => 'bi-people-fill', 'candidates' => 'bi-award-fill', 'election' => 'bi-check2-square', 'feedback' => 'bi-chat-dots-fill', 'admin' => 'bi-shield-fill', 'registration' => 'bi-person-check-fill', 'student' => 'bi-mortarboard-fill'];
                    $catIcon = $catIconMap[$cat] ?? 'bi-dot';
                    $catColorMap = ['election' => '#10b981', 'student' => '#3b82f6', 'voters' => '#6366f1', 'candidates' => '#ec4899', 'registration' => '#f97316', 'admin' => '#64748b', 'feedback' => '#f59e0b'];
                    $catCol = $catColorMap[$cat] ?? '#94a3b8';
                  ?>
                    <tr class="al2-row" data-id="<?= $row['id'] ?>">
                      <td class="al2-td-check"><input type="checkbox" class="al2-checkbox al-chk" value="<?= $row['id'] ?>" onchange="alUpdateSel()"></td>
                      <td class="al2-td-num"><?= $globalIdx ?></td>
                      <td class="al2-td-time">
                        <div class="al2-date"><?= h(date('M d, Y', $dt)) ?></div>
                        <div class="al2-time"><?= h(date('h:i:s A', $dt)) ?></div>
                      </td>
                      <td>
                        <span class="al2-cat-badge" style="--cc:<?= $catCol ?>">
                          <i class="bi <?= $catIcon ?>"></i><?= h($row['category'] ?? '') ?>
                        </span>
                      </td>
                      <td>
                        <span class="al2-action-pill"><i class="bi bi-lightning-charge-fill"></i><?= h($row['action'] ?? '') ?></span>
                      </td>
                      <td class="al2-details"><?= h($row['details'] ?? '') ?></td>
                      <td>
                        <form method="POST" action="admin.php?section=audit-log" onsubmit="return confirmAction('Delete Log Entry', 'Are you sure you want to delete this log entry?')" class="d-inline">
                          <input type="hidden" name="action" value="delete_log">
                          <input type="hidden" name="id" value="<?= $row['id'] ?>">
                          <button type="submit" class="al2-del-btn" title="Delete entry"><i class="bi bi-trash3"></i></button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ── Pagination ── -->
          <?php if ($totalPages > 1): ?>
            <div class="al2-pagination mt-3">
              <?php if ($curPage > 1): ?>
                <a href="admin.php?section=audit-log&q=<?= urlencode($_GET['q'] ?? '') ?>&cat=<?= urlencode($_GET['cat'] ?? 'all') ?>&per_page=<?= $perPage ?>&page=<?= $curPage - 1 ?>" class="al2-pg-btn"><i class="bi bi-chevron-left"></i></a>
              <?php endif; ?>
              <?php
              $start = max(1, $curPage - 2);
              $end   = min($totalPages, $curPage + 2);
              if ($start > 1): ?><a href="admin.php?section=audit-log&q=<?= urlencode($_GET['q'] ?? '') ?>&cat=<?= urlencode($_GET['cat'] ?? 'all') ?>&per_page=<?= $perPage ?>&page=1" class="al2-pg-btn">1</a><?php if ($start > 2): ?><span class="al2-pg-ellipsis">…</span><?php endif;
                                                                                                                                                                                                                                                                            endif;
                                                                                                                                                                                                                                                                            for ($p = $start; $p <= $end; $p++): ?>
                <a href="admin.php?section=audit-log&q=<?= urlencode($_GET['q'] ?? '') ?>&cat=<?= urlencode($_GET['cat'] ?? 'all') ?>&per_page=<?= $perPage ?>&page=<?= $p ?>" class="al2-pg-btn <?= $p === $curPage ? 'al2-pg-active' : '' ?>"><?= $p ?></a>
                <?php endfor;
                                                                                                                                                                                                                                                                            if ($end < $totalPages): if ($end < $totalPages - 1): ?><span class="al2-pg-ellipsis">…</span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                          ?><a href="admin.php?section=audit-log&q=<?= urlencode($_GET['q'] ?? '') ?>&cat=<?= urlencode($_GET['cat'] ?? 'all') ?>&per_page=<?= $perPage ?>&page=<?= $totalPages ?>" class="al2-pg-btn"><?= $totalPages ?></a><?php endif; ?>
              <?php if ($curPage < $totalPages): ?>
                <a href="admin.php?section=audit-log&q=<?= urlencode($_GET['q'] ?? '') ?>&cat=<?= urlencode($_GET['cat'] ?? 'all') ?>&per_page=<?= $perPage ?>&page=<?= $curPage + 1 ?>" class="al2-pg-btn"><i class="bi bi-chevron-right"></i></a>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- ══ MOBILE TIMELINE ══ -->
          <div class="al2-mobile-timeline audit-cards-wrap mt-3">
            <?php foreach ($pageRows as $i => $row):
              $cat = strtolower($row['category'] ?? '');
              $dt  = strtotime($row['created_at'] ?? 'now');
              $globalIdx = $offset + $i + 1;
              $catColorMap2 = ['election' => '#10b981', 'student' => '#3b82f6', 'voters' => '#6366f1', 'candidates' => '#ec4899', 'registration' => '#f97316', 'admin' => '#64748b', 'feedback' => '#f59e0b'];
              $col2 = $catColorMap2[$cat] ?? '#94a3b8';
            ?>
              <div class="al2-tl-item">
                <div class="al2-tl-line"></div>
                <div class="al2-tl-dot" style="background:<?= $col2 ?>"></div>
                <div class="al2-tl-card">
                  <div class="al2-tl-top">
                    <span class="al2-cat-badge" style="--cc:<?= $col2 ?>"><?= h($row['category'] ?? '') ?></span>
                    <span class="al2-tl-action"><?= h($row['action'] ?? '') ?></span>
                    <span class="al2-tl-time"><i class="bi bi-clock"></i> <?= h(date('M d · g:i A', $dt)) ?></span>
                    <form method="POST" action="admin.php?section=audit-log" onsubmit="return confirmAction('Delete Entry', 'Are you sure you want to delete this entry?')" class="d-inline">
                      <input type="hidden" name="action" value="delete_log">
                      <input type="hidden" name="id" value="<?= $row['id'] ?>">
                      <button type="submit" class="al2-del-btn"><i class="bi bi-trash3"></i></button>
                    </form>
                  </div>
                  <?php if (!empty($row['details'])): ?>
                    <div class="al2-tl-details"><?= h($row['details']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        <?php else: ?>
          <div class="al2-empty-state">
            <div class="al2-empty-icon"><i class="bi bi-clock-history"></i></div>
            <div class="al2-empty-title">No log entries found</div>
            <div class="al2-empty-sub">Actions you take will appear here.</div>
          </div>
        <?php endif; ?>

        <style>
          /* ══════════════════════════════════════════════════
             AUDIT LOG v3 — Premium Redesign
          ══════════════════════════════════════════════════ */

          /* ── Hero Banner ── */
          .al-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, .18);
          }

          .al-hero-left {
            display: flex;
            align-items: center;
            gap: 14px;
          }

          .al-hero-icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #fff;
            box-shadow: 0 4px 14px rgba(16, 185, 129, .35);
            flex-shrink: 0;
          }

          .al-hero-title {
            font-size: 20px;
            font-weight: 800;
            color: #f1f5f9;
            letter-spacing: -.3px;
          }

          .al-hero-sub {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 2px;
          }

          .al-btn-export {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 9px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .14);
            color: #e2e8f0;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all .18s;
            cursor: pointer;
          }

          .al-btn-export:hover {
            background: rgba(255, 255, 255, .14);
            color: #fff;
          }

          .al-btn-danger {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 9px;
            background: rgba(239, 68, 68, .15);
            border: 1px solid rgba(239, 68, 68, .3);
            color: #fca5a5;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all .18s;
          }

          .al-btn-danger:hover {
            background: rgba(239, 68, 68, .25);
            color: #fff;
          }

          /* ── Stat Cards ── */
          .al2-stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            position: relative;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
          }

          .al2-stat-card:hover {
            box-shadow: 0 6px 24px rgba(0, 0, 0, .09);
            transform: translateY(-2px);
          }

          .al2-stat-glow {
            position: absolute;
            top: -20px;
            right: -20px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--gc) 0%, transparent 70%);
            opacity: .12;
            pointer-events: none;
          }

          .al2-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--ib);
            color: var(--ic);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
          }

          .al2-stat-body {}

          .al2-stat-val {
            font-size: 24px;
            font-weight: 800;
            color: var(--text);
            line-height: 1.1;
            letter-spacing: -.5px;
          }

          .al2-stat-lbl {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 500;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: .04em;
          }

          /* ── Toolbar ── */
          .al2-toolbar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
            box-shadow: var(--shadow-sm);
          }

          .al2-toolbar-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
          }

          .al2-search-wrap {
            position: relative;
            flex: 1;
            min-width: 160px;
            max-width: 300px;
          }

          .al2-search-icon {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 13px;
            pointer-events: none;
          }

          .al2-search-input {
            width: 100%;
            padding: 8px 12px 8px 32px;
            border-radius: 9px;
            border: 1.5px solid var(--border);
            background: var(--surface-2, #f8fafc);
            color: var(--text);
            font-size: 13px;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
          }

          .al2-search-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
          }

          .al2-pills-wrap {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
          }

          .al2-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
          }

          .al2-pill:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(16, 185, 129, .06);
          }

          .al2-pill.al2-pill-active {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff;
          }

          .al2-perpage-wrap {
            margin-left: auto;
          }

          .al2-perpage {
            padding: 7px 10px;
            border-radius: 9px;
            border: 1.5px solid var(--border);
            background: var(--surface-2, #f8fafc);
            color: var(--text);
            font-size: 13px;
            cursor: pointer;
            outline: none;
          }

          /* ── Summary Strip ── */
          .al2-summary-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
          }

          .al2-summary-left {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
          }

          .al2-summary-total {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 4px 12px;
          }

          .al2-cat-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            background: color-mix(in srgb, var(--cc) 12%, transparent);
            color: var(--cc);
            border: 1px solid color-mix(in srgb, var(--cc) 25%, transparent);
          }

          .al2-cat-chip b {
            font-weight: 800;
          }

          .al2-page-info {
            font-size: 12px;
            color: var(--text-muted);
          }

          /* ── Bulk Bar ── */
          .al2-bulk-bar {
            display: none;
            align-items: center;
            gap: 10px;
            background: rgba(59, 130, 246, .07);
            border: 1.5px solid rgba(59, 130, 246, .2);
            border-radius: var(--radius);
            padding: 9px 16px;
            margin-bottom: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #3b82f6;
            animation: bulkIn .15s ease;
          }

          @keyframes bulkIn {
            from {
              opacity: 0;
              transform: translateY(-6px);
            }

            to {
              opacity: 1;
              transform: none;
            }
          }

          .al2-bulk-bar.al-bulk-visible {
            display: flex;
          }

          .al2-bulk-del {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 7px;
            background: rgba(239, 68, 68, .1);
            border: 1px solid rgba(239, 68, 68, .25);
            color: #ef4444;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all .15s;
          }

          .al2-bulk-del:hover {
            background: #ef4444;
            color: #fff;
          }

          .al2-bulk-clear {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 7px;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all .15s;
          }

          .al2-bulk-clear:hover {
            border-color: var(--text-muted);
            color: var(--text);
          }

          /* ── Table ── */
          .al2-table-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
          }

          .al2-table {
            width: 100%;
            border-collapse: collapse;
          }

          .al2-table thead tr {
            background: var(--surface-2, #f8fafc);
            border-bottom: 2px solid var(--border);
          }

          .al2-table thead th {
            padding: 11px 14px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 700;
            color: var(--text-muted);
            white-space: nowrap;
          }

          .al2-th-check {
            width: 36px;
          }

          .al2-th-num {
            width: 44px;
          }

          .al2-th-del {
            width: 46px;
          }

          .al2-table tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .1s;
          }

          .al2-table tbody tr:last-child {
            border-bottom: none;
          }

          .al2-table tbody tr:hover {
            background: rgba(16, 185, 129, .035);
          }

          .al2-table tbody td {
            padding: 11px 14px;
            vertical-align: middle;
          }

          .al2-td-check {
            width: 36px;
          }

          .al2-td-num {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-muted);
          }

          .al2-td-time {}

          .al2-date {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text);
          }

          .al2-time {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 1px;
          }

          .al2-cat-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 700;
            background: color-mix(in srgb, var(--cc) 12%, transparent);
            color: var(--cc);
            border: 1px solid color-mix(in srgb, var(--cc) 22%, transparent);
            white-space: nowrap;
          }

          .al2-action-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text);
          }

          .al2-action-pill .bi-lightning-charge-fill {
            font-size: 10px;
            color: var(--accent);
            opacity: .7;
          }

          .al2-details {
            font-size: 12px;
            color: var(--text-muted);
            max-width: 320px;
            word-break: break-word;
          }

          /* Checkbox */
          .al2-checkbox {
            width: 15px;
            height: 15px;
            cursor: pointer;
            accent-color: var(--accent);
          }

          /* Delete btn */
          .al2-del-btn {
            background: none;
            border: 1.5px solid transparent;
            color: var(--text-muted);
            border-radius: 7px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            transition: all .15s;
            padding: 0;
          }

          .al2-del-btn:hover {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #dc2626;
          }

          /* ── Pagination ── */
          .al2-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
          }

          .al2-pg-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 11px;
            border-radius: 9px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all .15s;
          }

          .al2-pg-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(16, 185, 129, .05);
          }

          .al2-pg-btn.al2-pg-active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            box-shadow: 0 3px 10px rgba(16, 185, 129, .3);
          }

          .al2-pg-ellipsis {
            color: var(--text-muted);
            font-size: 13px;
            padding: 0 4px;
          }

          /* ── Mobile Timeline ── */
          .al2-mobile-timeline {
            display: none;
          }

          .al2-tl-item {
            position: relative;
            padding-left: 28px;
            margin-bottom: 10px;
          }

          .al2-tl-line {
            position: absolute;
            left: 7px;
            top: 0;
            bottom: -10px;
            width: 2px;
            background: var(--border);
          }

          .al2-tl-item:last-child .al2-tl-line {
            display: none;
          }

          .al2-tl-dot {
            position: absolute;
            left: 2px;
            top: 14px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid var(--surface);
            box-shadow: 0 0 0 1px var(--border);
          }

          .al2-tl-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 12px 14px;
            box-shadow: var(--shadow-sm);
          }

          .al2-tl-top {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
          }

          .al2-tl-action {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text);
          }

          .al2-tl-time {
            font-size: 11px;
            color: var(--text-muted);
            margin-left: auto;
          }

          .al2-tl-details {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 6px;
          }

          /* ── Empty State ── */
          .al2-empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
          }

          .al2-empty-icon {
            font-size: 42px;
            color: var(--text-muted);
            opacity: .35;
            margin-bottom: 12px;
          }

          .al2-empty-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
          }

          .al2-empty-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
          }

          /* ── Dark Mode Overrides ── */
          body.dark-mode .al2-stat-card,
          body.dark-mode .al2-toolbar,
          body.dark-mode .al2-table-card,
          body.dark-mode .al2-tl-card,
          body.dark-mode .al2-summary-total,
          body.dark-mode .al2-empty-state {
            background: #161b22;
            border-color: #30363d;
          }

          body.dark-mode .al2-search-input,
          body.dark-mode .al2-perpage {
            background: #0d1117;
            border-color: #30363d;
            color: #c9d1d9;
          }

          body.dark-mode .al2-search-input:focus {
            border-color: var(--accent);
          }

          body.dark-mode .al2-pill {
            border-color: rgba(255, 255, 255, .12);
            background: rgba(255, 255, 255, .04);
          }

          body.dark-mode .al2-pill:hover {
            background: rgba(16, 185, 129, .08);
          }

          body.dark-mode .al2-table thead tr {
            background: rgba(255, 255, 255, .03);
          }

          body.dark-mode .al2-table tbody tr:hover {
            background: rgba(16, 185, 129, .05);
          }

          body.dark-mode .al2-pg-btn {
            background: #161b22;
            border-color: #30363d;
            color: #c9d1d9;
          }

          body.dark-mode .al2-del-btn:hover {
            background: rgba(239, 68, 68, .12);
            border-color: rgba(239, 68, 68, .3);
          }

          body.dark-mode .al2-tl-dot {
            border-color: #161b22;
          }

          /* ── Dark mode: ensure all audit log text is light ── */
          body.dark-mode .al2-date,
          body.dark-mode .al2-stat-val,
          body.dark-mode .al2-summary-total,
          body.dark-mode .al2-action-pill,
          body.dark-mode .al2-tl-action,
          body.dark-mode .al2-empty-title,
          body.dark-mode .al2-table thead th,
          body.dark-mode .al2-table tbody td,
          body.dark-mode .al2-td-num,
          body.dark-mode .al2-pg-btn,
          body.dark-mode .al-hero-title {
            color: #e6edf3;
          }

          body.dark-mode .al2-time,
          body.dark-mode .al2-stat-lbl,
          body.dark-mode .al2-page-info,
          body.dark-mode .al2-details,
          body.dark-mode .al2-tl-details,
          body.dark-mode .al2-tl-time,
          body.dark-mode .al2-empty-sub {
            color: #8b949e;
          }

          body.dark-mode .al2-bulk-bar {
            background: rgba(59, 130, 246, .1);
            border-color: rgba(59, 130, 246, .25);
            color: #93c5fd;
          }

          /* ── Responsive ── */
          @media (max-width: 768px) {
            .al2-table-card.audit-table-wrap {
              display: none;
            }

            .al2-mobile-timeline {
              display: block;
            }

            .al2-toolbar-form {
              flex-direction: column;
              align-items: stretch;
            }

            .al2-search-wrap {
              max-width: 100%;
            }

            .al2-perpage-wrap {
              margin-left: 0;
            }

            .al-hero {
              padding: 16px 18px;
            }

            .al-hero-title {
              font-size: 17px;
            }
          }
        </style>

        <script>
          function alToggleAll(cb) {
            document.querySelectorAll('.al-chk').forEach(c => {
              c.checked = cb.checked;
            });
            alUpdateSel();
          }

          function alUpdateSel() {
            const checked = document.querySelectorAll('.al-chk:checked');
            const bar = document.getElementById('alBulkBar');
            document.getElementById('alBulkCount').textContent = checked.length + ' selected';
            bar.classList.toggle('al-bulk-visible', checked.length > 0);
          }

          function alClearSel() {
            document.querySelectorAll('.al-chk, #alSelectAll').forEach(c => c.checked = false);
            alUpdateSel();
          }

          function alBulkSubmit(form) {
            const ids = [...document.querySelectorAll('.al-chk:checked')].map(c => c.value).join(',');
            if (!ids) {
              alert('No entries selected.');
              return false;
            }
            document.getElementById('alBulkIds').value = ids;
            return confirm('Delete ' + document.querySelectorAll('.al-chk:checked').length + ' selected entries?');
          }
        </script>

        <!-- ════ FEEDBACK ════════════════════════════════════════ -->
      <?php elseif ($section === 'feedback'): ?>
        <div class="d-flex gap-2 mb-4">
          <?php foreach (['all' => "All ({$fb_total})", 'unread' => "Unread ({$fb_unread})", 'read' => "Read ({$fb_read})"] as $k => $lb): ?>
            <a class="st-badge <?= $fb_filter === $k ? 'stb-green' : 'stb-gray' ?>" href="admin.php?section=feedback&fb=<?= $k ?>" style="padding:7px 16px;font-size:12.5px;cursor:pointer;text-decoration:none"><?= $lb ?></a>
          <?php endforeach; ?>
        </div>
        <?php if ($reply_target): ?>
          <div class="st-card mb-4">
            <div class="st-card-header">
              <div class="st-card-title"><i class="bi bi-reply-fill"></i> Reply to Feedback</div>
            </div>
            <div class="st-card-body">
              <div style="background:#f8fafc;border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:13.5px;color:#475569"><strong><?= h($reply_target['name'] ?? 'Anonymous') ?>:</strong> <?= h($reply_target['message'] ?? '') ?></div>
              <form method="POST" action="admin.php?section=feedback"><input type="hidden" name="action" value="reply_feedback"><input type="hidden" name="id" value="<?= $reply_target['id'] ?>">
                <div class="mb-3"><label class="st-label">Your Reply</label><textarea class="st-input" name="reply" rows="4"><?= h($reply_target['reply'] ?? '') ?></textarea></div>
                <div class="d-flex gap-2"><button class="btn-st-primary" type="submit"><i class="bi bi-send-fill"></i> Send Reply</button><a class="btn-st-muted" href="admin.php?section=feedback"><i class="bi bi-x-lg"></i> Cancel</a></div>
              </form>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($feedback_rows): ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($feedback_rows as $fb): $stars = str_repeat('★', (int)($fb['rating'] ?? 0)) . str_repeat('☆', 5 - (int)($fb['rating'] ?? 0)); ?>
              <div class="fb-card <?= $fb['is_read'] ? '' : 'unread' ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <div>
                    <div style="font-weight:700;font-size:14.5px"><?= h($fb['name'] ?? 'Anonymous') ?></div>
                    <div class="text-muted-sm"><?= h($fb['created_at'] ?? '') ?><?php if ($fb['category']): ?> · <strong><?= h($fb['category']) ?></strong><?php endif; ?></div>
                  </div>
                  <span class="fb-stars"><?= $stars ?></span>
                </div>
                <p style="font-size:13.5px;color:#475569;line-height:1.6"><?= h($fb['message'] ?? '') ?></p>
                <?php if ($fb['reply']): ?><div class="fb-reply-box mt-2"><strong style="font-size:9.5px;letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:4px">Admin Reply</strong><?= h($fb['reply']) ?></div><?php endif; ?>
                <div class="d-flex gap-2 mt-3 flex-wrap">
                  <?php if (!$fb['is_read']): ?><form method="POST" action="admin.php?section=feedback&fb=<?= $fb_filter ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= $fb['id'] ?>"><button class="btn-st-muted btn-sm-icon" type="submit"><i class="bi bi-check2"></i> Mark Read</button></form><?php endif; ?>
                  <a class="btn-st-muted btn-sm-icon" href="admin.php?section=feedback&fb=<?= $fb_filter ?>&reply=<?= $fb['id'] ?>"><i class="bi bi-reply-fill"></i> Reply</a>
                  <form method="POST" action="admin.php?section=feedback&fb=<?= $fb_filter ?>" onsubmit="return confirmAction('Delete?', 'Are you sure you want to permanently delete this? This cannot be undone.')"><input type="hidden" name="action" value="delete_feedback"><input type="hidden" name="id" value="<?= $fb['id'] ?>"><button class="btn-st-danger btn-sm-icon" type="submit"><i class="bi bi-trash-fill"></i></button></form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="st-card">
            <div class="st-empty">
              <div class="st-empty-icon"><i class="bi bi-chat-dots"></i></div>
              <div class="st-empty-text">No feedback yet.</div>
            </div>
          </div><?php endif; ?>

        <!-- ════ MY ACCOUNT ══════════════════════════════════════ -->
      <?php elseif ($section === 'account'): ?>
        <div class="row justify-content-center">
          <div class="col-md-6">
            <div class="st-card">
              <div class="st-card-body">
                <div class="acc-avatar"><?= $initials ?></div>
                <div class="text-center mb-4 text-muted-sm">Logged in as <strong style="color:#0f172a"><?= $adminUser ?></strong> · Administrator</div>
                <form method="POST" action="admin.php?section=account"><input type="hidden" name="action" value="save_account">
                  <div class="row g-3">
                    <div class="col-6"><label class="st-label">Full Name <span class="req">*</span></label><input class="st-input" type="text" name="full_name" value="<?= h($admin_row['full_name'] ?? $_SESSION['admin_name'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="st-label">Username <span class="req">*</span></label><input class="st-input" type="text" name="username" value="<?= h($admin_row['username'] ?? $_SESSION['admin_username'] ?? '') ?>" required></div>
                    <div class="col-12"><label class="st-label">Email <span class="req">*</span></label><input class="st-input" type="email" name="email" value="<?= h($admin_row['email'] ?? $_SESSION['admin_email'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="st-label">New Password</label><input class="st-input" type="password" name="new_password" placeholder="Min. 6 chars"></div>
                    <div class="col-6"><label class="st-label">Confirm Password</label><input class="st-input" type="password" name="confirm_password"></div>
                  </div>
                  <div class="form-divider"></div>
                  <button class="btn-st-primary" type="submit"><i class="bi bi-save-fill"></i> Save Changes</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const generalPositions = <?php
                              $gpNames = [];
                              foreach ($allPositions as $p) {
                                if ($p['election_type'] === 'general') $gpNames[] = $p['name'];
                              }
                              echo json_encode($gpNames);
                              ?>;

    const clasPositions = <?php
                          $cpNames = [];
                          foreach ($allPositions as $p) {
                            if ($p['election_type'] === 'clas') $cpNames[] = $p['name'];
                          }
                          echo json_encode($cpNames);
                          ?>;

    function filterPositionDropdown(electionType) {
      const select = document.getElementById('cand_position_select');
      select.innerHTML = '<option value="">— Select a position —</option>';
      const positions = electionType === 'clas' ? clasPositions : generalPositions;
      positions.forEach(name => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        select.appendChild(opt);
      });
      select.classList.toggle('clas-input', electionType === 'clas');
      document.getElementById('cand_position_hidden').value = '';
      updateSectionHint('');
      // Show Program & Section only for CLAS
      const psWrap = document.getElementById('cand_program_section_wrap');
      if (psWrap) psWrap.style.display = electionType === 'clas' ? 'block' : 'none';
    }

    function validateCandForm(form) {
      const pos = document.getElementById('cand_position_hidden').value.trim();
      const name = document.getElementById('cand_name').value.trim();
      if (!name) {
        alert('Please enter the candidate\'s full name.');
        document.getElementById('cand_name').focus();
        return false;
      }
      if (!pos) {
        const sel = document.getElementById('cand_position_select');
        if (sel && sel.value) {
          document.getElementById('cand_position_hidden').value = sel.value;
          return true;
        }
        alert('Please select a position for the candidate.');
        document.getElementById('cand_position_select').focus();
        return false;
      }
      return true;
    }

    function updateSectionHint(positionName) {
      const hint = document.getElementById('cand_section_hint');
      const label = document.getElementById('cand_section_label');
      const etype = document.getElementById('cand_etype').value;
      const isRep = etype === 'clas' && positionName.toLowerCase().includes('representative');
      const isClas = etype === 'clas';
      if (hint) {
        if (isClas) {
          hint.style.display = 'block';
          if (isRep) {
            hint.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> <strong>1 candidate per section rule:</strong> Each section (e.g. 1A, 2A) can only have <strong>1 active candidate</strong> for this Representative position.';
            hint.style.background = '#fefce8';
            hint.style.borderColor = '#fde68a';
            hint.style.color = '#92400e';
          } else {
            hint.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> <strong>1 candidate per year rule:</strong> Each year level (1st, 2nd, 3rd, 4th) can only have <strong>1 active candidate</strong> for this council position.';
            hint.style.background = '#eff6ff';
            hint.style.borderColor = '#bfdbfe';
            hint.style.color = '#1e40af';
          }
        } else {
          hint.style.display = 'none';
        }
      }
      if (label) {
        label.innerHTML = isClas ?
          'Section <span class="req">*</span> <span style="font-size:10px;font-weight:600;color:#6366f1">' + (isRep ? '(1 per section)' : '(1 per year level)') + '</span>' :
          'Section';
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const sel = document.getElementById('cand_position_select');
      if (sel) {
        sel.addEventListener('change', function() {
          document.getElementById('cand_position_hidden').value = this.value;
          updateSectionHint(this.value);
        });
        filterPositionDropdown('general');
      }
    });

    function previewCandPhoto(input) {
      const preview = document.getElementById('cand_photo_preview');
      if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
          preview.innerHTML = '<img src="' + e.target.result + '" alt="">';
        };
        reader.readAsDataURL(input.files[0]);
      }
    }

    function openVoterModal() {
      document.getElementById('voterModalTitle').innerHTML = '<i class="bi bi-person-plus-fill"></i> Add New Voter';
      document.getElementById('voterSaveLabel').textContent = 'Add Voter';
      document.getElementById('voter_id').value = '';
      ['voter_student_id', 'voter_first_name', 'voter_last_name', 'voter_email', 'voter_birthdate'].forEach(id => document.getElementById(id).value = '');
      document.getElementById('voter_status').value = 'Pending';
      document.getElementById('voter_pw_note').style.display = 'block';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('voterModal')).show();
    }

    function openEditVoter(id, data) {
      document.getElementById('voterModalTitle').innerHTML = '<i class="bi bi-pencil-fill"></i> Edit Voter';
      document.getElementById('voterSaveLabel').textContent = 'Save Changes';
      document.getElementById('voter_id').value = id;
      document.getElementById('voter_student_id').value = data.student_id;
      document.getElementById('voter_first_name').value = data.first_name;
      document.getElementById('voter_last_name').value = data.last_name;
      document.getElementById('voter_email').value = data.email;
      document.getElementById('voter_birthdate').value = data.birthdate || '';
      document.getElementById('voter_status').value = data.approval_status || 'Pending';
      document.getElementById('voter_pw_note').style.display = 'none';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('voterModal')).show();
    }

    // ── Student autocomplete search ──
    var _searchTimer = null;

    function searchStudents(q) {
      clearTimeout(_searchTimer);
      var dd = document.getElementById('student_dropdown');
      if (!q || q.length < 2) {
        dd.style.display = 'none';
        return;
      }
      _searchTimer = setTimeout(function() {
        fetch('admin.php?api=search_students&q=' + encodeURIComponent(q))
          .then(function(r) {
            return r.json();
          })
          .then(function(students) {
            if (!students.length) {
              dd.innerHTML = '<div style="padding:12px 16px;color:#94a3b8;font-size:13px">No registered students found.</div>';
            } else {
              dd.innerHTML = students.map(function(s) {
                var fullName = s.first_name + ' ' + s.last_name;
                return '<div onclick="selectStudent(' + JSON.stringify(s).replace(/"/g, "&quot;") + ')" style="padding:10px 16px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13.5px;color:var(--text);transition:background .1s" onmouseover="this.style.background=\'var(--surface-2)\'" onmouseout="this.style.background=\'\'"><strong>' + fullName + '</strong> <span style="color:#94a3b8;font-size:12px">· ' + s.student_id + '</span></div>';
              }).join('');
            }
            dd.style.display = 'block';
          })
          .catch(function() {
            dd.style.display = 'none';
          });
      }, 280);
    }

    function selectStudent(s) {
      var fullName = s.first_name + ' ' + s.last_name;
      document.getElementById('cand_name').value = fullName;
      document.getElementById('student_search_input').value = fullName;
      document.getElementById('student_dropdown').style.display = 'none';
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      var dd = document.getElementById('student_dropdown');
      var wrap = document.getElementById('student_search_wrap');
      if (dd && wrap && !wrap.contains(e.target)) dd.style.display = 'none';
    });

    function openCandidateModal() {
      document.getElementById('candidateModalTitle').innerHTML = '<i class="bi bi-plus-circle-fill"></i> Add New Candidate';
      document.getElementById('candSaveLabel').textContent = 'Add Candidate';
      document.getElementById('cand_id').value = '';
      document.getElementById('cand_name').value = '';
      document.getElementById('cand_motto').value = '';
      document.getElementById('cand_program').value = '';
      document.getElementById('cand_section').value = '';
      document.getElementById('cand_etype').value = 'general';
      document.getElementById('cand_photo_input').value = '';
      document.getElementById('cand_photo_preview').innerHTML = '<i class="bi bi-person-fill"></i>';
      // Clear student search
      var si = document.getElementById('student_search_input');
      if (si) si.value = '';
      var dd = document.getElementById('student_dropdown');
      if (dd) dd.style.display = 'none';
      filterPositionDropdown('general');
      // general = hide program/section
      var psWrap = document.getElementById('cand_program_section_wrap');
      if (psWrap) psWrap.style.display = 'none';
      updateSectionHint('');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('candidateModal')).show();
    }

    function openEditCandidate(id, data) {
      document.getElementById('candidateModalTitle').innerHTML = '<i class="bi bi-pencil-fill"></i> Edit Candidate';
      document.getElementById('candSaveLabel').textContent = 'Save Changes';
      document.getElementById('cand_id').value = id;
      document.getElementById('cand_name').value = data.full_name;
      document.getElementById('cand_motto').value = data.motto || '';
      document.getElementById('cand_program').value = data.program || '';
      document.getElementById('cand_section').value = data.section || '';
      document.getElementById('cand_photo_input').value = '';

      const preview = document.getElementById('cand_photo_preview');
      if (data.photo) {
        preview.innerHTML = '<img src="' + data.photo + '?v=' + Date.now() + '" alt="">';
      } else {
        preview.innerHTML = '<i class="bi bi-person-fill"></i>';
      }

      const etype = data.election_type || 'general';
      document.getElementById('cand_etype').value = etype;
      filterPositionDropdown(etype);

      // Defer selection until after filterPositionDropdown has populated the options
      setTimeout(function() {
        const sel = document.getElementById('cand_position_select');
        const posName = data.position_name || '';
        for (let i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === posName) {
            sel.selectedIndex = i;
            break;
          }
        }
        document.getElementById('cand_position_hidden').value = posName;
        updateSectionHint(posName);
      }, 0);

      bootstrap.Modal.getOrCreateInstance(document.getElementById('candidateModal')).show();
    }

    document.addEventListener('DOMContentLoaded', function() {
      if (localStorage.getItem('suffraDark') === '1') {
        document.body.classList.add('dark-mode');
        document.documentElement.classList.remove('dark-mode-early');
        const ic = document.getElementById('dmIcon');
        if (ic) {
          ic.classList.remove('bi-moon-fill');
          ic.classList.add('bi-sun-fill');
        }
      }
    });

    // ── Live clock in topbar ──
    (function() {
      var clockEl = document.getElementById('topbar-clock');
      if (!clockEl) return;
      if (window.innerWidth >= 768) clockEl.style.display = '';

      function tick() {
        var now = new Date();
        var h = now.getHours(),
          m = now.getMinutes(),
          s = now.getSeconds();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        clockEl.textContent = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s + ' ' + ampm;
      }
      tick();
      setInterval(tick, 1000);
    })();

    function toggleDarkMode() {
      const isDark = document.body.classList.toggle('dark-mode');
      const icon = document.getElementById('dmIcon');
      const btn = document.getElementById('dmToggle');

      // Ripple effect
      btn.classList.remove('ripple');
      void btn.offsetWidth; // reflow
      btn.classList.add('ripple');

      // Spin icon out, swap, spin in
      btn.classList.add('spinning');
      setTimeout(function() {
        if (isDark) {
          icon.classList.remove('bi-moon-fill');
          icon.classList.add('bi-sun-fill');
          localStorage.setItem('suffraDark', '1');
        } else {
          icon.classList.remove('bi-sun-fill');
          icon.classList.add('bi-moon-fill');
          localStorage.setItem('suffraDark', '0');
        }
        btn.classList.remove('spinning');
      }, 220);
    }

    function toggleSidebar() {
      const sb = document.getElementById('stSidebar');
      const ov = document.getElementById('sbOverlay');
      const tog = document.getElementById('sbToggle');
      sb.classList.toggle('sb-open');
      ov.classList.toggle('active');
      tog.innerHTML = sb.classList.contains('sb-open') ?
        '<i class="bi bi-x-lg"></i>' :
        '<i class="bi bi-list"></i>';
    }

    function closeSidebar() {
      const sb = document.getElementById('stSidebar');
      const ov = document.getElementById('sbOverlay');
      const tog = document.getElementById('sbToggle');
      sb.classList.remove('sb-open');
      ov.classList.remove('active');
      if (tog) tog.innerHTML = '<i class="bi bi-list"></i>';
    }

    document.addEventListener('DOMContentLoaded', function() {
      // Auto-close sidebar on mobile nav click
      if (window.innerWidth <= 992) {
        document.querySelectorAll('.sb-nav-link, .sb-logout').forEach(function(link) {
          link.addEventListener('click', closeSidebar);
        });
      }
      // Close sidebar on ESC
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
      });
    });
  </script>

  <!-- ── Logout Confirmation Modal ── -->
  <div id="logoutModal" style="
    display:none;
    position:fixed;inset:0;
    z-index:9999;
    align-items:center;
    justify-content:center;
  ">
    <!-- Backdrop -->
    <div id="logoutBackdrop" onclick="closeLogoutModal()" style="
      position:absolute;inset:0;
      background:rgba(0,0,0,.55);
      backdrop-filter:blur(4px);
      opacity:0;
      transition:opacity .3s ease;
    "></div>
    <!-- Card -->
    <div id="logoutCard" style="
      position:relative;
      background:var(--surface,#fff);
      border-radius:20px;
      padding:36px 32px 28px;
      width:min(360px,90vw);
      box-shadow:0 24px 80px rgba(0,0,0,.22);
      text-align:center;
      transform:scale(.85) translateY(24px);
      opacity:0;
      transition:transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s ease;
    ">
      <!-- Icon -->
      <div style="
        width:64px;height:64px;border-radius:50%;
        background:linear-gradient(135deg,#fef2f2,#fee2e2);
        display:flex;align-items:center;justify-content:center;
        margin:0 auto 18px;
        font-size:28px;color:#ef4444;
        box-shadow:0 4px 16px rgba(239,68,68,.2);
      "><i class="bi bi-box-arrow-right"></i></div>
      <!-- Title -->
      <div style="
        font-family:var(--font-display);
        font-size:18px;font-weight:700;
        color:var(--text,#0f172a);
        margin-bottom:8px;
      ">Sign Out?</div>
      <!-- Subtitle -->
      <div style="
        font-size:13.5px;color:var(--text-muted,#94a3b8);
        margin-bottom:28px;line-height:1.5;
      ">You will be logged out of the admin panel. Any unsaved changes will be lost.</div>
      <!-- Buttons -->
      <div style="display:flex;gap:10px;justify-content:center;">
        <button onclick="closeLogoutModal()" style="
          flex:1;padding:10px 0;
          border-radius:10px;border:1px solid #e2e8f0;
          background:var(--surface-2,#f8fafc);
          color:var(--text,#0f172a);
          font-size:13.5px;font-weight:600;cursor:pointer;
          transition:background .15s,border-color .15s;
        " onmouseover="this.style.background='#eef2f8'" onmouseout="this.style.background='var(--surface-2,#f8fafc)'">
          Cancel
        </button>
        <a href="admin.php?logout=1" id="logoutConfirmBtn" style="
          flex:1;padding:10px 0;
          border-radius:10px;border:none;
          background:linear-gradient(135deg,#ef4444,#dc2626);
          color:#fff;font-size:13.5px;font-weight:600;
          cursor:pointer;text-decoration:none;
          display:flex;align-items:center;justify-content:center;gap:6px;
          box-shadow:0 4px 14px rgba(239,68,68,.35);
          transition:opacity .15s,transform .1s;
        " onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
          <i class="bi bi-box-arrow-right"></i> Sign Out
        </a>
      </div>
    </div>
  </div>

  <style>
    body.dark-mode #logoutCard {
      background: #161b22 !important;
    }

    .sb-logout {
      background: none;
      border: none;
      width: 100%;
      cursor: pointer;
      font: inherit;
    }
  </style>

  <script>
    function openLogoutModal() {
      var modal = document.getElementById('logoutModal');
      var backdrop = document.getElementById('logoutBackdrop');
      var card = document.getElementById('logoutCard');
      modal.style.display = 'flex';
      // Force reflow
      void modal.offsetWidth;
      backdrop.style.opacity = '1';
      card.style.transform = 'scale(1) translateY(0)';
      card.style.opacity = '1';
      // Close on Escape
      document._logoutEsc = function(e) {
        if (e.key === 'Escape') closeLogoutModal();
      };
      document.addEventListener('keydown', document._logoutEsc);
    }

    function closeLogoutModal() {
      var modal = document.getElementById('logoutModal');
      var backdrop = document.getElementById('logoutBackdrop');
      var card = document.getElementById('logoutCard');
      backdrop.style.opacity = '0';
      card.style.transform = 'scale(.85) translateY(24px)';
      card.style.opacity = '0';
      setTimeout(function() {
        modal.style.display = 'none';
      }, 320);
      document.removeEventListener('keydown', document._logoutEsc);
    }
  </script>
  <!-- admin.js removed - functions are inline -->

  <!-- ══ Custom Confirm Modal ══════════════════════════════════ -->
  <div id="confirmModal" style="display:none;position:fixed;inset:0;z-index:10000;align-items:center;justify-content:center;">
    <div id="confirmBackdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);opacity:0;transition:opacity .3s ease;"></div>
    <div id="confirmCard" style="position:relative;background:#fff;border-radius:22px;padding:36px 32px 28px;width:min(420px,92vw);box-shadow:0 28px 80px rgba(0,0,0,.25);text-align:center;transform:scale(.85) translateY(28px);opacity:0;transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .3s ease;">
      <div id="confirmIcon" style="width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,#fef2f2,#fee2e2);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:30px;color:#ef4444;box-shadow:0 6px 20px rgba(239,68,68,.22);"></div>
      <div id="confirmTitle" style="font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:800;color:#0f172a;margin-bottom:8px;"></div>
      <div id="confirmMessage" style="font-size:14px;color:#64748b;margin-bottom:28px;line-height:1.6;"></div>
      <div style="display:flex;gap:10px;justify-content:center;">
        <button onclick="closeConfirmModal(false)" style="flex:1;padding:12px 0;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#0f172a;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='#eef2f8'" onmouseout="this.style.background='#f8fafc'">Cancel</button>
        <button id="confirmOkBtn" onclick="closeConfirmModal(true)" style="flex:1;padding:12px 0;border-radius:12px;border:none;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(239,68,68,.35);transition:opacity .15s;" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">Confirm</button>
      </div>
    </div>
  </div>

  <!-- ══ Reset Votes Modal ═════════════════════════════════════ -->
  <div id="resetModal" style="display:none;position:fixed;inset:0;z-index:10000;align-items:center;justify-content:center;">
    <div id="resetBackdrop" onclick="closeResetModal()" style="position:absolute;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);opacity:0;transition:opacity .3s ease;"></div>
    <div id="resetCard" style="position:relative;background:#fff;border-radius:22px;padding:36px 32px 28px;width:min(440px,92vw);box-shadow:0 28px 80px rgba(0,0,0,.25);text-align:center;transform:scale(.85) translateY(28px);opacity:0;transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .3s ease;">
      <div style="width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,#fef2f2,#fee2e2);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:30px;color:#ef4444;box-shadow:0 6px 20px rgba(239,68,68,.22);">
        <i class="bi bi-exclamation-triangle-fill"></i>
      </div>
      <div id="resetModalTitle" style="font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:800;color:#0f172a;margin-bottom:8px;"></div>
      <div id="resetModalMessage" style="font-size:13.5px;color:#64748b;margin-bottom:22px;line-height:1.6;"></div>
      <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:12px 16px;margin-bottom:24px;display:flex;align-items:center;gap:10px;">
        <i class="bi bi-shield-exclamation" style="color:#dc2626;font-size:18px;flex-shrink:0;"></i>
        <span style="font-size:12.5px;font-weight:600;color:#991b1b;">This action is <strong>permanent</strong> and cannot be undone.</span>
      </div>
      <div style="display:flex;gap:10px;justify-content:center;">
        <button onclick="closeResetModal()" style="flex:1;padding:12px 0;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#0f172a;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='#eef2f8'" onmouseout="this.style.background='#f8fafc'"><i class="bi bi-x-lg me-1"></i>Cancel</button>
        <button id="resetConfirmBtn" onclick="submitReset()" style="flex:1;padding:12px 0;border-radius:12px;border:none;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(239,68,68,.35);display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity .15s;" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'"><i class="bi bi-arrow-counterclockwise"></i> Yes, Reset Votes</button>
      </div>
    </div>
  </div>

  <style>
    body.dark-mode #confirmCard,
    body.dark-mode #resetCard {
      background: #161b22 !important;
    }

    body.dark-mode #confirmTitle,
    body.dark-mode #resetModalTitle {
      color: #e6edf3 !important;
    }

    body.dark-mode #confirmMessage,
    body.dark-mode #resetModalMessage {
      color: #8b949e !important;
    }
  </style>

  <script>
    /* ── Generic inline confirmAction (replaces window.confirm) ── */
    var _confirmResolve = null;

    function confirmAction(title, message) {
      // Synchronous shim — open modal and block form via returnValue trick
      // We store pending form and show modal; form submit is cancelled, button retriggers
      openConfirmModal(title, message);
      return false; // always block native submit; modal OK will submit the form
    }
    var _pendingForm = null;

    function openConfirmModal(title, message) {
      document.getElementById('confirmTitle').textContent = title;
      document.getElementById('confirmMessage').textContent = message;
      var modal = document.getElementById('confirmModal');
      var backdrop = document.getElementById('confirmBackdrop');
      var card = document.getElementById('confirmCard');
      modal.style.display = 'flex';
      void modal.offsetWidth;
      backdrop.style.opacity = '1';
      card.style.transform = 'scale(1) translateY(0)';
      card.style.opacity = '1';
    }

    function closeConfirmModal(confirmed) {
      var modal = document.getElementById('confirmModal');
      var backdrop = document.getElementById('confirmBackdrop');
      var card = document.getElementById('confirmCard');
      backdrop.style.opacity = '0';
      card.style.transform = 'scale(.85) translateY(28px)';
      card.style.opacity = '0';
      setTimeout(function() {
        modal.style.display = 'none';
      }, 320);
      if (confirmed && _pendingForm) {
        var f = _pendingForm;
        _pendingForm = null;
        f.removeAttribute('onsubmit');
        f.submit();
      }
      _pendingForm = null;
    }

    /* Intercept all forms using confirmAction */
    document.addEventListener('submit', function(e) {
      var form = e.target;
      var onsubAttr = form.getAttribute('onsubmit');
      if (onsubAttr && onsubAttr.indexOf('confirmAction') !== -1) {
        e.preventDefault();
        var match = onsubAttr.match(/confirmAction\('([^']+)',\s*'([^']+)'\)/);
        if (match) {
          _pendingForm = form;
          openConfirmModal(match[1], match[2]);
        }
      }
    }, true);

    /* ── Reset Votes Modal ── */
    var _resetType = null;

    function openResetModal(type) {
      _resetType = type;
      var isGeneral = type === 'general';
      document.getElementById('resetModalTitle').textContent = isGeneral ? 'Reset General Votes' : 'Reset CLAS Votes';
      document.getElementById('resetModalMessage').textContent = isGeneral ?
        'This will permanently delete ALL general election votes and reset the vote count to zero for all General candidates.' :
        'This will permanently delete ALL CLAS election votes and reset the vote count to zero for all CLAS candidates.';
      var modal = document.getElementById('resetModal');
      var backdrop = document.getElementById('resetBackdrop');
      var card = document.getElementById('resetCard');
      modal.style.display = 'flex';
      void modal.offsetWidth;
      backdrop.style.opacity = '1';
      card.style.transform = 'scale(1) translateY(0)';
      card.style.opacity = '1';
    }

    function closeResetModal() {
      var modal = document.getElementById('resetModal');
      var backdrop = document.getElementById('resetBackdrop');
      var card = document.getElementById('resetCard');
      backdrop.style.opacity = '0';
      card.style.transform = 'scale(.85) translateY(28px)';
      card.style.opacity = '0';
      setTimeout(function() {
        modal.style.display = 'none';
      }, 320);
    }

    function submitReset() {
      closeResetModal();
      setTimeout(function() {
        var formId = _resetType === 'general' ? 'resetGeneralForm' : 'resetClasForm';
        var f = document.getElementById(formId);
        if (f) f.submit();
      }, 340);
    }
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeConfirmModal(false);
        closeResetModal();
      }
    });
  </script>
</body>

</html>