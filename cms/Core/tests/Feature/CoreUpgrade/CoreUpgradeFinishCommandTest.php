<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\CoreUpgrade\CoreUpgradeFinisher;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Runner that records every step with its environment and fails the steps named in $fail. */
function cufRunner(array $fail = []): ProcessRunner
{
    return new class($fail) extends ProcessRunner {
        /** @var list<array{cmd: string, env: array<string, mixed>}> */
        public array $steps = [];

        public function __construct(private array $fail)
        {
        }

        public function run(array $cmd, ?string $cwd, array $env = [], bool $quiet = false): int
        {
            $line = implode(' ', array_slice($cmd, 2));
            $this->steps[] = ['cmd' => $line, 'env' => $env];

            return in_array($line, $this->fail, true) ? 1 : 0;
        }

        /** @return list<string> */
        public function lines(): array
        {
            return array_column($this->steps, 'cmd');
        }
    };
}

/** A command standing in for a real one: counts its calls. */
function cufStub(string $name): Command
{
    return new class($name) extends Command {
        public int $calls = 0;

        public function __construct(string $name)
        {
            $this->signature = $name.' {--all} {--public-only}';
            parent::__construct();
        }

        public function handle(): int
        {
            $this->calls++;

            return self::SUCCESS;
        }
    };
}

beforeEach(function () {
    $this->originalBase = base_path();
    $this->dir = sys_get_temp_dir().'/core-finish-'.bin2hex(random_bytes(6));
    mkdir($this->dir.'/.ispa-runner', 0700, true);
    $this->app->setBasePath($this->dir);
    $this->result = $this->dir.'/.ispa-runner/result.json';
});

afterEach(function () {
    $this->app->setBasePath($this->originalBase);
    File::deleteDirectory($this->dir);
});

test('the finish command runs the release-zip steps and writes the outcome', function () {
    $runner = cufRunner();
    $this->app->instance(ProcessRunner::class, $runner);

    $this->artisan('cms:core-upgrade-finish', ['--result' => $this->result])->assertSuccessful();

    expect(json_decode((string) file_get_contents($this->result), true))->toBe(['ok' => true, 'warnings' => []])
        ->and($runner->lines())->toBe([
            'migrate --force', 'cms:modules-sync-permissions', 'optimize:clear', 'cms:admin-config',
            'cms:translation-sync --all', 'cms:translation-generate --public-only',
        ])
        // The admin-config step is told the packs follow, so its bridge stays out of the way.
        ->and($runner->steps[3]['env'])->toBe([CoreUpgradeFinisher::FINISHING_ENV => '1']);
});

test('the finish command runs the deploy steps with --deploy', function () {
    $runner = cufRunner();
    $this->app->instance(ProcessRunner::class, $runner);

    $this->artisan('cms:core-upgrade-finish', ['--deploy' => true, '--result' => $this->result])->assertSuccessful();

    expect($runner->lines())->toContain('config:cache')->toContain('route:cache')->toContain('cms:translation-generate')
        ->and($runner->lines())->not->toContain('cms:translation-generate --public-only');
});

test('a failed migration is reported with its code and fails the command', function () {
    $this->app->instance(ProcessRunner::class, cufRunner(['migrate --force']));

    $this->artisan('cms:core-upgrade-finish', ['--result' => $this->result])->assertFailed();

    expect(json_decode((string) file_get_contents($this->result), true))
        ->toMatchArray(['ok' => false, 'code' => 'CORE_MIGRATE_FAILED']);
});

test('a step that only warns is carried in the outcome', function () {
    $this->app->instance(ProcessRunner::class, cufRunner(['cms:translation-generate --public-only']));

    $this->artisan('cms:core-upgrade-finish', ['--result' => $this->result])->assertSuccessful();

    $outcome = json_decode((string) file_get_contents($this->result), true);
    expect($outcome['ok'])->toBeTrue()
        ->and(implode("\n", $outcome['warnings']))->toContain('cms:translation-generate --public-only');
});

test('admin-config writes the missing language packs only during an update and only when they are missing', function () {
    Storage::fake('public');
    config(['ispa-cms.app_version' => '2026.12.1']);
    $sync = cufStub('cms:translation-sync');
    $generate = cufStub('cms:translation-generate');
    $kernel = $this->app->make(Kernel::class);
    $kernel->registerCommand($sync);
    $kernel->registerCommand($generate);
    $state = $this->dir.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE;
    $configJs = ['--path' => $this->dir.'/config.js'];

    // No update in progress (a deploy, an install, a hand run): nothing.
    Artisan::call('cms:admin-config', $configJs);
    expect($sync->calls + $generate->calls)->toBe(0);

    // An older updater after the swap: packs of the new version missing, so they are written.
    file_put_contents($state, '{}');
    Artisan::call('cms:admin-config', $configJs);
    expect($sync->calls)->toBe(1)->and($generate->calls)->toBe(1);

    // During the new version's own steps: they write the packs themselves.
    putenv(CoreUpgradeFinisher::FINISHING_ENV.'=1');
    try {
        Artisan::call('cms:admin-config', $configJs);
    } finally {
        putenv(CoreUpgradeFinisher::FINISHING_ENV);
    }
    expect($sync->calls)->toBe(1);

    // Packs already there: nothing.
    Storage::disk('public')->put('i18n/2026.12.1/adm/vi.json', '{}');
    Artisan::call('cms:admin-config', $configJs);
    expect($sync->calls)->toBe(1)->and($generate->calls)->toBe(1);
});
