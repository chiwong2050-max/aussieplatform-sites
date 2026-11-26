<?php
/**
 * Fetch a public ClickSites page (HTML) and localise every image it uses.
 *
 * Usage:
 *   php bin/fetch-clicksites.php <url> import/<folder>
 *
 * The page is saved as import/<folder>/index.html and every referenced image
 * (media.clicksites.ai uploads plus same-origin /assets images) is downloaded
 * into import/<folder>/assets/ with all references rewritten to local relative
 * paths. This makes the export self-contained and repeatable, so the importer
 * (bin/import-clicksites.php) can run offline afterwards.
 */

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/** Small HTTP GET helper (curl when available, otherwise stream wrapper). */
function apd_fetch_http(string $url, bool $binary = false): string
{
    $ua = 'Mozilla/5.0 (compatible; aussieplatform-sites importer)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => $ua,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (false !== $body && '' !== $body && $code > 0 && $code < 400) {
            return (string) $body;
        }
        // Fall through to the stream wrapper, which honours the system CA store.
    }
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 30, 'header' => "User-Agent: {$ua}\r\n"),
    ));
    $body = @file_get_contents($url, false, $ctx);
    return false === $body ? '' : $body;
}

/** Turn an absolute image URL into a safe, unique-ish local file name. */
function apd_fetch_local_name(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $base = basename($path);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
    $base = trim((string) $base, '-.');
    if ('' === $base) {
        $base = 'image';
    }
    return $base;
}

/**
 * Collect the image URLs worth localising:
 *   - any media.clicksites.ai upload
 *   - same-origin /assets/... design images
 *   - gallery image arrays inside __NEXT_DATA__
 */
function apd_fetch_image_urls(string $html, string $page_url): array
{
    $found = array();

    // Absolute media.clicksites.ai URLs (in markup or JSON).
    if (preg_match_all('#https://media\.clicksites\.ai/[A-Za-z0-9._~/%+\-]+#i', $html, $m)) {
        foreach ($m[0] as $u) {
            $found[] = html_entity_decode($u, ENT_QUOTES);
        }
    }

    // Same-origin design assets referenced from the page.
    if (preg_match_all('#/assets/[A-Za-z0-9._~/%+\-]+\\.(?:jpe?g|png|webp|gif|svg)#i', $html, $m2)) {
        $origin = '';
        $p = parse_url($page_url);
        if (isset($p['scheme'], $p['host'])) {
            $origin = $p['scheme'] . '://' . $p['host'];
        }
        foreach ($m2[0] as $u) {
            $found[] = $origin . html_entity_decode($u, ENT_QUOTES);
        }
    }

    // __NEXT_DATA__ may carry images that never reached the rendered markup.
    if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $nd)) {
        $json = json_decode($nd[1], true);
        $data = $json['props']['pageProps']['data'] ?? null;
        if (is_array($data)) {
            apd_fetch_collect_json_images($data, $found);
            if (isset($data['content']) && is_string($data['content'])) {
                $content = json_decode($data['content'], true);
                if (is_array($content)) {
                    apd_fetch_collect_json_images($content, $found);
                }
            }
        }
    }

    return array_values(array_unique(array_filter($found)));
}

/** Walk decoded JSON and grab anything that looks like a media URL. */
function apd_fetch_collect_json_images($node, array &$found): void
{
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            if (is_string($value) && preg_match('#^https://media\.clicksites\.ai/#i', $value)) {
                $found[] = $value;
            } elseif (is_array($value)) {
                apd_fetch_collect_json_images($value, $found);
            }
        }
    }
}

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php bin/fetch-clicksites.php <url> import/<folder>\n");
    exit(1);
}

$url  = $args[0];
$dest = rtrim($args[1], '/\\');
if (!preg_match('#^https?://#i', $url)) {
    fwrite(STDERR, "The first argument must be an http(s) URL.\n");
    exit(1);
}

if (!is_dir($dest)) {
    @mkdir($dest, 0775, true);
}
if (!is_dir($dest)) {
    fwrite(STDERR, "Could not create {$dest}\n");
    exit(1);
}

$html = apd_fetch_http($url);
if ('' === $html) {
    fwrite(STDERR, "Failed to download {$url}\n");
    exit(1);
}

$assets_dir = $dest . '/assets';
if (!is_dir($assets_dir)) {
    @mkdir($assets_dir, 0775, true);
}

$urls = apd_fetch_image_urls($html, $url);
$map  = array();   // original URL => local relative path
$used = array();   // local file name => original URL
$ok   = 0;
$fail = array();

foreach ($urls as $image_url) {
    $name = apd_fetch_local_name($image_url);
    if (isset($used[$name]) && $used[$name] !== $image_url) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $name = $stem . '-' . substr(md5($image_url), 0, 6) . ($ext ? '.' . $ext : '');
    }
    $used[$name] = $image_url;

    $local = 'assets/' . $name;
    $target = $assets_dir . '/' . $name;

    if (!is_file($target) || 0 === filesize($target)) {
        $binary = apd_fetch_http($image_url);
        if ('' === $binary) {
            $fail[] = $image_url;
            continue;
        }
        file_put_contents($target, $binary);
    }
    $map[$image_url] = $local;
    $ok++;
}

// Rewrite every occurrence (plain, JSON-escaped and entity-encoded) to local.
$rewritten = $html;
foreach ($map as $from => $to) {
    $variants = array(
        $from,
        str_replace('/', '\\/', $from),
        str_replace('/', '%2F', $from),
    );
    foreach ($variants as $variant) {
        $rewritten = str_replace($variant, $to, $rewritten);
    }
}

// Google Fonts is allowed; normalise the protocol-relative URL to https.
$rewritten = str_replace('href="//fonts.googleapis.com', 'href="https://fonts.googleapis.com', $rewritten);
$rewritten = str_replace('href="//fonts.gstatic.com', 'href="https://fonts.gstatic.com', $rewritten);

file_put_contents($dest . '/index.html', $rewritten);

echo "Fetched ClickSites export\n";
echo "  url:        {$url}\n";
echo "  saved to:   {$dest}/index.html (" . strlen($rewritten) . " bytes)\n";
echo "  images:     {$ok} localised into {$dest}/assets/\n";
if ($fail) {
    echo "  failed:     " . count($fail) . "\n";
    foreach ($fail as $f) {
        echo "    - {$f}\n";
    }
}
