<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Presets;

/**
 * Items of a preset set that come from the site's own records (e.g. `content.categories`: the post categories).
 *
 * A set names its class as `resolver` next to `source`; the class is resolved from the container on each request.
 *
 * @package Cms\Core\Services\Workflow\Presets
 * @since   2026-11-24
 */
interface WorkflowPresetSource
{
    /**
     * Items in the set kind's shape (classify_labels: `[{key, label}]`), labels in the user's language.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array;
}
