<?php
/**
 * fetch_article.php — web script
 * Accepts POST param 'url', fetches the X.com article via
 * Jina Reader, saves myarticle.html to the working directory,
 * then redirects the browser to the public URL.
 */

$BASE_URL   = 'https://labsolns.com/twittart';
$dirName    = bin2hex(random_bytes(4)); // 8 random hex chars
$OUT_DIR    = __DIR__ . '/' . $dirName;
$OUT_FILE   = $OUT_DIR . '/myarticle.html';
$PUBLIC_URL = $BASE_URL . '/' . $dirName . '/myarticle.html';

if (!mkdir($OUT_DIR, 0755)) {
    die(errorPage('Failed to create output directory.'));
}

// ── Input validation ──────────────────────────────────────────
$url = trim($_POST['url'] ?? '');

if (empty($url)) {
    die(errorPage('No URL provided.'));
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    die(errorPage('Invalid URL.'));
}
if (!preg_match('#^https?://(x\.com|twitter\.com)/.+/article/#i', $url)) {
    die(errorPage('URL must be an x.com or twitter.com article link.'));
}

// ── Fetch via Jina Reader ─────────────────────────────────────
$jinaUrl = 'https://r.jina.ai/' . $url;

$ch = curl_init($jinaUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'X-With-Images-Summary: all',
    ],
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    die(errorPage("Jina Reader request failed (HTTP $httpCode)."));
}

$data = json_decode($response, true);
if (!$data || ($data['code'] ?? 0) !== 200) {
    die(errorPage('Could not parse Jina Reader response.'));
}

$rawTitle = trim($data['data']['title']   ?? '');
$markdown = trim($data['data']['content'] ?? '');

// ── Clean up title ────────────────────────────────────────────
$rawTitle = preg_replace('/[\|\/]\s*X\s*$/', '', $rawTitle);
$rawTitle = trim($rawTitle);

// Fallback: first non-empty content line is usually the article title
if (empty($rawTitle) || $rawTitle === 'X') {
    foreach (explode("\n", $markdown) as $line) {
        $line = trim(preg_replace('/^#+\s*/', '', $line));
        if (strlen($line) > 5) { $rawTitle = $line; break; }
    }
    $rawTitle = $rawTitle ?: 'Untitled Article';
}

if (empty($markdown)) {
    die(errorPage('Article body is empty. The page may require login.'));
}

// ── Download article images ───────────────────────────────────
// Only download actual article media (pbs.twimg.com/media/),
// skipping profile pics and emoji.

function isArticleImage(string $url): bool
{
    return (bool) preg_match('#pbs\.twimg\.com/media/#i', $url);
}

// Upgrade a small/thumb URL to 900x900 by replacing name= param
function upgradeImageUrl(string $url): string
{
    return preg_replace('/([?&]name=)[^&]+/', '$1900x900', $url);
}

function downloadImage(string $url, string $destDir): ?string
{
    $parsed   = parse_url($url);
    $base     = basename($parsed['path'] ?? 'image');
    $ext      = '';
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $q);
        if (!empty($q['format'])) $ext = '.' . $q['format'];
    }
    if ($ext === '' && !preg_match('/\.\w{2,4}$/', $base)) $ext = '.jpg';
    $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base) . $ext;
    $dest     = $destDir . '/' . $filename;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $data = curl_exec($ch);
    $ok   = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    if ($ok && $data && file_put_contents($dest, $data) !== false) {
        return $filename;
    }
    return null;
}

// Build map: original_inline_url => local_filename
// Also collect alt texts so we can rewrite linked-image syntax
$imageMap = []; // inline_url => local_filename

// 1. Collect all inline image URLs from markdown
//    Handles both  ![alt](url)  and  [![alt](url)](link)
preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $markdown, $inlineImgs, PREG_SET_ORDER);

foreach ($inlineImgs as $m) {
    $inlineUrl = $m[2];
    if (!isArticleImage($inlineUrl) || isset($imageMap[$inlineUrl])) continue;
    // Try to download a higher-res version first
    $hiResUrl = upgradeImageUrl($inlineUrl);
    $local    = downloadImage($hiResUrl, $OUT_DIR)
             ?? downloadImage($inlineUrl, $OUT_DIR);
    if ($local) $imageMap[$inlineUrl] = $local;
}

// 2. Also grab any images key entries not already captured inline
foreach (($data['data']['images'] ?? []) as $label => $imgUrl) {
    if (!isArticleImage($imgUrl) || isset($imageMap[$imgUrl])) continue;
    $local = downloadImage($imgUrl, $OUT_DIR);
    if ($local) $imageMap[$imgUrl] = $local;
}

// Rewrite markdown:
// [![alt](img_url)](link_url)  →  ![alt](local_file)   (drop outer link)
// ![alt](img_url)              →  ![alt](local_file)
$markdown = preg_replace_callback(
    '/\[!\[([^\]]*)\]\(([^)]+)\)\]\([^)]+\)/',   // linked image
    function ($m) use ($imageMap) {
        $alt   = $m[1];
        $url   = $m[2];
        $local = $imageMap[$url] ?? $url;
        return '![' . $alt . '](' . $local . ')';
    },
    $markdown
);
foreach ($imageMap as $origUrl => $localFile) {
    // plain inline images not already rewritten above
    $markdown = str_replace("({$origUrl})", "({$localFile})", $markdown);
}



function markdownToHtml(string $md): string
{
    // Remove the leading # title line (shown separately in <h1>)
    $md   = preg_replace('/^#[^\n]*\n/', '', $md);
    $lines = explode("\n", trim($md));
    $out   = [];
    $inUl  = $inOl = $inBq = false;

    $closeAll = function () use (&$out, &$inUl, &$inOl, &$inBq) {
        if ($inUl) { $out[] = '</ul>'; $inUl = false; }
        if ($inOl) { $out[] = '</ol>'; $inOl = false; }
        if ($inBq) { $out[] = '</blockquote>'; $inBq = false; }
    };

    foreach ($lines as $line) {
        $line = rtrim($line);

        // Heading
        if (preg_match('/^(#{1,3})\s+(.+)/', $line, $m)) {
            $closeAll();
            $lvl   = strlen($m[1]) + 1; // h1 reserved for article title
            $out[] = "<h{$lvl}>" . inline($m[2]) . "</h{$lvl}>";
            continue;
        }
        // Blockquote
        if (str_starts_with($line, '> ')) {
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
            if ($inOl) { $out[] = '</ol>'; $inOl = false; }
            if (!$inBq) { $out[] = '<blockquote>'; $inBq = true; }
            $out[] = '<p>' . inline(substr($line, 2)) . '</p>';
            continue;
        }
        if ($inBq && $line !== '') {
            $out[] = '<p>' . inline($line) . '</p>';
            continue;
        }
        // Unordered list
        if (preg_match('/^[*\-]\s+(.*)/', $line, $m)) {
            if ($inBq) { $out[] = '</blockquote>'; $inBq = false; }
            if ($inOl) { $out[] = '</ol>'; $inOl = false; }
            if (!$inUl) { $out[] = '<ul>'; $inUl = true; }
            $out[] = '<li>' . inline($m[1]) . '</li>';
            continue;
        }
        // Ordered list
        if (preg_match('/^\d+\.\s+(.*)/', $line, $m)) {
            if ($inBq) { $out[] = '</blockquote>'; $inBq = false; }
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
            if (!$inOl) { $out[] = '<ol>'; $inOl = true; }
            $out[] = '<li>' . inline($m[1]) . '</li>';
            continue;
        }
        // HR
        if (preg_match('/^---+$/', $line)) {
            $closeAll(); $out[] = '<hr>'; continue;
        }
        // Blank line
        if (trim($line) === '') {
            $closeAll(); continue;
        }
        // Paragraph
        $closeAll();
        $out[] = '<p>' . inline($line) . '</p>';
    }

    $closeAll();
    return implode("\n", $out);
}

function inline(string $s): string
{
    // Images must be handled before escaping
    // Extract image tokens first, replace with placeholders
    $images = [];
    $s = preg_replace_callback(
        '/!\[([^\]]*)\]\(([^)]+)\)/',
        function ($m) use (&$images) {
            $token = "\x00IMG" . count($images) . "\x00";
            $alt   = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $src   = htmlspecialchars($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $images[$token] = "<img src=\"{$src}\" alt=\"{$alt}\" style=\"max-width:100%;height:auto;display:block;margin:1em 0\">";
            return $token;
        },
        $s
    );

    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $s);
    $s = preg_replace('/`(.+?)`/',       '<code>$1</code>',     $s);
    $s = preg_replace(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        '<a href="$2" target="_blank" rel="noopener">$1</a>',
        $s
    );

    // Restore image tags
    foreach ($images as $token => $tag) {
        $s = str_replace(htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $tag, $s);
    }
    return $s;
}

$bodyHtml = markdownToHtml($markdown);
$titleEsc = htmlspecialchars($rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ── Build and save myarticle.html ─────────────────────────────
$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$titleEsc}</title>
<style>
body {
  font-family: Georgia, serif;
  max-width: 75ch;
  margin: 2rem auto;
  line-height: 1.7;
  color: #222;
  padding: 0 1rem 4rem;
}
h1 { font-size: 1.5rem; margin-bottom: 1.5rem; line-height: 1.3; }
h2 { font-size: 1.2rem; margin: 1.5rem 0 .5rem; }
h3 { font-size: 1.05rem; margin: 1.2rem 0 .4rem; }
p  { margin: 0 0 1em; }
blockquote {
  border-left: 3px solid #6366f1;
  margin: 1em 0;
  padding: .25em 1em;
  color: #444;
  font-style: italic;
}
ul, ol { margin: .5em 0 1em 1.5em; }
li { margin-bottom: .25em; }
hr { border: none; border-top: 1px solid #e5e7eb; margin: 1.5em 0; }
</style>
</head>
<body>
<h1>{$titleEsc}</h1>
{$bodyHtml}
</body>
</html>
HTML;

if (file_put_contents($OUT_FILE, $html) === false) {
    die(errorPage('Failed to write myarticle.html — check directory permissions.'));
}

// ── Redirect to public URL ────────────────────────────────────
header('Location: ' . $PUBLIC_URL);
exit;

// ── Error helper ─────────────────────────────────────────────
function errorPage(string $msg): string
{
    http_response_code(500);
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
         . '<title>Error</title></head><body>'
         . '<p style="font-family:sans-serif;color:#dc2626;padding:2rem">'
         . htmlspecialchars($msg) . '</p>'
         . '<p style="font-family:sans-serif;padding:0 2rem">'
         . '<a href="javascript:history.back()">← Back</a></p>'
         . '</body></html>';
}
