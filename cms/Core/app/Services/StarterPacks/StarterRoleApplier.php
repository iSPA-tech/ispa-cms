<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;

/**
 * Creates a starter pack's named roles with their permissions on a site, add-only.
 *
 * Thin adapter over the configuration package role import (`RolesSection`): a missing role is created with the permissions that
 * exist on the site; an existing role with the same name (case-insensitive, trimmed) is never changed, not even to
 * gain permissions, because apply always passes empty choices. Nobody is assigned to a created role. Rows the
 * role import blocks (level 0, more privileged than the acting user, ambiguous name) are reported as skipped.
 *
 * Input is the decoded `roles.json` list: `[{ name, label?: {vi, en}, custom?: {...}, permissions?: ["group|name"] }]`.
 * The whole list is validated before anything is written (all-or-nothing validation). `label` has no column on
 * roles, so it is stored as `custom.label` unless the pack's `custom` already has a `label` key.
 *
 * Both methods take the acting user id, a deliberate addition to the planned `plan(array $roles)` signature: the
 * role import fails closed without a known importer (its level bounds which roles may be created).
 */
class StarterRoleApplier
{
    /** File name used in validation messages. */
    private const FILE = 'roles.json';

    /** Reason reported for a role that already exists on the site. */
    public const REASON_EXISTS = 'role already exists; left unchanged';

    /** Role names a starter pack may never declare (normalized). */
    private const FORBIDDEN_NAMES = ['webmaster', 'super-admin'];

    /** Fields allowed on one role entry. */
    private const FIELDS = ['name', 'label', 'custom', 'permissions'];

    /**
     * @param RolesSection $roles configuration package role import used for matching, safety checks and writes.
     */
    public function __construct(private readonly RolesSection $roles)
    {
    }

    /**
     * Dry run: report what apply would do without writing anything.
     *
     * @param list<mixed> $roles        Decoded roles.json list.
     * @param int         $actingUserId User performing the install.
     *
     * @return array{created: list<string>, skipped: list<array{name: string, reason: string}>, missing_permissions: array<string, list<string>>}
     *
     * @throws StarterPackException When the list is invalid.
     */
    public function plan(array $roles, int $actingUserId): array
    {
        $rows = $this->toRows($this->validate($roles));
        $diff = $this->roles->diff($rows, new DiffContext($actingUserId));
        $result = $this->emptyResult();

        foreach ($diff->items as $item) {
            $name = (string) $item['key'];
            if ($item['action'] === 'create') {
                $result['created'][] = $name;
                $this->addMissing($result, $name, $item['missing_permissions'] ?? []);
            } else {
                $result['skipped'][] = ['name' => $name, 'reason' => $this->skipReason((string) $item['action'], $item['reason'] ?? null)];
            }
        }

        return $result;
    }

    /**
     * Create the pack's missing roles; existing roles are left exactly as they are.
     *
     * @param list<mixed> $roles        Decoded roles.json list.
     * @param int         $actingUserId User performing the install.
     *
     * @return array{created: list<string>, skipped: list<array{name: string, reason: string}>, missing_permissions: array<string, list<string>>}
     *
     * @throws StarterPackException When the list is invalid (nothing is written).
     */
    public function apply(array $roles, int $actingUserId): array
    {
        $rows = $this->toRows($this->validate($roles));

        // Missing permissions per row, read before writing (apply results only carry warning text).
        $missing = [];
        foreach ($this->roles->diff($rows, new DiffContext($actingUserId))->items as $item) {
            $missing[(string) $item['key']] = $item['missing_permissions'] ?? [];
        }

        // Empty choices: an existing role never gains permissions here (overwriting is a separate step).
        $context = new ApplyContext([], $actingUserId);
        $applied = $this->roles->apply($rows, $context);
        $this->roles->afterApply($context);

        $result = $this->emptyResult();
        foreach ($applied->items as $item) {
            $name = (string) $item['key'];
            if ($item['outcome'] === 'created') {
                $result['created'][] = $name;
                $this->addMissing($result, $name, $missing[$name] ?? []);
            } else {
                $result['skipped'][] = ['name' => $name, 'reason' => $this->skipReason((string) $item['outcome'], $item['reason'] ?? null)];
            }
        }

        return $result;
    }

    /**
     * Validate the whole list; return the entries as associative arrays.
     *
     * @param list<mixed> $roles
     *
     * @return list<array<string, mixed>>
     */
    private function validate(array $roles): array
    {
        if (!array_is_list($roles)) {
            throw StarterPackException::invalidPack(self::FILE, 'must be a list of roles');
        }

        $seen = [];
        foreach ($roles as $index => $entry) {
            $at = 'entry ' . $index;
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw StarterPackException::invalidPack(self::FILE, $at . ' must be an object');
            }

            $unknown = array_diff(array_map('strval', array_keys($entry)), self::FIELDS);
            if ($unknown !== []) {
                throw StarterPackException::invalidPack(self::FILE, $at . ' has unknown field(s): ' . implode(', ', $unknown));
            }

            $name = $entry['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                throw StarterPackException::invalidField(self::FILE, $at . '.name', 'must be a non-empty string');
            }

            $normalized = mb_strtolower(trim($name));
            if (in_array($normalized, self::FORBIDDEN_NAMES, true)) {
                throw StarterPackException::invalidField(self::FILE, $at . '.name', 'must not be a protected role ("' . trim($name) . '")');
            }

            if (isset($seen[$normalized])) {
                throw StarterPackException::invalidField(self::FILE, $at . '.name', 'repeats the role "' . trim($name) . '"');
            }
            $seen[$normalized] = true;

            if (array_key_exists('label', $entry)) {
                $label = $entry['label'];
                if (!is_array($label) || !is_string($label['vi'] ?? null) || !is_string($label['en'] ?? null)) {
                    throw StarterPackException::invalidField(self::FILE, $at . '.label', 'must be an object with string "vi" and "en"');
                }
            }

            if (array_key_exists('custom', $entry)) {
                $custom = $entry['custom'];
                if (!is_array($custom) || ($custom !== [] && array_is_list($custom))) {
                    throw StarterPackException::invalidField(self::FILE, $at . '.custom', 'must be an object');
                }
            }

            $permissions = $entry['permissions'] ?? [];
            if (!is_array($permissions) || !array_is_list($permissions)) {
                throw StarterPackException::invalidField(self::FILE, $at . '.permissions', 'must be a list of "group|name" strings');
            }

            foreach ($permissions as $key) {
                if (!is_string($key) || preg_match('/^[^|]+\|[^|]+$/', $key) !== 1 || trim(explode('|', $key)[0]) === '' || trim(explode('|', $key)[1]) === '') {
                    throw StarterPackException::invalidField(self::FILE, $at . '.permissions', 'must contain only "group|name" strings');
                }
            }
        }

        return $roles;
    }

    /**
     * Convert validated entries into RolesSection rows `{ name, custom, permissions: ["group.name"] }`.
     *
     * @param list<array<string, mixed>> $roles
     *
     * @return list<array<string, mixed>>
     */
    private function toRows(array $roles): array
    {
        $rows = [];
        foreach ($roles as $entry) {
            $custom = $entry['custom'] ?? [];
            if (isset($entry['label']) && !array_key_exists('label', $custom)) {
                $custom['label'] = ['vi' => $entry['label']['vi'], 'en' => $entry['label']['en']];
            }

            $rows[] = [
                'name' => $entry['name'],
                'custom' => $custom,
                'permissions' => array_map(
                    static fn (string $key): string => implode('.', array_map('trim', explode('|', $key))),
                    $entry['permissions'] ?? [],
                ),
            ];
        }

        return $rows;
    }

    /**
     * Record missing permission keys for a created role, in `group|name` form.
     *
     * @param array<string, mixed> $result
     * @param list<string>         $keys   Keys in `group.name` form.
     */
    private function addMissing(array &$result, string $name, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $result['missing_permissions'][$name] = array_map(
            static fn (string $key): string => preg_replace('/\./', '|', $key, 1) ?? $key,
            array_values($keys),
        );
    }

    /**
     * Plain English reason for a role that is not created.
     */
    private function skipReason(string $action, mixed $reason): string
    {
        if (in_array($action, ['update', 'unchanged', 'skipped'], true)) {
            return self::REASON_EXISTS;
        }

        return is_string($reason) && $reason !== '' ? $reason : 'role cannot be created';
    }

    /**
     * @return array{created: list<string>, skipped: list<array{name: string, reason: string}>, missing_permissions: array<string, list<string>>}
     */
    private function emptyResult(): array
    {
        return ['created' => [], 'skipped' => [], 'missing_permissions' => []];
    }
}
