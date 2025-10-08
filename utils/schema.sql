-- Comments System Database Schema
-- SQLite database for managing threaded comments

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

-- Settings table for admin configuration
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Insert default settings
INSERT OR IGNORE INTO settings (key, value) VALUES
    ('admin_password_hash', ''),
    ('require_moderation', 'true'),
    ('allow_guest_comments', 'true'),
    ('max_comment_length', '5000'),
    ('enable_notifications', 'false'),
    ('admin_email', '');

-- Subscriptions table for email notifications
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
