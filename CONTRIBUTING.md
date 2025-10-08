# Contributing to Standalone Comment System

Thank you for your interest in contributing! This document provides guidelines for contributing to this project.

## How to Contribute

### Reporting Bugs

If you find a bug:

1. **Check existing issues** - It may already be reported
2. **Create a detailed report** including:
   - PHP version
   - Web server (Apache/Nginx/LiteSpeed)
   - Steps to reproduce
   - Expected vs actual behavior
   - Error messages from logs
   - Browser console errors (if applicable)

### Suggesting Features

Feature suggestions are welcome! Please:

1. Check if it's already been suggested
2. Explain the use case
3. Describe how it would work
4. Consider backwards compatibility

### Pull Requests

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/amazing-feature`)
3. **Make your changes**
4. **Test thoroughly** - See Testing section below
5. **Commit with clear messages**
6. **Push to your fork**
7. **Open a Pull Request**

#### PR Guidelines

- Keep changes focused (one feature/fix per PR)
- Follow existing code style
- Add/update documentation
- Test on both Apache and Nginx if possible
- Update CHANGELOG.md with your changes

## Code Style

### PHP

- Use 4 spaces for indentation
- Follow PSR-12 style guide where practical
- Use meaningful variable names
- Comment complex logic
- Always use prepared statements for SQL

**Example:**
```php
<?php
// Good
function getCommentsByPage($db, $pageUrl) {
    $stmt = $db->prepare("
        SELECT id, author_name, content, created_at
        FROM comments
        WHERE page_url = ? AND status = 'approved'
        ORDER BY created_at ASC
    ");
    $stmt->execute([$pageUrl]);
    return $stmt->fetchAll();
}

// Bad
function get_comments($p) {
    return $db->query("SELECT * FROM comments WHERE page_url='$p'");  // SQL injection!
}
```

### JavaScript

- Use ES6+ features
- Use `const` and `let`, not `var`
- Use template literals for strings
- Add comments for complex logic
- Handle errors gracefully

**Example:**
```javascript
// Good
async function loadComments(pageUrl) {
    try {
        const response = await fetch(`${API_URL}?action=comments&url=${encodeURIComponent(pageUrl)}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return await response.json();
    } catch (error) {
        console.error('Failed to load comments:', error);
        throw error;
    }
}
```

### CSS

- Use semantic class names
- Mobile-first responsive design
- Include fallbacks for older browsers
- Comment major sections

## Testing

### Manual Testing Checklist

Before submitting a PR, test:

- [ ] Comments display correctly
- [ ] Can post new comment
- [ ] Reply threading works
- [ ] Admin login works
- [ ] Can approve/delete comments
- [ ] Email subscriptions work (if changed)
- [ ] Rate limiting functions
- [ ] Spam detection works
- [ ] Security: Run `utils/test-htaccess.sh`
- [ ] Test on mobile browser
- [ ] Test with JavaScript disabled

### Test Environments

Test on at least:
- PHP 7.4 and 8.0+
- Apache or Nginx
- Different browsers (Chrome, Firefox, Safari)

### Security Testing

Security is critical. Always:

1. **SQL Injection** - Try SQL in all input fields
2. **XSS** - Try `<script>alert('xss')</script>` in comments
3. **CSRF** - Test API without proper origin
4. **File Access** - Try accessing `/db/comments.db` directly
5. **Email Injection** - Try newlines in email fields

## Development Setup

### Local Environment

```bash
# Clone repository
git clone https://github.com/yourusername/standalone-comments.git
cd standalone-comments

# Create dev marker for local database
touch dev.marker

# Start PHP built-in server
php -S localhost:8000

# Visit http://localhost:8000
```

### Database

Development uses `db/comments-dev.db` automatically when:
- Running on localhost
- `dev.marker` file exists
- Using PHP built-in server

Production uses `db/comments.db` on real servers.

## Documentation

When adding features:

1. Update relevant docs in `docs/`
2. Update `README.md` if user-facing
3. Update `CHANGELOG.md`
4. Add inline code comments
5. Update `INSTALL.md` if setup changes

## Commit Messages

Use clear, descriptive commit messages:

**Good:**
```
Add email validation to subscription form

- Check email format before saving
- Show user-friendly error message
- Add unit test for validation
```

**Bad:**
```
fix bug
```

## Release Process

Maintainers will:

1. Update version in README.md
2. Update CHANGELOG.md with release date
3. Create git tag (`v2.1.0`)
4. Create GitHub release with notes
5. Update demo site

## Code of Conduct

Be respectful and constructive:

- Welcome newcomers
- Provide helpful feedback
- Focus on the code, not the person
- Assume good intentions
- Be patient with questions

## Questions?

- Open a GitHub Discussion for general questions
- Open an Issue for bugs/features
- Email maintainer for security issues

## License

By contributing, you agree your contributions will be licensed under the MIT License.

---

Thank you for contributing to make commenting better for the self-hosted web!
