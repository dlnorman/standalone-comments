<?php
// Comment System API

// Load config.php if it exists, otherwise use defaults
if (file_exists(__DIR__ . '/config.php')) {
    require_once 'config.php';
}

// Define constants if not already defined by config.php
if (!defined('DB_PATH')) {
    // Auto-detect environment
    $isLocalhost = false;
    if (getenv('COMMENT_ENV') === 'development') {
        $isLocalhost = true;
    } elseif (isset($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
        $isLocalhost = (
            strpos($host, 'localhost') !== false ||
            strpos($host, '127.0.0.1') !== false ||
            strpos($host, '.local') !== false ||
            strpos($host, ':1313') !== false
        );
    } elseif (php_sapi_name() === 'cli-server') {
        $isLocalhost = true;
    }

    define('DB_PATH', __DIR__ . ($isLocalhost ? '/db/comments-dev.db' : '/db/comments.db'));
}

if (!defined('ADMIN_TOKEN_COOKIE')) {
    define('ADMIN_TOKEN_COOKIE', 'comment_admin_token');
}

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600 * 24 * 30); // 30 days
}

if (!defined('ALLOWED_ORIGINS')) {
    // Default CORS - allow all origins (can be restricted in config.php)
    define('ALLOWED_ORIGINS', ['*']);
}

// Set timezone if not already set
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// Error logging setup
if (!ini_get('error_log')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    ini_set('error_log', $logsDir . '/php-errors.log');
}

require_once 'database.php';

header('Content-Type: application/json');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");

// Cache control - prevent caching of API responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// CORS headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', ALLOWED_ORIGINS) || in_array($origin, ALLOWED_ORIGINS)) {
    if (in_array('*', ALLOWED_ORIGINS)) {
        header("Access-Control-Allow-Origin: *");
    } else {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = getDatabase();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Periodic housekeeping: prune rows that are no longer useful.
// Runs on ~1% of requests to avoid adding overhead to every call.
function periodicCleanup($db) {
    // vote_log is only used for rate-limiting within a 60-second window
    $db->exec("DELETE FROM vote_log WHERE created_at < datetime('now', '-2 hours')");
    // login_attempts are checked within a 1-hour window
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-2 hours')");
    // expired sessions
    $db->exec("DELETE FROM sessions WHERE expires_at < datetime('now')");
    // processed/failed email queue entries older than 30 days
    if (tableExists($db, 'email_queue')) {
        $db->exec("DELETE FROM email_queue WHERE status IN ('sent','failed') AND created_at < datetime('now', '-30 days')");
    }
}
if (rand(1, 100) === 1) {
    periodicCleanup($db);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function sanitizeUrl($url) {
    if (empty($url)) return null;
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
}

function isAdmin() {
    if (isset($_COOKIE[ADMIN_TOKEN_COOKIE])) {
        $token = $_COOKIE[ADMIN_TOKEN_COOKIE];
        $db = getDatabase();

        // Check if session exists and is not expired
        $stmt = $db->prepare("
            SELECT id FROM sessions
            WHERE token = ? AND expires_at > datetime('now')
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if ($session) {
            // Update last activity timestamp
            $updateStmt = $db->prepare("
                UPDATE sessions SET last_activity = datetime('now') WHERE id = ?
            ");
            $updateStmt->execute([$session['id']]);

            return true;
        }

        // Fallback to old token system for backward compatibility
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_token'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result && hash_equals($result['value'], $token);
    }
    return false;
}

function generateCSRFToken() {
    if (!isset($_COOKIE['csrf_token'])) {
        $token = bin2hex(random_bytes(32));
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
        setcookie('csrf_token', $token, time() + SESSION_LIFETIME, '/comments/', '', $isSecure, false); // Not HTTPOnly - JS needs to read it
        return $token;
    }
    return $_COOKIE['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token);
}

function checkRateLimit($ipAddress, $email) {
    // Skip rate limiting for logged-in admins (for testing)
    if (isAdmin()) {
        return ['limited' => false];
    }

    $db = getDatabase();

    // Check IP-based rate limiting (5 comments per hour)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM comments
        WHERE ip_address = ? AND created_at > datetime('now', '-1 hour')
    ");
    $stmt->execute([$ipAddress]);
    $result = $stmt->fetch();

    if ($result['count'] >= 5) {
        return ['limited' => true, 'reason' => 'Too many comments from your IP address. Please try again later.'];
    }

    // Check email-based rate limiting (3 comments per 10 minutes)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM comments
        WHERE author_email = ? AND created_at > datetime('now', '-10 minutes')
    ");
    $stmt->execute([$email]);
    $result = $stmt->fetch();

    if ($result['count'] >= 3) {
        return ['limited' => true, 'reason' => 'Too many comments in a short time. Please wait a few minutes.'];
    }

    return ['limited' => false];
}

function detectSpam($content, $authorName, $authorEmail, $authorUrl) {
    $spamScore = 0;

    // Check for excessive links
    $linkCount = preg_match_all('/(https?:\/\/|www\.)/i', $content);
    if ($linkCount > 3) {
        $spamScore += 2;
    }

    // Check for common spam keywords
    $spamKeywords = ['viagra', 'cialis', 'pharmacy', 'poker', 'casino', 'loan', 'mortgage', 'seo services', 'buy now'];
    foreach ($spamKeywords as $keyword) {
        if (stripos($content, $keyword) !== false || stripos($authorName, $keyword) !== false) {
            $spamScore += 3;
        }
    }

    // Check for excessive capitalization
    if (preg_match('/[A-Z]{10,}/', $content)) {
        $spamScore += 1;
    }

    // Check for suspicious email domains
    $suspiciousDomains = ['example.com', 'test.com', 'tempmail', 'disposable'];
    foreach ($suspiciousDomains as $domain) {
        if (stripos($authorEmail, $domain) !== false) {
            $spamScore += 1;
        }
    }

    // Check content length (too short or too long)
    $contentLength = strlen($content);
    if ($contentLength < 10) {
        $spamScore += 1;
    }
    if ($contentLength > 4000) {
        $spamScore += 1;
    }

    // If spam score is high, auto-mark as spam
    return $spamScore >= 4;
}

function sanitizeEmailContent($input) {
    // Remove characters that could be used for email header injection
    // Strip newlines, carriage returns, and URL-encoded versions
    return str_replace(["\r", "\n", "%0a", "%0d", "\x0A", "\x0D"], '', $input);
}

function queueEmail($commentId, $recipientEmail, $recipientName, $emailType, $subject, $body) {
    try {
        $db = getDatabase();
        if (!$db) {
            error_log("Failed to get database connection for email queue");
            return false;
        }

        // Validate email address before queuing
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address, not queuing: $recipientEmail");
            return false;
        }

        $stmt = $db->prepare("
            INSERT INTO email_queue (comment_id, recipient_email, recipient_name, email_type, subject, body, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");

        return $stmt->execute([$commentId, $recipientEmail, $recipientName, $emailType, $subject, $body]);
    } catch (PDOException $e) {
        error_log("Failed to queue email: " . $e->getMessage());
        // Don't throw - just log and return false so comment posting can continue
        return false;
    }
}

function checkLoginRateLimit($ipAddress) {
    $db = getDatabase();

    // Allow 5 login attempts per hour per IP
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM login_attempts
        WHERE ip_address = ? AND attempted_at > datetime('now', '-1 hour')
    ");
    $stmt->execute([$ipAddress]);
    $result = $stmt->fetch();

    if ($result['count'] >= 5) {
        return ['limited' => true, 'reason' => 'Too many login attempts. Please try again later.'];
    }

    return ['limited' => false];
}

function recordLoginAttempt($ipAddress, $success) {
    $db = getDatabase();

    $stmt = $db->prepare("
        INSERT INTO login_attempts (ip_address, success, attempted_at)
        VALUES (?, ?, datetime('now'))
    ");

    return $stmt->execute([$ipAddress, $success ? 1 : 0]);
}

function sendNotificationEmail($commentId, $pageUrl, $parentId, $authorName, $content, $authorEmail) {
    $db = getDatabase();

    // Check if notifications are enabled
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'enable_notifications'");
    $stmt->execute();
    $result = $stmt->fetch();

    if (!$result || $result['value'] !== 'true') {
        return; // Notifications disabled
    }

    // Sanitize all user input to prevent email header injection
    $safeAuthorName = sanitizeEmailContent($authorName);
    $safeContent = sanitizeEmailContent($content);
    $safePageUrl = sanitizeEmailContent($pageUrl);

    // Track who has been notified to prevent duplicates
    $notifiedEmails = [];

    // If this is a reply, notify the parent comment author first with personalized message
    if ($parentId !== null) {
        $stmt = $db->prepare("SELECT author_email, author_name FROM comments WHERE id = ?");
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();

        if ($parent && $parent['author_email'] && $parent['author_email'] !== $authorEmail) {
            $safeParentName = sanitizeEmailContent($parent['author_name']);

            // Get unsubscribe token for parent
            $stmt = $db->prepare("SELECT token FROM subscriptions WHERE page_url = ? AND email = ?");
            $stmt->execute([$pageUrl, $parent['author_email']]);
            $subData = $stmt->fetch();
            $unsubscribeUrl = $subData ? "https://" . $_SERVER['HTTP_HOST'] . "/comments/unsubscribe.php?token=" . $subData['token'] : "";

            $subject = "New reply to your comment";
            $message = "Hello {$safeParentName},\n\n";
            $message .= "{$safeAuthorName} replied to your comment on {$safePageUrl}:\n\n";
            $message .= "{$safeContent}\n\n";
            $message .= "View and reply: {$safePageUrl}#comment-{$commentId}\n\n";
            if ($unsubscribeUrl) {
                $message .= "---\n";
                $message .= "To unsubscribe from notifications: {$unsubscribeUrl}\n";
            }

            // Queue email instead of sending immediately
            queueEmail($commentId, $parent['author_email'], $safeParentName, 'parent_reply', $subject, $message);

            // Mark this email as notified
            $notifiedEmails[] = $parent['author_email'];
        }
    }

    // Get all active subscribers for this page (excluding the comment author and those already notified)
    $stmt = $db->prepare("
        SELECT email, token FROM subscriptions
        WHERE page_url = ? AND active = 1 AND email != ?
    ");
    $stmt->execute([$pageUrl, $authorEmail]);
    $subscribers = $stmt->fetchAll();

    // Queue notification emails to all subscribers who haven't been notified yet
    foreach ($subscribers as $subscriber) {
        // Skip if already notified
        if (in_array($subscriber['email'], $notifiedEmails)) {
            continue;
        }

        $unsubscribeUrl = "https://" . $_SERVER['HTTP_HOST'] . "/comments/unsubscribe.php?token=" . $subscriber['token'];

        $subject = "New comment on " . parse_url($pageUrl, PHP_URL_PATH);
        $message = "Hello,\n\n";
        $message .= "{$safeAuthorName} posted a new comment on {$safePageUrl}:\n\n";
        $message .= "{$safeContent}\n\n";
        $message .= "View and reply: {$safePageUrl}#comment-{$commentId}\n\n";
        $message .= "---\n";
        $message .= "To unsubscribe from notifications for this page: {$unsubscribeUrl}\n";

        // Queue email instead of sending immediately
        queueEmail($commentId, $subscriber['email'], '', 'subscriber', $subject, $message);
    }

    // Notify admin of new comment
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_email'");
    $stmt->execute();
    $result = $stmt->fetch();

    if ($result && !empty($result['value'])) {
        $subject = "New comment on your site";
        $message = "New comment from {$safeAuthorName} on {$safePageUrl}:\n\n";
        $message .= "{$safeContent}\n\n";
        $message .= "Manage comments: https://" . $_SERVER['HTTP_HOST'] . "/comments/admin.html\n";

        // Queue admin email instead of sending immediately
        queueEmail($commentId, $result['value'], 'Admin', 'admin', $subject, $message);
    }
}

function sendPostReactionNotificationEmail($pageUrl, $reactionType) {
    $db = getDatabase();

    // Check if notifications are enabled
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'enable_notifications'");
    $stmt->execute();
    $result = $stmt->fetch();
    if (!$result || $result['value'] !== 'true') {
        return;
    }

    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_email'");
    $stmt->execute();
    $result = $stmt->fetch();
    if (!$result || empty($result['value'])) {
        return;
    }
    $adminEmail = $result['value'];

    $reactionLabels = [
        'heart'     => '♥ heart',
        'thumbsup'  => '👍 thumbs up',
        'lightbulb' => '💡 lightbulb',
        'funny'     => '😄 laugh',
    ];
    $reactionLabel = $reactionLabels[$reactionType] ?? $reactionType;
    $fullPageUrl = "https://" . $_SERVER['HTTP_HOST'] . $pageUrl;
    $safePageUrl = sanitizeEmailContent($fullPageUrl);

    $subject = "New post reaction on your site";
    $message = "Someone left a {$reactionLabel} reaction on {$safePageUrl}.\n\n";
    $message .= "View post reactions: https://" . $_SERVER['HTTP_HOST'] . "/comments/admin-post-reactions.html\n";

    queueEmail(null, $adminEmail, 'Admin', 'post_reaction', $subject, $message);
}

function sendReactionNotificationEmail($commentId, $pageUrl, $authorName, $authorEmail, $reactionType) {
    if (empty($authorEmail)) {
        return;
    }

    $db = getDatabase();

    // Check if notifications are enabled
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'enable_notifications'");
    $stmt->execute();
    $result = $stmt->fetch();
    if (!$result || $result['value'] !== 'true') {
        return;
    }

    $reactionLabels = [
        'heart'     => '♥ heart',
        'thumbsup'  => '👍 thumbs up',
        'lightbulb' => '💡 lightbulb',
        'funny'     => '😄 laugh',
    ];
    $reactionLabel = $reactionLabels[$reactionType] ?? $reactionType;

    $safeAuthorName = sanitizeEmailContent($authorName);
    $fullPageUrl = "https://" . $_SERVER['HTTP_HOST'] . $pageUrl;
    $safePageUrl = sanitizeEmailContent($fullPageUrl);

    // Get unsubscribe token if they have a subscription
    $stmt = $db->prepare("SELECT token FROM subscriptions WHERE page_url = ? AND email = ?");
    $stmt->execute([$pageUrl, $authorEmail]);
    $subData = $stmt->fetch();
    $unsubscribeUrl = $subData ? "https://" . $_SERVER['HTTP_HOST'] . "/comments/unsubscribe.php?token=" . $subData['token'] : "";

    $subject = "Someone reacted to your comment";
    $message = "Hello {$safeAuthorName},\n\n";
    $message .= "Someone left a {$reactionLabel} reaction on your comment at {$safePageUrl}.\n\n";
    $message .= "View your comment: {$safePageUrl}#comment-{$commentId}\n\n";
    if ($unsubscribeUrl) {
        $message .= "---\n";
        $message .= "To unsubscribe from notifications: {$unsubscribeUrl}\n";
    }

    queueEmail($commentId, $authorEmail, $safeAuthorName, 'reaction', $subject, $message);
}

// GET /api.php?action=comments&url=...
if ($method === 'GET' && $action === 'comments') {
    $pageUrl = $_GET['url'] ?? '';
    if (empty($pageUrl)) {
        jsonResponse(['error' => 'URL is required'], 400);
    }

    // Add pagination support to prevent memory overflow with large comment counts
    $limit = isset($_GET['limit']) ? min(max(1, (int)$_GET['limit']), 1000) : 500;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $status = isAdmin() ? ['pending', 'approved'] : ['approved'];
    $placeholders = implode(',', array_fill(0, count($status), '?'));

    // Get total count for pagination metadata
    $countStmt = $db->prepare("
        SELECT COUNT(*) as total FROM comments
        WHERE page_url = ? AND status IN ($placeholders)
    ");
    $countStmt->execute(array_merge([$pageUrl], $status));
    $countResult = $countStmt->fetch();
    $total = $countResult['total'];

    $stmt = $db->prepare("
        SELECT c.id, c.page_url, c.parent_id, c.author_name, c.author_email, c.author_url,
               c.content, c.created_at, c.status,
               COALESCE(v.votes_heart, 0) AS votes_heart,
               COALESCE(v.votes_thumbsup, 0) AS votes_thumbsup,
               COALESCE(v.votes_lightbulb, 0) AS votes_lightbulb,
               COALESCE(v.votes_funny, 0) AS votes_funny
        FROM comments c
        LEFT JOIN (
            SELECT comment_id,
                   SUM(reaction_type = 'heart') AS votes_heart,
                   SUM(reaction_type = 'thumbsup') AS votes_thumbsup,
                   SUM(reaction_type = 'lightbulb') AS votes_lightbulb,
                   SUM(reaction_type = 'funny') AS votes_funny
            FROM votes
            GROUP BY comment_id
        ) v ON v.comment_id = c.id
        WHERE c.page_url = ? AND c.status IN ($placeholders)
        ORDER BY c.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge([$pageUrl], $status, [$limit, $offset]));
    $comments = $stmt->fetchAll();

    // Build threaded structure
    $threaded = [];
    $lookup = [];

    foreach ($comments as $comment) {
        $comment['replies'] = [];
        // Don't expose email to non-admins
        if (!isAdmin()) {
            unset($comment['author_email']);
        }
        $lookup[$comment['id']] = $comment;
    }

    foreach ($lookup as $id => $comment) {
        if ($comment['parent_id'] === null) {
            $threaded[] = &$lookup[$id];
        } else if (isset($lookup[$comment['parent_id']])) {
            $lookup[$comment['parent_id']]['replies'][] = &$lookup[$id];
        }
    }

    // Fetch post-level reaction counts for this page (single query)
    $postReactions = ['heart' => 0, 'thumbsup' => 0, 'lightbulb' => 0, 'funny' => 0];
    $prStmt = $db->prepare("SELECT reaction_type, COUNT(*) as count FROM post_reactions WHERE page_url = ? GROUP BY reaction_type");
    $prStmt->execute([$pageUrl]);
    foreach ($prStmt->fetchAll() as $row) {
        if (isset($postReactions[$row['reaction_type']])) {
            $postReactions[$row['reaction_type']] = (int)$row['count'];
        }
    }

    jsonResponse([
        'comments' => $threaded,
        'post_reactions' => $postReactions,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ]
    ]);
}

// GET /api.php?action=recent&limit=10
// Public endpoint for displaying recent comments site-wide
if ($method === 'GET' && $action === 'recent') {
    $limit = isset($_GET['limit']) ? min(max(1, (int)$_GET['limit']), 100) : 10;

    $stmt = $db->prepare("
        SELECT id, page_url, author_name, author_url,
               content, created_at
        FROM comments
        WHERE status = 'approved'
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $comments = $stmt->fetchAll();

    // Trim content to excerpt for display
    foreach ($comments as &$comment) {
        if (strlen($comment['content']) > 150) {
            $comment['excerpt'] = substr($comment['content'], 0, 150) . '...';
        } else {
            $comment['excerpt'] = $comment['content'];
        }
    }

    jsonResponse(['comments' => $comments]);
}

// POST /api.php?action=vote
// Toggle a reaction on a comment (one per IP address per reaction type)
if ($method === 'POST' && $action === 'vote') {
    $input = getInput();
    $commentId = isset($input['comment_id']) ? (int)$input['comment_id'] : 0;

    if ($commentId <= 0) {
        jsonResponse(['error' => 'Invalid comment ID'], 400);
    }

    $allowedTypes = ['heart', 'thumbsup', 'lightbulb', 'funny'];
    $reactionType = $input['reaction_type'] ?? 'heart';
    if (!in_array($reactionType, $allowedTypes)) {
        jsonResponse(['error' => 'Invalid reaction type'], 400);
    }

    // Verify the comment exists and is approved
    $checkStmt = $db->prepare("SELECT id, author_name, author_email, page_url FROM comments WHERE id = ? AND status = 'approved'");
    $checkStmt->execute([$commentId]);
    $comment = $checkStmt->fetch();
    if (!$comment) {
        jsonResponse(['error' => 'Comment not found'], 404);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: max 15 vote actions per IP per 60 seconds
    $rateStmt = $db->prepare("SELECT COUNT(*) as count FROM vote_log WHERE ip_address = ? AND created_at > datetime('now', '-60 seconds')");
    $rateStmt->execute([$ip]);
    if ((int)$rateStmt->fetch()['count'] >= 15) {
        jsonResponse(['error' => 'Too many reactions. Please slow down.'], 429);
    }

    // Check if this IP has already used this reaction on this comment
    $existsStmt = $db->prepare("SELECT id FROM votes WHERE comment_id = ? AND ip_address = ? AND reaction_type = ?");
    $existsStmt->execute([$commentId, $ip, $reactionType]);
    $existing = $existsStmt->fetch();

    if ($existing) {
        // Already reacted — remove it (toggle off)
        $db->prepare("DELETE FROM votes WHERE comment_id = ? AND ip_address = ? AND reaction_type = ?")->execute([$commentId, $ip, $reactionType]);
        $voted = false;
    } else {
        // New reaction — insert it
        $db->prepare("INSERT INTO votes (comment_id, ip_address, reaction_type) VALUES (?, ?, ?)")->execute([$commentId, $ip, $reactionType]);
        $voted = true;
    }

    // Log this action for rate limiting
    $db->prepare("INSERT INTO vote_log (ip_address) VALUES (?)")->execute([$ip]);

    // Notify comment author of new reaction
    if ($voted) {
        sendReactionNotificationEmail($commentId, $comment['page_url'], $comment['author_name'], $comment['author_email'], $reactionType);
    }

    // Return per-type counts
    $counts = [];
    foreach ($allowedTypes as $type) {
        $countStmt = $db->prepare("SELECT COUNT(*) as count FROM votes WHERE comment_id = ? AND reaction_type = ?");
        $countStmt->execute([$commentId, $type]);
        $counts[$type] = (int)$countStmt->fetch()['count'];
    }

    jsonResponse(['voted' => $voted, 'reaction_type' => $reactionType, 'counts' => $counts]);
}

// POST /api.php?action=post
if ($method === 'POST' && $action === 'post') {
    $input = getInput();

    $pageUrl = $input['page_url'] ?? '';
    $parentId = $input['parent_id'] ?? null;
    $authorName = trim($input['author_name'] ?? '');
    $authorEmail = trim($input['author_email'] ?? '');
    $authorUrl = sanitizeUrl($input['author_url'] ?? '');
    $content = trim($input['content'] ?? '');
    $subscribe = $input['subscribe'] ?? false;
    $honeypot = $input['website'] ?? ''; // Honeypot field

    // Honeypot check - if filled, it's likely a bot
    if (!empty($honeypot)) {
        jsonResponse(['error' => 'Invalid submission'], 400);
    }

    // Validation
    $errors = [];
    if (empty($pageUrl)) $errors[] = 'URL is required';
    if (empty($authorName)) $errors[] = 'Name is required';
    if (empty($authorEmail) || !validateEmail($authorEmail)) $errors[] = 'Valid email is required';
    if (empty($content)) $errors[] = 'Comment content is required';
    if (strlen($content) > 5000) $errors[] = 'Comment is too long';

    if (!empty($errors)) {
        jsonResponse(['error' => implode(', ', $errors)], 400);
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Rate limiting
    $rateLimitCheck = checkRateLimit($ipAddress, $authorEmail);
    if ($rateLimitCheck['limited']) {
        jsonResponse(['error' => $rateLimitCheck['reason']], 429);
    }

    // Spam detection
    $isSpam = detectSpam($content, $authorName, $authorEmail, $authorUrl);

    // Check if parent exists if specified
    if ($parentId !== null) {
        $stmt = $db->prepare("SELECT id FROM comments WHERE id = ?");
        $stmt->execute([$parentId]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Parent comment not found'], 404);
        }
    }

    // Get moderation setting
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'require_moderation'");
    $stmt->execute();
    $moderation = $stmt->fetch();

    // Check if this email has previously approved comments (trusted commenter)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM comments
        WHERE author_email = ? AND status = 'approved'
    ");
    $stmt->execute([$authorEmail]);
    $result = $stmt->fetch();
    $isTrustedCommenter = $result['count'] > 0;

    // Determine status: spam > trusted > moderation > approved
    if ($isSpam) {
        $status = 'spam';
    } else if ($isTrustedCommenter) {
        $status = 'approved'; // Auto-approve trusted commenters
    } else {
        $status = ($moderation && $moderation['value'] === 'true') ? 'pending' : 'approved';
    }

    // Insert comment with explicit timestamp in configured timezone
    $now = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        INSERT INTO comments (page_url, parent_id, author_name, author_email, author_url,
                             content, status, ip_address, user_agent, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $pageUrl, $parentId, $authorName, $authorEmail, $authorUrl,
        $content, $status, $ipAddress, $userAgent, $now, $now
    ]);

    $commentId = $db->lastInsertId();

    // Handle subscription preference
    if ($subscribe && $status !== 'spam') {
        $token = bin2hex(random_bytes(32));
        $subscribeTime = date('Y-m-d H:i:s');
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO subscriptions (page_url, email, token, subscribed_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$pageUrl, $authorEmail, $token, $subscribeTime]);
    }

    // If not spam and approved/pending, send notification
    if ($status !== 'spam') {
        // Send notification email (if enabled)
        sendNotificationEmail($commentId, $pageUrl, $parentId, $authorName, $content, $authorEmail);
    }

    // Generate appropriate message
    $message = 'Comment posted successfully';
    if ($status === 'spam') {
        $message = 'Comment marked as spam';
    } else if ($status === 'pending') {
        $message = 'Comment submitted for moderation';
    } else if ($isTrustedCommenter) {
        $message = 'Comment posted successfully (auto-approved)';
    }

    jsonResponse([
        'success' => true,
        'id' => $commentId,
        'status' => $status,
        'message' => $message,
        'trusted' => $isTrustedCommenter
    ], 201);
}

// GET /api.php?action=csrf_token
if ($method === 'GET' && $action === 'csrf_token') {
    $token = generateCSRFToken();
    jsonResponse(['token' => $token]);
}

// POST /api.php?action=login (admin)
if ($method === 'POST' && $action === 'login') {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Check login rate limiting
    $rateLimit = checkLoginRateLimit($ipAddress);
    if ($rateLimit['limited']) {
        jsonResponse(['error' => $rateLimit['reason']], 429);
    }

    $input = getInput();
    $password = $input['password'] ?? '';

    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_password_hash'");
    $stmt->execute();
    $result = $stmt->fetch();

    if ($result && password_verify($password, $result['value'])) {
        // Record successful login attempt
        recordLoginAttempt($ipAddress, true);

        $token = bin2hex(random_bytes(32));
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Create new session in sessions table
        $stmt = $db->prepare("
            INSERT INTO sessions (token, expires_at, ip_address, user_agent)
            VALUES (?, datetime('now', '+30 days'), ?, ?)
        ");
        $stmt->execute([$token, $ipAddress, $userAgent]);

        // Also store in old settings table for backward compatibility
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('admin_token', ?)");
        $stmt->execute([$token]);

        // Set secure cookie (HTTPS only in production)
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
        setcookie(ADMIN_TOKEN_COOKIE, $token, time() + SESSION_LIFETIME, '/comments/', '', $isSecure, true);

        // Generate CSRF token for this session
        $csrfToken = generateCSRFToken();

        jsonResponse(['success' => true, 'message' => 'Logged in successfully', 'csrf_token' => $csrfToken]);
    } else {
        // Record failed login attempt
        recordLoginAttempt($ipAddress, false);

        jsonResponse(['error' => 'Invalid password'], 401);
    }
}

// POST /api.php?action=logout (admin)
if ($method === 'POST' && $action === 'logout') {
    if (isset($_COOKIE[ADMIN_TOKEN_COOKIE])) {
        $token = $_COOKIE[ADMIN_TOKEN_COOKIE];
        // Invalidate the session in the database
        $stmt = $db->prepare("DELETE FROM sessions WHERE token = ?");
        $stmt->execute([$token]);
        // Clear the admin_token fallback too
        $stmt = $db->prepare("DELETE FROM settings WHERE key = 'admin_token' AND value = ?");
        $stmt->execute([$token]);
    }
    // Expire the cookie
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    setcookie(ADMIN_TOKEN_COOKIE, '', time() - 3600, '/comments/', '', $isSecure, true);
    setcookie('csrf_token', '', time() - 3600, '/comments/', '', $isSecure, false);
    jsonResponse(['success' => true]);
}

// PUT /api.php?action=moderate&id=...
if ($method === 'PUT' && $action === 'moderate') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();

    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $id = $_GET['id'] ?? '';
    $status = $input['status'] ?? '';

    if (!in_array($status, ['approved', 'spam', 'deleted'])) {
        jsonResponse(['error' => 'Invalid status'], 400);
    }

    $stmt = $db->prepare("UPDATE comments SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    jsonResponse(['success' => true, 'message' => 'Comment updated']);
}

// DELETE /api.php?action=delete&id=...
if ($method === 'DELETE' && $action === 'delete') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Validate CSRF token from query parameter (since DELETE can't have body)
    $csrfToken = $_GET['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $id = $_GET['id'] ?? '';
    $stmt = $db->prepare("DELETE FROM comments WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'Comment deleted']);
}

// GET /api.php?action=pending (admin)
if ($method === 'GET' && $action === 'pending') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Add pagination to prevent browser crashes with large datasets
    $limit = isset($_GET['limit']) ? min(max(1, (int)$_GET['limit']), 10000) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // Get total count
    $countStmt = $db->query("SELECT COUNT(*) as total FROM comments WHERE status = 'pending'");
    $countResult = $countStmt->fetch();
    $total = $countResult['total'];

    $stmt = $db->prepare("
        SELECT c.id, c.page_url, c.parent_id, c.author_name, c.author_email, c.author_url,
               c.content, c.created_at, c.status, c.ip_address,
               COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'heart'), 0) AS votes_heart,
               COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'thumbsup'), 0) AS votes_thumbsup,
               COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'lightbulb'), 0) AS votes_lightbulb,
               COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'funny'), 0) AS votes_funny
        FROM comments c
        WHERE c.status = 'pending'
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $comments = $stmt->fetchAll();

    jsonResponse([
        'comments' => $comments,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ]
    ]);
}

// GET /api.php?action=all (admin)
if ($method === 'GET' && $action === 'all') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $limit        = isset($_GET['limit'])  ? min(max(1, (int)$_GET['limit']), 100) : 50;
    $offset       = isset($_GET['offset']) ? max(0, (int)$_GET['offset'])           : 0;
    $statusFilter = trim($_GET['status']   ?? 'all');
    $search       = trim($_GET['search']   ?? '');

    // Build WHERE clause from filters
    $where  = [];
    $params = [];
    if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'approved', 'spam', 'deleted'])) {
        $where[]  = 'c.status = ?';
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $where[]  = '(c.author_name LIKE ? OR c.author_email LIKE ? OR c.page_url LIKE ? OR c.content LIKE ?)';
        $s = '%' . $search . '%';
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }
    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Filtered count (for pagination)
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM comments c $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['total'];

    // Unfiltered status aggregates (for the stat cards — always full-table)
    $aggrRows = $db->query("SELECT status, COUNT(*) as count FROM comments GROUP BY status")->fetchAll();
    $aggregates = ['pending' => 0, 'approved' => 0, 'spam' => 0, 'deleted' => 0];
    foreach ($aggrRows as $row) {
        if (isset($aggregates[$row['status']])) {
            $aggregates[$row['status']] = (int)$row['count'];
        }
    }

    // Use a single LEFT JOIN for vote counts instead of 4 correlated subqueries
    $stmt = $db->prepare("
        SELECT c.id, c.page_url, c.parent_id, c.author_name, c.author_email, c.author_url,
               c.content, c.created_at, c.status, c.ip_address,
               COALESCE(v.votes_heart, 0)     AS votes_heart,
               COALESCE(v.votes_thumbsup, 0)  AS votes_thumbsup,
               COALESCE(v.votes_lightbulb, 0) AS votes_lightbulb,
               COALESCE(v.votes_funny, 0)     AS votes_funny
        FROM comments c
        LEFT JOIN (
            SELECT comment_id,
                   SUM(reaction_type = 'heart')     AS votes_heart,
                   SUM(reaction_type = 'thumbsup')  AS votes_thumbsup,
                   SUM(reaction_type = 'lightbulb') AS votes_lightbulb,
                   SUM(reaction_type = 'funny')     AS votes_funny
            FROM votes GROUP BY comment_id
        ) v ON v.comment_id = c.id
        $whereSQL
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $comments = $stmt->fetchAll();

    jsonResponse([
        'comments'   => $comments,
        'aggregates' => $aggregates,
        'pagination' => [
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => ($offset + $limit) < $total,
        ],
    ]);
}

// GET /api.php?action=subscriptions (admin)
if ($method === 'GET' && $action === 'subscriptions') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Add pagination to prevent browser crashes with large datasets
    $limit = isset($_GET['limit']) ? min(max(1, (int)$_GET['limit']), 10000) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // Get total count
    $countStmt = $db->query("SELECT COUNT(*) as total FROM subscriptions");
    $countResult = $countStmt->fetch();
    $total = $countResult['total'];

    $stmt = $db->prepare("
        SELECT id, page_url, email, token, subscribed_at, active
        FROM subscriptions
        ORDER BY subscribed_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $subscriptions = $stmt->fetchAll();

    jsonResponse([
        'subscriptions' => $subscriptions,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ]
    ]);
}

// POST /api.php?action=toggle_subscription (admin)
if ($method === 'POST' && $action === 'toggle_subscription') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();

    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $token = $input['token'] ?? '';
    $active = $input['active'] ?? 1;

    $stmt = $db->prepare("UPDATE subscriptions SET active = ? WHERE token = ?");
    $stmt->execute([$active, $token]);

    jsonResponse(['success' => true, 'message' => 'Subscription updated']);
}

// DELETE /api.php?action=delete_subscription&token=... (admin)
if ($method === 'DELETE' && $action === 'delete_subscription') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Validate CSRF token from query parameter
    $csrfToken = $_GET['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $token = $_GET['token'] ?? '';
    $stmt = $db->prepare("DELETE FROM subscriptions WHERE token = ?");
    $stmt->execute([$token]);

    jsonResponse(['success' => true, 'message' => 'Subscription deleted']);
}

// POST /api.php?action=test_email (admin)
if ($method === 'POST' && $action === 'test_email') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();

    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $testEmail = $input['email'] ?? '';
    $pageUrl = $input['page_url'] ?? '/';

    if (!validateEmail($testEmail)) {
        jsonResponse(['error' => 'Invalid email address'], 400);
    }

    // Sanitize inputs
    $safeEmail = sanitizeEmailContent($testEmail);
    $safePageUrl = sanitizeEmailContent($pageUrl);

    $subject = "Test Email from Comment System";
    $message = "This is a test email from your comment notification system.\n\n";
    $message .= "If you receive this, email notifications are working correctly!\n\n";
    $message .= "Test details:\n";
    $message .= "- Page URL: {$safePageUrl}\n";
    $message .= "- Sent at: " . date('Y-m-d H:i:s') . "\n";
    $message .= "- Server: " . $_SERVER['HTTP_HOST'] . "\n\n";
    $message .= "---\n";
    $message .= "This was a test email sent from the admin panel.\n";

    $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";

    $result = @mail($testEmail, $subject, $message, $headers);

    if ($result) {
        jsonResponse([
            'success' => true,
            'message' => 'Test email sent successfully! Check your inbox (and spam folder).'
        ]);
    } else {
        jsonResponse([
            'error' => 'Failed to send email. Check server mail configuration.',
            'debug' => 'mail() function returned false'
        ], 500);
    }
}

// GET /api.php?action=export_disqus (admin)
if ($method === 'GET' && $action === 'export_disqus') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Fetch all approved/pending comments (skip spam/deleted)
    $stmt = $db->query("
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status, ip_address
        FROM comments
        WHERE status != 'spam'
        ORDER BY created_at ASC
    ");
    $comments = $stmt->fetchAll();

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="disqus_export_' . date('Y-m-d') . '.xml"');
    header('Cache-Control: no-cache');

    // Base URL for constructing full URLs from relative paths
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    $forum   = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST']);

    // Build thread map: page_url -> sequential thread dsq:id
    $threadMap = [];
    $threadId  = 1;
    foreach ($comments as $comment) {
        if (!isset($threadMap[$comment['page_url']])) {
            $threadMap[$comment['page_url']] = $threadId++;
        }
    }

    $e = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
    $fullUrl = function ($pageUrl) use ($baseUrl) {
        return (strpos($pageUrl, 'http') === 0) ? $pageUrl : $baseUrl . $pageUrl;
    };
    $isoDate = fn($ts) => gmdate('Y-m-d\TH:i:s\Z', strtotime($ts));

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<disqus' . "\n";
    echo '  xmlns="http://disqus.com"' . "\n";
    echo '  xmlns:dsq="http://disqus.com/disqus-internals"' . "\n";
    echo '  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
    echo '  xsi:schemaLocation="http://disqus.com http://disqus.com/api/schemas/1.0/disqus.xsd">' . "\n\n";

    // Single default category
    echo '  <category dsq:id="1">' . "\n";
    echo '    <forum>' . $e($forum) . '</forum>' . "\n";
    echo '    <title>General</title>' . "\n";
    echo '    <isDefault>true</isDefault>' . "\n";
    echo '  </category>' . "\n\n";

    // One thread per unique page
    foreach ($threadMap as $pageUrl => $tid) {
        $url = $fullUrl($pageUrl);
        echo '  <thread dsq:id="' . $tid . '">' . "\n";
        echo '    <id>' . $e($url) . '</id>' . "\n";
        echo '    <forum>' . $e($forum) . '</forum>' . "\n";
        echo '    <category dsq:id="1"/>' . "\n";
        echo '    <link>' . $e($url) . '</link>' . "\n";
        echo '    <title>' . $e($url) . '</title>' . "\n";
        echo '    <createdAt>' . gmdate('Y-m-d\TH:i:s\Z') . '</createdAt>' . "\n";
        echo '    <isClosed>false</isClosed>' . "\n";
        echo '    <isDeleted>false</isDeleted>' . "\n";
        echo '  </thread>' . "\n\n";
    }

    // One post per comment
    foreach ($comments as $comment) {
        $tid      = $threadMap[$comment['page_url']];
        $isSpam   = $comment['status'] === 'spam'    ? 'true' : 'false';
        $approved = $comment['status'] === 'approved' ? 'true' : 'false';

        echo '  <post dsq:id="' . $comment['id'] . '">' . "\n";
        echo '    <thread dsq:id="' . $tid . '"/>' . "\n";
        if ($comment['parent_id']) {
            echo '    <parent dsq:id="' . $comment['parent_id'] . '"/>' . "\n";
        }
        echo '    <author>' . "\n";
        echo '      <name>' . $e($comment['author_name']) . '</name>' . "\n";
        if ($comment['author_email']) {
            echo '      <email>' . $e($comment['author_email']) . '</email>' . "\n";
        }
        if ($comment['author_url']) {
            echo '      <link>' . $e($comment['author_url']) . '</link>' . "\n";
        }
        echo '      <isAnonymous>false</isAnonymous>' . "\n";
        echo '    </author>' . "\n";
        echo '    <message><![CDATA[' . $comment['content'] . ']]></message>' . "\n";
        if ($comment['ip_address']) {
            echo '    <ipAddress>' . $e($comment['ip_address']) . '</ipAddress>' . "\n";
        }
        echo '    <createdAt>' . $isoDate($comment['created_at']) . '</createdAt>' . "\n";
        echo '    <isDeleted>false</isDeleted>' . "\n";
        echo '    <isApproved>' . $approved . '</isApproved>' . "\n";
        echo '    <isFlagged>false</isFlagged>' . "\n";
        echo '    <isSpam>' . $isSpam . '</isSpam>' . "\n";
        echo '  </post>' . "\n\n";
    }

    echo '</disqus>' . "\n";
    exit;
}

// GET /api.php?action=post_reactions_summary (admin)
// Returns per-page reaction counts across all pages
if ($method === 'GET' && $action === 'post_reactions_summary') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $stmt = $db->query("
        SELECT page_url,
               SUM(CASE WHEN reaction_type = 'heart'     THEN 1 ELSE 0 END) AS heart,
               SUM(CASE WHEN reaction_type = 'thumbsup'  THEN 1 ELSE 0 END) AS thumbsup,
               SUM(CASE WHEN reaction_type = 'lightbulb' THEN 1 ELSE 0 END) AS lightbulb,
               SUM(CASE WHEN reaction_type = 'funny'     THEN 1 ELSE 0 END) AS funny,
               COUNT(*) AS total
        FROM post_reactions
        GROUP BY page_url
        ORDER BY total DESC
    ");
    $rows = $stmt->fetchAll();

    $totalCount = array_sum(array_column($rows, 'total'));

    jsonResponse(['pages' => $rows, 'total' => $totalCount]);
}

// GET /api.php?action=posts_summary (admin)
// Returns per-post comment statistics aggregated by page_url
if ($method === 'GET' && $action === 'posts_summary') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $search = trim($_GET['search'] ?? '');

    $whereClause = '';
    $params = [];
    if ($search !== '') {
        $whereClause = 'WHERE c.page_url LIKE ?';
        $params[] = '%' . $search . '%';
    }

    $stmt = $db->prepare("
        SELECT
            c.page_url,
            COUNT(*)                                                        AS total_comments,
            SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END)         AS approved_count,
            SUM(CASE WHEN c.status = 'pending'  THEN 1 ELSE 0 END)         AS pending_count,
            SUM(CASE WHEN c.status = 'spam'     THEN 1 ELSE 0 END)         AS spam_count,
            SUM(CASE WHEN c.status = 'deleted'  THEN 1 ELSE 0 END)         AS deleted_count,
            MIN(c.created_at)                                               AS first_comment_at,
            MAX(c.created_at)                                               AS last_comment_at,
            COUNT(DISTINCT c.author_email)                                  AS unique_authors,
            COUNT(DISTINCT c.ip_address)                                    AS unique_ips,
            ROUND(AVG(LENGTH(c.content)))                                   AS avg_length,
            COALESCE(pr.total_reactions, 0)                                 AS total_reactions
        FROM comments c
        LEFT JOIN (
            SELECT page_url, COUNT(*) AS total_reactions
            FROM post_reactions
            GROUP BY page_url
        ) pr ON c.page_url = pr.page_url
        $whereClause
        GROUP BY c.page_url
        ORDER BY last_comment_at DESC
    ");
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    foreach ($posts as &$post) {
        $post['total_comments']  = (int)$post['total_comments'];
        $post['approved_count']  = (int)$post['approved_count'];
        $post['pending_count']   = (int)$post['pending_count'];
        $post['spam_count']      = (int)$post['spam_count'];
        $post['deleted_count']   = (int)$post['deleted_count'];
        $post['unique_authors']  = (int)$post['unique_authors'];
        $post['unique_ips']      = (int)$post['unique_ips'];
        $post['avg_length']      = (int)$post['avg_length'];
        $post['total_reactions'] = (int)$post['total_reactions'];
    }
    unset($post);

    $totalPosts    = count($posts);
    $totalComments = array_sum(array_column($posts, 'total_comments'));
    $totalSpam     = array_sum(array_column($posts, 'spam_count'));
    $totalPending  = array_sum(array_column($posts, 'pending_count'));

    jsonResponse([
        'posts'   => $posts,
        'summary' => [
            'total_posts'    => $totalPosts,
            'total_comments' => $totalComments,
            'total_spam'     => $totalSpam,
            'total_pending'  => $totalPending,
        ],
    ]);
}

// GET /api.php?action=analytics (admin)
// Returns aggregated data for the analytics dashboard
if ($method === 'GET' && $action === 'analytics') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Status totals
    $statusStmt = $db->query("SELECT status, COUNT(*) AS count FROM comments GROUP BY status");
    $statusTotals = ['approved' => 0, 'pending' => 0, 'spam' => 0, 'deleted' => 0];
    foreach ($statusStmt->fetchAll() as $row) {
        if (array_key_exists($row['status'], $statusTotals)) {
            $statusTotals[$row['status']] = (int)$row['count'];
        }
    }

    // Timeline — daily (last 90 days)
    $daily = $db->query("
        SELECT strftime('%Y-%m-%d', created_at) AS period,
               COUNT(*) AS total,
               SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
               SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
        FROM comments
        WHERE created_at >= datetime('now', '-90 days')
        GROUP BY period ORDER BY period ASC
    ")->fetchAll();

    // Timeline — weekly (last 52 weeks)
    $weekly = $db->query("
        SELECT strftime('%Y-W%W', created_at) AS period,
               COUNT(*) AS total,
               SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
               SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
        FROM comments
        WHERE created_at >= datetime('now', '-364 days')
        GROUP BY period ORDER BY period ASC
    ")->fetchAll();

    // Timeline — monthly (all time)
    $monthly = $db->query("
        SELECT strftime('%Y-%m', created_at) AS period,
               COUNT(*) AS total,
               SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
               SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
        FROM comments
        GROUP BY period ORDER BY period ASC
    ")->fetchAll();

    // Cast all timeline rows to int
    foreach ([$daily, $weekly, $monthly] as &$arr) {
        foreach ($arr as &$row) {
            $row['total']    = (int)$row['total'];
            $row['approved'] = (int)$row['approved'];
            $row['pending']  = (int)$row['pending'];
            $row['spam']     = (int)$row['spam'];
        }
        unset($row);
    }
    unset($arr);

    // Hour-of-day distribution (all time, UTC)
    $hourlyRows = $db->query("
        SELECT CAST(strftime('%H', created_at) AS INTEGER) AS hour, COUNT(*) AS count
        FROM comments GROUP BY hour ORDER BY hour
    ")->fetchAll();
    $hourly = array_fill(0, 24, 0);
    foreach ($hourlyRows as $r) { $hourly[(int)$r['hour']] = (int)$r['count']; }

    // Day-of-week distribution (0=Sun)
    $dowRows = $db->query("
        SELECT CAST(strftime('%w', created_at) AS INTEGER) AS dow, COUNT(*) AS count
        FROM comments GROUP BY dow ORDER BY dow
    ")->fetchAll();
    $weekdays = array_fill(0, 7, 0);
    foreach ($dowRows as $r) { $weekdays[(int)$r['dow']] = (int)$r['count']; }

    // Top 10 posts by total comments
    $topPosts = $db->query("
        SELECT page_url,
               COUNT(*) AS total,
               SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
               SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
        FROM comments
        GROUP BY page_url ORDER BY total DESC LIMIT 10
    ")->fetchAll();
    foreach ($topPosts as &$p) {
        $p['total']    = (int)$p['total'];
        $p['approved'] = (int)$p['approved'];
        $p['pending']  = (int)$p['pending'];
        $p['spam']     = (int)$p['spam'];
    }
    unset($p);

    // Unique commenters / IPs
    $unique = $db->query("
        SELECT COUNT(DISTINCT author_email) AS emails, COUNT(DISTINCT ip_address) AS ips FROM comments
    ")->fetch();

    jsonResponse([
        'status_totals'      => $statusTotals,
        'timeline'           => ['daily' => $daily, 'weekly' => $weekly, 'monthly' => $monthly],
        'hourly'             => $hourly,
        'weekdays'           => $weekdays,
        'top_posts'          => $topPosts,
        'unique_commenters'  => (int)$unique['emails'],
        'unique_ips'         => (int)$unique['ips'],
    ]);
}

// DELETE /api.php?action=delete_post_reactions&url=... (admin)
// Clears all post reactions for a given page URL
if ($method === 'DELETE' && $action === 'delete_post_reactions') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $csrfToken = $_GET['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $pageUrl = $_GET['url'] ?? '';
    if (empty($pageUrl)) {
        jsonResponse(['error' => 'url is required'], 400);
    }

    $stmt = $db->prepare("DELETE FROM post_reactions WHERE page_url = ?");
    $stmt->execute([$pageUrl]);

    jsonResponse(['success' => true, 'message' => 'Post reactions cleared']);
}

// POST /api.php?action=post_reaction
// Toggle a reaction on the post itself (one per IP address per reaction type per page)
if ($method === 'POST' && $action === 'post_reaction') {
    $input = getInput();
    $pageUrl = $input['page_url'] ?? '';
    if (empty($pageUrl)) {
        jsonResponse(['error' => 'page_url is required'], 400);
    }

    $allowedTypes = ['heart', 'thumbsup', 'lightbulb', 'funny'];
    $reactionType = $input['reaction_type'] ?? 'heart';
    if (!in_array($reactionType, $allowedTypes)) {
        jsonResponse(['error' => 'Invalid reaction type'], 400);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: reuse vote_log (max 15 vote actions per IP per 60 seconds)
    $rateStmt = $db->prepare("SELECT COUNT(*) as count FROM vote_log WHERE ip_address = ? AND created_at > datetime('now', '-60 seconds')");
    $rateStmt->execute([$ip]);
    if ((int)$rateStmt->fetch()['count'] >= 15) {
        jsonResponse(['error' => 'Too many reactions. Please slow down.'], 429);
    }

    // Toggle reaction
    $existsStmt = $db->prepare("SELECT id FROM post_reactions WHERE page_url = ? AND ip_address = ? AND reaction_type = ?");
    $existsStmt->execute([$pageUrl, $ip, $reactionType]);
    $existing = $existsStmt->fetch();

    if ($existing) {
        $db->prepare("DELETE FROM post_reactions WHERE page_url = ? AND ip_address = ? AND reaction_type = ?")->execute([$pageUrl, $ip, $reactionType]);
        $voted = false;
    } else {
        $db->prepare("INSERT INTO post_reactions (page_url, ip_address, reaction_type) VALUES (?, ?, ?)")->execute([$pageUrl, $ip, $reactionType]);
        $voted = true;
    }

    // Log for rate limiting
    $db->prepare("INSERT INTO vote_log (ip_address) VALUES (?)")->execute([$ip]);

    // Notify admin of new post reaction
    if ($voted) {
        sendPostReactionNotificationEmail($pageUrl, $reactionType);
    }

    // Return per-type counts
    $counts = [];
    foreach ($allowedTypes as $type) {
        $countStmt = $db->prepare("SELECT COUNT(*) as count FROM post_reactions WHERE page_url = ? AND reaction_type = ?");
        $countStmt->execute([$pageUrl, $type]);
        $counts[$type] = (int)$countStmt->fetch()['count'];
    }

    jsonResponse(['voted' => $voted, 'reaction_type' => $reactionType, 'counts' => $counts]);
}

// POST /api.php?action=import_disqus (admin, multipart file upload)
if ($method === 'POST' && $action === 'import_disqus') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();

    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $xmlContent = $input['content'] ?? '';
    if (empty($xmlContent)) {
        jsonResponse(['error' => 'No file content received'], 400);
    }

    // Disable external entity loading to prevent XXE attacks.
    // PHP 8.0+ disables this by default; the function is deprecated there but safe to call.
    if (PHP_VERSION_ID < 80000) {
        libxml_disable_entity_loader(true);
    }
    libxml_use_internal_errors(true);
    // LIBXML_NONET prevents any network access during parsing (all PHP versions)
    $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET);

    if ($xml === false) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        jsonResponse(['error' => 'Invalid XML: ' . implode('; ', $errs)], 400);
    }

    // Helper: normalize a full URL to path-only (matches window.location.pathname)
    $normUrl = function($link) {
        $parsed = parse_url($link);
        $path   = $parsed['path'] ?? $link;
        if (isset($parsed['query']))    $path .= '?' . $parsed['query'];
        if (isset($parsed['fragment'])) $path .= '#' . $parsed['fragment'];
        return $path;
    };

    $threads  = []; // unique page paths seen (keyed by path for dedup)
    $rawTotal = 0;
    $skipped  = 0;
    $orphaned = 0;
    $rawPosts = [];

    if ($xml->getName() === 'rss') {
        // WordPress WXR format
        $wpNs = 'http://wordpress.org/export/1.0/';

        foreach ($xml->channel->item as $item) {
            $link = (string)$item->link;
            if (empty($link)) continue;
            $pageUrl = $normUrl($link);
            $threads[$pageUrl] = $pageUrl;

            $wpChildren = $item->children($wpNs);
            if (!isset($wpChildren->comment)) continue;

            foreach ($wpChildren->comment as $comment) {
                $wp = $comment->children($wpNs);
                $rawTotal++;
                $approved = (string)$wp->comment_approved;
                if ($approved !== '1') { $skipped++; continue; }

                $wpId       = (string)$wp->comment_id;
                $parentWpId = (string)$wp->comment_parent;
                $message    = html_entity_decode(strip_tags((string)$wp->comment_content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $rawPosts[] = [
                    'dsq_id'        => $wpId,
                    'dsq_parent_id' => ($parentWpId && $parentWpId !== '0') ? $parentWpId : null,
                    'page_url'      => $pageUrl,
                    'author_name'   => (string)$wp->comment_author       ?: 'Anonymous',
                    'author_email'  => (string)$wp->comment_author_email ?: '',
                    'author_url'    => (string)$wp->comment_author_url   ?: null,
                    'content'       => $message,
                    'created_at'    => date('Y-m-d H:i:s', strtotime((string)$wp->comment_date_gmt)),
                ];
            }
        }
    } else {
        // Native Disqus XML format
        $namespaces = $xml->getNamespaces(true);
        $dsqNs = $namespaces['dsq'] ?? 'http://disqus.com/disqus-internals';

        foreach ($xml->thread as $thread) {
            $dsqId = (string)$thread->attributes($dsqNs)->id;
            $link  = (string)$thread->link;
            if ($dsqId && $link) {
                $threads[$dsqId] = $normUrl($link);
            }
        }

        foreach ($xml->post as $post) {
            $rawTotal++;
            $isDeleted = ((string)$post->isDeleted) === 'true';
            $isSpam    = ((string)$post->isSpam)    === 'true';
            $dsqId     = (string)$post->attributes($dsqNs)->id;
            $threadId  = (string)$post->thread->attributes($dsqNs)->id;
            $pageUrl   = $threads[$threadId] ?? null;
            if ($isDeleted || $isSpam) { $skipped++; continue; }
            if (!$pageUrl)             { $orphaned++; continue; }
            $rawPosts[] = [
                'dsq_id'        => $dsqId,
                'dsq_parent_id' => (string)$post->parent->attributes($dsqNs)->id ?: null,
                'page_url'      => $pageUrl,
                'author_name'   => (string)$post->author->name  ?: 'Anonymous',
                'author_email'  => (string)$post->author->email ?: '',
                'author_url'    => (string)$post->author->link  ?: null,
                'content'       => html_entity_decode(strip_tags((string)$post->message), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'created_at'    => date('Y-m-d H:i:s', strtotime((string)$post->createdAt)),
            ];
        }
    }

    // Build a dedup set from existing comments: "created_at|page_url|author_name"
    $existingKeys = [];
    $existingRows = $db->query("SELECT created_at, page_url, author_name FROM comments")->fetchAll();
    foreach ($existingRows as $row) {
        $existingKeys[$row['created_at'] . '|' . $row['page_url'] . '|' . $row['author_name']] = true;
    }

    $dupCount  = 0;
    $newPosts  = [];
    foreach ($rawPosts as $post) {
        $key = $post['created_at'] . '|' . $post['page_url'] . '|' . $post['author_name'];
        if (isset($existingKeys[$key])) {
            $dupCount++;
        } else {
            $newPosts[] = $post;
        }
    }

    // Preview mode: return stats without inserting anything
    if (!empty($input['preview'])) {
        $pageCounts = array_count_values(array_column($newPosts, 'page_url'));
        arsort($pageCounts);
        $topThreads = [];
        foreach (array_slice($pageCounts, 0, 5, true) as $url => $count) {
            $topThreads[] = ['url' => $url, 'count' => $count];
        }

        $dates     = array_column($rawPosts, 'created_at');
        $dateRange = $dates ? ['oldest' => min($dates), 'newest' => max($dates)] : null;

        $warnings = [];
        if (count($threads) === 0) $warnings[] = 'No threads found in file.';
        if ($rawTotal === 0)       $warnings[] = 'No posts found in file.';
        if ($orphaned > 0)         $warnings[] = "$orphaned post(s) reference unknown threads and will be skipped.";
        if ($dupCount > 0)         $warnings[] = "$dupCount duplicate(s) already in database — will be skipped.";

        jsonResponse([
            'preview'      => true,
            'threads'      => count($threads),
            'posts_total'  => $rawTotal,
            'posts_import' => count($newPosts),
            'posts_skip'   => $skipped,
            'duplicates'   => $dupCount,
            'orphaned'     => $orphaned,
            'date_range'   => $dateRange,
            'top_threads'  => $topThreads,
            'warnings'     => $warnings,
        ]);
    }

    // Sort oldest-first so parent IDs exist before children
    usort($newPosts, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));

    $postIdMap = [];
    $imported  = 0;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO comments (page_url, parent_id, author_name, author_email, author_url,
                                  content, created_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')
        ");

        foreach ($newPosts as $post) {
            $parentId = $post['dsq_parent_id'] ? ($postIdMap[$post['dsq_parent_id']] ?? null) : null;

            $stmt->execute([
                $post['page_url'],
                $parentId,
                $post['author_name'],
                $post['author_email'],
                $post['author_url'],
                $post['content'],
                $post['created_at'],
            ]);

            $postIdMap[$post['dsq_id']] = $db->lastInsertId();
            $imported++;
        }

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    $uniquePages = count(array_unique(array_column($newPosts, 'page_url')));
    jsonResponse([
        'success'           => true,
        'imported'          => $imported,
        'unique_pages'      => $uniquePages,
        'skipped_duplicates'=> $dupCount,
    ]);
}

// POST /api.php?action=normalize_urls (admin) — one-time fix: strip scheme+host from full URLs
if ($method === 'POST' && $action === 'normalize_urls') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $stmt = $db->query("SELECT DISTINCT page_url FROM comments WHERE page_url LIKE 'http%'");
    $fullUrls = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $fixed = 0;
    $update = $db->prepare("UPDATE comments SET page_url = ? WHERE page_url = ?");
    foreach ($fullUrls as $url) {
        $parsed = parse_url($url);
        $path   = $parsed['path'] ?? $url;
        if (isset($parsed['query']))    $path .= '?' . $parsed['query'];
        if (isset($parsed['fragment'])) $path .= '#' . $parsed['fragment'];
        if ($path !== $url) {
            $update->execute([$path, $url]);
            $fixed += $update->rowCount();
        }
    }

    jsonResponse(['success' => true, 'comments_updated' => $fixed]);
}

// GET /api.php?action=db_stats (admin)
if ($method === 'GET' && $action === 'db_stats') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $tables = ['comments', 'settings', 'subscriptions', 'email_queue', 'login_attempts', 'sessions', 'votes', 'vote_log', 'post_reactions'];
    $stats = [];
    foreach ($tables as $table) {
        if (tableExists($db, $table)) {
            $row = $db->query("SELECT COUNT(*) as count FROM {$table}")->fetch();
            $stats[$table] = (int)$row['count'];
        }
    }

    // Count comments by status
    $statusCounts = [];
    $statusRows = $db->query("SELECT status, COUNT(*) as count FROM comments GROUP BY status")->fetchAll();
    foreach ($statusRows as $row) {
        $statusCounts[$row['status']] = (int)$row['count'];
    }

    $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

    jsonResponse([
        'tables' => $stats,
        'comment_statuses' => $statusCounts,
        'db_size_bytes' => $dbSize,
    ]);
}

// POST /api.php?action=vacuum (admin)
if ($method === 'POST' && $action === 'vacuum') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $sizeBefore = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
    periodicCleanup($db);
    $db->exec('VACUUM');
    $sizeAfter = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

    jsonResponse([
        'success' => true,
        'size_before' => $sizeBefore,
        'size_after' => $sizeAfter,
        'saved_bytes' => max(0, $sizeBefore - $sizeAfter),
    ]);
}

// POST /api.php?action=delete_spam (admin)
if ($method === 'POST' && $action === 'delete_spam') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $stmt = $db->query("SELECT COUNT(*) as count FROM comments WHERE status = 'spam'");
    $count = (int)$stmt->fetch()['count'];
    $db->exec("DELETE FROM comments WHERE status = 'spam'");

    jsonResponse(['success' => true, 'deleted' => $count]);
}

// GET /api.php?action=get_settings (admin)
if ($method === 'GET' && $action === 'get_settings') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $keys = ['require_moderation', 'enable_notifications', 'admin_email'];
    $settings = [];
    foreach ($keys as $key) {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $settings[$key] = $row ? $row['value'] : null;
    }

    jsonResponse(['settings' => $settings]);
}

// POST /api.php?action=save_settings (admin)
if ($method === 'POST' && $action === 'save_settings') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $allowed = ['require_moderation', 'enable_notifications', 'admin_email'];
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");

    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) {
            $value = $input[$key];
            if (in_array($key, ['require_moderation', 'enable_notifications'])) {
                $value = ($value === 'true' || $value === true || $value === '1' || $value === 1) ? 'true' : 'false';
            }
            if ($key === 'admin_email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['error' => 'Invalid email address'], 400);
            }
            $stmt->execute([$key, $value]);
        }
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Invalid action'], 400);
