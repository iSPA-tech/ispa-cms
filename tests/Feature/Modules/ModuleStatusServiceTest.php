<?php

declare(strict_types=1);

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\Exceptions\CoreModuleCannotBeDisabled;
use App\Support\Modules\ModuleStatusService;
use Cms\Core\Models\AuditLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Nwidart\Modules\Facades\Module;

/**
 * ModuleStatusService writes the status through nwidart (facade mocked:
 * the real modules_statuses.json is never written), records one audit row
 * and queues RefreshRuntimeCachesJob. The refresh job runs against a fake
 * ArtisanSubprocess and temp cache paths (never the real bootstrap/cache).
 */
const P3T17_MODULE = 'UnguardedFixture';

/**
 * Fake subprocess recording every artisan call; commands listed in
 * $failing answer with exit code 1.
 */
function p3t17FakeArtisan(array $failing = []): ArtisanSubprocess
{
    return new class($failing) extends ArtisanSubprocess {
        /** @var list<array<int, string>> */
        public array $calls = [];

        public function __construct(private readonly array $failing)
        {
        }

        public function run(array $args, int $timeout): ProcessResult
        {
            $this->calls[] = $args;

            return new FakeProcessResult(
                command: implode(' ', $args),
                exitCode: in_array($args[0], $this->failing, true) ? 1 : 0,
            );
        }
    };
}

describe('service', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Module::shouldReceive('allEnabled')->andReturn([]);
        Module::shouldReceive('all')->andReturn([]);
    });

    test('enable writes through nwidart, writes one audit row naming the module and queues the refresh job', function (): void {
        Module::shouldReceive('enable')->once()->with(P3T17_MODULE);

        app(ModuleStatusService::class)->change(P3T17_MODULE, true, null, 'cli');

        $rows = AuditLog::query()->where('target_type', 'module_status')->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->action)->toBe('action.module.enabled')
            ->and($rows[0]->changed)->toMatchArray(['module' => P3T17_MODULE, 'reason' => 'cli']);
        Queue::assertPushed(RefreshRuntimeCachesJob::class, 1);
    });

    test('disable writes a module.disabled audit row', function (): void {
        Module::shouldReceive('disable')->once()->with(P3T17_MODULE);

        app(ModuleStatusService::class)->change(P3T17_MODULE, false, null, 'admin');

        $row = AuditLog::query()->where('target_type', 'module_status')->sole();
        expect($row->action)->toBe('action.module.disabled');
        Queue::assertPushed(RefreshRuntimeCachesJob::class, 1);
    });

    test('the installer path changes the status without queueing a cache refresh', function (): void {
        Module::shouldReceive('enable')->once()->with(P3T17_MODULE);

        app(ModuleStatusService::class)->change(P3T17_MODULE, true, null, 'install', false);

        expect(AuditLog::query()->where('target_type', 'module_status')->count())->toBe(1);
        Queue::assertNotPushed(RefreshRuntimeCachesJob::class);
    });

    test('Core cannot be disabled and nothing is written', function (): void {
        Module::shouldReceive('disable')->never();

        expect(fn () => app(ModuleStatusService::class)->change('core', false, null, 'cli'))
            ->toThrow(CoreModuleCannotBeDisabled::class);

        expect(AuditLog::query()->where('target_type', 'module_status')->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('refresh job', function (): void {
    beforeEach(function (): void {
        $this->cacheDir = sys_get_temp_dir().'/p3t17-cache-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->cacheDir);
        // Point the cached-file paths at a temp dir; the real bootstrap/cache is never touched.
        putenv('APP_CONFIG_CACHE='.$this->cacheDir.'/config.php');
        putenv('APP_ROUTES_CACHE='.$this->cacheDir.'/routes-v7.php');
        putenv('APP_EVENTS_CACHE='.$this->cacheDir.'/events.php');
        $_SERVER['APP_CONFIG_CACHE'] = $this->cacheDir.'/config.php';
        $_SERVER['APP_ROUTES_CACHE'] = $this->cacheDir.'/routes-v7.php';
        $_SERVER['APP_EVENTS_CACHE'] = $this->cacheDir.'/events.php';
    });

    afterEach(function (): void {
        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }
        File::deleteDirectory($this->cacheDir);
    });

    test('rebuilds only caches whose file exists, then restarts the queue', function (): void {
        expect(app()->getCachedRoutesPath())->toStartWith($this->cacheDir);
        File::put($this->cacheDir.'/routes-v7.php', '<?php');

        $artisan = p3t17FakeArtisan();
        (new RefreshRuntimeCachesJob())->handle($artisan, app(Kernel::class));

        expect($artisan->calls)->toBe([['route:cache'], ['queue:restart']]);
    });

    test('clears a cache whose rebuild failed and keeps going', function (): void {
        File::put($this->cacheDir.'/config.php', '<?php return [];');
        File::put($this->cacheDir.'/events.php', '<?php return [];');

        $artisan = p3t17FakeArtisan(['config:cache']);
        (new RefreshRuntimeCachesJob())->handle($artisan, app(Kernel::class));

        expect($artisan->calls)->toBe([['config:cache'], ['config:clear'], ['event:cache'], ['queue:restart']]);
    });

    test('a cache is built into a temporary file and renamed, so the live file never goes missing', function (): void {
        $live = $this->cacheDir.'/routes-v7.php';
        File::put($live, '<?php // old');

        $artisan = new class($live) extends ArtisanSubprocess {
            /** @var list<array{args: array<int, string>, live_present: bool, target: ?string}> */
            public array $seen = [];

            public function __construct(private readonly string $live)
            {
            }

            public function run(array $args, int $timeout): ProcessResult
            {
                $target = $this->extraEnvironment()['APP_ROUTES_CACHE'] ?? null;
                $this->seen[] = ['args' => $args, 'live_present' => is_file($this->live), 'target' => $target];
                if ($args[0] === 'route:cache' && $target !== null) {
                    // What `route:cache` does: clear its (temporary) file, then write the new one.
                    @unlink($target);
                    file_put_contents($target, '<?php // new');
                }

                return new FakeProcessResult(command: implode(' ', $args), exitCode: 0);
            }
        };
        (new RefreshRuntimeCachesJob())->handle($artisan, app(Kernel::class));

        $route = $artisan->seen[0];
        expect($route['args'])->toBe(['route:cache'])
            ->and($route['live_present'])->toBeTrue()
            ->and(dirname((string) $route['target']))->toBe($this->cacheDir)
            ->and($route['target'])->not->toBe($live)
            ->and(file_get_contents($live))->toBe('<?php // new')
            ->and(glob($this->cacheDir.'/.*.tmp.php'))->toBe([])
            ->and($artisan->extraEnvironment())->toBe([]);
    });

    test('temporary cache files older than ten minutes are removed, fresh ones kept', function (): void {
        $old = $this->cacheDir.'/.routes-v7.aaaa.tmp.php';
        $fresh = $this->cacheDir.'/.routes-v7.bbbb.tmp.php';
        File::put($old, '<?php');
        File::put($fresh, '<?php');
        touch($old, time() - 3600);

        (new RefreshRuntimeCachesJob())->handle(p3t17FakeArtisan(), app(Kernel::class));

        expect(is_file($old))->toBeFalse()->and(is_file($fresh))->toBeTrue();
    });

    test('a failed rebuild removes its temporary file and clears the cache as before', function (): void {
        File::put($this->cacheDir.'/routes-v7.php', '<?php // old');
        $artisan = new class extends ArtisanSubprocess {
            public array $calls = [];

            public function __construct()
            {
            }

            public function run(array $args, int $timeout): ProcessResult
            {
                $this->calls[] = $args;
                $target = $this->extraEnvironment()['APP_ROUTES_CACHE'] ?? null;
                if ($target !== null) {
                    file_put_contents($target, '<?php // half');
                }

                return new FakeProcessResult(command: implode(' ', $args), exitCode: $args[0] === 'route:cache' ? 1 : 0);
            }
        };
        (new RefreshRuntimeCachesJob())->handle($artisan, app(Kernel::class));

        expect($artisan->calls)->toBe([['route:cache'], ['route:clear'], ['queue:restart']])
            ->and(glob($this->cacheDir.'/.*.tmp.php'))->toBe([]);
    });

    test('terminates horizon only when the command exists', function (): void {
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('all')->andReturn(['horizon:terminate' => new stdClass()]);

        $artisan = p3t17FakeArtisan();
        (new RefreshRuntimeCachesJob())->handle($artisan, $kernel);

        expect($artisan->calls)->toBe([['queue:restart'], ['horizon:terminate']]);

        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('all')->andReturn([]);
        $artisan = p3t17FakeArtisan();
        (new RefreshRuntimeCachesJob())->handle($artisan, $kernel);

        expect($artisan->calls)->toBe([['queue:restart']]);
    });

    test('is unique for 120 seconds', function (): void {
        $job = new RefreshRuntimeCachesJob();

        expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class)
            ->and($job->uniqueFor)->toBe(120);
    });
});
