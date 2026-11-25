<?php

declare(strict_types=1);

namespace Cms\Content\Services\Workflow;

use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Models\Category;
use Cms\Core\Services\Workflow\Presets\WorkflowPresetSource;

/**
 * Classify labels `content.categories`: the site's published post categories in the current language, read through
 * the Core category repository (CategoryInterface::getCategoriesByType, cached), as `[{key: uuid, label: name}]`.
 * The uuid key is what PostWorkflowNodes' "set categories" accepts, so a chosen label can be applied as is.
 *
 * @package Cms\Content\Services\Workflow
 * @since   2026-11-24
 */
class PostCategoryLabelSource implements WorkflowPresetSource
{
    public function __construct(protected CategoryInterface $categories)
    {
    }

    public function items(): array
    {
        $rows = collect($this->categories->getCategoriesByType('post'));
        $names = $rows->mapWithKeys(static fn ($row): array => [(int) data_get($row, 'id') => (string) data_get($row, 'name')])
            ->filter(static fn (string $name, int $id): bool => $id > 0 && trim($name) !== '');
        if ($names->isEmpty()) {
            return [];
        }

        $uuids = Category::query()->whereIn('id', $names->keys()->all())->pluck('uuid', 'id');

        $items = [];
        foreach ($names as $id => $name) {
            $uuid = $uuids[$id] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                $items[] = ['key' => $uuid, 'label' => $name];
            }
        }

        return $items;
    }
}
