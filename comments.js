/**
 * Standalone Comment System - Client Widget
 * Embeddable comment system for static sites
 */

class CommentSystem {
    constructor(options) {
        this.apiUrl = options.apiUrl || '/comments/api.php';
        this.pageUrl = options.pageUrl || window.location.pathname;
        this.containerId = options.containerId || 'comments-container';
        this.container = document.getElementById(this.containerId);

        if (!this.container) {
            console.error('Comment container not found');
            return;
        }

        this.init();
    }

    async init() {
        this.render();
        await this.loadComments();
    }

    render() {
        this.container.innerHTML = `
            <div class="comments-system">
                <h3 class="comments-title">Comments</h3>
                <div id="comment-form-container">
                    ${this.renderCommentForm()}
                </div>
                <div id="comments-list" class="comments-list">
                    <p class="loading">Loading comments...</p>
                </div>
            </div>
        `;

        this.attachFormHandler();
    }

    renderCommentForm(parentId = null, parentAuthor = null) {
        const replyText = parentAuthor ? `Reply to ${this.escapeHtml(parentAuthor)}` : 'Leave a Comment';
        const formId = parentId ? `reply-form-${parentId}` : 'main-comment-form';

        // Get saved user info from localStorage
        const savedInfo = this.getSavedUserInfo();

        return `
            <form class="comment-form" id="${formId}" data-parent-id="${parentId || ''}">
                <h4>${replyText}</h4>
                <div class="form-group">
                    <input type="text" name="author_name" placeholder="Name *" required class="form-input" value="${this.escapeHtml(savedInfo.name)}">
                </div>
                <div class="form-group">
                    <input type="email" name="author_email" placeholder="Email *" required class="form-input" value="${this.escapeHtml(savedInfo.email)}">
                </div>
                <div class="form-group">
                    <input type="url" name="author_url" placeholder="Website (optional)" class="form-input" value="${this.escapeHtml(savedInfo.url)}">
                </div>
                <div class="form-group" style="position: absolute; left: -9999px;">
                    <input type="text" name="website" placeholder="Website" class="form-input" tabindex="-1" autocomplete="off">
                </div>
                <div class="form-group">
                    <textarea name="content" placeholder="Your comment *" required class="form-textarea" rows="4"></textarea>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="subscribe" value="1" checked>
                        <span>Notify me of follow-up comments on this page</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember_me" value="1" ${savedInfo.remember ? 'checked' : ''}>
                        <span>Remember my name, email, and website for next time</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Post Comment</button>
                    ${parentId ? '<button type="button" class="btn-cancel" onclick="this.closest(\'.comment-reply-form\').remove()">Cancel</button>' : ''}
                </div>
                <div class="form-message"></div>
            </form>
        `;
    }

    attachFormHandler(form = null) {
        const forms = form ? [form] : document.querySelectorAll('.comment-form');
        forms.forEach(f => {
            f.addEventListener('submit', (e) => this.handleSubmit(e));
        });
    }

    async handleSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const messageEl = form.querySelector('.form-message');
        const submitBtn = form.querySelector('.btn-submit');

        submitBtn.disabled = true;
        messageEl.textContent = 'Posting...';
        messageEl.className = 'form-message info';

        const authorName = formData.get('author_name');
        const authorEmail = formData.get('author_email');
        const authorUrl = formData.get('author_url');
        const rememberMe = formData.get('remember_me') ? true : false;

        const data = {
            page_url: this.pageUrl,
            parent_id: form.dataset.parentId || null,
            author_name: authorName,
            author_email: authorEmail,
            author_url: authorUrl,
            content: formData.get('content'),
            subscribe: formData.get('subscribe') ? true : false
        };

        try {
            const response = await fetch(`${this.apiUrl}?action=post`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (response.ok) {
                // Save user info if remember me is checked
                this.saveUserInfo(authorName, authorEmail, authorUrl, rememberMe);

                messageEl.textContent = result.message;
                messageEl.className = 'form-message success';
                form.reset();

                // Restore saved info after reset if remember me was checked
                if (rememberMe) {
                    form.querySelector('input[name="author_name"]').value = authorName;
                    form.querySelector('input[name="author_email"]').value = authorEmail;
                    form.querySelector('input[name="author_url"]').value = authorUrl;
                    form.querySelector('input[name="remember_me"]').checked = true;
                }

                // Reload comments
                setTimeout(() => {
                    this.loadComments();
                    messageEl.textContent = '';
                }, 2000);
            } else {
                messageEl.textContent = result.error || 'Failed to post comment';
                messageEl.className = 'form-message error';
            }
        } catch (error) {
            messageEl.textContent = 'Network error. Please try again.';
            messageEl.className = 'form-message error';
        } finally {
            submitBtn.disabled = false;
        }
    }

    async loadComments() {
        try {
            const response = await fetch(`${this.apiUrl}?action=comments&url=${encodeURIComponent(this.pageUrl)}`);
            const data = await response.json();

            if (response.ok) {
                this.displayComments(data.comments);
            } else {
                document.getElementById('comments-list').innerHTML =
                    '<p class="error">Failed to load comments</p>';
            }
        } catch (error) {
            document.getElementById('comments-list').innerHTML =
                '<p class="error">Failed to load comments</p>';
        }
    }

    displayComments(comments) {
        const listEl = document.getElementById('comments-list');

        if (comments.length === 0) {
            listEl.innerHTML = '<p class="no-comments">No comments yet. Be the first to comment!</p>';
            return;
        }

        listEl.innerHTML = comments.map(comment => this.renderComment(comment)).join('');
    }

    renderComment(comment, depth = 0) {
        const date = new Date(comment.created_at);
        const formattedDate = date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        const authorLink = comment.author_url
            ? `<a href="${this.escapeHtml(comment.author_url)}" target="_blank" rel="nofollow noopener">${this.escapeHtml(comment.author_name)}</a>`
            : this.escapeHtml(comment.author_name);

        const isPending = comment.status === 'pending';
        const pendingBadge = isPending ? '<span class="badge-pending">Pending Moderation</span>' : '';

        let html = `
            <div class="comment ${isPending ? 'comment-pending' : ''}" id="comment-${comment.id}" style="margin-left: ${depth * 40}px">
                <div class="comment-meta">
                    <span class="comment-author">${authorLink}</span>
                    <span class="comment-date">${formattedDate}</span>
                    ${pendingBadge}
                </div>
                <div class="comment-content">
                    ${this.escapeHtml(comment.content).replace(/\n/g, '<br>')}
                </div>
                <div class="comment-actions">
                    <button class="btn-reply" onclick="commentsWidget.showReplyForm(${comment.id}, '${this.escapeHtml(comment.author_name).replace(/'/g, "\\'")}')">Reply</button>
                </div>
                <div id="reply-form-container-${comment.id}"></div>
            </div>
        `;

        if (comment.replies && comment.replies.length > 0) {
            html += comment.replies.map(reply => this.renderComment(reply, depth + 1)).join('');
        }

        return html;
    }

    showReplyForm(parentId, parentAuthor) {
        // Remove any existing reply forms
        document.querySelectorAll('.comment-reply-form').forEach(el => el.remove());

        const container = document.getElementById(`reply-form-container-${parentId}`);
        const formContainer = document.createElement('div');
        formContainer.className = 'comment-reply-form';
        formContainer.innerHTML = this.renderCommentForm(parentId, parentAuthor);
        container.appendChild(formContainer);

        this.attachFormHandler(formContainer.querySelector('form'));
        formContainer.querySelector('textarea').focus();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    getSavedUserInfo() {
        try {
            const saved = localStorage.getItem('comment_user_info');
            if (saved) {
                const info = JSON.parse(saved);
                return {
                    name: info.name || '',
                    email: info.email || '',
                    url: info.url || '',
                    remember: true
                };
            }
        } catch (e) {
            console.error('Error loading saved user info:', e);
        }
        return { name: '', email: '', url: '', remember: false };
    }

    saveUserInfo(name, email, url, remember) {
        try {
            if (remember) {
                localStorage.setItem('comment_user_info', JSON.stringify({
                    name: name,
                    email: email,
                    url: url
                }));
            } else {
                localStorage.removeItem('comment_user_info');
            }
        } catch (e) {
            console.error('Error saving user info:', e);
        }
    }
}

// Initialize when DOM is ready
let commentsWidget;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initComments);
} else {
    initComments();
}

function initComments() {
    // Configuration can be set via data attributes or global config
    const container = document.getElementById('comments-container');
    if (container) {
        const config = {
            apiUrl: container.dataset.apiUrl || window.COMMENTS_CONFIG?.apiUrl || '/comments/api.php',
            pageUrl: container.dataset.pageUrl || window.COMMENTS_CONFIG?.pageUrl || window.location.pathname,
            containerId: 'comments-container'
        };
        commentsWidget = new CommentSystem(config);
    }
}
