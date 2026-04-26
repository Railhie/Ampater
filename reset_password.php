<?php
// ══════════════════════════════════════════════════════════════
//  SuffraTech — Reset Password Page
// ══════════════════════════════════════════════════════════════

// ── Set timezone FIRST before anything else ──────────────────
date_default_timezone_set('Asia/Manila');

session_start();
require_once 'db.php';

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ── Ensure the password_resets table exists ──────────────────
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
} catch (Throwable $e) {
}

$token    = trim($_GET['token'] ?? '');
$pageErr  = '';
$pageDone = false;

// ── Validate token ───────────────────────────────────────────
$resetRow = null;
if ($token === '' || !preg_match('/^[0-9a-f]{128}$/', $token)) {
    $pageErr = 'invalid';
} else {
    try {
        // Fetch token row first WITHOUT expiry check
        $stmt = $pdo->prepare("
            SELECT pr.*, s.email, s.first_name
            FROM password_resets pr
            JOIN students s ON s.id = pr.student_id
            WHERE pr.token = :tok AND pr.used = 0
            LIMIT 1
        ");
        $stmt->execute([':tok' => $token]);
        $row = $stmt->fetch();

        if (!$row) {
            // Token not found or already used
            $pageErr = 'expired';
        } else {
            // ── Check expiry using PHP time (avoids timezone mismatch) ──
            $expiresAt = strtotime($row['expires_at']);
            $nowTime   = time();

            if ($nowTime > $expiresAt) {
                // Mark as used since it's expired
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = :tok")
                    ->execute([':tok' => $token]);
                $pageErr = 'expired';
            } else {
                $resetRow = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[SuffraTech] Reset token lookup: ' . $e->getMessage());
        $pageErr = 'error';
    }
}

// ── Handle form submission ───────────────────────────────────
$formErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetRow) {

    if (!hash_equals($_SESSION['csrf_reset'] ?? '', $_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Invalid session. Please refresh and try again.';
    } else {
        $newPw  = $_POST['new_password']     ?? '';
        $confPw = $_POST['confirm_password'] ?? '';

        if ($newPw === '') {
            $formErrors['new_password'] = 'Password is required.';
        } elseif (strlen($newPw) < 8) {
            $formErrors['new_password'] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
            $formErrors['new_password'] = 'Password must contain at least one uppercase letter and one number.';
        }

        if ($confPw === '') {
            $formErrors['confirm_password'] = 'Please confirm your password.';
        } elseif ($newPw !== $confPw) {
            $formErrors['confirm_password'] = 'Passwords do not match.';
        }

        if (empty($formErrors)) {
            try {
                $hashed = password_hash($newPw, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE students SET password_hash = :pw, updated_at = NOW() WHERE id = :id")
                    ->execute([':pw' => $hashed, ':id' => $resetRow['student_id']]);
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = :tok")
                    ->execute([':tok' => $token]);
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE student_id = :sid AND used = 0")
                    ->execute([':sid' => $resetRow['student_id']]);
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $resetRow['student_id']) {
                    session_destroy();
                    session_start();
                }
                $pageDone = true;
            } catch (Throwable $e) {
                error_log('[SuffraTech] Reset password save: ' . $e->getMessage());
                $formErrors[] = 'A server error occurred. Please try again.';
            }
        }
    }
}

// ── Generate CSRF for the reset form ────────────────────────
if (empty($_SESSION['csrf_reset'])) {
    $_SESSION['csrf_reset'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SuffraTech — Reset Password</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --green: #4ade80;
            --green-dark: #22c55e;
            --green-deeper: #16a34a;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-400: #94a3b8;
            --gray-600: #475569;
            --gray-800: #1e293b;
            --error: #dc2626;
            --error-bg: #fef2f2;
            --error-bd: #fca5a5;
            --shadow-lg: 0 12px 40px rgba(0, 0, 0, .12);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: linear-gradient(135deg, #15803d 0%, #22c55e 50%, #4ade80 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            background: #fff;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(160deg, #22c55e 0%, #16a34a 60%, #15803d 100%);
            padding: 36px 40px 32px;
            text-align: center;
        }

        .logo {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .logo-suffra {
            background: linear-gradient(135deg, #a8b8d8, #c8d8f0, #8fa8cc, #b0c4de);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 900;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .logo-tech {
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            background: linear-gradient(135deg, #d0e8d0, #a8d8a8, #c8e8c8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-sub {
            font-size: 12px;
            color: rgba(255, 255, 255, .6);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .card-body {
            padding: 36px 40px 40px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 6px;
        }

        .page-desc {
            font-size: 13.5px;
            color: var(--gray-400);
            margin-bottom: 24px;
            line-height: 1.55;
        }

        .alert {
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 13.5px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-error {
            background: var(--error-bg);
            border: 1.5px solid var(--error-bd);
            color: var(--error);
        }

        .alert-warn {
            background: #fffbeb;
            border: 1.5px solid #fde68a;
            color: #92400e;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 18px;
        }

        label {
            font-weight: 600;
            font-size: 13px;
            color: var(--gray-600);
        }

        .field-error {
            font-size: 12px;
            color: var(--error);
            font-weight: 500;
        }

        .pw-wrap {
            position: relative;
        }

        .pw-wrap input {
            padding-right: 48px;
        }

        input[type="password"],
        input[type="text"] {
            padding: 13px 16px;
            border: 1.5px solid var(--gray-200);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            color: var(--gray-800);
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: var(--gray-50);
            width: 100%;
        }

        input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(74, 222, 128, .12);
            background: #fff;
        }

        input.has-error {
            border-color: var(--error);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, .1);
        }

        .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-400);
            padding: 4px;
            display: flex;
            align-items: center;
        }

        .toggle-pw:hover {
            color: var(--gray-600);
        }

        .pw-bar-wrap {
            height: 4px;
            background: var(--gray-200);
            border-radius: 4px;
            margin-top: 4px;
            overflow: hidden;
        }

        .pw-bar {
            height: 100%;
            width: 0;
            border-radius: 4px;
            transition: width .3s, background .3s;
        }

        .pw-hint {
            font-size: 11.5px;
            color: var(--gray-400);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: 'DM Sans', sans-serif;
            background: linear-gradient(135deg, var(--green-dark), var(--green-deeper));
            color: #fff;
            box-shadow: 0 4px 16px rgba(22, 163, 74, .3);
            transition: all .2s;
            margin-top: 6px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(22, 163, 74, .35);
        }

        .btn-submit:disabled {
            opacity: .65;
            cursor: not-allowed;
            transform: none;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12.5px;
            color: var(--gray-400);
        }

        .footer a {
            color: var(--green-deeper);
            font-weight: 600;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .success-icon {
            font-size: 56px;
            text-align: center;
            margin-bottom: 16px;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="card-header">
            <div class="logo">
                <span class="logo-suffra">SUFFRA</span><span class="logo-tech">TECH</span>
            </div>
            <div class="logo-sub">E-Voting Platform</div>
        </div>
        <div class="card-body">

            <?php if ($pageDone): ?>
                <div class="success-icon">✅</div>
                <div class="page-title" style="text-align:center">Password Updated!</div>
                <p class="page-desc" style="text-align:center;margin-top:8px">
                    Your password has been successfully reset. You can now sign in with your new password.
                </p>
                <div class="footer" style="margin-top:28px">
                    <a href="login.php">← Back to Sign In</a>
                </div>

            <?php elseif ($pageErr === 'invalid' || $pageErr === 'expired'): ?>
                <div class="alert alert-warn">
                    <span style="font-size:20px">⏳</span>
                    <div>
                        <strong><?= $pageErr === 'expired' ? 'Link expired or already used' : 'Invalid reset link' ?></strong><br>
                        <span style="font-size:12.5px">
                            <?php if ($pageErr === 'expired'): ?>
                                This reset link has expired (links are valid for 1 hour) or has already been used.
                            <?php else: ?>
                                This reset link is invalid. Please request a new one.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="footer">
                    <a href="login.php">← Back to Login</a> &nbsp;·&nbsp; <a href="login.php#forgot">Request new link</a>
                </div>

            <?php elseif ($pageErr === 'error'): ?>
                <div class="alert alert-error">⚠️ &nbsp;A server error occurred. Please try again later.</div>
                <div class="footer"><a href="login.php">← Back to Login</a></div>

            <?php else: ?>
                <div class="page-title">🔑 Set New Password</div>
                <p class="page-desc">
                    Resetting password for <strong><?= h($resetRow['email']) ?></strong>.<br>
                    Choose a strong password — min. 8 characters, 1 uppercase, 1 number.
                </p>

                <?php if (!empty($formErrors) && isset($formErrors[0])): ?>
                    <div class="alert alert-error">⚠️ &nbsp;<?= h($formErrors[0]) ?></div>
                <?php endif; ?>

                <form method="POST" action="reset_password.php?token=<?= h($token) ?>" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_reset']) ?>">

                    <div class="field">
                        <label for="new_password">New Password <span style="color:#dc2626">*</span></label>
                        <div class="pw-wrap">
                            <input type="password" id="new_password" name="new_password"
                                class="<?= isset($formErrors['new_password']) ? 'has-error' : '' ?>"
                                placeholder="Create a strong password"
                                autocomplete="new-password" required />
                            <button type="button" class="toggle-pw" onclick="togglePw('new_password','eye1')" title="Show/hide">
                                <svg id="eye1" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                        <div class="pw-bar-wrap">
                            <div class="pw-bar" id="pwBar"></div>
                        </div>
                        <span class="pw-hint" id="pwHint">Min. 8 characters, 1 uppercase, 1 number</span>
                        <?php if (isset($formErrors['new_password'])): ?>
                            <span class="field-error"><?= h($formErrors['new_password']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="confirm_password">Confirm Password <span style="color:#dc2626">*</span></label>
                        <div class="pw-wrap">
                            <input type="password" id="confirm_password" name="confirm_password"
                                class="<?= isset($formErrors['confirm_password']) ? 'has-error' : '' ?>"
                                placeholder="Re-enter your new password"
                                autocomplete="new-password" required />
                            <button type="button" class="toggle-pw" onclick="togglePw('confirm_password','eye2')" title="Show/hide">
                                <svg id="eye2" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                        <?php if (isset($formErrors['confirm_password'])): ?>
                            <span class="field-error"><?= h($formErrors['confirm_password']) ?></span>
                        <?php endif; ?>
                    </div>

                    <button class="btn-submit" type="submit" id="submitBtn">Update Password →</button>
                </form>

                <div class="footer">
                    <a href="login.php">← Back to Login</a>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function togglePw(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                    <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>`;
            } else {
                input.type = 'password';
                icon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
            }
        }

        const pwInput = document.getElementById('new_password');
        if (pwInput) {
            pwInput.addEventListener('input', function() {
                const pw = this.value;
                const bar = document.getElementById('pwBar');
                const hint = document.getElementById('pwHint');
                let score = 0;
                if (pw.length >= 8) score++;
                if (/[A-Z]/.test(pw)) score++;
                if (/[0-9]/.test(pw)) score++;
                if (/[^A-Za-z0-9]/.test(pw)) score++;
                const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e'];
                const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
                bar.style.width = (score / 4 * 100) + '%';
                bar.style.background = colors[score] || '';
                hint.textContent = score > 0 ? labels[score] : 'Min. 8 characters, 1 uppercase, 1 number';
                hint.style.color = score > 0 ? colors[score] : '#94a3b8';
            });

            document.getElementById('confirm_password').addEventListener('input', function() {
                const pw = document.getElementById('new_password').value;
                this.value && this.value !== pw ?
                    this.classList.add('has-error') :
                    this.classList.remove('has-error');
            });
        }

        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function() {
                const btn = document.getElementById('submitBtn');
                btn.disabled = true;
                btn.textContent = 'Updating…';
            });
        }
    </script>
</body>

</html>