<?php

namespace Cms\Core\Console {
    use Illuminate\Console\Command;

    // SetupCoreModuleCommand only runs commands whose class lives in
    // Cms\Core\Console\. An anonymous class is named after its parent
    // (Illuminate\Console\Command@anonymous), so the fake must be a named class.
    if (!class_exists(SetupCoreModuleFakeCommand::class, false)) {
        class SetupCoreModuleFakeCommand extends Command
        {
            public function __construct(string $signature)
            {
                $this->signature = $signature;
                parent::__construct();
            }
        }
    }

    function makeFakeCoreCommand(string $signature): Command
    {
        return new SetupCoreModuleFakeCommand($signature);
    }
}

namespace {
    uses(\Cms\Core\Tests\TestCase::class);

    use Cms\Core\Console\SetupCoreModuleCommand;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Artisan;

    test('setup core module command runs core commands in sorted order', function () {
        $alphaCommand = \Cms\Core\Console\makeFakeCoreCommand('cms:core:alpha');
        $betaCommand = \Cms\Core\Console\makeFakeCoreCommand('cms:core:beta');

        Artisan::shouldReceive('all')->once()->andReturn([
            'cms:core:beta' => $betaCommand,
            'other:noop' => new class extends Command {
                protected $signature = 'other:noop';
            },
            'cms:core:alpha' => $alphaCommand,
            'cms:core:setup' => new SetupCoreModuleCommand(),
        ]);

        $command = new class extends SetupCoreModuleCommand {
            public array $called = [];

            public function call($command, array $arguments = [])
            {
                $this->called[] = $command;
                return Command::SUCCESS;
            }

            public function line($string, $style = null, $verbosity = null) {}
            public function info($string, $verbosity = null) {}
            public function warn($string, $verbosity = null) {}
            public function error($string, $verbosity = null) {}
        };

        $status = $command->handle();

        expect($status)->toBe(Command::SUCCESS);
        expect($command->called)->toBe(['cms:core:alpha', 'cms:core:beta']);
    });

    test('setup core module command exits cleanly when no core commands exist', function () {
        Artisan::shouldReceive('all')->once()->andReturn([
            'cms:core:setup' => new SetupCoreModuleCommand(),
            'other:noop' => new class extends Command {
                protected $signature = 'other:noop';
            },
        ]);

        $command = new class extends SetupCoreModuleCommand {
            public function call($command, array $arguments = [])
            {
                return Command::SUCCESS;
            }

            public function line($string, $style = null, $verbosity = null) {}
            public function info($string, $verbosity = null) {}
            public function warn($string, $verbosity = null) {}
            public function error($string, $verbosity = null) {}
        };

        $status = $command->handle();

        expect($status)->toBe(Command::SUCCESS);
    });
}
