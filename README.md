
https://x.com/thedarshakrana/article/2037199903248855072

Twitter article

no <title>

bak1: cli app

The Jina anonymous endpoint for x.com is currently blocked (temporary rate-limit from abuse by another anonymous user). The old code would just show "Could not parse Jina Reader response" which gives no actionable info.

The fix does two things:

Surfaces Jina's own readableMessage in the error page so you know why it failed
Adds support for a JINA_API_KEY env var — if you set that on your server, it bypasses anonymous rate limits entirely. Free keys are available at jina.ai/reader
The Jina block on the anonymous endpoint expires today at 13:29 UTC, so it should start working again shortly even without an API key. To make it reliably rate-limit-proof, set JINA_API_KEY in your server environment.

<?php
// Set the environment variable
putenv("JINA_API_KEY=your_api_key_here");

// Verify it works by retrieving it
$apiKey = getenv("JINA_API_KEY");
echo $apiKey;
?>
