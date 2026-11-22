<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\WidgetTemplatePathResolver;

uses(TestCase::class);

test('widget template resolver prefers the canonical generated storage path', function () {
    config()->set('ispa-cms.folder_name', 'Test Project');

    $paths = (new WidgetTemplatePathResolver())->candidates(7, 11);

    expect($paths[0])->toBe(storage_path('app/generated_widgets/test-project/widget_7_theme_11.blade.php'))
        ->and($paths)->toContain(storage_path('app/generated_widgets/default/widget_7_theme_11.blade.php'))
        ->and($paths)->toContain(resource_path('views/widgets/widget_7_theme_11.blade.php'));
});
