<?php
/**
 * Disqus Comment Import Script
 *
 * Usage: php import-disqus.php path/to/export.xml
 *
 * Supports two formats (auto-detected by root element):
 *   - Native Disqus XML: export from https://[your-site].disqus.com/admin/discussions/export/
 *   - WordPress WXR format: RSS-based export with <wp:comment> elements (used by some migration tools)
 */

require_once 'config.php';
require_once 'database.php';

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

if ($argc < 2) {
    echo "Usage: php import-disqus.php path/to/disqus-export.xml\n";
    exit(1);
}

$xmlFile = $argv[1];

if (!file_exists($xmlFile)) {
    echo "Error: File not found: $xmlFile\n";
    exit(1);
}

echo "Loading XML export...\n";

// Load XML
libxml_use_internal_errors(true);
$xml = simplexml_load_file($xmlFile);

if ($xml === false) {
    echo "Error parsing XML file:\n";
    foreach (libxml_get_errors() as $error) {
        echo "  - " . $error->message . "\n";
    }
    exit(1);
}

$db = getDatabase();
if (!$db) {
    echo "Error: Could not connect to database\n";
    exit(1);
}

$posts = [];
$postIdMap = []; // Maps source IDs to our IDs (populated during import)

$rootName = $xml->getName();

if ($rootName === 'rss') {
    // WordPress WXR format (also used by some Disqus-export tools)
    echo "Detected WordPress WXR format\n";

    $wpNs = 'http://wordpress.org/export/1.0/';

    echo "\nExtracting comments...\n";

    // WXR has one <item> per page, with <wp:comment> children
    foreach ($xml->channel->item as $item) {
        $link = (string)$item->link;
        if (empty($link)) continue;

        // Normalize to path-only
        $parsed = parse_url($link);
        $pageUrl = $parsed['path'] ?? $link;
        if (isset($parsed['query'])) $pageUrl .= '?' . $parsed['query'];
        if (isset($parsed['fragment'])) $pageUrl .= '#' . $parsed['fragment'];

        $wpChildren = $item->children($wpNs);
        if (!isset($wpChildren->comment)) continue;

        foreach ($wpChildren->comment as $comment) {
            $wp = $comment->children($wpNs);

            $wpId       = (string)$wp->comment_id;
            $approved   = (string)$wp->comment_approved;
            $parentWpId = (string)$wp->comment_parent;

            // Skip unapproved/spam
            if ($approved !== '1') {
                echo "  Skipping unapproved comment: $wpId\n";
                continue;
            }

            $authorName  = (string)$wp->comment_author;
            $authorEmail = (string)$wp->comment_author_email;
            $authorUrl   = (string)$wp->comment_author_url;
            $createdAt   = (string)$wp->comment_date_gmt;
            $message     = (string)$wp->comment_content;

            // Clean up message
            $message = strip_tags($message);
            $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (empty($authorName))  $authorName  = 'Anonymous';
            if (empty($authorEmail)) $authorEmail = 'anonymous@example.com';

            $posts[] = [
                'source_id'        => $wpId,
                'source_parent_id' => ($parentWpId && $parentWpId !== '0') ? $parentWpId : null,
                'page_url'         => $pageUrl,
                'author_name'      => $authorName,
                'author_email'     => $authorEmail,
                'author_url'       => $authorUrl ?: null,
                'content'          => $message,
                'created_at'       => date('Y-m-d H:i:s', strtotime($createdAt)),
                'status'           => 'approved',
            ];
        }
    }

} else {
    // Native Disqus XML format
    echo "Detected native Disqus format\n";

    // Parse XML namespaces; dsq:id is an attribute in the disqus-internals namespace
    $namespaces = $xml->getNamespaces(true);
    $dsq = $namespaces['dsq'] ?? 'http://disqus.com/disqus-internals';

    // Extract threads (posts/pages)
    echo "\nExtracting threads...\n";
    $threads = [];
    foreach ($xml->thread as $thread) {
        $dsqId = (string)$thread->attributes($dsq)->id;
        $link  = (string)$thread->link;
        if ($dsqId && $link) {
            $parsed = parse_url($link);
            $path   = $parsed['path'] ?? $link;
            if (isset($parsed['query']))    $path .= '?' . $parsed['query'];
            if (isset($parsed['fragment'])) $path .= '#' . $parsed['fragment'];
            $threads[$dsqId] = $path;
            echo "  Thread: $path\n";
        }
    }

    echo "\nExtracting comments...\n";

    foreach ($xml->post as $post) {
        $dsqId    = (string)$post->attributes($dsq)->id;
        $threadId = (string)$post->thread->attributes($dsq)->id;
        $parentId = (string)$post->parent->attributes($dsq)->id;

        $author      = $post->author;
        $authorName  = (string)$author->name;
        $authorEmail = (string)$author->email;
        $authorUrl   = (string)$author->link;

        $createdAt = (string)$post->createdAt;
        $message   = (string)$post->message;
        $isDeleted = ((string)$post->isDeleted) === 'true';
        $isSpam    = ((string)$post->isSpam) === 'true';

        if ($isDeleted || $isSpam) {
            echo "  Skipping deleted/spam comment: $dsqId\n";
            continue;
        }

        $pageUrl = $threads[$threadId] ?? null;
        if (!$pageUrl) {
            echo "  Warning: Could not find thread for comment $dsqId\n";
            continue;
        }

        $message = strip_tags($message);
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (empty($authorName))  $authorName  = 'Anonymous';
        if (empty($authorEmail)) $authorEmail = 'anonymous@example.com';

        $posts[] = [
            'source_id'        => $dsqId,
            'source_parent_id' => $parentId ?: null,
            'page_url'         => $pageUrl,
            'author_name'      => $authorName,
            'author_email'     => $authorEmail,
            'author_url'       => $authorUrl ?: null,
            'content'          => $message,
            'created_at'       => date('Y-m-d H:i:s', strtotime($createdAt)),
            'status'           => 'approved',
        ];
    }
}

echo "\nFound " . count($posts) . " comments to import\n";

// Sort posts by creation date to maintain proper threading
usort($posts, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

// Import comments
echo "\nImporting comments...\n";
$imported = 0;
$errors = 0;

$db->beginTransaction();

try {
    $stmt = $db->prepare("
        INSERT INTO comments (page_url, parent_id, author_name, author_email, author_url,
                             content, created_at, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($posts as $post) {
        // Resolve parent ID
        $parentId = null;
        if ($post['source_parent_id']) {
            $parentId = $postIdMap[$post['source_parent_id']] ?? null;
            if ($parentId === null) {
                echo "  Warning: Could not find parent comment for ID {$post['source_id']}\n";
            }
        }

        $stmt->execute([
            $post['page_url'],
            $parentId,
            $post['author_name'],
            $post['author_email'],
            $post['author_url'],
            $post['content'],
            $post['created_at'],
            $post['status']
        ]);

        // Map source ID to our new ID
        $postIdMap[$post['source_id']] = $db->lastInsertId();

        $imported++;

        if ($imported % 100 == 0) {
            echo "  Imported $imported comments...\n";
        }
    }

    $db->commit();
    echo "\nSuccess! Imported $imported comments\n";

} catch (PDOException $e) {
    $db->rollBack();
    echo "\nError importing comments: " . $e->getMessage() . "\n";
    exit(1);
}

// Display statistics
echo "\nImport Statistics:\n";
echo "  Total comments imported: $imported\n";
echo "  Unique pages: " . count(array_unique(array_column($posts, 'page_url'))) . "\n";

// Show sample of imported URLs
$stmt = $db->query("
    SELECT page_url, COUNT(*) as count
    FROM comments
    GROUP BY page_url
    ORDER BY count DESC
    LIMIT 10
");

echo "\nTop 10 pages by comment count:\n";
while ($row = $stmt->fetch()) {
    echo "  {$row['page_url']}: {$row['count']} comments\n";
}

echo "\nImport completed successfully!\n";
