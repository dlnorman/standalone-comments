# Comment System Features

## TalkYard Import ✅

Your `talkyard-export.json` file is **fully compatible** with the import script I created.

### How to Import from TalkYard

```bash
php import-talkyard.php talkyard-export.json
```

The script will:
- Parse the JSON export (pages, posts, members, guests)
- Map TalkYard's page IDs to actual URLs
- Convert comment threads (preserving parent/child relationships)
- Extract author information (name, email, website)
- Import all comments as "approved" status
- Maintain original timestamps

**Important Notes:**
- TalkYard uses special URL formats like `/comments-for-https...` - the script automatically converts these back to your original URLs
- Posts marked as "nr: 0" or "nr: 1" are page metadata and are skipped
- Deleted comments in TalkYard are automatically excluded
- The script handles both members and guests

After import, check the output for any URL mapping warnings and verify everything looks correct in the admin panel.

---

## Spam Prevention ✅

### 1. **Rate Limiting**
- **IP-based**: Maximum 5 comments per hour from the same IP address
- **Email-based**: Maximum 3 comments per 10 minutes from the same email

Returns HTTP 429 with clear error message when limit exceeded.

### 2. **Honeypot Field**
- Invisible `website` field in the form (hidden with CSS)
- Legitimate users won't see or fill it
- Bots that auto-fill forms will trigger it
- Instant rejection with generic error message

### 3. **Spam Detection Algorithm**

Automatic spam scoring based on:
- **Excessive links** (>3 URLs): +2 points
- **Spam keywords** (viagra, casino, loan, etc.): +3 points per match
- **Excessive caps** (10+ consecutive capitals): +1 point
- **Suspicious email domains** (tempmail, disposable): +1 point
- **Content length** (too short <10 or too long >4000): +1 point

**Score ≥4 = Auto-marked as spam**

### 4. **Input Validation**
- Email format validation
- URL sanitization
- Content length limits (max 5000 chars)
- SQL injection protection via prepared statements
- XSS protection via HTML escaping

### 5. **Manual Moderation**
- All comments can require approval before showing (configurable)
- Admin panel to review, approve, mark as spam, or delete
- IP address logging for tracking spam sources

---

## Email Notifications ✅

### Features Implemented:

#### 1. **Reply Notifications**
When someone replies to a comment, the original commenter receives an email:
```
Subject: New reply to your comment
Hello [Parent Commenter],

[New Author] replied to your comment on [Page URL]:

[Comment content]

View and reply: [Direct link to comment]
```

#### 2. **Admin Notifications**
Site admin receives email for all new comments:
```
Subject: New comment on your site
New comment from [Author] on [Page URL]:

[Comment content]

Manage comments: [Link to admin panel]
```

### Configuration:

Enable notifications in the database:
```sql
UPDATE settings SET value = 'true' WHERE key = 'enable_notifications';
UPDATE settings SET value = 'admin@yourdomain.com' WHERE key = 'admin_email';
```

Or via admin panel once you add a settings page.

### Email Privacy:
- Commenter emails are **never exposed** to other users
- Only used for notifications
- No unsubscribe link currently (future enhancement)

### Technical Details:
- Uses PHP's `mail()` function (requires server mail configuration)
- Sent asynchronously (doesn't block comment posting)
- Failures are silent (@mail suppresses errors)
- Headers set to avoid spam filters

---

## Current Limitations & Future Enhancements

### Not Yet Implemented (But Easy to Add):

1. **Email Unsubscribe**
   - Add `notification_opt_in` column to track preferences
   - Include unsubscribe link in emails
   - Create unsubscribe endpoint

2. **CAPTCHA**
   - Add reCAPTCHA or hCAPTCHA
   - Integrate in form and validate server-side

3. **Akismet Integration**
   - More sophisticated spam detection
   - Requires API key

4. **Email Verification**
   - Verify email addresses before allowing comments
   - Send confirmation code

5. **User Subscriptions**
   - Allow users to subscribe to threads
   - Get notified of all replies

6. **Better Email Templates**
   - HTML emails with styling
   - More customization options

### Recommended Next Steps:

1. **Test the TalkYard import** with your actual data
2. **Configure email settings** in your server and database
3. **Test spam prevention** by submitting test comments
4. **Review imported comments** in the admin panel
5. **Adjust spam detection thresholds** based on your needs

---

## Summary

✅ **TalkYard Import**: Yes, fully compatible
✅ **Spam Prevention**: Multi-layered (rate limiting, honeypot, detection, moderation)
✅ **Email Notifications**: Reply notifications + admin alerts

The system is production-ready with solid spam protection and notification features!
