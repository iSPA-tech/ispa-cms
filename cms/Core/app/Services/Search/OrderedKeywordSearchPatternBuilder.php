<?php

namespace Cms\Core\Services\Search;

use InvalidArgumentException;

final class OrderedKeywordSearchPatternBuilder
{
    /** @var array<string, string> */
    private const FOLD_GROUPS = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ',
        'e' => 'èéẹẻẽêềếệểễ',
        'i' => 'ìíịỉĩ',
        'o' => 'òóọỏõôồốộổỗơờớợởỡ',
        'u' => 'ùúụủũưừứựửữ',
        'y' => 'ỳýỵỷỹ',
        'd' => 'đ',
    ];

    /**
     * @return list<array{tier: int, mode: 'sensitive'|'folded', pattern: string}>
     */
    public function build(?string $keyword): array
    {
        if ($keyword === null) {
            return [];
        }

        $this->assertValidUtf8($keyword);

        $normalized = preg_replace('/\s+/u', ' ', $keyword);
        $normalized = mb_strtolower(trim($normalized ?? ''));

        if ($normalized === '') {
            return [];
        }

        $tokens = explode(' ', $normalized);
        $folded = $this->fold($normalized);
        $foldedTokens = explode(' ', $folded);

        $candidates = [
            ['tier' => 0, 'mode' => 'sensitive', 'pattern' => '%'.$this->escape($normalized).'%'],
            ['tier' => 1, 'mode' => 'sensitive', 'pattern' => '%'.implode('%', array_map($this->escape(...), $tokens)).'%'],
            ['tier' => 2, 'mode' => 'folded', 'pattern' => '%'.$this->escape($folded).'%'],
            ['tier' => 3, 'mode' => 'folded', 'pattern' => '%'.implode('%', array_map($this->escape(...), $foldedTokens)).'%'],
        ];

        $patterns = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['mode']."\0".$candidate['pattern'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $patterns[] = $candidate;
        }

        return $patterns;
    }

    public function fold(string $value): string
    {
        $this->assertValidUtf8($value);

        return strtr(mb_strtolower($value), $this->foldMap());
    }

    public function postgresTranslateFrom(): string
    {
        return implode('', self::FOLD_GROUPS);
    }

    public function postgresTranslateTo(): string
    {
        $translated = '';

        foreach (self::FOLD_GROUPS as $ascii => $group) {
            $translated .= str_repeat($ascii, mb_strlen($group));
        }

        return $translated;
    }

    /** @return array<string, string> */
    private function foldMap(): array
    {
        $map = [];

        foreach (self::FOLD_GROUPS as $ascii => $group) {
            foreach (mb_str_split($group) as $character) {
                $map[$character] = $ascii;
            }
        }

        return $map;
    }

    private function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function assertValidUtf8(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Keyword must be valid UTF-8.');
        }
    }
}
