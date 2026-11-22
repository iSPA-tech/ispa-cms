<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Services\Widget\Catalog\WidgetUsageService;

/**
 * What "Cập nhật" would change, for the update dialog: how many pages and shared areas show the widget, which designs
 * in use the new version drops (the update is then refused), and the labels of input fields the new version adds.
 * Removed inputs need no warning: their values stay stored on pages and the renderer ignores them.
 */
class WidgetHubImpact
{
    public function __construct(
        private readonly WidgetHubInstaller $installer,
        private readonly WidgetUsageService $usage,
    ) {
    }

    /**
     * @return array{pages: int, areas: int, removed_designs_in_use: list<string>, new_input_labels: list<string>}
     *
     * @throws WidgetHubException|WidgetBundleUntrusted
     */
    public function forUpdate(int $widgetId): array
    {
        $widget = $this->installer->updatable($widgetId);
        $bundle = $this->installer->latestBundleFor($widget);
        $summary = $this->usage->summaries([$widget])[(int) $widget->id] ?? ['pages' => 0, 'areas' => 0];

        $installedInputs = $widget->inputs()->pluck('input_key')->map(static fn ($k): string => (string) $k)->all();
        $newLabels = [];
        foreach ($bundle->inputs as $input) {
            if (!in_array($input['input_key'], $installedInputs, true)) {
                $newLabels[] = (string) ($input['name'] ?: $input['input_key']);
            }
        }

        return [
            'pages' => (int) $summary['pages'],
            'areas' => (int) $summary['areas'],
            'removed_designs_in_use' => $this->installer->removedDesignsInUse($widget, $bundle),
            'new_input_labels' => $newLabels,
        ];
    }
}
