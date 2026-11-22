<?php

declare(strict_types=1);

namespace App\Services\Comment;

use Cms\Core\Models\Setting;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Str;

/**
 * Banned words of the comment settings. A comment that holds one is never
 * hidden or rejected: the admin list flags it and asks before it is approved. Matching runs when comments are read, so
 * a word added later flags older comments too.
 *
 * Words are kept in one `settings` row, one entry per line. Scoped: the list is read once per request.
 */
#[Scoped]
final class CommentBannedWords
{
    public const SETTING_KEY = 'comment.banned_words';

    public const GROUP = 'comment';

    public const MAX_ENTRIES = 500;

    public const MAX_LENGTH = 100;

    /** @var list<string>|null */
    private ?array $words = null;

    /**
     * The saved list, as the admin wrote it.
     *
     * @return list<string>
     */
    public function words(): array
    {
        return $this->words ??= self::normalize(explode("\n", (string) Setting::query()
            ->where('setting_key', self::SETTING_KEY)
            ->value('value')));
    }

    /**
     * Save a new list (cleaned like {@see normalize()}) and return it.
     *
     * @param array<int, mixed> $words
     * @return list<string>
     */
    public function save(array $words): array
    {
        $clean = self::normalize($words);
        $setting = Setting::query()->firstOrNew(['setting_key' => self::SETTING_KEY]);
        $setting->fill([
            'type' => 'textarea',
            'group_name' => self::GROUP,
            'name' => 'banned_words',
            'language_id' => null,
            'enumerate_id' => null,
            'input_setting' => array_merge($setting->defaultInputSetting, ['title' => 'Comment banned words', 'isPublic' => true]),
            'value' => implode("\n", $clean),
        ]);
        $setting->save();

        return $this->words = $clean;
    }

    /**
     * Saved entries this comment's sender name or text contains.
     *
     * @return list<string>
     */
    public function in(?string ...$texts): array
    {
        return self::find($this->words(), ...$texts);
    }

    /**
     * Entries of `$words` found in any of `$texts`: whole words or phrases, ignoring case and Vietnamese accents.
     *
     * @param array<int, string> $words
     * @return list<string>
     */
    public static function find(array $words, ?string ...$texts): array
    {
        $haystack = self::fold(implode("\n", array_filter($texts, static fn (?string $text): bool => (string) $text !== '')));
        if ($haystack === '' || $words === []) {
            return [];
        }

        $found = [];
        foreach ($words as $word) {
            $needle = self::fold($word);
            if ($needle !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/', $haystack) === 1) {
                $found[] = $word;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Trim each entry, squeeze inner spaces, drop blank and repeated entries (case and accents ignored), cut each to
     * {@see MAX_LENGTH} characters and keep at most {@see MAX_ENTRIES}.
     *
     * @param array<int, mixed> $words
     * @return list<string>
     */
    public static function normalize(array $words): array
    {
        $clean = [];
        foreach ($words as $word) {
            $entry = mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_scalar($word) ? (string) $word : '')), 0, self::MAX_LENGTH);
            $key = self::fold($entry);
            if ($key !== '' && ! isset($clean[$key])) {
                $clean[$key] = $entry;
            }
        }

        return array_slice(array_values($clean), 0, self::MAX_ENTRIES);
    }

    /** Lower-case ASCII form with single spaces: `Lừa  ĐẢO` becomes `lua dao`. */
    private static function fold(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($text))));
    }
}
