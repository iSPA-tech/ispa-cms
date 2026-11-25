<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use Cms\Core\Services\ConfigPackage\PackageFormat;

/**
 * Workflow package format v1 (`ispa-workflow-package`): one canonical JSON document carrying ONE workflow.
 *
 * ```
 * { format: "ispa-workflow-package", version: 1, created_at,
 *   workflow: <WorkflowsSection row without is_active; site-local references replaced by `$bind:<name>`>,
 *   manifest: <derived from the workflow by WorkflowPackageManifest, never hand-written>,
 *   sample?: { root: {...} },          // optional record used by the dry run
 *   checksum: "sha256:<hex of canonical JSON of {workflow, manifest, sample}>" }
 * ```
 *
 * The workflow row shape, its validation and its apply are those of the config package `workflows` section
 * (WorkflowsSection); this format only adds the envelope, the manifest and the bindings. The checksum is integrity
 * only: packages are not signed in v1 (signing is roadmap phase C).
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
final class WorkflowPackageFormat
{
    public const FORMAT = 'ispa-workflow-package';

    public const VERSION = 1;

    public const TOP_LEVEL_KEYS = ['format', 'version', 'created_at', 'workflow', 'manifest', 'sample', 'checksum'];

    public const REQUIRED_KEYS = ['format', 'version', 'workflow', 'manifest', 'checksum'];

    /** Largest package accepted (bytes of JSON). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Checksum of the signed-over part of a package.
     *
     * @param array<string, mixed> $package
     */
    public static function checksum(array $package): string
    {
        return PackageFormat::checksum([
            'workflow' => $package['workflow'] ?? null,
            'manifest' => $package['manifest'] ?? null,
            'sample' => $package['sample'] ?? null,
        ]);
    }

    /**
     * Canonical JSON of a whole package (sorted keys, stable output).
     *
     * @param array<string, mixed> $package
     */
    public static function encode(array $package): string
    {
        return PackageFormat::canonicalJson($package);
    }
}
