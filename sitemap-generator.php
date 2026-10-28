<?php
$site = 'https://example.com';
$output = __DIR__ . '/../public_html/sitemap.xml'; // Adjust this path
$queue = [ $site . '/' ];
$visited = [];
$pages = [];

$normalize = function (string $href, string $current) use ($site): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || $href[0] === '#' || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) {
        return null;
    }
    if (str_starts_with($href, '//')) {
        $href = 'https:' . $href;
    } elseif (str_starts_with($href, '/')) {
        $href = $site . $href;
    } elseif (!preg_match('~^https?://~i', $href)) {
        $directory = dirname(parse_url($current, PHP_URL_PATH) ?: '/');
        $href = $site . ($directory === '/' ? '' : $directory) . '/' . $href;
    }

    $parts = parse_url($href);
    if (!$parts || strtolower($parts['host'] ?? '') !== 'novoscanais.com'
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || isset($parts['query'])) {
        return null;
    }

    $segments = [];
    foreach (explode('/', $parts['path'] ?? '/') as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') array_pop($segments);
        else $segments[] = $part;
    }
    $path = '/' . implode('/', $segments);
    if (str_ends_with($parts['path'] ?? '/', '/') && $path !== '/') $path .= '/';
    if (preg_match('~^/(admin|private|backup|tmp|api|login)(/|$)~i', $path)
        || preg_match('~\.(pdf|jpe?g|png|gif|svg|webp|css|js|zip|xml|txt|mp4)$~i', $path)) {
        return null;
    }
    return $site . $path;
};

while ($queue && count($visited) < 1000) {
    $url = array_shift($queue);
    if (isset($visited[$url])) continue;
    $visited[$url] = true;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'NovosCanaisSitemapGenerator/1.0',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    $effective = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    curl_close($ch);

    $effectiveUrl = $normalize($effective, $url);
    if ($code !== 200 || !is_string($html) || stripos($type, 'text/html') === false || !$effectiveUrl) continue;
    $visited[$effectiveUrl] = true;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $robots = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]/@content');
    $noindex = false;
    foreach ($robots as $item) {
        if (stripos($item->nodeValue, 'noindex') !== false) $noindex = true;
    }
    if (!$noindex) $pages[$effectiveUrl] = true;

    foreach ($xpath->query('//a[@href]') as $link) {
        $next = $normalize($link->getAttribute('href'), $effectiveUrl);
        if ($next && !isset($visited[$next])) $queue[] = $next;
    }
    usleep(100000);
}

ksort($pages);
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;
$root = $xml->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
$xml->appendChild($root);
foreach (array_keys($pages) as $url) {
    $entry = $xml->createElement('url');
    $loc = $xml->createElement('loc');
    $loc->appendChild($xml->createTextNode($url));
    $entry->appendChild($loc);
    $root->appendChild($entry);
}
$tmp = $output . '.tmp';
if ($xml->save($tmp) === false || !rename($tmp, $output)) {
    throw new RuntimeException('Unable to write ' . $output);
}
echo 'Generated sitemap with ' . count($pages) . " URLs\n";
