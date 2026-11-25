<?php

/*
 * Workflow designer presets this module adds (merged as `content.workflow_presets`, registered with Core's
 * WorkflowPresetCatalog::registerModuleConfig() from the provider).
 *
 * `post_categories`: AI classify labels read from the site's own published post categories (`source:
 * content.categories`); each label key is the category uuid, which the "set post categories" node accepts.
 */

return [
    'sets' => [
        'post_categories' => [
            'kind' => 'classify_labels',
            'label' => 'Post categories of the site',
            'description' => 'The published post categories of this site, one label each.',
            'source' => 'content.categories',
            'resolver' => \Cms\Content\Services\Workflow\PostCategoryLabelSource::class,
        ],
    ],
];
