<?php

namespace Cms\Core\Services\Ai;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class AiHtmlPromptSanitizer
{
    /**
     * @return array{
     *     input_was_html: bool,
     *     original_chars: int,
     *     text_chars: int,
     *     text_excerpt: string,
     *     links: array<int, array{href: string, text: string}>,
     *     links_html: array<int, string>
     * }
     */
    public function compactParts(string $input, ?string $baseUrl = null, int $maxTextChars = 6000, int $maxLinks = 80): array
    {
        $input = trim($input);
        $maxTextChars = max(0, $maxTextChars);
        $maxLinks = max(0, $maxLinks);

        if ($input === '') {
            return $this->emptyParts();
        }

        if (! $this->looksLikeHtml($input)) {
            $text = $this->limitText($this->normalizeText($input), $maxTextChars);

            return [
                'input_was_html' => false,
                'original_chars' => mb_strlen($input),
                'text_chars' => mb_strlen($text),
                'text_excerpt' => $text,
                'links' => [],
                'links_html' => [],
            ];
        }

        $document = $this->loadHtml($input);
        if (! $document instanceof DOMDocument) {
            $text = $this->limitText($this->normalizeText(strip_tags($input)), $maxTextChars);

            return [
                'input_was_html' => true,
                'original_chars' => mb_strlen($input),
                'text_chars' => mb_strlen($text),
                'text_excerpt' => $text,
                'links' => [],
                'links_html' => [],
            ];
        }

        $xpath = new DOMXPath($document);
        $links = $this->extractLinks($xpath, $baseUrl, $maxLinks);
        $this->removePageChrome($xpath);

        $body = $xpath->query('//body')->item(0);
        $text = $this->normalizeText($body instanceof DOMNode ? $body->textContent : $document->textContent);
        $text = $this->limitText($text, $maxTextChars);

        return [
            'input_was_html' => true,
            'original_chars' => mb_strlen($input),
            'text_chars' => mb_strlen($text),
            'text_excerpt' => $text,
            'links' => $links,
            'links_html' => array_map(fn (array $link): string => $this->anchorHtml($link), $links),
        ];
    }

    public function compactPrompt(string $input, ?string $baseUrl = null, int $maxTextChars = 12000, int $maxLinks = 80): string
    {
        $parts = $this->compactParts($input, $baseUrl, $maxTextChars, $maxLinks);
        $sections = [];

        if ($parts['links_html'] !== []) {
            $sections[] = "LINKS:\n" . implode("\n", $parts['links_html']);
        }

        if ($parts['text_excerpt'] !== '') {
            $sections[] = "TEXT:\n" . $parts['text_excerpt'];
        }

        return implode("\n\n", $sections) ?: '';
    }

    /**
     * @return array{
     *     input_was_html: bool,
     *     original_chars: int,
     *     text_chars: int,
     *     text_excerpt: string,
     *     links: array<int, array{href: string, text: string}>,
     *     links_html: array<int, string>
     * }
     */
    private function emptyParts(): array
    {
        return [
            'input_was_html' => false,
            'original_chars' => 0,
            'text_chars' => 0,
            'text_excerpt' => '',
            'links' => [],
            'links_html' => [],
        ];
    }

    private function looksLikeHtml(string $input): bool
    {
        return (bool) preg_match('/<\s*\/?\s*(html|head|body|main|article|section|div|p|br|h[1-6]|a|script|style|header|footer|nav|span|table)\b/i', $input);
    }

    private function loadHtml(string $html): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /**
     * @return array<int, array{href: string, text: string}>
     */
    private function extractLinks(DOMXPath $xpath, ?string $baseUrl, int $maxLinks): array
    {
        if ($maxLinks < 1) {
            return [];
        }

        $links = [];
        $seen = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = $this->normalizeUrl($baseUrl, trim((string) $node->getAttribute('href')));
            if ($href === null) {
                continue;
            }

            $text = $this->normalizeText($node->textContent);
            if ($text === '') {
                $text = $this->normalizeText((string) ($node->getAttribute('title') ?: $node->getAttribute('aria-label')));
            }
            if ($text === '') {
                $text = $href;
            }

            $key = $href . '|' . $text;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $links[] = [
                'href' => $href,
                'text' => mb_substr($text, 0, 240),
            ];

            if (count($links) >= $maxLinks) {
                break;
            }
        }

        return $links;
    }

    private function removePageChrome(DOMXPath $xpath): void
    {
        $query = implode('|', [
            '//head',
            '//script',
            '//style',
            '//noscript',
            '//svg',
            '//iframe',
            '//canvas',
            '//header',
            '//footer',
            '//nav',
            '//aside',
            '//form',
            '//button',
        ]);

        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node->parentNode instanceof DOMNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function normalizeUrl(?string $baseUrl, string $href): ?string
    {
        if ($href === '' || preg_match('/^(javascript|mailto|tel|sms):/i', $href)) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url((string) $baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme . ':' . $href;
        }

        if ($baseUrl === null || $baseUrl === '') {
            return null;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        $path = (string) (parse_url($baseUrl, PHP_URL_PATH) ?: '/');
        $dir = rtrim(str_ends_with($path, '/') ? $path : dirname($path), '/');

        return $scheme . '://' . $host . ($dir === '' ? '' : $dir) . '/' . ltrim($href, '/');
    }

    private function anchorHtml(array $link): string
    {
        $href = htmlspecialchars((string) $link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = htmlspecialchars((string) $link['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a href="' . $href . '">' . $text . '</a>';
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\b\d{1,9}\s+(?:lượt xem|views?)\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:lượt xem|views?)\s*:?\s*\d{1,9}\b/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function limitText(string $text, int $maxChars): string
    {
        if ($maxChars < 1 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars);
    }
}
