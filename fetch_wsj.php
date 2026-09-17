<?php
/**
 * fetch_wsj.php — web script
 * Receives a WSJ article extracted from the browser (logged-in session)
 * via POST, cleans it up, downloads images server-side, saves the result
 * as myarticle.html in a random subdirectory, and redirects there.
 *
 * POST params:
 *   html  — outerHTML of the article body (extracted by bookmarklet)
 *   title — article headline
 *   url   — original WSJ article URL
 */

$BASE_URL   = 'https://labsolns.com/twittart';
$dirName    = bin2hex(random_bytes(4));
$OUT_DIR    = __DIR__ . '/' . $dirName;
$OUT_FILE   = $OUT_DIR . '/myarticle.html';
$PUBLIC_URL = $BASE_URL . '/' . $dirName . '/myarticle.html';

if (!mkdir($OUT_DIR, 0755)) {
    die(errorPage('Failed to create output directory.'));
}

// ── Input ─────────────────────────────────────────────────────
$rawHtml  = trim($_POST['html']  ?? '');
$rawTitle = trim($_POST['title'] ?? '');
$srcUrl   = trim($_POST['url']   ?? '');

if (empty($rawHtml)) {
    die(errorPage('No article HTML received. Did the bookmarklet run on a WSJ article page?'));
}

// ── Parse article body from the received HTML ─────────────────
// The bookmarklet sends either:
//   (a) the outerHTML of the article element, or
//   (b) the full document HTML as a fallback
// We extract paragraphs, subheadings, figures and captions.

$dom = new DOMDocument();
// Suppress warnings from malformed HTML (WSJ uses lots of inline <style>/<script>)
@$dom->loadHTML('<?xml encoding="UTF-8">' . $rawHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

// ── Extract title ─────────────────────────────────────────────
if (empty($rawTitle)) {
    // Try h1[data-testid="headline"]
    $h1nodes = $xpath->query('//h1[@data-testid="headline"]');
    if ($h1nodes->length) {
        $rawTitle = trim($h1nodes->item(0)->textContent);
    }
    // Fallback: any h1
    if (empty($rawTitle)) {
        $h1nodes = $xpath->query('//h1');
        if ($h1nodes->length) {
            $rawTitle = trim($h1nodes->item(0)->textContent);
        }
    }
}
// Strip "WSJ" / "- WSJ" / "| The Wall Street Journal" suffixes
$rawTitle = preg_replace('/\s*[\|\-–]\s*(WSJ|The Wall Street Journal)\s*$/i', '', $rawTitle);
$rawTitle = trim($rawTitle) ?: 'Untitled Article';

// ── Extract byline ────────────────────────────────────────────
$byline = '';

// Helper: collect text from a node after removing <style>/<script> children
function nodeCleanText(DOMNode $node): string {
    $clone = $node->cloneNode(true);
    // Remove style and script descendants
    $toRemove = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveArrayIterator([]),   // placeholder — we'll walk manually
    );
    $walker = function(DOMNode $n) use (&$walker, &$toRemove) {
        foreach ($n->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE
                && in_array(strtolower($child->nodeName), ['style','script'])) {
                $toRemove[] = $child;
            } else {
                $walker($child);
            }
        }
    };
    $walker($clone);
    foreach ($toRemove as $el) { $el->parentNode->removeChild($el); }
    $text = preg_replace('/\s+/', ' ', $clone->textContent);
    return trim($text);
}

// Prefer explicit author <a> links inside the byline container
$bylineContainers = $xpath->query(
    '//*[@data-testid="byline"] | //*[contains(@class,"BylineContainer") or contains(@class,"byline-container")]'
);
if ($bylineContainers->length) {
    // Try to grab just the author link text (cleanest)
    $authorLinks = $xpath->query('.//a', $bylineContainers->item(0));
    $names = [];
    foreach ($authorLinks as $a) {
        $name = trim($a->textContent);
        if ($name && strlen($name) < 80) $names[] = $name;
    }
    if ($names) {
        $byline = 'By ' . implode(', ', array_unique($names));
    } else {
        // Fall back to full text content with CSS stripped
        $bylineText = nodeCleanText($bylineContainers->item(0));
        if (strlen($bylineText) < 300 && !str_contains($bylineText, '{')) {
            $byline = $bylineText;
        }
    }
}
// Final fallback: itemprop=author
if (empty($byline)) {
    $authorNodes = $xpath->query('//*[@itemprop="author"]');
    if ($authorNodes->length) {
        $byline = trim($authorNodes->item(0)->textContent);
    }
}

// ── Extract lead image ────────────────────────────────────────
// WSJ lead image is in a <figure> before the article body paragraphs.
// images.wsj.net/im-NNNNN format; request width=1280 for best quality.
$leadImgHtml = '';
$imageMap    = [];   // original_url => local_filename

$figureNodes = $xpath->query('//figure[.//img]');
foreach ($figureNodes as $fig) {
    // Only grab images from images.wsj.net (not ads, icons, etc.)
    $imgs = $xpath->query('.//img', $fig);
    foreach ($imgs as $img) {
        $src = $img->getAttribute('src');
        if (!preg_match('#images\.wsj\.net/im-#i', $src)) continue;

        // Get caption
        $captionNodes = $xpath->query('.//figcaption | .//*[contains(@class,"caption") or contains(@class,"Caption")]', $fig);
        $caption = '';
        if ($captionNodes->length) {
            $caption = trim(preg_replace('/\s+/', ' ', $captionNodes->item(0)->textContent));
            if (str_contains($caption, '{')) $caption = ''; // CSS leaked in
        }

        // Normalise URL — strip Wayback prefix if present, force width=1280
        $cleanSrc = preg_replace('#^https?://web\.archive\.org/web/\d+(?:im_)?/#', '', $src);
        $cleanSrc = strtok(html_entity_decode($cleanSrc), '?') . '?width=1280';

        $local = downloadImage($cleanSrc, $OUT_DIR);
        if ($local) {
            $imageMap[$src] = $local;
            $captionHtml = $caption
                ? '<figcaption style="font-size:.85rem;color:#555;margin-top:.4em">'
                  . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                  . '</figcaption>'
                : '';
            $leadImgHtml .= '<figure style="margin:1.5em 0">'
                . '<img src="' . htmlspecialchars($local, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '" alt="" style="max-width:100%;height:auto;display:block">'
                . $captionHtml
                . '</figure>';
        }
        break; // one lead image per figure
    }
}

// ── Extract body paragraphs ───────────────────────────────────
// WSJ marks body paragraphs with data-type="paragraph"
// Subheadings get data-type="header" (rendered as h2/h3 in their DOM)
// We walk these in document order to preserve reading sequence.

$bodyNodes = $xpath->query(
    '//*[@data-type="paragraph" or @data-type="header" or @data-type="image"]'
);

// Fallback if data-type is not present (older WSJ layout or stripped DOM):
// grab all <p> inside the article element directly
if ($bodyNodes->length === 0) {
    $bodyNodes = $xpath->query('//article//p | //article//h2 | //article//h3');
}

$bodyParts = [];
foreach ($bodyNodes as $node) {
    $type = $node->getAttribute('data-type');
    $tag  = strtolower($node->nodeName);

    if ($type === 'image') {
        // Inline image block within the article body
        $imgs = $xpath->query('.//img', $node);
        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            if (!preg_match('#images\.wsj\.net/im-#i', $src)) continue;
            $cleanSrc = preg_replace('#^https?://web\.archive\.org/web/\d+(?:im_)?/#', '', $src);
            $cleanSrc = strtok(html_entity_decode($cleanSrc), '?') . '?width=1280';
            if (!isset($imageMap[$src])) {
                $local = downloadImage($cleanSrc, $OUT_DIR);
                if ($local) $imageMap[$src] = $local;
            }
            if (isset($imageMap[$src])) {
                $captionNodes = $xpath->query('.//figcaption | .//*[contains(@class,"caption") or contains(@class,"Caption")]', $node);
                $caption = '';
                if ($captionNodes->length) {
                    $caption = trim(preg_replace('/\s+/', ' ', $captionNodes->item(0)->textContent));
                    if (str_contains($caption, '{')) $caption = '';
                }
                $captionHtml = $caption
                    ? '<figcaption style="font-size:.85rem;color:#555;margin-top:.4em">'
                      . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</figcaption>'
                    : '';
                $bodyParts[] = '<figure style="margin:1.5em 0">'
                    . '<img src="' . htmlspecialchars($imageMap[$src], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '" alt="" style="max-width:100%;height:auto;display:block">'
                    . $captionHtml . '</figure>';
            }
        }
        continue;
    }

    // Collect inline HTML of the node, converting WSJ's rich inline elements
    $text = nodeToHtml($node, $xpath);
    $text = trim($text);
    if ($text === '') continue;

    // Filter out lines that are pure CSS (style blocks that leaked into textContent)
    if (preg_match('/^\s*\.css-[a-z0-9]+\{/', $text)) continue;

    if ($type === 'header' || in_array($tag, ['h2', 'h3', 'h4'])) {
        $bodyParts[] = '<h2>' . $text . '</h2>';
    } else {
        $bodyParts[] = '<p>' . $text . '</p>';
    }
}

if (empty($bodyParts) && empty($leadImgHtml)) {
    die(errorPage(
        'Could not extract article body. '
        . 'Make sure you are logged in to WSJ and clicked the bookmarklet on the article page.'
    ));
}

$bodyHtml  = implode("\n", $bodyParts);
$titleEsc  = htmlspecialchars($rawTitle,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
$bylineEsc = htmlspecialchars($byline,    ENT_QUOTES | ENT_HTML5, 'UTF-8');
$srcUrlEsc = htmlspecialchars($srcUrl,    ENT_QUOTES | ENT_HTML5, 'UTF-8');

$bylineBlock = $bylineEsc
    ? '<p class="byline">' . $bylineEsc . '</p>'
    : '';
$sourceBlock = $srcUrlEsc
    ? '<p class="source"><a href="' . $srcUrlEsc . '" target="_blank" rel="noopener">Original article ↗</a></p>'
    : '';

// ── Build HTML ────────────────────────────────────────────────
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
h1 { font-size: 1.5rem; margin-bottom: .5rem; line-height: 1.3; }
h2 { font-size: 1.2rem; margin: 1.8rem 0 .5rem; }
h3 { font-size: 1.05rem; margin: 1.4rem 0 .4rem; }
p  { margin: 0 0 1em; }
p.byline {
  font-family: Arial, Helvetica, sans-serif;
  font-size: .9rem;
  color: #555;
  margin: .25rem 0 .1rem;
}
p.source {
  font-family: Arial, Helvetica, sans-serif;
  font-size: .8rem;
  color: #888;
  margin: 0 0 1.5rem;
}
p.source a { color: #888; }
figure { margin: 1.5em 0; }
figure img { max-width: 100%; height: auto; display: block; }
figcaption { font-size: .85rem; color: #555; margin-top: .4em;
             font-family: Arial, Helvetica, sans-serif; }
blockquote {
  border-left: 3px solid #b0a070;
  margin: 1em 0;
  padding: .25em 1em;
  color: #444;
  font-style: italic;
}
a { color: #1a0dab; }
</style>
</head>
<body>
<h1>{$titleEsc}</h1>
{$bylineBlock}
{$sourceBlock}
{$leadImgHtml}
{$bodyHtml}
</body>
</html>
HTML;

if (file_put_contents($OUT_FILE, $html) === false) {
    die(errorPage('Failed to write myarticle.html — check directory permissions.'));
}

header('Location: ' . $PUBLIC_URL);
exit;

// ── Helpers ───────────────────────────────────────────────────

/**
 * Convert a DOMNode's content to clean inline HTML,
 * preserving <strong>, <em>, <a>, <br> and stripping everything else.
 */
function nodeToHtml(DOMNode $node, DOMXPath $xpath): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $out .= htmlspecialchars($child->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;

        $tag = strtolower($child->nodeName);
        $inner = nodeToHtml($child, $xpath);

        switch ($tag) {
            case 'strong': case 'b':
                $out .= "<strong>{$inner}</strong>"; break;
            case 'em': case 'i':
                $out .= "<em>{$inner}</em>"; break;
            case 'br':
                $out .= '<br>'; break;
            case 'a':
                $href = htmlspecialchars(
                    $child->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'
                );
                // Strip Wayback Machine wrapper from href
                $href = preg_replace('#^/web/\d+(?:if_)?/#', '', $href);
                if ($href && $inner) {
                    $out .= "<a href=\"{$href}\" target=\"_blank\" rel=\"noopener\">{$inner}</a>";
                } else {
                    $out .= $inner;
                }
                break;
            case 'style': case 'script':
                break; // drop entirely
            default:
                $out .= $inner; // unwrap unknown tags, keep text
        }
    }
    return $out;
}

function downloadImage(string $url, string $destDir): ?string
{
    $parsed   = parse_url($url);
    $base     = basename($parsed['path'] ?? 'image');
    // WSJ images: im-12345678 → im-12345678.jpg
    if (!preg_match('/\.\w{2,4}$/', $base)) $base .= '.jpg';
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $base);
    $dest     = $destDir . '/' . $filename;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
        CURLOPT_HTTPHEADER     => ['Referer: https://www.wsj.com/'],
    ]);
    $data   = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code !== 200 || empty($data)) return null;

    // Fix extension from actual content-type
    if (str_contains($ctype, 'png'))  $filename = preg_replace('/\.jpg$/', '.png', $filename);
    if (str_contains($ctype, 'webp')) $filename = preg_replace('/\.jpg$/', '.webp', $filename);
    $dest = $destDir . '/' . $filename;

    return (file_put_contents($dest, $data) !== false) ? $filename : null;
}

function errorPage(string $msg): string
{
    http_response_code(400);
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
         . '<title>Error</title></head><body>'
         . '<p style="font-family:sans-serif;color:#dc2626;padding:2rem">'
         . htmlspecialchars($msg, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
         . '<p style="font-family:sans-serif;padding:0 2rem">'
         . '<a href="javascript:history.back()">← Back</a></p>'
         . '</body></html>';
}
