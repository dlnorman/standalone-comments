<?php
/**
 * Admin Password Setup Script
 *
 * Usage: php set-password.php
 */

require_once 'config.php';
require_once 'database.php';

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line for security');
}

echo "=== Set Admin Password ===\n\n";

// Prompt for password
echo "Enter new admin password: ";
$password = trim(fgets(STDIN));

if (empty($password)) {
    echo "Error: Password cannot be empty\n";
    exit(1);
}

if (strlen($password) < 8) {
    echo "Warning: Password should be at least 8 characters\n";
    echo "Continue anyway? (y/n): ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "Cancelled\n";
        exit(0);
    }
}

// Confirm password
echo "Confirm password: ";
$confirm = trim(fgets(STDIN));

if ($password !== $confirm) {
    echo "Error: Passwords do not match\n";
    exit(1);
}

// Hash and save
$hash = password_hash($password, PASSWORD_DEFAULT);

$db = getDatabase();
if (!$db) {
    echo "Error: Could not connect to database\n";
    exit(1);
}

$stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('admin_password_hash', ?)");
$stmt->execute([$hash]);

// Clear any existing session tokens
$stmt = $db->prepare("DELETE FROM settings WHERE key = 'admin_token'");
$stmt->execute();

echo "\n✓ Password updated successfully!\n";
echo "You can now log in to the admin panel with your new password.\n";
