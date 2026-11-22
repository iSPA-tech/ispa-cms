<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;

/**
 * Contract of one configuration package section (settings, translations, roles, ...).
 *
 * Adapters are additive by default (see guidelines/config-package-format.md): they never delete, never trust
 * flags carried by the package and never carry secrets.
 */
interface SectionAdapter
{
    /**
     * Section key as used in the package `sections` object (for example `settings`).
     */
    public function key(): string;

    /**
     * Export the section rows from this site.
     */
    public function export(ExportContext $c): SectionExport;

    /**
     * Compare incoming rows with this site without changing anything.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function diff(array $rows, DiffContext $c): SectionDiff;

    /**
     * Apply the chosen incoming rows to this site.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult;

    /**
     * Run post-apply synchronization (cache and runtime refresh).
     */
    public function afterApply(ApplyContext $c): void;
}
