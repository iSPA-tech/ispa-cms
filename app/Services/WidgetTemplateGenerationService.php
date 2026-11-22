<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class WidgetTemplateGenerationService
{
    public function regenerate(): void
    {
        $exitCode = Artisan::call('cms:generate-widget-template');

        if ($exitCode !== 0) {
            throw new RuntimeException('Widget template generation failed. Retry cms:generate-widget-template.');
        }
    }
}
