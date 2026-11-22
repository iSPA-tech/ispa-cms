<?php

declare(strict_types=1);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Setting;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use App\Models\User;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Create a setting row for export tests.
 */
function exportSetting(string $key, mixed $value, array $input = ['isEnv' => false, 'isPublic' => true], string $type = 'text'): void
{
    Setting::create([
        'setting_key' => $key,
        'type' => $type,
        'group_name' => 'basic',
        'name' => strtoupper($key),
        'value' => $value,
        'input_setting' => $input,
    ]);
}

/**
 * Create a user to attribute runs and audit rows to.
 */
function exportUser(): int
{
    return (int) User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Exporter',
        'username' => 'exporter-' . Str::lower(Str::random(6)),
        'email' => Str::lower(Str::random(8)) . '@example.com',
        'email_verified_at' => now(),
        'password' => 'x',
        'type' => 0,
        'level' => 0,
        'status' => null,
    ])->id;
}

/**
 * Exporter whose settings section always fails with a message that contains a "value".
 */
function failingExporter(): ConfigPackageExporter
{
    return new class extends ConfigPackageExporter {
        protected function adapter(string $section, array $options): SectionAdapter
        {
            return new class extends SettingsSection {
                public function export(ExportContext $c): SectionExport
                {
                    throw new RuntimeException('leaked-value-123');
                }
            };
        }
    };
}

it('exports a valid package with source, checksum and required secrets', function (): void {
    exportSetting('basic.title', 'Hello');
    exportSetting('basic.smtp_password', 'hunter2', ['isEnv' => false, 'isPublic' => true], 'password');

    $run = app(ConfigPackageExporter::class)->export(['settings', 'roles'], [], exportUser());
    $package = $run->payload();

    expect($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED)
        ->and($run->package_checksum)->toBe($package['checksum'])
        ->and($package['checksum'])->toBe(PackageFormat::checksum($package['sections']))
        ->and((new PackageValidator())->validate($package)->isValid())->toBeTrue()
        ->and(array_keys($package['sections']))->toBe(['settings', 'roles'])
        ->and(json_encode($package))->not->toContain('hunter2')
        ->and($run->background_task_uuid)->not->toBeNull()
        ->and(BackgroundTask::query()->where('uuid', $run->background_task_uuid)->value('status'))->toBe('succeeded');

    (new SecretGuard())->assertNoSecrets($package['sections']);

    $enabled = [];
    foreach (Module::allEnabled() as $module) {
        $enabled[$module->getName()] = (string) $module->get('version', '0.0.0');
    }
    expect($package['source']['modules'])->toBe($enabled)
        ->and(array_keys($package['source']))->toContain('core_version', 'host', 'app_name');
    expect($package['required_secrets'])->toBe($run->summary['required_secrets']['keys']);
});

it('lists skipped secret setting keys as required secrets', function (): void {
    exportSetting('email.smtp_password', 'hunter2', ['isEnv' => false, 'isPublic' => true], 'password');
    exportSetting('basic.title', 'Hello');

    $package = app(ConfigPackageExporter::class)->export(['settings'], [], exportUser())->payload();

    expect($package['required_secrets'])->toContain('email.smtp_password')
        ->and($package['skipped']['settings'])->not->toBeEmpty();
});

it('rejects unknown sections with a clear message and creates no run', function (): void {
    expect(fn () => app(ConfigPackageExporter::class)->export(['settings', 'bogus'], [], exportUser()))
        ->toThrow(InvalidArgumentException::class, 'bogus');

    expect(ConfigPackageRun::query()->count())->toBe(0);
});

it('marks the run and task failed without a payload or leaked values when a section fails', function (): void {
    // The job resolves the exporter from the container, so bind the failing one.
    app()->instance(ConfigPackageExporter::class, failingExporter());

    $run = app(ConfigPackageExporter::class)->export(['settings'], [], exportUser());

    expect($run->status)->toBe(ConfigPackageRun::STATUS_FAILED)
        ->and($run->hasPayload())->toBeFalse()
        ->and(json_encode($run->summary))->not->toContain('leaked-value-123')
        ->and(BackgroundTask::query()->where('uuid', $run->background_task_uuid)->first())
        ->status->toBe('failed');
    $task = BackgroundTask::query()->where('uuid', $run->background_task_uuid)->firstOrFail();
    expect((string) $task->message)->toBe('text.background_tasks.step.config_export_failed')
        ->and((string) $task->message)->not->toContain('leaked-value-123')
        ->and(json_encode($task->message_params))->not->toContain('leaked-value-123');
});

it('writes an audit event with section names and counts only', function (): void {
    exportSetting('basic.title', 'Very Secret Title Value');
    Event::fake([AuditLogEvent::class]);

    app(ConfigPackageExporter::class)->export(['settings'], [], exportUser());

    Event::assertDispatched(AuditLogEvent::class, function (AuditLogEvent $event): bool {
        return $event->action === 'config.package.export'
            && array_keys($event->changed) === ['sections']
            && array_keys($event->changed['sections']) === ['settings']
            && is_int($event->changed['sections']['settings'])
            && !str_contains(json_encode($event->changed), 'Very Secret');
    });
});

it('gives the same checksum when exporting twice', function (): void {
    exportSetting('basic.b', '2');
    exportSetting('basic.a', '1');

    $first = app(ConfigPackageExporter::class)->export(['settings', 'translations', 'roles', 'menus', 'workflows', 'tables'], [], exportUser())->payload();
    $second = app(ConfigPackageExporter::class)->export(['settings', 'translations', 'roles', 'menus', 'workflows', 'tables'], [], exportUser())->payload();

    expect($first['checksum'])->toBe($second['checksum'])
        ->and(array_column($first['sections']['settings'], 'key'))->toBe(['basic.a', 'basic.b']);
});

it('writes the package with the command --out option and prints the run uuid otherwise', function (): void {
    exportSetting('basic.title', 'Hello');
    $file = sys_get_temp_dir() . '/cms-config-export-' . uniqid() . '.json';

    expect(Artisan::call('cms:config-export', ['--sections' => 'settings', '--out' => $file, '--user' => exportUser()]))->toBe(0);
    $decoded = json_decode((string) file_get_contents($file), true);
    @unlink($file);
    expect($decoded['format'])->toBe(PackageFormat::FORMAT)
        ->and(array_keys($decoded['sections']))->toBe(['settings']);

    expect(Artisan::call('cms:config-export', ['--sections' => 'roles', '--user' => exportUser()]))->toBe(0);
    expect(trim(Artisan::output()))->toBe((string) ConfigPackageRun::query()->latest('id')->value('uuid'));
});

it('fails the command on an unknown section', function (): void {
    expect(Artisan::call('cms:config-export', ['--sections' => 'nope']))->toBe(1)
        ->and(Artisan::output())->toContain('nope');
});
