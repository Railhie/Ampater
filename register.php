<?php
// ══════════════════════════════════════════════
//  SuffraTech — Register Page (PHP Backend)
// ══════════════════════════════════════════════

session_start();

require_once 'db.php';

// ── Redirect if already logged in ───────────
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin.php' : 'user.php'));
    exit;
}

// ── Helpers ─────────────────────────────────
function h(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function old(string $key, string $default = ''): string
{
    return h($_POST[$key] ?? $default);
}

// ── CSRF token — MUST be generated BEFORE processing POST ───────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Programs list — defined ONCE, used for both validation & template ──
$programs = [
    'ABBS'        => 'ABBS - Bachelor of Arts in Behavioural Sciences',
    'BA COMM'     => 'BA COMM - Bachelor of Arts in Communication',
    'BA POS'      => 'BA POS - Bachelor of Arts in Political Science',
    'BECED'       => 'BECED - Bachelor of Early Childhood Education',
    'BPA'         => 'BPA - Bachelor of Public Administration',
    'BPA ECGE'    => 'BPA ECGE - BPA Evening Class for Govt Employees',
    'BS CPE'      => 'BS CPE - BS Computer Engineering',
    'BS CRIM'     => 'BS CRIM - Bachelor of Science in Criminology',
    'BS ECE'      => 'BS ECE - BS Electronics Engineering',
    'BS EE'       => 'BS EE - BS Electrical Engineering',
    'BS ENTREP'   => 'BS ENTREP - BS in Entrepreneurship',
    'BS IE'       => 'BS IE - BS Industrial Engineering',
    'BS MATH'     => 'BS MATH - BS in Mathematics',
    'BS PSY'      => 'BS PSY - BS in Psychology',
    'BSA'         => 'BSA - BS in Accountancy',
    'BSAIS'       => 'BSAIS - BS in Accounting Information Systems',
    'BSBA FMGT'   => 'BSBA FMGT - BSBA Major in Financial Management',
    'BSBA HRM'    => 'BSBA HRM - BSBA Major in Human Resource Management',
    'BSBA MKTG'   => 'BSBA MKTG - BSBA Major in Marketing Management',
    'BSCS'        => 'BSCS - BS in Computer Science',
    'BSE ENG'     => 'BSE ENG - BSEd Major in English',
    'BSE ENG-CHI' => 'BSE ENG-CHI - BSEd Major in English with Chinese',
    'BSE SCI'     => 'BSE SCI - BSEd Major in Science',
    'BSEMC'       => 'BSEMC - BS in Entertainment and Multimedia Computing',
    'BSHM'        => 'BSHM - BS in Hospitality Management',
    'BSIS'        => 'BSIS - BS in Information Systems',
    'BSISM'       => 'BSISM - BS in Industrial Security Management',
    'BSIT'        => 'BSIT - BS in Information Technology',
    'BSOAD'       => 'BSOAD - BS in Office Administration',
    'BSSW'        => 'BSSW - BS in Social Work',
    'BSTM'        => 'BSTM - BS in Tourism Management',
    'BTLED HE'    => 'BTLED HE - BTLEd Major in Home Economics',
    'CPE'         => 'CPE - Certificate in Professional Education',
    'DPA'         => 'DPA - Doctor in Public Administration',
    'MAED'        => 'MAED - MA in Education (Educational Management)',
    'MAT-EG'      => 'MAT-EG - MA in Teaching in the Early Grades',
    'MATS'        => 'MATS - MA in Teaching Science',
    'MBA'         => 'MBA - Master in Business Administration',
    'MPA'         => 'MPA - Master in Public Administration',
    'MSC'         => 'MSC - MS in Criminal Justice (Criminology)',
    'PHD'         => 'PHD - Doctor of Philosophy (Educational Management)',
];

// Derive valid program codes from the keys — single source of truth
$valid_programs = array_keys($programs);

// ── Validation & Registration ────────────────
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF check ──
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    } else {

        // ── Collect & trim inputs ──
        $first_name = trim($_POST['first_name']       ?? '');
        $last_name  = trim($_POST['last_name']        ?? '');
        $student_id = trim($_POST['student_id']       ?? '');
        $birthdate  = trim($_POST['birthdate']        ?? '');
        $phone      = trim($_POST['phone']            ?? '');
        $email      = trim($_POST['email']            ?? '');
        $password   = $_POST['password']              ?? '';
        $confirm_pw = $_POST['confirm_password']      ?? '';
        $program    = trim($_POST['program']          ?? '');
        $section    = strtoupper(trim($_POST['section'] ?? ''));

        // ── Field-level validation ──
        if ($first_name === '') {
            $errors['first_name'] = 'First name is required.';
        } elseif (strlen($first_name) > 60) {
            $errors['first_name'] = 'First name is too long.';
        }

        if ($last_name === '') {
            $errors['last_name'] = 'Last name is required.';
        } elseif (strlen($last_name) > 60) {
            $errors['last_name'] = 'Last name is too long.';
        }

        if ($student_id === '') {
            $errors['student_id'] = 'Student ID is required.';
        } elseif (!preg_match('/^\d{7,10}-[A-Za-z]$/', $student_id)) {
            $errors['student_id'] = 'Student ID must follow the format e.g. 20251234-S.';
        }

        if ($birthdate === '') {
            $errors['birthdate'] = 'Date of birth is required.';
        } else {
            $dob = DateTime::createFromFormat('Y-m-d', $birthdate);
            $now = new DateTime();
            if (!$dob || $dob > $now) {
                $errors['birthdate'] = 'Please enter a valid date of birth.';
            } elseif ((int)$dob->diff($now)->format('%y') < 17) {
                $errors['birthdate'] = 'You must be at least 17 years old to register.';
            }
        }

        if ($phone === '') {
            $errors['phone'] = 'Phone number is required.';
        } elseif (!preg_match('/^[0-9+\-\s]{7,15}$/', $phone)) {
            $errors['phone'] = 'Enter a valid phone number (e.g. 09XX XXX XXXX).';
        }

        if ($email === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must contain at least one uppercase letter and one number.';
        }

        if ($confirm_pw === '') {
            $errors['confirm_password'] = 'Please confirm your password.';
        } elseif ($password !== $confirm_pw) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if ($program === '') {
            $errors['program'] = 'Please select your program/course.';
        } elseif (!in_array($program, $valid_programs, true)) {
            $errors['program'] = 'Invalid program selected.';
        }

        if ($section === '') {
            $errors['section'] = 'Section is required (e.g. 1A, 2B).';
        } elseif (!preg_match('/^[A-Z0-9\-]{1,10}$/', $section)) {
            $errors['section'] = 'Section must be 1-10 alphanumeric characters (e.g. 1A, 3C).';
        }

        // ── DB uniqueness checks & insert ──
        if (empty($errors)) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS students (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    student_id      VARCHAR(30)  NOT NULL UNIQUE,
                    first_name      VARCHAR(60)  NOT NULL,
                    last_name       VARCHAR(60)  NOT NULL DEFAULT '',
                    email           VARCHAR(120) NOT NULL UNIQUE,
                    phone           VARCHAR(20)  DEFAULT NULL,
                    birthdate       DATE         DEFAULT NULL,
                    program         VARCHAR(60)  DEFAULT NULL,
                    section         VARCHAR(20)  DEFAULT NULL,
                    password_hash   VARCHAR(255) NOT NULL,
                    approval_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
                    has_voted       TINYINT(1)   NOT NULL DEFAULT 0,
                    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Add columns if missing (safe to ignore duplicate-column errors)
                foreach (['phone VARCHAR(20) DEFAULT NULL', 'program VARCHAR(60) DEFAULT NULL', 'section VARCHAR(20) DEFAULT NULL'] as $col) {
                    try { $pdo->exec("ALTER TABLE students ADD COLUMN $col"); } catch (PDOException $e) {}
                }

                $chk = $pdo->prepare("SELECT id FROM students WHERE student_id = :sid LIMIT 1");
                $chk->execute([':sid' => $student_id]);
                if ($chk->fetch()) {
                    $errors['student_id'] = 'This Student ID is already registered.';
                }

                $chk2 = $pdo->prepare("SELECT id FROM students WHERE email = :email LIMIT 1");
                $chk2->execute([':email' => $email]);
                if ($chk2->fetch()) {
                    $errors['email'] = 'This email address is already in use.';
                }

                $chkName = $pdo->prepare("SELECT id FROM students WHERE LOWER(first_name) = LOWER(:fn) AND LOWER(last_name) = LOWER(:ln) LIMIT 1");
                $chkName->execute([':fn' => $first_name, ':ln' => $last_name]);
                if ($chkName->fetch()) {
                    $errors['first_name'] = 'A student with this full name is already registered. If this is you, contact the admin.';
                }

                if (!isset($errors['phone'])) {
                    $chkPhone = $pdo->prepare("SELECT id FROM students WHERE phone = :ph LIMIT 1");
                    $chkPhone->execute([':ph' => $phone]);
                    if ($chkPhone->fetch()) {
                        $errors['phone'] = 'This phone number is already linked to an existing account.';
                    }
                }

                if (empty($errors)) {
                    $hashed_pw = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("
                        INSERT INTO students
                            (student_id, first_name, last_name, email, phone, birthdate,
                             program, section, password_hash, approval_status, has_voted, created_at)
                        VALUES
                            (:student_id, :first_name, :last_name, :email, :phone, :birthdate,
                             :program, :section, :pw, 'Pending', 0, NOW())
                    ");
                    $stmt->execute([
                        ':student_id' => $student_id,
                        ':first_name' => $first_name,
                        ':last_name'  => $last_name,
                        ':email'      => $email,
                        ':phone'      => $phone,
                        ':birthdate'  => $birthdate,
                        ':program'    => $program,
                        ':section'    => $section,
                        ':pw'         => $hashed_pw,
                    ]);

                    try {
                        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                            id         INT AUTO_INCREMENT PRIMARY KEY,
                            category   VARCHAR(50),
                            action     VARCHAR(100),
                            details    TEXT,
                            admin_id   INT,
                            created_at DATETIME DEFAULT NOW()
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                        $pdo->prepare(
                            "INSERT INTO audit_log (category, action, details, admin_id, created_at)
                             VALUES ('Registration', 'New Account', :d, 0, NOW())"
                        )->execute([
                            ':d' => "Student: {$first_name} {$last_name} | ID: {$student_id} | Email: {$email} | Program: {$program} | Section: {$section} | Status: Pending Approval",
                        ]);
                    } catch (Exception $auditEx) {
                        error_log('[SuffraTech] Audit log error: ' . $auditEx->getMessage());
                    }

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_POST   = [];
                    $success = 'pending';
                }
            } catch (PDOException $e) {
                error_log('[SuffraTech] Register error: ' . $e->getMessage());
                $errors[] = 'A server error occurred. Please try again later.';
            }
        }
    }
}

// ── Compute age for re-display after error ──
function computeAge(string $bd): string
{
    if ($bd === '') return '';
    try {
        $dob = new DateTime($bd);
        return (string)(int)(new DateTime())->diff($dob)->format('%y');
    } catch (Exception $e) {
        return '';
    }
}
$displayAge = computeAge($_POST['birthdate'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SuffraTech &mdash; Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet" />
    <style>
        :root {
            --forest: #0d2318;
            --forest-mid: #1b4332;
            --forest-lit: #2d6a4f;
            --sage: #52b788;
            --mint: #b7e4c7;
            --mint-faint: #eaf7ef;
            --white: #ffffff;
            --ink: #0f1c14;
            --ink-soft: #3a5244;
            --slate: #6b8f7a;
            --border: #d4e8dc;
            --error: #c0392b;
            --error-bg: #fdf0ef;
            --error-bd: #f5b7b1;
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --transition: 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--forest);
            min-height: 100vh;
            display: flex;
            align-items: stretch;
        }

        /* ─── LAYOUT ─────────────────────────────── */
        .page-shell {
            display: grid;
            grid-template-columns: 420px 1fr;
            width: 100%;
            min-height: 100vh;
        }

        /* ─── LEFT PANEL ─────────────────────────── */
        .brand-panel {
            background: var(--forest);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 52px 44px;
            overflow: hidden;
        }
        .brand-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, rgba(82,183,136,0.18) 1px, transparent 1px);
            background-size: 28px 28px;
            pointer-events: none;
        }
        .brand-panel::after {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(82,183,136,0.12) 0%, transparent 70%);
            pointer-events: none;
        }
        .brand-top { position: relative; z-index: 1; }
        .brand-wordmark { display: flex; flex-direction: column; gap: 2px; margin-bottom: 48px; }
        .wm-suffra {
            font-family: 'Syne', sans-serif;
            font-size: 42px; font-weight: 800;
            letter-spacing: 3px; text-transform: uppercase;
            color: var(--white); line-height: 1;
        }
        .wm-tech {
            font-family: 'Syne', sans-serif;
            font-size: 13px; font-weight: 700;
            letter-spacing: 6px; text-transform: uppercase;
            color: var(--sage);
        }
        .brand-illustration {
            width: 100%; max-width: 260px;
            margin: 0 auto 40px;
            display: block; opacity: 0.92;
            position: relative; z-index: 1;
        }
        .brand-tagline {
            font-size: 15px; color: var(--mint);
            line-height: 1.7; position: relative; z-index: 1;
            padding-left: 16px; border-left: 2px solid var(--sage);
        }
        .brand-tagline strong {
            display: block; font-size: 20px;
            font-family: 'Syne', sans-serif; font-weight: 700;
            color: var(--white); margin-bottom: 6px;
        }
        .brand-bottom { position: relative; z-index: 1; }
        .brand-signin {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--slate); font-size: 13.5px;
            text-decoration: none; transition: color var(--transition);
        }
        .brand-signin:hover { color: var(--mint); }
        .brand-signin span {
            display: inline-block;
            background: rgba(82,183,136,0.15); color: var(--sage);
            font-size: 12px; font-weight: 600;
            padding: 4px 10px; border-radius: 99px;
            transition: background var(--transition);
        }
        .brand-signin:hover span { background: rgba(82,183,136,0.28); }

        /* ─── RIGHT PANEL ─────────────────────────── */
        .form-panel {
            background: #f7faf8;
            display: flex; align-items: flex-start; justify-content: center;
            padding: 52px 40px; overflow-y: auto;
        }
        .form-inner {
            width: 100%; max-width: 520px;
            animation: fadeUp 0.5s ease both;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .form-heading {
            font-family: 'Syne', sans-serif;
            font-size: 26px; font-weight: 800;
            color: var(--ink); margin-bottom: 6px;
        }
        .form-sub { font-size: 14px; color: var(--slate); margin-bottom: 32px; }

        /* ─── SECTION DIVIDERS ───────────────────── */
        .section-label {
            display: flex; align-items: center; gap: 10px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 1.4px; text-transform: uppercase;
            color: var(--sage); margin-bottom: 18px;
        }
        .section-label::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }

        /* ─── ALERTS ─────────────────────────────── */
        .alert {
            border-radius: var(--radius-sm); padding: 12px 16px;
            font-size: 13.5px; margin-bottom: 24px;
            display: flex; align-items: flex-start; gap: 10px;
            border: 1.5px solid;
        }
        .alert-error { background: var(--error-bg); color: var(--error); border-color: var(--error-bd); }
        .alert-icon { flex-shrink: 0; margin-top: 1px; }

        /* ─── FORM GRID ──────────────────────────── */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-section { margin-bottom: 28px; }

        /* ─── FIELDS ─────────────────────────────── */
        .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
        label { font-size: 12.5px; font-weight: 600; color: var(--ink-soft); letter-spacing: 0.2px; }
        .input-wrap { position: relative; }
        .input-icon {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%); color: var(--slate);
            pointer-events: none; display: flex; align-items: center;
        }
        .input-wrap:focus-within .input-icon { color: var(--sage); }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="number"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px 12px 40px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            background-color: var(--white);
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition);
            -webkit-appearance: none;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        input[type="date"]:focus,
        input[type="number"]:focus,
        input[type="password"]:focus {
            border-color: var(--sage);
            box-shadow: 0 0 0 3.5px rgba(82,183,136,0.15);
        }
        input.has-error {
            border-color: var(--error);
            box-shadow: 0 0 0 3.5px rgba(192,57,43,0.1);
        }
        input[readonly] { background-color: #f0f5f2; color: var(--slate); cursor: not-allowed; }

        /* ─── FIX: SELECT — use background-color + background-image separately ── */
        select {
            width: 100%;
            padding: 12px 36px 12px 40px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            /* KEY FIX: use background-color (not shorthand 'background') so
               background-image is NOT accidentally reset */
            background-color: var(--white);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b8f7a' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            transition: border-color var(--transition), box-shadow var(--transition);
            cursor: pointer;
        }
        select:focus {
            border-color: var(--sage);
            box-shadow: 0 0 0 3.5px rgba(82,183,136,0.15);
        }
        select.has-error {
            border-color: var(--error);
            box-shadow: 0 0 0 3.5px rgba(192,57,43,0.1);
        }

        .field-error {
            font-size: 12px; color: var(--error);
            display: flex; align-items: center; gap: 4px;
        }

        /* ─── PASSWORD FIELD ─────────────────────── */
        .password-wrap { position: relative; }
        .password-wrap input { padding-right: 48px; }
        .toggle-pw {
            position: absolute; right: 13px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: var(--slate); padding: 4px;
            display: flex; align-items: center;
            transition: color var(--transition);
        }
        .toggle-pw:hover { color: var(--ink); }
        .pw-bar-wrap {
            height: 3px; background: var(--border);
            border-radius: 99px; overflow: hidden; margin-top: 4px;
        }
        .pw-bar {
            height: 100%; border-radius: 99px; width: 0;
            transition: width 0.35s ease, background 0.35s ease;
        }
        .pw-hint { font-size: 11.5px; color: var(--slate); margin-top: 4px; }

        /* ─── SUBMIT BUTTON ──────────────────────── */
        .btn-register {
            width: 100%; padding: 14px;
            border-radius: var(--radius-sm);
            font-size: 15px; font-weight: 700;
            font-family: 'Syne', sans-serif; letter-spacing: 0.5px;
            cursor: pointer; border: none;
            background: linear-gradient(135deg, var(--forest-lit) 0%, var(--forest-mid) 100%);
            color: var(--white);
            box-shadow: 0 4px 18px rgba(13,35,24,0.28);
            transition: all var(--transition);
            margin-top: 10px; display: block;
            text-align: center; text-decoration: none;
        }
        .btn-register:hover {
            background: linear-gradient(135deg, var(--sage) 0%, var(--forest-lit) 100%);
            box-shadow: 0 8px 28px rgba(13,35,24,0.32);
            transform: translateY(-1px);
        }
        .btn-register:active { transform: none; }
        .btn-register:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

        .form-footer { text-align: center; margin-top: 20px; font-size: 13px; color: var(--slate); }
        .form-footer a { color: var(--forest-lit); font-weight: 600; text-decoration: none; }
        .form-footer a:hover { text-decoration: underline; }

        /* ─── PENDING SCREEN ─────────────────────── */
        .pending-screen { text-align: center; padding: 12px 0 8px; animation: fadeUp 0.5s ease both; }
        .pending-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(135deg, var(--mint-faint), #c8edda);
            border: 2px solid var(--mint); margin-bottom: 20px; font-size: 32px;
            animation: pendingPulse 2.4s ease-in-out infinite;
        }
        @keyframes pendingPulse {
            0%,100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(82,183,136,0.3); }
            50%      { transform: scale(1.05); box-shadow: 0 0 0 12px rgba(82,183,136,0); }
        }
        .pending-title {
            font-family: 'Syne', sans-serif; font-size: 24px; font-weight: 800;
            color: var(--ink); margin-bottom: 10px;
        }
        .pending-desc {
            font-size: 14px; color: var(--slate); line-height: 1.7;
            margin-bottom: 32px; max-width: 380px;
            margin-left: auto; margin-right: auto;
        }
        .pending-steps { display: flex; flex-direction: column; gap: 10px; text-align: left; margin-bottom: 32px; }
        .pending-step {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; border-radius: var(--radius-sm);
            background: #f0f5f2; border: 1.5px solid var(--border);
            opacity: 0.5; transition: all var(--transition);
        }
        .pending-step.done  { background: #eaf7ef; border-color: #a3d9b5; opacity: 1; }
        .pending-step.active { background: #fffdf0; border-color: #f0d060; opacity: 1; }
        .ps-dot {
            width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; background: #e8e8e8; border: 1.5px solid #c0c0c0;
        }
        .pending-step.done .ps-dot  { background: var(--sage); border-color: var(--sage); }
        .pending-step.active .ps-dot { background: #f0d060; border-color: #d4a800; }
        .ps-text { flex: 1; }
        .ps-label { font-size: 14px; font-weight: 600; color: var(--ink); }
        .ps-sub   { font-size: 12px; color: var(--slate); margin-top: 2px; }

        /* ─── RESPONSIVE ─────────────────────────── */
        @media (max-width: 860px) {
            .page-shell { grid-template-columns: 1fr; }
            .brand-panel {
                padding: 36px 28px 32px;
                flex-direction: row; align-items: center; gap: 20px;
            }
            .brand-illustration, .brand-tagline, .brand-bottom { display: none; }
            .brand-wordmark { margin-bottom: 0; }
            .wm-suffra { font-size: 30px; }
            .form-panel { padding: 36px 24px 48px; }
        }
        @media (max-width: 520px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>

<body>
<div class="page-shell">

    <!-- ░░░ LEFT BRAND PANEL ░░░ -->
    <aside class="brand-panel">
        <div class="brand-top">
            <div class="brand-wordmark">
                <span class="wm-suffra">Suffra</span>
                <span class="wm-tech">Tech</span>
            </div>

            <svg class="brand-illustration" viewBox="0 0 260 200" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="40" y="90" width="180" height="100" rx="10" fill="#1b4332" stroke="#2d6a4f" stroke-width="1.5"/>
                <rect x="100" y="84" width="60" height="12" rx="6" fill="#0d2318" stroke="#52b788" stroke-width="1.5"/>
                <rect x="108" y="52" width="44" height="58" rx="5" fill="#eaf7ef" stroke="#52b788" stroke-width="1.5"/>
                <line x1="118" y1="68" x2="142" y2="68" stroke="#2d6a4f" stroke-width="2" stroke-linecap="round"/>
                <line x1="118" y1="76" x2="135" y2="76" stroke="#2d6a4f" stroke-width="2" stroke-linecap="round"/>
                <polyline points="120,88 126,96 142,78" stroke="#52b788" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="50" cy="50" r="18" stroke="#2d6a4f" stroke-width="1" stroke-dasharray="4 3" fill="none" opacity="0.5"/>
                <circle cx="210" cy="160" r="24" stroke="#2d6a4f" stroke-width="1" stroke-dasharray="4 3" fill="none" opacity="0.4"/>
                <circle cx="32" cy="110" r="3" fill="#52b788" opacity="0.4"/>
                <circle cx="228" cy="95" r="4" fill="#52b788" opacity="0.35"/>
                <circle cx="60" cy="170" r="2.5" fill="#52b788" opacity="0.3"/>
                <ellipse cx="130" cy="192" rx="75" ry="6" fill="#0d2318" opacity="0.5"/>
            </svg>

            <div class="brand-tagline">
                <strong>Your voice matters.</strong>
                Secure, transparent, and modern
                student elections &mdash; powered by SuffraTech.
            </div>
        </div>
        <div class="brand-bottom">
            <a href="login.php" class="brand-signin">
                Already registered? <span>Sign in &rarr;</span>
            </a>
        </div>
    </aside>

    <!-- ░░░ RIGHT FORM PANEL ░░░ -->
    <main class="form-panel">
        <div class="form-inner">

            <?php if ($success === 'pending'): ?>
            <!-- ── PENDING APPROVAL SCREEN ── -->
            <div class="pending-screen">
                <div class="pending-badge">&#9203;</div>
                <h1 class="pending-title">You're in the queue!</h1>
                <p class="pending-desc">
                    Your registration is submitted and now <strong>pending admin review.</strong>
                    You'll be able to vote once your eligibility is confirmed.
                </p>
                <div class="pending-steps">
                    <div class="pending-step done">
                        <div class="ps-dot">&#10003;</div>
                        <div class="ps-text">
                            <div class="ps-label">Registration Complete</div>
                            <div class="ps-sub">Your details have been saved securely.</div>
                        </div>
                    </div>
                    <div class="pending-step active">
                        <div class="ps-dot">&#128269;</div>
                        <div class="ps-text">
                            <div class="ps-label">Admin Review</div>
                            <div class="ps-sub">The committee is verifying your eligibility.</div>
                        </div>
                    </div>
                    <div class="pending-step">
                        <div class="ps-dot">&#128379;</div>
                        <div class="ps-text">
                            <div class="ps-label">Cast Your Vote</div>
                            <div class="ps-sub">Once approved, you can log in and vote.</div>
                        </div>
                    </div>
                </div>
                <a href="login.php" class="btn-register">Go to Login &rarr;</a>
            </div>

            <?php else: ?>
            <!-- ── REGISTRATION FORM ── -->

            <h1 class="form-heading">Create your account</h1>
            <p class="form-sub">Register below to participate in the student election.</p>

            <?php if (!empty($errors) && isset($errors[0])): ?>
            <div class="alert alert-error" role="alert">
                <svg class="alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span><?= h($errors[0]) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="register.php" novalidate id="regForm">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>" />

                <!-- ── SECTION 1: PERSONAL INFO ── -->
                <div class="form-section">
                    <div class="section-label">Personal Info</div>

                    <div class="form-row">
                        <div class="field">
                            <label for="first_name">First Name</label>
                            <div class="input-wrap">
                                <input class="<?= isset($errors['first_name']) ? 'has-error' : '' ?>"
                                    type="text" id="first_name" name="first_name"
                                    placeholder="First name" value="<?= old('first_name') ?>"
                                    autocomplete="given-name" required />
                                <span class="input-icon">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </span>
                            </div>
                            <?php if (isset($errors['first_name'])): ?>
                            <span class="field-error">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <?= h($errors['first_name']) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label for="last_name">Last Name</label>
                            <div class="input-wrap">
                                <input class="<?= isset($errors['last_name']) ? 'has-error' : '' ?>"
                                    type="text" id="last_name" name="last_name"
                                    placeholder="Last name" value="<?= old('last_name') ?>"
                                    autocomplete="family-name" required />
                                <span class="input-icon">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </span>
                            </div>
                            <?php if (isset($errors['last_name'])): ?>
                            <span class="field-error">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <?= h($errors['last_name']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="student_id">Student ID</label>
                        <div class="input-wrap">
                            <input class="<?= isset($errors['student_id']) ? 'has-error' : '' ?>"
                                type="text" id="student_id" name="student_id"
                                placeholder="e.g. 20251234-S" value="<?= old('student_id') ?>"
                                autocomplete="off" required />
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
                                </svg>
                            </span>
                        </div>
                        <?php if (isset($errors['student_id'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['student_id']) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="birthdate">Date of Birth</label>
                            <div class="input-wrap">
                                <input class="<?= isset($errors['birthdate']) ? 'has-error' : '' ?>"
                                    type="date" id="birthdate" name="birthdate"
                                    value="<?= old('birthdate') ?>"
                                    max="<?= date('Y-m-d', strtotime('-17 years')) ?>"
                                    required />
                                <span class="input-icon">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8"  y1="2" x2="8"  y2="6"/>
                                        <line x1="3"  y1="10" x2="21" y2="10"/>
                                    </svg>
                                </span>
                            </div>
                            <?php if (isset($errors['birthdate'])): ?>
                            <span class="field-error">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <?= h($errors['birthdate']) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label for="age">Age</label>
                            <div class="input-wrap">
                                <input type="number" id="age" name="age"
                                    placeholder="Auto-calculated"
                                    value="<?= h($displayAge) ?>"
                                    readonly />
                                <span class="input-icon">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div id="ageWarning" style="display:none;align-items:center;gap:8px;background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:#dc2626;margin-top:-6px;margin-bottom:4px;line-height:1.5"></div>

                    <div class="field">
                        <label for="phone">Phone Number</label>
                        <div class="input-wrap">
                            <input class="<?= isset($errors['phone']) ? 'has-error' : '' ?>"
                                type="tel" id="phone" name="phone"
                                placeholder="e.g. 09XX XXX XXXX"
                                value="<?= old('phone') ?>"
                                pattern="[0-9+\-\s]{7,15}"
                                autocomplete="tel" required />
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.7a16 16 0 0 0 6 6l.86-.86a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                            </span>
                        </div>
                        <?php if (isset($errors['phone'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['phone']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── SECTION 2: ACADEMIC INFO ── -->
                <div class="form-section">
                    <div class="section-label">Academic Info</div>

                    <div class="field">
                        <label for="program">Program / Course</label>
                        <div class="input-wrap">
                            <!-- Uses the $programs array defined at the top of the PHP section -->
                            <select id="program" name="program"
                                class="<?= isset($errors['program']) ? 'has-error' : '' ?>"
                                required>
                                <option value="" disabled <?= ($_POST['program'] ?? '') === '' ? 'selected' : '' ?>>
                                    &mdash; Select your program &mdash;
                                </option>
                                <?php foreach ($programs as $val => $label):
                                    $sel = (($_POST['program'] ?? '') === $val) ? 'selected' : '';
                                ?>
                                <option value="<?= h($val) ?>" <?= $sel ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                            </span>
                        </div>
                        <?php if (isset($errors['program'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['program']) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="section">Section</label>
                        <div class="input-wrap">
                            <input class="<?= isset($errors['section']) ? 'has-error' : '' ?>"
                                type="text" id="section" name="section"
                                placeholder="e.g. 1A, 2B, 3C"
                                value="<?= old('section') ?>"
                                maxlength="10" autocomplete="off" required />
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                                    <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                                </svg>
                            </span>
                        </div>
                        <?php if (isset($errors['section'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['section']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── SECTION 3: ACCOUNT SETUP ── -->
                <div class="form-section">
                    <div class="section-label">Account Setup</div>

                    <div class="field">
                        <label for="email">Email Address</label>
                        <div class="input-wrap">
                            <input class="<?= isset($errors['email']) ? 'has-error' : '' ?>"
                                type="email" id="email" name="email"
                                placeholder="yourname@email.com"
                                value="<?= old('email') ?>"
                                autocomplete="email" required />
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                    <polyline points="22,6 12,13 2,6"/>
                                </svg>
                            </span>
                        </div>
                        <?php if (isset($errors['email'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['email']) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-wrap password-wrap">
                            <input class="<?= isset($errors['password']) ? 'has-error' : '' ?>"
                                type="password" id="password" name="password"
                                placeholder="Create a strong password"
                                autocomplete="new-password" required />
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            <button type="button" class="toggle-pw" onclick="togglePassword('password','eyeIcon1')" title="Toggle password">
                                <svg id="eyeIcon1" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <div class="pw-bar-wrap"><div class="pw-bar" id="pwBar"></div></div>
                        <span class="pw-hint" id="pwHint">Min. 8 characters &middot; 1 uppercase &middot; 1 number</span>
                        <?php if (isset($errors['password'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['password']) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrap password-wrap">
                            <input class="<?= isset($errors['confirm_password']) ? 'has-error' : '' ?>"
                                type="password" id="confirm_password" name="confirm_password"
                                placeholder="Re-enter your password"
                                autocomplete="new-password" required />
                            <span class="input-icon">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            <button type="button" class="toggle-pw" onclick="togglePassword('confirm_password','eyeIcon2')" title="Toggle password">
                                <svg id="eyeIcon2" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <?php if (isset($errors['confirm_password'])): ?>
                        <span class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= h($errors['confirm_password']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <button class="btn-register" type="submit" id="submitBtn">
                    Create Account &rarr;
                </button>

            </form>
            <?php endif; ?>

        </div>
    </main>

</div>

<script>
    /* Toggle password visibility */
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>`;
        } else {
            input.type = 'password';
            icon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>`;
        }
    }

    /* Auto-calculate age + 17+ restriction */
    document.getElementById('birthdate').addEventListener('input', function () {
        const ageField  = document.getElementById('age');
        const ageWarn   = document.getElementById('ageWarning');
        const submitBtn = document.getElementById('submitBtn');
        if (!this.value) { ageField.value = ''; if (ageWarn) ageWarn.style.display = 'none'; return; }
        const dob = new Date(this.value), now = new Date();
        let age = now.getFullYear() - dob.getFullYear();
        const m = now.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age--;
        ageField.value = age >= 0 ? age : '';
        if (ageWarn) {
            if (age < 17) {
                ageWarn.style.display = 'flex';
                ageWarn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>&nbsp;You must be at least <strong>&nbsp;17 years old&nbsp;</strong> to register. You are currently ' + age + ' year' + (age !== 1 ? 's' : '') + ' old.';
                this.classList.add('has-error');
                if (submitBtn) submitBtn.disabled = true;
            } else {
                ageWarn.style.display = 'none';
                this.classList.remove('has-error');
                if (submitBtn) submitBtn.disabled = false;
            }
        }
    });

    /* Password strength bar */
    document.getElementById('password').addEventListener('input', function () {
        const pw   = this.value;
        const bar  = document.getElementById('pwBar');
        const hint = document.getElementById('pwHint');
        let score = 0;
        if (pw.length >= 8)          score++;
        if (/[A-Z]/.test(pw))        score++;
        if (/[0-9]/.test(pw))        score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        const colors = ['', '#e53e3e', '#dd6b20', '#d69e2e', '#38a169'];
        const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        bar.style.width      = (score / 4 * 100) + '%';
        bar.style.background = colors[score] || '';
        hint.textContent     = score > 0 ? labels[score] : 'Min. 8 characters \u00b7 1 uppercase \u00b7 1 number';
        hint.style.color     = score > 0 ? colors[score] : 'var(--slate)';
    });

    /* Live confirm-password match */
    document.getElementById('confirm_password').addEventListener('input', function () {
        const pw = document.getElementById('password').value;
        this.value && this.value !== pw
            ? this.classList.add('has-error')
            : this.classList.remove('has-error');
    });

    /* Prevent double-submit */
    document.getElementById('regForm')?.addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        btn.textContent = 'Creating account\u2026';
        btn.disabled = true;
    });
</script>
</body>
</html>