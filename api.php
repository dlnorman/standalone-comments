<?php
// Comment System API

require_once 'config.php';
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
if (in_array($origin, ALLOWED_ORIGINS)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
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
        return $result && $result['value'] === $token;
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
    $db = getDatabase();

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
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status
        FROM comments
        WHERE page_url = ? AND status IN ($placeholders)
        ORDER BY created_at ASC
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

    jsonResponse([
        'comments' => $threaded,
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
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status, ip_address
        FROM comments
        WHERE status = 'pending'
        ORDER BY created_at DESC
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

    // Add pagination to prevent browser crashes with large datasets
    $limit = isset($_GET['limit']) ? min(max(1, (int)$_GET['limit']), 10000) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // Get total count
    $countStmt = $db->query("SELECT COUNT(*) as total FROM comments");
    $countResult = $countStmt->fetch();
    $total = $countResult['total'];

    $stmt = $db->prepare("
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status, ip_address
        FROM comments
        ORDER BY created_at DESC
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

    // Fetch all comments
    $stmt = $db->query("
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status, ip_address
        FROM comments
        ORDER BY created_at ASC
    ");
    $comments = $stmt->fetchAll();

    // Generate Disqus WXR format
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="disqus_export_' . date('Y-m-d') . '.xml"');
    header('Cache-Control: no-cache');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0"' . "\n";
    echo '     xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
    echo '     xmlns:dsq="http://www.disqus.com/"' . "\n";
    echo '     xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
    echo '     xmlns:wp="http://wordpress.org/export/1.0/">' . "\n";
    echo '  <channel>' . "\n";
    echo '    <title>' . htmlspecialchars($_SERVER['HTTP_HOST']) . '</title>' . "\n";
    echo '    <link>https://' . htmlspecialchars($_SERVER['HTTP_HOST']) . '</link>' . "\n";
    echo '    <description>Comment export from standalone-comments</description>' . "\n";
    echo '    <pubDate>' . date('r') . '</pubDate>' . "\n\n";

    // Group comments by page
    $pageGroups = [];
    foreach ($comments as $comment) {
        $pageGroups[$comment['page_url']][] = $comment;
    }

    // Output each page as an item
    foreach ($pageGroups as $pageUrl => $pageComments) {
        // Use page URL as identifier
        $pageId = md5($pageUrl);

        echo '    <item>' . "\n";
        echo '      <title>' . htmlspecialchars($pageUrl) . '</title>' . "\n";
        echo '      <link>' . htmlspecialchars($pageUrl) . '</link>' . "\n";
        echo '      <content:encoded><![CDATA[]]></content:encoded>' . "\n";
        echo '      <dsq:thread_identifier>' . htmlspecialchars($pageId) . '</dsq:thread_identifier>' . "\n";
        echo '      <wp:post_date_gmt>' . date('Y-m-d H:i:s') . '</wp:post_date_gmt>' . "\n";
        echo '      <wp:comment_status>open</wp:comment_status>' . "\n";

        // Output comments for this page
        foreach ($pageComments as $comment) {
            echo '      <wp:comment>' . "\n";
            echo '        <wp:comment_id>' . $comment['id'] . '</wp:comment_id>' . "\n";
            echo '        <wp:comment_author>' . htmlspecialchars($comment['author_name']) . '</wp:comment_author>' . "\n";
            echo '        <wp:comment_author_email>' . htmlspecialchars($comment['author_email']) . '</wp:comment_author_email>' . "\n";
            if ($comment['author_url']) {
                echo '        <wp:comment_author_url>' . htmlspecialchars($comment['author_url']) . '</wp:comment_author_url>' . "\n";
            }
            echo '        <wp:comment_author_IP>' . htmlspecialchars($comment['ip_address'] ?? '') . '</wp:comment_author_IP>' . "\n";
            echo '        <wp:comment_date_gmt>' . date('Y-m-d H:i:s', strtotime($comment['created_at'])) . '</wp:comment_date_gmt>' . "\n";
            echo '        <wp:comment_content><![CDATA[' . $comment['content'] . ']]></wp:comment_content>' . "\n";

            // Map status
            $approved = ($comment['status'] === 'approved') ? '1' : '0';
            echo '        <wp:comment_approved>' . $approved . '</wp:comment_approved>' . "\n";

            if ($comment['parent_id']) {
                echo '        <wp:comment_parent>' . $comment['parent_id'] . '</wp:comment_parent>' . "\n";
            }
            echo '      </wp:comment>' . "\n";
        }

        echo '    </item>' . "\n\n";
    }

    echo '  </channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

jsonResponse(['error' => 'Invalid action'], 400);
