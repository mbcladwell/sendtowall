<?php
/**
 * fetch_article.php
 * Usage: php fetch_article.php <x.com_article_url>
 *
 * Fetches an X.com article using Puppeteer (headless Chrome),
 * extracts title + content, wraps text responsively via CSS,
 * writes myarticle.html, SCPs it to the server,
 * and opens it in the browser.
 */

if ($argc < 2) {
    die("Usage: php fetch_article.php <article_url>\n");
}

$url     = trim($argv[1]);
$jsHelper = __DIR__ . '/fetch_article.js';

if (!file_exists($jsHelper)) {
    die("Error: fetch_article.js not found next to this script.\n");
}

// ── 1. Fetch via Puppeteer Node helper ───────────────────────
echo "Fetching article via Puppeteer...\n";

$cmd    = sprintf('node %s %s 2>&1', escapeshellarg($jsHelper), escapeshellarg($url));
$output = shell_exec($cmd);

if (empty($output)) {
    die("Error: Node script returned no output.\n");
}

// Node may print multiple lines; grab the last JSON line
$lines = array_filter(array_map('trim', explode("\n", $output)));
$json  = end($lines);
$data  = json_decode($json, true);

if (!$data || isset($data['error'])) {
    $err = $data['error'] ?? 'Unknown error';
    die("Error from Node: $err\n");
}

$rawTitle = trim($data['title'] ?? '');
$bodyText = trim($data['body']  ?? '');

// Strip " / X" or "| X" suffix from title
$rawTitle = preg_replace('/[\|\/]\s*X\s*$/', '', $rawTitle);
$rawTitle = trim($rawTitle);

// If title is just "X" (site name), try to extract from body first line
if ($rawTitle === 'X' || empty($rawTitle)) {
    $firstLine = strtok($bodyText, "\n");
    if ($firstLine && strlen($firstLine) > 5) {
        $rawTitle = trim($firstLine);
    } else {
        $rawTitle = 'Untitled Article';
    }
}

echo "Title: $rawTitle\n";

if (empty($bodyText)) {
    die("Error: Article body is empty. The page may require login.\n");
}

// ── 2. Build title slug (first 5 words, underscore-joined) ───
$words     = preg_split('/\s+/', $rawTitle, -1, PREG_SPLIT_NO_EMPTY);
$titleSlug = implode('_', array_slice($words, 0, 5));

// ── 3. Convert body text into <p> paragraphs ─────────────────
function textToHtmlParagraphs(string $text): string
{
    $paragraphs = preg_split('/\n{2,}/', trim($text));
    $output     = '';
    foreach ($paragraphs as $para) {
        $para = preg_replace('/\s+/', ' ', trim($para));
        if ($para === '') continue;
        $escaped = htmlspecialchars(
            $para,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $output .= "<p>$escaped</p>\n";
    }
    return $output;
}

$bodyHtml = textToHtmlParagraphs($bodyText);

// ── 5. Write myarticle.html ───────────────────────────────────
$titleEsc = htmlspecialchars($rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$htmlOut = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport"
  content="width=device-width,
  initial-scale=1.0">
<title>{$titleSlug}</title>
<style>
body {
  font-family: Georgia, serif;
  max-width: 75ch;
  margin: 2rem auto;
  line-height: 1.7;
  color: #222;
  padding: 0 1rem;
}
h1 { font-size: 1.4rem; }
p { margin: 0 0 1em; }
</style>
</head>
<body>
<h1>{$titleEsc}</h1>
<div>
{$bodyHtml}
</div>
</body>
</html>
HTML;

$outFile = 'myarticle.html';
file_put_contents($outFile, $htmlOut);
echo "Written: $outFile\n";

// ── 6. SCP to remote server ───────────────────────────────────
$remoteHost = 'labsmaee@67.223.118.97';
$remotePath = '/home/labsmaee/public_html/wallabag/web/uploads/';
$remoteFile = $remotePath . $outFile;

// Delete remote file if it exists
echo "Removing remote file if it exists...\n";
$sshCmd = sprintf(
    'ssh -o BatchMode=yes -o ConnectTimeout=15 -p 21098 %s "rm -f %s"',
    escapeshellarg($remoteHost),
    escapeshellarg($remoteFile)
);
exec($sshCmd, $sshOut, $sshRet);
if ($sshRet !== 0) {
    echo "Warning: SSH delete failed (code $sshRet).\n";
}

echo "Uploading via SCP...\n";
$scpCmd = sprintf(
    'scp -o BatchMode=yes -o ConnectTimeout=15 -P 21098 %s %s',
    escapeshellarg($outFile),
    escapeshellarg($remoteHost . ':' . $remotePath)
);
passthru($scpCmd, $scpRet);

if ($scpRet !== 0) {
    echo "Warning: SCP failed (code $scpRet). Check SSH access.\n";
} else {
    echo "Upload successful.\n";
}

// ── 7. Open in browser ────────────────────────────────────────
$webUrl = 'https://labsolns.com/wallabag/web/uploads/myarticle.html';
echo "Opening $webUrl ...\n";
exec(sprintf('xdg-open %s 2>/dev/null &', escapeshellarg($webUrl)));

echo "Done.\n";
