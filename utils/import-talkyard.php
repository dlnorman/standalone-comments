<?php
/**
 * TalkYard Comment Import Script
 *
 * Usage: php import-talkyard.php path/to/talkyard-export.json
 *
 * TalkYard export format is JSON. Export from your TalkYard admin panel.
 */

require_once 'config.php';
require_once 'database.php';

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

if ($argc < 2) {
    echo "Usage: php import-talkyard.php path/to/talkyard-export.json\n";
    exit(1);
}

$jsonFile = $argv[1];

if (!file_exists($jsonFile)) {
    echo "Error: File not found: $jsonFile\n";
    exit(1);
}

echo "Loading TalkYard JSON export...\n";

$jsonContent = file_get_contents($jsonFile);
$data = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error parsing JSON file: " . json_last_error_msg() . "\n";
    exit(1);
}

$db = getDatabase();
if (!$db) {
    echo "Error: Could not connect to database\n";
    exit(1);
}

// Extract data
$pagePaths = $data['pagePaths'] ?? [];
$posts = $data['posts'] ?? [];
$members = $data['members'] ?? [];
$guests = $data['guests'] ?? [];

echo "Found " . count($posts) . " posts across " . count($pagePaths) . " pages\n";

// Build page ID to URL mapping
echo "\nBuilding page URL mappings...\n";
$pageUrls = [];
foreach ($pagePaths as $path) {
    if ($path['canonical']) {
        $pageId = $path['pageId'];
        $url = $path['value'];

        // Clean up TalkYard-specific URLs
        // TalkYard uses paths like "/-4/comments-for-..." which need to be mapped back to original URLs
        if (preg_match('/comments-for-https?(.+)/', $url, $matches)) {
            // Extract the original URL from the slug
            $cleanUrl = 'http' . str_replace('-', '/', $matches[1]);
            $pageUrls[$pageId] = $cleanUrl;
        } else if (preg_match('/comments-for-(.+)/', $url, $matches)) {
            // Slug-based URL, use as-is or try to reconstruct
            $pageUrls[$pageId] = '/' . $matches[1];
        } else {
            $pageUrls[$pageId] = $url;
        }

        echo "  Page {$pageId}: {$pageUrls[$pageId]}\n";
    }
}

// Build user ID to user info mapping
echo "\nBuilding user mappings...\n";
$users = [];

// Add members
foreach ($members as $member) {
    $userId = $member['id'] ?? null;
    if ($userId) {
        $users[$userId] = [
            'name' => $member['fullName'] ?? $member['username'] ?? 'Anonymous',
            'email' => $member['primaryEmailAddress'] ?? 'noreply@example.com',
            'url' => $member['websiteUrl'] ?? null
        ];
    }
}

// Add guests (negative IDs in TalkYard)
foreach ($guests as $guest) {
    $userId = $guest['id'] ?? null;
    if ($userId) {
        $users[$userId] = [
            'name' => $guest['fullName'] ?? $guest['guestName'] ?? 'Anonymous',
            'email' => $guest['emailAddress'] ?? 'guest@example.com',
            'url' => $guest['websiteUrl'] ?? null
        ];
    }
}

echo "Found " . count($users) . " users\n";

// Filter and process posts
echo "\nProcessing posts...\n";
$commentsToImport = [];
$postIdMap = []; // Maps TalkYard post IDs to our IDs

foreach ($posts as $post) {
    $postId = $post['id'] ?? null;
    $pageId = $post['pageId'] ?? null;
    $parentNr = $post['parentNr'] ?? null;
    $postNr = $post['nr'] ?? null;
    $postType = $post['postType'] ?? 1;

    // Skip title posts (nr = 0) and body posts (nr = 1) - these are page metadata
    if ($postNr === 0 || $postNr === 1) {
        continue;
    }

    // Skip deleted posts
    if (($post['deletedStatus'] ?? 0) > 0) {
        echo "  Skipping deleted post ID {$postId}\n";
        continue;
    }

    // Get page URL
    $pageUrl = $pageUrls[$pageId] ?? null;
    if (!$pageUrl) {
        echo "  Warning: Could not find URL for page {$pageId}, post {$postId}\n";
        continue;
    }

    // Get author info
    $authorId = $post['createdById'] ?? 1;
    $author = $users[$authorId] ?? [
        'name' => 'Anonymous',
        'email' => 'anonymous@example.com',
        'url' => null
    ];

    // Get content
    $content = $post['approvedSource'] ?? '';
    if (empty($content)) {
        echo "  Skipping empty post ID {$postId}\n";
        continue;
    }

    // Strip HTML tags from content
    $content = strip_tags($content);
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Get timestamp
    $createdAt = $post['createdAt'] ?? null;
    if ($createdAt) {
        // TalkYard uses milliseconds since epoch
        $createdAt = date('Y-m-d H:i:s', $createdAt / 1000);
    } else {
        $createdAt = date('Y-m-d H:i:s');
    }

    // Determine parent post
    // In TalkYard, parentNr refers to the post number (nr) within the page
    // We need to map this to our parent_id
    $parentPostId = null;
    if ($parentNr !== null && $parentNr > 1) {
        // Find the TalkYard post with this nr on the same page
        foreach ($posts as $potentialParent) {
            if ($potentialParent['pageId'] === $pageId &&
                $potentialParent['nr'] === $parentNr) {
                $parentPostId = $potentialParent['id'];
                break;
            }
        }
    }

    $commentsToImport[] = [
        'talkyard_id' => $postId,
        'talkyard_parent_id' => $parentPostId,
        'page_url' => $pageUrl,
        'author_name' => $author['name'],
        'author_email' => $author['email'],
        'author_url' => $author['url'],
        'content' => $content,
        'created_at' => $createdAt,
        'status' => 'approved' // Import as approved
    ];
}

echo "\nPrepared " . count($commentsToImport) . " comments to import\n";

// Sort by creation date to maintain proper threading
usort($commentsToImport, function($a, $b) {
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

    foreach ($commentsToImport as $comment) {
        // Resolve parent ID
        $parentId = null;
        if ($comment['talkyard_parent_id']) {
            $parentId = $postIdMap[$comment['talkyard_parent_id']] ?? null;
            if ($parentId === null) {
                echo "  Warning: Could not find parent comment for TalkYard ID {$comment['talkyard_id']}\n";
            }
        }

        $stmt->execute([
            $comment['page_url'],
            $parentId,
            $comment['author_name'],
            $comment['author_email'],
            $comment['author_url'],
            $comment['content'],
            $comment['created_at'],
            $comment['status']
        ]);

        // Map TalkYard ID to our new ID
        $postIdMap[$comment['talkyard_id']] = $db->lastInsertId();

        $imported++;

        if ($imported % 50 == 0) {
            echo "  Imported $imported comments...\n";
        }
    }

    $db->commit();
    echo "\n✓ Success! Imported $imported comments\n";

} catch (PDOException $e) {
    $db->rollBack();
    echo "\nError importing comments: " . $e->getMessage() . "\n";
    exit(1);
}

// Display statistics
echo "\nImport Statistics:\n";
echo "  Total comments imported: $imported\n";
echo "  Unique pages: " . count(array_unique(array_column($commentsToImport, 'page_url'))) . "\n";

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

echo "\n✓ Import completed successfully!\n";
echo "\nNote: TalkYard URLs like 'comments-for-https...' have been converted back to original URLs.\n";
echo "If any URLs look incorrect, you may need to manually update them in the database.\n";
