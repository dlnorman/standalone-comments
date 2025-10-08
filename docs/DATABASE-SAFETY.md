# Database Safety Guide

## 🚨 CRITICAL: Protecting Your Production Data

Your `comments.db` file contains **all your comments, subscriptions, and settings**. Losing this file means losing all your data. Follow these safety practices:

---

## File Structure

```
comments/
├── comments.db              ← YOUR PRODUCTION DATA (DO NOT COMMIT!)
├── comments-default.db      ← Empty template (safe to commit)
├── backups/                 ← Automated backups directory
│   └── comments-backup-*.db
└── .gitignore              ← Protects comments.db from git
```

---

## ✅ Safe Deployment Process

### Before Uploading Files

1. **Backup your server database first:**
   ```bash
   ssh server
   cd /path/to/comments
   cp comments.db comments-backup-$(date +%Y%m%d).db
   ```

2. **Upload files EXCLUDING comments.db:**
   ```bash
   # Upload specific files (safe)
   scp api.php database.php config.php server:/path/to/comments/

   # OR use rsync with exclude
   rsync -av --exclude='comments.db' --exclude='backups/' \
         comments/ server:/path/to/comments/
   ```

3. **NEVER do this:**
   ```bash
   scp comments/* server:/path/to/comments/  # ❌ Overwrites production DB!
   ```

---

## 🔒 Git Protection

The `.gitignore` file protects your database:

```gitignore
# Protect production database
comments.db
comments.db-*
*BACKUP*.db

# Allow template database
!comments-default.db
```

**Verify protection:**
```bash
git status  # Should NOT show comments.db
git add comments.db  # Should be ignored
```

---

## 💾 Backup Strategies

### Automatic Backups (Recommended)

Use the provided backup script:

```bash
# Manual backup
./dev\ files/backup-db.sh

# Backup with custom name
./dev\ files/backup-db.sh "before-update"
```

### Cron Job (Server)

Add to your crontab:
```cron
# Daily backup at 2am
0 2 * * * cd /path/to/comments && ./dev\ files/backup-db.sh daily

# Weekly backup on Sunday at 3am
0 3 * * 0 cd /path/to/comments && ./dev\ files/backup-db.sh weekly
```

### Manual Backup

```bash
# Quick backup
cp comments.db comments-backup-$(date +%Y%m%d).db

# With verification
sqlite3 comments.db ".backup comments-backup-$(date +%Y%m%d).db"
```

---

## 🔧 Database Migration Safety

### How Migrations Work

1. **New installations:** Copies `comments-default.db` → `comments.db`
2. **Existing installations:** Runs `migrateDatabase()` to add missing tables
3. **Migration is non-destructive:** Only adds missing tables, never drops data

### What Migrations Do

- ✅ Add missing tables (e.g., `subscriptions`)
- ✅ Add missing indexes
- ✅ Preserve all existing data
- ❌ Never drop tables
- ❌ Never delete data
- ❌ Never modify existing columns

### Testing Migrations Safely

```bash
# 1. Make a backup first
cp comments.db comments-test-backup.db

# 2. Test the migration
php -r "require 'database.php';"

# 3. Verify data is intact
sqlite3 comments.db "SELECT COUNT(*) FROM comments;"

# 4. If problems occur, restore
cp comments-test-backup.db comments.db
```

---

## 🆘 Recovery Procedures

### Restore from Backup

```bash
# List available backups
ls -lh backups/

# Restore a specific backup
cp backups/comments-backup-20251007.db comments.db

# Verify restoration
sqlite3 comments.db "SELECT COUNT(*) FROM comments;"
```

### Merge Two Databases

If you accidentally have two databases:

```bash
# Attach second database and copy comments
sqlite3 comments.db << 'EOF'
ATTACH DATABASE 'other-comments.db' AS other;

INSERT OR IGNORE INTO comments
SELECT * FROM other.comments;

INSERT OR REPLACE INTO subscriptions
SELECT * FROM other.subscriptions;

DETACH DATABASE other;
EOF
```

### Export to SQL

```bash
# Full database dump
sqlite3 comments.db .dump > comments-dump-$(date +%Y%m%d).sql

# Comments only
sqlite3 comments.db "SELECT * FROM comments;" > comments-export.csv
```

---

## 📊 Database Health Checks

### Check Database Integrity

```bash
sqlite3 comments.db "PRAGMA integrity_check;"
# Should return: ok
```

### View Database Contents

```bash
# Count records
sqlite3 comments.db << 'EOF'
SELECT 'Comments: ' || COUNT(*) FROM comments;
SELECT 'Subscriptions: ' || COUNT(*) FROM subscriptions;
SELECT 'Settings: ' || COUNT(*) FROM settings;
EOF

# Recent comments
sqlite3 comments.db "SELECT created_at, author_name, page_url FROM comments ORDER BY created_at DESC LIMIT 5;"
```

### Check Database Size

```bash
du -h comments.db
sqlite3 comments.db "VACUUM; SELECT 'Database optimized';"
```

---

## 🔐 Permissions and Security

### Correct File Permissions

```bash
# Database file (read/write for web server only)
chmod 644 comments.db
chown www-data:www-data comments.db  # Adjust for your server

# Prevent direct downloads
# Add to .htaccess:
echo "Deny from all" > .htaccess
```

### Secure Database Location

Consider moving the database outside the web root:

```php
// In config.php
define('DB_PATH', '/var/lib/comments/comments.db');
```

---

## 📋 Pre-Deployment Checklist

Before deploying updates:

- [ ] Backup server database
- [ ] Test changes locally first
- [ ] Verify .gitignore excludes comments.db
- [ ] Upload files WITHOUT comments.db
- [ ] Test admin panel after deployment
- [ ] Verify comment count matches backup
- [ ] Create post-deployment backup

---

## 🚀 Initial Setup (Server)

### First Time Setup

1. **Upload files (excluding database):**
   ```bash
   rsync -av --exclude='comments.db' --exclude='backups/' \
         comments/ server:/path/to/comments/
   ```

2. **Database will auto-initialize from template**
   - Visit any page with comments
   - `comments-default.db` → copied to → `comments.db`

3. **Set admin password:**
   ```bash
   ssh server
   cd /path/to/comments
   php dev\ files/set-password.php
   ```

4. **Enable notifications (optional):**
   ```bash
   sqlite3 comments.db "UPDATE settings SET value='true' WHERE key='enable_notifications';"
   ```

---

## 🔍 Troubleshooting

### Database Locked Error

```bash
# Check for stale locks
fuser comments.db  # Linux
lsof comments.db   # macOS

# Force unlock (if safe)
sqlite3 comments.db "PRAGMA locking_mode=NORMAL; VACUUM;"
```

### Corrupted Database

```bash
# Try to repair
sqlite3 comments.db "REINDEX; VACUUM;"

# If that fails, restore from backup
cp backups/comments-backup-latest.db comments.db
```

### Missing Tables After Update

```bash
# Manually trigger migration
php -r "require 'database.php'; migrateDatabase();"
```

---

## 📝 Development Best Practices

### Local Development

1. **Never use production database locally**
2. **Use separate local database:**
   ```bash
   cp comments-default.db comments.db  # Local only
   ```
3. **Import test data if needed**

### Version Control

```bash
# Check what's being committed
git status | grep -i comment

# Should NOT see:
# - comments.db
# - Any backup files

# SHOULD see:
# - comments-default.db
# - All .php, .js, .css files
```

---

## 🎯 Quick Reference

### Commands to Remember

```bash
# Backup
./dev\ files/backup-db.sh

# Check contents
sqlite3 comments.db "SELECT COUNT(*) FROM comments;"

# Test integrity
sqlite3 comments.db "PRAGMA integrity_check;"

# Safe upload
rsync -av --exclude='comments.db' comments/ server:/path/

# Emergency restore
cp backups/comments-backup-YYYYMMDD.db comments.db
```

---

## ⚠️ What NOT to Do

❌ **Never** commit `comments.db` to git
❌ **Never** upload `comments.db` to server via scp/ftp
❌ **Never** delete `comments.db` on production
❌ **Never** run SQL DROP commands without backup
❌ **Never** test migrations on production first
❌ **Never** use `rm` on database files

✅ **Always** backup before changes
✅ **Always** test locally first
✅ **Always** use rsync with --exclude
✅ **Always** verify backup integrity
✅ **Always** check record counts after migration

---

## 📞 Support

If you need to restore data or have database issues:

1. **Don't panic** - backups exist
2. **Don't make changes** - could make it worse
3. **Check backups directory** - recent backups available
4. **Restore from backup** - follow recovery procedures above
5. **Test restoration** - verify data integrity
