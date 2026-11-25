<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use InvalidArgumentException;
use JsonException;

/**
 * One signed workflow template version distributed through the hub (roadmap phase C).
 *
 * ```
 * { format: "ispa-workflow-bundle", key, version: <int YYYYMMNN>, publisher, name, description, group,
 *   notes: { vi?, en? } | null,                    // what changed in this version
 *   requires: { modules: [..], node_types: { <type>: <config version> } },   // = package manifest
 *   summary: { steps: [{step_code, name, step_type, safety}], safety_classes, outbound_hosts, credential_kinds,
 *              needs_approval, setup_questions: [{kind, step_code?, field?, step_name?}] },
 *   package: <workflow package v1, without bindings>,
 *   content_sha256 }
 * ```
 *
 * `requires` and `summary` are derived by {@see WorkflowBundleSummary} at build time, never typed. The signed bytes are
 * {@see self::toCanonicalJson()} (keys sorted at every level, lists kept in order, the config-package JSON flags).
 * `content_sha256` hashes the same document without `version`, `notes` and itself, so two versions of the same
 * workflow content hash alike.
 */
final readonly class WorkflowBundle
{
    public const FORMAT = 'ispa-workflow-bundle';

    private const TOP_KEYS = ['content_sha256', 'description', 'format', 'group', 'key', 'name', 'notes', 'package', 'publisher', 'requires', 'summary', 'version'];

    private const SUMMARY_KEYS = ['credential_kinds', 'needs_approval', 'outbound_hosts', 'safety_classes', 'setup_questions', 'steps'];

    /** Template key: the widget key shape (`ispa.church-story`), at most 191 chars. */
    public const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/';

    private const PUBLISHER = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    private const GROUP = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    private const SHA256 = '/\A[0-9a-f]{64}\z/';

    public const NAME_MAX = 190;

    public const DESCRIPTION_MAX = 2000;

    public const NOTES_MAX = 1000;

    /** Largest signed bundle (a package is at most WorkflowPackageFormat::MAX_BYTES). */
    public const MAX_BYTES = 4 * 1024 * 1024;

    /**
     * @param array<string, string>|null $notes
     * @param array{modules: list<string>, node_types: array<string, int>} $requires
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $package
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $publisher,
        public string $name,
        public ?string $description,
        public ?string $group,
        public ?array $notes,
        public array $requires,
        public array $summary,
        public array $package,
        public string $contentSha256,
    ) {
    }

    /**
     * Build a bundle and compute its content hash.
     *
     * @param array<string, string>|null $notes
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $package
     */
    public static function create(string $key, int $version, string $publisher, string $name, ?string $description, ?string $group, ?array $notes, array $summary, array $package): self
    {
        $manifest = (array) ($package['manifest'] ?? []);
        $nodeTypes = (array) ($manifest['node_types'] ?? []);
        ksort($nodeTypes);
        $requires = ['modules' => array_values((array) ($manifest['modules'] ?? [])), 'node_types' => $nodeTypes];
        $notes = self::normalNotes($notes);

        $draft = new self($key, $version, $publisher, $name, $description, $group, $notes, $requires, $summary, $package, '');

        return new self($key, $version, $publisher, $name, $description, $group, $notes, $requires, $summary, $package, $draft->computeContentSha256());
    }

    /** `20261201` → `2026.12.01`. */
    public static function versionLabel(int $version): string
    {
        $text = (string) $version;

        return strlen($text) === 8 ? substr($text, 0, 4) . '.' . substr($text, 4, 2) . '.' . substr($text, 6, 2) : $text;
    }

    /**
     * `2026.12.01`, `2026.12.1` or `20261201` → 20261201; null for anything else.
     */
    public static function parseVersion(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/\A(\d{4})\.(\d{1,2})\.(\d{1,2})\z/', $value, $m) === 1) {
            $month = (int) $m[2];
            $n = (int) $m[3];

            return $month >= 1 && $month <= 12 && $n >= 1 ? (int) $m[1] * 10000 + $month * 100 + $n : null;
        }

        return preg_match('/\A[1-9]\d{0,9}\z/', $value) === 1 && (int) $value <= 2147483647 ? (int) $value : null;
    }

    /** The exact bytes that are signed. */
    public function toCanonicalJson(): string
    {
        return PackageFormat::canonicalJson($this->document() + ['content_sha256' => $this->contentSha256]);
    }

    /** sha256 of the canonical document without version, notes and the stored hash. */
    public function computeContentSha256(): string
    {
        $doc = $this->document();
        unset($doc['version'], $doc['notes']);

        return hash('sha256', PackageFormat::canonicalJson($doc));
    }

    /**
     * Strictly parse canonical bundle JSON. Does not check the canonical form or hashes; {@see WorkflowBundleVerifier} does.
     *
     * @throws InvalidArgumentException when the document does not have the bundle shape
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('bundle is too large');
        }
        try {
            $doc = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('bundle is not valid JSON');
        }
        if (!is_array($doc) || array_is_list($doc)) {
            throw new InvalidArgumentException('bundle must be a JSON object');
        }
        $keys = array_keys($doc);
        sort($keys);
        if ($keys !== self::TOP_KEYS) {
            throw new InvalidArgumentException('bundle must have exactly the keys ' . implode(', ', self::TOP_KEYS));
        }
        if ($doc['format'] !== self::FORMAT) {
            throw new InvalidArgumentException('format must be ' . self::FORMAT);
        }
        self::match($doc['key'], self::KEY_PATTERN, 'key');
        self::match($doc['publisher'], self::PUBLISHER, 'publisher');
        self::match($doc['content_sha256'], self::SHA256, 'content_sha256');
        if ($doc['group'] !== null) {
            self::match($doc['group'], self::GROUP, 'group');
        }
        if (!is_int($doc['version']) || $doc['version'] < 1) {
            throw new InvalidArgumentException('version must be a positive integer');
        }
        if (!is_string($doc['name']) || trim($doc['name']) === '' || mb_strlen($doc['name']) > self::NAME_MAX) {
            throw new InvalidArgumentException('name must be a non-empty string of at most ' . self::NAME_MAX . ' characters');
        }
        if ($doc['description'] !== null && (!is_string($doc['description']) || mb_strlen($doc['description']) > self::DESCRIPTION_MAX)) {
            throw new InvalidArgumentException('description must be null or a string of at most ' . self::DESCRIPTION_MAX . ' characters');
        }
        if ($doc['notes'] !== null && (!is_array($doc['notes']) || array_diff(array_keys($doc['notes']), ['en', 'vi']) !== [] || self::normalNotes($doc['notes']) !== $doc['notes'])) {
            throw new InvalidArgumentException('notes must be null or {vi?, en?} non-empty strings');
        }

        $requires = $doc['requires'];
        if (!is_array($requires) || array_keys($requires) !== ['modules', 'node_types']
            || !is_array($requires['modules']) || !array_is_list($requires['modules'])
            || array_filter($requires['modules'], 'is_string') !== $requires['modules'] || !is_array($requires['node_types'])) {
            throw new InvalidArgumentException('requires must be {modules: [..], node_types: {..}}');
        }
        foreach ($requires['node_types'] as $type => $version) {
            if (!is_string($type) || !is_int($version)) {
                throw new InvalidArgumentException('requires.node_types must map node types to integer versions');
            }
        }

        $summary = $doc['summary'];
        if (!is_array($summary)) {
            throw new InvalidArgumentException('summary must be an object');
        }
        $summaryKeys = array_keys($summary);
        sort($summaryKeys);
        if ($summaryKeys !== self::SUMMARY_KEYS || !is_bool($summary['needs_approval'])) {
            throw new InvalidArgumentException('summary must have exactly the keys ' . implode(', ', self::SUMMARY_KEYS));
        }
        foreach (['steps', 'setup_questions', 'safety_classes', 'outbound_hosts', 'credential_kinds'] as $list) {
            if (!is_array($summary[$list]) || !array_is_list($summary[$list])) {
                throw new InvalidArgumentException("summary.{$list} must be a list");
            }
        }
        foreach ($summary['steps'] as $step) {
            if (!is_array($step) || !is_string($step['step_code'] ?? null) || !is_string($step['step_type'] ?? null) || !is_array($step['safety'] ?? null)) {
                throw new InvalidArgumentException('summary.steps rows need step_code, step_type and safety');
            }
        }
        foreach ($summary['setup_questions'] as $question) {
            if (!is_array($question) || !is_string($question['kind'] ?? null)) {
                throw new InvalidArgumentException('summary.setup_questions rows need a kind');
            }
        }
        if (!is_array($doc['package']) || ($doc['package']['format'] ?? null) !== WorkflowPackageFormat::FORMAT) {
            throw new InvalidArgumentException('package must be a workflow package');
        }

        return new self($doc['key'], $doc['version'], $doc['publisher'], $doc['name'], $doc['description'], $doc['group'], $doc['notes'], $requires, $summary, $doc['package'], $doc['content_sha256']);
    }

    /**
     * Notes in normal form: only `vi` / `en` (other keys dropped), trimmed, non-empty, cut to NOTES_MAX; null when none.
     *
     * @param array<array-key, mixed>|null $notes
     * @return array<string, string>|null
     */
    public static function normalNotes(?array $notes): ?array
    {
        $out = [];
        foreach (['en', 'vi'] as $lang) {
            $text = $notes[$lang] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $out[$lang] = mb_substr(trim($text), 0, self::NOTES_MAX);
            }
        }
        return $out === [] ? null : $out;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'format' => self::FORMAT,
            'key' => $this->key,
            'version' => $this->version,
            'publisher' => $this->publisher,
            'name' => $this->name,
            'description' => $this->description,
            'group' => $this->group,
            'notes' => $this->notes,
            'requires' => $this->requires,
            'summary' => $this->summary,
            'package' => $this->package,
        ];
    }

    private static function match(mixed $value, string $pattern, string $field): void
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($field . ' has an invalid value');
        }
    }
}
