<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Feed;

use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationRunner;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `sitemap_read`: read page URLs (and lastmod) from a sitemap or a sitemap index on the allowed hosts list. Child
 * sitemaps of an index are fetched through SafeHttpFetcher too (same allowlist and SSRF checks).
 *
 * Config: `url`, `pattern` (optional; `*` is a wildcard, matched against the whole URL, e.g. `*\/news/*`),
 * `max_urls` (1-5000, default 500), `max_sitemaps` (1-10, default 5), `max_bytes`, `storeAs`.
 * Result: {urls[{loc, lastmod}], count, sitemaps_read, truncated}.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Feed
 * @since   2026-11-24
 */
class SitemapReadRunner extends IntegrationRunner
{
    protected function type(): string
    {
        return 'sitemap_read';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $url = $this->renderText($config['url'] ?? '', $context);
        $pattern = $this->renderText($config['pattern'] ?? '', $context);
        $regex = $pattern !== '' ? '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i' : null;
        $maxUrls = max(1, min(5000, (int) ($config['max_urls'] ?? 500)));
        $maxSitemaps = max(1, min(10, (int) ($config['max_sitemaps'] ?? 5)));
        $fetcher = app(SafeHttpFetcher::class);
        $options = array_filter(['max_bytes' => $config['max_bytes'] ?? null], static fn ($v): bool => $v !== null);

        $queue = [$url];
        $seen = [];
        $urls = [];
        $read = 0;
        $truncated = false;

        while ($queue !== [] && $read < $maxSitemaps) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $response = $fetcher->fetch($current, $options);
            $read++;
            if (!$response->successful()) {
                throw new IntegrationFailure("the sitemap answered HTTP {$response->status}.");
            }
            $xpath = FeedXml::load($response->body, 'sitemap');

            foreach ($xpath->query("/*[local-name()='sitemapindex']/*[local-name()='sitemap']") as $node) {
                $loc = FeedXml::child($xpath, $node, 'loc');
                if ($loc !== '' && !isset($seen[$loc])) {
                    $queue[] = $loc;
                }
            }
            foreach ($xpath->query("/*[local-name()='urlset']/*[local-name()='url']") as $node) {
                $loc = FeedXml::child($xpath, $node, 'loc');
                if ($loc === '' || ($regex !== null && !preg_match($regex, $loc))) {
                    continue;
                }
                if (count($urls) >= $maxUrls) {
                    $truncated = true;
                    break 2;
                }
                $urls[] = ['loc' => $loc, 'lastmod' => FeedXml::child($xpath, $node, 'lastmod') ?: null];
            }
        }
        $truncated = $truncated || $queue !== [];

        $result = ['urls' => $urls, 'count' => count($urls), 'sitemaps_read' => $read, 'truncated' => $truncated, 'tainted' => true];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'sitemap_read',
            'sitemaps_read' => $read,
            'urls' => count($urls),
            'truncated' => $truncated,
        ]]);
    }
}
