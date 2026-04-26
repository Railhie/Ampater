<?php
date_default_timezone_set('Asia/Manila');
require_once 'db.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    echo "No token provided";
    exit;
}

$nowPhp = date('Y-m-d H:i:s');
echo "PHP time now: " . $nowPhp . "<br><br>";

// Check the raw token row
$stmt = $pdo->prepare("SELECT *, NOW() as mysql_now FROM password_resets WHERE token = :tok LIMIT 1");
$stmt->execute([':tok' => $token]);
$row = $stmt->fetch();

if (!$row) {
    echo "Token NOT found in database at all!";
} else {
    echo "Token found!<br>";
    echo "used: " . $row['used'] . "<br>";
    echo "expires_at: " . $row['expires_at'] . "<br>";
    echo "MySQL NOW(): " . $row['mysql_now'] . "<br>";
    echo "PHP time: " . $nowPhp . "<br><br>";

    if ($row['used'] == 1) {
        echo "❌ Problem: Token is marked as USED";
    } elseif ($row['expires_at'] < $nowPhp) {
        echo "❌ Problem: Token is EXPIRED (PHP time)";
    } elseif ($row['expires_at'] < $row['mysql_now']) {
        echo "❌ Problem: Token is EXPIRED (MySQL time)";
    } else {
        echo "✅ Token looks valid!";
    }
}
