<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Console\Commands\ModuleInstallCommandRunCommand;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * (F2): `cms:module-install-command <Module> <command>` runs a declared `install.artisan` command only when
 * artisan has a command registered under exactly that name. Plain `php artisan <name>` resolves abbreviations
 * (`cms:probe-run:sync-perm` runs `cms:probe-run:sync-permission`, `cms:probe-run:sync` could run a command of a module
 * whose kebab name starts with `probe-run`), so the installer never passes a declared name to artisan directly.
 * Probe commands are registered on the test's console kernel and record their runs in $GLOBALS.
 */
beforeEach(function () {
    $GLOBALS['mirc_runs'] = [];
    foreach (['cms:probe-run:sync-permission', 'cms:probe-run-extra:sync-permission', 'cms:probe-run:fail'] as $name) {
        $command = new class($name) extends Command {
            public function __construct(private readonly string $commandName)
            {
                $this->signature = $commandName;
                parent::__construct();
            }

            public function handle(): int
            {
                $GLOBALS['mirc_runs'][] = $this->commandName;

                return str_ends_with($this->commandName, ':fail') ? self::FAILURE : self::SUCCESS;
            }
        };
        $this->app->make(Kernel::class)->registerCommand($command);
    }
});

afterEach(function () {
    unset($GLOBALS['mirc_runs']);
});

it('runs a declared command registered under exactly that name', function () {
    $this->artisan(ModuleInstallCommandRunCommand::NAME, ['module' => 'ProbeRun', 'declared' => 'cms:probe-run:sync-permission'])
        ->assertExitCode(0);

    expect($GLOBALS['mirc_runs'])->toBe(['cms:probe-run:sync-permission']);
});

it('passes the exit code of a failing declared command on', function () {
    $this->artisan(ModuleInstallCommandRunCommand::NAME, ['module' => 'ProbeRun', 'declared' => 'cms:probe-run:fail'])
        ->assertExitCode(1);

    expect($GLOBALS['mirc_runs'])->toBe(['cms:probe-run:fail']);
});

it('refuses an abbreviated or unregistered name before running anything', function (string $declared) {
    $this->artisan(ModuleInstallCommandRunCommand::NAME, ['module' => 'ProbeRun', 'declared' => $declared])
        ->assertExitCode(1);

    expect($GLOBALS['mirc_runs'])->toBe([]);
})->with([
    'abbreviated command' => ['cms:probe-run:fa'],
    'ambiguous abbreviation' => ['cms:probe-run:sync-perm'],
    'prefix of another module command' => ['cms:probe-run:sync'],
    'not registered' => ['cms:probe-run:missing'],
]);

it('refuses a name outside the module namespace even when it is registered', function (string $declared) {
    $this->artisan(ModuleInstallCommandRunCommand::NAME, ['module' => 'ProbeRun', 'declared' => $declared])
        ->assertExitCode(1);

    expect($GLOBALS['mirc_runs'])->toBe([]);
})->with([
    'other module' => ['cms:probe-run-extra:sync-permission'],
    'core command' => ['cache:clear'],
]);

it('a real artisan abbreviation would have run the other command', function () {
    // Guard for the premise of F2: `php artisan <name>` (the console kernel with argv) resolves an abbreviation that
    // matches one command, here `cms:probe-run:fa` to `cms:probe-run:fail`.
    $this->app->make(Kernel::class)->handle(new ArgvInput(['artisan', 'cms:probe-run:fa']), new BufferedOutput());

    expect($GLOBALS['mirc_runs'])->toBe(['cms:probe-run:fail']);
});
