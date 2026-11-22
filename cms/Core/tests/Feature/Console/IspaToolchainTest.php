<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Console\Commands\DevCommand;
use Cms\Core\Services\Modules\Install\AdminRebuilder;
use Cms\Core\Services\Toolchain\AdminPaths;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * `./ispa start|build` and the AdminRebuilder fallback. Every path is a temp folder: base path (through an injected
 * AdminPaths), public path and storage path. The process runner is a recording fake, so nothing builds or serves.
 */
class FakeToolchainRunner extends ProcessRunner
{
    /** @var array<int, array{cmd: array<int, string>, cwd: ?string, env: array<string, string|false>}> */
    public array $runs = [];

    /** @var array<string, array{cmd: array<int, string>, cwd: ?string, env: array<string, string|false>}> */
    public array $parallel = [];

    /** @var array<string, int> Exit code by "cmd joined with spaces" (substring match); default 0. */
    public array $codes = [];

    public function run(array $cmd, ?string $cwd, array $env = [], bool $quiet = false): int
    {
        $this->runs[] = ['cmd' => $cmd, 'cwd' => $cwd, 'env' => $env];
        $line = implode(' ', $cmd);
        foreach ($this->codes as $needle => $code) {
            if (str_contains($line, $needle)) {
                return $code;
            }
        }

        return 0;
    }

    public function parallel(array $jobs): int
    {
        $this->parallel = $jobs;

        return 0;
    }

    /** The recorded runs whose command line contains the text. */
    public function ran(string $needle): array
    {
        return array_values(array_filter($this->runs, fn ($r) => str_contains(implode(' ', $r['cmd']), $needle)));
    }
}

beforeEach(function () {
    $this->tmp = realpath(sys_get_temp_dir()).'/ispa-toolchain-'.Str::random(8);
    $this->site = $this->tmp.'/site';
    File::ensureDirectoryExists($this->site.'/admin');
    File::put($this->site.'/admin/package.json', '{}');

    $this->origPublic = app()->publicPath();
    $this->origStorage = app()->storagePath();
    $this->origPath = getenv('PATH');
    app()->usePublicPath($this->tmp.'/public');
    app()->useStoragePath($this->tmp.'/storage');

    config([
        'ispa-cms.app_version' => '2026.12.0',
        'ispa-cms.admin_auth_key' => 'key-123',
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'app.url' => 'https://example.com',
    ]);

    $this->runner = new FakeToolchainRunner();
    app()->instance(ProcessRunner::class, $this->runner);
    app()->instance(AdminPaths::class, new AdminPaths($this->site));
    $this->marker = $this->tmp.'/storage/app/ispa/custom-build.json';
});

afterEach(function () {
    app()->usePublicPath($this->origPublic);
    app()->useStoragePath($this->origStorage);
    putenv('PATH='.$this->origPath);
    File::deleteDirectory($this->tmp);
});

test('build runs site and admin, then writes config.js and the marker', function () {
    $this->artisan('cms:build')->assertExitCode(0);

    $builds = $this->runner->ran('build');
    expect($builds)->toHaveCount(2);
    expect($builds[0]['cwd'])->toBe($this->site)->and($builds[0]['cmd'])->toBe(['pnpm', 'build']);

    $admin = $builds[1];
    expect($admin['cmd'])->toBe(['pnpm', '--dir', realpath($this->site.'/admin'), 'build']);
    expect($admin['env']['ADMIN_BUILD_OUTPUT_DIR'])->toBe($this->tmp.'/public/iPanel')
        ->and($admin['env']['VITE_NODE_ENV'])->toBe('production')
        ->and($admin['env']['NODE_ENV'])->toBe('production')
        ->and($admin['env']['VITE_BUILD_VERSION'])->toStartWith('2026.12.0-')
        ->and($admin['env']['VITE_BASE_URL'])->toBe('')
        ->and($admin['env']['VITE_APP_ADMIN_AUTH_KEY'])->toBe('');

    expect(File::get($this->tmp.'/public/iPanel/config.js'))->toContain('"adminAuthKey":"key-123"');

    $marker = json_decode(File::get($this->marker), true);
    expect($marker['site'])->toBeTrue()->and($marker['admin'])->toBeTrue()
        ->and($marker['version'])->toBe('2026.12.0')
        ->and(strtotime($marker['built_at']))->not->toBeFalse();
});

test('build --admin builds only the admin and a later --site keeps admin true in the marker', function () {
    $this->artisan('cms:build', ['--admin' => true])->assertExitCode(0);

    expect($this->runner->ran('build'))->toHaveCount(1)
        ->and($this->runner->ran('--dir'))->toHaveCount(1);
    $marker = json_decode(File::get($this->marker), true);
    expect($marker['site'])->toBeFalse()->and($marker['admin'])->toBeTrue();

    $this->artisan('cms:build', ['--site' => true])->assertExitCode(0);

    $marker = json_decode(File::get($this->marker), true);
    expect($marker['site'])->toBeTrue()->and($marker['admin'])->toBeTrue();
});

test('build fails cleanly without pnpm or on a build error: exit 1, the fix, no marker, config.js untouched', function () {
    File::ensureDirectoryExists($this->tmp.'/public/iPanel');
    File::put($this->tmp.'/public/iPanel/config.js', 'RELEASED');

    // pnpm missing
    $this->runner->codes = ['--version' => 127];
    $this->artisan('cms:build')->expectsOutputToContain('corepack enable')->assertExitCode(1);
    expect($this->runner->ran('build'))->toBeEmpty();

    // site build fails
    $this->runner->codes = ['pnpm build' => 1];
    $this->artisan('cms:build')->expectsOutputToContain('The site build failed')->assertExitCode(1);

    // admin build fails
    $this->runner->codes = ['--dir' => 1];
    $this->artisan('cms:build', ['--admin' => true])->expectsOutputToContain('The admin build failed')->assertExitCode(1);

    expect(file_exists($this->marker))->toBeFalse()
        ->and(File::get($this->tmp.'/public/iPanel/config.js'))->toBe('RELEASED');
});

test('build --admin without admin sources exits 1 and builds nothing', function () {
    app()->instance(AdminPaths::class, new AdminPaths($this->tmp.'/empty'));

    $this->artisan('cms:build', ['--admin' => true])->expectsOutputToContain('admin sources were not found')->assertExitCode(1);

    expect($this->runner->runs)->toBeEmpty()->and(file_exists($this->marker))->toBeFalse();
});

test('dev writes the admin env and starts three processes', function () {
    File::ensureDirectoryExists($this->site.'/node_modules');
    File::ensureDirectoryExists($this->site.'/admin/node_modules');

    $this->artisan('cms:dev')->assertExitCode(0);

    $env = File::get($this->site.'/admin/.env.development.local');
    expect($env)->toStartWith(DevCommand::ENV_MARKER)
        ->and($env)->toContain('VITE_BASE_URL="127.0.0.1:8000"')
        ->and($env)->toContain('VITE_PROTOCOL="http"')
        ->and($env)->toContain('VITE_DOMAIN="127.0.0.1"')
        ->and($env)->toContain('VITE_APP_ADMIN_AUTH_KEY="key-123"')
        ->and($env)->toContain('VITE_CMS_API_URL="https://hub.test"');

    $jobs = $this->runner->parallel;
    expect(array_keys($jobs))->toBe(['serve', 'site', 'admin']);
    expect($jobs['serve']['cmd'])->toContain('artisan', 'serve', '--port=8000')
        ->and($jobs['serve']['cwd'])->toBe($this->site);
    expect($jobs['site']['cmd'])->toBe(['pnpm', 'exec', 'vite']);
    expect($jobs['admin']['cmd'])->toBe(['pnpm', '--dir', realpath($this->site.'/admin'), 'exec', 'vite', '--port', '3000']);

    // custom ports, and a value that needs quoting
    config(['ispa-cms.admin_auth_key' => 'a"b$c\\d']);
    $this->artisan('cms:dev', ['--port' => 9000, '--admin-port' => 4000])->assertExitCode(0);
    expect(File::get($this->site.'/admin/.env.development.local'))
        ->toContain('VITE_BASE_URL="127.0.0.1:9000"')
        ->toContain('VITE_APP_ADMIN_AUTH_KEY="a\\"b\\$c\\\\d"');
    expect(end($this->runner->parallel['admin']['cmd']))->toBe('4000');
});

test('dev refuses without admin root, node_modules, pnpm, or over an owner env file', function () {
    // no admin root
    app()->instance(AdminPaths::class, new AdminPaths($this->tmp.'/empty'));
    $this->artisan('cms:dev')->expectsOutputToContain('admin sources were not found')->assertExitCode(1);

    // no node_modules
    app()->instance(AdminPaths::class, new AdminPaths($this->site));
    $this->artisan('cms:dev')->expectsOutputToContain('pnpm install')->assertExitCode(1);

    File::ensureDirectoryExists($this->site.'/node_modules');
    File::ensureDirectoryExists($this->site.'/admin/node_modules');

    // no pnpm
    $this->runner->codes = ['--version' => 127];
    $this->artisan('cms:dev')->expectsOutputToContain('corepack enable')->assertExitCode(1);
    $this->runner->codes = [];

    // an env file the owner wrote is never overwritten
    File::put($this->site.'/admin/.env.development.local', "VITE_BASE_URL=mine\n");
    $this->artisan('cms:dev')->expectsOutputToContain('left alone')->assertExitCode(1);
    expect(File::get($this->site.'/admin/.env.development.local'))->toBe("VITE_BASE_URL=mine\n");

    expect($this->runner->parallel)->toBeEmpty();
});

test('AdminRebuilder falls back to cms:build --admin on a flat site with admin sources, node and pnpm', function () {
    $bin = $this->tmp.'/bin';
    File::ensureDirectoryExists($bin);
    foreach (['node', 'pnpm'] as $tool) {
        File::put($bin.'/'.$tool, "#!/bin/sh\n");
        chmod($bin.'/'.$tool, 0755);
    }
    putenv('PATH='.$bin);
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    $result = (new AdminRebuilder(null, $this->site))->rebuild();

    expect($result['ok'])->toBeTrue();
    Process::assertRan(fn ($p) => is_array($p->command) && array_slice($p->command, -2) === ['cms:build', '--admin']
        && $p->path === realpath($this->site));

    // a failing build is reported as MODULE_ADMIN_BUILD_FAILED
    Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);
    $failed = (new AdminRebuilder(null, $this->site))->rebuild();
    expect($failed['errorCode'])->toBe(AdminRebuilder::ERROR_FAILED);

});

test('AdminRebuilder keeps the not-deployed warning on a flat site without node and pnpm, or without admin sources', function () {
    Process::fake();
    putenv('PATH='.$this->tmp.'/nothing');

    $missing = (new AdminRebuilder(null, $this->site))->rebuild();
    expect($missing['errorCode'])->toBe(AdminRebuilder::ERROR_NOT_DEPLOYED);

    $noAdmin = (new AdminRebuilder(null, $this->tmp))->rebuild();
    expect($noAdmin['errorCode'])->toBe(AdminRebuilder::ERROR_NOT_DEPLOYED);

    Process::assertNothingRan();
});

test('the ispa script maps commands', function () {
    $run = function (array $args): array {
        $process = new SymfonyProcess([PHP_BINARY, 'ispa', ...$args], base_path());
        $process->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    };

    [$code, $out] = $run([]);
    expect($code)->toBe(0)->and($out)->toContain('start')->toContain('build')->toContain('upgrade');

    [$code, $out] = $run(['nope']);
    expect($code)->toBe(1)->and($out)->toContain('Unknown command')->toContain('start');
});

/** A copy of the `ispa` script in a temp base with the repo's vendor/ linked in and no bootstrap/ folder. */
function ispaRunnerBase(string $tmp): string
{
    $base = $tmp.'/runner';
    File::ensureDirectoryExists($base.'/app');
    copy(base_path('ispa'), $base.'/ispa');
    symlink(base_path('vendor'), $base.'/vendor');
    file_put_contents($base.'/app/X.php', '<?php');
    file_put_contents($base.'/core-manifest.json', json_encode([
        'format' => 1, 'version' => '2026.11.0', 'php_min' => '8.1', 'built_at' => '2026-11-22T00:00:00+00:00',
        'composer_lock_sha256' => '', 'checked' => ['app/X.php' => hash_file('sha256', $base.'/app/X.php')], 'replaced' => [],
    ]));

    return $base;
}

test('ispa upgrade --run-requested exits 0 without booting when idle', function () {
    $base = ispaRunnerBase($this->tmp);
    $process = new SymfonyProcess([PHP_BINARY, 'ispa', 'upgrade', '--run-requested'], $base);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and(is_file($base.'/'.\Cms\Core\Services\CoreUpgrade\CoreRunnerTick::HEARTBEAT_FILE))->toBeTrue();
});

test('ispa upgrade --run-requested boots only when a request exists', function () {
    $base = ispaRunnerBase($this->tmp);
    \Cms\Core\Services\CoreUpgrade\CoreRunnerTick::writeRequest($base, 'restore', '0b6f2c1e-4a7d-4c3e-9f10-2a3b4c5d6e7f', null, PHP_VERSION);
    $process = new SymfonyProcess([PHP_BINARY, 'ispa', 'upgrade', '--run-requested'], $base);
    $process->run();

    // bootstrap/app.php is missing, so getting past the fast path fails.
    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('bootstrap/app.php');
});

test('the scheduler registers core-upgrade-runner every minute without an overlap lock', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    $events = array_values(array_filter($schedule->events(), fn ($e) => $e->description === 'core-upgrade-runner'));

    expect($events)->toHaveCount(1)->and($events[0]->expression)->toBe('* * * * *')
        ->and($events[0]->withoutOverlapping)->toBeFalse();
});

test('the scheduled runner only starts a detached runner process when a request waits', function () {
    $base = ispaRunnerBase($this->tmp);
    $spawned = [];
    app()->instance(\Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher::class, new \Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher($base, function (array $command) use (&$spawned): void {
        $spawned[] = $command;
    }));
    // The engine never runs inside schedule:run.
    app()->bind(\Cms\Core\Services\CoreUpgrade\CoreRequestRunner::class, fn () => throw new RuntimeException('The upgrade ran in-process.'));
    $event = array_values(array_filter(app(\Illuminate\Console\Scheduling\Schedule::class)->events(), fn ($e) => $e->description === 'core-upgrade-runner'))[0];

    $event->run(app());
    expect($spawned)->toBe([])
        ->and(is_file($base.'/'.\Cms\Core\Services\CoreUpgrade\CoreRunnerTick::HEARTBEAT_FILE))->toBeTrue();

    \Cms\Core\Services\CoreUpgrade\CoreRunnerTick::writeRequest($base, 'restore', '0b6f2c1e-4a7d-4c3e-9f10-2a3b4c5d6e7f', null, PHP_VERSION);
    $event->run(app());
    expect($spawned)->toHaveCount(1)
        ->and(array_slice($spawned[0], 1))->toBe([$base.'/ispa', 'upgrade', '--run-requested'])
        ->and(is_file($base.'/'.\Cms\Core\Services\CoreUpgrade\CoreRunnerTick::REQUEST_FILE))->toBeTrue();
});

test('the CLI refuses while the runner lock is held', function () {
    $base = $this->tmp.'/locked';
    File::ensureDirectoryExists($base);
    app()->instance(\Cms\Core\Services\CoreUpgrade\CoreUpgrader::class, new \Cms\Core\Services\CoreUpgrade\CoreUpgrader($base));
    $lock = \Cms\Core\Services\CoreUpgrade\CoreRunnerTick::acquireLock($base);
    expect($lock)->not->toBeNull();

    try {
        $this->artisan('cms:core-upgrade', ['--restore' => true])
            ->expectsOutputToContain('A core upgrade is already running on this site.')
            ->assertExitCode(1);
    } finally {
        \Cms\Core\Services\CoreUpgrade\CoreRunnerTick::releaseLock($lock);
    }
});

test('--check reports a quiet runner with its last heartbeat and the setup line', function () {
    $base = ispaRunnerBase($this->tmp);
    app()->instance(\Cms\Core\Services\CoreUpgrade\CoreUpgrader::class, new \Cms\Core\Services\CoreUpgrade\CoreUpgrader($base));

    $this->artisan('cms:core-upgrade', ['--check' => true, '--to' => '2026.12.0'])
        // One output line: the "not set up" notice ends with the runner line, the base folder shell-quoted.
        ->expectsOutputToContain(': * * * * * cd '.escapeshellarg($base).' && php ispa upgrade --run-requested >/dev/null 2>&1');

    (new \Cms\Core\Services\CoreUpgrade\CoreRunnerTick($base))->writeHeartbeat(time() - 600);
    $this->artisan('cms:core-upgrade', ['--check' => true, '--to' => '2026.12.0'])
        ->expectsOutputToContain('Upgrade runner: not seen in the last 3 minutes (last as ')
        ->expectsOutputToContain('php ispa upgrade --run-requested');
});
