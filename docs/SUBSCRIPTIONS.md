# Comment Subscription System

## Overview

The comment system now includes an email subscription feature that allows users to receive notifications when new comments are posted on pages they're interested in.

## Features

✅ **Opt-in Checkbox** - Users can subscribe when posting a comment (checked by default)
✅ **Page-Specific** - Subscriptions are per-page, not site-wide
✅ **Auto-Exclusion** - Comment authors don't receive notifications about their own comments
✅ **Unsubscribe Links** - Every notification email includes a one-click unsubscribe link
✅ **Secure Tokens** - Each subscription has a unique unsubscribe token
✅ **Reply Notifications** - Direct replies still notify the parent comment author

## How It Works

### For Commenters

1. **Subscribe**: When posting a comment, check the "Notify me of follow-up comments" box (checked by default)
2. **Receive Notifications**: Get an email whenever a new comment is posted on that page
3. **Unsubscribe**: Click the unsubscribe link in any notification email

### For Admins

#### Initial Setup

1. **Run the migration script** to add the subscriptions table:
   ```bash
   php dev\ files/add-subscriptions.php
   ```

2. **Enable email notifications** in your settings (if not already enabled):
   ```bash
   php dev\ files/set-password.php
   # Then update the 'enable_notifications' setting to 'true'
   ```

#### How Notifications Work

When a new comment is posted:

1. **All Subscribers**: Everyone subscribed to that page gets notified (except the comment author)
2. **Reply Notifications**: If it's a reply, the parent comment author gets a special "reply" notification
3. **Admin Notification**: Site admin still receives all comment notifications

## Email Content

### Subscription Notification
```
Hello,

[Author Name] posted a new comment on [Page URL]:

[Comment Content]

View and reply: [Page URL]#comment-[ID]

---
To unsubscribe from notifications for this page: [Unsubscribe Link]
```

### Reply Notification
```
Hello [Parent Author],

[Author Name] replied to your comment on [Page URL]:

[Comment Content]

View and reply: [Page URL]#comment-[ID]

---
To unsubscribe from notifications: [Unsubscribe Link]
```

## Database Schema

### Subscriptions Table

```sql
CREATE TABLE subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_url TEXT NOT NULL,
    email TEXT NOT NULL,
    token TEXT UNIQUE NOT NULL,
    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    active INTEGER DEFAULT 1,
    UNIQUE(page_url, email)
);
```

**Fields:**
- `page_url` - The page being subscribed to
- `email` - Subscriber's email address
- `token` - Unique unsubscribe token (64 hex characters)
- `subscribed_at` - When the subscription was created
- `active` - 1 = active, 0 = unsubscribed

**Constraints:**
- One subscription per email per page (UNIQUE constraint)
- Tokens are globally unique

## API Changes

### POST /api.php?action=post

**New Field:**
```json
{
  "subscribe": true
}
```

When `subscribe` is true, a subscription is created for the commenter's email on that page.

## Security Features

✅ **Email Header Injection Protection** - All user input sanitized
✅ **Email Validation** - Invalid emails skipped
✅ **Unique Tokens** - Cryptographically secure random tokens
✅ **No Info Leakage** - Invalid tokens show generic error

## Unsubscribe Process

1. User clicks unsubscribe link in email
2. Taken to `/comments/unsubscribe.php?token=...`
3. Shows confirmation page with subscription details
4. Click "Yes, Unsubscribe" to confirm
5. Subscription set to `active = 0` (soft delete)

## Privacy Considerations

- **Email addresses are not shared** with other commenters
- **Subscriptions are invisible** to other users
- **One-click unsubscribe** - no login required
- **Email addresses validated** before sending
- **Soft delete** - subscriptions kept but inactive (for analytics)

## Customization

### Change Default Checkbox State

In `comments.js`, line 66:

```javascript
// Checked by default
<input type="checkbox" name="subscribe" value="1" checked>

// Unchecked by default
<input type="checkbox" name="subscribe" value="1">
```

### Customize Email Subject

In `api.php`, line 193:

```php
$subject = "New comment on " . parse_url($pageUrl, PHP_URL_PATH);
```

### Customize Unsubscribe Page

Edit `/comments/unsubscribe.php` for custom styling or messaging.

## Testing

### Test Subscription Flow

1. Post a comment with subscribe checkbox checked
2. Check database: `SELECT * FROM subscriptions;`
3. Should see entry with your email and unique token

### Test Notifications

1. Enable notifications in settings
2. Subscribe to a page
3. Post another comment on the same page (different email)
4. First email should receive notification

### Test Unsubscribe

1. Copy token from database or email
2. Visit: `/comments/unsubscribe.php?token=YOUR_TOKEN`
3. Confirm unsubscribe
4. Check database: `active` should be 0

## Troubleshooting

### Emails Not Sending

1. Check that `enable_notifications` is set to `true`:
   ```sql
   SELECT * FROM settings WHERE key = 'enable_notifications';
   ```

2. Verify PHP mail() is working:
   ```bash
   php -r "mail('test@example.com', 'Test', 'Test message');"
   ```

### Duplicate Subscriptions

The database prevents this with a UNIQUE constraint on (page_url, email). If someone tries to subscribe twice, the existing subscription is updated (INSERT OR REPLACE).

### Unsubscribe Not Working

- Check that the token exists: `SELECT * FROM subscriptions WHERE token = 'TOKEN';`
- Verify the token hasn't been modified in the URL
- Check that the unsubscribe.php file has proper permissions

## Migration from Old System

If you're upgrading from a version without subscriptions:

1. **Backup your database**:
   ```bash
   cp comments.db comments.db.backup
   ```

2. **Run migration**:
   ```bash
   php dev\ files/add-subscriptions.php
   ```

3. **Verify**:
   ```bash
   sqlite3 comments.db "SELECT name FROM sqlite_master WHERE type='table';"
   ```
   Should include `subscriptions` in the list.

## Future Enhancements

Potential improvements:

- ✨ Subscribe without commenting
- ✨ Manage all subscriptions page
- ✨ Email preferences (digest vs real-time)
- ✨ Subscription analytics for admins
- ✨ Re-subscribe functionality

## Support

For issues or questions, check:
- `TROUBLESHOOTING.md` for common problems
- `SECURITY-AUDIT.md` for security considerations
- `README.md` for general documentation
