<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageExporter;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;

/**
 * Builds the unsigned hub bundle ({@see WorkflowBundle}) of one local workflow.
 *
 * The package is the workflow package v1 of WorkflowPackageExporter; it must pass the package inspector here and carry
 * no binding: a template never names this site's users, records, profiles or credentials — leave those fields empty so
 * the installing site answers them as setup questions. `requires` and the summary are derived from the package and the
 * project's setup questions ({@see WorkflowBundleSummary}); name and description default to the project's.
 */
class WorkflowBundleBuilder
{
    public function __construct(
        protected WorkflowPackageExporter $exporter,
        protected WorkflowPackageInspector $inspector,
        protected WorkflowSetupService $setup,
    ) {
    }

    /**
     * @param array<string, string>|null $notes `{vi?, en?}`
     *
     * @throws WorkflowPackageException when the workflow cannot become a template (message names the reason)
     */
    public function build(string $slug, string $key, int $version, string $publisher, ?string $name = null, ?string $description = null, ?string $group = null, ?array $notes = null): WorkflowBundle
    {
        $project = WorkflowProject::query()->where('slug', trim($slug))->first();
        if ($project === null) {
            throw new WorkflowPackageException("No workflow with slug {$slug}.");
        }
        if (preg_match(WorkflowBundle::KEY_PATTERN, $key) !== 1) {
            throw new WorkflowPackageException('--key must be letters, digits, dot, dash or underscore (for example ispa.church-story).');
        }

        $package = $this->exporter->export($project->slug);
        // Not part of the package checksum; dropped so equal content builds equal bytes.
        unset($package['created_at']);
        $report = $this->inspector->inspect($package, null, [], null, null, false);
        if (!$report['valid']) {
            throw new WorkflowPackageException('The workflow cannot be shared as it is: ' . implode('; ', array_column($report['problems'], 'message')), $report);
        }
        $bindings = (array) ($package['manifest']['bindings'] ?? []);
        if ($bindings !== []) {
            $names = array_map(static fn (array $b): string => ($b['name'] ?? '?') . ' (' . ($b['kind'] ?? '?') . ', steps ' . implode(', ', (array) ($b['steps'] ?? [])) . ')', $bindings);
            throw new WorkflowPackageException('A hub template cannot name this site\'s users, records, profiles or credentials; clear them so each site answers them in setup: ' . implode('; ', $names));
        }

        $summary = WorkflowBundleSummary::derive($package, $this->setup->describe($project)['questions']);
        $name = trim((string) ($name ?? '')) !== '' ? trim((string) $name) : (string) $project->name;
        $description = $description !== null && trim($description) !== '' ? trim($description) : (is_string($project->description) && trim($project->description) !== '' ? trim($project->description) : null);

        return WorkflowBundle::create(
            key: $key,
            version: $version,
            publisher: $publisher,
            name: mb_substr($name, 0, WorkflowBundle::NAME_MAX),
            description: $description === null ? null : mb_substr($description, 0, WorkflowBundle::DESCRIPTION_MAX),
            group: $group !== null && trim($group) !== '' ? trim($group) : null,
            notes: $notes,
            summary: $summary,
            package: $package,
        );
    }
}
