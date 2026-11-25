<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use Cms\Core\Services\Mail\ManagedEmailTemplates;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Illuminate\Support\Facades\Cache;

/**
 * Installs a workflow package: add-only, always inactive, never scheduled.
 *
 * Refuses (WorkflowPackageException with the inspector report) when the package has any problem, a binding is
 * unresolved, or the target slug exists — also as a deleted workflow. `$asSlug` installs it as a copy under a new slug
 * (`$asName`, optional, gives the copy its own name);
 * an existing workflow, locally modified or not, is never touched. The resolved row is created through
 * WorkflowsSection::apply(), the same path and checks as a config package import (catalog, permissions of the
 * installing user, taint lint, callable allowlist); WorkflowsSection creates new projects with `is_active = false` and
 * only sets name, description, tags, trigger and target, so no schedule is set. The install is audited
 * (`workflow.package_installed`, with the package checksum).
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
class WorkflowPackageInstaller
{
    public const AUDIT_ACTION = 'workflow.package_installed';

    public const MAX_NAME_LENGTH = 190;

    public function __construct(
        protected WorkflowPackageInspector $inspector = new WorkflowPackageInspector(),
        protected WorkflowPackageBindings $bindings = new WorkflowPackageBindings(),
    ) {
    }

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $bindingValues binding name → local value
     * @return array{slug: string, name: string, is_active: bool, report: array<string, mixed>}
     *
     * @throws WorkflowPackageException
     */
    public function install(array $package, User $user, array $bindingValues = [], ?string $asSlug = null, ?string $asName = null): array
    {
        $report = $this->inspector->inspect($package, $user, $bindingValues, $asSlug, null, false);
        if (!$report['installable']) {
            throw new WorkflowPackageException('The workflow package cannot be installed.', $report);
        }

        $slug = (string) $report['target_slug'];
        $declared = array_values(array_filter((array) ($package['manifest']['bindings'] ?? []), 'is_array'));
        $resolved = $this->bindings->resolve($declared, $bindingValues)['resolved'];
        $row = $this->bindings->apply($package['workflow'], $declared, $resolved);
        $row['slug'] = $slug;
        unset($row['is_active']);
        // A copy may get its own name so it does not look like the original in the list.
        $name = trim((string) $asName);
        if ($name !== '') {
            $row['name'] = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        }

        $lock = Cache::lock('workflow-package-install:' . $slug, 30);
        if (!$lock->get()) {
            throw new WorkflowPackageException("Another install of {$slug} is running.", $report);
        }

        try {
            $section = new WorkflowsSection();
            $userId = (int) $user->getKey();
            $diff = $section->diff([$row], new DiffContext($userId))->items[0] ?? [];
            if (($diff['action'] ?? null) !== 'create') {
                // Add-only: an existing workflow (created since the check) or a refused graph is never applied.
                throw new WorkflowPackageException('The workflow package cannot be installed: ' . ($diff['reason'] ?? "slug {$slug} exists") . '.', $report);
            }

            $result = $section->apply([$row], new ApplyContext([$slug => true], $userId));
            if (($result->items[0]['outcome'] ?? null) !== 'created') {
                throw new WorkflowPackageException('The workflow package cannot be installed: ' . ($result->items[0]['reason'] ?? 'refused') . '.', $report);
            }
        } finally {
            $lock->release();
        }

        $project = WorkflowProject::query()->where('slug', $slug)->firstOrFail();
        // Default rows of the managed e-mail templates its `email_send` steps use (never overwrites an existing key).
        $templates = app(ManagedEmailTemplates::class);
        $templates->ensureDefaults($templates->keysUsedBy((array) ($row['steps'] ?? [])));
        event(new AuditLogEvent(self::AUDIT_ACTION, [
            'slug' => $slug,
            'source_slug' => $report['source_slug'],
            'checksum' => $package['checksum'] ?? null,
            'bindings' => array_keys($resolved),
        ], WorkflowProject::class, (int) $project->getKey(), 'fa fa-download', 'warning', $userId));

        return ['slug' => $slug, 'name' => (string) $project->name, 'is_active' => (bool) $project->is_active, 'report' => $report];
    }
}
