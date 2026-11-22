<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\WidgetAreaUsageCounter;

/** Default while the Widget module is off: no shared areas exist, so every widget is used in 0 of them. */
final class NullWidgetAreaUsageCounter implements WidgetAreaUsageCounter
{
    public function count(int $widgetId): int
    {
        return 0;
    }

    public function countMany(array $widgetIds): array
    {
        return array_fill_keys(array_map('intval', $widgetIds), 0);
    }

    public function themeIdsInUse(int $widgetId): array
    {
        return [];
    }
}
