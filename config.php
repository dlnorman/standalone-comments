<?php
// Comment System Configuration

// Database path - use comments-dev.db for local development, comments.db in production
// Defaults to PRODUCTION for safety - only switches to dev if confident
$isLocalhost = false;

// Method 1: Check for environment variable (most explicit)
if (getenv('COMMENT_ENV') === 'development') {
    $isLocalhost = true;
}
// Method 2: Check HTTP_HOST for localhost/dev domains
elseif (isset($_SERVER['HTTP_HOST'])) {
    $host = $_SERVER['HTTP_HOST'];
    $isLocalhost = (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        strpos($host, '.local') !== false ||
        strpos($host, ':1313') !== false  // Hugo dev server port
    );
}
// Method 3: Detect PHP built-in server (php -S)
elseif (php_sapi_name() === 'cli-server') {
    $isLocalhost = true;
}
// Method 4: CLI without HTTP_HOST assumes development
elseif (php_sapi_name() === 'cli' && !isset($_SERVER['HTTP_HOST'])) {
    $isLocalhost = true;
}

// Method 5: Check for dev-specific file marker (create 'dev.marker' locally, don't commit)
if (!$isLocalhost && file_exists(__DIR__ . '/dev.marker')) {
    $isLocalhost = true;
}

define('DB_PATH', __DIR__ . ($isLocalhost ? '/db/comments-dev.db' : '/db/comments.db'));
define('ADMIN_TOKEN_COOKIE', 'comment_admin_token');
define('SESSION_LIFETIME', 3600 * 24 * 30); // 30 days

// CORS settings - configure based on your domain
define('ALLOWED_ORIGINS', [
    'http://localhost:1313', // Hugo dev server
    'https://darcynorman.net', // Production domain
]);

// Timezone - IMPORTANT: Set to your local timezone
date_default_timezone_set('America/Edmonton');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
