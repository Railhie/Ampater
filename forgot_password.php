<?php
// ══════════════════════════════════════════════════════════════
//  SuffraTech — Forgot Password Handler
// ══════════════════════════════════════════════════════════════

// ── Set timezone FIRST before anything else ──────────────────
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

define('MAIL_HOST',  'smtp.gmail.com');
define('MAIL_PORT',  587);
define('MAIL_FROM',  'suffratechadmin@gmail.com');
define('MAIL_PASS',  'dmycjwhmngfdpwgu');
define('MAIL_NAME',  'SuffraTech E-Voting');
define('APP_URL',    'http://localhost/suffratech');

session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function jsonOut(bool $ok, string $msg): void
{
    echo json_encode(['ok' => $ok, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(false, 'Invalid request method.');
}

$email = trim($_POST['email'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOut(false, 'Please enter a valid email address.');
}

$now = time();
if (!isset($_SESSION['fp_attempts'])) {
    $_SESSION['fp_attempts'] = [];
}
$_SESSION['fp_attempts'] = array_filter(
    $_SESSION['fp_attempts'],
    fn($t) => ($now - $t) < 900
);
if (count($_SESSION['fp_attempts']) >= 3) {
    jsonOut(false, 'Too many reset attempts. Please wait 15 minutes and try again.');
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT          NOT NULL,
        token      VARCHAR(128) NOT NULL UNIQUE,
        expires_at DATETIME     NOT NULL,
        used       TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     DEFAULT NOW(),
        INDEX idx_token (token),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("SELECT id, first_name FROM students WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $student = $stmt->fetch();

    $genericOk = 'If that email is registered, you\'ll receive a reset link within a few minutes. Check your inbox (and spam folder).';

    if (!$student) {
        $_SESSION['fp_attempts'][] = $now;
        jsonOut(true, $genericOk);
    }

    // Invalidate old tokens
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE student_id = :sid AND used = 0")
        ->execute([':sid' => $student['id']]);

    // Generate token
    $token = bin2hex(random_bytes(64));

    // ── Use PHP time so it matches reset_password.php comparison ─
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $pdo->prepare("INSERT INTO password_resets (student_id, token, expires_at) VALUES (:sid, :tok, :exp)")
        ->execute([':sid' => $student['id'], ':tok' => $token, ':exp' => $expiresAt]);

    $resetUrl = rtrim(APP_URL, '/') . '/reset_password.php?token=' . urlencode($token);

    // Load PHPMailer
    $loaded = false;
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        $loaded = true;
    } elseif (file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php')) {
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';
        $loaded = true;
    }

    if (!$loaded) {
        error_log('[SuffraTech] PHPMailer not found. Reset token for student #' . $student['id'] . ': ' . $token);
        $_SESSION['fp_attempts'][] = $now;
        jsonOut(true, $genericOk);
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer') && !class_exists('PHPMailer')) {
        error_log('[SuffraTech] PHPMailer class not found after loading files.');
        $_SESSION['fp_attempts'][] = $now;
        jsonOut(true, $genericOk);
    }

    $mail       = new PHPMailer(true);
    $encryption = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_FROM;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = $encryption;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM, MAIL_NAME);
    $mail->addAddress($email, $student['first_name']);
    $mail->isHTML(true);
    $mail->Subject = 'Reset Your SuffraTech Password';

    $firstName = htmlspecialchars($student['first_name'], ENT_QUOTES, 'UTF-8');
    $safeUrl   = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

    $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'DM Sans',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 20px;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.1);">
        <tr><td style="background:linear-gradient(135deg,#22c55e,#16a34a);padding:36px 40px;text-align:center;">
          <div style="font-size:28px;font-weight:900;letter-spacing:2px;color:#fff;">SUFFRA<span style="font-size:16px;font-weight:600;letter-spacing:3px;">TECH</span></div>
          <div style="font-size:11px;color:rgba(255,255,255,.65);letter-spacing:2px;margin-top:4px;text-transform:uppercase;">E-Voting Platform</div>
        </td></tr>
        <tr><td style="padding:36px 40px 40px;">
          <h2 style="margin:0 0 8px;font-size:22px;color:#1e293b;font-weight:700;">🔑 Password Reset</h2>
          <p style="margin:0 0 22px;font-size:14px;color:#64748b;line-height:1.6;">Hi <strong>{$firstName}</strong>, we received a request to reset your SuffraTech account password. Click the button below — this link expires in <strong>1 hour</strong>.</p>
          <div style="text-align:center;margin:28px 0;">
            <a href="{$safeUrl}" style="display:inline-block;padding:15px 36px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-size:15px;font-weight:700;border-radius:12px;text-decoration:none;box-shadow:0 4px 16px rgba(22,163,74,.35);">Reset My Password →</a>
          </div>
          <p style="margin:0 0 8px;font-size:12.5px;color:#94a3b8;line-height:1.6;">If the button doesn't work, copy and paste this link into your browser:</p>
          <p style="margin:0 0 24px;font-size:11.5px;color:#475569;word-break:break-all;background:#f8fafc;border-radius:8px;padding:10px 12px;">{$safeUrl}</p>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;">
          <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">If you didn't request this, you can safely ignore this email — your password will remain unchanged. This link expires in 1 hour.</p>
        </td></tr>
        <tr><td style="background:#f8fafc;padding:18px 40px;text-align:center;">
          <p style="margin:0;font-size:11.5px;color:#94a3b8;">© 2026 SuffraTech E-Voting Platform. All rights reserved.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $mail->AltBody = "Hi {$student['first_name']},\n\nReset your SuffraTech password using the link below (expires in 1 hour):\n\n{$resetUrl}\n\nIf you didn't request this, ignore this email.\n\n— SuffraTech";

    $mail->send();

    $_SESSION['fp_attempts'][] = $now;
    jsonOut(true, $genericOk);
} catch (Throwable $e) {
    error_log('[SuffraTech] Forgot password error: ' . $e->getMessage());
    jsonOut(false, 'Failed to send the email. Please check your connection and try again.');
}
