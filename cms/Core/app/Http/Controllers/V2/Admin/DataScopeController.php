<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Models\User;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\DataScope\DataScopeAssignment;
use Cms\Core\Models\DataScope\DataScopeGrant;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Responses\AppResponse;
use Illuminate\Http\Request;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\HierarchyProvider;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopeAssignmentService;
use Cms\Core\Services\DataScope\ScopeSummary;

/**
 * Data-scope management API: dimensions, node search, the scope of a role or a user, and replacing that scope.
 *
 * Writes go through ScopeAssignmentService (validation, anti-escalation guard, audit row) and answer with the
 * same shape as the matching read endpoint.
 *
 * Every route is gated by the `data_scope.manage` permission. Responses identify nodes by the provider's stable key
 * and never carry node ids.
 */
class DataScopeController extends AdminController
{
    /**
     * Maximum number of nodes returned by the node search.
     */
    protected const SEARCH_LIMIT = 20;

    /**
     * GET /api/v2/admin/data-scope/dimensions
     *
     * Enabled dimensions as `{ name, label, label_key, module, hierarchy }`. `label_key` is the admin translation key the
     * admin renders in its own locale; `label` stays for old callers.
     *
     * @param DimensionRegistry $dimensions Registered dimensions.
     * @param ManifestScopeCatalog $catalog Manifest catalog, source of the hierarchy kind.
     */
    public function dimensions(DimensionRegistry $dimensions, ManifestScopeCatalog $catalog): AppResponse
    {
        $declared = $catalog->dimensions();
        $list = [];

        foreach ($dimensions->all() as $name => $meta) {
            if ($dimensions->provider($name) === null) {
                continue;
            }

            $hierarchy = $declared[$name]['hierarchy'] ?? null;

            $list[] = [
                'name' => $name,
                'label' => (string) __($meta['label_key']),
                'label_key' => $meta['label_key'],
                'module' => $meta['module'],
                'hierarchy' => is_string($hierarchy) ? $hierarchy : null,
            ];
        }

        return ires()->data($list);
    }

    /**
     * GET /api/v2/admin/data-scope/dimensions/{dimension}/nodes?q=
     *
     * At most 20 nodes of the dimension matching the query by label, as `{ key, label, trail }`.
     *
     * @param string $dimension Dimension name.
     * @param DimensionRegistry $dimensions Registered dimensions.
     */
    public function nodes(string $dimension, DimensionRegistry $dimensions): AppResponse
    {
        $provider = $dimensions->provider($dimension);

        if ($provider === null) {
            return ires()->notFound();
        }

        $q = trim((string) request()->query('q', ''));
        $nodes = [];

        foreach ($provider->search($q, self::SEARCH_LIMIT) as $row) {
            $nodes[] = ['key' => $row['key'], 'label' => $row['label'], 'trail' => $provider->trail($row['id'])];

            if (count($nodes) >= self::SEARCH_LIMIT) {
                break;
            }
        }

        return ires()->data($nodes);
    }

    /**
     * GET /api/v2/admin/data-scope/roles/{id}
     *
     * The role's scoped permissions with their grant level, and its node assignments.
     *
     * @param int $id Role id.
     * @param ManifestScopeCatalog $catalog Manifest catalog of scope levels.
     * @param DimensionRegistry $dimensions Registered dimensions.
     */
    public function role(int $id, ManifestScopeCatalog $catalog, DimensionRegistry $dimensions): AppResponse
    {
        $role = Role::query()->with('permissions')->find($id);

        if ($role === null) {
            return ires()->notFound();
        }

        $levels = DataScopeGrant::query()->where('role_id', $role->id)->pluck('level', 'permission_key')->all();
        $grants = [];
        $labelKeys = $this->permissionLabelKeys();

        foreach ($role->permissions as $permission) {
            $key = $this->keyOf($permission);
            $allowed = $this->scopedLevels($catalog, $key);

            if ($allowed === null) {
                continue;
            }

            $grants[] = [
                'permission_key' => $key,
                'title' => (string) $permission->title,
                'label_key' => $labelKeys[$key] ?? null,
                'allowed_levels' => $allowed,
                'level' => (string) ($levels[$key] ?? 'all'),
            ];
        }

        usort($grants, static fn (array $a, array $b): int => $a['permission_key'] <=> $b['permission_key']);

        return ires()->data([
            'grants' => $grants,
            'assignments' => $this->assignments(DataScopeAssignment::SUBJECT_ROLE, (int) $role->id, $dimensions),
        ]);
    }

    /**
     * GET /api/v2/admin/data-scope/users/{uuid}
     *
     * The user's own node assignments and the effective scope summary of every scoped permission they hold.
     *
     * @param string $uuid User uuid.
     * @param ManifestScopeCatalog $catalog Manifest catalog of scope levels.
     * @param DimensionRegistry $dimensions Registered dimensions.
     * @param ScopeSummary $summary Effective scope summariser.
     */
    public function user(string $uuid, ManifestScopeCatalog $catalog, DimensionRegistry $dimensions, ScopeSummary $summary): AppResponse
    {
        $user = User::query()->where('uuid', $uuid)->first();

        if ($user === null) {
            return ires()->notFound();
        }

        $titles = [];

        foreach ($user->roles()->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $titles[$this->keyOf($permission)] = (string) $permission->title;
            }
        }

        ksort($titles);
        $effective = [];
        $labelKeys = $this->permissionLabelKeys();

        foreach ($titles as $key => $title) {
            if ($this->scopedLevels($catalog, $key) === null) {
                continue;
            }

            $effective[] = [
                'permission_key' => $key,
                'title' => $title,
                'label_key' => $labelKeys[$key] ?? null,
                'summary' => $summary->for($user, $key),
            ];
        }

        return ires()->data([
            'assignments' => $this->assignments(DataScopeAssignment::SUBJECT_USER, (int) $user->id, $dimensions),
            'effective' => $effective,
        ]);
    }

    /**
     * PUT /api/v2/admin/data-scope/roles/{id}
     *
     * Replace the role's grant levels and node assignments; answers like GET roles/{id}.
     *
     * @param int $id Role id.
     * @param Request $request Body `{ grants: [{ permission_key, level }], assignments: [{ dimension, node_key, expansion }] }`.
     * @param ScopeAssignmentService $service Scope writer with the escalation guard.
     * @param ManifestScopeCatalog $catalog Manifest catalog of scope levels.
     * @param DimensionRegistry $dimensions Registered dimensions.
     */
    public function updateRole(int $id, Request $request, ScopeAssignmentService $service, ManifestScopeCatalog $catalog, DimensionRegistry $dimensions): AppResponse
    {
        $role = Role::query()->find($id);

        if ($role === null) {
            return ires()->notFound();
        }

        $service->updateRole($request->user(), $role, $request->only(['grants', 'assignments']));

        return $this->role($id, $catalog, $dimensions);
    }

    /**
     * PUT /api/v2/admin/data-scope/users/{uuid}
     *
     * Replace the user's own node assignments; answers like GET users/{uuid}.
     *
     * @param string $uuid User uuid.
     * @param Request $request Body `{ assignments: [{ dimension, node_key, expansion }] }`.
     * @param ScopeAssignmentService $service Scope writer with the escalation guard.
     * @param ManifestScopeCatalog $catalog Manifest catalog of scope levels.
     * @param DimensionRegistry $dimensions Registered dimensions.
     */
    public function updateUser(string $uuid, Request $request, ScopeAssignmentService $service, ManifestScopeCatalog $catalog, DimensionRegistry $dimensions): AppResponse
    {
        $user = User::query()->where('uuid', $uuid)->first();

        if ($user === null) {
            return ires()->notFound();
        }

        $service->updateUser($request->user(), $user, $request->only(['assignments']));

        // Resolve the summariser after the save so it uses the fresh per-request resolver.
        return $this->user($uuid, $catalog, $dimensions, app(ScopeSummary::class));
    }

    /**
     * Translation keys of the permission labels declared in the manifests of enabled modules, keyed by permission key.
     *
     * The admin translates them in the viewer locale; permissions without a manifest label are absent.
     *
     * @return array<string, string>
     */
    protected function permissionLabelKeys(): array
    {
        $keys = [];

        foreach (app(RepositoryInterface::class)->allEnabled() as $module) {
            $permissions = $module->get('permissions', []);

            foreach (is_array($permissions) ? $permissions : [] as $entry) {
                if (is_array($entry) && is_string($entry['key'] ?? null) && is_string($entry['label'] ?? null) && $entry['label'] !== '') {
                    $keys[$entry['key']] = $entry['label'];
                }
            }
        }

        return $keys;
    }

    /**
     * Permission key `group.name`.
     */
    protected function keyOf(Permission $permission): string
    {
        return trim((string) $permission->group) . '.' . trim((string) $permission->name);
    }

    /**
     * Allowed scope levels of a key when it declares more than `all`; null when it is not scoped.
     *
     * @return array<int, string>|null
     */
    protected function scopedLevels(ManifestScopeCatalog $catalog, string $key): ?array
    {
        $levels = $catalog->allowedLevels($key);

        return array_diff($levels, ['all']) === [] ? null : array_values($levels);
    }

    /**
     * Node assignments of a subject as `{ uuid, dimension, node: { key, label, trail }, expansion }`.
     *
     * Assignments whose dimension is disabled or whose node is gone are left out (fail closed).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function assignments(string $subjectType, int $subjectId, DimensionRegistry $dimensions): array
    {
        $rows = DataScopeAssignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('id')
            ->get();

        $providers = [];
        $out = [];

        foreach ($rows as $row) {
            $providers[$row->dimension] ??= $dimensions->provider($row->dimension);
            /** @var HierarchyProvider|null $provider */
            $provider = $providers[$row->dimension];

            if ($provider === null) {
                continue;
            }

            $key = $provider->keyFor((int) $row->node_id);
            $label = $provider->labels([(int) $row->node_id])[(int) $row->node_id] ?? null;

            if ($key === null || $label === null) {
                continue;
            }

            $out[] = [
                'uuid' => $row->uuid,
                'dimension' => $row->dimension,
                'node' => ['key' => $key, 'label' => $label, 'trail' => $provider->trail((int) $row->node_id)],
                'expansion' => $row->expansion instanceof \BackedEnum ? $row->expansion->value : (string) $row->expansion,
            ];
        }

        return $out;
    }
}
