# Auto-Approve Comments Configuration

## Current Behavior

By default, **all comments require moderation** (status = 'pending').

## How to Enable Auto-Approval

### Option 1: Disable Moderation (All comments auto-approved)

Update the database setting:

```sql
UPDATE settings SET value = 'false' WHERE key = 'require_moderation';
```

Or via command line:
```bash
sqlite3 comments.db "UPDATE settings SET value = 'false' WHERE key = 'require_moderation';"
```

**Result:** All new comments will have status='approved' and show immediately.

### Option 2: Smart Auto-Approval (Recommended)

Keep moderation enabled but rely on spam detection. The system already has:

**Automatic Spam Detection:**
- Comments with spam score ≥4 are auto-marked as 'spam'
- Low-risk comments get status='pending' for review
- After review, you can approve them

**Spam Detection Criteria:**
- Excessive links (>3 URLs): +2 points
- Spam keywords (viagra, casino, etc.): +3 points each
- Excessive caps (10+ consecutive): +1 point
- Suspicious email domains: +1 point
- Too short (<10 chars) or too long (>4000 chars): +1 point

### Option 3: First-Time Moderation (Future Enhancement)

Could be added: Auto-approve comments from previously-approved email addresses.

Would require adding this to `api.php`:

```php
// Check if this email has approved comments before
$stmt = $db->prepare("
    SELECT COUNT(*) as count FROM comments
    WHERE author_email = ? AND status = 'approved'
");
$stmt->execute([$authorEmail]);
$result = $stmt->fetch();
$hasApprovedComments = $result['count'] > 0;

if ($hasApprovedComments && !$isSpam) {
    $status = 'approved';  // Auto-approve trusted commenters
} else {
    $status = 'pending';   // First-time commenters need moderation
}
```

## Current Settings

Check your current settings:

```bash
sqlite3 comments.db "SELECT * FROM settings WHERE key = 'require_moderation';"
```

## Recommendation

For your blog, I'd suggest:

1. **Keep moderation enabled** for now
2. **Rely on spam detection** to catch obvious spam
3. **Review pending queue** periodically
4. **Later**: Add first-time moderation once you're comfortable with the system

This gives you:
- ✅ Control over what gets published
- ✅ Automatic spam filtering
- ✅ Protection against spam floods
- ✅ Ability to see all comments (using admin-all.html)

## Troubleshooting

If comments aren't showing up:

1. **Check database location:**
   ```bash
   php -r "require 'config.php'; echo DB_PATH . PHP_EOL;"
   ```

2. **Check if comments were saved:**
   ```bash
   sqlite3 comments.db "SELECT COUNT(*) FROM comments;"
   ```

3. **Check recent comments:**
   ```bash
   sqlite3 comments.db "SELECT id, author_name, status, created_at FROM comments ORDER BY id DESC LIMIT 5;"
   ```

4. **Use debug.php:**
   Visit: `https://darcynorman.net/comments/debug.php`
   (Delete this file after troubleshooting!)
