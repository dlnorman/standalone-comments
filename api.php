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
        // Simple token validation - in production use more secure method
        $db = getDatabase();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_token'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result && $result['value'] === $token;
    }
    return false;
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

    // Get all active subscribers for this page (excluding the comment author)
    $stmt = $db->prepare("
        SELECT email, token FROM subscriptions
        WHERE page_url = ? AND active = 1 AND email != ?
    ");
    $stmt->execute([$pageUrl, $authorEmail]);
    $subscribers = $stmt->fetchAll();

    // Send notification to all subscribers
    foreach ($subscribers as $subscriber) {
        $to = filter_var($subscriber['email'], FILTER_VALIDATE_EMAIL);
        if (!$to) continue; // Skip invalid emails

        $unsubscribeUrl = "https://" . $_SERVER['HTTP_HOST'] . "/comments/unsubscribe.php?token=" . $subscriber['token'];

        $subject = "New comment on " . parse_url($pageUrl, PHP_URL_PATH);
        $message = "Hello,\n\n";
        $message .= "{$safeAuthorName} posted a new comment on {$safePageUrl}:\n\n";
        $message .= "{$safeContent}\n\n";
        $message .= "View and reply: {$safePageUrl}#comment-{$commentId}\n\n";
        $message .= "---\n";
        $message .= "To unsubscribe from notifications for this page: {$unsubscribeUrl}\n";

        $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
        $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";

        @mail($to, $subject, $message, $headers);
    }

    // If this is a reply, also notify the parent comment author directly
    if ($parentId !== null) {
        $stmt = $db->prepare("SELECT author_email, author_name FROM comments WHERE id = ?");
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();

        if ($parent && $parent['author_email'] && $parent['author_email'] !== $authorEmail) {
            $safeParentName = sanitizeEmailContent($parent['author_name']);
            $to = filter_var($parent['author_email'], FILTER_VALIDATE_EMAIL);
            if ($to) {
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

                $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
                $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";

                @mail($to, $subject, $message, $headers);
            }
        }
    }

    // Notify admin of new comment
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_email'");
    $stmt->execute();
    $result = $stmt->fetch();

    if ($result && !empty($result['value'])) {
        $to = filter_var($result['value'], FILTER_VALIDATE_EMAIL);
        if (!$to) return; // Invalid email, skip

        $subject = "New comment on your site";
        $message = "New comment from {$safeAuthorName} on {$safePageUrl}:\n\n";
        $message .= "{$safeContent}\n\n";
        $message .= "Manage comments: https://" . $_SERVER['HTTP_HOST'] . "/comments/admin.html\n";

        $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
        @mail($to, $subject, $message, $headers);
    }
}

// GET /api.php?action=comments&url=...
if ($method === 'GET' && $action === 'comments') {
    $pageUrl = $_GET['url'] ?? '';
    if (empty($pageUrl)) {
        jsonResponse(['error' => 'URL is required'], 400);
    }

    $status = isAdmin() ? ['pending', 'approved'] : ['approved'];
    $placeholders = implode(',', array_fill(0, count($status), '?'));

    $stmt = $db->prepare("
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status
        FROM comments
        WHERE page_url = ? AND status IN ($placeholders)
        ORDER BY created_at ASC
    ");
    $stmt->execute(array_merge([$pageUrl], $status));
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

    jsonResponse(['comments' => $threaded]);
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

// POST /api.php?action=login (admin)
if ($method === 'POST' && $action === 'login') {
    $input = getInput();
    $password = $input['password'] ?? '';

    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_password_hash'");
    $stmt->execute();
    $result = $stmt->fetch();

    if ($result && password_verify($password, $result['value'])) {
        $token = bin2hex(random_bytes(32));

        // Store token
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('admin_token', ?)");
        $stmt->execute([$token]);

        // Set secure cookie (HTTPS only in production)
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
        setcookie(ADMIN_TOKEN_COOKIE, $token, time() + SESSION_LIFETIME, '/comments/', '', $isSecure, true);
        jsonResponse(['success' => true, 'message' => 'Logged in successfully']);
    } else {
        jsonResponse(['error' => 'Invalid password'], 401);
    }
}

// PUT /api.php?action=moderate&id=...
if ($method === 'PUT' && $action === 'moderate') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $id = $_GET['id'] ?? '';
    $input = getInput();
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

    $stmt = $db->query("
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status, ip_address
        FROM comments
        WHERE status = 'pending'
        ORDER BY created_at DESC
    ");
    $comments = $stmt->fetchAll();

    jsonResponse(['comments' => $comments]);
}

// GET /api.php?action=all (admin)
if ($method === 'GET' && $action === 'all') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $stmt = $db->query("
        SELECT id, page_url, parent_id, author_name, author_email, author_url,
               content, created_at, status, ip_address
        FROM comments
        ORDER BY created_at DESC
    ");
    $comments = $stmt->fetchAll();

    jsonResponse(['comments' => $comments]);
}

// GET /api.php?action=subscriptions (admin)
if ($method === 'GET' && $action === 'subscriptions') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $stmt = $db->query("
        SELECT id, page_url, email, token, subscribed_at, active
        FROM subscriptions
        ORDER BY subscribed_at DESC
    ");
    $subscriptions = $stmt->fetchAll();

    jsonResponse(['subscriptions' => $subscriptions]);
}

// POST /api.php?action=toggle_subscription (admin)
if ($method === 'POST' && $action === 'toggle_subscription') {
    if (!isAdmin()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = getInput();
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

jsonResponse(['error' => 'Invalid action'], 400);
