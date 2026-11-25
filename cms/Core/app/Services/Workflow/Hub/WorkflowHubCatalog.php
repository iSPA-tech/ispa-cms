<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Nwidart\Modules\Facades\Module;
use Throwable;

/**
 * Site-side reader of the workflow template library on the hub (`/api/v2/public/platform/workflows`) through Core's
 * signed {@see PlatformHubClient}, resolved from the container on each call; the admin API is WorkflowLibraryController.
 *
 * The hub catalog is cached 10 minutes per site (key includes app.url), like the widget hub. Site state is added on
 * every read: `available` / `missing_modules` (required modules that are off here; Core is always on), `installed`
 * (the newest hub install of the key: project id, slug, name, version, modified, is_active), `update_available`, and the
 * summary rendered in the admin's language. Catalog rows are not signed; detail() verifies the signed bundle and
 * answers from it. Any hub failure is {@see WorkflowHubException::UNREACHABLE}, a hub 404 is NOT_FOUND.
 */
class WorkflowHubCatalog
{
    public const PATH = '/api/v2/public/platform/workflows';

    public const CACHE_KEY = 'workflow-hub:catalog';

    public const TTL_SECONDS = 600;

    /** Admin page of the setup questions (base-admin route `setting-workflows-setup`). */
    public const SETUP_PATH = '/setting/automation/workflows/setup/';

    public const PLAN_NEW = 'new';

    public const PLAN_COPY = 'copy';

    public const PLAN_UPDATE = 'update';

    public const PLAN_CURRENT = 'current';

    public function __construct(protected WorkflowBundleVerifier $verifier, protected WorkflowTemplateHash $hashes)
    {
    }

    /**
     * Every template the hub shows this site, with site state.
     *
     * @return list<array<string, mixed>>
     *
     * @throws WorkflowHubException
     */
    public function list(bool $refresh = false): array
    {
        $entries = $refresh ? null : Cache::get(self::cacheKey());
        if (!is_array($entries)) {
            $entries = $this->fetchCatalog();
            Cache::put(self::cacheKey(), $entries, self::TTL_SECONDS);
        }
        $installed = $this->installedByKey();

        return array_map(fn (array $entry): array => $this->present($entry, $installed[$entry['key']] ?? []), $entries);
    }

    /**
     * One template from its verified signed bundle (latest version): the list fields plus `summary_lines`
     * (`{does, asks, may, tags}`), `notes` in the admin's language, `signature` (`{verified: true, key_id, publisher}`)
     * and what install would do: `update_in_place` (the newest install is not modified and this version is newer: its
     * draft is updated, setup answers kept), `install_as_copy` / `copy_reason` (`modified`, or null).
     *
     * @return array<string, mixed>
     *
     * @throws WorkflowHubException
     */
    public function detail(string $key): array
    {
        ['bundle' => $bundle, 'key_id' => $keyId] = $this->verifiedBundle($key);
        $projects = $this->installedByKey()[$bundle->key] ?? [];
        $item = $this->present(self::entryOf($bundle), $projects);
        $plan = $this->plan($projects, false, $bundle->version);

        return $item + [
            'signature' => ['verified' => true, 'key_id' => $keyId, 'publisher' => $bundle->publisher],
            'update_in_place' => $plan['mode'] === self::PLAN_UPDATE,
            'install_as_copy' => $plan['mode'] === self::PLAN_COPY,
            'copy_reason' => $plan['reason'],
        ];
    }

    /**
     * The verified bundle of the latest version of a template.
     *
     * @return array{bundle: WorkflowBundle, key_id: string}
     *
     * @throws WorkflowHubException
     */
    public function verifiedBundle(string $key): array
    {
        if (preg_match(WorkflowBundle::KEY_PATTERN, $key) !== 1) {
            throw WorkflowHubException::notFound();
        }
        try {
            $payload = $this->client()->get(self::PATH . '/' . $key);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
                throw WorkflowHubException::notFound();
            }
            throw WorkflowHubException::unreachable($e);
        }
        $sig = $payload['sig_json'] ?? null;
        if (!is_string($sig) || $sig === '') {
            throw WorkflowHubException::unreachable();
        }
        $verified = $this->verifier->verify($sig);
        if ($verified['bundle']->key !== $key) {
            throw WorkflowHubException::untrusted('the hub answered with the bundle of another template');
        }

        return $verified;
    }

    /**
     * Refuse a signed bundle older than the version the hub's catalog advertises for its key (review fix 34): a replayed
     * older bundle verifies, so the signature alone cannot tell it is stale. A cached catalog that says newer is read
     * again from the hub once before refusing; a key the catalog does not list is not compared.
     *
     * @throws WorkflowHubException UNTRUSTED (422) for a stale bundle, UNREACHABLE when the catalog cannot be read.
     */
    public function assertNotOlderThanAdvertised(WorkflowBundle $bundle): void
    {
        foreach ([false, true] as $refresh) {
            $entries = $refresh ? null : Cache::get(self::cacheKey());
            if (!is_array($entries)) {
                $entries = $this->fetchCatalog();
                Cache::put(self::cacheKey(), $entries, self::TTL_SECONDS);
            }
            $advertised = 0;
            foreach ($entries as $entry) {
                if (($entry['key'] ?? null) === $bundle->key) {
                    $advertised = max($advertised, (int) $entry['latest_version']);
                }
            }
            if ($advertised <= $bundle->version) {
                return;
            }
        }

        throw WorkflowHubException::untrusted(sprintf(
            'the hub sent version %s of this template but lists %s as the latest; try again later',
            WorkflowBundle::versionLabel($bundle->version),
            WorkflowBundle::versionLabel($advertised),
        ));
    }

    /**
     * Hub installs of one key on this site, newest first (by version, then id).
     *
     * @return list<WorkflowProject>
     */
    public function installsOf(string $key): array
    {
        return $this->installedByKey()[$key] ?? [];
    }

    /**
     * What installing `$version` of a key does here, judged on the newest install ({@see installsOf()}):
     * - `new`: no install yet (or the caller asked for a copy: `copy` with reason `asked`);
     * - `copy` with reason `modified`: the newest install was changed here beyond setup answers; it is never touched;
     * - `update`: the newest install is not modified and `$version` is newer than its `source.version`; its draft is
     *   updated in place ({@see WorkflowHubInstaller});
     * - `current`: the newest install is not modified and already at `$version` or newer; install is refused.
     *
     * @param list<WorkflowProject> $projects
     * @return array{mode: string, reason: ?string, project: ?WorkflowProject}
     */
    public function plan(array $projects, bool $asked, int $version): array
    {
        $latest = $projects[0] ?? null;
        if ($asked) {
            return ['mode' => self::PLAN_COPY, 'reason' => 'asked', 'project' => null];
        }
        if ($latest === null) {
            return ['mode' => self::PLAN_NEW, 'reason' => null, 'project' => null];
        }
        if ($this->hashes->modified($latest)) {
            return ['mode' => self::PLAN_COPY, 'reason' => 'modified', 'project' => $latest];
        }

        return $version > (int) ($latest->source['version'] ?? 0)
            ? ['mode' => self::PLAN_UPDATE, 'reason' => null, 'project' => $latest]
            : ['mode' => self::PLAN_CURRENT, 'reason' => null, 'project' => $latest];
    }

    /**
     * Required modules that are off on this site (Core is always on).
     *
     * @param list<string> $modules
     * @return list<string>
     */
    public function missingModules(array $modules): array
    {
        $missing = [];
        foreach ($modules as $name) {
            if (strcasecmp($name, 'Core') !== 0 && !$this->moduleEnabled($name)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    public static function setupUrl(int $projectId): string
    {
        return self::SETUP_PATH . $projectId;
    }

    protected function moduleEnabled(string $name): bool
    {
        try {
            $module = Module::find($name);
        } catch (Throwable) {
            return false;
        }

        return $module !== null && $module->isEnabled();
    }

    /**
     * @param array<string, mixed>  $entry
     * @param list<WorkflowProject> $projects
     * @return array<string, mixed>
     */
    protected function present(array $entry, array $projects): array
    {
        $missing = $this->missingModules($entry['requires']['modules']);
        $latest = $projects[0] ?? null;
        $installed = null;
        if ($latest !== null) {
            $version = (int) ($latest->source['version'] ?? 0);
            $installed = [
                'project_id' => (int) $latest->getKey(),
                'slug' => (string) $latest->slug,
                'name' => (string) $latest->name,
                'version' => $version,
                'version_label' => WorkflowBundle::versionLabel($version),
                'modified' => $this->hashes->modified($latest),
                'is_active' => (bool) $latest->is_active,
                'setup_url' => self::setupUrl((int) $latest->getKey()),
                'copies' => count($projects),
            ];
        }

        return [
            'key' => $entry['key'],
            'publisher' => $entry['publisher'],
            'name' => $entry['name'],
            'description' => $entry['description'],
            'group' => $entry['group'],
            'latest_version' => $entry['latest_version'],
            'latest_version_label' => WorkflowBundle::versionLabel($entry['latest_version']),
            'requires' => $entry['requires'],
            'summary' => $entry['summary'],
            'summary_lines' => WorkflowBundleSummary::lines($entry['summary']),
            'notes' => self::localNotes($entry['notes']),
            'available' => $missing === [],
            'missing_modules' => $missing,
            'installed' => $installed,
            'update_available' => $installed !== null && $entry['latest_version'] > $installed['version'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function entryOf(WorkflowBundle $bundle): array
    {
        return [
            'key' => $bundle->key, 'publisher' => $bundle->publisher, 'name' => $bundle->name, 'description' => $bundle->description,
            'group' => $bundle->group, 'latest_version' => $bundle->version, 'requires' => $bundle->requires,
            'summary' => $bundle->summary, 'notes' => $bundle->notes,
        ];
    }

    /** Notes in the admin's language, falling back to Vietnamese then English. */
    protected static function localNotes(?array $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $locale = substr((string) App::getLocale(), 0, 2);

        return $notes[$locale] ?? $notes['vi'] ?? $notes['en'] ?? null;
    }

    /**
     * Hub installs by template key, newest first.
     *
     * @return array<string, list<WorkflowProject>>
     */
    protected function installedByKey(): array
    {
        $byKey = [];
        foreach (WorkflowProject::query()->whereNotNull('source')->get() as $project) {
            $source = $project->source;
            if (is_array($source) && ($source['kind'] ?? null) === 'hub' && is_string($source['key'] ?? null)) {
                $byKey[$source['key']][] = $project;
            }
        }
        foreach ($byKey as &$projects) {
            usort($projects, static fn (WorkflowProject $a, WorkflowProject $b): int => [(int) ($b->source['version'] ?? 0), (int) $b->getKey()] <=> [(int) ($a->source['version'] ?? 0), (int) $a->getKey()]);
        }

        return $byKey;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchCatalog(): array
    {
        try {
            $payload = $this->client()->get(self::PATH);
        } catch (PlatformHubException $e) {
            throw WorkflowHubException::unreachable($e);
        }
        if (!is_array($payload['workflows'] ?? null)) {
            throw WorkflowHubException::unreachable();
        }
        $entries = [];
        foreach ($payload['workflows'] as $row) {
            $entry = self::entry($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * One catalog row with only known fields and checked types; null for a malformed row.
     *
     * @return array<string, mixed>|null
     */
    protected static function entry(mixed $row): ?array
    {
        if (!is_array($row) || !is_string($row['key'] ?? null) || preg_match(WorkflowBundle::KEY_PATTERN, $row['key']) !== 1
            || !is_int($row['latest_version'] ?? null) || $row['latest_version'] < 1 || !is_string($row['name'] ?? null)) {
            return null;
        }
        $requires = is_array($row['requires'] ?? null) ? $row['requires'] : [];
        $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
        $strings = static fn (mixed $v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));

        return [
            'key' => $row['key'],
            'publisher' => is_string($row['publisher'] ?? null) ? $row['publisher'] : null,
            'name' => mb_substr($row['name'], 0, WorkflowBundle::NAME_MAX),
            'description' => is_string($row['description'] ?? null) ? mb_substr($row['description'], 0, WorkflowBundle::DESCRIPTION_MAX) : null,
            'group' => is_string($row['group'] ?? null) ? $row['group'] : null,
            'latest_version' => $row['latest_version'],
            'requires' => ['modules' => $strings($requires['modules'] ?? null), 'node_types' => is_array($requires['node_types'] ?? null) ? $requires['node_types'] : []],
            'summary' => [
                'steps' => array_values(array_filter(is_array($summary['steps'] ?? null) ? $summary['steps'] : [], 'is_array')),
                'safety_classes' => $strings($summary['safety_classes'] ?? null),
                'outbound_hosts' => $strings($summary['outbound_hosts'] ?? null),
                'credential_kinds' => $strings($summary['credential_kinds'] ?? null),
                'needs_approval' => ($summary['needs_approval'] ?? false) === true,
                'setup_questions' => array_values(array_filter(is_array($summary['setup_questions'] ?? null) ? $summary['setup_questions'] : [], 'is_array')),
            ],
            'notes' => is_array($row['notes'] ?? null) ? WorkflowBundle::normalNotes($row['notes']) : null,
        ];
    }

    /** Per site, so sites sharing one cache store never see each other's catalog. */
    protected static function cacheKey(): string
    {
        return self::CACHE_KEY . ':' . sha1((string) config('app.url', ''));
    }

    protected function client(): PlatformHubClient
    {
        return app(PlatformHubClient::class);
    }
}
