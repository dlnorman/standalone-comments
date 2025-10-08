# Package Contents

This standalone distribution contains everything needed to deploy a self-hosted comment system.

## Core Files

### Application Files
- `api.php` - Main REST API endpoint (handles all comment operations)
- `config.php` - Configuration file (edit for your domain/timezone)
- `database.php` - Database initialization and helper functions
- `comments.js` - Frontend JavaScript widget
- `comments.css` - Default styling for comment interface

### Admin Interface
- `admin.html` - Main admin panel (pending comments)
- `admin-all.html` - View all comments across site
- `admin-subscriptions.html` - Subscription management panel

### Public Pages
- `index.html` - Welcome/demo page
- `recent-comments.html` - Recent comments display page
- `unsubscribe.php` - Email unsubscribe handler

### Configuration & Security
- `.htaccess` - Apache security rules (CRITICAL - protects sensitive files)
- `.gitignore` - Prevents committing production database
- `config.php` - System configuration (edit this!)

## Database

### db/
- `comments-default.db` - Clean template database with schema
- `comments.db` - Production database (auto-created on first run)
- `comments-dev.db` - Development database (auto-created locally)

## Documentation

### Root Documentation
- `README.md` - Project overview and quick start
- `INSTALL.md` - Complete installation guide
- `CHANGELOG.md` - Version history and updates
- `CONTRIBUTING.md` - Contribution guidelines
- `LICENSE` - MIT License
- `DEPLOYMENT-SUMMARY.md` - Deployment checklist

### docs/
- `FEATURES.md` - Complete feature list
- `PRODUCTION-CHECKLIST.md` - Pre-launch checklist
- `SECURITY-AUDIT.md` - Security analysis
- `SECURITY-FIXES-APPLIED.md` - Security fix history
- `DATABASE-SAFETY.md` - Database protection guide
- `SAFE-DEPLOYMENT.md` - Step-by-step deployment
- `SUBSCRIPTIONS.md` - Email notification setup
- `TESTING-SUBSCRIPTIONS.md` - Email testing guide
- `RECENT-COMMENTS.md` - Recent comments feature
- `TROUBLESHOOTING.md` - Common issues and solutions
- `UPDATES.md` - Update procedures

## Hugo Integration

### hugo/
- `README.md` - Hugo-specific integration guide
- `hugo-partial.html` - For use in theme layouts
- `hugo-shortcode.html` - For use in content files
- `recent-comments-shortcode.html` - Recent comments widget
- `example.html` - Standalone example page

## Utilities

### utils/
Utility scripts for setup, maintenance, and migration (blocked by .htaccess)

#### Setup & Configuration
- `setup.php` - Initialize database from scratch
- `set-password.php` - Set/reset admin password
- `enable-notifications.php` - Enable email notifications
- `schema.sql` - Database schema (fallback)

#### Maintenance
- `backup-db.sh` - Automated database backup script
- `test-htaccess.sh` - Security configuration tester
- `test-email.php` - Email delivery tester
- `test-api.html` - API endpoint tester
- `debug.php` - Debug information tool

#### Migration & Import
- `import-disqus.php` - Import from Disqus XML export
- `import-talkyard.php` - Import from TalkYard JSON export
- `IMPORT-SUMMARY.md` - Import tool documentation
- `migrate-subscriptions.php` - Migrate subscription data
- `add-subscriptions.php` - Bulk add subscriptions
- `fix-urls.php` - Fix URL paths in comments

#### Server Configuration
- `nginx.conf.example` - Nginx configuration template
- `AUTO-APPROVE.md` - Auto-approval system docs
- `index.html` - Blocks directory listing

## Directory Structure

```
standalone-comments/
├── README.md                      # Start here!
├── INSTALL.md                     # Installation guide
├── LICENSE                        # MIT License
├── CONTRIBUTING.md                # How to contribute
├── CHANGELOG.md                   # Version history
├── DEPLOYMENT-SUMMARY.md          # Deployment checklist
├── PACKAGE-CONTENTS.md            # This file
│
├── api.php                        # Main API
├── config.php                     # Configuration (edit!)
├── database.php                   # Database functions
├── comments.js                    # Frontend widget
├── comments.css                   # Styling
│
├── admin.html                     # Admin panel
├── admin-all.html                 # All comments view
├── admin-subscriptions.html       # Subscriptions
├── index.html                     # Demo page
├── recent-comments.html           # Recent comments
├── unsubscribe.php                # Unsubscribe handler
│
├── .htaccess                      # Security (Apache)
├── .gitignore                     # Git protection
│
├── db/                            # Databases
│   ├── comments-default.db        # Template
│   ├── comments.db                # Production (auto-created)
│   └── comments-dev.db            # Development (auto-created)
│
├── docs/                          # Documentation
│   ├── FEATURES.md
│   ├── PRODUCTION-CHECKLIST.md
│   ├── SECURITY-AUDIT.md
│   ├── DATABASE-SAFETY.md
│   ├── SUBSCRIPTIONS.md
│   ├── RECENT-COMMENTS.md
│   └── ... (more docs)
│
├── hugo/                          # Hugo integration
│   ├── README.md
│   ├── hugo-partial.html
│   ├── hugo-shortcode.html
│   ├── recent-comments-shortcode.html
│   └── example.html
│
└── utils/                         # Utilities (blocked)
    ├── setup.php
    ├── set-password.php
    ├── backup-db.sh
    ├── import-disqus.php
    ├── import-talkyard.php
    ├── nginx.conf.example
    └── ... (more utils)
```

## Quick Start

1. **Upload** entire directory to your server
2. **Edit** `config.php` with your domain and timezone
3. **Set password** via `utils/set-password.php`
4. **Test security** with `utils/test-htaccess.sh`
5. **Integrate** using Hugo shortcode or JavaScript

Full guide: See `INSTALL.md`

## What Gets Created Automatically

On first run, the system will auto-create:
- `db/comments.db` - Production database (or `db/comments-dev.db` locally)
- `backups/` - Backup directory (when running backup script)

## What You Need to Configure

Minimum required configuration in `config.php`:
```php
// Your domain(s)
define('ALLOWED_ORIGINS', [
    'https://yourdomain.com',
]);

// Your timezone
date_default_timezone_set('America/New_York');
```

## What You Can Safely Delete After Setup

After successful installation and testing:
- `utils/set-password.php` (security best practice)
- `utils/setup.php` (if not needed)
- `INSTALL.md` (keep for reference though)

## What You Should NEVER Delete

Critical files:
- `.htaccess` - Provides security!
- `config.php` - System configuration
- `database.php` - Database functions
- `api.php` - Core API
- `db/comments.db` - Your data!

## Support Files

Documentation and examples can be kept for reference or removed if disk space is a concern:
- All of `docs/` directory
- All of `hugo/` directory (after copying to theme)
- `CHANGELOG.md`, `CONTRIBUTING.md`, etc.

## Security Notes

**Protected by .htaccess:**
- `/db/` directory (all databases)
- `/utils/` directory (all scripts)
- `/backups/` directory (all backups)
- All `.db`, `.sql`, `.sh`, `.log` files
- All `.md` documentation files

**Test security** after deployment:
```bash
cd utils
./test-htaccess.sh https://yourdomain.com/comments
```

All sensitive files should show `✓ BLOCKED`.

## File Sizes

Total package size: ~1.5MB (including documentation and utilities)

Core runtime files only: ~200KB
- PHP: ~60KB
- JavaScript: ~10KB  
- CSS: ~5KB
- HTML: ~80KB
- Database template: ~60KB

## Browser Compatibility

Tested and working on:
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest, including iOS)
- Mobile browsers (Chrome Mobile, Safari Mobile)

## Server Requirements

- **PHP:** 7.4+ (8.0+ recommended)
- **SQLite:** Included in PHP by default
- **Web Server:** Apache (with mod_rewrite) or Nginx
- **Disk Space:** 10MB minimum (more for comments/backups)
- **Memory:** 32MB PHP memory minimum

## License

All files licensed under MIT License. See `LICENSE` file.

---

**Version:** 2.0  
**Last Updated:** October 2025  
**Package Type:** Complete standalone distribution
