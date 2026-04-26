<?php
// ── db.php — Shared Database Connection ──────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'suffratech');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<div style="font-family:sans-serif;background:#fef2f2;border:2px solid #fca5a5;color:#dc2626;padding:24px;margin:40px auto;max-width:600px;border-radius:12px">
        <h2>Database Connection Error</h2>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <p style="font-size:13px;margin-top:12px;opacity:.7">Check your database credentials in <code>db.php</code>.</p>
    </div>');
}
