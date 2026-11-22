<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Support\Modules\ActivatorMarker;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Platform\SiteStatusService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether this site may install runtime modules (spec 2026-11-22 module install from the admin).
 *
 * Effective permission = not forbidden by the server AND not forbidden by the hub AND switched on:
 * - server: config `core.module_packages.install_enabled` (`MODULE_INSTALL_ENABLED`) is tri-state. `false` forbids
 *   (the admin switch is locked), `true` keeps the old meaning (on without the switch), unset lets the switch decide;
 * - hub: {@see self::hubForbids()}: the last known `module_install` word of the backend hub verification (SiteStatusService: 15
 *   minute cache, 7 day grace keeps the last known value). No known value lets the switch decide;
 * - admin: the Webmaster's "allow module install" switch, a Core setting row (`modules.install_enabled`), off by
 *   default, so existing sites behave as before until it is switched on.
 *
 * Switching on (`enable()`) also prepares the site: it creates the runtime modules folder when it is missing, writes
 * the activator marker ({@see ActivatorMarker}) so module status moves to the database (the DatabaseActivator imports
 * `modules_statuses.json` once, keeping every enabled module enabled), when the configuration is cached rebuilds
 * that cache in a fresh PHP process so the new activator and scan path apply, and with a real queue signals the
 * workers to restart (`queue:restart`) so they load that configuration too. It never writes `.env` and never clears
 * the application cache. Switching off
 * only stores the setting: the marker and the folder stay, because module status then lives in the database.
 */
class ModuleInstallPolicy
{
    public const SETTING_KEY = 'modules.install_enabled';

    public const STATE_ALLOWED = 'allowed';

    public const STATE_DISABLED = 'disabled';

    public const STATE_FORBIDDEN_BY_SERVER = 'forbidden_by_server';

    public const STATE_FORBIDDEN_BY_HUB = 'forbidden_by_hub';

    /** What the server says: `on`, `off` or `unset` (the admin decides). */
    public const SERVER_ON = 'on';

    public const SERVER_OFF = 'off';

    public const SERVER_UNSET = 'unset';

    public const AUDIT_ENABLED = 'modules.install_enabled';

    public const AUDIT_DISABLED = 'modules.install_disabled';

    /** Mode of the runtime folders, as the deploy creates them (group-writable, setgid). */
    public const FOLDER_MODE = 02775;

    /**
     * @param ArtisanSubprocess|null $artisan runs `config:cache` and `queue:restart` in a fresh process
     */
    public function __construct(
        private readonly ?ArtisanSubprocess $artisan = null,
    ) {
    }

    /**
     * Server say from config: true, false or null (unset).
     */
    public function serverValue(): ?bool
    {
        $value = config('core.module_packages.install_enabled');
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Whether the hub forbids installs on this site: the last known `module_install` word of the backend hub
     * verification (cached answer, else the last good answer within the grace) is `forbidden`. Never asks the hub
     * itself, so the install path never waits on it; no known value (or a failure reading it) never forbids: the hub
     * is not a dependency for installing signed packages.
     */
    public function hubForbids(): bool
    {
        try {
            return app(SiteStatusService::class)->knownModuleInstall() === SiteStatusService::MODULE_INSTALL_FORBIDDEN;
        } catch (Throwable $e) {
            Log::warning('The hub module install flag could not be read; the admin switch decides.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The Webmaster's stored switch (false when absent or unreadable).
     */
    public function settingEnabled(): bool
    {
        try {
            $value = Setting::query()->where('setting_key', self::SETTING_KEY)->value('value');
        } catch (Throwable) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * Effective state: one of the STATE_* constants.
     */
    public function state(): string
    {
        $server = $this->serverValue();

        return match (true) {
            $server === false => self::STATE_FORBIDDEN_BY_SERVER,
            $this->hubForbids() => self::STATE_FORBIDDEN_BY_HUB,
            $server === true, $this->settingEnabled() => self::STATE_ALLOWED,
            default => self::STATE_DISABLED,
        };
    }

    /**
     * Whether installs (and uninstalls) may run now.
     */
    public function allowed(): bool
    {
        return $this->state() === self::STATE_ALLOWED;
    }

    /**
     * Everything the admin switch shows.
     *
     * @return array{enabled: bool, state: string, setting: bool, locked: bool, server: string, activator: string, activator_source: string, runtime_folder: bool}
     */
    public function resolution(): array
    {
        $server = $this->serverValue();
        $state = $this->state();
        $forced = config('modules.activator_env');

        return [
            'enabled' => $state === self::STATE_ALLOWED,
            'state' => $state,
            'setting' => $this->settingEnabled(),
            // The switch cannot change anything while the server or the hub decides.
            'locked' => $server !== null || $state === self::STATE_FORBIDDEN_BY_HUB,
            'server' => match ($server) {
                true => self::SERVER_ON,
                false => self::SERVER_OFF,
                null => self::SERVER_UNSET,
            },
            'activator' => (string) config('modules.activator'),
            'activator_source' => is_string($forced) && $forced !== ''
                ? 'env'
                : (ActivatorMarker::read() === ActivatorMarker::DATABASE ? 'marker' : 'default'),
            'runtime_folder' => RuntimeModules::path() !== null,
        ];
    }

    /**
     * Switch install on: create the runtime folder, write the activator marker, store the setting, rebuild a
     * cached configuration and restart queue workers. Each step is idempotent, so a failed call can be repeated.
     *
     * @throws ModuleInstallSettingException When a step fails (its code names which).
     */
    public function enable(): void
    {
        $this->ensureRuntimeFolder();

        try {
            ActivatorMarker::writeDatabase();
        } catch (\RuntimeException $e) {
            throw new ModuleInstallSettingException(ModuleInstallSettingException::MARKER_FAILED, $e->getMessage(), $e);
        }

        $this->store(true);
        $this->rebuildConfigCache();
        $this->restartWorkers();
    }

    /**
     * Switch install off (the setting only).
     */
    public function disable(): void
    {
        $this->store(false);
    }

    /**
     * Id of the setting row (0 when absent), for the audit entry.
     */
    public function settingId(): int
    {
        return (int) Setting::query()->where('setting_key', self::SETTING_KEY)->value('id');
    }

    /**
     * Create the runtime modules folder when RuntimeModules::path() finds none.
     *
     * - a link at the expected place whose target is missing (a release deploy links `runtime-modules` to the shared
     *   `runtime/modules`): create the target;
     * - nothing there: create the storage fallback `storage/app/runtime-modules` ({@see RuntimeModules::location()}
     *   reads it from then on), so nothing is written into the code tree;
     * `.staging` and `.versions` are created inside with the deploy's mode.
     *
     * @throws ModuleInstallSettingException When the folder cannot be created.
     */
    public function ensureRuntimeFolder(): string
    {
        $existing = RuntimeModules::path();
        if ($existing !== null) {
            return $existing;
        }

        $expected = RuntimeModules::expectedPath();
        try {
            if (is_link($expected)) {
                $target = (string) readlink($expected);
                $dir = str_starts_with($target, DIRECTORY_SEPARATOR) ? $target : dirname($expected) . DIRECTORY_SEPARATOR . $target;
            } else {
                // Something that is not a folder in the way: makeFolder() refuses it.
                $dir = file_exists($expected) ? $expected : (RuntimeModules::fallbackPath() ?? $expected);
            }
            $this->makeFolder($dir);
            foreach (['.staging', '.versions'] as $child) {
                $this->makeFolder($dir . DIRECTORY_SEPARATOR . $child);
            }
        } catch (\RuntimeException $e) {
            throw new ModuleInstallSettingException(ModuleInstallSettingException::RUNTIME_FOLDER_FAILED, $e->getMessage(), $e);
        }

        $path = RuntimeModules::path();
        if ($path === null) {
            throw new ModuleInstallSettingException(ModuleInstallSettingException::RUNTIME_FOLDER_FAILED, 'The runtime modules folder is still missing.');
        }

        return $path;
    }

    /**
     * Create one folder with the deploy's mode.
     *
     * @throws \RuntimeException When it cannot be created.
     */
    private function makeFolder(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, self::FOLDER_MODE, true) && ! is_dir($dir)) {
            throw new \RuntimeException('The runtime modules folder could not be created.');
        }
        @chmod($dir, self::FOLDER_MODE);
    }

    /**
     * Store the switch in the settings table (same row shape as other Core admin settings).
     */
    private function store(bool $enabled): void
    {
        $setting = Setting::withTrashed()->where('setting_key', self::SETTING_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::SETTING_KEY,
            'type' => 'text',
            'group_name' => 'modules',
            'name' => 'install_enabled',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'Allow module install'],
            'value' => $enabled ? '1' : '0',
        ])->save();
    }

    /**
     * Rebuild `config:cache` in a fresh PHP process when the configuration is cached (the activator and the runtime
     * scan path are read while configuration loads). Never clears the application cache.
     *
     * @throws ModuleInstallSettingException When the rebuild fails.
     */
    private function rebuildConfigCache(): void
    {
        if (! app()->configurationIsCached()) {
            return;
        }

        try {
            $result = ($this->artisan ?? app(ArtisanSubprocess::class))->run(['config:cache'], 120);
            $ok = $result->successful();
            $output = $result->errorOutput() ?: $result->output();
        } catch (Throwable $e) {
            $ok = false;
            $output = $e->getMessage();
        }

        if (! $ok) {
            Log::warning('Rebuilding the config cache after switching module install on failed.', ['output' => $output]);

            throw new ModuleInstallSettingException(ModuleInstallSettingException::CONFIG_CACHE_FAILED, 'The configuration cache could not be rebuilt.');
        }
    }

    /**
     * With a real queue, signal the workers to restart (`queue:restart` in a fresh process): a running worker keeps
     * the configuration it started with (file activator, no runtime scan path) and would run installs with it.
     * `queue:restart` only writes the restart timestamp to the cache; it never clears the cache.
     *
     * @throws ModuleInstallSettingException When the signal fails.
     */
    private function restartWorkers(): void
    {
        if ((string) config('queue.default', 'sync') === 'sync') {
            return;
        }

        try {
            $result = ($this->artisan ?? app(ArtisanSubprocess::class))->run(['queue:restart'], 60);
            $ok = $result->successful();
            $output = $result->errorOutput() ?: $result->output();
        } catch (Throwable $e) {
            $ok = false;
            $output = $e->getMessage();
        }

        if (! $ok) {
            Log::warning('Restarting the queue workers after switching module install on failed.', ['output' => $output]);

            throw new ModuleInstallSettingException(ModuleInstallSettingException::WORKER_RESTART_FAILED, 'The queue workers could not be told to restart.');
        }
    }
}
