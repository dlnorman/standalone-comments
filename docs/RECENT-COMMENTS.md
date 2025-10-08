# Recent Comments Feature

Display recent comments from across your entire site.

## API Endpoint

### GET `/comments/api.php?action=recent&limit=10`

**Public endpoint** - no authentication required

**Parameters:**
- `limit` (optional) - Number of comments to return (1-100, default: 10)

**Response:**
```json
{
  "comments": [
    {
      "id": 123,
      "page_url": "/blog/my-post/",
      "author_name": "Jane Doe",
      "author_url": "https://example.com",
      "content": "Full comment text...",
      "excerpt": "First 150 characters...",
      "created_at": "2025-10-08 14:30:00"
    }
  ]
}
```

**Notes:**
- Only returns **approved** comments
- Email addresses are never exposed
- Content is truncated to 150 chars in `excerpt` field
- Results ordered by most recent first

## Usage Options

### Option 1: Standalone Page

Use the pre-built HTML page at `/comments/recent-comments.html`

Features:
- Responsive design
- Dropdown to select 5/10/20/50/100 comments
- Relative timestamps (e.g., "5 minutes ago")
- Links to original comment on page
- Hover effects

**How to use:**
1. Access directly: `https://yourdomain.com/comments/recent-comments.html`
2. Link from your site navigation or footer
3. Embed in iframe (not recommended for SEO)

---

### Option 2: Hugo Shortcode

Copy `hugo/recent-comments-shortcode.html` to your theme:
```bash
cp hugo/recent-comments-shortcode.html themes/yourtheme/layouts/shortcodes/recent-comments.html
```

**Usage in content:**
```markdown
---
title: Recent Comments
---

Check out what people are saying:

{{< recent-comments >}}
```

**With custom parameters:**
```markdown
{{< recent-comments limit="20" title="Latest Discussions" excerpt="false" >}}
```

**Parameters:**
- `limit="10"` - How many comments to show (max 100)
- `title="Recent Comments"` - Widget heading
- `excerpt="true"` - Show comment excerpts (true/false)

---

### Option 3: Custom Integration

Fetch and display comments with your own JavaScript:

```javascript
async function loadRecentComments(limit = 10) {
    const response = await fetch(`/comments/api.php?action=recent&limit=${limit}`);
    const data = await response.json();

    if (data.comments) {
        data.comments.forEach(comment => {
            console.log(comment.author_name, 'on', comment.page_url);
            console.log(comment.excerpt);
        });
    }
}

loadRecentComments(10);
```

**Example HTML widget:**
```html
<div id="recent-comments"></div>

<script>
fetch('/comments/api.php?action=recent&limit=5')
    .then(r => r.json())
    .then(data => {
        const html = data.comments.map(c => `
            <div class="comment">
                <strong>${c.author_name}</strong>
                <p>${c.excerpt}</p>
                <a href="${c.page_url}#comment-${c.id}">Read more</a>
            </div>
        `).join('');

        document.getElementById('recent-comments').innerHTML = html;
    });
</script>
```

---

### Option 4: RSS Feed

Create a custom RSS feed endpoint (requires additional work):

```php
<?php
// recent-comments-rss.php
require_once 'config.php';
require_once 'database.php';

header('Content-Type: application/rss+xml; charset=utf-8');

$db = getDatabase();
$stmt = $db->prepare("
    SELECT id, page_url, author_name, content, created_at
    FROM comments
    WHERE status = 'approved'
    ORDER BY created_at DESC
    LIMIT 20
");
$stmt->execute();
$comments = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0">
  <channel>
    <title>Recent Comments</title>
    <link>https://yourdomain.com</link>
    <description>Latest comments from the blog</description>
    <?php foreach ($comments as $c): ?>
    <item>
      <title><?= htmlspecialchars($c['author_name']) ?> on <?= htmlspecialchars($c['page_url']) ?></title>
      <link>https://yourdomain.com<?= $c['page_url'] ?>#comment-<?= $c['id'] ?></link>
      <description><?= htmlspecialchars($c['content']) ?></description>
      <pubDate><?= date('r', strtotime($c['created_at'])) ?></pubDate>
    </item>
    <?php endforeach; ?>
  </channel>
</rss>
```

## Styling

The standalone HTML page and Hugo shortcode include built-in styles. To customize:

**CSS Variables:**
```css
.recent-comments-widget {
    --primary-color: #3498db;
    --text-color: #333;
    --bg-color: #fafafa;
    --border-color: #3498db;
}
```

**Override styles in your theme:**
```css
.recent-comment-item {
    border-left-color: #e74c3c; /* Custom accent color */
}

.recent-comment-item:hover {
    background: #ecf0f1; /* Custom hover color */
}
```

## Performance Considerations

**Caching:**
The API endpoint has cache-control headers set to `no-cache` for real-time updates. For high-traffic sites, consider:

1. **Client-side caching:**
```javascript
// Cache for 5 minutes
const CACHE_KEY = 'recent-comments';
const CACHE_DURATION = 5 * 60 * 1000;

function getCachedComments() {
    const cached = localStorage.getItem(CACHE_KEY);
    if (cached) {
        const data = JSON.parse(cached);
        if (Date.now() - data.timestamp < CACHE_DURATION) {
            return data.comments;
        }
    }
    return null;
}

function setCachedComments(comments) {
    localStorage.setItem(CACHE_KEY, JSON.stringify({
        comments: comments,
        timestamp: Date.now()
    }));
}
```

2. **Static site generation:**
If using Hugo, fetch comments at build time and embed in HTML (requires build hook).

## Privacy Considerations

The recent comments API:
- ✓ Never exposes email addresses
- ✓ Only shows approved comments
- ✓ Respects comment moderation settings
- ✓ Includes `rel="nofollow noopener"` on author URLs

## Examples

### Sidebar Widget
Create a narrow sidebar version:
```html
<aside class="sidebar">
    <h3>Recent Comments</h3>
    <div id="sidebar-comments"></div>
</aside>

<script>
fetch('/comments/api.php?action=recent&limit=5')
    .then(r => r.json())
    .then(data => {
        const html = data.comments.map(c => `
            <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                <strong>${c.author_name}</strong> on
                <a href="${c.page_url}#comment-${c.id}">${getPageTitle(c.page_url)}</a>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">
                    ${c.excerpt}
                </div>
            </div>
        `).join('');
        document.getElementById('sidebar-comments').innerHTML = html;
    });

function getPageTitle(url) {
    return url.split('/').filter(Boolean).pop() || 'Home';
}
</script>
```

### Footer Widget
```html
<footer>
    <div class="footer-comments">
        <h4>Join the Conversation</h4>
        <p>Recent comments from our community:</p>
        <div id="footer-comments"></div>
    </div>
</footer>
```

## Troubleshooting

**Comments not loading:**
1. Check browser console for errors
2. Verify API endpoint: `curl https://yourdomain.com/comments/api.php?action=recent&limit=5`
3. Check CORS settings in `config.php`

**Blank page:**
1. Ensure `/comments/api.php` is accessible
2. Check database has approved comments
3. Verify JavaScript is enabled

**Wrong timezone:**
Comments use server timezone set in `config.php`:
```php
date_default_timezone_set('America/Edmonton');
```

---

**Last Updated:** 2025-10-08
