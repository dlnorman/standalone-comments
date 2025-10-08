# Testing Email Subscriptions

## Overview

The new **Subscriptions Admin Panel** lets you view, manage, and test email notifications.

Access it at: `/comments/admin-subscriptions.html`

---

## Features

### 1. View All Subscriptions
- See all subscribers across all pages
- Check active vs unsubscribed status
- View subscription dates

### 2. Manage Subscriptions
- **Unsubscribe** - Deactivate a subscription
- **Reactivate** - Re-enable an unsubscribed user
- **Delete** - Permanently remove subscription

### 3. Test Email Delivery
- Send test emails to verify mail configuration
- Test from admin panel without posting comments
- See immediate feedback on success/failure

---

## How to Test Email Notifications

### Step 1: Enable Notifications

On the server:
```bash
ssh server
cd /path/to/comments
php dev\ files/enable-notifications.php
```

Or manually:
```bash
sqlite3 comments.db "UPDATE settings SET value='true' WHERE key='enable_notifications';"
```

### Step 2: Send a Test Email

1. Log into admin panel: `/comments/admin-subscriptions.html`
2. Navigate to "Test Email Notifications" section
3. Enter your email address
4. Enter a page URL (or use default `/`)
5. Click "Send Test Email"
6. Check your inbox (and spam folder!)

**Expected result:**
```
✓ Test email sent successfully! Check your inbox (and spam folder).
```

### Step 3: Test Real Notifications

**Scenario A: New Comment on Subscribed Page**

1. Post a comment with subscribe checkbox checked (as user@example.com)
2. Post another comment on the same page (as different@example.com)
3. Check inbox for user@example.com
4. Should receive: "New comment on [page]"

**Scenario B: Reply Notification**

1. Post a comment (as user@example.com)
2. Reply to that comment (as replier@example.com)
3. Check inbox for user@example.com
4. Should receive: "New reply to your comment"

---

## Understanding the System

### When Do Emails Get Sent?

✅ **Emails ARE sent when:**
- Someone posts a new comment on a page you're subscribed to
- Someone replies to your comment
- You send a test email from admin panel

❌ **Emails are NOT sent when:**
- You post your own comment
- Notifications are disabled in settings
- Comment is marked as spam
- Server mail() function is not configured

### Why Didn't I Get an Email?

Check these common issues:

1. **Notifications disabled**
   ```bash
   sqlite3 comments.db "SELECT value FROM settings WHERE key='enable_notifications';"
   # Should return: true
   ```

2. **Not subscribed**
   ```bash
   sqlite3 comments.db "SELECT * FROM subscriptions WHERE email='your@email.com';"
   # Should show your subscription
   ```

3. **You posted the comment**
   - The system doesn't notify you about your own comments
   - Test with a different email address

4. **Mail server not configured**
   - PHP's `mail()` function must work
   - Test with the admin panel test email feature

5. **Check spam folder**
   - Notifications might be flagged as spam
   - Add noreply@yourdomain.com to contacts

---

## Admin Panel Walkthrough

### Dashboard Stats

Shows at a glance:
- **Total Subscriptions** - All subscriptions ever created
- **Active** - Currently receiving notifications
- **Unsubscribed** - Users who opted out
- **Pages with Subscribers** - Unique page count

### Test Email Section

**Purpose:** Verify email delivery without posting comments

**Fields:**
- **Send test email to:** Your email address
- **For page (URL):** Page path (e.g., `/2025/01/01/test-post/`)

**What the test email contains:**
```
Subject: Test Email from Comment System

This is a test email from your comment notification system.

If you receive this, email notifications are working correctly!

Test details:
- Page URL: /test/
- Sent at: 2025-10-07 21:30:00
- Server: darcynorman.net
```

### Subscription List

**Shows for each subscription:**
- Email address
- Page URL they're subscribed to
- Subscription date
- Status badge (Active/Unsubscribed)

**Actions:**
- **Unsubscribe** - Set active=0 (soft delete)
- **Reactivate** - Re-enable notifications
- **Delete** - Permanently remove from database

---

## Troubleshooting

### Test Email Says "Failed to send"

**Problem:** Server mail configuration

**Solutions:**

1. **Check if mail() works:**
   ```bash
   php -r "mail('test@example.com', 'Test', 'Test message');"
   ```

2. **Check PHP mail settings:**
   ```bash
   php -i | grep mail
   ```

3. **Common fixes:**
   - Install/configure sendmail or postfix
   - Configure SMTP in php.ini
   - Check server firewall allows port 25

4. **Alternative: Use SMTP plugin**
   - Install PHPMailer
   - Configure SMTP credentials
   - Update notification functions

### Test Email Sent But Not Received

1. **Check spam folder** - Most common issue
2. **Check server mail logs:**
   ```bash
   tail -f /var/log/mail.log
   # OR
   tail -f /var/log/maillog
   ```
3. **Verify email address** - Typos happen
4. **Test with different provider** - Gmail, Yahoo, etc.

### Subscriptions Not Created

**Check database:**
```bash
sqlite3 comments.db "SELECT * FROM subscriptions;"
```

**If empty, check:**
1. Subscribe checkbox was checked
2. Comment was posted successfully
3. Comment status is not 'spam'
4. No JavaScript errors in browser console

**Manual subscription test:**
```bash
sqlite3 comments.db << 'EOF'
INSERT INTO subscriptions (page_url, email, token)
VALUES ('/test/', 'test@example.com', 'test123token');
EOF
```

### No Unsubscribe Link in Email

**This means:**
- User is not subscribed (replied to their comment directly)
- OR subscription doesn't exist

**Fix:**
- Ensure users check subscribe checkbox
- Verify subscriptions table populated

---

## Testing Checklist

Use this checklist to verify everything works:

### Setup
- [ ] Notifications enabled in settings
- [ ] Admin email configured (optional)
- [ ] Server mail() function tested

### Test Email Feature
- [ ] Can access admin-subscriptions.html
- [ ] Test email form loads
- [ ] Test email sends successfully
- [ ] Test email received in inbox

### Subscription Creation
- [ ] Subscribe checkbox appears on comment form
- [ ] Checkbox is checked by default
- [ ] Posting comment creates subscription
- [ ] Subscription appears in admin panel

### Email Notifications
- [ ] New comment triggers email to subscribers
- [ ] Reply triggers email to parent author
- [ ] Author doesn't receive email about own comment
- [ ] Unsubscribe link included in email
- [ ] Unsubscribe link works

### Admin Features
- [ ] Can view all subscriptions
- [ ] Can toggle subscription status
- [ ] Can delete subscriptions
- [ ] Stats update correctly

---

## Production Deployment

Before going live:

1. **Upload all files:**
   ```bash
   rsync -av --exclude='comments.db' --exclude='backups/' \
         comments/ server:/path/to/comments/
   ```

2. **Enable notifications on server:**
   ```bash
   ssh server "cd /path/to/comments && php dev\ files/enable-notifications.php"
   ```

3. **Test email delivery:**
   - Visit `/comments/admin-subscriptions.html`
   - Send test email to yourself
   - Verify receipt

4. **Post test comment:**
   - Visit a blog post
   - Post comment with subscribe checked
   - Verify subscription created

5. **Test notification:**
   - Post second comment (different email)
   - Verify first commenter receives email

6. **Monitor initial rollout:**
   - Check admin panel daily
   - Watch for subscription growth
   - Monitor spam reports

---

## Email Deliverability Tips

### Improve Inbox Placement

1. **Configure SPF record:**
   ```
   v=spf1 mx a ip4:YOUR.SERVER.IP ~all
   ```

2. **Configure DKIM:**
   - Sign emails with domain key
   - Reduces spam score

3. **Set From address to your domain:**
   ```php
   // In api.php, update headers:
   $headers = "From: comments@darcynorman.net\r\n";
   ```

4. **Avoid spam triggers:**
   - ✅ Clear subject lines
   - ✅ Plain text email (not HTML)
   - ✅ Include unsubscribe link
   - ✅ Use real domain in From address

5. **Monitor bounce rates:**
   - Check mail logs for bounces
   - Remove invalid emails
   - Keep bounce rate < 5%

---

## Advanced Testing

### Load Testing Subscriptions

```bash
# Add 100 test subscriptions
for i in {1..100}; do
  sqlite3 comments.db "INSERT INTO subscriptions (page_url, email, token)
    VALUES ('/test/', 'test$i@example.com', '$(openssl rand -hex 32)');"
done
```

### Test Email Batching

Post a comment on a page with multiple subscribers:
- Verify all receive emails
- Check mail logs for delivery
- Confirm no duplicates sent

### Test Unsubscribe Flow

1. Get unsubscribe link from email
2. Click link
3. Verify confirmation page
4. Confirm unsubscribe
5. Check subscription set to inactive
6. Post another comment
7. Verify no email sent

---

## Quick Reference

```bash
# Enable notifications
sqlite3 comments.db "UPDATE settings SET value='true' WHERE key='enable_notifications';"

# Count subscriptions
sqlite3 comments.db "SELECT COUNT(*) FROM subscriptions WHERE active=1;"

# List all subscribers for a page
sqlite3 comments.db "SELECT email FROM subscriptions WHERE page_url='/your/page/' AND active=1;"

# Test mail function
php -r "mail('test@example.com', 'Test', 'Test message');"

# Check mail logs
tail -f /var/log/mail.log
```

---

## Support

If emails still aren't working after all tests:

1. **Check server mail logs** - Most informative
2. **Try command-line mail** - `echo "test" | mail -s "test" you@example.com`
3. **Consider third-party SMTP** - SendGrid, Mailgun, etc.
4. **Contact hosting support** - May need to enable mail features

Remember: The admin panel test email feature is your friend! Use it to quickly verify mail configuration without posting test comments.
