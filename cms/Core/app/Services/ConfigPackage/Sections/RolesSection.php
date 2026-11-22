<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Illuminate\Support\Facades\DB;

/**
 * Roles and permissions section of the configuration package.
 *
 * Rows are `{ name, custom, permissions }`: roles are matched by name (case-insensitive, trimmed) and
 * permissions by their `group.name` key; no numeric id and no `custom.user_id` ever enters a package.
 *
 * Safety rules, re-checked against the live target on every diff and apply so a forged package cannot bypass them:
 * - the `Webmaster` role and any role whose effective level is 0 are never exported, matched, created or changed;
 * - an incoming level must be a non-negative integer (or absent) and never more privileged (numerically lower)
 *   than the importing user's own level; the importing user is mandatory (fail closed);
 * - a role name that matches two or more target roles, or appears twice in the package, is blocked as ambiguous;
 * - permission keys missing on the target are warnings and are skipped, never created;
 * - new roles are created with the permissions that exist on the target;
 * - an existing role only gains permissions when the operator explicitly chose it (`choices[<name>] === true`);
 *   its `custom` (level included) is never changed and permissions are never detached.
 */
class RolesSection implements SectionAdapter
{
    /** Name of the protected super role (see RoleTableSeed). */
    public const WEBMASTER = 'Webmaster';

    /** Level `User::roleLevel()` assumes for a role without `custom.level`. */
    public const DEFAULT_LEVEL = 99;

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'roles';
    }

    /**
     * @inheritDoc
     *
     * Every non-protected role is exported; the context key patterns are setting key patterns and are not used.
     * Protected roles (Webmaster, level 0) and roles sharing a name with another role are counted in `skipped`.
     */
    public function export(ExportContext $c): SectionExport
    {
        $rows = [];
        $skipped = [];
        $roles = Role::query()->with('permissions')->orderBy('name')->orderBy('id')->get();
        $nameCounts = $roles->countBy(fn (Role $role): string => $this->normalizeName($role->name));

        foreach ($roles as $role) {
            if ($this->isProtectedRole($role)) {
                $skipped['protected'] = ($skipped['protected'] ?? 0) + 1;

                continue;
            }

            if (($nameCounts[$this->normalizeName($role->name)] ?? 0) > 1) {
                $skipped['ambiguous'] = ($skipped['ambiguous'] ?? 0) + 1;

                continue;
            }

            $rows[] = [
                'name' => trim((string) $role->name),
                'custom' => $this->cleanCustom($role->custom),
                'permissions' => $this->roleKeys($role),
            ];
        }

        ksort($skipped);

        return new SectionExport($rows, $skipped);
    }

    /**
     * @inheritDoc
     *
     * Items carry `key`, `action`, `reason`, `before`, `after`, `warnings` and `missing_permissions`; an `update`
     * item also carries `needs_choice: true` and `default_choice: false` because additions to an existing role are
     * an explicit, off-by-default operator choice.
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $state = $this->targetState($c->userId);
        $packageCounts = $this->packageNameCounts($rows);
        $items = [];

        foreach ($rows as $row) {
            $item = $this->diffRow(is_array($row) ? $row : [], $state, $packageCounts);
            // Numeric ids are apply-time internals and never leave the section.
            unset($item['role_id'], $item['permission_ids'], $item['added_ids']);
            $items[] = $item;
        }

        return new SectionDiff($items);
    }

    /**
     * @inheritDoc
     *
     * `created` and `updated` outcomes are both counted in ApplyResult::$updated.
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        $packageCounts = $this->packageNameCounts($rows);
        $items = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            // Fresh target state per row: earlier rows may have created roles.
            $diff = $this->diffRow($row, $this->targetState($c->userId), $packageCounts);
            $key = (string) $diff['key'];
            $reason = $diff['reason'];

            switch ($diff['action']) {
                case 'create':
                    $this->createRole($key, (array) $diff['after']['custom'], $diff['permission_ids'], $c->userId);
                    $outcome = 'created';
                    ++$counts['updated'];
                    break;
                case 'update':
                    if (($c->choices[$key] ?? null) !== true) {
                        $outcome = 'skipped';
                        $reason = 'not chosen';
                        ++$counts['skipped'];
                        break;
                    }

                    $role = Role::query()->findOrFail($diff['role_id']);
                    DB::transaction(static fn () => $role->permissions()->syncWithoutDetaching($diff['added_ids']));
                    $outcome = 'updated';
                    ++$counts['updated'];
                    break;
                case 'unchanged':
                    $outcome = 'unchanged';
                    ++$counts['unchanged'];
                    break;
                default:
                    $outcome = 'blocked';
                    ++$counts['blocked'];
            }

            $items[] = [
                'key' => $key,
                'outcome' => $outcome,
                'reason' => $reason,
                'warnings' => $diff['warnings'],
            ];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     */
    public function afterApply(ApplyContext $c): void
    {
        $this->clearPermissionCaches();
    }

    /**
     * Clear cached permission data (seam for tests).
     *
     * Intentionally a no-op, verified 2026-11-07: no persistent cache holds role or permission data.
     * `CMSPermissionKeyResolver` is stateless (pure static key helpers), `User::$cachedPermissionKeys` is a
     * per-instance memo that dies with the request, and `CacheConstants` has no role/permission key. The only
     * stored copies are Sanctum token abilities and the `user:{uuid}` / `users:{uuid}` login snapshots, which are
     * per-session token data written at sign-in; dropping them would sign users out, so they are left alone and
     * new permissions apply from the next sign-in for token abilities and immediately for policy checks.
     */
    protected function clearPermissionCaches(): void
    {
    }

    /**
     * Compare one incoming row with the target state.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     * @param array<string, int> $packageCounts
     *
     * @return array<string, mixed>
     */
    private function diffRow(array $row, array $state, array $packageCounts): array
    {
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $normalized = $this->normalizeName($name);
        $item = [
            'key' => $name,
            'action' => 'blocked',
            'reason' => null,
            'before' => null,
            'after' => null,
            'warnings' => [],
            'missing_permissions' => [],
        ];

        if ($name === '') {
            $item['reason'] = 'role name is missing';

            return $item;
        }

        if ($normalized === $this->normalizeName(self::WEBMASTER)) {
            $item['reason'] = 'the Webmaster role is never changed by an import';

            return $item;
        }

        $custom = $row['custom'] ?? [];
        if (!is_array($custom)) {
            $item['reason'] = 'role custom data is not an object';

            return $item;
        }

        $level = $this->parseLevel($custom);
        if ($level === null) {
            $item['reason'] = 'role level must be a whole number';

            return $item;
        }

        if ($level === 0) {
            $item['reason'] = 'level 0 is reserved for the Webmaster role';

            return $item;
        }

        if ($state['importer_level'] === null) {
            $item['reason'] = 'the importing user is unknown';

            return $item;
        }

        if ($level < $state['importer_level']) {
            $item['reason'] = 'role level is more privileged than your own';

            return $item;
        }

        if (($packageCounts[$normalized] ?? 0) > 1) {
            $item['reason'] = 'ambiguous: the package lists this role name more than once';

            return $item;
        }

        $targets = $state['roles'][$normalized] ?? [];
        if (count($targets) > 1) {
            $item['reason'] = 'ambiguous: this name matches ' . count($targets) . ' roles on this site';

            return $item;
        }

        [$wanted, $ids, $missing] = $this->resolvePermissions($row['permissions'] ?? [], $state['permissions']);
        $item['missing_permissions'] = $missing;
        foreach ($missing as $key) {
            $item['warnings'][] = 'permission ' . $key . ' does not exist on this site; skipped';
        }

        $custom = $this->cleanCustom($custom);

        if ($targets === []) {
            $item['action'] = 'create';
            $item['after'] = ['custom' => $custom, 'permissions' => $wanted];
            $item['permission_ids'] = $ids;

            return $item;
        }

        /** @var Role $target */
        $target = $targets[0];

        if ($this->isProtectedRole($target)) {
            $item['reason'] = 'the Webmaster role is never changed by an import';

            return $item;
        }

        if ($this->roleEffectiveLevel($target) < $state['importer_level']) {
            $item['reason'] = 'the role on this site is more privileged than your own';

            return $item;
        }

        $current = $this->roleKeys($target);
        $added = array_values(array_diff($wanted, $current));
        $item['before'] = ['custom' => $this->cleanCustom($target->custom), 'permissions' => $current];
        $item['role_id'] = (int) $target->id;

        if ($added === []) {
            $item['action'] = 'unchanged';
            $item['after'] = $item['before'];

            return $item;
        }

        $item['action'] = 'update';
        $item['after'] = ['permissions' => array_values(array_unique(array_merge($current, $added))), 'added_permissions' => $added];
        sort($item['after']['permissions']);
        $item['added_ids'] = $this->idsForKeys($added, $state['permissions']);
        $item['needs_choice'] = true;
        $item['default_choice'] = false;

        return $item;
    }

    /**
     * Create a new role with existing permissions only.
     *
     * @param array<string, mixed> $custom
     * @param list<int> $permissionIds
     */
    private function createRole(string $name, array $custom, array $permissionIds, ?int $userId): void
    {
        DB::transaction(function () use ($name, $custom, $permissionIds, $userId): void {
            $role = Role::query()->create([
                'name' => $name,
                'custom' => $custom === [] ? null : $custom,
                // Same as RoleController::fill: the creator is the acting user.
                'user_id' => $userId !== null && User::query()->whereKey($userId)->exists() ? $userId : null,
            ]);

            $role->permissions()->syncWithoutDetaching($permissionIds);
        });
    }

    /**
     * Load target roles grouped by normalized name, permissions by key, and the importer level.
     *
     * @return array{roles: array<string, list<Role>>, permissions: array<string, list<int>>, importer_level: int|null}
     */
    private function targetState(?int $userId): array
    {
        $roles = [];
        foreach (Role::query()->with('permissions')->orderBy('id')->get() as $role) {
            $roles[$this->normalizeName($role->name)][] = $role;
        }

        $permissions = [];
        foreach (Permission::query()->orderBy('id')->get() as $permission) {
            $permissions[(string) $permission->key][] = (int) $permission->id;
        }

        return ['roles' => $roles, 'permissions' => $permissions, 'importer_level' => $this->importerLevel($userId)];
    }

    /**
     * Level of the importing user (lower number = more privilege), or null when unknown (fail closed).
     *
     * Webmasters (`User::isWebmaster()`, which also covers user id 1) count as level 0 and may import level >= 1.
     */
    private function importerLevel(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->with('roles')->find($userId);

        if (!$user instanceof User) {
            return null;
        }

        return $user->isWebmaster() ? 0 : $user->roleLevel();
    }

    /**
     * Split incoming permission keys into existing keys, their ids, and missing keys.
     *
     * @param array<string, list<int>> $existing
     *
     * @return array{0: list<string>, 1: list<int>, 2: list<string>}
     */
    private function resolvePermissions(mixed $keys, array $existing): array
    {
        $wanted = [];
        $missing = [];

        foreach (is_array($keys) ? $keys : [] as $key) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            $key = trim($key);
            if (isset($existing[$key])) {
                $wanted[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        $wanted = array_values(array_unique($wanted));
        $missing = array_values(array_unique($missing));
        sort($wanted);
        sort($missing);

        return [$wanted, $this->idsForKeys($wanted, $existing), $missing];
    }

    /**
     * Permission ids for the given keys.
     *
     * @param list<string> $keys
     * @param array<string, list<int>> $existing
     *
     * @return list<int>
     */
    private function idsForKeys(array $keys, array $existing): array
    {
        $ids = [];
        foreach ($keys as $key) {
            array_push($ids, ...($existing[$key] ?? []));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Count incoming rows per normalized role name.
     *
     * @param list<mixed> $rows
     *
     * @return array<string, int>
     */
    private function packageNameCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $name = is_array($row) && is_string($row['name'] ?? null) ? $this->normalizeName($row['name']) : '';
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Incoming level: DEFAULT_LEVEL when absent, the integer when valid, null when invalid.
     *
     * Strictness matters: `User::roleLevel()` casts with `(int)`, so `null`, `"abc"` or `""` would become level 0.
     *
     * @param array<string, mixed> $custom
     */
    private function parseLevel(array $custom): ?int
    {
        if (!array_key_exists('level', $custom)) {
            return self::DEFAULT_LEVEL;
        }

        $level = $custom['level'];

        if (is_int($level)) {
            return $level >= 0 ? $level : null;
        }

        if (is_string($level) && preg_match('/^\d{1,6}$/', trim($level)) === 1) {
            return (int) trim($level);
        }

        return null;
    }

    /**
     * Level of a target role exactly as `User::roleLevel()` computes it.
     */
    private function roleEffectiveLevel(Role $role): int
    {
        return (int) data_get($role->custom, 'level', self::DEFAULT_LEVEL);
    }

    /**
     * Tell whether a target role must never be exported or changed (Webmaster name or effective level 0).
     */
    private function isProtectedRole(Role $role): bool
    {
        return $this->normalizeName($role->name) === $this->normalizeName(self::WEBMASTER)
            || $this->roleEffectiveLevel($role) <= 0;
    }

    /**
     * Sorted permission keys attached to a role.
     *
     * @return list<string>
     */
    private function roleKeys(Role $role): array
    {
        $keys = $role->permissions->map(static fn (Permission $p): string => (string) $p->key)->unique()->values()->all();
        sort($keys);

        return $keys;
    }

    /**
     * Role custom data without `user_id`, keys sorted for stable output.
     *
     * @return array<string, mixed>
     */
    private function cleanCustom(mixed $custom): array
    {
        $custom = is_array($custom) ? $custom : [];
        unset($custom['user_id']);
        ksort($custom);

        return $custom;
    }

    /**
     * Normalized role name used for matching.
     */
    private function normalizeName(mixed $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
