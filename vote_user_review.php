<?php

/**
 * vote_user_review.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Handles voter feedback / review submissions and retrieval for SuffraTech.
 *
 * GET  → returns all approved reviews + summary stats  (JSON)
 * POST → saves a new review from the logged-in voter     (JSON)
 *
 * Expected POST body (application/json):
 * {
 *   "rating"        : 1-5,
 *   "tags"          : "Easy to use, Fast & responsive",  // comma-separated
 *   "review_text"   : "Great experience!",
 *   "election_type" : "general" | "clas"
 * }
 *
 * Response shape (both GET and POST):
 * {
 *   "ok"         : true | false,
 *   "error"      : "...",          // only on failure
 *   "reviews"    : [...],          // on GET
 *   "avg_rating" : 4.2,            // on GET
 *   "total"      : 12              // on GET
 * }
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// ── Bootstrap / session ──────────────────────────────────────────────────────
// Adjust the path below to match your project's bootstrap / db include.
// The file must define: $pdo (PDO instance) and $_SESSION['user_id'].
require_once __DIR__ . '/includes/db.php';   // provides $pdo
session_start();

header('Content-Type: application/json; charset=utf-8');

// ── Helper: emit JSON and exit ────────────────────────────────────────────────
function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Auth guard ────────────────────────────────────────────────────────────────
$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!$userId) {
    jsonOut(['ok' => false, 'error' => 'Not authenticated.'], 401);
}

// ── Route by HTTP method ──────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ════════════════════════════════════════════════════════════════════════════
//  GET  —  return all reviews + summary
// ════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {

    try {
        // Fetch reviews joined with student names
        $stmt = $pdo->prepare("
            SELECT
                r.id,
                r.rating,
                r.tags,
                r.review_text,
                r.election_type,
                r.created_at,
                CONCAT(u.first_name, ' ', u.last_name) AS student_name
            FROM   voter_reviews r
            JOIN   users         u ON u.id = r.user_id
            ORDER  BY r.created_at DESC
            LIMIT  200
        ");
        $stmt->execute();
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Summary stats
        $statStmt = $pdo->query("
            SELECT
                COUNT(*)              AS total,
                ROUND(AVG(rating), 1) AS avg_rating
            FROM voter_reviews
        ");
        $stats = $statStmt->fetch(PDO::FETCH_ASSOC);

        jsonOut([
            'ok'         => true,
            'reviews'    => $reviews,
            'avg_rating' => $stats['avg_rating'] ? (float) $stats['avg_rating'] : null,
            'total'      => (int) $stats['total'],
        ]);
    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'error' => 'Database error.'], 500);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  POST  —  submit a review
// ════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {

    // Parse JSON body
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        jsonOut(['ok' => false, 'error' => 'Invalid request body.'], 400);
    }

    $rating       = isset($body['rating'])        ? (int)   $body['rating']        : 0;
    $tags         = isset($body['tags'])           ? trim((string) $body['tags'])   : '';
    $reviewText   = isset($body['review_text'])    ? trim((string) $body['review_text']) : '';
    $electionType = isset($body['election_type'])  ? trim((string) $body['election_type']) : 'general';

    // ── Validation ─────────────────────────────────────────────────────────
    if ($rating < 1 || $rating > 5) {
        jsonOut(['ok' => false, 'error' => 'Please select a star rating (1–5).'], 422);
    }
    if (strlen($reviewText) < 3) {
        jsonOut(['ok' => false, 'error' => 'Please write a comment before submitting.'], 422);
    }
    if (!in_array($electionType, ['general', 'clas'], true)) {
        $electionType = 'general';
    }

    // ── Duplicate check: one review per user per election ──────────────────
    try {
        $dupCheck = $pdo->prepare("
            SELECT id FROM voter_reviews
            WHERE user_id = :uid AND election_type = :et
            LIMIT 1
        ");
        $dupCheck->execute([':uid' => $userId, ':et' => $electionType]);

        if ($dupCheck->fetch()) {
            jsonOut(['ok' => false, 'error' => 'You have already submitted a review for this election.'], 409);
        }

        // ── Insert ─────────────────────────────────────────────────────────
        $insert = $pdo->prepare("
            INSERT INTO voter_reviews
                (user_id, rating, tags, review_text, election_type, created_at)
            VALUES
                (:uid, :rating, :tags, :review_text, :et, NOW())
        ");
        $insert->execute([
            ':uid'         => $userId,
            ':rating'      => $rating,
            ':tags'        => $tags,
            ':review_text' => $reviewText,
            ':et'          => $electionType,
        ]);

        jsonOut(['ok' => true, 'message' => 'Review submitted. Thank you!']);
    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'error' => 'Could not save your review. Please try again.'], 500);
    }
}

// ── Any other method ──────────────────────────────────────────────────────────
jsonOut(['ok' => false, 'error' => 'Method not allowed.'], 405);
