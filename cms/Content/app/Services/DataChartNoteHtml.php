<?php

declare(strict_types=1);

namespace Cms\Content\Services;

/** Restricted Tiptap markup for chart side notes; only a numeric ordered-list start survives. */
final class DataChartNoteHtml
{
    private const TAGS = ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li'];

    public static function hasText(string $html): bool
    {
        $text = self::root($html)?->textContent ?? '';
        $visible = preg_replace('/[\p{Z}\s]+/u', '', $text);

        return is_string($visible) && $visible !== '';
    }

    public static function valid(string $html): bool
    {
        if (mb_strlen($html) > 4000) {
            return false;
        }

        $root = self::root($html);
        if ($root === null || !self::hasText($html) || mb_strlen($root->textContent) > 1000) {
            return false;
        }

        foreach ($root->childNodes as $child) {
            if (!self::validNode($child)) {
                return false;
            }
        }

        return true;
    }

    public static function render(string $html): string
    {
        $root = self::root($html);
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= self::renderNode($child);
        }

        return $result;
    }

    private static function validNode(\DOMNode $node): bool
    {
        if ($node instanceof \DOMText) {
            return true;
        }
        if (!$node instanceof \DOMElement || !in_array($tag = strtolower($node->tagName), self::TAGS, true)
            || ($node->attributes->length > 0 && !($tag === 'ol' && $node->attributes->length === 1 && self::orderedStart($node) !== null))) {
            return false;
        }
        foreach ($node->childNodes as $child) {
            if (!self::validNode($child)) {
                return false;
            }
        }

        return true;
    }

    private static function renderNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (!$node instanceof \DOMElement || !in_array($tag = strtolower($node->tagName), self::TAGS, true)) {
            return '';
        }
        if ($tag === 'br') {
            return '<br>';
        }

        $content = '';
        foreach ($node->childNodes as $child) {
            $content .= self::renderNode($child);
        }

        $start = $tag === 'ol' ? self::orderedStart($node) : null;

        return '<' . $tag . ($start !== null ? ' start="' . $start . '"' : '') . '>' . $content . '</' . $tag . '>';
    }

    private static function orderedStart(\DOMElement $element): ?int
    {
        $value = $element->getAttribute('start');

        return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 999 ? (int) $value : null;
    }

    private static function root(string $html): ?\DOMElement
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="chart-note-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document->getElementById('chart-note-root');
    }
}
