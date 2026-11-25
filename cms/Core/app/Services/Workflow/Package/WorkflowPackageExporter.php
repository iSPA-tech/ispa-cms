<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;

/**
 * Builds the workflow package of one workflow (by slug).
 *
 * The row is the config package `workflows` row (WorkflowsSection::export(), which already skips a workflow holding a
 * secret-looking key or a graph without step codes) without `is_active`; site-local references become `$bind:`
 * placeholders (WorkflowPackageBindings) and the manifest is derived from the result. Callers run the inspector on the
 * package before handing it out.
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
class WorkflowPackageExporter
{
    public function __construct(
        protected WorkflowPackageBindings $bindings = new WorkflowPackageBindings(),
        protected WorkflowPackageManifest $manifests = new WorkflowPackageManifest(),
    ) {
    }

    /**
     * @param array<string, mixed>|null $sample `{root: {...}}` for the dry run on the receiving site
     * @return array<string, mixed>
     *
     * @throws WorkflowPackageException When the workflow does not exist or cannot be exported.
     */
    public function export(string $slug, ?array $sample = null): array
    {
        $slug = trim($slug);
        if (!WorkflowProject::query()->where('slug', $slug)->exists()) {
            throw new WorkflowPackageException("No workflow with slug {$slug}.");
        }

        $row = null;
        foreach ((new WorkflowsSection())->export(new ExportContext())->rows as $candidate) {
            if (($candidate['slug'] ?? null) === $slug) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            throw new WorkflowPackageException("Workflow {$slug} cannot be exported: it holds a secret-looking value or a step without a step code.");
        }

        unset($row['is_active']);
        $extracted = $this->bindings->extract($row);

        $package = [
            'format' => WorkflowPackageFormat::FORMAT,
            'version' => WorkflowPackageFormat::VERSION,
            'created_at' => now()->toIso8601String(),
            'workflow' => $extracted['row'],
            'manifest' => $this->manifests->derive($extracted['row'], $extracted['bindings']),
        ];
        if ($sample !== null) {
            $package['sample'] = $sample;
        }
        $package['checksum'] = WorkflowPackageFormat::checksum($package);

        return $package;
    }
}
