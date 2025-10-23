<?php
// Database helper functions

require_once 'config.php';

function getDatabase() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

function tableExists($db, $tableName) {
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$tableName]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function initDatabase() {
    $db = getDatabase();
    if (!$db) return false;

    // Check if schema.sql exists, otherwise use inline schema
    $schemaFile = __DIR__ . '/utils/schema.sql';
    if (file_exists($schemaFile)) {
        $schema = file_get_contents($schemaFile);
    } else {
        // Inline schema for deployment
        $schema = "
            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url TEXT NOT NULL,
                parent_id INTEGER DEFAULT NULL,
                author_name TEXT NOT NULL,
                author_email TEXT NOT NULL,
                author_url TEXT DEFAULT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'approved', 'spam', 'deleted')),
                ip_address TEXT,
                user_agent TEXT,
                FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_page_url ON comments(page_url);
            CREATE INDEX IF NOT EXISTS idx_parent_id ON comments(parent_id);
            CREATE INDEX IF NOT EXISTS idx_status ON comments(status);
            CREATE INDEX IF NOT EXISTS idx_created_at ON comments(created_at);

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            INSERT OR IGNORE INTO settings (key, value) VALUES
                ('admin_password_hash', ''),
                ('require_moderation', 'true'),
                ('allow_guest_comments', 'true'),
                ('max_comment_length', '5000'),
                ('enable_notifications', 'false'),
                ('admin_email', '');

            CREATE TABLE IF NOT EXISTS subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url TEXT NOT NULL,
                email TEXT NOT NULL,
                token TEXT UNIQUE NOT NULL,
                subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1,
                UNIQUE(page_url, email)
            );

            CREATE INDEX IF NOT EXISTS idx_sub_page_url ON subscriptions(page_url);
            CREATE INDEX IF NOT EXISTS idx_sub_email ON subscriptions(email);
            CREATE INDEX IF NOT EXISTS idx_sub_token ON subscriptions(token);
        ";
    }

    try {
        $db->exec($schema);
        return true;
    } catch (PDOException $e) {
        error_log('Database initialization failed: ' . $e->getMessage());
        return false;
    }
}

function migrateDatabase() {
    $db = getDatabase();
    if (!$db) return false;

    try {
        // Check if subscriptions table exists, if not create it
        if (!tableExists($db, 'subscriptions')) {
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
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_page_url ON subscriptions(page_url)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_email ON subscriptions(email)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_token ON subscriptions(token)");
            error_log('Database migration: subscriptions table created');
        }

        return true;
    } catch (PDOException $e) {
        error_log('Database migration failed: ' . $e->getMessage());
        return false;
    }
}

// Initialize database if it doesn't exist
if (!file_exists(DB_PATH)) {
    // Ensure db directory exists
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    // Check if a default template database exists
    $defaultDbPath = __DIR__ . '/db/comments-default.db';
    if (file_exists($defaultDbPath)) {
        // Copy the default database instead of creating from scratch
        copy($defaultDbPath, DB_PATH);
        error_log('Database initialized from db/comments-default.db template');
    } else {
        // Create new database from schema
        initDatabase();
        error_log('Database initialized from schema');
    }
} else {
    // Run migrations on existing database
    migrateDatabase();
}
