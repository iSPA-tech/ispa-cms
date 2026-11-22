<?php

declare(strict_types=1);

use Cms\Core\Jobs\ApplyConfigPackageJob;
use Cms\Core\Jobs\DryRunConfigPackageJob;
use Cms\Core\Jobs\ExportConfigPackageJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const SMK_PREFIX = 'text.background_tasks.step.';

/**
 * Recorder that remembers every progress call as [percent, message, params].
 */
function smkRecorder(ArrayObject $calls): BackgroundTaskRecorder
{
    return new class ($calls) extends BackgroundTaskRecorder {
        public function __construct(private readonly ArrayObject $calls)
        {
        }

        public function progress(BackgroundTask $task, int $percent, ?string $message = null, array $params = []): BackgroundTask
        {
            $this->calls[] = [$percent, $message, $params];

            return parent::progress($task, $percent, $message, $params);
        }
    };
}

/**
 * Create a queued run of the given kind holding two section names.
 */
function smkRun(string $kind): ConfigPackageRun
{
    return ConfigPackageRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => $kind,
        'status' => ConfigPackageRun::STATUS_QUEUED,
        'sections' => ['settings', 'menus'],
    ]);
}

it('records export progress as keys with done and total counts', function (): void {
    $run = smkRun(ConfigPackageRun::KIND_EXPORT);
    $calls = new ArrayObject();
    $exporter = Mockery::mock(ConfigPackageExporter::class);
    $exporter->shouldReceive('build')->once()->andReturnUsing(function (array $sections, array $options, callable $onSection): array {
        $onSection('settings');
        $onSection('menus');

        throw new RuntimeException('secret-value-xyz');
    });

    (new ExportConfigPackageJob((int) $run->id))->handle($exporter, smkRecorder($calls));

    $rows = $calls->getArrayCopy();
    expect($rows[0][1])->toBe(SMK_PREFIX . 'config_export_started')
        ->and($rows[1][1])->toBe(SMK_PREFIX . 'config_export_section')
        ->and($rows[1][2])->toBe(['done' => 1, 'total' => 2])
        ->and($rows[2][2])->toBe(['done' => 2, 'total' => 2])
        ->and(json_encode($rows))->not->toContain('settings')
        ->and(json_encode($rows))->not->toContain('menus');

    $task = BackgroundTask::query()->where('uuid', $run->fresh()->background_task_uuid)->firstOrFail();
    expect($task->status)->toBe('failed')
        ->and($task->message)->toBe(SMK_PREFIX . 'config_export_failed')
        ->and(json_encode($task->message_params))->not->toContain('secret-value-xyz');
});

it('records dry run progress and failure as keys', function (): void {
    $run = smkRun(ConfigPackageRun::KIND_DRY_RUN);
    $calls = new ArrayObject();
    $differ = Mockery::mock(ConfigPackageDiffer::class);
    $differ->shouldReceive('dryRunInto')->once()->andReturnUsing(function (ConfigPackageRun $r, int $userId, callable $onSection): ConfigPackageRun {
        $onSection('settings');
        $onSection('menus');
        $r->forceFill(['status' => ConfigPackageRun::STATUS_FAILED, 'summary' => ['error' => 'secret-value-xyz']])->save();

        return $r;
    });

    (new DryRunConfigPackageJob($run->uuid, 0))->handle($differ, smkRecorder($calls));

    $rows = $calls->getArrayCopy();
    expect($rows[0][1])->toBe(SMK_PREFIX . 'config_dry_run_started')
        ->and($rows[1][1])->toBe(SMK_PREFIX . 'config_dry_run_section')
        ->and($rows[1][2])->toBe(['done' => 1, 'total' => 2])
        ->and($rows[2][2])->toBe(['done' => 2, 'total' => 2]);

    $task = BackgroundTask::query()->where('uuid', $run->fresh()->background_task_uuid)->firstOrFail();
    expect($task->message)->toBe(SMK_PREFIX . 'config_dry_run_failed')
        ->and(json_encode($task->message_params))->not->toContain('secret-value-xyz');
});

it('records apply progress and failure as keys', function (): void {
    $run = smkRun(ConfigPackageRun::KIND_APPLY);
    $calls = new ArrayObject();
    $applier = Mockery::mock(ConfigPackageApplier::class);
    $applier->shouldReceive('execute')->once()->andReturnUsing(function (ConfigPackageRun $r, int $userId, callable $onSection): ConfigPackageRun {
        $onSection('settings');
        $onSection('menus');
        $r->forceFill(['status' => ConfigPackageRun::STATUS_FAILED, 'summary' => ['error' => 'secret-value-xyz', 'result' => ['succeeded' => [], 'failed' => ['settings'], 'skipped' => []]]])->save();

        return $r;
    });

    (new ApplyConfigPackageJob((int) $run->id, 0))->handle($applier, smkRecorder($calls));

    $rows = $calls->getArrayCopy();
    expect($rows[0][1])->toBe(SMK_PREFIX . 'config_apply_started')
        ->and($rows[1][1])->toBe(SMK_PREFIX . 'config_apply_section')
        ->and($rows[1][2])->toBe(['done' => 1, 'total' => 2])
        ->and($rows[2][2])->toBe(['done' => 2, 'total' => 2]);

    $task = BackgroundTask::query()->where('uuid', $run->fresh()->background_task_uuid)->firstOrFail();
    expect($task->message)->toBe(SMK_PREFIX . 'config_apply_failed')
        ->and(json_encode($task->message_params))->not->toContain('secret-value-xyz');
});

it('sends the installer task a status key while the install row keeps the English text', function (): void {
    $calls = new ArrayObject();
    $installer = (new ReflectionClass(ModuleInstaller::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(ModuleInstaller::class, 'recorder'))->setValue($installer, smkRecorder($calls));
    $step = new ReflectionMethod(ModuleInstaller::class, 'step');

    foreach ([
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_BACKING_UP, 'backing_up'],
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_DOWNLOADING, 'downloading'],
        [ModuleInstall::SOURCE_UPLOAD, ModuleInstall::STATUS_DOWNLOADING, 'uploading_read'],
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_VERIFYING, 'verifying'],
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_PLACING, 'placing'],
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_ENABLING, 'enabling'],
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_MIGRATING, 'migrating'],
        [ModuleInstall::SOURCE_HUB, ModuleInstall::STATUS_BUILDING_ADMIN, 'building_admin'],
    ] as [$source, $status, $suffix]) {
        $task = (new BackgroundTaskRecorder())->start(ModuleInstall::TASK_TYPE, 'Core', null);
        $install = ModuleInstall::query()->create([
            'name' => 'Demo',
            'version' => '1.0.0',
            'source' => $source,
            'status' => ModuleInstall::STATUS_QUEUED,
            'background_task_uuid' => $task->uuid,
        ]);

        $step->invoke($installer, $install, $task, $status, 10, 'English text for the install row.');

        expect($task->refresh()->message)->toBe(SMK_PREFIX . 'module_install.' . $suffix)
            ->and($install->refresh()->step_message)->toBe('English text for the install row.');
    }
});

it('defines every referenced step key in the admin-authored translations with English and Vietnamese text', function (): void {
    $core = dirname(__DIR__, 3);
    $sources = [
        $core . '/app/Jobs/ExportConfigPackageJob.php',
        $core . '/app/Jobs/DryRunConfigPackageJob.php',
        $core . '/app/Jobs/ApplyConfigPackageJob.php',
        $core . '/app/Services/Modules/Install/ModuleInstaller.php',
    ];
    $keys = [];
    foreach ($sources as $file) {
        preg_match_all("/text\.background_tasks\.step\.[a-z_.]*[a-z_](?=')/", (string) file_get_contents($file), $m);
        $keys = array_merge($keys, $m[0]);
    }
    // The installer builds status keys dynamically: add every status it can use.
    foreach (['backing_up', 'downloading', 'uploading_read', 'verifying', 'placing', 'enabling', 'migrating', 'building_admin'] as $suffix) {
        $keys[] = SMK_PREFIX . 'module_install.' . $suffix;
    }
    $keys = array_values(array_unique($keys));
    expect(count($keys))->toBeGreaterThan(10);

    $seeder = (string) file_get_contents($core . '/database/seeders/sql/translate-admin-authored.php');
    foreach ($keys as $key) {
        $found = preg_match("/\\\$entry\(null, 'text', '" . preg_quote($key, '/') . "', '((?:[^'\\\\]|\\\\.)+)', '((?:[^'\\\\]|\\\\.)+)'\)/u", $seeder, $row);
        expect($found)->toBe(1, "missing translation row for $key");
        expect(trim($row[1]))->not->toBe('')->and(trim($row[2]))->not->toBe('');
    }
});
