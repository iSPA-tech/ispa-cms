<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes;

use InvalidArgumentException;

/**
 * One workflow node type: what the designer palette shows, how its config is validated and upgraded, and the safety
 * facts the save/import lint and the runtime rely on.
 *
 * Core nodes are described in `core.workflow_nodes.definitions`; module nodes come from a #[WorkflowNode] attribute on
 * a service method (then `service`/`method` are set and ModuleNodeRunner runs them).
 *
 * @package Cms\Core\Workflows\Nodes
 * @since   2026-11-23
 */
final class NodeDefinition
{
    public const SAFETY_CLASSES = ['read', 'internal_write', 'external_call', 'ai_spend', 'publish'];

    public const APPROVAL_DEFAULTS = ['required', 'on', 'off'];

    /**
     * Safety classes the taint lint protects: they may not consume untrusted data without an approval before them.
     * `external_call` counts: sending AI or outside text to people or services is a release ("chỉ
     * duyệt release"); a node that only fetches from allowlisted sources sets `taintSink: false`.
     */
    public const GUARDED_SAFETY = ['internal_write', 'external_call', 'publish'];

    /** Types a result field (or the whole stored value) may have. */
    public const RESULT_TYPES = ['string', 'number', 'boolean', 'list', 'object', 'datetime'];

    /**
     * Results the designer expands from the step's own config, as the catalog's `dynamic`:
     * - `schema`: the JSON schema in `config.schema` (its `properties`, nested objects and `items` too) describes the
     *   parts: under the result field marked `dynamic: true`, or at the root of `storeAs` when no field is marked.
     * - `model`: the fields of the record type `config.model` (the model schema endpoint): one record at the root of
     *   `storeAs`, or, for `retrieve` with `first` off, a list of such records.
     * - `assignments`: each `config.assignments[].key` is a variable path the step sets (no `storeAs`).
     * - `loop`: `config.itemVar` holds the current element of `config.collectionExpr`, `config.indexVar` its number.
     */
    public const DYNAMIC_KINDS = ['schema', 'model', 'assignments', 'loop'];

    /** @var list<array<string, mixed>>|null Normalised result fields; null when the node declares none. */
    public readonly ?array $result;

    /**
     * @param list<string>          $safety
     * @param array<string, mixed>  $configSchema  JSON-schema subset (JsonSchemaShapeValidator)
     * @param array<string, mixed>  $defaultConfig
     * @param list<string>          $outputs       Named output handles
     * @param list<string>          $bindings      Credential binding kinds (`$bind:` names live in the config)
     * @param class-string<NodeConfigUpgrade>|null $upgrader Moves a config from version N to N+1
     * @param array<string, array<string, list<string>>> $safetyWhen Extra safety classes by config value, e.g.
     *        `['mode' => ['jev' => ['ai_spend']]]` (read by the executor's budget gate through safetyFor())
     * @param list<array<string, mixed>>|null $result Parts of the value stored under `storeAs`:
     *        `[{key, type, label_key, label, item?, dynamic?, values?, values_from?}]` (`key` a dot path under
     *        `storeAs`, `item` the parts of each list element, `label_key` defaults to
     *        `text.workflow.result.<key with . as _>`). Null = undeclared.
     *        `values`: the field always holds one of a known set, `[{value, label, label_key?}]` (`label_key` defaults
     *        to `text.workflow.value.<value>`); the catalog sends `label` in the user's language. The condition
     *        builder offers them as a select.
     *        `values_from`: the set comes from the step's own config key (e.g. `labels` of ai_classify). Each element
     *        of that config value is one value: a string (value and label), an object `{key, label?, description?}`
     *        (value = key; label = label, else description, else key), or, for a map, key => description. For a list
     *        field the values apply to each element.
     * @param string                $resultType    Type of the whole stored value (RESULT_TYPES)
     * @param string|null           $dynamic       Result expanded from the step config (DYNAMIC_KINDS)
     * @param bool                  $taintSink     False only for bookkeeping or draft-only writes nobody outside sees
     *        (e.g. `dedupe`, `helpdesk.save_draft`): the taint lint then does not ask for an approval before the node.
     *        Taint still flows through it, so a guarded sink reading its output still needs one.
     */
    public function __construct(
        public readonly string $type,
        public readonly int $version = 1,
        public readonly string $group = 'other',
        public readonly ?string $labelKey = null,
        public readonly ?string $descriptionKey = null,
        public readonly array $safety = ['read'],
        public readonly string $approvalDefault = 'off',
        public readonly bool $taintOutput = false,
        public readonly ?string $permission = null,
        public readonly array $configSchema = ['type' => 'object'],
        public readonly array $defaultConfig = [],
        public readonly array $outputs = ['main'],
        public readonly array $bindings = [],
        public readonly ?string $upgrader = null,
        public readonly ?string $runner = null,
        public readonly ?string $module = null,
        public readonly ?string $service = null,
        public readonly ?string $method = null,
        public readonly ?string $icon = null,
        public readonly ?string $editor = null,
        public readonly bool $palette = true,
        public readonly array $safetyWhen = [],
        public readonly bool $taintSink = true,
        ?array $result = null,
        public readonly string $resultType = 'object',
        public readonly ?string $dynamic = null,
    ) {
        if ($type === '' || strlen($type) > 64 || !preg_match('/^[A-Za-z0-9_.:\-]+$/', $type)) {
            throw new InvalidArgumentException("Invalid workflow node type [{$type}].");
        }
        if ($version < 1) {
            throw new InvalidArgumentException("Workflow node {$type}: version must be >= 1.");
        }
        if ($safety === [] || array_diff($safety, self::SAFETY_CLASSES) !== []) {
            throw new InvalidArgumentException("Workflow node {$type}: unknown safety class.");
        }
        foreach ($safetyWhen as $byValue) {
            foreach ((array) $byValue as $extra) {
                if (!is_array($extra) || array_diff($extra, self::SAFETY_CLASSES) !== []) {
                    throw new InvalidArgumentException("Workflow node {$type}: unknown safety class in safety_when.");
                }
            }
        }
        if (!in_array($approvalDefault, self::APPROVAL_DEFAULTS, true)) {
            throw new InvalidArgumentException("Workflow node {$type}: unknown approval default [{$approvalDefault}].");
        }
        if (in_array('publish', $safety, true) && $approvalDefault !== 'required') {
            throw new InvalidArgumentException("Workflow node {$type}: a publish node must default to approval required.");
        }
        if ($upgrader !== null && !is_subclass_of($upgrader, NodeConfigUpgrade::class)) {
            throw new InvalidArgumentException("Workflow node {$type}: upgrader must implement NodeConfigUpgrade.");
        }
        if (!in_array($resultType, self::RESULT_TYPES, true)) {
            throw new InvalidArgumentException("Workflow node {$type}: unknown result type [{$resultType}].");
        }
        if ($dynamic !== null && !in_array($dynamic, self::DYNAMIC_KINDS, true)) {
            throw new InvalidArgumentException("Workflow node {$type}: unknown dynamic result kind [{$dynamic}].");
        }
        $this->result = $result === null ? null : self::normalizeResult($type, $result);
    }

    /**
     * Check the result fields and fill each missing `label_key` from the key (`feed.title` → `...result.feed_title`).
     *
     * @param array<mixed> $fields
     * @return list<array<string, mixed>>
     */
    private static function normalizeResult(string $type, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $key)) {
                throw new InvalidArgumentException("Workflow node {$type}: a result field needs a key.");
            }
            if (!in_array($field['type'] ?? null, self::RESULT_TYPES, true)) {
                throw new InvalidArgumentException("Workflow node {$type}: result field {$key} has an unknown type.");
            }
            if (!is_string($field['label'] ?? null) || $field['label'] === '') {
                throw new InvalidArgumentException("Workflow node {$type}: result field {$key} needs an English label.");
            }

            $row = [
                'key' => $key,
                'type' => $field['type'],
                'label_key' => is_string($field['label_key'] ?? null) && $field['label_key'] !== ''
                    ? $field['label_key'] : 'text.workflow.result.' . str_replace('.', '_', $key),
                'label' => $field['label'],
            ];
            if (isset($field['item'])) {
                if (!is_array($field['item']) || $field['type'] !== 'list') {
                    throw new InvalidArgumentException("Workflow node {$type}: result field {$key} has item parts but is not a list.");
                }
                $row['item'] = self::normalizeResult($type, $field['item']);
            }
            if (($field['dynamic'] ?? false) === true) {
                $row['dynamic'] = true;
            }
            if (isset($field['values'])) {
                $row['values'] = self::normalizeValues($type, $key, $field['values']);
            }
            if (isset($field['values_from'])) {
                if (!is_string($field['values_from']) || !preg_match('/^[A-Za-z0-9_]+$/', $field['values_from'])) {
                    throw new InvalidArgumentException("Workflow node {$type}: result field {$key} has an invalid values_from.");
                }
                $row['values_from'] = $field['values_from'];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Check the known values of a result field and fill each missing `label_key` (`text.workflow.value.<value>`).
     *
     * @return list<array{value: string, label_key: string, label: string}>
     */
    private static function normalizeValues(string $type, string $key, mixed $values): array
    {
        if (!is_array($values) || $values === []) {
            throw new InvalidArgumentException("Workflow node {$type}: result field {$key} has an empty values list.");
        }

        $out = [];
        foreach ($values as $row) {
            $value = is_array($row) ? ($row['value'] ?? null) : null;
            if (!is_string($value) || $value === '' || !is_string($row['label'] ?? null) || $row['label'] === '') {
                throw new InvalidArgumentException("Workflow node {$type}: every value of result field {$key} needs a value and an English label.");
            }
            $out[] = [
                'value' => $value,
                'label_key' => is_string($row['label_key'] ?? null) && $row['label_key'] !== ''
                    ? $row['label_key'] : 'text.workflow.value.' . $value,
                'label' => $row['label'],
            ];
        }

        return $out;
    }

    /**
     * Build a definition from a config row (`core.workflow_nodes.definitions.<type>`).
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(string $type, array $row, ?string $runner = null): self
    {
        $list = static fn (mixed $value, array $default): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : $default;

        return new self(
            type: $type,
            version: (int) ($row['version'] ?? 1),
            group: (string) ($row['group'] ?? 'other'),
            labelKey: isset($row['label_key']) ? (string) $row['label_key'] : null,
            descriptionKey: isset($row['description_key']) ? (string) $row['description_key'] : null,
            safety: $list($row['safety'] ?? null, ['read']),
            approvalDefault: (string) ($row['approval_default'] ?? 'off'),
            taintOutput: (bool) ($row['taint_output'] ?? false),
            permission: isset($row['permission']) && $row['permission'] !== '' ? (string) $row['permission'] : null,
            configSchema: is_array($row['config_schema'] ?? null) ? $row['config_schema'] : ['type' => 'object'],
            defaultConfig: is_array($row['default_config'] ?? null) ? $row['default_config'] : [],
            outputs: $list($row['outputs'] ?? null, ['main']),
            bindings: $list($row['bindings'] ?? null, []),
            upgrader: isset($row['upgrader']) ? (string) $row['upgrader'] : null,
            runner: $runner ?? (isset($row['runner']) ? (string) $row['runner'] : null),
            icon: isset($row['icon']) ? (string) $row['icon'] : null,
            editor: isset($row['editor']) ? (string) $row['editor'] : null,
            palette: (bool) ($row['palette'] ?? true),
            safetyWhen: is_array($row['safety_when'] ?? null) ? $row['safety_when'] : [],
            taintSink: (bool) ($row['taint_sink'] ?? true),
            result: is_array($row['result'] ?? null) ? $row['result'] : null,
            resultType: (string) ($row['result_type'] ?? 'object'),
            dynamic: isset($row['dynamic']) ? (string) $row['dynamic'] : null,
        );
    }

    /**
     * Safety classes of a step with this config: the static ones plus any `safety_when` match (config value, or the
     * default config's value when the step leaves it unset).
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function safetyFor(array $config): array
    {
        $safety = $this->safety;
        foreach ($this->safetyWhen as $key => $byValue) {
            $value = $config[$key] ?? $this->defaultConfig[$key] ?? null;
            if (is_scalar($value) && isset($byValue[(string) $value])) {
                $safety = array_merge($safety, (array) $byValue[(string) $value]);
            }
        }

        return array_values(array_unique($safety));
    }

    public function isModuleNode(): bool
    {
        return $this->service !== null && $this->method !== null;
    }

    /** Whether the taint lint must protect this node (it writes, sends out or publishes, and is a taint sink). */
    public function isGuardedSink(): bool
    {
        return $this->taintSink && array_intersect($this->safety, self::GUARDED_SAFETY) !== [];
    }

    /**
     * A config written for this definition's current version, stamped with `_v` when that version is above 1 (a code
     * installer writes configs straight into the database; the stamp lets an exported template declare the version).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function stamped(array $config): array
    {
        return $this->version > 1 && !isset($config['_v']) ? $config + ['_v' => $this->version] : $config;
    }

    /**
     * Upgrade a config stored at version `$from` to this definition's version, one step at a time.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function upgrade(int $from, array $config): array
    {
        if ($from >= $this->version || $this->upgrader === null) {
            return $config;
        }

        /** @var NodeConfigUpgrade $upgrader */
        $upgrader = app($this->upgrader);
        for ($version = max(1, $from); $version < $this->version; $version++) {
            $config = $upgrader->upgrade($version, $config);
        }

        return $config;
    }

    /**
     * Palette / catalog payload.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return [
            'type' => $this->type,
            'version' => $this->version,
            'group' => $this->group,
            'module' => $this->module,
            'label_key' => $this->labelKey,
            'label' => $this->translate($this->labelKey) ?? $this->humanType(),
            'description_key' => $this->descriptionKey,
            'description' => $this->translate($this->descriptionKey),
            'icon' => $this->icon,
            'editor' => $this->editor,
            'config_schema' => $this->configSchema,
            'default_config' => $this->defaultConfig === [] ? new \stdClass() : $this->defaultConfig,
            'outputs' => $this->outputs,
            'safety' => $this->safety,
            'approval_default' => $this->approvalDefault,
            'taint_output' => $this->taintOutput,
            'taint_sink' => $this->taintSink,
            'permission' => $this->permission,
            'bindings' => $this->bindings,
            'result' => $this->result === null ? null : $this->translateResult($this->result),
            'result_type' => $this->resultType,
            'dynamic' => $this->dynamic,
        ];
    }

    /**
     * Result fields with `label` in the user's language (the English label when no translation exists).
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function translateResult(array $fields): array
    {
        return array_map(function (array $field): array {
            $field['label'] = $this->translate($field['label_key']) ?? $field['label'];
            if (isset($field['values'])) {
                $field['values'] = array_map(fn (array $value): array => [
                    'value' => $value['value'],
                    'label_key' => $value['label_key'],
                    'label' => $this->translate($value['label_key']) ?? $value['label'],
                ], $field['values']);
            }
            if (isset($field['item'])) {
                $field['item'] = $this->translateResult($field['item']);
            }

            return $field;
        }, $fields);
    }

    private function translate(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    private function humanType(): string
    {
        $name = str_contains($this->type, '.') ? substr($this->type, strpos($this->type, '.') + 1) : $this->type;

        return ucfirst(str_replace('_', ' ', $name));
    }
}
