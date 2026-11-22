<?php

namespace Cms\Core\Services\Forms;

/** Bounded presentation metadata shared by reviewed and legacy form renderers. */
final class FormLayout
{
    private const DEVICES = ['mobile', 'tablet', 'desktop'];

    public static function columns(array $custom): array
    {
        $values = $custom['layout']['columns'] ?? [];
        $values = is_array($values) ? $values : [];
        return array_combine(self::DEVICES, array_map(fn ($device) => self::count($values[$device] ?? null, 1), self::DEVICES));
    }

    public static function spans(array $custom, array $columns, string $type): array
    {
        $values = $custom['layout']['span'] ?? [];
        $values = is_array($values) ? $values : [];
        $result = [];
        foreach (self::DEVICES as $device) {
            $value = $values[$device] ?? 'auto';
            $full = $value === 'full' || ($value === 'auto' && in_array($type, ['textarea', 'editor'], true));
            $result[$device] = $full ? $columns[$device] : min($columns[$device], self::count($value, 1));
        }
        return $result;
    }

    public static function style(array $values, string $prefix): string
    {
        if (!in_array($prefix, ['cols', 'span'], true)) throw new \InvalidArgumentException('Unknown layout style prefix.');
        return implode(';', array_map(fn ($device) => '--form-' . $prefix . '-' . $device . ':' . self::count($values[$device] ?? null, 1), self::DEVICES));
    }

    private static function count(mixed $value, int $default): int
    {
        if (!is_int($value) && !is_string($value)) return $default;
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number !== false && $number >= 1 && $number <= 3 ? $number : $default;
    }
}
