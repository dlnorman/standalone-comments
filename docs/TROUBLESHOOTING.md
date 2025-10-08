# Comment System Troubleshooting

## Issue: Comment Posted But Not Showing Up

### Symptoms
- Comment form says "submitted for moderation"
- Comment doesn't appear in admin panel (pending or approved)
- Database appears empty or comment is missing

### Diagnosis Steps

#### 1. Visit the Debug Page
Upload `debug.php` to your server and visit:
```
https://darcynorman.net/comments/debug.php
```

This will show:
- ✅ Database file location and size
- ✅ Database connection status
- ✅ Comment counts by status
- ✅ Recent comments
- ✅ File permissions
- ✅ PHP configuration

**⚠️ Delete debug.php after troubleshooting!**

#### 2. Check Database Location

The database should be at:
```
/path/to/your/server/comments/comments.db
```

SSH into your server and check:
```bash
cd /path/to/comments
ls -lah comments.db
```

Expected: File should be >0 bytes (probably 100KB+ with imports)

#### 3. Download the Correct Database

Make sure you're downloading from the right location:
```bash
# On your server
cd /path/to/comments
pwd  # Confirm you're in the right place
ls -lah comments.db  # Check file size
```

Then download via SFTP/SCP to local machine.

#### 4. Check Database Contents

```bash
sqlite3 comments.db "SELECT COUNT(*) FROM comments;"
# Should show: 232 (from imports) + any new comments

sqlite3 comments.db "SELECT id, author_name, status, created_at FROM comments ORDER BY id DESC LIMIT 10;"
# Should show recent comments including your test
```

### Common Issues

#### Issue 1: Database File Permissions

**Symptom:** Comments.db file is 0 bytes or doesn't exist

**Solution:**
```bash
# Check directory permissions
ls -la /path/to/comments/

# Directory should be writable by web server
chmod 755 /path/to/comments/

# Database should be writable
chmod 664 /path/to/comments/comments.db

# Make sure web server owns the file
chown www-data:www-data /path/to/comments/comments.db
# Or: chown apache:apache (depends on your server)
```

#### Issue 2: Wrong Database Location

**Symptom:** New comments go to a different database than imported comments

**Check config.php:**
```php
define('DB_PATH', __DIR__ . '/comments.db');
```

This creates `comments.db` in the same directory as `config.php`.

**Verify on server:**
```bash
php -r "require 'config.php'; echo DB_PATH . PHP_EOL;"
```

#### Issue 3: Database Not Initialized

**Symptom:** Error: "no such table: comments"

**Solution:**
```bash
php setup.php
```

Or manually:
```bash
sqlite3 comments.db < schema.sql
```

#### Issue 4: Admin Panel Empty But Comments Exist

**Cause:** Admin panel shows only 'pending' comments by default

**Solution:**
- Use `admin-all.html` to see ALL comments (approved, pending, spam)
- Filter by status to find your comments

#### Issue 5: CORS Errors (Comments Not Loading)

**Symptom:** Browser console shows CORS errors

**Check config.php:**
```php
define('ALLOWED_ORIGINS', [
    'https://darcynorman.net',  // Must match your domain exactly
]);
```

**Browser console should show:**
```
Access-Control-Allow-Origin: https://darcynorman.net
```

### Manual Database Inspection

```bash
# Connect to database
sqlite3 comments.db

# List all tables
.tables

# Show schema
.schema comments

# Count comments
SELECT COUNT(*) FROM comments;

# Show all statuses
SELECT status, COUNT(*) FROM comments GROUP BY status;

# Show recent 10
SELECT id, author_name, page_url, status, created_at
FROM comments
ORDER BY id DESC
LIMIT 10;

# Exit
.quit
```

### Re-Upload Database

If you have a working local copy with the 232 imported comments:

```bash
# Backup server database first
cp comments.db comments.db.backup

# Upload your local copy
scp /path/to/local/comments.db user@server:/path/to/comments/

# Set permissions
chmod 664 /path/to/comments/comments.db
chown www-data:www-data /path/to/comments/comments.db
```

### Next Steps

1. **Run debug.php** - This will tell you exactly what's wrong
2. **Check file permissions** - Most common issue
3. **Verify database location** - Make sure you're looking at the right file
4. **Check admin-all.html** - Shows all comments, not just pending

### Still Stuck?

Check your web server error logs:
```bash
# Apache
tail -f /var/log/apache2/error.log

# Nginx
tail -f /var/log/nginx/error.log
```

Look for PHP errors related to:
- Database connection failures
- Permission denied errors
- SQLite errors
