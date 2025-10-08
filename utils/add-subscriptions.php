<?php
// Migration script to add subscriptions table to existing database
// Run this once to upgrade your database

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

$db = getDatabase();
if (!$db) {
    die("Database connection failed\n");
}

try {
    // Create subscriptions table
    $db->exec("
        CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_url TEXT NOT NULL,
            email TEXT NOT NULL,
            token TEXT UNIQUE NOT NULL,
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            active INTEGER DEFAULT 1,
            UNIQUE(page_url, email)
        )
    ");

    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_page_url ON subscriptions(page_url)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_email ON subscriptions(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_token ON subscriptions(token)");

    echo "✓ Subscriptions table created successfully!\n";

} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
