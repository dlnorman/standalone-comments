<?php
/**
 * Disqus Comment Import Script
 *
 * Usage: php import-disqus.php path/to/disqus-export.xml
 *
 * Disqus export format is XML. You can export from:
 * https://[your-site].disqus.com/admin/discussions/export/
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

echo "Loading Disqus XML export...\n";

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

// Parse XML namespaces
$namespaces = $xml->getNamespaces(true);
$dsq = $namespaces['dsq'] ?? 'http://disqus.com';

// Extract threads (posts/pages)
echo "\nExtracting threads...\n";
$threads = [];
foreach ($xml->thread as $thread) {
    $dsqId = (string)$thread->children($dsq)->id;
    $link = (string)$thread->link;
    $threads[$dsqId] = $link;
    echo "  Thread: $link\n";
}

// Extract posts (comments)
echo "\nExtracting comments...\n";
$posts = [];
$postIdMap = []; // Maps Disqus IDs to our IDs

foreach ($xml->post as $post) {
    $dsqId = (string)$post->children($dsq)->id;
    $threadId = (string)$post->thread['dsq:id'];
    $parentId = (string)$post->parent['dsq:id'];

    $author = $post->author;
    $authorName = (string)$author->name;
    $authorEmail = (string)$author->email;
    $authorUrl = (string)$author->link;

    $createdAt = (string)$post->createdAt;
    $message = (string)$post->message;
    $isDeleted = ((string)$post->isDeleted) === 'true';
    $isSpam = ((string)$post->isSpam) === 'true';

    // Skip deleted or spam comments if desired
    if ($isDeleted || $isSpam) {
        echo "  Skipping deleted/spam comment: $dsqId\n";
        continue;
    }

    // Get thread URL
    $pageUrl = $threads[$threadId] ?? null;
    if (!$pageUrl) {
        echo "  Warning: Could not find thread for comment $dsqId\n";
        continue;
    }

    // Clean up the message (remove HTML tags)
    $message = strip_tags($message);
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Default values if not provided
    if (empty($authorName)) $authorName = 'Anonymous';
    if (empty($authorEmail)) $authorEmail = 'anonymous@example.com';

    $posts[] = [
        'disqus_id' => $dsqId,
        'disqus_parent_id' => $parentId ?: null,
        'page_url' => $pageUrl,
        'author_name' => $authorName,
        'author_email' => $authorEmail,
        'author_url' => $authorUrl ?: null,
        'content' => $message,
        'created_at' => date('Y-m-d H:i:s', strtotime($createdAt)),
        'status' => 'approved' // Import as approved
    ];
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
        if ($post['disqus_parent_id']) {
            $parentId = $postIdMap[$post['disqus_parent_id']] ?? null;
            if ($parentId === null) {
                echo "  Warning: Could not find parent comment for Disqus ID {$post['disqus_id']}\n";
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

        // Map Disqus ID to our new ID
        $postIdMap[$post['disqus_id']] = $db->lastInsertId();

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
