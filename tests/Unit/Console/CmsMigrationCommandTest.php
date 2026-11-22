<?php

uses(\Tests\TestCase::class);

use App\Console\Commands\CmsMigration;
use Illuminate\Console\Command;

test('cms migrate skips stored procedure migration on non mysql drivers', function () {
    $command = new class extends CmsMigration {
        public array $warnings = [];
        public bool $discovered = false;

        protected function currentDriver(): string
        {
            return 'pgsql';
        }

        protected function discoverProcedureFiles(): array
        {
            $this->discovered = true;

            return [];
        }

        public function warn($string, $verbosity = null): void
        {
            $this->warnings[] = $string;
        }

        public function info($string, $verbosity = null): void {}
    };

    $status = $command->handle();

    expect($status)->toBe(Command::SUCCESS);
    expect($command->warnings)->toContain(
        'Skipping MySQL stored procedure migration because the current database driver is not mysql.'
    );
    expect($command->discovered)->toBeFalse();
});

test('cms migrate runs discovered stored procedure files on mysql', function () {
    $command = new class extends CmsMigration {
        public array $infos = [];
        public array $executed = [];

        protected function currentDriver(): string
        {
            return 'mysql';
        }

        protected function discoverProcedureFiles(): array
        {
            return [
                '/tmp/2024_01_19_000000_create_get_daily_tracking_table.php',
                '/tmp/2024_12_01_000000_create_get_best_view_of_target_type_table.php',
            ];
        }

        protected function runMigrationFile(string $file): bool
        {
            $this->executed[] = $file;

            return true;
        }

        public function info($string, $verbosity = null): void
        {
            $this->infos[] = $string;
        }

        public function warn($string, $verbosity = null): void {}
    };

    $status = $command->handle();

    expect($status)->toBe(Command::SUCCESS);
    expect($command->executed)->toBe([
        '/tmp/2024_01_19_000000_create_get_daily_tracking_table.php',
        '/tmp/2024_12_01_000000_create_get_best_view_of_target_type_table.php',
    ]);
    expect($command->infos)->toBe([
        '2024_01_19_000000_create_get_daily_tracking_table.php migrated',
        '2024_12_01_000000_create_get_best_view_of_target_type_table.php migrated',
    ]);
});

test('cms migrate does not report skipped stored procedure files as migrated', function () {
    $command = new class extends CmsMigration {
        public array $infos = [];
        public array $warnings = [];

        protected function currentDriver(): string
        {
            return 'mysql';
        }

        protected function discoverProcedureFiles(): array
        {
            return [
                '/tmp/2024_01_20_000000_create_get_counting_report_table.php',
            ];
        }

        protected function runMigrationFile(string $file): bool
        {
            $this->warn(basename($file) . ' skipped because required modules are disabled.');

            return false;
        }

        public function info($string, $verbosity = null): void
        {
            $this->infos[] = $string;
        }

        public function warn($string, $verbosity = null): void
        {
            $this->warnings[] = $string;
        }
    };

    $status = $command->handle();

    expect($status)->toBe(Command::SUCCESS);
    expect($command->infos)->toBe([]);
    expect($command->warnings)->toContain(
        '2024_01_20_000000_create_get_counting_report_table.php skipped because required modules are disabled.'
    );
});

test('cms migrate injects enabled modules before running stored procedure migration', function () {
    $migration = new class {
        public array $enabledModules = [];
        public bool $ran = false;

        public function setEnabledModules(array $enabledModules): void
        {
            $this->enabledModules = $enabledModules;
        }

        public function up(): void
        {
            $this->ran = true;
        }
    };

    $file = tempnam(sys_get_temp_dir(), 'cms-migration-');
    file_put_contents($file, '<?php return $GLOBALS["cms_migration_test_object"];');
    $GLOBALS['cms_migration_test_object'] = $migration;

    $command = new class extends CmsMigration {
        protected function enabledModules(): array
        {
            return [
                'Core' => true,
                'Content' => false,
            ];
        }

        public function runForTest(string $file): bool
        {
            return $this->runMigrationFile($file);
        }
    };

    try {
        expect($command->runForTest($file))->toBeTrue();
        expect($migration->ran)->toBeTrue();
        expect($migration->enabledModules)->toBe([
            'Core' => true,
            'Content' => false,
        ]);
    } finally {
        unset($GLOBALS['cms_migration_test_object']);
        @unlink($file);
    }
});

test('cms migrate skips migration when required module is disabled', function () {
    $migration = new class {
        public bool $ran = false;

        public function requiredModules(): array
        {
            return ['Content'];
        }

        public function up(): void
        {
            $this->ran = true;
        }
    };

    $file = tempnam(sys_get_temp_dir(), 'cms-migration-');
    file_put_contents($file, '<?php return $GLOBALS["cms_migration_required_test_object"];');
    $GLOBALS['cms_migration_required_test_object'] = $migration;

    $command = new class extends CmsMigration {
        public array $warnings = [];

        protected function enabledModules(): array
        {
            return [
                'Core' => true,
                'Content' => false,
            ];
        }

        public function warn($string, $verbosity = null): void
        {
            $this->warnings[] = $string;
        }

        public function runForTest(string $file): bool
        {
            return $this->runMigrationFile($file);
        }
    };

    try {
        expect($command->runForTest($file))->toBeFalse();
        expect($migration->ran)->toBeFalse();
        expect($command->warnings)->toContain(basename($file) . ' skipped because required modules are disabled.');
    } finally {
        unset($GLOBALS['cms_migration_required_test_object']);
        @unlink($file);
    }
});
