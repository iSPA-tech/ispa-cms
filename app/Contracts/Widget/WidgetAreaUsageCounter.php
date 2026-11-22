<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

/**
 * Counts how many shared-area (Site Layout) placements use a widget, for the widget library.
 *
 * Root contract so Core never depends on the Widget module: the safe default
 * ({@see \App\Support\Widget\NullWidgetAreaUsageCounter}, bound in AppServiceProvider) answers 0 while the module is
 * off; cms/Widget rebinds it in its service provider boot.
 */
interface WidgetAreaUsageCounter
{
    public function count(int $widgetId): int;

    /**
     * Counts for many widgets in one pass (list pages).
     *
     * @param list<int> $widgetIds
     * @return array<int, int> widget id => placement count, one entry per requested id
     */
    public function countMany(array $widgetIds): array;

    /**
     * Designs (widget_themes ids) of one widget that counted placements show, for hub updates that drop a design.
     *
     * @return list<int> distinct theme ids, ascending
     */
    public function themeIdsInUse(int $widgetId): array;
}
