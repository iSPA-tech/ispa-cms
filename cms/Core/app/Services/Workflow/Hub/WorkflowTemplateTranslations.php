<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use Illuminate\Support\Facades\App;

/**
 * Per-language text of a hub workflow template (2026-11-28: English-only sites listed and installed the Vietnamese
 * text of the iSPA templates).
 *
 * The signed bundle format does not change: the text rides inside the template's package as `workflow.translations`,
 * a row key that sites without this class ignore (they keep listing and installing the base text):
 *
 * ```
 * translations: { <en|vi>: { name, description?, steps?: { <step_code>: { name?, config?: { <config key>: <value> } } } } }
 * ```
 *
 * The workflow row itself is the base text and the fallback for any other language. A language replaces the project
 * name and description, step names and top-level keys of a step config that the step already has (notice texts, AI
 * label descriptions); never the graph. {@see self::derive()} builds one language at bundle build time from a second
 * local workflow with the same graph; {@see self::localizePackage()} applies it before an install or update, so the
 * per-site checks (inspector, manifest, designer save rules) run on exactly what is stored. The hub keeps the part a
 * list needs ({@see self::display()}: name, description, step names) per version and sends it as the catalog row's
 * `translations`, which sites without this class also ignore.
 *
 * The language is the request's app locale ({@see self::locale()}): the admin API answers in the site's language
 * (base-admin never sends `X-Locale`), the same rule the template notes already follow.
 */
final class WorkflowTemplateTranslations
{
    /** Key of the translations inside the package's workflow row. */
    public const KEY = 'translations';

    public const LOCALES = ['en', 'vi'];

    /** Language template text is shown and installed in on this request. */
    public static function locale(): string
    {
        return strtolower(substr((string) App::getLocale(), 0, 2));
    }

    /**
     * Language an installer command writes: its `--locale` option, else the site language. Null when the option names
     * a language templates are not written in.
     */
    public static function commandLocale(mixed $option): ?string
    {
        if (is_string($option) && trim($option) !== '') {
            $locale = strtolower(trim($option));

            return in_array($locale, self::LOCALES, true) ? $locale : null;
        }

        return self::locale();
    }

    /**
     * Normal form of a package `translations` value: only {@see self::LOCALES}, trimmed non-empty names, step entries
     * with a name and/or a non-empty config object, step codes sorted; anything else is dropped. Null when nothing is left.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public static function normal(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach (self::LOCALES as $locale) {
            $text = $value[$locale] ?? null;
            if (!is_array($text)) {
                continue;
            }
            $entry = [];
            if (($name = self::string($text['name'] ?? null, WorkflowBundle::NAME_MAX)) !== null) {
                $entry['name'] = $name;
            }
            if (($description = self::string($text['description'] ?? null, WorkflowBundle::DESCRIPTION_MAX)) !== null) {
                $entry['description'] = $description;
            }
            $steps = [];
            foreach (is_array($text['steps'] ?? null) ? $text['steps'] : [] as $code => $step) {
                if ((string) $code === '' || !is_array($step)) {
                    continue;
                }
                $item = [];
                if (($stepName = self::string($step['name'] ?? null, WorkflowBundle::NAME_MAX)) !== null) {
                    $item['name'] = $stepName;
                }
                if (is_array($step['config'] ?? null) && $step['config'] !== [] && !array_is_list($step['config'])) {
                    $item['config'] = $step['config'];
                }
                if ($item !== []) {
                    $steps[(string) $code] = $item;
                }
            }
            if ($steps !== []) {
                ksort($steps, SORT_STRING);
                $entry['steps'] = $steps;
            }
            if ($entry !== []) {
                $out[$locale] = $entry;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * What a template list needs per language: `{<locale>: {name?, description?, steps?: {<step_code>: name}}}`. Reads
     * both the package form ({@see self::normal()}) and this display form itself (the hub's catalog rows).
     *
     * @return array<string, array<string, mixed>>|null
     */
    public static function display(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach (self::LOCALES as $locale) {
            $text = $value[$locale] ?? null;
            if (!is_array($text)) {
                continue;
            }
            $entry = [];
            if (($name = self::string($text['name'] ?? null, WorkflowBundle::NAME_MAX)) !== null) {
                $entry['name'] = $name;
            }
            if (($description = self::string($text['description'] ?? null, WorkflowBundle::DESCRIPTION_MAX)) !== null) {
                $entry['description'] = $description;
            }
            $steps = [];
            foreach (is_array($text['steps'] ?? null) ? $text['steps'] : [] as $code => $step) {
                $stepName = self::string(is_array($step) ? ($step['name'] ?? null) : $step, WorkflowBundle::NAME_MAX);
                if ((string) $code !== '' && $stepName !== null) {
                    $steps[(string) $code] = $stepName;
                }
            }
            if ($steps !== []) {
                ksort($steps, SORT_STRING);
                $entry['steps'] = $steps;
            }
            if ($entry !== []) {
                $out[$locale] = $entry;
            }
        }

        return $out === [] ? null : $out;
    }

    /** Display translations carried by a bundle's package. */
    public static function displayOf(WorkflowBundle $bundle): ?array
    {
        return self::display(self::normal($bundle->package['workflow'][self::KEY] ?? null));
    }

    /**
     * A library entry (`name`, `description`, `summary`, `translations`) in `$locale` (default {@see self::locale()}):
     * name, description, the summary's step names and the step names its setup questions quote. `translations` is
     * dropped; a language without text keeps the base text.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function localizeEntry(array $entry, ?string $locale = null): array
    {
        $text = self::display($entry['translations'] ?? null)[$locale ?? self::locale()] ?? null;
        unset($entry['translations']);
        if ($text === null) {
            return $entry;
        }
        $entry['name'] = $text['name'] ?? $entry['name'];
        if (isset($text['description'])) {
            $entry['description'] = $text['description'];
        }
        $names = $text['steps'] ?? [];
        if ($names !== [] && is_array($entry['summary'] ?? null)) {
            foreach (['steps' => 'name', 'setup_questions' => 'step_name'] as $list => $field) {
                foreach (is_array($entry['summary'][$list] ?? null) ? $entry['summary'][$list] : [] as $i => $row) {
                    $code = is_array($row) && is_string($row['step_code'] ?? null) ? $row['step_code'] : null;
                    if ($code !== null && isset($names[$code])) {
                        $entry['summary'][$list][$i][$field] = $names[$code];
                    }
                }
            }
        }

        return $entry;
    }

    /** The template name in `$locale` (default {@see self::locale()}), else the bundle's name. */
    public static function name(WorkflowBundle $bundle, ?string $locale = null): string
    {
        return self::normal($bundle->package['workflow'][self::KEY] ?? null)[$locale ?? self::locale()]['name'] ?? $bundle->name;
    }

    /**
     * Every name of the template: the bundle's name, its workflow row's name and each language's name.
     *
     * @return list<string>
     */
    public static function names(WorkflowBundle $bundle): array
    {
        $names = [$bundle->name, (string) ($bundle->package['workflow']['name'] ?? '')];
        foreach (self::normal($bundle->package['workflow'][self::KEY] ?? null) ?? [] as $text) {
            $names[] = (string) ($text['name'] ?? '');
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
    }

    /**
     * The package with its workflow row in `$locale` (default {@see self::locale()}) and without `translations`, the
     * checksum recomputed (the bundle signature already vouched for the text). A package without translations is returned
     * as it is. Config keys a step does not have, and reserved keys (`_v`), are never added or replaced.
     *
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public static function localizePackage(array $package, ?string $locale = null): array
    {
        $row = $package['workflow'] ?? null;
        if (!is_array($row) || !array_key_exists(self::KEY, $row)) {
            return $package;
        }
        $text = self::normal($row[self::KEY])[$locale ?? self::locale()] ?? null;
        unset($row[self::KEY]);
        if ($text !== null) {
            if (isset($text['name'])) {
                $row['name'] = $text['name'];
            }
            if (isset($text['description'])) {
                $row['description'] = $text['description'];
            }
            foreach (is_array($row['steps'] ?? null) ? $row['steps'] : [] as $i => $step) {
                $over = is_array($step) ? ($text['steps'][(string) ($step['step_code'] ?? '')] ?? null) : null;
                if ($over === null) {
                    continue;
                }
                if (isset($over['name'])) {
                    $row['steps'][$i]['name'] = $over['name'];
                }
                $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
                $replace = array_filter(
                    array_intersect_key($over['config'] ?? [], $config),
                    static fn (string|int $key): bool => !str_starts_with((string) $key, '_'),
                    ARRAY_FILTER_USE_KEY,
                );
                if ($replace !== []) {
                    $row['steps'][$i]['step_config'] = array_replace($config, $replace);
                }
            }
        }
        $package['workflow'] = $row;
        $package['checksum'] = WorkflowPackageFormat::checksum($package);

        return $package;
    }

    /**
     * One language's text over a template defined in code (the installer commands): project attributes (`name`,
     * `description`) and steps `code => [type, name, config]`, with the same rules as {@see self::localizePackage()}.
     *
     * @param array<string, array<string, mixed>> $texts locale => text in the package form of one language
     * @param array<string, mixed> $project
     * @param array<string, array{0: string, 1: string, 2: array<string, mixed>}> $steps
     * @return array{0: array<string, mixed>, 1: array<string, array{0: string, 1: string, 2: array<string, mixed>}>}
     */
    public static function applyToDefinition(array $texts, string $locale, array $project, array $steps): array
    {
        $text = self::normal($texts)[$locale] ?? null;
        if ($text === null) {
            return [$project, $steps];
        }
        if (isset($text['name'])) {
            $project['name'] = $text['name'];
        }
        if (isset($text['description'])) {
            $project['description'] = $text['description'];
        }
        foreach ($steps as $code => [$type, $name, $config]) {
            $over = $text['steps'][(string) $code] ?? null;
            if ($over === null) {
                continue;
            }
            $replace = array_filter(array_intersect_key($over['config'] ?? [], $config), static fn (string|int $key): bool => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
            $steps[$code] = [$type, $over['name'] ?? $name, array_replace($config, $replace)];
        }

        return [$project, $steps];
    }

    /**
     * One language's text, from a local workflow row in that language whose graph is the base row's: same steps (code,
     * type, start flag, config keys), same connections, same trigger and target. Keeps the name, the description, each
     * differing step name and each differing top-level config value.
     *
     * @param array<string, mixed> $base       exported workflow row of the template
     * @param array<string, mixed> $translated exported workflow row of the same workflow in another language
     * @return array<string, mixed>
     *
     * @throws WorkflowPackageException when the two workflows differ in more than text
     */
    public static function derive(array $base, array $translated): array
    {
        foreach (['trigger_event', 'target_entity_type'] as $field) {
            if (($base[$field] ?? null) !== ($translated[$field] ?? null)) {
                throw new WorkflowPackageException("The translated workflow must have the same {$field}.");
            }
        }
        $baseSteps = self::stepsByCode($base);
        $otherSteps = self::stepsByCode($translated);
        if (array_keys($baseSteps) !== array_keys($otherSteps)) {
            throw new WorkflowPackageException('The translated workflow must have the same step codes.');
        }
        if (self::edges($base) !== self::edges($translated)) {
            throw new WorkflowPackageException('The translated workflow must have the same connections.');
        }

        $steps = [];
        foreach ($baseSteps as $code => $step) {
            $other = $otherSteps[$code];
            if (($step['step_type'] ?? null) !== ($other['step_type'] ?? null) || (bool) ($step['is_start'] ?? false) !== (bool) ($other['is_start'] ?? false)) {
                throw new WorkflowPackageException("Step {$code} of the translated workflow must have the same type and start flag.");
            }
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            $otherConfig = is_array($other['step_config'] ?? null) ? $other['step_config'] : [];
            $keys = array_map('strval', array_keys($config));
            $otherKeys = array_map('strval', array_keys($otherConfig));
            sort($keys);
            sort($otherKeys);
            if ($keys !== $otherKeys) {
                throw new WorkflowPackageException("Step {$code} of the translated workflow must have the same settings.");
            }
            $item = [];
            $name = self::string($other['name'] ?? null, WorkflowBundle::NAME_MAX);
            if ($name !== null && $name !== ($step['name'] ?? null)) {
                $item['name'] = $name;
            }
            $changed = [];
            foreach ($config as $key => $value) {
                if ($value === $otherConfig[$key]) {
                    continue;
                }
                if (str_starts_with((string) $key, '_')) {
                    throw new WorkflowPackageException("Step {$code} of the translated workflow must have the same {$key}.");
                }
                $changed[$key] = $otherConfig[$key];
            }
            if ($changed !== []) {
                $item['config'] = $changed;
            }
            if ($item !== []) {
                $steps[$code] = $item;
            }
        }

        $text = [];
        if (($name = self::string($translated['name'] ?? null, WorkflowBundle::NAME_MAX)) !== null) {
            $text['name'] = $name;
        }
        if (($description = self::string($translated['description'] ?? null, WorkflowBundle::DESCRIPTION_MAX)) !== null) {
            $text['description'] = $description;
        }
        if ($steps !== []) {
            $text['steps'] = $steps;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array<string, mixed>>
     */
    private static function stepsByCode(array $row): array
    {
        $steps = [];
        foreach (is_array($row['steps'] ?? null) ? $row['steps'] : [] as $step) {
            if (is_array($step) && is_string($step['step_code'] ?? null)) {
                $steps[$step['step_code']] = $step;
            }
        }
        ksort($steps, SORT_STRING);

        return $steps;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function edges(array $row): array
    {
        $edges = [];
        foreach (is_array($row['connections'] ?? null) ? $row['connections'] : [] as $c) {
            if (is_array($c)) {
                $edges[] = json_encode([$c['from'] ?? null, $c['to'] ?? null, $c['condition'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        sort($edges);

        return $edges;
    }

    private static function string(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
