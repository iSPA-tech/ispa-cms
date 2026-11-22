<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\Exceptions\CoreModuleCannotBeDisabled;
use App\Support\Modules\ModuleStatusService;
use Illuminate\Console\Command;
use Throwable;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

/**
 * Lists module statuses as seen by the configured nwidart activator (file
 * or database), and with `set <Name> on|off` changes one status through
 * ModuleStatusService (audit entry + runtime cache refresh). After enabling,
 * the activation tasks run in clean artisan subprocesses.
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   2026-11-05
 * @last_updated 2026-11-06 - `set` action.
 */
class ModuleStatusCommand extends Command
{
    protected $signature = 'cms:module-status
        {action? : Leave empty to list; "set" to change a status}
        {name? : Module name (with set)}
        {state? : on or off (with set)}
        {--reason= : Reason stored in the audit entry (with set; default "cli")}
        {--enabled : Only list enabled modules}
        {--plain : Print one module name per line}
        {--json : Print a JSON array of {name, enabled, version}}';

    protected $description = 'List modules with their enabled status, version and activator, or set one module on/off.';

    /** Timeout in seconds for each activation task subprocess. */
    private const TASK_TIMEOUT = 600;

    /**
     * Print the module statuses in the requested format, or run `set`.
     *
     * @param RepositoryInterface $modules nwidart module repository.
     * @param ModuleStatusService $statuses Module status write path (used by `set`).
     * @param ArtisanSubprocess $artisan Clean artisan subprocess runner (used by `set`).
     * @return int Command exit code.
     *
     * @author  iSPA Team
     * @since   2026-11-05
     */
    public function handle(RepositoryInterface $modules, ModuleStatusService $statuses, ArtisanSubprocess $artisan): int
    {
        $action = $this->argument('action');
        if ($action !== null && $action !== '') {
            if ($action !== 'set') {
                $this->error(sprintf('Unknown action "%s"; use "set <Name> on|off".', $action));

                return self::INVALID;
            }

            return $this->set($modules, $statuses, $artisan);
        }

        $rows = [];
        foreach ($modules->all() as $module) {
            /** @var Module $module */
            $enabled = $module->isEnabled();
            if ($this->option('enabled') && ! $enabled) {
                continue;
            }

            $version = $module->get('version');
            $rows[] = [
                'name' => $module->getName(),
                'enabled' => $enabled,
                'version' => is_scalar($version) ? (string) $version : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('plain')) {
            foreach ($rows as $row) {
                $this->line($row['name']);
            }

            return self::SUCCESS;
        }

        $activator = class_basename((string) config('modules.activators.'.config('modules.activator').'.class'));
        $this->table(
            ['Name', 'Enabled', 'Version', 'Activator'],
            array_map(static fn (array $row): array => [
                $row['name'],
                $row['enabled'] ? 'yes' : 'no',
                $row['version'] ?? '-',
                $activator,
            ], $rows),
        );

        return self::SUCCESS;
    }

    /**
     * Change one module status, then run activation tasks after an enable.
     */
    private function set(RepositoryInterface $modules, ModuleStatusService $statuses, ArtisanSubprocess $artisan): int
    {
        $name = (string) $this->argument('name');
        $state = strtolower((string) $this->argument('state'));

        if ($name === '' || ! in_array($state, ['on', 'off'], true)) {
            $this->error('Usage: cms:module-status set <Name> on|off [--reason=...]');

            return self::INVALID;
        }

        $module = $modules->find($name);
        if ($module === null) {
            $this->error(sprintf('Module "%s" not found.', $name));

            return self::FAILURE;
        }

        $name = $module->getName();
        $enable = $state === 'on';
        $reason = (string) ($this->option('reason') ?: 'cli');

        try {
            $statuses->change($name, $enable, null, $reason);
        } catch (CoreModuleCannotBeDisabled $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Module %s %s.', $name, $enable ? 'enabled' : 'disabled'));

        if (! $enable) {
            return self::SUCCESS;
        }

        $failed = false;
        foreach ([
            ['module:migrate', $name, '--force'],
            ['cms:modules-sync', $name, '--skip-translations'],
            ['cms:translation-sync', $name],
        ] as $task) {
            try {
                $result = $artisan->run($task, self::TASK_TIMEOUT);
                $ok = $result->successful();
                $detail = trim($result->errorOutput() ?: $result->output());
            } catch (Throwable $exception) {
                $ok = false;
                $detail = $exception->getMessage();
            }

            if (! $ok) {
                $failed = true;
                $this->error(sprintf('Activation task "%s" failed: %s', implode(' ', $task), $detail));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
