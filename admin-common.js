/**
 * Common admin functions for CSRF token handling
 */

let csrfToken = null;

// Get CSRF token from cookie
function getCSRFToken() {
    const name = 'csrf_token=';
    const decodedCookie = decodeURIComponent(document.cookie);
    const ca = decodedCookie.split(';');
    for(let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) == ' ') {
            c = c.substring(1);
        }
        if (c.indexOf(name) == 0) {
            return c.substring(name.length, c.length);
        }
    }
    return null;
}

// Fetch CSRF token if not in cookie
async function ensureCSRFToken() {
    if (!csrfToken) {
        csrfToken = getCSRFToken();
    }
    if (!csrfToken) {
        try {
            const response = await fetch('api.php?action=csrf_token', {
                credentials: 'include'
            });
            const data = await response.json();
            csrfToken = data.token;
        } catch (error) {
            console.error('Failed to fetch CSRF token:', error);
        }
    }
    return csrfToken;
}

// Store CSRF token (call after login)
function setCSRFToken(token) {
    csrfToken = token;
}

// HTML escaping function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
