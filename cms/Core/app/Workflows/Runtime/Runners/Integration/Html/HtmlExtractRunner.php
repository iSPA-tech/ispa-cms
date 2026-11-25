<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Html;

use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationRunner;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * `html_extract`: turn HTML already in the run (for example `vars.page.body` from http_request) into readable text,
 * CSS-selector fields, links and images. No network.
 *
 * Config: `html` (template), `base_url` (to make links absolute), `selectors{name: "css" | "css@attr" |
 * {selector, attr?, all?}}`, `max_links` (default 200), `storeAs`.
 * Result: {title, text, fields, links[{url, text}], images[{src, alt}], tainted: true}. Main text prefers <article>,
 * <main> or [role=main]; scripts, styles, forms, navigation, header, footer and asides are removed first. Only
 * http(s) links and images are listed.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Html
 * @since   2026-11-24
 */
class HtmlExtractRunner extends IntegrationRunner
{
    private const MAX_HTML_BYTES = 5 * 1024 * 1024;

    private const NOISE = ['script', 'style', 'noscript', 'iframe', 'svg', 'template', 'form', 'nav', 'header', 'footer', 'aside', 'button', 'input', 'select', 'textarea', 'object', 'embed'];

    private const BLOCKS = ['p', 'div', 'section', 'article', 'main', 'br', 'li', 'ul', 'ol', 'tr', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'figure', 'figcaption', 'dd', 'dt', 'hr'];

    protected function type(): string
    {
        return 'html_extract';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $html = $this->render($config['html'] ?? '', $context);
        if (is_array($html)) {
            $html = (string) ($html['body'] ?? '');
        }
        $html = (string) $html;
        if (trim($html) === '') {
            throw new IntegrationFailure('html is empty.');
        }
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new IntegrationFailure('html is larger than ' . self::MAX_HTML_BYTES . ' bytes.');
        }
        $baseUrl = $this->renderText($config['base_url'] ?? '', $context);
        $baseUrl = preg_match('#^https?://#i', $baseUrl) ? $baseUrl : '';
        $maxLinks = max(0, min(1000, (int) ($config['max_links'] ?? 200)));

        $fields = $this->fields($html, $baseUrl, is_array($config['selectors'] ?? null) ? $config['selectors'] : []);

        $dom = $this->load($html);
        $xpath = new DOMXPath($dom);
        $title = trim((string) ($xpath->evaluate('string(//title)') ?: ''));
        $links = $this->links($xpath, $baseUrl, $maxLinks);
        $images = $this->images($xpath, $baseUrl, $maxLinks);

        foreach (self::NOISE as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $main = $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $xpath->query('//*[@role="main"]')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0)
            ?? $dom->documentElement;
        $text = $main ? $this->normalize($this->textOf($main)) : '';

        $result = [
            'title' => $title,
            'text' => $text,
            'fields' => $fields,
            'links' => $links,
            'images' => $images,
            'tainted' => true,
        ];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'html_extract',
            'html_bytes' => strlen($html),
            'text_length' => mb_strlen($text),
            'fields' => count($fields),
            'links' => count($links),
        ]]);
    }

    /**
     * @param array<string, mixed> $selectors
     * @return array<string, mixed>
     */
    private function fields(string $html, string $baseUrl, array $selectors): array
    {
        if ($selectors === []) {
            return [];
        }
        $crawler = new Crawler($html, $baseUrl !== '' ? $baseUrl : null);
        $out = [];
        foreach (array_slice($selectors, 0, 50, true) as $name => $spec) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $selector = is_array($spec) ? (string) ($spec['selector'] ?? '') : (string) $spec;
            $attr = is_array($spec) ? (isset($spec['attr']) ? (string) $spec['attr'] : null) : null;
            $all = is_array($spec) && filter_var($spec['all'] ?? false, FILTER_VALIDATE_BOOL);
            if (!is_array($spec) && preg_match('/^(.*)@([A-Za-z_:][A-Za-z0-9_:.-]*)$/', $selector, $m)) {
                $selector = $m[1];
                $attr = $m[2];
            }
            if (trim($selector) === '') {
                throw new IntegrationFailure("selector for {$name} is empty.");
            }
            try {
                $nodes = $crawler->filter($selector);
            } catch (Throwable) {
                throw new IntegrationFailure("selector for {$name} is not a valid CSS selector.");
            }
            $values = [];
            foreach ($nodes->slice(0, $all ? 500 : 1) as $node) {
                /** @var DOMElement $node */
                $value = $attr !== null ? $node->getAttribute($attr) : $this->normalize($this->textOf($node));
                if ($attr !== null && in_array(strtolower($attr), ['href', 'src'], true)) {
                    $value = $this->absolute($value, $baseUrl) ?? '';
                }
                $values[] = $value;
            }
            $out[$name] = $all ? $values : ($values[0] ?? null);
        }

        return $out;
    }

    private function load(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        if (!preg_match('/<meta[^>]+charset/i', $html)) {
            $html = '<meta charset="utf-8">' . $html;
        }
        $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /** @return list<array{url: string, text: string}> */
    private function links(DOMXPath $xpath, string $baseUrl, int $max): array
    {
        $out = [];
        $seen = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            if (count($out) >= $max) {
                break;
            }
            /** @var DOMElement $a */
            $url = $this->absolute($a->getAttribute('href'), $baseUrl);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = ['url' => $url, 'text' => mb_substr($this->normalize($a->textContent), 0, 300)];
        }

        return $out;
    }

    /** @return list<array{src: string, alt: string}> */
    private function images(DOMXPath $xpath, string $baseUrl, int $max): array
    {
        $out = [];
        foreach ($xpath->query('//img') as $img) {
            if (count($out) >= $max) {
                break;
            }
            /** @var DOMElement $img */
            $src = $this->absolute($img->getAttribute('src') ?: $img->getAttribute('data-src'), $baseUrl);
            if ($src !== null) {
                $out[] = ['src' => $src, 'alt' => mb_substr(trim($img->getAttribute('alt')), 0, 300)];
            }
        }

        return $out;
    }

    private function absolute(string $url, string $base): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || $base === '') {
            return null;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($url, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $path = (string) ($parts['path'] ?? '/');

        return $origin . (substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/') . $url;
    }

    private function textOf(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return $node->textContent;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->textOf($child);
        }
        if ($node instanceof DOMElement && in_array(strtolower($node->tagName), self::BLOCKS, true)) {
            $text = "\n" . $text . "\n";
        }

        return $text;
    }

    private function normalize(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[ \t\f\v]+/u', ' ', $line)), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
