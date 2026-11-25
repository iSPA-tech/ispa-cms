<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use App\Support\Modules\RuntimeModules;
use Closure;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Computes what applying a starter pack would do on this site, without writing anything persistent.
 *
 * Blockers (the plan cannot be applied): a module that is neither installed nor published on the hub (or whose
 * version constraint no available version meets); a `requires` conflict between the pack's modules and the site;
 * the environment guard; a pack `version` older than the newest successfully applied version in the ledger; an
 * unreadable or invalid configuration, roles, workflows or content file.
 *
 * Environment guard: a site flagged production
 * (`app()->environment('production')`) that already holds content of any of the pack's content modules is blocked.
 * Modules with no known content probe count as holding content (fail closed). A separate production flag is a
 * later step. Re-applying is add-only, so a site that already has content on a dev site is allowed.
 *
 * Only the configuration dry run touches the database, and it does so inside a transaction that is always rolled
 * back (the dry run stores a run row); the other parts only read. The plan has no timestamps or random values, so
 * two calls on an unchanged site return equal plans.
 */
class StarterPackPlanner
{
    /** Models whose rows count as site content, per content module. */
    public const CONTENT_MODELS = [
        'Helpdesk' => [\Cms\Helpdesk\Models\HelpdeskArticle::class, \Cms\Helpdesk\Models\HelpdeskProduct::class],
        'Content' => [\Cms\Content\Models\Post::class],
    ];

    /** @var Closure(): bool */
    private Closure $isProduction;

    /** @var array<string, list<class-string>> */
    private array $contentModels;

    /** @var list<array<string, mixed>>|null */
    private ?array $hub = null;

    /** @var string|null Reason the hub could not be read. */
    private ?string $hubError = null;

    /** @var Closure(string, string): PreflightResult */
    private Closure $preflight;

    /**
     * @param ModuleCatalog              $catalog       Installed state and hub catalog of modules.
     * @param StarterContentRegistry     $registry      Content importers registered by enabled modules.
     * @param StarterRoleApplier         $roles         Role planner.
     * @param StarterWorkflowApplier     $workflows     Workflow planner.
     * @param ConfigPackageDiffer        $differ        configuration package dry run.
     * @param (callable(): bool)|null    $isProduction  Production flag (default: the application environment).
     * @param array<string, list<class-string>>|null $contentModels Content probes (default: {@see self::CONTENT_MODELS}).
     * @param (callable(string, string): PreflightResult)|null $preflight Install preflight, read for the partner warning of a module to install (default: InstallPreflight::check).
     */
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly StarterContentRegistry $registry,
        private readonly StarterRoleApplier $roles,
        private readonly StarterWorkflowApplier $workflows,
        private readonly ConfigPackageDiffer $differ,
        ?callable $isProduction = null,
        ?array $contentModels = null,
        ?callable $preflight = null,
    ) {
        $this->preflight = $preflight !== null
            ? Closure::fromCallable($preflight)
            : static fn (string $name, string $version): PreflightResult => app(InstallPreflight::class)->check($name, $version);
        $this->isProduction = $isProduction !== null
            ? Closure::fromCallable($isProduction)
            : static fn (): bool => app()->environment('production');
        $this->contentModels = $contentModels ?? self::CONTENT_MODELS;
    }

    /**
     * Plan the application of a pack.
     *
     * @param StarterPackManifest $pack         Loaded pack.
     * @param int|null            $actingUserId User the plan is computed for (default: the signed-in user).
     */
    public function plan(StarterPackManifest $pack, ?int $actingUserId = null): StarterPackPlan
    {
        $userId = (int) ($actingUserId ?? Auth::id() ?? 0);
        $blockers = [];
        $warnings = [];
        $this->hub = null;
        $this->hubError = null;

        $modules = $this->planModules($pack, $blockers, $warnings);
        $this->checkLedger($pack, $blockers);
        $this->checkEnvironment($pack, $blockers, $warnings);

        $config = $this->planConfig($pack, $userId, $blockers, $warnings);
        $roles = $this->planRoles($pack, $userId, $blockers, $warnings);
        $workflows = $this->planWorkflows($pack, $blockers, $warnings);
        $content = $this->planContent($pack, $modules, $blockers, $warnings);

        return new StarterPackPlan($pack->key, $pack->version, $modules, $config, $roles, $workflows, $content, array_values(array_unique($blockers)), array_values(array_unique($warnings)));
    }

    /**
     * Module states, version constraints and `requires` conflicts.
     *
     * @param list<string> $blockers
     * @param list<string> $warnings
     *
     * @return list<array{name: string, constraint: string, version: ?string, state: string, irreversible: list<string>, third_party: ?array<string, mixed>}>
     */
    private function planModules(StarterPackManifest $pack, array &$blockers, array &$warnings): array
    {
        $out = [];
        $requires = [];

        foreach ($pack->modules as $name => $constraint) {
            $name = (string) $name;
            $constraint = (string) $constraint;
            $installed = $this->catalog->installedState($name);
            $irreversible = [];
            $version = null;
            $thirdParty = null;

            if ($installed['installed'] !== 'none') {
                $version = $installed['installed_version'];
                $state = $installed['enabled'] ? StarterPackPlan::STATE_ENABLED : StarterPackPlan::STATE_TO_ENABLE;
                if ($version !== null && !ModuleVersionConstraint::satisfies($version, $constraint)) {
                    $blockers[] = "Module {$name} {$version} does not meet the pack's constraint \"{$constraint}\".";
                }
                $requires[$name] = $this->installedRequires($name, $installed['installed']);
            } else {
                $state = StarterPackPlan::STATE_TO_INSTALL;
                $entry = $this->hubVersion($name, $constraint, $blockers);
                if ($entry !== null) {
                    $version = $entry['version'];
                    $irreversible = $this->irreversibleList($entry['irreversible'] ?? false);
                    if (($entry['irreversible'] ?? false) !== false && ($entry['irreversible'] ?? []) !== []) {
                        $warnings[] = "Module {$name} {$version} has irreversible migrations.";
                    }
                    $requires[$name] = ModuleVersionConstraint::parseRequires((array) ($entry['requires'] ?? []));
                    $thirdParty = $this->thirdParty($name, (string) $version);
                }
            }

            $out[] = ['name' => $name, 'constraint' => $constraint, 'version' => $version, 'state' => $state, 'irreversible' => $irreversible, 'third_party' => $thirdParty];
        }

        // Keyed without case: a `requires` entry `questionbank` names the pack module `QuestionBank`.
        $byName = [];
        foreach ($out as $row) {
            $byName[strtolower($row['name'])] = $row;
        }
        foreach ($requires as $module => $deps) {
            foreach ($deps as $dep => $depConstraint) {
                if (isset($byName[strtolower($dep)])) {
                    $dep = $byName[strtolower($dep)]['name'];
                    $depVersion = $byName[strtolower($dep)]['version'];
                    if ($depVersion !== null && !ModuleVersionConstraint::satisfies($depVersion, $depConstraint)) {
                        $blockers[] = "Module {$module} requires {$dep} {$depConstraint}, but the pack brings {$depVersion}.";
                    }

                    continue;
                }
                $dep = $this->catalog->installedName($dep);
                $state = $this->catalog->installedState($dep);
                if ($state['installed'] === 'none' || !$state['enabled']) {
                    $blockers[] = "Module {$module} requires {$dep}, which is not enabled on this site and is not part of the pack.";
                } elseif ($state['installed_version'] !== null && !ModuleVersionConstraint::satisfies($state['installed_version'], $depConstraint)) {
                    $blockers[] = "Module {$module} requires {$dep} {$depConstraint}, but this site has {$state['installed_version']}.";
                }
            }
        }

        return $out;
    }

    /**
     * `{publisher, impact}` of the partner warning (MODULE_WARN_THIRD_PARTY_FIRST_INSTALL) the install check gives a
     * module to install, or null for an iSPA module, a publisher this site confirmed before, or a check that cannot
     * run. The same details the single install shows in its confirmation box.
     *
     * @return array<string, mixed>|null
     */
    private function thirdParty(string $name, string $version): ?array
    {
        try {
            $check = ($this->preflight)($name, $version);
        } catch (\Throwable) {
            return null;
        }
        foreach ($check->warnings as $warning) {
            if (($warning['code'] ?? null) === InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL) {
                return ['publisher' => $warning['details']['publisher'] ?? null, 'impact' => $warning['details']['impact'] ?? null];
            }
        }

        return null;
    }

    /**
     * Highest published, not revoked hub version that meets the constraint; records a blocker when none exists.
     *
     * @param list<string> $blockers
     *
     * @return array<string, mixed>|null
     */
    private function hubVersion(string $name, string $constraint, array &$blockers): ?array
    {
        if ($this->hub === null && $this->hubError === null) {
            try {
                $this->hub = $this->catalog->hubEntries();
            } catch (Throwable) {
                $this->hubError = 'The module catalog could not be read.';
            }
        }
        if ($this->hubError !== null) {
            $blockers[] = "Module {$name} is not installed and {$this->hubError}";

            return null;
        }

        $found = false;
        $best = null;
        foreach ((array) $this->hub as $entry) {
            if ($entry['name'] !== $name) {
                continue;
            }
            $found = true;
            foreach ($entry['versions'] as $candidate) {
                if ($candidate['revoked'] || !ModuleVersionConstraint::satisfies($candidate['version'], $constraint)) {
                    continue;
                }
                if ($best === null || ModuleVersionConstraint::compare($candidate['version'], $best['version']) > 0) {
                    $best = $candidate;
                }
            }
        }

        if (!$found) {
            $blockers[] = "Module {$name} is not installed on this site and is not published on the hub.";
        } elseif ($best === null) {
            $blockers[] = "No published version of module {$name} meets the pack's constraint \"{$constraint}\".";
        }

        return $best;
    }

    /**
     * `requires` of an installed module, read from its module.json.
     *
     * @return array<string, string>
     */
    private function installedRequires(string $name, string $source): array
    {
        $base = $source === 'runtime' ? RuntimeModules::path() : $this->catalog->releasePath();
        $raw = $base === null ? false : @file_get_contents($base . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'module.json');
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) && is_array($decoded['requires'] ?? null) ? ModuleVersionConstraint::parseRequires($decoded['requires']) : [];
    }

    /**
     * Normalise a hub `irreversible` value to a list of migration names.
     *
     * @return list<string>
     */
    private function irreversibleList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * Block a pack older than the newest version already applied here.
     *
     * @param list<string> $blockers
     */
    private function checkLedger(StarterPackManifest $pack, array &$blockers): void
    {
        if (!Schema::hasTable((new StarterPackApplication())->getTable())) {
            return;
        }

        $newest = null;
        foreach (StarterPackApplication::query()->succeeded()->where('pack_key', $pack->key)->pluck('pack_version') as $version) {
            if ($newest === null || ModuleVersionConstraint::compare((string) $version, $newest) > 0) {
                $newest = (string) $version;
            }
        }

        if ($newest !== null && ModuleVersionConstraint::compare($pack->version, $newest) < 0) {
            $blockers[] = "Pack version {$pack->version} is older than version {$newest} already applied to this site.";
        }
    }

    /**
     * Environment guard.
     *
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    private function checkEnvironment(StarterPackManifest $pack, array &$blockers, array &$warnings): void
    {
        if (!($this->isProduction)()) {
            return;
        }

        foreach (array_unique(array_column($pack->content, 'module')) as $module) {
            $holds = $this->holdsContent((string) $module);
            if ($holds === null) {
                $warnings[] = "Cannot check whether this site holds {$module} content; treated as holding content.";
            }
            if ($holds !== false) {
                $blockers[] = "This site is flagged as production and already holds {$module} content; a starter pack is only applied to a dev site or a site without content.";
            }
        }
    }

    /**
     * Whether the site holds content of a module: true, false, or null when no probe is known.
     */
    private function holdsContent(string $module): ?bool
    {
        if (!isset($this->contentModels[$module])) {
            return null;
        }

        foreach ($this->contentModels[$module] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $model = new $class();
            if (Schema::hasTable($model->getTable()) && $class::query()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configuration package dry run of the pack's configuration package, rolled back so nothing stays written.
     *
     * @param list<string> $blockers
     * @param list<string> $warnings
     *
     * @return array<string, mixed>|null
     */
    private function planConfig(StarterPackManifest $pack, int $userId, array &$blockers, array &$warnings): ?array
    {
        $path = $pack->configPath();
        if ($path === null) {
            return null;
        }
        $package = $this->readJson($path);
        if (!is_array($package)) {
            $blockers[] = 'The pack configuration file could not be read.';

            return ['status' => 'failed', 'error' => 'unreadable', 'errors' => [], 'sections' => [], 'warnings' => 0];
        }

        DB::beginTransaction();
        try {
            $run = $this->differ->dryRun($package, $userId);
        } catch (Throwable $e) {
            $blockers[] = 'The pack configuration could not be compared with this site.';

            return ['status' => 'failed', 'error' => class_basename($e), 'errors' => [], 'sections' => [], 'warnings' => 0];
        } finally {
            DB::rollBack();
        }

        $summary = (array) $run->summary;
        if ($run->status !== 'succeeded') {
            $blockers[] = 'The pack configuration is not valid: ' . (string) ($summary['error'] ?? 'unknown error');

            return ['status' => 'failed', 'error' => (string) ($summary['error'] ?? ''), 'errors' => array_values((array) ($summary['errors'] ?? [])), 'sections' => [], 'warnings' => 0];
        }

        if ((int) ($summary['choices'] ?? 0) > 0) {
            $warnings[] = 'The configuration has items that need a choice; apply keeps existing values.';
        }

        // Settings rows that do not exist here are reported as blocked; say why, because the module that owns them has
        // not installed its defaults yet (for example the Helpdesk rows before its settings seeder ran).
        $settings = (array) (((array) ($summary['sections'] ?? []))['settings'] ?? []);
        $missing = (int) (((array) ($settings['counts'] ?? []))['blocked'] ?? 0);
        if ($missing > 0) {
            $warnings[] = "{$missing} setting(s) of the configuration cannot be added because the module's default settings are not installed on this site yet.";
        }

        return ['status' => 'succeeded', 'sections' => (array) ($summary['sections'] ?? []), 'warnings' => (int) ($summary['warnings'] ?? 0)];
    }

    /**
     * Role plan.
     *
     * @param list<string> $blockers
     * @param list<string> $warnings
     *
     * @return array<string, mixed>|null
     */
    private function planRoles(StarterPackManifest $pack, int $userId, array &$blockers, array &$warnings): ?array
    {
        $path = $pack->rolesPath();
        if ($path === null) {
            return null;
        }
        $rows = $this->readJson($path);
        if (!is_array($rows)) {
            $blockers[] = 'The pack roles file could not be read.';

            return null;
        }

        try {
            $plan = $this->roles->plan($rows, $userId);
        } catch (StarterPackException $e) {
            $blockers[] = $e->getMessage();

            return null;
        }

        foreach ($plan['missing_permissions'] as $role => $missing) {
            $warnings[] = "Role {$role} will be created without " . count($missing) . ' permission(s) missing on this site.';
        }

        return $plan;
    }

    /**
     * Workflow plan.
     *
     * @param list<string> $blockers
     * @param list<string> $warnings
     *
     * @return array<string, mixed>|null
     */
    private function planWorkflows(StarterPackManifest $pack, array &$blockers, array &$warnings): ?array
    {
        $path = $pack->workflowsPath();
        if ($path === null) {
            return null;
        }
        $rows = $this->readJson($path);
        if (!is_array($rows)) {
            $blockers[] = 'The pack workflows file could not be read.';

            return null;
        }

        try {
            return $this->workflows->plan($rows);
        } catch (StarterPackException $e) {
            $blockers[] = $e->getMessage();

            return null;
        }
    }

    /**
     * Content counts per importer.
     *
     * @param list<array{name: string, state: string}> $modules
     * @param list<string> $blockers
     * @param list<string> $warnings
     *
     * @return list<array{module: string, importer: string, file: string, counts: array<string, int>|null}>
     */
    private function planContent(StarterPackManifest $pack, array $modules, array &$blockers, array &$warnings): array
    {
        $states = array_column($modules, 'state', 'name');
        $out = [];

        foreach ($pack->content as $entry) {
            $row = ['module' => $entry['module'], 'importer' => $entry['importer'], 'file' => $entry['file'], 'counts' => null];

            if (!$this->registry->has($entry['importer'])) {
                if (($states[$entry['module']] ?? null) === StarterPackPlan::STATE_ENABLED) {
                    $blockers[] = "Content importer {$entry['importer']} is not registered although module {$entry['module']} is enabled.";
                } else {
                    $warnings[] = "Content of {$entry['module']} is counted after the module is enabled.";
                }
                $out[] = $row;

                continue;
            }

            $data = $this->readJson($entry['path']);
            if (!is_array($data)) {
                $blockers[] = "Content file {$entry['file']} could not be read.";
                $out[] = $row;

                continue;
            }

            $importer = $this->registry->get($entry['importer']);
            $errors = $importer->validate($data);
            if ($errors !== []) {
                foreach ($errors as $error) {
                    $blockers[] = "Content file {$entry['file']}: {$error}";
                }
                $out[] = $row;

                continue;
            }

            $row['counts'] = $importer->plan($data);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Decode a JSON file; null when it cannot be read or decoded.
     *
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
