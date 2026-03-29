/**
 * fetch_article.js
 * Called by fetch_article.php via shell_exec.
 * Usage: node fetch_article.js <url>
 * Outputs JSON: { title, body }
 */

const puppeteer = require('puppeteer');

(async () => {
  const url = process.argv[2];
  if (!url) {
    console.error(JSON.stringify({ error: 'No URL provided' }));
    process.exit(1);
  }

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
      ],
    });

    const page = await browser.newPage();

    // Mimic a real browser
    await page.setUserAgent(
      'Mozilla/5.0 (X11; Linux x86_64) '
      + 'AppleWebKit/537.36 (KHTML, like Gecko) '
      + 'Chrome/124.0.0.0 Safari/537.36'
    );

    await page.goto(url, {
      waitUntil: 'networkidle2',
      timeout: 30000,
    });

    // Wait for article content to appear
    await page.waitForSelector('h1, article, [data-testid="articleBody"]', {
      timeout: 10000,
    }).catch(() => {});

    const result = await page.evaluate(() => {
      // Title: prefer article-specific h1 (not the site h1),
      // then og:title meta, then <title>
      let title = '';

      // og:title is usually the article title
      const ogTitle = document.querySelector('meta[property="og:title"]');
      if (ogTitle) title = ogTitle.getAttribute('content') || '';

      // Fallback: first h1 inside article/main
      if (!title) {
        const articleH1 = document.querySelector(
          '[data-testid="articleBody"] h1, article h1, main h1'
        );
        if (articleH1) title = articleH1.innerText.trim();
      }

      // Last resort: page <title>
      if (!title) {
        title = document.title.replace(/[|\/]\s*X\s*$/, '').trim();
      }

      // Body: prefer articleBody, then article, then main
      const bodyEl =
        document.querySelector('[data-testid="articleBody"]') ||
        document.querySelector('article') ||
        document.querySelector('main');

      let body = '';
      if (bodyEl) {
        // Replace block elements with newlines
        bodyEl.querySelectorAll(
          'p, h1, h2, h3, h4, h5, h6, li, br'
        ).forEach(el => {
          if (el.tagName === 'BR') {
            el.replaceWith('\n');
          } else {
            el.insertAdjacentText('afterend', '\n\n');
          }
        });
        body = bodyEl.innerText.trim();
      }

      return { title, body };
    });

    console.log(JSON.stringify(result));
  } catch (err) {
    console.error(JSON.stringify({ error: err.message }));
    process.exit(1);
  } finally {
    if (browser) await browser.close();
  }
})();
