<?php

declare(strict_types=1);

namespace Cms\Widget\Console\Commands;

use App\Contracts\Modules\ModuleDeploymentPreflight;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryCompatibilityChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * `cms:widget-registry-preflight` (plan Chunk 2 Task 15, lines 848-884,
 * 872-874; docs/architecture/adr-widget-provider-lifecycle.md decisions 7
 * and 10).
 *
 * Reads every retained Draft/Release `widget_layout_versions` row (Task
 * 13a's real schema; `registry_contracts` is already array-cast by
 * {@see WidgetLayoutVersion}) and compares each row's stored snapshot
 * against CURRENT registry state via {@see WidgetRegistryCompatibilityChecker}.
 * Missing keys or breaking semantic-field drift exits non-zero before
 * traffic; zero retained rows is an expected, explicit VACUOUS PASS (no
 * Draft/Release exists yet -- Tasks 17/18 create the first one). A
 * `superseded` row is retired history, never scanned.
 *
 * Dual role, one file (the plan's allowed_paths lists no separate checker-
 * adapter file): this class is BOTH a direct Artisan command AND an
 * {@see ModuleDeploymentPreflight} implementation, sharing one private
 * {@see self::issuesFor()} so CLI invocation and registry-integration
 * report byte-identical issues. WidgetServiceProvider::boot() registers
 * this instance into the root, Widget-agnostic
 * `App\Support\Modules\ModuleDeploymentPreflightRegistry`, which the
 * EXISTING `cms:deploy-preflight` (cms/Core/app/Console/Commands/
 * DeployPreflightCommand.php) already calls unconditionally -- Core gains
 * no Widget knowledge; a module with nothing registered leaves
 * `cms:deploy-preflight`'s behavior exactly as it was before this task.
 *
 * DEPLOYMENT-HOOK EVIDENCE (checklist: "prove the actual deployment
 * pipeline runs target-release preflight before switching traffic"): Task
 * 5a already proved, and this task independently RE-READ AND CONFIRMED,
 * that `deployment/build-script/deployment/hooks/before-activation.sh`
 * lines 439-444 run:
 *
 *     if artisan_has_command "cms:deploy-preflight"; then
 *         "$php_executable" artisan cms:deploy-preflight || exit 1
 *     ...
 *
 * before the release's `current` symlink swap (docs/evidence/widget-site-
 * layout-preflight.md:359-363, citing deploy.sh:523-524). That hook file is
 * a per-project deployment template living OUTSIDE this repository (never
 * checked out alongside a bare `ispa-cms` clone -- "the ispa-cms source
 * repo itself has no .github/workflows"), so no test reads it directly;
 * the executable proof this task owns instead is that `cms:deploy-
 * preflight`, once invoked (already proven wired), now transitively runs
 * THIS check -- see WidgetRegistryPreflightCommandTest's "deployment
 * pipeline integration" section.
 *
 * OLD-RELEASE PREFLIGHT / PACKAGE REMOVAL (docs/architecture/adr-widget-
 * module-boundary.md decision 9): raw `php artisan module:disable`, a
 * direct `modules_statuses.json` edit, and package/status removal are
 * UNMANAGED deployment bypasses outside the guarantee this command and the
 * Task 7 `cms:module-disable` (GuardedModuleDisableCommand /
 * ManagedModuleDisabler) enforce together. Before Widget code/status is
 * ever removed from a release, the operator runbook must (1) run managed
 * disable (`cms:module-disable widget`, which already blocks while any
 * retained Instance Version references a Core-owned Definition/Theme --
 * enforcement code exists from Task 7) and (2) run THIS preflight (or
 * `cms:deploy-preflight`) against the OLD release WHILE Widget code is
 * still present, proving zero retained module-owned dependencies or an
 * approved archival/migration plan -- an already-removed module cannot
 * guard itself, because neither this command nor its registry entry would
 * exist to run. This is a documented operational sequencing requirement,
 * not new enforcement code this task adds.
 */
final class WidgetRegistryPreflightCommand extends Command implements ModuleDeploymentPreflight
{
    protected $signature = 'cms:widget-registry-preflight';

    protected $description = 'Verify retained Draft/Release Widget Site Layout registry contracts against current Slot/Template/Target Context/provider registrations before traffic.';

    public function __construct(
        private readonly WidgetRegistryCompatibilityChecker $checker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->retainedRows();

        if ($rows->isEmpty()) {
            $this->info('No retained Draft/Release registry_contracts rows found; vacuous pass.');

            return self::SUCCESS;
        }

        $issues = $this->issuesFor($rows);

        if ($issues === []) {
            $this->info("Registry compatibility check PASSED for {$rows->count()} retained Draft/Release row(s).");

            return self::SUCCESS;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }

        return self::FAILURE;
    }

    public function name(): string
    {
        return 'widget-registry';
    }

    /**
     * @return list<string>
     */
    public function check(): array
    {
        return $this->issuesFor($this->retainedRows());
    }

    private function retainedRows(): Collection
    {
        return WidgetLayoutVersion::query()
            ->whereIn('state', [WidgetLayoutVersion::STATE_DRAFT, WidgetLayoutVersion::STATE_RELEASE])
            ->get();
    }

    /**
     * @return list<string>
     */
    private function issuesFor(Collection $rows): array
    {
        $issues = [];

        foreach ($rows as $row) {
            $contracts = is_array($row->registry_contracts) ? $row->registry_contracts : [];

            foreach ($this->checker->compare($contracts) as $breach) {
                $issues[] = "layout_version #{$row->id} (layout_id={$row->layout_id}, state={$row->state}): {$breach}";
            }
        }

        return $issues;
    }
}
