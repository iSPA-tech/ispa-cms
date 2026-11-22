<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Modules\ModuleStatus;
use Nwidart\Modules\Facades\Module;

/**
 * Builds a mocked nwidart module exposing the two methods ModuleStatus relies on.
 */
function fakeStatusModule(string $name, bool $enabled): \Nwidart\Modules\Module
{
    $module = Mockery::mock(\Nwidart\Modules\Module::class);
    $module->shouldReceive('getName')->andReturn($name);
    $module->shouldReceive('isEnabled')->andReturn($enabled);

    return $module;
}

beforeEach(function (): void {
    $modules = [
        'content' => fakeStatusModule('Content', true),
        'ecommerce' => fakeStatusModule('Ecommerce', false),
    ];

    Module::shouldReceive('find')->andReturnUsing(
        static fn (string $name) => $modules[strtolower($name)] ?? null
    );
    Module::shouldReceive('allEnabled')->andReturn(['Content' => $modules['content']]);
});

it('reports an enabled module as enabled', function (): void {
    expect(ModuleStatus::isEnabled('Content'))->toBeTrue();
});

it('reports a disabled module as disabled', function (): void {
    expect(ModuleStatus::isEnabled('Ecommerce'))->toBeFalse();
});

it('answers the same for lowercase and uppercase names', function (): void {
    expect(ModuleStatus::isEnabled('content'))->toBeTrue()
        ->and(ModuleStatus::isEnabled('CONTENT'))->toBeTrue()
        ->and(ModuleStatus::isEnabled('ecommerce'))->toBeFalse()
        ->and(ModuleStatus::isEnabled('ECOMMERCE'))->toBeFalse();
});

it('treats an unknown module as disabled', function (): void {
    expect(ModuleStatus::isEnabled('DoesNotExist'))->toBeFalse();
});

it('lists enabled module names and omits disabled ones', function (): void {
    $names = ModuleStatus::enabledNames();

    expect($names)->toContain('Content')->not->toContain('Ecommerce');
});
