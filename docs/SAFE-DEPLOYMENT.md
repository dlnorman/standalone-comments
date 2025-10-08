# Safe Deployment Guide

## 🎯 TL;DR - Deploy Without Losing Data

```bash
# 1. Backup server database first
ssh server "cd /path/to/comments && cp comments.db comments-backup-\$(date +%Y%m%d).db"

# 2. Upload files EXCLUDING database
rsync -av --exclude='comments.db' --exclude='backups/' \
      /Users/dnorman/Documents/Blog/blog/static/comments/ \
      server:/path/to/comments/

# 3. Verify (should show same comment count as before)
ssh server "sqlite3 /path/to/comments/comments.db 'SELECT COUNT(*) FROM comments;'"
```

---

## 📁 What Gets Uploaded vs Protected

### ✅ SAFE TO UPLOAD (Code Files)
- `api.php` - API endpoints
- `database.php` - Database logic
- `config.php` - Configuration
- `comments.js` - Frontend widget
- `comments.css` - Styles
- `admin.html` - Admin panel
- `admin-all.html` - All comments view
- `unsubscribe.php` - Unsubscribe page
- `comments-default.db` - Empty template database

### ❌ NEVER UPLOAD (Data Files)
- `comments.db` - YOUR PRODUCTION DATA
- `comments-BACKUP-*.db` - Backup files
- `backups/` - Backup directory
- `comments.db-shm` - SQLite temp files
- `comments.db-wal` - SQLite temp files

---

## 🔒 Safety Features Implemented

### 1. Git Protection
The `.gitignore` file prevents accidental commits:
```gitignore
comments.db          # Production database
comments.db-*        # SQLite temporary files
*BACKUP*.db          # Backup files
backups/             # Backup directory
```

**Test it:**
```bash
git status  # Should NOT show comments.db
```

### 2. Template Database System
- `comments-default.db` - Clean template (safe to commit/upload)
- `comments.db` - Production data (protected)
- On first run, copies template → production

### 3. Non-Destructive Migrations
The `database.php` file:
- ✅ Adds missing tables (like `subscriptions`)
- ✅ Preserves all existing data
- ❌ Never drops tables or deletes data

### 4. Backup Script
Automated backup creation:
```bash
./dev\ files/backup-db.sh          # Creates timestamped backup
./dev\ files/backup-db.sh "v2.0"   # Named backup
```

---

## 📦 Deployment Methods

### Method 1: rsync (Recommended)

```bash
# Upload only code files, exclude data
rsync -av \
  --exclude='comments.db' \
  --exclude='comments.db-*' \
  --exclude='backups/' \
  --exclude='.git' \
  /Users/dnorman/Documents/Blog/blog/static/comments/ \
  user@server:/path/to/comments/
```

**Why rsync?**
- Only uploads changed files
- Automatically excludes protected files
- Preserves permissions
- Shows what's being transferred

### Method 2: Selective SCP

```bash
# Upload specific files only
scp api.php database.php config.php \
    comments.js comments.css \
    admin.html admin-all.html \
    unsubscribe.php comments-default.db \
    user@server:/path/to/comments/
```

**Why selective?**
- Full control over what's uploaded
- No risk of overwriting database
- Good for small updates

### Method 3: Git Deploy (Advanced)

```bash
# On server
cd /path/to/comments
git pull origin main

# comments.db is gitignored, so it's protected
```

**Why git?**
- Track all code changes
- Easy rollback
- Team collaboration
- Database automatically excluded

---

## 🚀 Step-by-Step First Deployment

### On Your Local Machine

1. **Verify .gitignore is working:**
   ```bash
   cd /Users/dnorman/Documents/Blog/blog/static/comments
   git status  # Should NOT see comments.db
   ```

2. **Test locally:**
   ```bash
   # Visit http://localhost:1313 with Hugo running
   # Post a test comment
   # Verify it works
   ```

### On Server

3. **Create directory structure:**
   ```bash
   ssh server
   mkdir -p /path/to/comments/backups
   ```

4. **Upload files (EXCLUDING database):**
   ```bash
   # From local machine
   rsync -av --exclude='comments.db' --exclude='backups/' \
         comments/ server:/path/to/comments/
   ```

5. **Initialize database:**
   ```bash
   # Database auto-creates from comments-default.db template
   # Just visit your site - it happens automatically
   ```

6. **Set admin password:**
   ```bash
   ssh server
   cd /path/to/comments
   php dev\ files/set-password.php
   # Enter your admin password
   ```

7. **Test:**
   - Visit a blog post
   - Post a test comment
   - Log into admin panel
   - Verify everything works

---

## 🔄 Updating Existing Installation

### Before You Start

1. **Backup server database:**
   ```bash
   ssh server "cd /path/to/comments && \
               cp comments.db backups/comments-backup-\$(date +%Y%m%d-%H%M%S).db"
   ```

2. **Check current data:**
   ```bash
   ssh server "sqlite3 /path/to/comments/comments.db \
               'SELECT COUNT(*) FROM comments;'"
   # Remember this number!
   ```

### Deploy Updates

3. **Upload changed files:**
   ```bash
   rsync -av --exclude='comments.db' --exclude='backups/' \
         comments/ server:/path/to/comments/
   ```

4. **Migrations run automatically:**
   - Visit any page
   - `database.php` checks for missing tables
   - Adds them if needed
   - All data preserved

### Verify

5. **Check data is intact:**
   ```bash
   ssh server "sqlite3 /path/to/comments/comments.db \
               'SELECT COUNT(*) FROM comments;'"
   # Should match the number from step 2!
   ```

6. **Test functionality:**
   - Load comments on a page
   - Post a new comment
   - Log into admin
   - Moderate a comment

---

## ⚠️ Common Mistakes to Avoid

### ❌ DON'T DO THIS

```bash
# Uploading everything (overwrites database!)
scp -r comments/* server:/path/to/comments/  # DANGEROUS!

# Committing database to git
git add comments.db  # NO!

# Testing on production first
# Always test locally first

# Deleting database without backup
rm comments.db  # Only do this locally after backup
```

### ✅ DO THIS INSTEAD

```bash
# Use rsync with excludes
rsync -av --exclude='comments.db' comments/ server:/path/

# Verify git protection
git status  # Check comments.db is not shown

# Test locally first
# Make changes → test locally → deploy to server

# Backup before deleting
cp comments.db comments-backup.db && rm comments.db
```

---

## 🆘 Emergency Recovery

### "I accidentally uploaded comments.db and overwrote production!"

1. **Stop immediately** - don't make more changes
2. **Check server backups:**
   ```bash
   ssh server "ls -lht /path/to/comments/backups/"
   ```
3. **Restore most recent backup:**
   ```bash
   ssh server "cd /path/to/comments && \
               cp backups/comments-backup-LATEST.db comments.db"
   ```
4. **Verify restoration:**
   ```bash
   ssh server "sqlite3 /path/to/comments/comments.db \
               'SELECT COUNT(*) FROM comments;'"
   ```

### "My local database disappeared!"

1. **Check for backups:**
   ```bash
   ls -lht comments-BACKUP-*.db
   ```
2. **Restore:**
   ```bash
   cp comments-BACKUP-20251007-*.db comments.db
   ```
3. **Or re-initialize from template:**
   ```bash
   cp comments-default.db comments.db
   ```

---

## 📋 Pre-Deployment Checklist

Print this and check off each item:

- [ ] Backed up server database
- [ ] Tested changes locally
- [ ] Verified .gitignore excludes comments.db
- [ ] Used rsync with --exclude OR selective scp
- [ ] Did NOT upload comments.db
- [ ] Tested admin panel after deployment
- [ ] Verified comment count matches pre-deployment
- [ ] Created post-deployment backup

---

## 🎓 Understanding the Safety System

### How Database Initialization Works

```
First Installation:
1. No comments.db exists
2. System checks for comments-default.db
3. Copies template → comments.db
4. Ready to use!

Existing Installation:
1. comments.db exists
2. System runs migrateDatabase()
3. Checks for missing tables
4. Adds only what's missing
5. All data preserved
```

### What Happens on First Page Load

```
1. api.php loads
2. Requires database.php
3. database.php checks if comments.db exists:

   NO → Copy comments-default.db to comments.db
   YES → Run migrations (add missing tables only)

4. Returns database connection
5. API processes request
```

### Migration Safety

Migrations are **additive only**:
```sql
-- What migrations do:
CREATE TABLE IF NOT EXISTS ...  -- ✅ Safe
CREATE INDEX IF NOT EXISTS ...  -- ✅ Safe

-- What migrations DON'T do:
DROP TABLE ...                  -- ❌ Never
DELETE FROM ...                 -- ❌ Never
ALTER TABLE ... DROP COLUMN ... -- ❌ Never
```

---

## 📞 Quick Commands Reference

```bash
# Backup
./dev\ files/backup-db.sh

# Upload safely
rsync -av --exclude='comments.db' comments/ server:/path/

# Check data integrity
sqlite3 comments.db "PRAGMA integrity_check;"

# Count records
sqlite3 comments.db "SELECT COUNT(*) FROM comments;"

# Restore backup
cp backups/comments-backup-YYYYMMDD.db comments.db

# Test git protection
git status | grep comments.db  # Should return nothing
```

---

**Remember:** When in doubt, backup first! 💾
