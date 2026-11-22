<?php

declare(strict_types=1);

namespace Cms\Widget\Console\Commands;

use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Operations\WidgetModuleDisableGuard;
use Illuminate\Console\Command;

/**
 * `cms:widget-module-disable-check` (plan Chunk 2 Task 22, DoD lines 1201-1202;
 * architecture.md line 214/232: appended to `WidgetServiceProvider::registerCommands()`'s
 * cumulative discovery list alongside `cms:widget-registry-preflight` and
 * `cms:widget-manifests-reconcile`).
 *
 * A NON-MUTATING operator diagnostic -- unlike the sanctioned
 * `cms:module-disable` (Core's {@see \Cms\Core\Console\Commands\GuardedModuleDisableCommand}),
 * this command never disables anything. It answers "would a managed
 * disable be blocked right now, and why" by running the SAME
 * {@see WidgetModuleDisableGuard::evaluate()} predicate directly, plus
 * reports three ADDITIONAL informational signals that never block by
 * themselves (DoD 1201: "Report unreferenced Blob/Manifest and empty
 * Layout history without blocking"), useful for the OLD-RELEASE PREFLIGHT
 * / PACKAGE REMOVAL runbook {@see \Cms\Widget\Console\Commands\WidgetRegistryPreflightCommand}'s
 * own docblock already describes: unreferenced Blob rows (no Manifest
 * points to them), unreferenced Manifest rows (no Instance Version points
 * to them), and whether any Layout Draft/Release history exists at all.
 */
final class WidgetModuleDisableCheckCommand extends Command
{
    protected $signature = 'cms:widget-module-disable-check';

    protected $description = 'Report whether managed Widget module disable is currently blocked, plus informational unreferenced Blob/Manifest and Layout-history signals (never mutates anything).';

    public function __construct(
        private readonly WidgetModuleDisableGuard $guard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $unreferencedBlobCount = WidgetArtifactBlob::query()->doesntHave('manifests')->count();
        $unreferencedManifestCount = WidgetArtifactManifestModel::query()->doesntHave('instanceVersions')->count();
        $layoutHistoryEmpty = WidgetLayoutVersion::query()->doesntExist();

        $this->info("Unreferenced Blob row(s): {$unreferencedBlobCount}");
        $this->info("Unreferenced Manifest row(s): {$unreferencedManifestCount}");
        $this->info($layoutHistoryEmpty ? 'Layout history is empty.' : 'Layout history is non-empty.');

        $blockingReason = $this->guard->evaluate();

        if ($blockingReason !== null) {
            $this->error("Widget module disable is currently BLOCKED ({$blockingReason}): a retained Instance Version references a Core-owned Definition/Theme.");

            return self::FAILURE;
        }

        $this->info('Widget module disable check PASSED: no retained Instance Version references a Core-owned Definition/Theme.');

        return self::SUCCESS;
    }
}
