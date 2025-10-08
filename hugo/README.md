# Hugo Integration Files

This directory contains templates for integrating the comment system with Hugo static sites.

## Files

### hugo-partial.html
**For use in theme templates**

Copy to: `themes/yourtheme/layouts/partials/comments.html`

Usage in templates:
```html
{{ partial "comments.html" . }}
```

This file uses `.RelPermalink` to get the page URL and is designed to be called from theme layout files.

---

### hugo-shortcode.html
**For use in content files**

Copy to: `themes/yourtheme/layouts/shortcodes/comments.html`

Usage in markdown/content:
```markdown
{{< comments >}}
```

---

### recent-comments-shortcode.html
**For displaying recent comments site-wide**

Copy to: `themes/yourtheme/layouts/shortcodes/recent-comments.html`

Usage in markdown/content:
```markdown
{{< recent-comments >}}

<!-- With custom options: -->
{{< recent-comments limit="20" title="Latest Comments" excerpt="false" >}}
```

**Parameters:**
- `limit` - Number of comments to show (default: 10, max: 100)
- `title` - Heading text (default: "Recent Comments")
- `excerpt` - Show comment excerpts (default: true)

This creates a widget showing the most recent approved comments from across your entire site.

This file uses `.Page.RelPermalink` and supports optional parameters like custom API URLs.

---

### example.html
**Standalone example page**

A complete HTML example showing how to integrate the comment system without Hugo. Useful for:
- Testing the comment system
- Understanding the integration
- Non-Hugo sites

Can be accessed directly at: `/comments/example.html`

---

## Quick Setup

### Option 1: Use as Partial (Recommended)

1. **Copy partial to your theme:**
   ```bash
   cp hugo/hugo-partial.html \
      themes/yourtheme/layouts/partials/comments.html
   ```

2. **Add to your single post template:**
   ```html
   <!-- In themes/yourtheme/layouts/_default/single.html -->
   {{ partial "comments.html" . }}
   ```

3. **Done!** Comments will appear on all single pages.

---

### Option 2: Use as Shortcode

1. **Copy shortcode to your theme:**
   ```bash
   cp hugo/hugo-shortcode.html \
      themes/yourtheme/layouts/shortcodes/comments.html
   ```

2. **Add to specific posts:**
   ```markdown
   ---
   title: "My Post"
   ---

   Post content here...

   {{< comments >}}
   ```

3. **Optional parameters:**
   ```markdown
   {{< comments api_url="/custom/api.php" >}}
   ```

---

## Configuration

Both files expect the comment system to be available at `/comments/`:

```
yourdomain.com/
├── comments/           ← Comment system root
│   ├── api.php        ← API endpoint
│   ├── comments.js    ← Widget script
│   └── comments.css   ← Styles
└── your-post/         ← Hugo content
```

### Custom Paths

If your comment system is at a different location, update the paths in the templates:

```html
<!-- Change from: -->
<link rel="stylesheet" href="/comments/comments.css">
<script src="/comments/comments.js"></script>

<!-- To: -->
<link rel="stylesheet" href="/custom-path/comments.css">
<script src="/custom-path/comments.js"></script>
```

---

## How It Works

### 1. Container
Creates a div with the comment widget:
```html
<div id="comments-container"
     data-api-url="/comments/api.php"
     data-page-url="{{ .RelPermalink }}">
</div>
```

### 2. Loads Assets
Includes CSS and JavaScript:
```html
<link rel="stylesheet" href="/comments/comments.css">
<script src="/comments/comments.js"></script>
```

### 3. Auto-Initialization
The JavaScript automatically:
- Detects the container
- Reads the `data-page-url` attribute
- Loads comments for that page
- Renders the comment form

---

## Testing

### 1. Local Testing (Hugo Dev Server)

```bash
# Start Hugo server
hugo server

# Visit a post at:
http://localhost:1313/your-post/

# Comments should load (if api.php is accessible)
```

**Note:** For local testing, you may need to:
- Run PHP server for `/comments/` directory
- Or deploy to a server with PHP support

### 2. Production Testing

After deployment:
1. Visit a blog post
2. Check browser console for errors (F12)
3. Post a test comment
4. Verify it appears in admin panel

---

## Troubleshooting

### Comments not loading

**Check browser console (F12) for errors:**

Common issues:
- ❌ `Failed to load resource: api.php` → Check CORS in config.php
- ❌ `CORS policy error` → Add your domain to ALLOWED_ORIGINS
- ❌ `404 on comments.js` → Check file path is correct

### CORS Errors

Edit `/comments/config.php`:
```php
define('ALLOWED_ORIGINS', [
    'http://localhost:1313',        // Hugo dev server
    'https://yourdomain.com',       // Production domain
    'https://www.yourdomain.com',   // WWW variant if needed
]);
```

### Styles not loading

Verify CSS path:
```html
<!-- Check: -->
<link rel="stylesheet" href="/comments/comments.css">

<!-- Try absolute URL: -->
<link rel="stylesheet" href="https://yourdomain.com/comments/comments.css">
```

### Comments show on wrong pages

The system uses `data-page-url` to identify pages. Make sure:
- Each page has unique URL
- Same URL always generates same identifier
- No trailing slash inconsistencies

---

## Advanced Usage

### Conditional Display

Only show comments on certain sections:

```html
{{ if in (slice "post" "blog") .Section }}
  {{ partial "comments.html" . }}
{{ end }}
```

### Custom Container

Use custom HTML structure:

```html
<section class="my-comments-section">
  <h2>Comments</h2>
  {{ partial "comments.html" . }}
</section>
```

### Multiple Comment Sections

Not recommended, but possible with custom container IDs:

```html
<div id="comments-container-main"
     data-api-url="/comments/api.php"
     data-page-url="{{ .RelPermalink }}">
</div>
```

---

## Files Reference

| File | Purpose | Location | Usage |
|------|---------|----------|-------|
| hugo-partial.html | Theme template | `layouts/partials/` | `{{ partial "comments.html" . }}` |
| hugo-shortcode.html | Content shortcode | `layouts/shortcodes/` | `{{< comments >}}` |
| example.html | Standalone demo | View in browser | Direct access |

---

## Support

For more information:
- Main README: `/comments/README.md`
- Troubleshooting: `/comments/docs/TROUBLESHOOTING.md`
- Documentation: `/comments/docs/`
