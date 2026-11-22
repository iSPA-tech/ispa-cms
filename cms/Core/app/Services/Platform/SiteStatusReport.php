<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Platform;

use App\Support\Modules\RuntimeModuleAutoloader;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Backup\ServerNightlyStatus;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleIntegrity;
use Cms\Core\Services\Modules\Install\RevocationAttentionProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Builds the allowlisted status report a site sends to the platform hub.
 *
 * The report holds no secrets, paths, environment values or other hosts. Every data source is read defensively:
 * a source that cannot be read yields null (or a failed health flag) and never throws.
 *
 * Report levels: `off` sends nothing; `basic` sends what the hub needs for updates, grants, ratings and
 * support (schema, domain, app version, release, modules with name, enabled, version and source); `full` adds the
 * operations data (deploy details, PHP/Laravel versions, activator, revoked flags, pending migrations, health, backups,
 * runtime collisions and integrity counts). The level is the `.env` value `CMS_PLATFORM_REPORT_LEVEL` when valid, else
 * the stored setting `platform.report_level`, else the default: `full` for a site whose secret is in `.env` (the
 * iSPA-run fleet), `basic` for a site connected from the connect screen. An unconnected site sends nothing.
 */
class SiteStatusReport
{
    public const SCHEMA = 1;

    public const LEVEL_OFF = 'off';

    public const LEVEL_BASIC = 'basic';

    public const LEVEL_FULL = 'full';

    /** Every level, smallest first. */
    public const LEVELS = [self::LEVEL_OFF, self::LEVEL_BASIC, self::LEVEL_FULL];

    /** Settings row key of the level chosen by the Webmaster. */
    public const SETTING_KEY = 'platform.report_level';

    /** Where the level in use comes from. */
    public const SOURCE_ENV = 'env';

    public const SOURCE_SETTING = 'setting';

    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_UNCONNECTED = 'unconnected';

    /** Top-level keys of a basic report, in order. */
    private const BASIC_KEYS = ['schema', 'domain', 'app_version', 'release', 'modules'];

    /** Keys of a module row in a basic report. */
    private const BASIC_MODULE_KEYS = ['name', 'enabled', 'version', 'source'];

    private const MAX_MIGRATION_NAMES = 50;

    /**
     * The level in use and where it comes from.
     *
     * `stored` is the Webmaster's saved choice (null when none or invalid); `env_locked` is true while a valid `.env`
     * level decides, so the setting cannot change anything; `connected` is false when the site holds no hub
     * credentials, which always means `off`.
     *
     * @return array{level: string, source: string, default: string, stored: ?string, env_locked: bool, connected: bool}
     */
    public function resolution(): array
    {
        $credentials = app(HubCredentials::class);
        $connected = $credentials->isConfigured();
        $default = $credentials->source() === HubCredentials::SOURCE_ENV ? self::LEVEL_FULL : self::LEVEL_BASIC;
        $env = self::valid(config('ispa-cms.platform_report_level'));
        $stored = $this->storedLevel();

        [$level, $source] = match (true) {
            !$connected => [self::LEVEL_OFF, self::SOURCE_UNCONNECTED],
            $env !== null => [$env, self::SOURCE_ENV],
            $stored !== null => [$stored, self::SOURCE_SETTING],
            default => [$default, self::SOURCE_DEFAULT],
        };

        return [
            'level' => $level,
            'source' => $source,
            'default' => $default,
            'stored' => $stored,
            'env_locked' => $env !== null,
            'connected' => $connected,
        ];
    }

    /**
     * The level in use (one of LEVELS).
     */
    public function level(): string
    {
        return $this->resolution()['level'];
    }

    /**
     * Save the Webmaster's level in the settings table (it does not apply while a `.env` level is set).
     *
     * @param string $level One of LEVELS.
     *
     * @throws \InvalidArgumentException For any other value.
     */
    public function storeLevel(string $level): void
    {
        if (self::valid($level) === null) {
            throw new \InvalidArgumentException('Unknown report level.');
        }

        $setting = Setting::withTrashed()->where('setting_key', self::SETTING_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::SETTING_KEY,
            'type' => 'text',
            'group_name' => 'platform',
            'name' => 'report_level',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'Site report level'],
            'value' => $level,
        ])->save();
    }

    /**
     * The report the next push sends at the given level (default: the level in use), or null for `off`.
     *
     * @param string|null $level One of LEVELS; null means the level in use.
     *
     * @return array<string, mixed>|null
     */
    public function payload(?string $level = null): ?array
    {
        $level = $level === null ? $this->level() : (self::valid($level) ?? self::LEVEL_OFF);

        return match ($level) {
            self::LEVEL_FULL => $this->build(),
            self::LEVEL_BASIC => $this->basic(),
            default => null,
        };
    }

    /**
     * The given value when it is one of LEVELS, else null.
     */
    public static function valid(mixed $level): ?string
    {
        return is_string($level) && in_array($level, self::LEVELS, true) ? $level : null;
    }

    /**
     * Build the full report with the fixed top-level key set (plus the optional `module_integrity` map once a check
     * ran). The diagnostic bundle reads it too; what is pushed to the hub goes through {@see payload()}.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $deploy = $this->deployInfo();

        $report = [
            'schema' => self::SCHEMA,
            'domain' => app(HubCredentials::class)->domain(),
            'app_version' => $this->text(config('ispa-cms.app_version')),
            'release' => $deploy['release'],
            'revision' => $deploy['revision'],
            'deployed_at' => $deploy['deployed_at'],
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'activator' => $this->text(config('modules.activator')),
            'modules' => $this->modules(),
            'pending_migrations' => $this->pendingMigrations(),
            'health' => $this->health(),
            'backups' => $this->backups(),
            'runtime_collisions' => array_values(array_map('strval', RuntimeModuleAutoloader::collisions())),
        ];

        // Optional key: changed-file counts per module from the last integrity check, never file names.
        // Left out when no check ran lately; a hub that predates it ignores it.
        $integrity = ModuleIntegrity::counts();
        if ($integrity !== null) {
            $report['module_integrity'] = (object) $integrity;
        }

        return $report;
    }

    /**
     * The basic report: schema, domain, app version, release and the module rows without the revoked flag. Nothing
     * else is read, so no health probe runs.
     *
     * @return array<string, mixed>
     */
    private function basic(): array
    {
        $modules = array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(self::BASIC_MODULE_KEYS)),
            $this->modules(),
        );

        return array_combine(self::BASIC_KEYS, [
            self::SCHEMA,
            app(HubCredentials::class)->domain(),
            $this->text(config('ispa-cms.app_version')),
            $this->deployInfo()['release'],
            $modules,
        ]);
    }

    /**
     * The stored level, or null when none is saved or the value is not a level.
     */
    private function storedLevel(): ?string
    {
        try {
            return self::valid(Setting::query()->where('setting_key', self::SETTING_KEY)->first()?->value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Release, revision and deploy time written by the deploy (null when absent).
     *
     * @return array{release: ?string, revision: ?string, deployed_at: ?string}
     */
    private function deployInfo(): array
    {
        $info = ['release' => null, 'revision' => null, 'deployed_at' => null];

        try {
            $file = base_path('.deploy-info.json');
            $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
            if (is_array($data)) {
                foreach (array_keys($info) as $key) {
                    $info[$key] = $this->text($data[$key] ?? null);
                }
            }
            if ($info['revision'] === null && is_file(base_path('REVISION'))) {
                $info['revision'] = $this->text(trim((string) @file_get_contents(base_path('REVISION'))));
            }
        } catch (Throwable) {
            // Keep the nulls.
        }

        return $info;
    }

    /**
     * Modules known to this site: name, enabled flag, installed version, source and revoked flag (no paths).
     *
     * @return list<array{name: string, enabled: bool, version: ?string, source: ?string, revoked: bool}>
     */
    private function modules(): array
    {
        $rows = [];

        try {
            $catalog = app(ModuleCatalog::class);
            $revoked = RevocationAttentionProvider::revoked();
            foreach (ModuleFacade::all() as $module) {
                $name = $module->getName();
                $state = $catalog->installedState($name);
                $version = $state['installed_version'];
                // The copy that runs: a listed release module whose newer runtime copy wins reports `runtime`.
                $source = $state['source'] ?? $state['installed'];
                $rows[] = [
                    'name' => $name,
                    'enabled' => $state['enabled'],
                    'version' => $version,
                    'source' => in_array($source, ['release', 'runtime'], true) ? $source : null,
                    'revoked' => RevocationAttentionProvider::matches($revoked, $name, $version) !== null,
                ];
            }
        } catch (Throwable) {
            // Return what was collected.
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Migration files that have not run yet (count and up to 50 base names, no paths).
     *
     * @return array{count: ?int, names: list<string>}
     */
    private function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
            $repository = app('migration.repository');
            // Without a migrations table nothing has run yet, so every file is pending.
            $ran = $repository->repositoryExists() ? $repository->getRan() : [];
            $pending = array_values(array_diff(array_keys($files), $ran));
            sort($pending);

            return ['count' => count($pending), 'names' => array_slice(array_map('basename', $pending), 0, self::MAX_MIGRATION_NAMES)];
        } catch (Throwable) {
            return ['count' => null, 'names' => []];
        }
    }

    /**
     * Database, cache, queue and disk health.
     *
     * @return array{db: string, cache: string, queue: array{connection: ?string, failed_24h: ?int}, disk_free_percent: ?int}
     */
    private function health(): array
    {
        $db = 'fail';
        try {
            DB::select('select 1');
            $db = 'ok';
        } catch (Throwable) {
        }

        $cache = 'fail';
        try {
            $key = 'platform:report:probe:' . bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 30);
            $cache = Cache::get($key) === 'ok' ? 'ok' : 'fail';
            Cache::forget($key);
        } catch (Throwable) {
        }

        $failed = null;
        try {
            if (Schema::hasTable('failed_jobs')) {
                $failed = (int) DB::table('failed_jobs')->where('failed_at', '>=', Carbon::now()->subDay())->count();
            }
        } catch (Throwable) {
        }

        $disk = null;
        try {
            $free = @disk_free_space(base_path());
            $total = @disk_total_space(base_path());
            if ($free !== false && $total !== false && $total > 0) {
                $disk = (int) round($free / $total * 100);
            }
        } catch (Throwable) {
        }

        return [
            'db' => $db,
            'cache' => $cache,
            'queue' => ['connection' => $this->text(config('queue.default')), 'failed_24h' => $failed],
            'disk_free_percent' => $disk,
        ];
    }

    /**
     * Last application backup and the server nightly backup status.
     *
     * @return array{last_app: array{at: ?string, status: ?string}, server_nightly: array{date: ?string, ok: ?bool, stale: ?bool}}
     */
    private function backups(): array
    {
        $lastApp = ['at' => null, 'status' => null];
        try {
            $backup = Backup::query()->whereNotNull('finished_at')->orderByDesc('finished_at')->first();
            if ($backup !== null) {
                $lastApp = ['at' => $backup->finished_at?->toIso8601String(), 'status' => $this->text($backup->status)];
            }
        } catch (Throwable) {
        }

        $nightly = ['date' => null, 'ok' => null, 'stale' => null];
        try {
            $status = (new ServerNightlyStatus())->read();
            if (in_array($status['state'], ['ok', 'failed', 'stale'], true)) {
                $nightly = ['date' => $status['date'], 'ok' => $status['state'] === 'ok', 'stale' => $status['state'] === 'stale'];
            }
        } catch (Throwable) {
        }

        return ['last_app' => $lastApp, 'server_nightly' => $nightly];
    }

    /**
     * Non-empty string or null.
     */
    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_scalar($value) && $value !== '' && $value !== null ? (string) $value : null);
    }
}
