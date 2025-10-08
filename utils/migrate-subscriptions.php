<?php
// Simple migration script to add subscriptions table
// Run this once to upgrade your database

define('DB_PATH', __DIR__ . '/../comments.db');

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    echo "✓ Indexes created successfully!\n";

    // Check if table exists
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subscriptions'");
    if ($result->fetch()) {
        echo "✓ Table verified in database\n";
    }

} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
