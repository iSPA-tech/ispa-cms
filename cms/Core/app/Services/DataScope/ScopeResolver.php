<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Models\DataScope\DataScopeAssignment;
use Cms\Core\Services\CMSPermissionKeyResolver;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the data scope of a person (user × permission key → ResolvedScope).
 *
 * Algorithm:
 * 1. Webmaster gets `everything()`, even without the permission key.
 * 2. A user without the permission (`hasPermissionResolved` false) gets `none()`.
 * 3. For each role of the user that holds one of the candidate keys, the level is that role's grant row for the first candidate that has one. A role with no row means `all`, so the result is `everything()`.
 * 4. Level `own` sets `own = true`.
 * 5. A dimension level counts only when the dimension is registered, enabled, and listed in `ManifestScopeCatalog::allowedLevels($permissionKey)`. Its held nodes are that role's assignments for the dimension plus the user's own assignments for it. Plan them with `ExpansionPlanner`; per dimension, merge the held nodes of all roles, then plan once.
 * 6. Any other level contributes nothing. Log it once per request with `CMSLogger` at warning level, with no user data.
 *
 * An explicit grant row with level `all` is treated like a missing row (any `all` wins). Assignment rows with an
 * unknown expansion value contribute nothing (fail closed). Anchors are computed only by ExpansionPlanner.
 * Bound as a container `scoped()` instance, so results are memoised per request or job only.
 */
class ScopeResolver
{
    /**
     * Resolved scopes keyed by "<user id>|<permission key>".
     *
     * @var array<string, ResolvedScope>
     */
    protected array $resolved = [];

    /**
     * Unusable levels already logged in this request, keyed by "<permission key>|<level>".
     *
     * @var array<string, true>
     */
    protected array $loggedLevels = [];

    /**
     * @param DimensionRegistry $dimensions Registered dimensions and their hierarchy providers.
     * @param ManifestScopeCatalog $catalog Levels each permission key allows, from module manifests.
     * @param ExpansionPlanner $planner The only place that turns held nodes into anchors.
     */
    public function __construct(
        protected DimensionRegistry $dimensions,
        protected ManifestScopeCatalog $catalog,
        protected ExpansionPlanner $planner,
    ) {
    }

    /**
     * Resolve the data scope the user holds for the permission key (memoised per instance).
     */
    public function for(User $user, string $permissionKey): ResolvedScope
    {
        $memoKey = ($user->getKey() ?? 'new#' . spl_object_id($user)) . '|' . $permissionKey;

        return $this->resolved[$memoKey] ??= $this->resolve($user, $permissionKey);
    }

    /**
     * Compute the scope without memoisation.
     */
    protected function resolve(User $user, string $permissionKey): ResolvedScope
    {
        // One query each for roles and their permissions; permissionKeys() and isWebmaster() reuse them.
        $user->loadMissing('roles.permissions');

        // Webmaster is the full-access role: it must not resolve to none() while a newly deployed
        // permission key is not yet granted by cms:modules-sync. Route middleware still gates access.
        if ($user->isWebmaster()) {
            return ResolvedScope::everything();
        }

        if (! $user->hasPermissionResolved($permissionKey)) {
            return ResolvedScope::none();
        }

        $candidates = array_values(CMSPermissionKeyResolver::getCandidates($permissionKey));
        $roleIds = [];

        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $key = trim((string) $permission->group) . '.' . trim((string) $permission->name);

                if (in_array($key, $candidates, true)) {
                    $roleIds[] = (int) $role->id;
                    break;
                }
            }
        }

        if ($roleIds === []) {
            return ResolvedScope::none();
        }

        $grants = [];

        foreach (DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_key', $candidates)
            ->get(['role_id', 'permission_key', 'level']) as $row) {
            $grants[(int) $row->role_id][(string) $row->permission_key] = (string) $row->level;
        }

        $own = false;
        $allowed = $this->catalog->allowedLevels($permissionKey);
        /** @var array<string, array<int, int>> $dimensionRoles role ids per usable dimension */
        $dimensionRoles = [];

        foreach ($roleIds as $roleId) {
            $level = null;

            foreach ($candidates as $candidate) {
                if (isset($grants[$roleId][$candidate])) {
                    $level = $grants[$roleId][$candidate];
                    break;
                }
            }

            if ($level === null || $level === 'all') {
                return ResolvedScope::everything();
            }

            if ($level === 'own') {
                $own = true;

                continue;
            }

            if (in_array($level, $allowed, true) && $this->dimensions->provider($level) !== null) {
                $dimensionRoles[$level][] = $roleId;

                continue;
            }

            $this->logUnusableLevel($permissionKey, $level);
        }

        return new ResolvedScope(false, $own, $this->plan($user, $dimensionRoles));
    }

    /**
     * Plan each usable dimension once from the merged held nodes of its roles plus the user's own assignments.
     *
     * @param array<string, array<int, int>> $dimensionRoles
     * @return array<string, ExpansionPlan>
     */
    protected function plan(User $user, array $dimensionRoles): array
    {
        if ($dimensionRoles === []) {
            return [];
        }

        $rows = DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)
            ->whereIn('dimension', array_keys($dimensionRoles))
            ->where(static function ($query) use ($user, $dimensionRoles): void {
                $query->where(static function ($q) use ($user): void {
                    $q->where('subject_type', DataScopeAssignment::SUBJECT_USER)->where('subject_id', (int) $user->getKey());
                })->orWhere(static function ($q) use ($dimensionRoles): void {
                    $q->where('subject_type', DataScopeAssignment::SUBJECT_ROLE)
                        ->whereIn('subject_id', array_values(array_unique(array_merge(...array_values($dimensionRoles)))));
                });
            })
            ->orderBy('id')
            ->get(['subject_type', 'subject_id', 'dimension', 'node_id', 'expansion']);

        /** @var array<string, array<string, HeldNode>> $held */
        $held = [];

        foreach ($rows as $row) {
            $dimension = (string) $row->dimension;
            $subjectId = (int) $row->subject_id;
            $expansion = Expansion::tryFrom((string) $row->expansion);

            if ($expansion === null) {
                continue;
            }

            // A role assignment applies only to a role whose level for this permission is this dimension.
            if ($row->subject_type === DataScopeAssignment::SUBJECT_ROLE && ! in_array($subjectId, $dimensionRoles[$dimension], true)) {
                continue;
            }

            $dedupeKey = $row->subject_type . ':' . $subjectId . ':' . (int) $row->node_id . ':' . $expansion->value;
            $held[$dimension][$dedupeKey] = new HeldNode((int) $row->node_id, $expansion, (string) $row->subject_type, $subjectId);
        }

        $plans = [];

        foreach ($held as $dimension => $nodes) {
            $provider = $this->dimensions->provider($dimension);

            if ($provider !== null) {
                $plans[$dimension] = $this->planner->plan(array_values($nodes), $provider);
            }
        }

        ksort($plans);

        return $plans;
    }

    /**
     * Warn once per request about a level that cannot be used for the permission key (no user data).
     */
    protected function logUnusableLevel(string $permissionKey, string $level): void
    {
        $key = $permissionKey . '|' . $level;

        if (isset($this->loggedLevels[$key])) {
            return;
        }

        $this->loggedLevels[$key] = true;

        CMSLogger::warning([
            'message' => 'Data scope level ignored: not own, all, or a registered, enabled and allowed dimension',
            'permission_key' => $permissionKey,
            'level' => $level,
        ]);
    }
}
