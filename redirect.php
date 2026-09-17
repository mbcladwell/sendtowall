<?php
/**
 * redirect.php
 * Receives the article URL as a GET parameter, then auto-submits
 * a POST form to fetch_article.php — all from this server's origin,
 * so no CSP issues from x.com.
 *
 * Usage: https://labsolns.com/twittart/redirect.php?url=<encoded_url>
 */

$url = trim($_GET['url'] ?? '');

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;padding:2rem;color:#dc2626">Invalid or missing URL.</p>');
}

$urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sending to Wall…</title>
</head>
<body>
<p style="font-family:sans-serif;padding:2rem;color:#555">Sending to wall…</p>
<form id="f" method="POST" action="fetch_article.php">
  <input type="hidden" name="url" value="<?= $urlEsc ?>">
</form>
<script>document.getElementById('f').submit();</script>
</body>
</html>
