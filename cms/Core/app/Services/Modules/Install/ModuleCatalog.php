<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Support\Modules\RuntimeModules;
use App\Support\Modules\RuntimePrecedence;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Closure;
use Illuminate\Support\Facades\Cache;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Installable modules published on the platform hub, plus what this site already has.
 *
 * The hub answer (`GET /api/v2/public/platform/catalog`, verified by PlatformHubClient) is cached for 10 minutes
 * per site; a failed or badly signed answer throws and is never cached, so an error can not look like an empty
 * catalog. Expected hub payload:
 *
 *   {"modules": [{"name": "Foo", "latest_version": "2026.1.0", "versions": [
 *       {"version": "2026.1.0", "revoked": false, "revoked_reason": null, "requires": {"Core": ">=2026.03.0"},
 *        "irreversible": false, "custom_code": false, "php_min": "8.3.0",
 *        "migrations": ["2026_01_01_000000_create_foo_items_table"]}]}]}
 * `migrations` (, optional) lists the migration names of the signed package; it only sets the default of the
 * pre-install backup switch ({@see InstallBackupAdvice}).
 *
 * `listing` (, optional per module) is the store summary `{category, publisher: {key, type, name}, icon_token,
 * content: {vi|en: {display_name, summary}}, installs, price_note}` (`installs` and `price_note`); it is validated by {@see ModuleListingClient::summary} (capped
 * strings, publisher type from the known set, icon token replaced by a local `icon_hash`) and is `null` when absent or
 * malformed, so an older hub or a bad summary never breaks the catalog.
 *
 * `publisher` (, optional per module) is `{key, type, name}` of a module that iSPA does not publish; the hub sends
 * it whether or not a listing is live, and the entry has the key only when it is valid and not `ispa`.
 *
 * `renewal` (, optional per module) is `{reason: expired|updates_ended|grace, date: ISO 8601, version: ?string}`:
 * the grant ended (the hub then lists no version), the update window of a purchase ended before `version`, or the
 * grant is in grace until `date`. It drives the store's "Gia hạn" note only; it never disables a module. A malformed
 * note, or none from an older hub, is `null`.
 */
final class ModuleCatalog
{
    /** Hub path of the signed catalog. */
    public const HUB_PATH = '/api/v2/public/platform/catalog';

    /** Cache lifetime of the hub answer in seconds. */
    public const TTL_SECONDS = 600;

    /** @var (Closure(): PlatformHubClient)|null */
    private ?Closure $clientFactory;

    /**
     * @param (callable(): PlatformHubClient)|null $clientFactory builds the hub client lazily (default: from config)
     * @param string|null                          $releaseModulesPath release modules folder (default: config modules.paths.modules)
     */
    public function __construct(?callable $clientFactory = null, private readonly ?string $releaseModulesPath = null)
    {
        $this->clientFactory = $clientFactory !== null ? Closure::fromCallable($clientFactory) : null;
    }

    /**
     * Hub catalog entries with this site's installed state added.
     *
     * Each entry: name, latest_version, versions[], installed (release|runtime|none), enabled, installed_version,
     * upgrade_available (bool: an installed runtime module has a newer, not revoked version on the hub),
     * upgrade_version (that newest version, or null) and updates_unavailable (the stored entitlement state is expired
     * or revoked, the hub's renewal note says the grant expired, or the hub no longer lists an installed runtime
     * module; then no upgrade is offered and the module keeps running). Release modules are git-deployed and never offer an upgrade.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PlatformHubException when the hub cannot be reached or its answer is not trusted
     */
    public function entries(): array
    {
        $hub = $this->hubEntries();
        $rows = array_map(function (array $entry): array {
            $state = $this->installedState($entry['name']);
            $offer = $this->upgradeOffer($entry, $state);

            $ended = ($entry['renewal']['reason'] ?? null) === 'expired';

            return $entry + $state + $offer + ['updates_unavailable' => $ended || EntitlementAttentionProvider::blocksUpdates($entry['name'])];
        }, $hub);

        // A runtime module the hub no longer lists (hidden after an entitlement ended, or unpublished) stays visible.
        $listed = array_column($hub, 'name');
        foreach ($this->runtimeNames() as $name) {
            if (! in_array($name, $listed, true)) {
                $rows[] = [
                    'name' => $name,
                    'latest_version' => null,
                    'versions' => [],
                    'listing' => null,
                    'renewal' => null,
                    'upgrade_available' => false,
                    'upgrade_version' => null,
                    'updates_unavailable' => true,
                ] + $this->installedState($name);
            }
        }

        return $rows;
    }

    /**
     * Newest non-revoked hub version above the installed version of a runtime module.
     *
     * @param array{name: string, versions: list<array<string, mixed>>} $entry
     * @param array{installed: string, installed_version: ?string} $state
     *
     * @return array{upgrade_available: bool, upgrade_version: ?string}
     */
    private function upgradeOffer(array $entry, array $state): array
    {
        $best = null;
        if (($state['installed'] === 'runtime' || ($state['handover'] ?? false) === true) && $state['installed_version'] !== null
            && ! EntitlementAttentionProvider::blocksUpdates((string) $entry['name'])) {
            foreach ($entry['versions'] as $version) {
                $number = (string) $version['version'];
                if (! $version['revoked'] && version_compare($number, $state['installed_version'], '>')
                    && ($best === null || version_compare($number, $best, '>'))) {
                    $best = $number;
                }
            }
        }

        return ['upgrade_available' => $best !== null, 'upgrade_version' => $best];
    }

    /**
     * Raw validated hub entries (cached 10 minutes).
     *
     * @return list<array{name: string, latest_version: ?string, versions: list<array<string, mixed>>}>
     *
     * @throws PlatformHubException
     */
    public function hubEntries(): array
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $client = $this->clientFactory !== null ? ($this->clientFactory)() : new PlatformHubClient();
        $entries = $this->validated($client->get(self::HUB_PATH));
        Cache::put($key, $entries, self::TTL_SECONDS);

        return $entries;
    }

    /**
     * One published version: ['module' => hub entry, 'version' => version entry], or null when not published.
     *
     * @return array{module: array<string, mixed>, version: array<string, mixed>}|null
     *
     * @throws PlatformHubException
     */
    public function find(string $name, string $version): ?array
    {
        foreach ($this->hubEntries() as $entry) {
            if ($entry['name'] !== $name) {
                continue;
            }
            foreach ($entry['versions'] as $candidate) {
                if ($candidate['version'] === $version) {
                    return ['module' => $entry, 'version' => $candidate];
                }
            }
        }

        return null;
    }

    /**
     * Drop the cached hub answer.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Where a module is installed on this site and with which version.
     *
     * `installed` is `release` (folder in the release modules folder), `runtime` (runtime folder) or `none`. The
     * version comes from module.json (manifest v2) and falls back to composer.json, as ModuleController does.
     *
     * `handover` is true for a release module listed in runtime_modules.json (it may get an online copy while
     * the release copy stays as the fallback). `source` is the copy that actually runs: `runtime` when
     * RuntimePrecedence prefers the runtime copy, and then `installed_version` is the runtime version. `installed`
     * stays `release` for such a module, so it is never uninstalled online.
     *
     * @return array{installed: string, enabled: bool, installed_version: ?string, source: string, handover: bool}
     */
    public function installedState(string $name): array
    {
        $dir = null;
        $source = 'none';
        if (in_array($name, $this->releaseNames(), true)) {
            $source = 'release';
            $dir = $this->releasePath() . DIRECTORY_SEPARATOR . $name;
        } elseif (in_array($name, $this->runtimeNames(), true)) {
            $source = 'runtime';
            $dir = RuntimeModules::path() . DIRECTORY_SEPARATOR . $name;
        }

        $enabled = false;
        if ($source !== 'none') {
            try {
                $enabled = ModuleFacade::find($name)?->isEnabled() === true;
            } catch (Throwable) {
                $enabled = false;
            }
        }

        $version = $dir !== null ? $this->versionIn($dir) : null;
        $handover = $source === 'release' && $this->isHandover($name);
        $running = $source;
        if ($handover) {
            $decision = RuntimePrecedence::decide($this->basePath(), $name);
            if ($decision['runtime']) {
                $running = 'runtime';
                $version = $decision['runtime_version'];
            }
        }

        return [
            'installed' => $source,
            'enabled' => $enabled,
            'installed_version' => $version,
            'source' => $running,
            'handover' => $handover,
        ];
    }

    /**
     * True for a release module listed in the project's runtime_modules.json: an online copy may be placed
     * beside the release copy, which stays as the fallback.
     */
    public function isHandover(string $name): bool
    {
        return in_array($name, $this->releaseNames(), true)
            && in_array($name, RuntimePrecedence::listed($this->basePath()), true);
    }

    /**
     * Version a handover package must exceed: the higher of the release copy and a present runtime copy.
     *
     * Null when the release version, or the version of a runtime copy that is present, can not be read.
     */
    public function handoverBaseline(string $name): ?string
    {
        $release = $this->versionIn($this->releasePath() . DIRECTORY_SEPARATOR . $name);
        if ($release === null || preg_match('/^\d+(?:\.\d+)*$/', $release) !== 1) {
            return null;
        }
        $runtimeRoot = RuntimeModules::path();
        if ($runtimeRoot === null || @lstat($runtimeRoot . DIRECTORY_SEPARATOR . $name) === false) {
            return $release;
        }
        $runtime = $this->versionIn($runtimeRoot . DIRECTORY_SEPARATOR . $name);
        if ($runtime === null || preg_match('/^\d+(?:\.\d+)*$/', $runtime) !== 1) {
            return null;
        }

        return version_compare($runtime, $release, '>') ? $runtime : $release;
    }

    /**
     * Application base path that holds runtime_modules.json: the parent of the release modules folder.
     */
    public function basePath(): string
    {
        return dirname($this->releasePath());
    }

    /**
     * Folder name of an installed module whose name matches $name without case, release folder first, then runtime.
     *
     * A module.json `requires` entry may name a module in any case (`questionbank` for `QuestionBank`); callers that
     * look up such an entry resolve it here before {@see self::installedState()}, which matches folder names exactly
     * (a case-only clash is a separate name conflict there). Returns $name unchanged when nothing matches.
     */
    public function installedName(string $name): string
    {
        foreach ([$this->releaseNames(), $this->runtimeNames()] as $names) {
            if (in_array($name, $names, true)) {
                return $name;
            }
            foreach ($names as $folder) {
                if (ModuleVersionConstraint::sameModule($folder, $name)) {
                    return $folder;
                }
            }
        }

        return $name;
    }

    /**
     * Folder names in the release modules folder.
     *
     * @return list<string>
     */
    public function releaseNames(): array
    {
        return $this->folderNames($this->releasePath());
    }

    /**
     * Folder (or link) names in the runtime modules folder, dot entries excluded.
     *
     * @return list<string>
     */
    public function runtimeNames(): array
    {
        $path = RuntimeModules::path();

        return $path === null ? [] : $this->folderNames($path);
    }

    /**
     * Absolute path of the release modules folder.
     */
    public function releasePath(): string
    {
        return rtrim($this->releaseModulesPath ?? (string) config('modules.paths.modules', base_path('cms')), '/');
    }

    /**
     * Check the hub payload shape and normalise it.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return list<array{name: string, latest_version: ?string, versions: list<array<string, mixed>>, listing: ?array<string, mixed>, renewal: ?array{reason: string, date: string, version: ?string}}>
     */
    private function validated(array $payload): array
    {
        $modules = $payload['modules'] ?? null;
        if (!is_array($modules)) {
            throw new PlatformHubException(PlatformHubException::BAD_RESPONSE, 'Platform hub catalog has an unexpected shape.');
        }

        $entries = [];
        foreach ($modules as $module) {
            $name = is_array($module) ? ($module['name'] ?? null) : null;
            if (!is_string($name) || preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $name) !== 1) {
                throw new PlatformHubException(PlatformHubException::BAD_RESPONSE, 'Platform hub catalog has an unexpected shape.');
            }

            $versions = [];
            foreach ((array) ($module['versions'] ?? []) as $version) {
                $number = is_array($version) ? ($version['version'] ?? null) : null;
                if (!is_string($number) || preg_match('/^\d+\.\d+\.\d+$/', $number) !== 1) {
                    throw new PlatformHubException(PlatformHubException::BAD_RESPONSE, 'Platform hub catalog has an unexpected shape.');
                }
                $versions[] = [
                    'version' => $number,
                    'revoked' => (bool) ($version['revoked'] ?? false),
                    'revoked_reason' => is_string($version['revoked_reason'] ?? null) ? $version['revoked_reason'] : null,
                    'requires' => is_array($version['requires'] ?? null) ? $version['requires'] : [],
                    'irreversible' => $version['irreversible'] ?? false,
                    'custom_code' => (bool) ($version['custom_code'] ?? false),
                    'php_min' => is_string($version['php_min'] ?? null) ? $version['php_min'] : null,
                    // Migration names of the signed package; null from a hub that predates the field.
                    'migrations' => InstallBackupAdvice::migrationsFromCatalog($version['migrations'] ?? null),
                ];
            }

            $entry = [
                'name' => $name,
                'latest_version' => is_string($module['latest_version'] ?? null) ? $module['latest_version'] : null,
                'versions' => $versions,
                // Optional store summary; null from a hub that predates listings or has none live.
                'listing' => ModuleListingClient::summary($name, $module['listing'] ?? null),
                // Optional renewal note; null from a hub that predates it or when malformed.
                'renewal' => self::renewal($module['renewal'] ?? null),
            ];
            // Publisher of a module that iSPA does not publish, also without a live listing; the key is
            // left out otherwise, so an iSPA entry keeps its old shape.
            $publisher = self::publisher($module['publisher'] ?? null);
            if ($publisher !== null) {
                $entry['publisher'] = $publisher;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The validated `{key, type, name}` publisher of a hub entry, or null when absent, malformed or iSPA.
     *
     * @return array{key: string, type: string, name: string}|null
     */
    private static function publisher(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $key = $raw['key'] ?? null;
        $name = $raw['name'] ?? null;
        $type = $raw['type'] ?? null;
        if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $key) !== 1
            || !is_string($name) || trim($name) === '' || mb_strlen($name) > 120
            || !is_string($type) || $type === 'ispa' || !in_array($type, ModuleListingClient::PUBLISHER_TYPES, true)) {
            return null;
        }

        return ['key' => $key, 'type' => $type, 'name' => trim($name)];
    }

    /**
     * The validated renewal note of a hub entry, or null.
     *
     * @return array{reason: string, date: string, version: ?string}|null
     */
    private static function renewal(mixed $note): ?array
    {
        if (!is_array($note) || !in_array($note['reason'] ?? null, ['expired', 'updates_ended', 'grace'], true)) {
            return null;
        }
        $date = $note['date'] ?? null;
        if (!is_string($date) || strlen($date) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $date) !== 1
            || strtotime($date) === false) {
            return null;
        }
        $version = $note['version'] ?? null;

        return [
            'reason' => $note['reason'],
            'date' => $date,
            'version' => is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : null,
        ];
    }

    /**
     * Cache key unique per site (domain and hub URL).
     */
    private function cacheKey(): string
    {
        return 'platform:module-catalog:v1:' . sha1(
            (string) config('ispa-cms.cms_domain', '') . '|' . (string) config('ispa-cms.platform_hub_url', '')
        );
    }

    /**
     * Directory (or link to a directory) names below $path, skipping dot entries.
     *
     * @return list<string>
     */
    private function folderNames(string $path): array
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        $names = [];
        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * Version of a module folder: module.json first, then composer.json.
     */
    private function versionIn(string $dir): ?string
    {
        foreach (['module.json', 'composer.json'] as $file) {
            $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . $file);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded) && is_string($decoded['version'] ?? null) && $decoded['version'] !== '') {
                return $decoded['version'];
            }
        }

        return null;
    }
}
