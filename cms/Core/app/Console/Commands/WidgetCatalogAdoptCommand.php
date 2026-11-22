<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Local authoring: turn a site widget into a hub widget of a publisher, so the next `cms:export-widgets` writes it
 * into that publisher's catalog. Refused outside an authoring environment.
 *
 * Usage: php artisan cms:widget-catalog-adopt {widget_key} [--publisher=iSPA]
 */
class WidgetCatalogAdoptCommand extends Command
{
    protected $signature = 'cms:widget-catalog-adopt {widget_key : Key of the site widget to adopt} {--publisher=iSPA : Catalog publisher that will own it}';

    protected $description = 'Mark a local site widget as a hub widget of a catalog publisher (authoring only)';

    public function handle(WidgetAuthoringPolicy $policy): int
    {
        if (!$policy->authoringEnabled()) {
            $this->error('Widget catalog authoring is off here (ispa-cms.widget.catalog_authoring, never production).');

            return self::FAILURE;
        }

        $publisher = (string) $this->option('publisher');
        try {
            WidgetCatalogSources::directory($publisher);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $key = (string) $this->argument('widget_key');
        /** @var Widget|null $widget */
        $widget = Widget::query()->where('widget_key', $key)->first();
        if ($widget === null) {
            $this->error("No widget with key {$key}.");

            return self::FAILURE;
        }
        if ($widget->isHub()) {
            $this->error("Widget {$key} is already a hub widget of {$widget->publisher}.");

            return self::FAILURE;
        }

        // A hub widget carries no site clone state.
        $widget->forceFill([
            'origin' => Widget::ORIGIN_HUB,
            'publisher' => $publisher,
            'cloned_from_widget_key' => null,
            'cloned_at' => null,
        ])->save();

        $this->info("Widget {$key} is now a hub widget of {$publisher}; run cms:export-widgets --publisher={$publisher} to write it.");

        return self::SUCCESS;
    }
}
