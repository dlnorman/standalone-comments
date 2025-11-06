#!/usr/bin/env php
<?php
/**
 * Email Queue Processor
 *
 * This script processes queued emails in the background to prevent blocking
 * comment submission requests. Run this script via cron or as a daemon.
 *
 * Usage:
 *   - Cron (every minute): * * * * * /usr/bin/php /path/to/comments/utils/process-email-queue.php
 *   - Manual: php /path/to/comments/utils/process-email-queue.php
 *   - Continuous daemon: php /path/to/comments/utils/process-email-queue.php --daemon
 */

// Change to script directory
chdir(__DIR__);

require_once '../config.php';
require_once '../database.php';

// Configuration
define('BATCH_SIZE', 10); // Process 10 emails per run
define('MAX_ATTEMPTS', 3); // Retry failed emails up to 3 times
define('RETRY_DELAY', 300); // Wait 5 minutes before retrying failed emails
define('DAEMON_SLEEP', 10); // Sleep 10 seconds between daemon cycles

// Check for daemon mode
$daemonMode = in_array('--daemon', $argv ?? []);

/**
 * Process pending emails from the queue
 */
function processEmailQueue() {
    $db = getDatabase();
    if (!$db) {
        error_log('Email queue processor: Database connection failed');
        return false;
    }

    // Get pending emails, ordered by creation date
    $stmt = $db->prepare("
        SELECT id, comment_id, recipient_email, recipient_name, email_type,
               subject, body, attempts
        FROM email_queue
        WHERE status = 'pending'
          AND attempts < ?
          AND (last_error IS NULL OR created_at < datetime('now', '-' || ? || ' seconds'))
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->execute([MAX_ATTEMPTS, RETRY_DELAY, BATCH_SIZE]);
    $emails = $stmt->fetchAll();

    if (empty($emails)) {
        return 0; // No emails to process
    }

    $processed = 0;
    $succeeded = 0;
    $failed = 0;

    foreach ($emails as $email) {
        $processed++;

        // Build email headers
        $headers = "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";
        $headers .= "Reply-To: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        // Attempt to send email
        $success = @mail($email['recipient_email'], $email['subject'], $email['body'], $headers);

        if ($success) {
            // Mark as sent
            $updateStmt = $db->prepare("
                UPDATE email_queue
                SET status = 'sent',
                    sent_at = datetime('now')
                WHERE id = ?
            ");
            $updateStmt->execute([$email['id']]);
            $succeeded++;

            error_log("Email sent to {$email['recipient_email']} (type: {$email['email_type']})");
        } else {
            // Increment attempts and update error
            $newAttempts = $email['attempts'] + 1;
            $status = $newAttempts >= MAX_ATTEMPTS ? 'failed' : 'pending';

            $updateStmt = $db->prepare("
                UPDATE email_queue
                SET attempts = ?,
                    status = ?,
                    last_error = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $newAttempts,
                $status,
                "Failed to send email (attempt $newAttempts)",
                $email['id']
            ]);
            $failed++;

            error_log("Email failed to {$email['recipient_email']} (attempt $newAttempts/{MAX_ATTEMPTS})");
        }
    }

    if ($processed > 0) {
        error_log("Email queue: Processed $processed emails ($succeeded sent, $failed failed)");
    }

    return $processed;
}

/**
 * Clean up old sent and failed emails
 */
function cleanupOldEmails() {
    $db = getDatabase();
    if (!$db) return false;

    // Delete emails sent more than 30 days ago
    $stmt = $db->prepare("
        DELETE FROM email_queue
        WHERE status = 'sent'
          AND sent_at < datetime('now', '-30 days')
    ");
    $stmt->execute();
    $sentDeleted = $stmt->rowCount();

    // Delete failed emails more than 7 days old
    $stmt = $db->prepare("
        DELETE FROM email_queue
        WHERE status = 'failed'
          AND created_at < datetime('now', '-7 days')
    ");
    $stmt->execute();
    $failedDeleted = $stmt->rowCount();

    if ($sentDeleted > 0 || $failedDeleted > 0) {
        error_log("Email queue cleanup: Deleted $sentDeleted sent and $failedDeleted failed emails");
    }

    return true;
}

// Main execution
if ($daemonMode) {
    // Daemon mode: run continuously
    error_log('Email queue processor starting in daemon mode');

    $cleanupCounter = 0;
    while (true) {
        processEmailQueue();

        // Run cleanup every hour (360 cycles of 10 seconds)
        if (++$cleanupCounter >= 360) {
            cleanupOldEmails();
            $cleanupCounter = 0;
        }

        sleep(DAEMON_SLEEP);
    }
} else {
    // Single run mode (for cron)
    $processed = processEmailQueue();

    // Run cleanup randomly (1% chance) to avoid doing it every minute
    if (rand(1, 100) === 1) {
        cleanupOldEmails();
    }

    exit($processed > 0 ? 0 : 1);
}
