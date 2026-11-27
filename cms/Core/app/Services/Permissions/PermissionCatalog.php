<?php

declare(strict_types=1);

namespace Cms\Core\Services\Permissions;

use Cms\Core\Models\Permission;
use Illuminate\Support\Str;

/**
 * Read side of the permission catalog shared by the permission editor (PermissionCatalogController) and pickers that
 * store a permission key (the workflow step form).
 *
 * Labels follow how permissions are authored: module seeders write `title` as "English | Tiếng Việt", older rows a
 * plain title. The user's locale picks the half (vi → second, otherwise first); a row without a title shows its key.
 * A module's label is the `text.permission.resource.<module>` translation the permission editor uses, else its name.
 *
 * @package Cms\Core\Services\Permissions
 * @since   2026-11-24
 */
class PermissionCatalog
{
    /** Module of a permission group: its first dot segment, lower-cased (`sample-module.stories` → `sample-module`). */
    public static function moduleOf(?string $group): string
    {
        return Str::lower(Str::before((string) $group, '.'));
    }

    /**
     * Every permission as a picker option, ordered by module then key. Carries no ids and nothing but names and titles.
     *
     * `resource_label` names what a module permission is about (`education.edu_instructor` → "Giảng viên"), so a picker
     * can read "Education › Giảng viên › Xem tất cả" instead of a list of identical "Education › Xem tất cả" rows; it
     * is empty for a one-part group (the module label already names it).
     *
     * @return list<array{key: string, label: string, module: string, module_label: string, resource_label: string}>
     */
    public function options(): array
    {
        $moduleLabels = [];
        $resourceLabels = [];

        return Permission::query()
            ->select(['name', 'group', 'title'])
            ->whereNotNull('group')
            ->get()
            ->map(function (Permission $permission) use (&$moduleLabels, &$resourceLabels): array {
                $module = self::moduleOf($permission->group);
                $key = $permission->group . '.' . $permission->name;
                $group = (string) $permission->group;

                return [
                    'key' => $key,
                    'label' => self::label($permission->title, $key),
                    'module' => $module,
                    'module_label' => $moduleLabels[$module] ??= self::moduleLabel($module),
                    'resource_label' => $resourceLabels[$group] ??= self::resourceLabel($group),
                ];
            })
            ->sortBy([['module', 'asc'], ['key', 'asc']])
            ->values()
            ->all();
    }

    /** The title in the user's language: the matching half of "English | Tiếng Việt", the plain title, or the key. */
    public static function label(?string $title, string $key): string
    {
        $title = trim((string) $title);
        if ($title === '') {
            return $key;
        }
        if (!str_contains($title, '|')) {
            return $title;
        }

        [$en, $vi] = array_map('trim', explode('|', $title, 2));
        $text = app()->getLocale() === 'vi' ? $vi : $en;

        return $text !== '' ? $text : ($en !== '' ? $en : $key);
    }

    /**
     * Name of what a module permission group is about: the translation `text.permission.resource.<group>` (the key the
     * role permission editor reads too), else the resource part made readable (`edu_research_result` → "Research
     * Result"). Empty for a one-part group.
     */
    public static function resourceLabel(string $group): string
    {
        if (!str_contains($group, '.')) {
            return '';
        }

        $name = 'text.permission.resource.' . Str::lower($group);
        $text = __($name);
        if (is_string($text) && $text !== $name) {
            return $text;
        }

        return Str::headline((string) preg_replace('/^(edu|qb)_/', '', Str::after($group, '.')));
    }

    public static function moduleLabel(string $module): string
    {
        $name = 'text.permission.resource.' . $module;
        $text = __($name);

        return is_string($text) && $text !== $name ? $text : Str::headline($module);
    }
}
