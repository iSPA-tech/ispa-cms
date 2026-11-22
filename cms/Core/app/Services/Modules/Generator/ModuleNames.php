<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Generator;

use Illuminate\Support\Str;

/**
 * The names of a module built by `cms:make-module` and the placeholder renaming of its template.
 *
 * The template names the module `Skeleton` and its sample entity `Thing`. Each case form of both words, alone or
 * combined (`SkeletonThing`, `skeleton_things`, `skeleton-thing`, ...), maps to the same form of the new names.
 * A standalone lowercase `skeleton` / `thing` is the kebab form (URLs, translation keys, file names); camel and snake
 * forms appear only combined. A placeholder is replaced only as a whole token: it may not be followed by a lowercase
 * letter, and a placeholder that starts lowercase may not follow one either. So `QueueSkeletonThingJob` is renamed
 * (camel-case boundary) while "something" and "Skeletons" are left alone (and the template lint test rejects them).
 */
final class ModuleNames
{
    public function __construct(
        public readonly string $module,
        public readonly string $entity,
    ) {
    }

    /**
     * Error text for a module or entity name that is not StudlyCase ASCII, or null when the name is valid.
     */
    public static function validate(string $name): ?string
    {
        // Every capital starts a word of at least two characters: a run of capitals (CMSBlog) would become
        // one-letter words in kebab and snake case (c-m-s-blog).
        return preg_match('/^(?:[A-Z][a-z0-9]+)+$/', $name) === 1
            ? null
            : "\"{$name}\" must be StudlyCase words, each a capital then lowercase letters or digits "
                . '(Blog, KnowledgeBase, CmsBlog; not CMSBlog).';
    }

    /** Module alias: the kebab name the module provider registers (`KnowledgeBase` -> `knowledge-base`). */
    public function alias(): string
    {
        return Str::kebab($this->module);
    }

    /**
     * Placeholder => replacement, longest placeholder first.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $module = $this->module;
        $entity = $this->entity;
        $entities = Str::plural($entity);
        $combined = $module . $entity;
        $combinedPlural = $module . $entities;

        $tokens = [
            'SkeletonThings' => $combinedPlural,
            'SkeletonThing' => $combined,
            'skeletonThings' => lcfirst($combinedPlural),
            'skeletonThing' => lcfirst($combined),
            'skeleton_things' => Str::snake($combinedPlural),
            'skeleton_thing' => Str::snake($combined),
            'skeleton-things' => Str::kebab($combinedPlural),
            'skeleton-thing' => Str::kebab($combined),
            'SKELETON_THINGS' => Str::upper(Str::snake($combinedPlural)),
            'SKELETON_THING' => Str::upper(Str::snake($combined)),
            'Skeleton' => $module,
            'skeleton' => Str::kebab($module),
            'SKELETON' => Str::upper(Str::snake($module)),
            'Things' => $entities,
            'Thing' => $entity,
            'things' => Str::kebab($entities),
            'thing' => Str::kebab($entity),
        ];

        uksort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $tokens;
    }

    /** Replace every whole placeholder token in a path or file content. */
    public function rename(string $text): string
    {
        $tokens = $this->tokens();

        return (string) preg_replace_callback($this->pattern(), static fn (array $m): string => $tokens[$m[0]], $text);
    }

    /** The regex that finds whole placeholder tokens (see the class comment). */
    public function pattern(): string
    {
        $quote = static fn (array $list): string => implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $list));
        $tokens = array_keys($this->tokens());
        $lower = array_values(array_filter($tokens, static fn (string $t): bool => ctype_lower($t[0])));
        $upper = array_values(array_filter($tokens, static fn (string $t): bool => ! ctype_lower($t[0])));

        return '/(?:(?<![a-z])(?:' . $quote($lower) . ')|(?:' . $quote($upper) . '))(?![a-z])/';
    }
}
