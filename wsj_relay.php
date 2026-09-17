<?php
/**
 * wsj_relay.php
 * Receives html/title/url via POST from the bookmarklet's new blank tab,
 * then immediately re-POSTs them to fetch_wsj.php via an auto-submitting form.
 *
 * Why this exists: WSJ's CSP blocks form-action to external domains, so the
 * bookmarklet opens a new about:blank tab and posts here instead.  This page
 * lives on the same origin as fetch_wsj.php, so no CSP applies.
 *
 * The payload comes in two shapes depending on how the bookmarklet called us:
 *   • Direct POST  — html/title/url fields in the POST body  (preferred)
 *   • Fallback GET — fields in the query string (only for tiny payloads)
 */

// Accept fields from either POST or GET
$html  = $_POST['html']  ?? $_GET['html']  ?? '';
$title = $_POST['title'] ?? $_GET['title'] ?? '';
$url   = $_POST['url']   ?? $_GET['url']   ?? '';

if (empty($html)) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;padding:2rem;color:#dc2626">'
      . 'No article HTML received. Run the bookmarklet on a WSJ article page.</p>');
}

// Escape for embedding in HTML attribute values
$htmlEsc  = htmlspecialchars($html,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
$titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$urlEsc   = htmlspecialchars($url,   ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sending to Wall…</title>
</head>
<body>
<p style="font-family:sans-serif;padding:2rem;color:#555">Sending to wall…</p>
<form id="f" method="POST" action="fetch_wsj.php">
  <textarea name="html"  style="display:none"><?= $htmlEsc ?></textarea>
  <input type="hidden" name="title" value="<?= $titleEsc ?>">
  <input type="hidden" name="url"   value="<?= $urlEsc ?>">
</form>
<script>document.getElementById('f').submit();</script>
</body>
</html>
