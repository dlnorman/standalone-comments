<?php
/**
 * Debug script to check comment system status
 * Visit: https://darcynorman.net/comments/debug.php
 */

require_once 'config.php';
require_once 'database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Comment System Debug</title>
    <style>
        body { font-family: monospace; padding: 2rem; background: #f5f5f5; }
        .section { background: white; padding: 1rem; margin-bottom: 1rem; border-radius: 4px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        h2 { margin-top: 0; }
        pre { background: #f0f0f0; padding: 1rem; overflow-x: auto; }
    </style>
</head>
<body>
<h1>Comment System Debug Info</h1>

<div class="section">
    <h2>1. Database Path</h2>
    <pre><?php echo DB_PATH; ?></pre>
    <?php if (file_exists(DB_PATH)): ?>
        <p class="success">✓ Database file exists</p>
        <p>File size: <?php echo filesize(DB_PATH); ?> bytes</p>
        <p>Writable: <?php echo is_writable(DB_PATH) ? 'Yes' : 'No'; ?></p>
    <?php else: ?>
        <p class="error">✗ Database file does not exist</p>
    <?php endif; ?>
</div>

<div class="section">
    <h2>2. Database Connection</h2>
    <?php
    $db = getDatabase();
    if ($db):
    ?>
        <p class="success">✓ Database connection successful</p>
    <?php else: ?>
        <p class="error">✗ Database connection failed</p>
    <?php endif; ?>
</div>

<div class="section">
    <h2>3. Comment Counts</h2>
    <?php if ($db): ?>
        <?php
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM comments GROUP BY status");
        $counts = $stmt->fetchAll();
        ?>
        <table style="border-collapse: collapse;">
            <tr><th style="text-align: left; padding: 0.5rem;">Status</th><th style="text-align: left; padding: 0.5rem;">Count</th></tr>
            <?php foreach ($counts as $row): ?>
                <tr>
                    <td style="padding: 0.5rem;"><?php echo htmlspecialchars($row['status']); ?></td>
                    <td style="padding: 0.5rem;"><?php echo $row['count']; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php
        $stmt = $db->query("SELECT COUNT(*) as total FROM comments");
        $total = $stmt->fetch();
        ?>
        <p><strong>Total comments: <?php echo $total['total']; ?></strong></p>
    <?php else: ?>
        <p class="error">Cannot query - no database connection</p>
    <?php endif; ?>
</div>

<div class="section">
    <h2>4. Recent Comments (Last 5)</h2>
    <?php if ($db): ?>
        <?php
        $stmt = $db->query("SELECT id, page_url, author_name, status, created_at FROM comments ORDER BY id DESC LIMIT 5");
        $recent = $stmt->fetchAll();
        ?>
        <?php if (count($recent) > 0): ?>
            <table style="border-collapse: collapse; width: 100%;">
                <tr>
                    <th style="text-align: left; padding: 0.5rem; border-bottom: 1px solid #ddd;">ID</th>
                    <th style="text-align: left; padding: 0.5rem; border-bottom: 1px solid #ddd;">Author</th>
                    <th style="text-align: left; padding: 0.5rem; border-bottom: 1px solid #ddd;">Page</th>
                    <th style="text-align: left; padding: 0.5rem; border-bottom: 1px solid #ddd;">Status</th>
                    <th style="text-align: left; padding: 0.5rem; border-bottom: 1px solid #ddd;">Created</th>
                </tr>
                <?php foreach ($recent as $comment): ?>
                    <tr>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #eee;"><?php echo $comment['id']; ?></td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($comment['author_name']); ?></td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($comment['page_url']); ?></td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #eee;"><?php echo $comment['status']; ?></td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #eee;"><?php echo $comment['created_at']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="warning">No comments found in database</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="error">Cannot query - no database connection</p>
    <?php endif; ?>
</div>

<div class="section">
    <h2>5. Configuration</h2>
    <pre>ALLOWED_ORIGINS: <?php print_r(ALLOWED_ORIGINS); ?>
DB_PATH: <?php echo DB_PATH; ?>
SESSION_LIFETIME: <?php echo SESSION_LIFETIME; ?> seconds (<?php echo SESSION_LIFETIME / 3600; ?> hours)
Timezone: <?php echo date_default_timezone_get(); ?>
Current time: <?php echo date('Y-m-d H:i:s'); ?></pre>
</div>

<div class="section">
    <h2>6. File Permissions</h2>
    <?php
    $dir = __DIR__;
    $dirPerms = substr(sprintf('%o', fileperms($dir)), -4);
    ?>
    <p>Directory: <?php echo $dir; ?></p>
    <p>Directory permissions: <?php echo $dirPerms; ?> (<?php echo is_writable($dir) ? 'writable' : 'not writable'; ?>)</p>

    <?php if (file_exists(DB_PATH)): ?>
        <?php $dbPerms = substr(sprintf('%o', fileperms(DB_PATH)), -4); ?>
        <p>Database permissions: <?php echo $dbPerms; ?> (<?php echo is_writable(DB_PATH) ? 'writable' : 'not writable'; ?>)</p>
    <?php endif; ?>
</div>

<div class="section">
    <h2>7. PHP Info</h2>
    <p>PHP Version: <?php echo phpversion(); ?></p>
    <p>SQLite Extension: <?php echo extension_loaded('pdo_sqlite') ? '✓ Loaded' : '✗ Not loaded'; ?></p>
    <p>JSON Extension: <?php echo extension_loaded('json') ? '✓ Loaded' : '✗ Not loaded'; ?></p>
</div>

<p style="margin-top: 2rem; color: #666;">
    <strong>⚠️ Security Note:</strong> Delete this debug.php file after troubleshooting!
</p>

</body>
</html>
