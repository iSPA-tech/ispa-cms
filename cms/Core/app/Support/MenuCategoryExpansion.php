<?php

namespace Cms\Core\Support;

final class MenuCategoryExpansion
{
    public const LEGACY_LEVEL = 2;

    public static function level(mixed $custom): int
    {
        $level = data_get($custom, 'expandChildLevel');

        return self::isValidLevel($level) ? (int) $level : self::LEGACY_LEVEL;
    }

    public static function isValidLevel(mixed $level): bool
    {
        if ($level === null || $level === '') {
            return false;
        }

        $validated = filter_var($level, FILTER_VALIDATE_INT);

        return $validated !== false && $validated >= 0;
    }

    public static function normalizeCustom(mixed $custom): array
    {
        $custom = is_array($custom) ? $custom : [];
        $custom['expandChildLevel'] = self::level($custom);

        return $custom;
    }

    public static function itemTreeHasValidLevels(mixed $items): bool
    {
        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            $custom = $item['custom'] ?? [];
            if (is_array($custom)
                && array_key_exists('expandChildLevel', $custom)
                && ! self::isValidLevel($custom['expandChildLevel'])) {
                return false;
            }

            if (array_key_exists('items', $item)
                && $item['items'] !== null
                && ! self::itemTreeHasValidLevels($item['items'])) {
                return false;
            }
        }

        return true;
    }
}
