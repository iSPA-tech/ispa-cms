<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Console\Commands\ConfigImportCommand;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Translate;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplyRefused;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Test adapter that records apply/afterApply calls into a shared log and delegates apply to a real adapter.
 *
 * afterApply is never delegated (the real ones call Artisan and Redis); it only records the call.
 */
final class CpaRecordingSection implements SectionAdapter
{
    /**
     * @param SectionAdapter $inner      Real adapter doing the work.
     * @param ArrayObject    $log        Shared call log.
     * @param bool           $failAfter  Throw after the inner apply wrote (proves the section rollback).
     */
    public function __construct(
        private readonly SectionAdapter $inner,
        private readonly ArrayObject $log,
        private readonly bool $failAfter = false,
    ) {
    }

    /** @inheritDoc */
    public function key(): string
    {
        return $this->inner->key();
    }

    /** @inheritDoc */
    public function export(ExportContext $c): SectionExport
    {
        return $this->inner->export($c);
    }

    /** @inheritDoc */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        return $this->inner->diff($rows, $c);
    }

    /** @inheritDoc */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $this->log[] = 'apply:' . $this->key();
        $result = $this->inner->apply($rows, $c);

        if ($this->failAfter) {
            throw new RuntimeException('Planted-Exception-Value');
        }

        return $result;
    }

    /** @inheritDoc */
    public function afterApply(ApplyContext $c): void
    {
        $this->log[] = 'after:' . $this->key();
    }
}

beforeEach(function (): void {
    $this->log = new ArrayObject();
    // The first user is the Webmaster; it also holds a level-0 role so the check never depends on id 1.
    $this->webmaster = cpaUser(0);
});

/**
 * Create a user holding one role of the given level and return its id.
 */
function cpaUser(int $level): int
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Importer ' . $level,
        'username' => 'importer-' . Str::lower(Str::random(8)),
        'email' => Str::lower(Str::random(10)) . '@example.com',
        'email_verified_at' => now(),
        'password' => 'x',
        'type' => 0,
        'level' => $level,
        'status' => null,
    ]);
    $role = Role::create(['name' => ($level === 0 ? 'Webmaster' : 'Level ' . $level) . ' ' . $user->id, 'custom' => ['level' => $level]]);
    DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $user->id]);

    return (int) $user->id;
}

/**
 * Valid package around the given sections.
 *
 * @param array<string, mixed> $sections
 *
 * @return array<string, mixed>
 */
function cpaPackage(array $sections): array
{
    return [
        'format' => PackageFormat::FORMAT,
        'format_version' => PackageFormat::VERSION,
        'created_at' => '2026-11-07T10:00:00+00:00',
        'source' => ['app_name' => 'Source'],
        'sections' => $sections,
        'required_secrets' => [],
        'skipped' => [],
        'checksum' => PackageFormat::checksum($sections),
    ];
}

function cpaSetting(string $key, mixed $value): Setting
{
    return Setting::create([
        'setting_key' => $key,
        'type' => 'text',
        'group_name' => 'general',
        'name' => strtoupper($key),
        'value' => $value,
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);
}

function cpaSettingValue(string $key): mixed
{
    return Setting::query()->where('setting_key', $key)->firstOrFail()->value;
}

function cpaTranslate(string $name, array $text): Translate
{
    return Translate::create([
        'name' => $name,
        'group_name' => 'text',
        'modules' => ['Core'],
        'text' => $text,
        'for_web' => true,
        'status' => 'active',
        'is_used' => true,
        'used_in' => [],
    ]);
}

/**
 * Package translation row carrying the same fields cpaTranslate() stores.
 *
 * @return array<string, mixed>
 */
function cpaRow(string $name, array $text): array
{
    return ['name' => $name, 'group_name' => 'text', 'modules' => ['Core'], 'text' => $text, 'for_web' => true, 'for_adm' => false, 'for_app' => false, 'status' => 'active'];
}

/**
 * Row counts of every configuration table an apply could write.
 *
 * @return array<string, int>
 */
function cpaCounts(): array
{
    $counts = [];
    foreach (['settings', 'translates', 'roles', 'permissions', 'permission_role', 'menus', 'menu_items', 'workflow_projects', 'workflow_steps'] as $table) {
        if (Schema::hasTable($table)) {
            $counts[$table] = DB::table($table)->count();
        }
    }

    return $counts;
}

/**
 * Backup gate mock that records the call and returns an unsaved backup (or throws when $fail is set).
 */
function cpaGate(ArrayObject $log, bool $fail = false): BackupGate
{
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturnUsing(function (string $reason) use ($log, $fail): Backup {
        $log[] = 'backup:' . $reason;

        if ($fail) {
            throw new BackupRequiredException('BACKUP_ALL_DESTINATIONS_FAILED');
        }

        return (new Backup())->forceFill(['uuid' => 'backup-uuid-1']);
    });

    return $gate;
}

/**
 * Default adapters of these tests: settings, translations and roles wrapped in recorders.
 *
 * @return list<SectionAdapter>
 */
function cpaAdapters(ArrayObject $log, array $failing = []): array
{
    return [
        new CpaRecordingSection(new SettingsSection(), $log, in_array('settings', $failing, true)),
        new CpaRecordingSection(new TranslationsSection('all'), $log, in_array('translations', $failing, true)),
        new CpaRecordingSection(new RolesSection(), $log, in_array('roles', $failing, true)),
    ];
}

/**
 * Build the applier, bind it in the container (the job resolves it) and return it.
 *
 * @param list<SectionAdapter> $adapters
 */
function cpaApplier(BackupGate $gate, array $adapters): ConfigPackageApplier
{
    $applier = new ConfigPackageApplier($gate, $adapters, new ConfigPackageDiffer($adapters));
    app()->instance(ConfigPackageApplier::class, $applier);

    return $applier;
}

/**
 * Standard fixture: one setting update and one new translation key.
 *
 * @return array<string, mixed>
 */
function cpaStandardPackage(): array
{
    cpaSetting('general.title', 'Old');
    cpaTranslate('text.hello', ['vi' => 'Xin chao']);

    return cpaPackage([
        'settings' => [['key' => 'general.title', 'language' => null, 'value' => 'New']],
        'translations' => [
            cpaRow('text.hello', ['vi' => 'Xin chao']),
            cpaRow('text.added', ['vi' => 'Moi']),
        ],
    ]);
}

function cpaDryRun(array $package, array $adapters, int $userId): ConfigPackageRun
{
    $run = (new ConfigPackageDiffer($adapters))->dryRun($package, $userId);
    expect($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED);

    return $run;
}

it('refuses a dry run older than one hour without writing', function (): void {
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);
    $dryRun->forceFill(['created_at' => now()->subMinutes(61)])->save();
    $before = cpaCounts();

    expect(fn () => cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster))
        ->toThrow(ConfigPackageApplyRefused::class, 'older than 1 hour');

    expect(cpaCounts())->toBe($before)
        ->and($this->log->getArrayCopy())->toBe([])
        ->and(ConfigPackageRun::query()->where('kind', 'apply')->count())->toBe(0)
        ->and(cpaSettingValue('general.title'))->toBe('Old');
});

it('refuses a run that is not a successful dry run', function (): void {
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);
    $applier = cpaApplier(cpaGate($this->log), $adapters);

    $dryRun->forceFill(['status' => ConfigPackageRun::STATUS_FAILED])->save();
    expect(fn () => $applier->apply($dryRun, [], $this->webmaster))->toThrow(ConfigPackageApplyRefused::class, 'successful dry run');

    $dryRun->forceFill(['status' => ConfigPackageRun::STATUS_SUCCEEDED, 'kind' => ConfigPackageRun::KIND_EXPORT])->save();
    expect(fn () => $applier->apply($dryRun, [], $this->webmaster))->toThrow(ConfigPackageApplyRefused::class, 'successful dry run');

    expect($this->log->getArrayCopy())->toBe([]);
});

it('refuses when the live database drifted since the dry run', function (): void {
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);
    Setting::query()->where('setting_key', 'general.title')->firstOrFail()->update(['value' => 'Changed here']);
    $before = cpaCounts();

    expect(fn () => cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster))
        ->toThrow(ConfigPackageApplyRefused::class, 'run the dry run again');

    expect(cpaCounts())->toBe($before)
        ->and($this->log->getArrayCopy())->toBe([])
        ->and(cpaSettingValue('general.title'))->toBe('Changed here')
        // The fingerprint probe leaves no extra run behind.
        ->and(ConfigPackageRun::query()->count())->toBe(1);
});

it('refuses a package checksum that differs from the run checksum', function (): void {
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);
    $dryRun->forceFill(['package_checksum' => 'sha256:' . str_repeat('0', 64)])->save();

    expect(fn () => cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster))
        ->toThrow(ConfigPackageApplyRefused::class, 'checksum');

    expect($this->log->getArrayCopy())->toBe([])
        ->and(cpaSettingValue('general.title'))->toBe('Old');
});

it('refuses a user who is not a Webmaster', function (): void {
    $editor = cpaUser(5);
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);

    expect(fn () => cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $editor))
        ->toThrow(ConfigPackageApplyRefused::class, 'Webmaster');

    expect($this->log->getArrayCopy())->toBe([])
        ->and(cpaSettingValue('general.title'))->toBe('Old');
});

it('takes the backup before any write and aborts with zero writes when it fails', function (): void {
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);
    $before = cpaCounts();
    $settingsBefore = DB::table('settings')->orderBy('id')->get();

    $run = cpaApplier(cpaGate($this->log, fail: true), $adapters)->apply($dryRun, [], $this->webmaster);

    expect($run->kind)->toBe(ConfigPackageRun::KIND_APPLY)
        ->and($run->status)->toBe(ConfigPackageRun::STATUS_FAILED)
        ->and($run->parent_run_id)->toBe((int) $dryRun->id)
        ->and($run->backup_ref)->toBeNull()
        ->and((string) $run->summary['error'])->toContain('BACKUP_ALL_DESTINATIONS_FAILED')
        ->and($this->log->getArrayCopy())->toBe(['backup:' . Backup::REASON_PRE_IMPORT])
        ->and(cpaCounts())->toBe($before)
        ->and(DB::table('settings')->orderBy('id')->get())->toEqual($settingsBefore)
        ->and(BackgroundTask::query()->where('uuid', $run->background_task_uuid)->value('status'))->toBe('failed');
});

it('applies each section in its own transaction and keeps going after a failing section', function (): void {
    $adapters = cpaAdapters($this->log, failing: ['settings']);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);

    $run = cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster);

    expect($run->status)->toBe(ConfigPackageRun::STATUS_FAILED)
        ->and($run->backup_ref)->toBe('backup-uuid-1')
        ->and($run->summary['result'])->toBe(['succeeded' => ['translations'], 'failed' => ['settings'], 'skipped' => []])
        // The settings write happened inside the failed section and was rolled back.
        ->and(cpaSettingValue('general.title'))->toBe('Old')
        ->and(Translate::query()->where('name', 'text.added')->exists())->toBeTrue()
        ->and(json_encode($run->summary))->not->toContain('Planted-Exception-Value');

    $task = BackgroundTask::query()->where('uuid', $run->background_task_uuid)->firstOrFail();
    expect($task->status)->toBe('failed')
        ->and((string) $task->message)->toBe('text.background_tasks.step.config_apply_failed')
        ->and((string) $task->message)->not->toContain('Planted-Exception-Value')
        ->and(json_encode($task->message_params))->not->toContain('Planted-Exception-Value')
        ->and($task->result['succeeded'])->toBe(['translations'])
        ->and($task->result['failed'])->toBe(['settings']);
});

it('applies only choices offered by the dry run and ticked by the operator', function (): void {
    foreach (['post.view', 'post.update'] as $key) {
        [$group, $name] = explode('.', $key);
        Permission::create(['group' => $group, 'name' => $name, 'title' => $key]);
    }
    $editor = Role::create(['name' => 'Editor', 'custom' => ['level' => 5]]);
    $editor->permissions()->attach(Permission::query()->where('name', 'view')->pluck('id')->all());
    cpaSetting('general.title', 'Old');

    $package = cpaPackage([
        'settings' => [['key' => 'general.title', 'language' => null, 'value' => 'New']],
        'roles' => [['name' => 'Editor', 'custom' => ['level' => 5], 'permissions' => ['post.update', 'post.view']]],
    ]);
    $adapters = cpaAdapters($this->log);
    $editorKeys = fn (): array => $editor->fresh()->permissions->map(fn (Permission $p): string => $p->key)->sort()->values()->all();

    // Not ticked: the role keeps its permissions; a choice for an item the dry run did not offer is ignored.
    $dryRun = cpaDryRun($package, $adapters, $this->webmaster);
    expect($dryRun->plan['choices'])->toHaveCount(1);

    $run = cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [
        'settings' => ['general.title' => false],
        'bogus' => ['anything' => true],
    ], $this->webmaster);

    expect($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED)
        ->and($editorKeys())->toBe(['post.view'])
        ->and(cpaSettingValue('general.title'))->toBe('New')
        ->and($run->plan['choices'])->toBe(['roles' => ['Editor' => false]])
        ->and($run->summary['sections']['roles']['skipped'])->toBe(1);

    // Ticked: the role gains the missing permission (never loses one).
    $dryRun = cpaDryRun($package, $adapters, $this->webmaster);
    $run = cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, ['roles' => ['Editor' => true]], $this->webmaster);

    expect($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED)
        ->and($editorKeys())->toBe(['post.update', 'post.view']);
});

it('writes one audit event per section with counts and keys only plus a summary event', function (): void {
    Event::fake([AuditLogEvent::class]);
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);

    $run = cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster);
    expect($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED);

    $actions = [];
    Event::assertDispatched(AuditLogEvent::class, function (AuditLogEvent $event) use (&$actions): bool {
        $actions[] = $event->action;
        $json = json_encode($event->changed);

        expect($json)->not->toContain('"New"')->and($json)->not->toContain('Moi')->and($json)->not->toContain('Xin chao');

        return true;
    });

    expect($actions)->toBe(['config.package.apply.settings', 'config.package.apply.translations', 'config.package.apply']);

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.package.apply.settings'
        && $e->changed['counts']['updated'] === 1
        && $e->changed['items']['updated'] === ['general.title']
        && $e->type === ConfigPackageRun::class
        && $e->id === (int) $run->id);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.package.apply.translations'
        && $e->changed['items']['updated'] === ['text.added']
        && $e->changed['counts']['unchanged'] === 1);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'config.package.apply'
        && $e->changed['result']['succeeded'] === ['settings', 'translations']
        && $e->changed['backup_ref'] === 'backup-uuid-1');
});

it('runs afterApply once per touched section after all sections', function (): void {
    cpaSetting('general.title', 'Old');
    cpaTranslate('text.hello', ['vi' => 'Xin chao']);
    $package = cpaPackage([
        'settings' => [['key' => 'general.title', 'language' => null, 'value' => 'New']],
        'translations' => [cpaRow('text.hello', ['vi' => 'Xin chao'])],
    ]);
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun($package, $adapters, $this->webmaster);

    cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster);

    expect($this->log->getArrayCopy())->toBe([
        'backup:' . Backup::REASON_PRE_IMPORT,
        'apply:settings',
        'apply:translations',
        'after:settings',
    ]);
});

it('shows progress per section on the background task as keys with counts only', function (): void {
    $messages = new ArrayObject();
    app()->instance(BackgroundTaskRecorder::class, new class ($messages) extends BackgroundTaskRecorder {
        public function __construct(private readonly ArrayObject $messages)
        {
        }

        public function progress(BackgroundTask $task, int $percent, ?string $message = null, array $params = []): BackgroundTask
        {
            $this->messages[] = [$percent, $message];

            return parent::progress($task, $percent, $message, $params);
        }
    });
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);

    $run = cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [], $this->webmaster);
    $texts = array_column($messages->getArrayCopy(), 1);

    expect($texts)->toContain('text.background_tasks.step.config_apply_started')
        ->and($texts)->toContain('text.background_tasks.step.config_apply_section')
        ->and(json_encode($texts))->not->toContain('settings')
        ->and(json_encode($texts))->not->toContain('translations')
        ->and(json_encode($texts))->not->toContain('New')
        ->and(BackgroundTask::query()->where('uuid', $run->background_task_uuid)->value('status'))->toBe('succeeded')
        ->and(BackgroundTask::query()->where('uuid', $run->background_task_uuid)->value('type'))->toBe('config.package.apply');
});

it('refuses cms:config-import --apply without a matching successful dry run', function (): void {
    Artisan::registerCommand(app(ConfigImportCommand::class));
    $adapters = cpaAdapters($this->log);
    $package = cpaStandardPackage();
    $dryRun = cpaDryRun($package, $adapters, $this->webmaster);
    cpaApplier(cpaGate($this->log), $adapters);

    $other = cpaPackage(['settings' => [['key' => 'general.title', 'language' => null, 'value' => 'Other']]]);
    $file = tempnam(sys_get_temp_dir(), 'cfgpkg');
    file_put_contents($file, json_encode($other));

    try {
        // The file does not hold the dry run's package.
        $code = Artisan::call('cms:config-import', ['file' => $file, '--apply' => true, '--run' => $dryRun->uuid, '--user' => $this->webmaster]);
        expect($code)->not->toBe(0)->and(Artisan::output())->toContain('checksum');

        // No run given, and an unknown run.
        expect(Artisan::call('cms:config-import', ['--apply' => true, '--user' => $this->webmaster]))->not->toBe(0);
        expect(Artisan::call('cms:config-import', ['--apply' => true, '--run' => (string) Str::uuid(), '--user' => $this->webmaster]))->not->toBe(0);

        // A failed dry run is refused by the applier.
        $dryRun->forceFill(['status' => ConfigPackageRun::STATUS_FAILED])->save();
        expect(Artisan::call('cms:config-import', ['--apply' => true, '--run' => $dryRun->uuid, '--user' => $this->webmaster]))->not->toBe(0)
            ->and(Artisan::output())->toContain('successful dry run');

        expect($this->log->getArrayCopy())->toBe([])
            ->and(cpaSettingValue('general.title'))->toBe('Old')
            ->and(ConfigPackageRun::query()->where('kind', 'apply')->count())->toBe(0);

        // A matching file and a successful dry run apply.
        $dryRun->forceFill(['status' => ConfigPackageRun::STATUS_SUCCEEDED])->save();
        file_put_contents($file, json_encode($package));
        $code = Artisan::call('cms:config-import', ['file' => $file, '--apply' => true, '--run' => $dryRun->uuid, '--user' => $this->webmaster]);

        expect($code)->toBe(0)
            ->and(Artisan::output())->toContain('settings: succeeded')
            ->and(cpaSettingValue('general.title'))->toBe('New');
    } finally {
        @unlink($file);
    }
});

it('skips a whole section when the operator sends the section-level skip choice', function (): void {
    $adapters = cpaAdapters($this->log);
    $dryRun = cpaDryRun(cpaStandardPackage(), $adapters, $this->webmaster);

    $run = cpaApplier(cpaGate($this->log), $adapters)->apply($dryRun, [
        'settings' => ['*' => false, 'general.title' => false],
        'bogus' => ['*' => false],
    ], $this->webmaster);

    expect($run->plan['choices'])->toBe(['settings' => ['*' => false]])
        ->and($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED)
        ->and($run->summary['sections']['settings'])->toBe(['status' => 'skipped'])
        ->and($run->summary['result']['skipped'])->toBe(['settings'])
        ->and(cpaSettingValue('general.title'))->toBe('Old')
        ->and(Translate::query()->where('name', 'text.added')->exists())->toBeTrue();
});
