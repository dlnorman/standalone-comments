# Production Deployment Checklist

## Pre-Deployment

- [ ] Test locally with `dev.marker` file or localhost
- [ ] Verify all comments display correctly
- [ ] Test admin login works locally
- [ ] Check email notifications (if enabled)
- [ ] Run `utils/test-htaccess.sh` locally if using PHP built-in server

## Deployment Steps

### 1. Upload Files
Upload all files to server **except**:
- `db/comments-dev.db` (your local development database)
- `dev.marker` (local development flag)
- Any `*.log` files

### 2. Database Setup
Move your production database to the correct location:
```bash
# On server, ensure database is in db/ folder
mv comments.db db/comments.db  # If upgrading from old structure
```

Or copy your local database:
```bash
# Locally, copy dev to production
cp db/comments-dev.db db/comments.db

# Upload db/comments.db to server
```

### 3. Set Permissions
```bash
# On server
chmod 755 db/
chmod 644 db/comments.db
chmod 644 db/comments-default.db

# Ensure PHP can write to database
chown www-data:www-data db/comments.db  # Apache/Nginx
# or
chown nobody:nobody db/comments.db      # LiteSpeed
```

### 4. Verify Configuration

Visit (temporarily enable in .htaccess if needed):
```
https://yourdomain.com/comments/utils/debug-config.php
```

Should show:
- ✓ Database file exists: YES
- ✓ Database connection successful
- ✓ Admin password set: YES
- ✓ Tables found: 4 (comments, settings, subscriptions, sqlite_sequence)

**Delete debug-config.php after verification!**

### 5. Security Verification

Run security test:
```bash
./utils/test-htaccess.sh https://yourdomain.com/comments
```

All sensitive files should show `✓ BLOCKED`:
- Database directory (`db/`)
- Utils directory (`utils/`)
- Backups directory (`backups/`)
- `.db` files
- `.sh` scripts
- `.sql` files

### 6. Test Core Functions

- [ ] Comments load on a page
- [ ] Can post new comment (appears as pending if moderation enabled)
- [ ] Admin login works
- [ ] Can approve/delete comments from admin panel
- [ ] Reply threading works
- [ ] Email subscriptions work (if enabled)

## Post-Deployment

### Cleanup Server
Remove any debug/setup files from server:
```bash
rm utils/debug-config.php
rm utils/set-password-prod.php
```

### Remove Old Database Location
If upgrading from old structure:
```bash
# On server, remove old database from root
rm comments.db
rm comments-default.db
rm comments-backup-*.db
```

### Monitor Logs
Check for errors:
```bash
# Apache
tail -f /var/log/apache2/error.log

# Nginx
tail -f /var/log/nginx/error.log

# LiteSpeed
tail -f /usr/local/lsws/logs/error.log
```

## Environment Detection

Production automatically detected when:
- HTTP_HOST is your domain (not localhost/127.0.0.1)
- No `dev.marker` file exists
- Not running PHP built-in server
- COMMENT_ENV is not set to 'development'

To verify which database is being used, check:
```php
// In any PHP file, temporarily add:
echo DB_PATH;
```

Should show: `/path/to/comments/db/comments.db` (not comments-dev.db)

## Troubleshooting

### Can't Login to Admin
1. Check database location: `ls -la db/`
2. Verify admin password is set in database
3. Check cookie settings in browser (SameSite, Secure flags)
4. Clear browser cookies for your domain

### Database Not Found
1. Ensure `db/comments.db` exists on server
2. Check file permissions (644, readable by web server)
3. Verify PHP can write to `db/` directory (755)

### Comments Not Displaying
1. Check CORS settings in `config.php` - production domain must be in ALLOWED_ORIGINS
2. Check browser console for JavaScript errors
3. Verify API endpoint works: `curl https://yourdomain.com/comments/api.php?action=comments&url=/test`

### .htaccess Not Working
1. Verify Apache has `AllowOverride All` in site config
2. Check `mod_rewrite` is enabled: `apache2ctl -M | grep rewrite`
3. Test each protection rule with curl: `curl -I https://yourdomain.com/comments/db/`

## Configuration Files to Review

Before deploying, check these settings:

### `config.php`
- [ ] `ALLOWED_ORIGINS` includes your production domain
- [ ] `date_default_timezone_set()` is correct
- [ ] Error reporting disabled: `ini_set('display_errors', '0')`

### `.htaccess`
- [ ] All debug exceptions removed
- [ ] `db/` directory blocked
- [ ] `utils/` directory blocked
- [ ] `backups/` directory blocked

### `database.php`
- [ ] Points to `db/comments-default.db` template
- [ ] Creates `db/` directory if needed

## Rollback Plan

If something goes wrong:

1. **Backup current database:**
   ```bash
   cp db/comments.db db/comments-backup-$(date +%Y%m%d-%H%M%S).db
   ```

2. **Restore from backup:**
   ```bash
   cp backups/comments-backup-TIMESTAMP.db db/comments.db
   ```

3. **Revert code:**
   - Keep old version in `comments-old/` during deployment
   - Can quickly switch back if needed

## Success Criteria

Deployment is successful when:
- ✓ Comments display on pages
- ✓ Can submit new comments
- ✓ Admin panel accessible and functional
- ✓ All security tests pass (no exposed files)
- ✓ Database in correct location (`db/comments.db`)
- ✓ No errors in server logs
- ✓ Email notifications work (if enabled)

---

**Last Updated:** 2025-10-08
**Version:** 2.0 (with db/ folder structure)
