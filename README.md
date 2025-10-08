# Standalone Comment System

A lightweight, self-hosted commenting system for static sites. No external dependencies, no tracking, no ads. Just comments.

**Perfect for:** Hugo, Jekyll, Eleventy, or any static site generator

## Features

✓ **Self-hosted** - Your data, your server, your control
✓ **SQLite database** - No MySQL/PostgreSQL required
✓ **Threaded replies** - Nested comment conversations
✓ **Email subscriptions** - Notify users of new replies
✓ **Spam detection** - Built-in spam scoring
✓ **Comment moderation** - Approve/delete from admin panel
✓ **Rate limiting** - Prevent abuse (5 comments/hour per IP)
✓ **Recent comments** - Site-wide recent comments widget
✓ **Hugo integration** - Ready-to-use partials and shortcodes
✓ **Import tools** - Migrate from Disqus, TalkYard
✓ **Responsive design** - Mobile-friendly interface
✓ **Security focused** - SQL injection protection, XSS prevention
✓ **Privacy respecting** - No tracking, minimal data collection

## Quick Start

1. **Upload** to your server: `/public_html/comments/`
2. **Configure** `config.php` with your domain
3. **Set password** via `utils/set-password.php`
4. **Integrate** using Hugo shortcode or JavaScript

**Full installation guide:** [INSTALL.md](INSTALL.md)

## Requirements

- PHP 7.4+ (8.0+ recommended)
- SQLite support (included in PHP by default)
- Apache with mod_rewrite (or Nginx)
- Write permissions for database directory

## Demo

Visit `/comments/` after installation to see:
- Working comment form
- Admin panel demo
- Recent comments widget
- Integration examples

## Directory Structure

```
comments/
├── README.md                    # This file
├── .htaccess                    # Security protection
├── .gitignore                   # Protects production data
│
├── api.php                      # Main API endpoint
├── config.php                   # Configuration
├── database.php                 # Database initialization
│
├── comments.js                  # Frontend widget
├── comments.css                 # Styles
│
├── admin.html                   # Pending comments admin
├── admin-all.html               # All comments admin
├── admin-subscriptions.html     # Subscription management
├── unsubscribe.php              # Public unsubscribe page
├── index.html                   # Landing/info page
│
├── comments-default.db          # Empty template database
├── comments.db                  # Production database (auto-created, not in git)
├── comments-dev.db              # Local development database (not in git)
│
├── docs/                        # Documentation
│   ├── DATABASE-SAFETY.md
│   ├── FEATURES.md
│   ├── SAFE-DEPLOYMENT.md
│   ├── SECURITY-AUDIT.md
│   ├── SECURITY-FIXES-APPLIED.md
│   ├── SUBSCRIPTIONS.md
│   ├── TESTING-SUBSCRIPTIONS.md
│   ├── TROUBLESHOOTING.md
│   └── UPDATES.md
│
├── utils/                       # Utility scripts (blocked by .htaccess)
│   ├── setup.php
│   ├── set-password.php
│   ├── enable-notifications.php
│   ├── import-disqus.php
│   ├── import-talkyard.php
│   ├── fix-urls.php
│   ├── migrate-subscriptions.php
│   ├── test-email.php
│   ├── test-htaccess.sh
│   ├── backup-db.sh
│   └── schema.sql
│
├── hugo/                        # Hugo integration templates
│   ├── README.md
│   ├── hugo-partial.html        # For theme layouts
│   ├── hugo-shortcode.html      # For content files
│   └── example.html             # Standalone example
│
└── backups/                     # Database backups (auto-created, not in git)
```

## Configuration

Edit `config.php`:

```php
// Timezone (IMPORTANT!)
date_default_timezone_set('America/Edmonton');

// Allowed origins for CORS
define('ALLOWED_ORIGINS', [
    'http://localhost:1313',
    'https://yourdomain.com'
]);
```

## Admin Panel

Access at: `https://yourdomain.com/comments/admin.html`

### Pages:
- **Pending** - Moderate new comments
- **All Comments** - View and manage all comments
- **Subscriptions** - View subscribers, test email delivery

## Testing Email Notifications

1. Visit `/comments/admin-subscriptions.html`
2. Click "Test Email Notifications"
3. Enter your email address
4. Check inbox (and spam folder)

## Security Features

- ✅ SQL injection protection (prepared statements)
- ✅ XSS protection (output escaping)
- ✅ CSRF protection (CORS whitelist)
- ✅ Email header injection protection
- ✅ Rate limiting (IP + email based)
- ✅ Spam detection
- ✅ Honeypot fields
- ✅ Secure cookies (HTTPOnly, Secure)
- ✅ Database file protection
- ✅ Utility script blocking
- ✅ Security headers

## Database Safety

**IMPORTANT:** Never commit or upload `comments.db` accidentally!

- ✅ `.gitignore` protects production database
- ✅ Auto-detects localhost vs production
- ✅ Uses `comments-dev.db` for local development
- ✅ Uses `comments.db` in production
- ✅ Backups stored in `backups/` directory

### Backup Database

```bash
# Create backup
./utils/backup-db.sh

# Or manually
cp comments.db backups/comments-backup-$(date +%Y%m%d).db
```

## Troubleshooting

### Comments not loading

- Check browser console for errors
- Verify `api.php` is accessible
- Check CORS configuration in `config.php`

### Email not sending

- Run: `php utils/test-email.php`
- Check server mail logs
- Verify notifications enabled in settings

### Database errors

- Check file permissions: `chmod 644 comments.db`
- Verify SQLite extension: `php -m | grep sqlite`
- Check .htaccess allows PHP execution

## Documentation

See the `/docs` folder for comprehensive guides:

- **DATABASE-SAFETY.md** - Protecting your data
- **SAFE-DEPLOYMENT.md** - Step-by-step deployment
- **SUBSCRIPTIONS.md** - Email subscription system
- **TESTING-SUBSCRIPTIONS.md** - Testing email delivery
- **SECURITY-AUDIT.md** - Security analysis
- **TROUBLESHOOTING.md** - Common issues

## Importing Existing Comments

### From Disqus

```bash
# Export from Disqus, then:
php utils/import-disqus.php path/to/export.xml
```

### From TalkYard

```bash
# Export from TalkYard, then:
php utils/import-talkyard.php path/to/export.json
```

## Requirements

- PHP 7.4+
- SQLite extension
- Apache with mod_rewrite (recommended)
- mail() function configured (for notifications)

## Hugo Integration

Hugo integration templates are in the `/hugo` directory.

### Quick Setup

**Option 1: Partial (in theme templates)**
```bash
cp hugo/hugo-partial.html themes/yourtheme/layouts/partials/comments.html
```
```html
{{ partial "comments.html" . }}
```

**Option 2: Shortcode (in content files)**
```bash
cp hugo/hugo-shortcode.html themes/yourtheme/layouts/shortcodes/comments.html
```
```markdown
{{< comments >}}
```

### Recent Comments Widget

Display recent comments from across your site:

```bash
cp hugo/recent-comments-shortcode.html themes/yourtheme/layouts/shortcodes/recent-comments.html
```
```markdown
{{< recent-comments limit="10" >}}
```

**See `/hugo/README.md` for full documentation**

## Support

For issues or questions:

1. Check `/docs/TROUBLESHOOTING.md`
2. Check browser console for errors
3. Check server error logs
4. Review security audit in `/docs/SECURITY-AUDIT.md`

## License

This comment system is provided as-is for personal use.

## Credits

Built for self-hosted, privacy-focused commenting on static websites.

---

**Version:** 2.0
**Last Updated:** October 2025
