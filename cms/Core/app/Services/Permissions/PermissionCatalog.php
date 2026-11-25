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
     * @return list<array{key: string, label: string, module: string, module_label: string}>
     */
    public function options(): array
    {
        $moduleLabels = [];

        return Permission::query()
            ->select(['name', 'group', 'title'])
            ->whereNotNull('group')
            ->get()
            ->map(function (Permission $permission) use (&$moduleLabels): array {
                $module = self::moduleOf($permission->group);
                $key = $permission->group . '.' . $permission->name;

                return [
                    'key' => $key,
                    'label' => self::label($permission->title, $key),
                    'module' => $module,
                    'module_label' => $moduleLabels[$module] ??= self::moduleLabel($module),
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

    public static function moduleLabel(string $module): string
    {
        $name = 'text.permission.resource.' . $module;
        $text = __($name);

        return is_string($text) && $text !== $name ? $text : Str::headline($module);
    }
}
