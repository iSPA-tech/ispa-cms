<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Support\Widget\WidgetNameTranslations;
use InvalidArgumentException;
use JsonException;

/**
 * One signed widget version distributed through the widget hub: the widget row, every design (Blade, CSS, query,
 * project list, optional preview image), the widget's input definitions, local assets and the resolvers it needs.
 *
 * The signed bytes are {@see self::toCanonicalJson()}: keys sorted at every level, lists in a fixed order (designs by
 * theme_key, inputs by input_key, assets by path), `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 * `contentSha256` hashes the same form without `version`, preview images and the hash itself, so two versions with
 * the same widget content hash alike.
 *
 * Inputs belong to the widget (`widget_inputs.widget_id`), not to a design; they are keyed by `input_key`.
 *
 * Per-language names: the widget may carry `name_translations` and
 * `description_translations`, each design `name_translations`, as `{en?, vi?}` maps in
 * {@see WidgetNameTranslations} normal form. They are content (in the canonical JSON and `contentSha256`), and a
 * bundle without them has no such key at all, so every bundle built before they existed keeps its exact bytes and hash.
 */
final readonly class WidgetBundle
{
    /** Highest bundle renderer version this CMS can render. */
    public const WIDGET_RENDERER_VERSION = 1;

    private const TOP_KEYS = ['assets', 'content_sha256', 'designs', 'inputs', 'publisher', 'requires', 'version', 'widget', 'widget_key'];
    private const WIDGET_KEYS = ['description', 'js', 'name'];
    private const DESIGN_KEYS = ['content', 'css', 'name', 'preview_png_base64', 'project_name', 'query', 'theme_key'];

    /** Optional widget keys: translations, present only when non-empty. */
    private const WIDGET_TRANSLATION_KEYS = ['description_translations' => WidgetNameTranslations::DESCRIPTION_MAX, 'name_translations' => WidgetNameTranslations::NAME_MAX];

    /** Optional design keys: translations, present only when non-empty. */
    private const DESIGN_TRANSLATION_KEYS = ['name_translations' => WidgetNameTranslations::NAME_MAX];
    private const INPUT_KEYS = ['default_value', 'input_key', 'name', 'options', 'type'];
    private const ASSET_KEYS = ['base64', 'path', 'sha256'];

    /**
     * widget_key, theme_key, input_key: the shapes existing keys use (`exampleshop.hero-slider`, `site.<key>-<n>`,
     * `legacy.widget-12`, `manual.<uuid>`, `manual-<uuid>`, `s1_url`), at most 191 chars (the indexed column length).
     */
    private const IDENTIFIER = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/';

    /** Publisher: a catalog name or project folder (`iSPA`, `ExampleShop`), at most 64 chars (column length). */
    private const PUBLISHER = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    /** Relative asset path: segments that start with a letter or digit, joined by '/'; no dot, empty or hidden segment. */
    private const ASSET_PATH = '/\A[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*\z/';

    /** Static asset types a bundle may carry. */
    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf', 'json'];

    private const SHA256 = '/\A[0-9a-f]{64}\z/';

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Largest decoded preview image. */
    public const PREVIEW_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param array{name: string, description: ?string, js: ?string, name_translations?: array<string, string>, description_translations?: array<string, string>} $widget
     * @param list<array{theme_key: string, name: ?string, content: string, css: ?string, query: mixed, project_name: mixed, preview_png_base64: ?string}> $designs
     * @param list<array{input_key: string, name: ?string, type: ?string, default_value: ?string, options: ?string}> $inputs
     * @param list<array{path: string, sha256: string, base64: string}> $assets
     * @param array{renderer: int, resolvers: array<string, int>} $requires
     */
    public function __construct(
        public string $widgetKey,
        public int $version,
        public string $publisher,
        public array $widget,
        public array $designs,
        public array $inputs,
        public array $assets,
        public array $requires,
        public string $contentSha256,
    ) {
    }

    /**
     * Build a bundle and compute its content hash; lists are put in canonical order.
     */
    public static function create(
        string $widgetKey,
        int $version,
        string $publisher,
        array $widget,
        array $designs,
        array $inputs,
        array $assets,
        array $requires,
    ): self {
        $widget = self::withTranslations($widget, self::WIDGET_TRANSLATION_KEYS);
        $designs = array_map(static fn (array $d): array => self::withTranslations($d, self::DESIGN_TRANSLATION_KEYS), $designs);
        $designs = self::sortedBy($designs, 'theme_key');
        $inputs = self::sortedBy($inputs, 'input_key');
        $assets = self::sortedBy($assets, 'path');
        $resolvers = $requires['resolvers'] ?? [];
        ksort($resolvers);
        $requires = ['renderer' => (int) ($requires['renderer'] ?? self::WIDGET_RENDERER_VERSION), 'resolvers' => $resolvers];

        $bundle = new self($widgetKey, $version, $publisher, $widget, $designs, $inputs, $assets, $requires, '');

        return new self($widgetKey, $version, $publisher, $widget, $designs, $inputs, $assets, $requires, $bundle->computeContentSha256());
    }

    /**
     * The exact bytes that are signed.
     */
    public function toCanonicalJson(): string
    {
        return self::encode($this->document(true) + ['content_sha256' => $this->contentSha256]);
    }

    /**
     * sha256 of the canonical document without version, preview images and the stored hash.
     */
    public function computeContentSha256(): string
    {
        $doc = $this->document(false);
        unset($doc['version']);

        return hash('sha256', self::encode($doc));
    }

    /**
     * Strictly parse canonical bundle JSON. Does not check the hash or assets; {@see WidgetBundleVerifier} does.
     *
     * @throws InvalidArgumentException when the document does not have the bundle shape
     */
    public static function fromCanonicalJson(string $json): self
    {
        try {
            $doc = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('bundle is not valid JSON');
        }
        self::requireKeys($doc, self::TOP_KEYS, 'bundle');

        self::requireMatch($doc['widget_key'], self::IDENTIFIER, 'widget_key');
        self::requireMatch($doc['publisher'], self::PUBLISHER, 'publisher');
        self::requireMatch($doc['content_sha256'], self::SHA256, 'content_sha256');
        if (!is_int($doc['version']) || $doc['version'] < 1) {
            throw new InvalidArgumentException('version must be a positive integer');
        }

        self::requireKeys($doc['widget'], self::WIDGET_KEYS, 'widget', self::WIDGET_TRANSLATION_KEYS);
        if (!is_string($doc['widget']['name'])) {
            throw new InvalidArgumentException('widget name must be a string');
        }
        self::requireTranslations($doc['widget'], self::WIDGET_TRANSLATION_KEYS, 'widget');
        self::requireNullableStrings($doc['widget'], ['description', 'js'], 'widget');

        $previous = null;
        foreach (self::requireList($doc['designs'], 'designs') as $design) {
            self::requireKeys($design, self::DESIGN_KEYS, 'design', self::DESIGN_TRANSLATION_KEYS);
            self::requireMatch($design['theme_key'], self::IDENTIFIER, 'theme_key');
            self::requireTranslations($design, self::DESIGN_TRANSLATION_KEYS, 'design');
            if (!is_string($design['content'])) {
                throw new InvalidArgumentException('design content must be a string');
            }
            self::requireNullableStrings($design, ['name', 'css', 'preview_png_base64'], 'design');
            if ($design['preview_png_base64'] !== null) {
                self::requirePng($design['preview_png_base64']);
            }
            $previous = self::requireAscending($previous, $design['theme_key'], 'theme_key');
        }

        $previous = null;
        foreach (self::requireList($doc['inputs'], 'inputs') as $input) {
            self::requireKeys($input, self::INPUT_KEYS, 'input');
            self::requireMatch($input['input_key'], self::IDENTIFIER, 'input_key');
            self::requireNullableStrings($input, ['name', 'type', 'default_value', 'options'], 'input');
            $previous = self::requireAscending($previous, $input['input_key'], 'input_key');
        }

        $previous = null;
        foreach (self::requireList($doc['assets'], 'assets') as $asset) {
            self::requireKeys($asset, self::ASSET_KEYS, 'asset');
            self::requireMatch($asset['path'], self::ASSET_PATH, 'asset path');
            if (!in_array(strtolower(pathinfo($asset['path'], PATHINFO_EXTENSION)), self::ASSET_EXTENSIONS, true)) {
                throw new InvalidArgumentException('asset type is not allowed');
            }
            self::requireMatch($asset['sha256'], self::SHA256, 'asset sha256');
            if (!is_string($asset['base64'])) {
                throw new InvalidArgumentException('asset base64 must be a string');
            }
            $previous = self::requireAscending($previous, $asset['path'], 'asset path');
        }

        self::requireKeys($doc['requires'], ['renderer', 'resolvers'], 'requires');
        if (!is_int($doc['requires']['renderer']) || !is_array($doc['requires']['resolvers'])) {
            throw new InvalidArgumentException('requires must have an integer renderer and a resolver map');
        }
        foreach ($doc['requires']['resolvers'] as $key => $version) {
            if (!is_string($key) || !is_int($version)) {
                throw new InvalidArgumentException('resolvers must map keys to integer versions');
            }
        }

        return new self(
            $doc['widget_key'],
            $doc['version'],
            $doc['publisher'],
            $doc['widget'],
            $doc['designs'],
            $doc['inputs'],
            $doc['assets'],
            $doc['requires'],
            $doc['content_sha256'],
        );
    }

    /**
     * A widget or design row without its translation keys: the switch-over compare ({@see
     * \Cms\Core\Console\Commands\WidgetHubAdoptCommand}) ignores names given per language.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function withoutTranslations(array $row): array
    {
        unset($row['name_translations'], $row['description_translations']);

        return $row;
    }

    /**
     * `$row` with each translation key in normal form, or without the key when there is nothing to carry.
     *
     * @param array<string, mixed> $row
     * @param array<string, int> $keys key => longest value
     * @return array<string, mixed>
     */
    private static function withTranslations(array $row, array $keys): array
    {
        foreach (array_keys($keys) as $key) {
            $normal = WidgetNameTranslations::normalize($row[$key] ?? null);
            if ($normal === null) {
                unset($row[$key]);
            } else {
                $row[$key] = $normal;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $keys key => longest value
     */
    private static function requireTranslations(array $row, array $keys, string $what): void
    {
        foreach ($keys as $key => $max) {
            if (array_key_exists($key, $row) && ($problem = WidgetNameTranslations::problem($row[$key], $max)) !== null) {
                throw new InvalidArgumentException($what . ' ' . $key . ' ' . $problem);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function document(bool $withPreviews): array
    {
        $designs = $this->designs;
        if (!$withPreviews) {
            foreach ($designs as $index => $design) {
                unset($designs[$index]['preview_png_base64']);
            }
        }

        return [
            'widget_key' => $this->widgetKey,
            'version' => $this->version,
            'publisher' => $this->publisher,
            'widget' => $this->widget,
            'designs' => $designs,
            'inputs' => $this->inputs,
            'assets' => $this->assets,
            'requires' => $this->requires,
        ];
    }

    private static function encode(array $doc): string
    {
        return json_encode(self::sortKeys($doc), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function sortedBy(array $rows, string $key): array
    {
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? '')));

        return $rows;
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $optional keys that may also be present
     */
    private static function requireKeys(mixed $value, array $keys, string $what, array $optional = []): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($what . ' must be an object');
        }
        $actual = array_values(array_diff(array_map('strval', array_keys($value)), array_keys($optional)));
        sort($actual);
        if ($actual !== $keys) {
            throw new InvalidArgumentException($what . ' must have exactly ' . implode(', ', $keys)
                . ($optional === [] ? '' : ' (and optionally ' . implode(', ', array_keys($optional)) . ')'));
        }
    }

    private static function requireMatch(mixed $value, string $pattern, string $what): void
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($what . ' is not well formed');
        }
    }

    /**
     * Lists are strictly ascending by key (the order {@see self::create()} writes), which also refuses duplicates.
     */
    private static function requireAscending(?string $previous, string $current, string $what): string
    {
        if ($previous !== null && strcmp($previous, $current) >= 0) {
            throw new InvalidArgumentException($what . ' values must be unique and sorted');
        }

        return $current;
    }

    private static function requirePng(string $base64): void
    {
        if (strlen($base64) > intdiv(self::PREVIEW_MAX_BYTES + 2, 3) * 4) {
            throw new InvalidArgumentException('preview image is too large');
        }
        $raw = base64_decode($base64, true);
        if ($raw === false || !str_starts_with($raw, self::PNG_SIGNATURE)) {
            throw new InvalidArgumentException('preview image must be a base64 PNG');
        }
        if (strlen($raw) > self::PREVIEW_MAX_BYTES) {
            throw new InvalidArgumentException('preview image is too large');
        }
    }

    /**
     * @return list<mixed>
     */
    private static function requireList(mixed $value, string $what): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($what . ' must be a list');
        }

        return $value;
    }

    /**
     * @param list<string> $keys
     */
    private static function requireNullableStrings(array $row, array $keys, string $what): void
    {
        foreach ($keys as $key) {
            if ($row[$key] !== null && !is_string($row[$key])) {
                throw new InvalidArgumentException($what . ' ' . $key . ' must be a string or null');
            }
        }
    }
}
