<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Generator\ModuleGenerator;
use Cms\Core\Services\Modules\Generator\ModuleNames;
use Cms\Core\Services\Modules\Generator\ModuleTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Build a new module from the Core module template (`cms/Core/resources/module-template`) with one working sample
 * entity: lite = API + permissions, normal = + service, translations and admin page, full = + public pages, command,
 * job, mail, events. The database is touched only with --setup. Design: workspace
 * 
 */
final class MakeModuleCommand extends Command
{
    /** Module names the generator never writes. */
    private const RESERVED = ['Core', 'Content', 'Widget', 'Skeleton'];

    protected $signature = 'cms:make-module
        {name : StudlyCase module name, e.g. Blog}
        {--mode=normal : lite, normal or full}
        {--entity=Item : StudlyCase name of the sample entity, e.g. Post}
        {--setup : Also run the database steps (migrate, permissions, translations)}
        {--dry-run : List the files without writing anything}';

    protected $description = 'Create a module with a working sample entity (modes: lite, normal, full).';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $entity = (string) $this->option('entity');
        $mode = (string) $this->option('mode');
        $modulesDir = rtrim((string) config('modules.paths.modules'), '/');
        $template = new ModuleTemplate((string) (config('modules.generator_template') ?: dirname(__DIR__, 3) . '/resources/module-template'));

        $refusal = $this->refusal($name, $entity, $mode, $modulesDir);
        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $names = new ModuleNames($name, $entity);
        $generator = new ModuleGenerator($template);
        $moduleDir = $modulesDir . '/' . $name;

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$mode} module {$name} would be written to {$moduleDir}:");
            foreach (array_keys($generator->plan($names, $mode)) as $path) {
                $this->line('  ' . $path);
            }

            return self::SUCCESS;
        }

        $written = $generator->write($names, $mode, $moduleDir, [
            '__CORE_REQUIREMENT__' => $this->coreRequirement(),
            // CalVer YYYY.MM.NN: the first release of this month.
            '__MODULE_VERSION__' => now()->format('Y.m') . '.01',
        ]);
        $this->info(count($written) . " files written to {$moduleDir} ({$mode}).");

        // The module is turned on only once composer can autoload its provider; a listed module whose provider class
        // is missing breaks every later request.
        if (! $this->runStep('composer dump-autoload', 'composer dump-autoload', "The module is not enabled yet; after fixing composer, add \"{$name}\": true to the statuses file.")) {
            return self::FAILURE;
        }
        $this->enable($name);

        foreach ($this->setupSteps($names, $mode, $moduleDir) as $label => $command) {
            if (! $this->runStep($label, $command, 'The module files are in place and enabled; fix the problem and run the remaining steps by hand.')) {
                return self::FAILURE;
            }
        }

        $lint = $this->lint($moduleDir);
        $this->line($lint === 0 ? '  ✓ cms:manifest-lint' : "  ! cms:manifest-lint reported errors in {$name}; run it to see them");

        $this->summary($names, $mode);

        return self::SUCCESS;
    }

    private function refusal(string $name, string $entity, string $mode, string $modulesDir): ?string
    {
        if (! in_array($mode, ModuleTemplate::MODES, true)) {
            return "Unknown mode \"{$mode}\"; use lite, normal or full.";
        }
        foreach ([$name, $entity] as $each) {
            if (($error = ModuleNames::validate($each)) !== null) {
                return $error;
            }
        }
        if (in_array($name, self::RESERVED, true) || str_starts_with($name, 'Game')) {
            return "\"{$name}\" is reserved; choose another module name.";
        }
        if ($this->option('setup') && app()->environment('production')) {
            return 'APP_ENV is production: --setup would migrate this database. Create the module on a development copy, '
                . 'then deploy it.';
        }
        $statusesFile = (string) config('modules.activators.file.statuses-file');
        if (File::exists($statusesFile) && ! is_array(json_decode((string) File::get($statusesFile), true))) {
            return "{$statusesFile} is not valid JSON; fix it first (nothing was written).";
        }

        $names = new ModuleNames($name, $entity);
        $alias = $names->alias();
        $table = $names->tokens()['skeleton_things'];
        if ($this->tableExists($table)) {
            return "The table {$table} already exists in the database; choose another module or entity name.";
        }
        if ($mode === 'full' && ($clash = $this->publicRouteClash($alias)) !== null) {
            return "The public URL /{$alias} is already used by {$clash}; the module's public pages would clash with it. "
                . 'Choose another module name, or use --mode=normal and add public pages under a free prefix.';
        }
        foreach (File::isDirectory($modulesDir) ? File::directories($modulesDir) : [] as $dir) {
            $existing = basename($dir);
            if (strcasecmp($existing, $name) === 0) {
                return "The folder {$dir} already exists; nothing was written.";
            }
            $manifest = File::exists($dir . '/module.json') ? json_decode(File::get($dir . '/module.json'), true) : null;
            $existingAlias = is_array($manifest) ? (string) ($manifest['alias'] ?? '') : '';
            if ($existingAlias !== '' && str_replace('-', '', $existingAlias) === str_replace('-', '', $alias)) {
                return "Module {$existing} already uses the alias \"{$existingAlias}\"; choose another name.";
            }
        }

        return null;
    }

    /** Whether the sample table already exists; unknown (no database yet) counts as no. */
    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Lint the new module's manifest beside Core only, so a problem in another module does not read as one in the
     * new module.
     */
    private function lint(string $moduleDir): int
    {
        $dir = sys_get_temp_dir() . '/cms-make-module-lint-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $links = [$dir . '/Core' => dirname(__DIR__, 3), $dir . '/' . basename($moduleDir) => $moduleDir];
        try {
            foreach ($links as $link => $target) {
                symlink($target, $link);
            }

            return $this->callSilently('cms:manifest-lint', ['--path' => $dir]);
        } finally {
            foreach (array_keys($links) as $link) {
                if (is_link($link)) {
                    unlink($link);
                }
            }
            @rmdir($dir);
        }
    }

    /** `requires.core` of the new module: this core version up to the next major. */
    private function coreRequirement(): string
    {
        $core = json_decode((string) File::get(dirname(__DIR__, 3) . '/module.json'), true);
        $version = (string) ($core['version'] ?? '0.0.0');
        $major = (int) explode('.', $version)[0];

        return ">={$version},<" . ($major + 1) . '.00.00';
    }

    private function enable(string $name): void
    {
        $file = (string) config('modules.activators.file.statuses-file');
        $statuses = File::exists($file) ? (array) json_decode(File::get($file), true) : [];
        $statuses[$name] = true;
        File::put($file, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->line("  ✓ enabled in {$file}");
    }

    /** Run one post-copy step in a fresh PHP process (so the new module is autoloaded); false and a message on failure. */
    private function runStep(string $label, string $command, string $whenFailed): bool
    {
        $result = Process::path(base_path())->timeout(600)->run($command);
        if ($result->failed()) {
            $this->error("Step failed: {$label}. {$whenFailed}");
            $this->line(trim($result->errorOutput() . "\n" . $result->output()));

            return false;
        }
        $this->line("  ✓ {$label}");

        return true;
    }

    /**
     * Database steps of --setup, label => shell command.
     *
     * @return array<string, string>
     */
    private function setupSteps(ModuleNames $names, string $mode, string $moduleDir): array
    {
        if (! $this->option('setup')) {
            return [];
        }

        $artisan = escapeshellarg(PHP_BINARY) . ' artisan ';
        $steps = [
            'migrate' => $artisan . 'migrate --path=' . escapeshellarg($moduleDir . '/database/migrations') . ' --realpath',
            'cms:modules-sync-permissions' => $artisan . 'cms:modules-sync-permissions ' . $names->module,
        ];
        if ($mode !== 'lite') {
            $steps['cms:translation-sync'] = $artisan . 'cms:translation-sync ' . $names->module;
            $steps['cms:translation-generate'] = $artisan . 'cms:translation-generate --public-only';
        }

        return $steps;
    }

    /** The action of an existing route under /<alias> (the new module's public pages would clash with it), or null. */
    private function publicRouteClash(string $alias): ?string
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri === $alias || str_starts_with($uri, $alias . '/')) {
                return $route->getActionName() . " ({$uri})";
            }
        }

        return null;
    }

    private function summary(ModuleNames $names, string $mode): void
    {
        $tokens = $names->tokens();
        $this->newLine();
        $this->info("Module {$names->module} ({$mode}) is ready.");
        $this->line('  Admin API:  /api/v2/admin/' . $tokens['skeleton'] . '/' . $tokens['things']);
        if ($mode !== 'lite') {
            $this->line('  Admin page: /' . $tokens['skeleton'] . '/' . $tokens['things'] . ' (rebuild the admin: pnpm build)');
        }
        if ($mode === 'full') {
            $this->line('  Public:     /' . $tokens['skeleton']);
        }
        $this->line('  Tests:      php artisan test cms/' . $names->module . '/tests');
        if (! $this->option('setup')) {
            $this->newLine();
            $this->line('Next steps (they write to the database in .env):');
            $this->line('  php artisan migrate');
            $this->line('  php artisan cms:modules-sync-permissions ' . $names->module);
            if ($mode !== 'lite') {
                $this->line('  php artisan cms:translation-sync ' . $names->module);
                $this->line('  php artisan cms:translation-generate --public-only');
            }
        }
    }
}
