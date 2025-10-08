# Deployment Summary - Version 2.0

## ✅ What's Fixed

### 1. Timezone Issue ✓
- **Problem**: Comments showed wrong time (3:55am instead of 9:55pm)
- **Cause**: SQLite CURRENT_TIMESTAMP returns UTC, not local time
- **Fix**: API now explicitly sets timestamps using PHP's configured timezone
- **Result**: Timestamps now display correctly in America/Edmonton timezone

### 2. Database Safety ✓
- **Problem**: Risk of accidentally overwriting production database
- **Fix**:
  - Local development uses `comments-dev.db`
  - Production uses `comments.db`
  - Auto-detection based on hostname
  - Both protected by `.gitignore`
- **Result**: Cannot accidentally upload/commit production data

### 3. Directory Organization ✓
- **Problem**: Root directory cluttered with documentation and scripts
- **Fix**:
  - All `.md` files (except README) → `docs/` folder
  - All utility scripts → `utils/` folder
  - `.htaccess` blocks both `utils/` and `backups/` directories
- **Result**: Clean, professional structure

### 4. Security Enhancements ✓
- **`.htaccess` now blocks**:
  - All `.db` files (including `.db-shm`, `.db-wal`)
  - `utils/` directory
  - `backups/` directory
  - Sensitive file types (`.sql`, `.md`, `.log`, `.sh`)
  - Direct access to `config.php` and `database.php`

---

## 📂 New Directory Structure

```
comments/
├── README.md                      ← Updated with new structure
├── CHANGELOG.md                   ← New: Version history
├── DEPLOYMENT-SUMMARY.md          ← New: This file
│
├── Core Files (Production)
├── api.php                        ← Updated: Timezone fixes, subscription endpoints
├── config.php                     ← Updated: Auto-detect localhost
├── database.php                   ← Updated: Uses utils/schema.sql
├── comments.js                    ← Updated: Subscribe checkbox
├── comments.css                   ← Updated: Checkbox styling
│
├── Admin Panels
├── admin.html                     ← Updated: Cache fixes, nav updated
├── admin-all.html                 ← Updated: Cache fixes, nav updated
├── admin-subscriptions.html       ← New: Subscription management
├── unsubscribe.php                ← For public use
│
├── Hugo Integration
├── hugo-partial.html
├── hugo-shortcode.html
│
├── Security
├── .htaccess                      ← Updated: Blocks utils/, backups/, .db files
├── .gitignore                     ← Updated: Protects comments-dev.db
│
├── Databases
├── comments-default.db            ← Empty template (safe to upload)
├── comments.db                    ← Production (auto-created, protected)
├── comments-dev.db                ← Local dev (auto-created, protected)
│
├── docs/                          ← New folder
│   ├── DATABASE-SAFETY.md         ← Comprehensive safety guide
│   ├── FEATURES.md
│   ├── SAFE-DEPLOYMENT.md         ← Deployment instructions
│   ├── SECURITY-AUDIT.md
│   ├── SECURITY-FIXES-APPLIED.md
│   ├── SUBSCRIPTIONS.md           ← Subscription system docs
│   ├── TESTING-SUBSCRIPTIONS.md   ← Email testing guide
│   ├── TROUBLESHOOTING.md
│   └── UPDATES.md
│
└── utils/                         ← New folder (was "dev files")
    ├── setup.php
    ├── set-password.php
    ├── enable-notifications.php   ← New: Easy notification setup
    ├── test-email.php             ← New: Test email delivery
    ├── test-htaccess.sh           ← New: Security testing
    ├── backup-db.sh               ← New: Automated backups
    ├── migrate-subscriptions.php  ← New: Database migration
    ├── import-disqus.php
    ├── import-talkyard.php
    ├── fix-urls.php
    ├── debug.php
    └── schema.sql
```

---

## 🚀 Deployment Instructions

### Step 1: Upload Files to Server

```bash
# From your local machine
cd /Users/dnorman/Documents/Blog/blog/static/comments

# Upload everything except databases and backups
rsync -av \
  --exclude='comments.db' \
  --exclude='comments-dev.db' \
  --exclude='backups/' \
  --exclude='.git/' \
  ./ server:/path/to/comments/
```

### Step 2: Verify Upload

```bash
ssh server
cd /path/to/comments

# Check structure
ls -la

# Should see:
# - docs/ folder
# - utils/ folder
# - comments-default.db
# - NO comments.db (will be auto-created)
```

### Step 3: Enable Notifications (Optional)

```bash
# Still on server
php utils/enable-notifications.php

# Enter your email when prompted (or skip)
```

### Step 4: Test Security

```bash
# On server or from local machine
./utils/test-htaccess.sh https://yourdomain.com/comments

# Should show:
# ✓ BLOCKED for all .db files
# ✓ BLOCKED for utils/ directory
# ✓ BLOCKED for backups/ directory
# ✓ ACCESSIBLE for admin.html, api.php, etc.
```

### Step 5: Test Functionality

1. **Visit a blog post** with comments
   - Should see comment form
   - Should see subscribe checkbox

2. **Post a test comment**
   - Should create database automatically
   - Check `/path/to/comments/` - should now have `comments.db`

3. **Log into admin panel**
   - Visit: `https://yourdomain.com/comments/admin.html`
   - Use your admin password

4. **Test email (if enabled)**
   - Visit: `https://yourdomain.com/comments/admin-subscriptions.html`
   - Click "Test Email Notifications"
   - Check inbox

### Step 6: Verify Timezone

1. Post a comment
2. Note the time shown
3. Should match your local time (America/Edmonton)
4. If wrong, check `config.php` timezone setting

---

## 🔍 Verification Checklist

After deployment, verify:

- [ ] Comments load on blog posts
- [ ] Subscribe checkbox appears and is checked by default
- [ ] Posting comment works
- [ ] Timestamp shows correct local time
- [ ] Admin panel loads (`/comments/admin.html`)
- [ ] Can log into admin
- [ ] Can moderate comments
- [ ] Subscriptions panel works (`/comments/admin-subscriptions.html`)
- [ ] Database file exists on server (`comments.db`)
- [ ] Cannot download database: `https://yourdomain.com/comments/comments.db` returns 403
- [ ] Cannot access utils: `https://yourdomain.com/comments/utils/` returns 403
- [ ] Cannot access backups: `https://yourdomain.com/comments/backups/` returns 403

---

## 🐛 If Something Goes Wrong

### Comments show wrong time

**Check timezone in config.php:**
```bash
grep timezone config.php
# Should show: date_default_timezone_set('America/Edmonton');
```

**If incorrect, update and re-upload config.php**

### Cannot access admin panel

1. **Check .htaccess uploaded:**
   ```bash
   ls -la .htaccess
   ```

2. **Check Apache has AllowOverride enabled**

3. **Check browser console for errors**

### Database not created

1. **Check permissions:**
   ```bash
   ls -la /path/to/comments/
   # Directory should be writable by web server
   chmod 755 /path/to/comments
   ```

2. **Check PHP SQLite extension:**
   ```bash
   php -m | grep sqlite
   # Should show: pdo_sqlite, sqlite3
   ```

### Email test fails

1. **Check notifications enabled:**
   ```bash
   sqlite3 comments.db "SELECT value FROM settings WHERE key='enable_notifications';"
   # Should return: true
   ```

2. **Test mail() function:**
   ```bash
   php utils/test-email.php
   ```

3. **Check server mail logs:**
   ```bash
   tail -f /var/log/mail.log
   ```

---

## 📞 Support Resources

- **Deployment Guide**: `docs/SAFE-DEPLOYMENT.md`
- **Database Safety**: `docs/DATABASE-SAFETY.md`
- **Email Testing**: `docs/TESTING-SUBSCRIPTIONS.md`
- **Troubleshooting**: `docs/TROUBLESHOOTING.md`
- **Security Audit**: `docs/SECURITY-AUDIT.md`

---

## 🎉 What's New in v2.0

1. ✅ **Fixed timezone handling** - Times now display correctly
2. ✅ **Improved database safety** - Cannot accidentally overwrite production
3. ✅ **Reorganized structure** - Professional folder organization
4. ✅ **Enhanced security** - Better .htaccess protection
5. ✅ **Subscription management** - Admin panel for subscribers
6. ✅ **Email testing** - Test delivery from admin panel
7. ✅ **Better documentation** - Comprehensive guides in `/docs`
8. ✅ **Utility scripts** - Tools for backups, testing, etc.

---

## 💾 Backup Reminder

**Before any major changes, always backup first:**

```bash
# Create backup
./utils/backup-db.sh

# Or manually
cp comments.db backups/comments-backup-$(date +%Y%m%d).db
```

---

## ✨ Ready to Deploy!

Your comment system is production-ready with all fixes applied:

```bash
# One-command deployment
rsync -av --exclude='comments.db' --exclude='comments-dev.db' --exclude='backups/' \
      comments/ server:/path/to/comments/
```

Then visit your site and test! 🚀
