<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

/**
 * What applying a starter pack would do on this site right now (computed without writing anything).
 *
 * Module states: `enabled` (present and active, nothing to do), `to_enable` (present but disabled),
 * `to_install` (absent, published on the hub). `installed` is part of the vocabulary for callers that report a
 * module that is present but whose active flag is not known; the planner itself emits the other three.
 * Blockers mean the plan cannot be applied; warnings are informational.
 */
final readonly class StarterPackPlan
{
    public const STATE_INSTALLED = 'installed';
    public const STATE_ENABLED = 'enabled';
    public const STATE_TO_INSTALL = 'to_install';
    public const STATE_TO_ENABLE = 'to_enable';

    /**
     * @param string $packKey Pack key.
     * @param string $packVersion Pack version.
     * @param list<array{name: string, constraint: string, version: ?string, state: string, irreversible: list<string>, third_party?: ?array<string, mixed>}> $modules One entry per pack module.
     * @param array<string, mixed>|null $config Configuration package dry-run summary (`status`, `sections`, `warnings`, `error`, `errors`), or null when the pack has no configuration.
     * @param array{created: list<string>, skipped: list<array{name: string, reason: string}>, missing_permissions: array<string, list<string>>}|null $roles Role plan, or null.
     * @param array{created: list<string>, skipped: list<array{slug: string, reason: string}>}|null $workflows Workflow plan, or null.
     * @param list<array{module: string, importer: string, file: string, counts: array<string, int>|null}> $content Counts per importer; null counts mean the importer is not available until its module is enabled.
     * @param list<string> $blockers Reasons the plan cannot be applied.
     * @param list<string> $warnings Informational notes.
     */
    public function __construct(
        public string $packKey,
        public string $packVersion,
        public array $modules,
        public ?array $config,
        public ?array $roles,
        public ?array $workflows,
        public array $content,
        public array $blockers,
        public array $warnings,
    ) {
    }

    /**
     * Whether the plan has no blockers.
     */
    public function canApply(): bool
    {
        return $this->blockers === [];
    }

    /**
     * Whether applying would change anything (false means the pack is already fully applied). Configuration counts only additions, because a pack never overwrites existing values.
     */
    public function hasChanges(): bool
    {
        foreach ($this->modules as $module) {
            if ($module['state'] === self::STATE_TO_INSTALL || $module['state'] === self::STATE_TO_ENABLE) {
                return true;
            }
        }

        foreach ((array) ($this->config['sections'] ?? []) as $section) {
            $counts = (array) ($section['counts'] ?? []);
            // A pack only adds missing configuration (updates are never applied), as the applier's isEmpty() does.
            if ((int) ($counts['create'] ?? 0) > 0) {
                return true;
            }
        }

        if (($this->roles['created'] ?? []) !== [] || ($this->workflows['created'] ?? []) !== []) {
            return true;
        }

        foreach ($this->content as $entry) {
            if ($entry['counts'] === null || array_sum($entry['counts']) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plain array for the admin API and the command.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pack_key' => $this->packKey,
            'pack_version' => $this->packVersion,
            'can_apply' => $this->canApply(),
            'has_changes' => $this->hasChanges(),
            'modules' => $this->modules,
            'config' => $this->config,
            'roles' => $this->roles,
            'workflows' => $this->workflows,
            'content' => $this->content,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
        ];
    }
}
