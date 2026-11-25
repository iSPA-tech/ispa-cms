<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

/**
 * Splits long text or HTML into chunks of at most `$max` characters on block boundaries (after a closing block tag or
 * `<br>` for HTML, a blank line for text), so each AI call sees whole paragraphs. A single block longer than `$max`
 * stays whole: it is never cut inside a tag or sentence.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
final class TextChunker
{
    /**
     * @return list<string>
     */
    public static function chunks(string $text, int $max, bool $html): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        $pattern = $html
            ? '/(?<=<\/p>|<\/h1>|<\/h2>|<\/h3>|<\/h4>|<\/h5>|<\/h6>|<\/li>|<\/ul>|<\/ol>|<\/div>|<\/blockquote>|<\/table>|<\/figure>|<br>|<br\/>|<br \/>)/i'
            : '/(?<=\n\n)/';
        $blocks = preg_split($pattern, $text) ?: [$text];

        $chunks = [];
        $current = '';
        foreach ($blocks as $block) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($block) > $max) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $block;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Sequence of tag names (`p`, `/p`, `a`, ...) of an HTML fragment, attributes ignored.
     *
     * @return list<string>
     */
    public static function tagSequence(string $html): array
    {
        preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)/', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $x): string => $x[1] . strtolower($x[2]), $m);
    }
}
