<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Feed;

use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use DOMDocument;
use DOMXPath;

/**
 * Safe XML loading for rss_read and sitemap_read: no network, no DTD, no entity declarations (refused outright, so
 * entity expansion and external entities cannot happen).
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Feed
 * @since   2026-11-24
 */
final class FeedXml
{
    public static function load(string $xml, string $what): DOMXPath
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if ($xml === '') {
            throw new IntegrationFailure("the {$what} is empty.");
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new IntegrationFailure("the {$what} declares a DOCTYPE or entities, which is not allowed.");
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || $dom->documentElement === null) {
            throw new IntegrationFailure("the {$what} is not valid XML.");
        }

        return new DOMXPath($dom);
    }

    /** First matching child text by local name (namespace-agnostic). */
    public static function child(DOMXPath $xpath, \DOMNode $node, string ...$names): string
    {
        foreach ($names as $name) {
            $value = trim((string) $xpath->evaluate("string(*[local-name()='{$name}'][1])", $node));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
