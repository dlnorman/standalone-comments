# Installation Guide

Complete guide for installing the Standalone Comment System on your server.

## Requirements

- PHP 7.4 or higher (8.0+ recommended)
- SQLite support (enabled by default in most PHP installations)
- Apache with mod_rewrite (or Nginx with equivalent configuration)
- Write permissions for database directory

## Quick Start

### 1. Download and Upload

```bash
# Download the latest release
git clone https://github.com/yourusername/standalone-comments.git

# Or download ZIP and extract
```

Upload the entire `standalone-comments` directory to your web server:
```
/public_html/comments/  (or your preferred location)
```

### 2. Set Permissions

```bash
cd /path/to/comments
chmod 755 db/
chmod 644 db/comments-default.db
chmod 644 config.php
```

The system will automatically create `db/comments.db` on first run.

### 3. Configure

Edit `config.php`:

```php
// Add your domain to ALLOWED_ORIGINS
define('ALLOWED_ORIGINS', [
    'https://yourdomain.com',  // Your production domain
    'http://localhost:1313',   // Optional: Hugo dev server
]);

// Set your timezone
date_default_timezone_set('America/New_York');  // Change to your timezone
```

### 4. Set Admin Password

Visit: `https://yourdomain.com/comments/utils/set-password.php`

Set your admin password, then **delete the file** for security:
```bash
rm utils/set-password.php
```

### 5. Verify Security

Run the security test:
```bash
cd utils
./test-htaccess.sh https://yourdomain.com/comments
```

All sensitive files should show `✓ BLOCKED`.

### 6. Test

- Visit: `https://yourdomain.com/comments/`
- Login to admin: `https://yourdomain.com/comments/admin.html`
- View example: `https://yourdomain.com/comments/hugo/example.html`

---

## Detailed Installation

### Prerequisites Check

**Check PHP version:**
```bash
php -v
```
Should show 7.4 or higher.

**Check SQLite support:**
```bash
php -m | grep -i sqlite
```
Should show `pdo_sqlite` and `sqlite3`.

**Check Apache mod_rewrite:**
```bash
apache2ctl -M | grep rewrite
```
Should show `rewrite_module`.

### Directory Structure

After upload, your directory should look like:
```
comments/
├── api.php              # Main API endpoint
├── config.php           # Configuration (edit this!)
├── database.php         # Database initialization
├── comments.js          # Frontend JavaScript
├── comments.css         # Styling
├── admin.html           # Admin panel
├── admin-all.html       # View all comments
├── admin-subscriptions.html  # Subscription management
├── index.html           # Welcome/demo page
├── recent-comments.html # Recent comments display
├── unsubscribe.php      # Email unsubscribe handler
├── .htaccess            # Security rules (critical!)
├── .gitignore           # Git ignore rules
├── README.md            # Overview
├── INSTALL.md           # This file
├── CHANGELOG.md         # Version history
├── db/
│   ├── comments-default.db  # Template database
│   └── comments.db      # Created automatically (production)
├── docs/                # Documentation
│   ├── FEATURES.md
│   ├── PRODUCTION-CHECKLIST.md
│   ├── RECENT-COMMENTS.md
│   ├── SECURITY-AUDIT.md
│   ├── SUBSCRIPTIONS.md
│   └── TROUBLESHOOTING.md
├── hugo/                # Hugo integration files
│   ├── README.md
│   ├── hugo-partial.html
│   ├── hugo-shortcode.html
│   ├── recent-comments-shortcode.html
│   └── example.html
└── utils/               # Utility scripts (blocked by .htaccess)
    ├── setup.php
    ├── set-password.php
    ├── backup-db.sh
    ├── import-disqus.php
    ├── import-talkyard.php
    ├── enable-notifications.php
    ├── test-email.php
    └── schema.sql
```

### Configuration Options

**config.php** - Main configuration:
```php
// Database path (auto-detects dev vs production)
define('DB_PATH', __DIR__ . ($isLocalhost ? '/db/comments-dev.db' : '/db/comments.db'));

// CORS - IMPORTANT: Add your domains here!
define('ALLOWED_ORIGINS', [
    'http://localhost:1313',
    'https://yourdomain.com',
]);

// Timezone - Set to your location
date_default_timezone_set('America/New_York');

// Error reporting (keep display_errors OFF for production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
```

**.htaccess** - Security rules:
- Blocks database directory
- Blocks utils directory
- Blocks backups directory
- Prevents direct access to sensitive files
- Enables CORS for API

**DO NOT delete or modify .htaccess unless you know what you're doing!**

### Database Initialization

The database is automatically created on first run:

1. System checks if `db/comments.db` exists
2. If not, copies `db/comments-default.db` as template
3. If template doesn't exist, creates from `utils/schema.sql`
4. Runs any necessary migrations

**Manual initialization** (if needed):
```bash
cd utils
php -f setup.php
```

### Setting Admin Password

**Method 1: Web interface (recommended)**
1. Visit `https://yourdomain.com/comments/utils/set-password.php`
2. Enter your password
3. Delete the file immediately after use

**Method 2: Command line**
```bash
cd utils
php set-password.php
```

**Method 3: SQL directly**
```bash
cd db
sqlite3 comments.db
```
```sql
UPDATE settings
SET value = '$2y$10$your_bcrypt_hash_here'
WHERE key = 'admin_password_hash';
.quit
```

Generate bcrypt hash in PHP:
```php
php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
```

### Environment Detection

The system automatically detects development vs production:

**Development mode used when:**
- `COMMENT_ENV=development` environment variable is set
- HTTP_HOST contains `localhost`, `127.0.0.1`, `.local`, or `:1313`
- PHP built-in server (`php -S`)
- CLI mode
- `dev.marker` file exists in root

**Production mode (default):**
- Uses `db/comments.db`
- Any other environment

**Force development mode:**
```bash
# Create marker file
touch dev.marker

# Or set environment variable
export COMMENT_ENV=development
```

### Web Server Configuration

#### Apache (.htaccess included)

The included `.htaccess` should work automatically if:
- `AllowOverride All` is enabled in Apache config
- `mod_rewrite` is enabled

**If .htaccess doesn't work:**
Add to Apache VirtualHost config:
```apache
<Directory /path/to/comments>
    AllowOverride All
    Require all granted
</Directory>
```

#### Nginx

If using Nginx, add to your site config:

```nginx
location /comments/ {
    # Block database directory
    location ~ ^/comments/db/ {
        deny all;
        return 403;
    }

    # Block utils directory
    location ~ ^/comments/utils/ {
        deny all;
        return 403;
    }

    # Block backups directory
    location ~ ^/comments/backups/ {
        deny all;
        return 403;
    }

    # Block sensitive file types
    location ~ \.(db|db-shm|db-wal|sql|log|sh|bak|backup)$ {
        deny all;
        return 403;
    }

    # Process PHP files
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

#### LiteSpeed

The `.htaccess` rules work with LiteSpeed by default.

### Integrate with Your Site

#### Option 1: Hugo Partial (theme templates)

Copy to your theme:
```bash
cp hugo/hugo-partial.html themes/yourtheme/layouts/partials/comments.html
```

Add to your theme's single post template:
```html
{{ partial "comments.html" . }}
```

#### Option 2: Hugo Shortcode (content files)

Copy to your theme:
```bash
cp hugo/hugo-shortcode.html themes/yourtheme/layouts/shortcodes/comments.html
```

Use in any markdown file:
```markdown
{{< comments >}}
```

#### Option 3: Plain HTML/JavaScript

Add to your page:
```html
<div id="comments"></div>
<script src="/comments/comments.js"></script>
<script>
    CommentSystem.init({
        pageUrl: window.location.pathname,
        apiUrl: '/comments/api.php'
    });
</script>
```

### Email Notifications (Optional)

Enable email notifications for new comments:

1. **Enable notifications:**
```bash
cd utils
php enable-notifications.php
# Follow prompts
```

2. **Test email delivery:**
```bash
php test-email.php your-email@example.com
```

3. **Configure PHP mail:**

Ensure your server can send mail. Options:
- Use system `sendmail`
- Configure SMTP in `php.ini`
- Use a mail plugin (Postfix, Exim)

**For better deliverability, consider:**
- Setting up SPF/DKIM records
- Using a transactional email service (SendGrid, Mailgun, etc.)

### Import Existing Comments

#### From Disqus

```bash
cd utils
php import-disqus.php /path/to/disqus-export.xml
```

#### From TalkYard

```bash
cd utils
php import-talkyard.php /path/to/talkyard-export.json
```

See `utils/IMPORT-SUMMARY.md` for details.

## Post-Installation

### Security Checklist

- [ ] Admin password is set
- [ ] `utils/set-password.php` is deleted
- [ ] Run `utils/test-htaccess.sh` - all sensitive files blocked
- [ ] Database directory is not web-accessible
- [ ] `config.php` has correct ALLOWED_ORIGINS
- [ ] Error display is turned off (`display_errors = 0`)
- [ ] Backups directory is created and blocked

### Backup Strategy

**Automated backups:**
```bash
# Add to crontab (daily at 2 AM)
crontab -e
```
```
0 2 * * * /path/to/comments/utils/backup-db.sh
```

**Manual backup:**
```bash
cd utils
./backup-db.sh
```

Backups are stored in `backups/` directory with timestamps.

### Monitoring

**Check logs:**
```bash
# Apache
tail -f /var/log/apache2/error.log

# Nginx
tail -f /var/log/nginx/error.log

# PHP-FPM
tail -f /var/log/php8.2-fpm.log
```

**Monitor database size:**
```bash
du -h db/comments.db
```

**Check for spam:**
Visit admin panel regularly and review pending comments.

## Updating

### Minor Updates

```bash
# Backup first!
cp db/comments.db db/comments-backup-$(date +%Y%m%d).db

# Pull updates
git pull origin main

# Check CHANGELOG.md for any required actions
```

### Major Updates

Follow instructions in `CHANGELOG.md` for each version.

**Always backup before updating!**

## Troubleshooting

### "Database connection failed"

**Check:**
1. Does `db/comments.db` exist?
2. Is it readable? `chmod 644 db/comments.db`
3. Is PHP SQLite extension enabled? `php -m | grep sqlite`

**Fix:**
```bash
cd utils
php setup.php
```

### "403 Forbidden" errors

**Check:**
1. Is `.htaccess` uploaded?
2. Is `AllowOverride` enabled in Apache?
3. Are you trying to access blocked files (db/, utils/)?

**Test:**
```bash
curl -I https://yourdomain.com/comments/api.php
# Should return 200 OK
```

### Can't login to admin

**Check:**
1. Is admin password set? View `admin-subscriptions.html` debug info
2. Are cookies enabled in browser?
3. Is HTTPS configured correctly?

**Reset password:**
```bash
cd utils
php set-password.php
```

### Comments not displaying

**Check:**
1. Browser console for JavaScript errors
2. Is correct API URL configured?
3. Is CORS configured for your domain in `config.php`?
4. Test API: `curl https://yourdomain.com/comments/api.php?action=comments&url=/test`

### Email notifications not working

**Check:**
1. Is `enable_notifications` set to `true`?
2. Can PHP send mail? `php test-email.php your@email.com`
3. Check spam folder
4. Check server mail logs

**Debug:**
```bash
cd utils
php -f test-email.php
```

See `docs/TROUBLESHOOTING.md` for more solutions.

## Support

- **Documentation:** See `docs/` directory
- **Issues:** Report bugs via GitHub Issues
- **Security:** Email security issues privately to maintainer

## Uninstallation

To completely remove:

```bash
# Backup data first if needed
cp db/comments.db ~/comments-backup.db

# Remove directory
rm -rf /path/to/comments

# Remove from theme (if integrated)
rm themes/yourtheme/layouts/partials/comments.html
rm themes/yourtheme/layouts/shortcodes/comments.html
```

---

**Installation complete!**

Visit your comments system at: `https://yourdomain.com/comments/`

Next steps:
1. Read `README.md` for feature overview
2. Check `docs/FEATURES.md` for capabilities
3. Review `docs/PRODUCTION-CHECKLIST.md` before going live
