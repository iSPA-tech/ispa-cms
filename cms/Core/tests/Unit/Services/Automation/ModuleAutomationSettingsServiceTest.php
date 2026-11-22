<?php

declare(strict_types=1);

use Cms\Core\Services\Automation\ModuleAutomationSettingsService;

/*
 * the automation settings catalog is registered by the modules (config key `automation-modules`), not written into
 * Core. A module name is matched in any spelling (StudlyCase, kebab-case, snake_case).
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['automation-modules' => ['sample_module' => [
        'config_root' => 'sample-module',
        'label' => 'SampleModule',
        'pipeline_modes' => ['single', 'split'],
        'default_pipeline_mode' => 'single',
        'schedules' => [
            'ingest' => ['label' => 'Ingest', 'config' => 'sample-module.schedules.ingest', 'default_preset' => 'hourly', 'default_expression' => '0 * * * *'],
        ],
        'providers' => ['ai' => ['label' => 'AI', 'options' => 'ai']],
        'models' => [],
    ]]]);
});

it('reads the catalog from the modules, in any spelling of the module name', function () {
    $service = new ModuleAutomationSettingsService();

    foreach (['SampleModule', 'sample-module', 'sample_module'] as $spelling) {
        expect($service->pipelineModes($spelling))->toBe(['single', 'split'])
            ->and($service->scheduleSteps($spelling))->toBe(['ingest'])
            ->and($service->providerTypes($spelling))->toBe(['ai']);
    }
});

it('refuses a module that registered no catalog entry', function () {
    expect(fn () => (new ModuleAutomationSettingsService())->pipelineModes('UnknownModule'))
        ->toThrow(RuntimeException::class, 'Unsupported automation module [UnknownModule].');
});

it('ships no module catalog of its own', function () {
    config(['automation-modules' => []]);

    expect(fn () => (new ModuleAutomationSettingsService())->scheduleSteps('SampleModule'))->toThrow(RuntimeException::class);
});

it('keeps no client module name in the service source', function () {
    $source = (string) file_get_contents(base_path('cms/Core/app/Services/Automation/ModuleAutomationSettingsService.php'));

    expect(preg_match('/catholic|civic/i', $source))->toBe(0);
});
