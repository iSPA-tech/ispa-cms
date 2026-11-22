<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use Cms\Core\Console\Commands\ModuleStatusCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/**
 * Read-only listing of module statuses through nwidart (file activator in tests).
 */
beforeEach(function (): void {
    $this->app->make(Kernel::class)->registerCommand($this->app->make(ModuleStatusCommand::class));
    $this->run = function (array $options): array {
        $code = Artisan::call('cms:module-status', $options);

        return [$code, Artisan::output()];
    };
});

test('plain lists one module name per line', function (): void {
    [$code, $output] = ($this->run)(['--plain' => true]);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

    expect($code)->toBe(0)
        ->and($lines)->toContain('Core')
        ->and(count($lines))->toBe(count(app('modules')->all()));
});

test('enabled limits the listing to enabled modules', function (): void {
    [$code, $output] = ($this->run)(['--plain' => true, '--enabled' => true]);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

    $expected = array_values(array_map(fn ($m) => $m->getName(), app('modules')->allEnabled()));
    sort($expected);

    expect($code)->toBe(0)
        ->and($lines)->toBe($expected)
        ->and($lines)->toContain('Core');
});

test('json prints name, enabled and version for every module', function (): void {
    [$code, $output] = ($this->run)(['--json' => true]);
    $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    $core = collect($rows)->firstWhere('name', 'Core');

    expect($code)->toBe(0)
        ->and($rows)->toHaveCount(count(app('modules')->all()))
        ->and(array_keys($rows[0]))->toBe(['name', 'enabled', 'version'])
        ->and($core['enabled'])->toBeTrue()
        ->and($core['version'])->toBe(app('modules')->find('Core')->get('version'));
});

test('default table output succeeds', function (): void {
    [$code, $output] = ($this->run)([]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Activator')
        ->and($output)->toContain('Core');
});

/*
 * `set <Name> on|off`: the status write goes through
 * ModuleStatusService (mocked or refused before any write) and activation
 * tasks run through a fake ArtisanSubprocess, so nothing real is written
 * or executed.
 */
function p3t17CommandArtisan(array $failing = []): \App\Support\Modules\ArtisanSubprocess
{
    return new class($failing) extends \App\Support\Modules\ArtisanSubprocess {
        /** @var list<array<int, string>> */
        public array $calls = [];

        public function __construct(private readonly array $failing)
        {
        }

        public function run(array $args, int $timeout): \Illuminate\Contracts\Process\ProcessResult
        {
            $this->calls[] = $args;

            return new \Illuminate\Process\FakeProcessResult(
                command: implode(' ', $args),
                exitCode: in_array($args[0], $this->failing, true) ? 1 : 0,
                errorOutput: in_array($args[0], $this->failing, true) ? 'boom' : '',
            );
        }
    };
}

test('set refuses to turn Core off', function (): void {
    $artisan = p3t17CommandArtisan();
    $this->app->instance(\App\Support\Modules\ArtisanSubprocess::class, $artisan);
    \Illuminate\Support\Facades\Queue::fake();

    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => 'Core', 'state' => 'off']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('Core module is always enabled')
        ->and($artisan->calls)->toBe([])
        ->and(app('modules')->find('Core')->isEnabled())->toBeTrue();
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});

test('set fails for an unknown module', function (): void {
    $service = Mockery::mock(\App\Support\Modules\ModuleStatusService::class);
    $service->shouldReceive('change')->never();
    $this->app->instance(\App\Support\Modules\ModuleStatusService::class, $service);

    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => 'NoSuchModuleP3T17', 'state' => 'on']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('not found');
});

test('set rejects a state other than on or off', function (): void {
    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => 'Core', 'state' => 'maybe']);

    expect($code)->not->toBe(0);
});

test('set on calls the service and runs the activation tasks in subprocesses', function (): void {
    $service = Mockery::mock(\App\Support\Modules\ModuleStatusService::class);
    $service->shouldReceive('change')->once()->with('Core', true, null, 'owner request');
    $this->app->instance(\App\Support\Modules\ModuleStatusService::class, $service);
    $artisan = p3t17CommandArtisan();
    $this->app->instance(\App\Support\Modules\ArtisanSubprocess::class, $artisan);

    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => 'core', 'state' => 'on', '--reason' => 'owner request']);

    expect($code)->toBe(0)
        ->and($artisan->calls)->toBe([
            ['module:migrate', 'Core', '--force'],
            ['cms:modules-sync', 'Core', '--skip-translations'],
            ['cms:translation-sync', 'Core'],
        ]);
});

test('set on reports a failed activation task with a non-zero exit', function (): void {
    $service = Mockery::mock(\App\Support\Modules\ModuleStatusService::class);
    $service->shouldReceive('change')->once()->with('Core', true, null, 'cli');
    $this->app->instance(\App\Support\Modules\ModuleStatusService::class, $service);
    $artisan = p3t17CommandArtisan(['cms:modules-sync']);
    $this->app->instance(\App\Support\Modules\ArtisanSubprocess::class, $artisan);

    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => 'Core', 'state' => 'on']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('cms:modules-sync Core --skip-translations')
        ->and(count($artisan->calls))->toBe(3);
});

test('set off does not run activation tasks', function (): void {
    $service = Mockery::mock(\App\Support\Modules\ModuleStatusService::class);
    $service->shouldReceive('change')->once()->with('Core', false, null, 'cli');
    $this->app->instance(\App\Support\Modules\ModuleStatusService::class, $service);
    $artisan = p3t17CommandArtisan();
    $this->app->instance(\App\Support\Modules\ArtisanSubprocess::class, $artisan);

    $code = Artisan::call('cms:module-status', ['action' => 'set', 'name' => 'Core', 'state' => 'off']);

    expect($code)->toBe(0)->and($artisan->calls)->toBe([]);
});
