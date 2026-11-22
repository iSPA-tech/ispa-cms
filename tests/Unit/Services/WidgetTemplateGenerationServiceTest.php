<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\WidgetTemplateGenerationService;
use Illuminate\Support\Facades\Artisan;

uses(TestCase::class);

test('generation service exposes a retryable failure when the command fails', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:generate-widget-template')
        ->andReturn(1);

    expect(fn () => (new WidgetTemplateGenerationService())->regenerate())
        ->toThrow(\RuntimeException::class, 'Widget template generation failed');
});
