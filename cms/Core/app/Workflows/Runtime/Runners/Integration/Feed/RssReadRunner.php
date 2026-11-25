<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Feed;

use Carbon\CarbonImmutable;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationRunner;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * `rss_read`: read the items of an RSS 2.0, RSS 1.0 (RDF) or Atom feed on the allowed hosts list (SafeHttpFetcher).
 *
 * Config: `url`, `since` (date; older items are left out), `max_items` (1-200, default 50), `max_bytes`, `storeAs`.
 * Result: {feed{title, link}, items[{title, link, guid, published_at, summary, content, author}], count,
 * tainted: true}.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Feed
 * @since   2026-11-24
 */
class RssReadRunner extends IntegrationRunner
{
    protected function type(): string
    {
        return 'rss_read';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $url = $this->renderText($config['url'] ?? '', $context);
        $maxItems = max(1, min(200, (int) ($config['max_items'] ?? 50)));
        $sinceText = $this->renderText($config['since'] ?? '', $context);
        $since = null;
        if ($sinceText !== '') {
            try {
                $since = CarbonImmutable::parse($sinceText);
            } catch (Throwable) {
                throw new IntegrationFailure('since is not a valid date.');
            }
        }

        $response = app(SafeHttpFetcher::class)->fetch($url, array_filter([
            'headers' => ['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'],
            'max_bytes' => $config['max_bytes'] ?? null,
        ], static fn ($v): bool => $v !== null));
        if (!$response->successful()) {
            throw new IntegrationFailure("the feed answered HTTP {$response->status}.");
        }

        $xpath = FeedXml::load($response->body, 'feed');
        $isAtom = $xpath->evaluate("count(/*[local-name()='feed'])") > 0;
        $channel = $isAtom ? $xpath->query('/*')->item(0) : $xpath->query("//*[local-name()='channel']")->item(0);
        $feed = [
            'title' => $channel ? FeedXml::child($xpath, $channel, 'title') : '',
            'link' => $channel ? ($isAtom ? $this->atomLink($xpath, $channel) : FeedXml::child($xpath, $channel, 'link')) : '',
        ];

        $items = [];
        $nodes = $xpath->query($isAtom ? "/*/*[local-name()='entry']" : "//*[local-name()='item']");
        foreach ($nodes as $node) {
            /** @var DOMElement $node */
            $published = FeedXml::child($xpath, $node, 'pubDate', 'published', 'updated', 'date');
            $publishedAt = $this->date($published);
            if ($since !== null && $publishedAt !== null && $publishedAt->lt($since)) {
                continue;
            }
            $content = FeedXml::child($xpath, $node, 'encoded', 'content');
            $summary = FeedXml::child($xpath, $node, 'description', 'summary');
            $items[] = [
                'title' => mb_substr(html_entity_decode(strip_tags(FeedXml::child($xpath, $node, 'title')), ENT_QUOTES | ENT_HTML5), 0, 500),
                'link' => $isAtom ? $this->atomLink($xpath, $node) : FeedXml::child($xpath, $node, 'link'),
                'guid' => FeedXml::child($xpath, $node, 'guid', 'id') ?: null,
                'published_at' => $publishedAt?->toIso8601String(),
                'summary' => mb_substr(trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5)), 0, 2000),
                'content' => mb_substr($content, 0, 100_000),
                'author' => FeedXml::child($xpath, $node, 'creator', 'author') ?: null,
            ];
            if (count($items) >= $maxItems) {
                break;
            }
        }

        $result = ['feed' => $feed, 'items' => $items, 'count' => count($items), 'tainted' => true];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'rss_read',
            'host' => $response->host(),
            'bytes' => strlen($response->body),
            'items' => count($items),
            'format' => $isAtom ? 'atom' : 'rss',
        ]]);
    }

    private function atomLink(DOMXPath $xpath, \DOMNode $node): string
    {
        $href = (string) $xpath->evaluate("string(*[local-name()='link'][@rel='alternate' or not(@rel)][1]/@href)", $node);

        return trim($href !== '' ? $href : FeedXml::child($xpath, $node, 'link'));
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
