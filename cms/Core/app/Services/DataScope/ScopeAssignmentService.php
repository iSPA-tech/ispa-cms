<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\DataScope\DataScopeAssignment;
use Cms\Core\Models\DataScope\DataScopeGrant;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes role data-scope grants and node assignments, and user node assignments, with an anti-escalation guard and
 * one audit row per save.
 *
 * Replace semantics:
 * - Role grants: the body replaces the levels of every permission key the role holds. A held key missing from the
 *   body, or sent with level `all`, ends up with no grant row, which means `all`. Grant rows for keys the role does
 *   not hold are left untouched (they are outside what this screen edits).
 * - Assignments (role or user): the body replaces every assignment of the subject. Rows whose dimension and node
 *   are unchanged keep their uuid.
 *
 * Validation (422): a level must be listed by `ManifestScopeCatalog::allowedLevels()` for the key, and a dimension
 * level must be a registered, enabled dimension; a role grant must name a key the role holds; an assignment needs an
 * enabled dimension, a live node resolved through that dimension's provider (`assignments.N.node_key`), an
 * `Expansion` value, and no duplicate dimension + node.
 *
 * Anti-escalation, for an acting manager who is not Webmaster (Webmaster may do anything):
 * - `self_edit_forbidden`: a manager never edits their own user assignments.
 * - `held_role_forbidden`: a manager never edits a role they hold.
 * - `level_all_requires_all`: a grant whose level changes to `all` (explicitly, or by dropping its row) needs the
 *   manager's own `all` for that key.
 * - `scope_escalation`: any other widening. Only changes are checked (a level that changed, an assignment that was
 *   added or whose expansion changed); removals only narrow. Visible sets are always computed by
 *   `ExpansionPlanner::plan()` + `ExpansionPlan::visibleIds()` from HeldNode lists, and the manager's own set comes
 *   from `ScopeResolver::for()` (its plan for the same dimension); this class never computes anchors. A set is
 *   accepted when it is a subset of the manager's visible set for the same permission key, or the manager's scope
 *   for that key is `all`:
 *   - level changed to `own`: accepted when the previous level was `all` (pure narrowing) or the manager has `all`;
 *     the subject's own rows are not inside a node set, so nothing narrower can vouch for them.
 *   - level changed to a dimension: the set planned from the role's new assignments in that dimension plus the
 *     user assignments of every holder of the role in that dimension (the level change activates those too).
 *   - added role assignment: its own planned set, checked for every key whose new level on the role is that
 *     dimension.
 *   - added user assignment: its own planned set, checked for every key the target user holds through a role whose
 *     grant level for that key is that dimension.
 *   - an assignment no key currently uses (inert) is checked, fail closed, against every key the manager holds whose
 *     manifest allows that dimension; when there is no such key it is refused.
 *
 * Each save runs in one DB transaction and writes one AuditLog row (`data_scope.role_updated` or
 * `data_scope.user_updated`) holding the before and after grants and assignments (dimension, node key, expansion).
 * After the save the per-request ScopeResolver (and the DataScope service that holds it) is dropped from the
 * container so the next resolve in the same request sees the change.
 */
class ScopeAssignmentService
{
    /** Reason code: the change would reach rows outside the manager's own scope. */
    public const REASON_SCOPE_ESCALATION = 'scope_escalation';

    /** Reason code: level `all` needs the manager's own `all`. */
    public const REASON_LEVEL_ALL = 'level_all_requires_all';

    /** Reason code: a manager may not edit their own assignments. */
    public const REASON_SELF_EDIT = 'self_edit_forbidden';

    /** Reason code: a manager may not edit a role they hold. */
    public const REASON_HELD_ROLE = 'held_role_forbidden';

    /**
     * @param DimensionRegistry $dimensions Registered dimensions and their providers.
     * @param ManifestScopeCatalog $catalog Levels each permission key allows.
     * @param ExpansionPlanner $planner The only place that turns held nodes into anchors.
     */
    public function __construct(
        protected DimensionRegistry $dimensions,
        protected ManifestScopeCatalog $catalog,
        protected ExpansionPlanner $planner,
    ) {
    }

    /**
     * Replace a role's grant levels and node assignments.
     *
     * @param User $actor Acting manager.
     * @param Role $role Target role.
     * @param array<string, mixed> $input `{ grants: [{ permission_key, level }], assignments: [{ dimension, node_key, expansion }] }`
     *
     * @throws ValidationException On invalid input (422).
     * @throws HttpResponseException On an escalation (403 with a reason code).
     */
    public function updateRole(User $actor, Role $role, array $input): void
    {
        $webmaster = $actor->isWebmaster();

        if (! $webmaster && $actor->roles()->whereKey($role->getKey())->exists()) {
            $this->deny(self::REASON_HELD_ROLE);
        }

        $data = Validator::make($input, array_merge([
            'grants' => ['present', 'array'],
            'grants.*' => ['array'],
            'grants.*.permission_key' => ['required', 'string', 'max:191', 'distinct'],
            'grants.*.level' => ['required', 'string', 'max:64'],
        ], $this->assignmentRules()))->validate();

        $role->loadMissing('permissions');
        $heldKeys = [];

        foreach ($role->permissions as $permission) {
            $heldKeys[$this->keyOf($permission)] = true;
        }

        $errors = [];
        $afterLevels = [];

        foreach ($data['grants'] as $i => $grant) {
            $key = (string) $grant['permission_key'];
            $level = (string) $grant['level'];

            if (! isset($heldKeys[$key])) {
                $errors["grants.$i.permission_key"] = 'The role does not hold this permission.';

                continue;
            }

            if (! $this->levelUsable($key, $level)) {
                $errors["grants.$i.level"] = 'This level is not allowed for the permission.';

                continue;
            }

            $afterLevels[$key] = $level;
        }

        $after = $this->normaliseAssignments($data['assignments'], $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $roleId = (int) $role->getKey();
        $beforeLevels = DataScopeGrant::query()->where('role_id', $roleId)->pluck('level', 'permission_key')->all();
        $before = $this->currentAssignments(DataScopeAssignment::SUBJECT_ROLE, $roleId);

        $levelOf = static fn (array $levels, string $key): string => (string) ($levels[$key] ?? 'all');

        if (! $webmaster) {
            $holderIds = array_map('intval', DB::table(CoreTableConstant::ROLE_USER)->where('role_id', $roleId)->pluck('user_id')->all());

            foreach (array_keys($heldKeys) as $key) {
                $from = $levelOf($beforeLevels, $key);
                $to = $levelOf($afterLevels, $key);

                if ($from !== $to) {
                    $this->guardLevelChange($actor, $key, $from, $to, $after, $holderIds, $roleId);
                }
            }

            foreach ($this->added($before, $after) as $assignment) {
                $keys = array_keys(array_filter(
                    array_fill_keys(array_keys($heldKeys), true),
                    static fn ($v, string $key): bool => $levelOf($afterLevels, $key) === $assignment['dimension'],
                    ARRAY_FILTER_USE_BOTH,
                ));
                $this->guardAssignment($actor, $assignment, $keys, DataScopeAssignment::SUBJECT_ROLE, $roleId);
            }
        }

        DB::transaction(function () use ($actor, $role, $roleId, $heldKeys, $afterLevels, $beforeLevels, $before, $after): void {
            DataScopeGrant::query()->where('role_id', $roleId)->whereIn('permission_key', array_keys($heldKeys))->delete();

            foreach ($afterLevels as $key => $level) {
                if ($level !== 'all') {
                    DataScopeGrant::query()->create(['role_id' => $roleId, 'permission_key' => $key, 'level' => $level]);
                }
            }

            $this->replaceAssignments($actor, DataScopeAssignment::SUBJECT_ROLE, $roleId, $after);

            $afterRows = DataScopeGrant::query()->where('role_id', $roleId)->pluck('level', 'permission_key')->all();

            $this->audit($actor, 'data_scope.role_updated', $role, [
                'before' => ['grants' => $this->grantSnapshot($beforeLevels), 'assignments' => $this->assignmentSnapshot($before)],
                'after' => ['grants' => $this->grantSnapshot($afterRows), 'assignments' => $this->assignmentSnapshot($after)],
            ]);
        });

        $this->forgetResolvedScopes();
    }

    /**
     * Replace a user's own node assignments.
     *
     * @param User $actor Acting manager.
     * @param User $target Target user.
     * @param array<string, mixed> $input `{ assignments: [{ dimension, node_key, expansion }] }`
     *
     * @throws ValidationException On invalid input (422).
     * @throws HttpResponseException On an escalation (403 with a reason code).
     */
    public function updateUser(User $actor, User $target, array $input): void
    {
        $webmaster = $actor->isWebmaster();

        if (! $webmaster && (int) $actor->getKey() === (int) $target->getKey()) {
            $this->deny(self::REASON_SELF_EDIT);
        }

        $data = Validator::make($input, $this->assignmentRules())->validate();
        $errors = [];
        $after = $this->normaliseAssignments($data['assignments'], $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $userId = (int) $target->getKey();
        $before = $this->currentAssignments(DataScopeAssignment::SUBJECT_USER, $userId);

        if (! $webmaster) {
            $keysByDimension = $this->userKeysByDimension($target);

            foreach ($this->added($before, $after) as $assignment) {
                $this->guardAssignment($actor, $assignment, $keysByDimension[$assignment['dimension']] ?? [], DataScopeAssignment::SUBJECT_USER, $userId);
            }
        }

        DB::transaction(function () use ($actor, $target, $userId, $before, $after): void {
            $this->replaceAssignments($actor, DataScopeAssignment::SUBJECT_USER, $userId, $after);

            $this->audit($actor, 'data_scope.user_updated', $target, [
                'before' => ['assignments' => $this->assignmentSnapshot($before)],
                'after' => ['assignments' => $this->assignmentSnapshot($after)],
            ]);
        });

        $this->forgetResolvedScopes();
    }

    /**
     * Structural validation rules of the `assignments` list.
     *
     * @return array<string, array<int, string>>
     */
    protected function assignmentRules(): array
    {
        return [
            'assignments' => ['present', 'array'],
            'assignments.*' => ['array'],
            'assignments.*.dimension' => ['required', 'string', 'max:64'],
            'assignments.*.node_key' => ['required', 'string', 'max:191'],
            'assignments.*.expansion' => ['required', 'string', 'max:16'],
        ];
    }

    /**
     * Whether the level is allowed for the key by the manifest and, for a dimension level, the dimension is enabled.
     */
    protected function levelUsable(string $key, string $level): bool
    {
        if (! in_array($level, $this->catalog->allowedLevels($key), true)) {
            return false;
        }

        return in_array($level, ['all', 'own'], true) || $this->dimensions->provider($level) !== null;
    }

    /**
     * Resolve the posted assignments to node ids; collects per-field errors.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $errors
     * @return array<int, array{dimension: string, node_id: int, node_key: string, expansion: Expansion}>
     */
    protected function normaliseAssignments(array $rows, array &$errors): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $dimension = (string) $row['dimension'];
            $provider = $this->dimensions->provider($dimension);

            if ($provider === null) {
                $errors["assignments.$i.dimension"] = 'Unknown or disabled dimension.';

                continue;
            }

            $expansion = Expansion::tryFrom((string) $row['expansion']);

            if ($expansion === null) {
                $errors["assignments.$i.expansion"] = 'Unknown expansion.';
            }

            $nodeKey = (string) $row['node_key'];
            $nodeId = $provider->idForKey($nodeKey);

            if ($nodeId === null || ! $provider->exists($nodeId)) {
                $errors["assignments.$i.node_key"] = 'Unknown node.';

                continue;
            }

            if (isset($seen[$dimension . '|' . $nodeId])) {
                $errors["assignments.$i.node_key"] = 'The node is listed twice.';

                continue;
            }

            $seen[$dimension . '|' . $nodeId] = true;

            if ($expansion !== null) {
                $out[] = ['dimension' => $dimension, 'node_id' => $nodeId, 'node_key' => $nodeKey, 'expansion' => $expansion];
            }
        }

        return $out;
    }

    /**
     * Current assignments of a subject in the normalised shape (node key null when the dimension or node is gone).
     *
     * @return array<int, array{dimension: string, node_id: int, node_key: ?string, expansion: Expansion|null}>
     */
    protected function currentAssignments(string $subjectType, int $subjectId): array
    {
        $out = [];

        foreach (DataScopeAssignment::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->orderBy('id')->get() as $row) {
            $provider = $this->dimensions->provider((string) $row->dimension);
            $expansion = $row->expansion instanceof Expansion ? $row->expansion : Expansion::tryFrom((string) $row->getRawOriginal('expansion'));

            $out[] = [
                'dimension' => (string) $row->dimension,
                'node_id' => (int) $row->node_id,
                'node_key' => $provider?->keyFor((int) $row->node_id),
                'expansion' => $expansion,
            ];
        }

        return $out;
    }

    /**
     * Assignments of `$after` that are new or whose expansion changed compared to `$before`.
     *
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     * @return array<int, array<string, mixed>>
     */
    protected function added(array $before, array $after): array
    {
        $existing = [];

        foreach ($before as $row) {
            $existing[$row['dimension'] . '|' . $row['node_id'] . '|' . ($row['expansion']?->value ?? '')] = true;
        }

        return array_values(array_filter(
            $after,
            static fn (array $row): bool => ! isset($existing[$row['dimension'] . '|' . $row['node_id'] . '|' . $row['expansion']->value]),
        ));
    }

    /**
     * Refuse a level change that widens beyond the manager's scope.
     *
     * @param array<int, array<string, mixed>> $roleAssignments The role's assignments after the save.
     * @param array<int, int> $holderIds Users holding the role.
     */
    protected function guardLevelChange(User $actor, string $key, string $from, string $to, array $roleAssignments, array $holderIds, int $roleId): void
    {
        $resolver = $this->resolver();

        if ($to === 'all') {
            if (! $resolver->for($actor, $key)->all) {
                $this->deny(self::REASON_LEVEL_ALL);
            }

            return;
        }

        if ($to === 'own') {
            if ($from !== 'all' && ! $resolver->for($actor, $key)->all) {
                $this->deny(self::REASON_SCOPE_ESCALATION);
            }

            return;
        }

        $held = [];

        foreach ($roleAssignments as $row) {
            if ($row['dimension'] === $to) {
                $held[] = new HeldNode($row['node_id'], $row['expansion'], DataScopeAssignment::SUBJECT_ROLE, $roleId);
            }
        }

        if ($holderIds !== []) {
            foreach (DataScopeAssignment::query()
                ->where('subject_type', DataScopeAssignment::SUBJECT_USER)
                ->whereIn('subject_id', $holderIds)
                ->where('dimension', $to)
                ->get() as $row) {
                $expansion = $row->expansion instanceof Expansion ? $row->expansion : Expansion::tryFrom((string) $row->getRawOriginal('expansion'));

                if ($expansion !== null) {
                    $held[] = new HeldNode((int) $row->node_id, $expansion, DataScopeAssignment::SUBJECT_USER, (int) $row->subject_id);
                }
            }
        }

        if (! $this->covers($actor, $key, $to, $held)) {
            $this->deny(self::REASON_SCOPE_ESCALATION);
        }
    }

    /**
     * Refuse an added assignment that reaches beyond the manager's scope for any of the relevant keys.
     *
     * @param array<string, mixed> $assignment Normalised assignment.
     * @param array<int, string> $keys Permission keys that use the assignment's dimension for the subject.
     */
    protected function guardAssignment(User $actor, array $assignment, array $keys, string $subjectType, int $subjectId): void
    {
        $dimension = (string) $assignment['dimension'];

        if ($keys === []) {
            // Inert today; fail closed against every key the manager holds that may use this dimension.
            $keys = $this->actorKeysForDimension($actor, $dimension);

            if ($keys === []) {
                $this->deny(self::REASON_SCOPE_ESCALATION);
            }
        }

        $held = [new HeldNode((int) $assignment['node_id'], $assignment['expansion'], $subjectType, $subjectId)];

        foreach ($keys as $key) {
            if (! $this->covers($actor, $key, $dimension, $held)) {
                $this->deny(self::REASON_SCOPE_ESCALATION);
            }
        }
    }

    /**
     * Whether the set planned from the held nodes lies inside the manager's visible set for the key and dimension.
     *
     * @param array<int, HeldNode> $held
     */
    protected function covers(User $actor, string $key, string $dimension, array $held): bool
    {
        $scope = $this->resolver()->for($actor, $key);

        if ($scope->all) {
            return true;
        }

        $provider = $this->dimensions->provider($dimension);

        if ($provider === null) {
            return false;
        }

        $wanted = $this->planner->plan($held, $provider)->visibleIds($provider);

        if ($wanted === []) {
            return true;
        }

        $own = $scope->plans[$dimension] ?? null;

        if ($own === null) {
            return false;
        }

        return array_diff($wanted, $own->visibleIds($provider)) === [];
    }

    /**
     * Permission keys the target user holds through a role whose grant level for that key is a dimension, by dimension.
     *
     * @return array<string, array<int, string>>
     */
    protected function userKeysByDimension(User $target): array
    {
        $out = [];

        foreach ($target->roles()->with('permissions')->get() as $role) {
            $levels = DataScopeGrant::query()->where('role_id', $role->getKey())->pluck('level', 'permission_key')->all();

            foreach ($role->permissions as $permission) {
                $key = $this->keyOf($permission);
                $level = $levels[$key] ?? null;

                if ($level !== null && ! in_array($level, ['all', 'own'], true)) {
                    $out[$level][$key] = $key;
                }
            }
        }

        return array_map('array_values', $out);
    }

    /**
     * Permission keys the manager holds whose manifest allows the dimension.
     *
     * @return array<int, string>
     */
    protected function actorKeysForDimension(User $actor, string $dimension): array
    {
        $keys = [];

        foreach ($actor->roles()->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $key = $this->keyOf($permission);

                if (in_array($dimension, $this->catalog->allowedLevels($key), true)) {
                    $keys[$key] = $key;
                }
            }
        }

        return array_values($keys);
    }

    /**
     * Replace a subject's assignments, keeping the uuid of rows whose dimension and node are unchanged.
     *
     * @param array<int, array<string, mixed>> $after
     */
    protected function replaceAssignments(User $actor, string $subjectType, int $subjectId, array $after): void
    {
        $keep = [];

        foreach ($after as $row) {
            $existing = DataScopeAssignment::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('dimension', $row['dimension'])
                ->where('node_id', $row['node_id'])
                ->first();

            if ($existing === null) {
                $existing = DataScopeAssignment::query()->create([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'dimension' => $row['dimension'],
                    'node_id' => $row['node_id'],
                    'expansion' => $row['expansion'],
                    'assigned_by_user_id' => $actor->getKey(),
                ]);
            } elseif ($existing->expansion !== $row['expansion']) {
                $existing->update(['expansion' => $row['expansion'], 'assigned_by_user_id' => $actor->getKey()]);
            }

            $keep[] = (int) $existing->getKey();
        }

        DataScopeAssignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNotIn('id', $keep === [] ? [0] : $keep)
            ->delete();
    }

    /**
     * Grant rows as a sorted list of `{ permission_key, level }`.
     *
     * @param array<string, string> $levels
     * @return array<int, array{permission_key: string, level: string}>
     */
    protected function grantSnapshot(array $levels): array
    {
        ksort($levels);
        $out = [];

        foreach ($levels as $key => $level) {
            $out[] = ['permission_key' => (string) $key, 'level' => (string) $level];
        }

        return $out;
    }

    /**
     * Assignments as a list of `{ dimension, node_key, label, expansion }` (no node ids).
     *
     * The node label is frozen at write time (one batch lookup per dimension) so history can still name a node
     * that is later removed. It is null when the dimension or node is gone.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{dimension: string, node_key: ?string, label: ?string, expansion: ?string}>
     */
    protected function assignmentSnapshot(array $rows): array
    {
        $idsByDimension = [];

        foreach ($rows as $row) {
            $idsByDimension[$row['dimension']][] = (int) $row['node_id'];
        }

        $labels = [];

        foreach ($idsByDimension as $dimension => $ids) {
            $labels[$dimension] = $this->dimensions->provider((string) $dimension)?->labels(array_values(array_unique($ids))) ?? [];
        }

        return array_map(static fn (array $row): array => [
            'dimension' => $row['dimension'],
            'node_key' => $row['node_key'],
            'label' => isset($labels[$row['dimension']][(int) $row['node_id']]) ? (string) $labels[$row['dimension']][(int) $row['node_id']] : null,
            'expansion' => $row['expansion']?->value,
        ], $rows);
    }

    /**
     * Write the audit row of a save.
     *
     * @param array<string, mixed> $changed
     */
    protected function audit(User $actor, string $action, Role|User $target, array $changed): void
    {
        $request = request();

        AuditLog::query()->create([
            'action' => $action,
            'changed' => $changed,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'ip_address' => $request->ip(),
            'agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'options' => ['method' => $request->method()],
            'user_id' => $actor->getKey(),
        ]);
    }

    /**
     * Drop the per-request resolver (and the query service holding it) so the next resolve sees the saved scope.
     */
    protected function forgetResolvedScopes(): void
    {
        app()->forgetInstance(ScopeResolver::class);
        app()->forgetInstance(DataScope::class);
    }

    /**
     * The per-request scope resolver.
     */
    protected function resolver(): ScopeResolver
    {
        return app(ScopeResolver::class);
    }

    /**
     * Permission key `group.name`.
     */
    protected function keyOf(Permission $permission): string
    {
        return trim((string) $permission->group) . '.' . trim((string) $permission->name);
    }

    /**
     * Abort with a 403 JSON response carrying the reason code.
     *
     * @throws HttpResponseException Always.
     */
    protected function deny(string $reason): never
    {
        throw new HttpResponseException(
            ires()->forbidden('The data scope change is not allowed.')->errorCode($reason)->toResponse(request())
        );
    }
}
