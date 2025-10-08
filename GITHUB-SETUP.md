# GitHub Repository Setup Guide

Instructions for creating a GitHub repository for the Standalone Comment System.

## Repository Creation

### 1. Create Repository on GitHub

1. Go to https://github.com/new
2. Repository name: `standalone-comments` (or your preferred name)
3. Description: "A lightweight, self-hosted commenting system for static sites. No external dependencies, no tracking."
4. Make it **Public** (for open source) or **Private**
5. **Do NOT** initialize with README (we already have one)
6. **Do NOT** add .gitignore (we already have one)
7. Click "Create repository"

### 2. Initialize Git Repository Locally

```bash
cd ~/temp/standalone-comments

# Initialize git
git init

# Add all files
git add .

# First commit
git commit -m "Initial commit: Standalone Comment System v2.0

Features:
- Self-hosted commenting with SQLite
- Threaded replies and email subscriptions
- Admin panel with moderation
- Spam detection and rate limiting
- Recent comments widget
- Hugo integration (partials & shortcodes)
- Import tools (Disqus, TalkYard)
- Complete documentation"

# Add remote (replace with your repository URL)
git remote add origin https://github.com/yourusername/standalone-comments.git

# Push to GitHub
git branch -M main
git push -u origin main
```

### 3. Repository Topics (Tags)

Add these topics to your repository for discoverability:

- `comment-system`
- `static-site`
- `hugo`
- `self-hosted`
- `php`
- `sqlite`
- `privacy`
- `no-tracking`
- `blog-comments`
- `jekyll`
- `eleventy`

### 4. Create Release

Create your first release:

1. Go to repository → Releases → "Create a new release"
2. Tag version: `v2.0.0`
3. Release title: `v2.0.0 - Initial Release`
4. Description:

```markdown
# Standalone Comment System v2.0.0

First public release of a lightweight, self-hosted commenting system for static sites.

## 🎉 Features

✓ Self-hosted with SQLite (no external database)
✓ Threaded comment replies
✓ Email subscriptions with notifications
✓ Comment moderation and spam detection
✓ Recent comments widget
✓ Hugo integration (ready-to-use templates)
✓ Import from Disqus and TalkYard
✓ Security hardened with rate limiting
✓ Privacy-respecting (no tracking)

## 📦 Installation

1. Download and extract to your server
2. Edit `config.php` with your domain
3. Set admin password via `utils/set-password.php`
4. Integrate using Hugo shortcode or JavaScript

See [INSTALL.md](INSTALL.md) for complete guide.

## 📚 Documentation

- [README.md](README.md) - Overview and quick start
- [INSTALL.md](INSTALL.md) - Installation guide
- [docs/](docs/) - Complete documentation
- [hugo/](hugo/) - Hugo integration

## 🔒 Security

- SQL injection protection
- XSS prevention
- CSRF protection
- Rate limiting
- Spam detection
- Database protection

See [docs/SECURITY-AUDIT.md](docs/SECURITY-AUDIT.md) for details.

## 📋 Requirements

- PHP 7.4+
- SQLite (included in PHP)
- Apache or Nginx

## 📄 License

MIT License - See [LICENSE](LICENSE)
```

5. Click "Publish release"

## Repository Configuration

### Enable Issues

Settings → Features → Check "Issues"

Use issue templates:

**.github/ISSUE_TEMPLATE/bug_report.md:**
```yaml
name: Bug Report
about: Report a bug or issue
title: '[BUG] '
labels: bug
assignees: ''
```

**.github/ISSUE_TEMPLATE/feature_request.md:**
```yaml
name: Feature Request
about: Suggest a new feature
title: '[FEATURE] '
labels: enhancement
assignees: ''
```

### Enable Discussions (Optional)

Settings → Features → Check "Discussions"

Categories:
- General
- Help & Support
- Ideas & Feature Requests
- Show and Tell

### Security Policy

Create `.github/SECURITY.md`:

```markdown
# Security Policy

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please email security issues privately to: [your-email@example.com]

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

You will receive a response within 48 hours.

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 2.0.x   | :white_check_mark: |
| < 2.0   | :x:                |

## Security Features

- SQL injection protection (prepared statements)
- XSS prevention (output escaping)
- CSRF protection (CORS whitelist)
- Rate limiting (IP and email based)
- Spam detection
- Secure password hashing (bcrypt)
- Database file protection
- Email header injection prevention

See [docs/SECURITY-AUDIT.md](docs/SECURITY-AUDIT.md) for complete security analysis.
```

## README Badges (Optional)

Add to top of README.md:

```markdown
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Version](https://img.shields.io/badge/version-2.0.0-orange)
[![GitHub stars](https://img.shields.io/github/stars/yourusername/standalone-comments?style=social)](https://github.com/yourusername/standalone-comments)
```

## Documentation Website (GitHub Pages)

Optional: Create a documentation site:

1. Create `docs-site` branch
2. Add static site (Jekyll/Hugo)
3. Settings → Pages → Source: `docs-site` branch
4. Site will be at: `https://yourusername.github.io/standalone-comments/`

## Continuous Integration (Optional)

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    strategy:
      matrix:
        php-version: ['7.4', '8.0', '8.1', '8.2']
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php-version }}
        extensions: pdo_sqlite
    
    - name: Check PHP syntax
      run: find . -name "*.php" -exec php -l {} \;
    
    - name: Test database initialization
      run: |
        cd utils
        php setup.php
```

## Repository Structure Best Practices

```
standalone-comments/
├── .github/
│   ├── ISSUE_TEMPLATE/
│   │   ├── bug_report.md
│   │   └── feature_request.md
│   ├── workflows/
│   │   └── tests.yml
│   └── SECURITY.md
├── docs/
├── hugo/
├── utils/
├── db/
├── README.md
├── INSTALL.md
├── LICENSE
├── CONTRIBUTING.md
├── CHANGELOG.md
└── ... (other files)
```

## Promotion

Once repository is live:

1. **Reddit:** Post to r/selfhosted, r/hugo, r/opensource
2. **Hacker News:** Submit to Show HN
3. **Product Hunt:** Submit as new product
4. **Twitter/X:** Tweet about it with hashtags #selfhosted #opensource
5. **Dev.to:** Write article about building it
6. **Hugo Forum:** Announce in Hugo community

## Maintenance

Regular tasks:

- Respond to issues within 48 hours
- Review pull requests within 1 week
- Update dependencies regularly
- Release security patches immediately
- Tag versions following semver

## Support Channels

Decide on support channels:

- **Issues:** Bug reports and features
- **Discussions:** Questions and help
- **Discord/Slack:** Real-time chat (optional)
- **Email:** Security issues only

## License

MIT License is recommended for maximum adoption. Already included in package.

---

Ready to push to GitHub! 🚀
