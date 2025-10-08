<?php
// Unsubscribe from comment notifications

require_once 'config.php';
require_once 'database.php';

$db = getDatabase();
if (!$db) {
    die("Database error");
}

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm'])) {
    $token = $_POST['token'] ?? '';

    if (!empty($token)) {
        $stmt = $db->prepare("UPDATE subscriptions SET active = 0 WHERE token = ?");
        $stmt->execute([$token]);

        if ($stmt->rowCount() > 0) {
            $success = true;
            $message = "You have been successfully unsubscribed from comment notifications.";
        } else {
            $message = "Subscription not found or already unsubscribed.";
        }
    }
}

// Get subscription info
$subscriptionInfo = null;
if (!empty($token) && !$success) {
    $stmt = $db->prepare("SELECT page_url, email, active FROM subscriptions WHERE token = ?");
    $stmt->execute([$token]);
    $subscriptionInfo = $stmt->fetch();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe from Comment Notifications</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
            padding: 2rem;
        }

        .container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        h1 {
            color: #4a90e2;
            margin-bottom: 1.5rem;
        }

        .message {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .info {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }

        .info strong {
            display: block;
            margin-bottom: 0.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-block;
            margin-left: 1rem;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        form {
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Unsubscribe from Comment Notifications</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $success ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <p>You will no longer receive email notifications for new comments.</p>
            <p style="margin-top: 1rem;">
                <a href="/" class="btn btn-secondary">Return to Site</a>
            </p>
        <?php elseif ($subscriptionInfo && $subscriptionInfo['active']): ?>
            <div class="info">
                <strong>Subscription Details:</strong>
                <div>Page: <?php echo htmlspecialchars($subscriptionInfo['page_url']); ?></div>
                <div>Email: <?php echo htmlspecialchars($subscriptionInfo['email']); ?></div>
            </div>

            <p>Are you sure you want to unsubscribe from comment notifications for this page?</p>

            <form method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn btn-danger">Yes, Unsubscribe</button>
                <a href="/" class="btn btn-secondary">Cancel</a>
            </form>
        <?php else: ?>
            <div class="message error">
                Invalid or expired unsubscribe link.
            </div>
            <p style="margin-top: 1rem;">
                <a href="/" class="btn btn-secondary">Return to Site</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
