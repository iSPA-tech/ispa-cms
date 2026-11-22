<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Translate;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Roadmap exit case: export from state A, reset to state B, dry run, apply, and the carried keys equal A again.
 */
beforeEach(function (): void {
    // Helpdesk declares `helpdesk.*` in its manifest; pin it so the test does not depend on the enabled modules.
    app()->instance(ConfigExportRegistry::class, new ConfigExportRegistry(
        static fn (): array => ['Helpdesk' => ['settings' => ['helpdesk.*'], 'tables' => []]],
    ));
    // The real settings adapter, without the Redis cache drop (seam for tests).
    app()->bind(SettingsSection::class, static fn () => new class extends SettingsSection {
        protected function clearSettingsCache(): void
        {
        }
    });
});

function rtWebmaster(): int
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Webmaster',
        'username' => 'wm-' . Str::lower(Str::random(8)),
        'email' => Str::lower(Str::random(10)) . '@example.com',
        'email_verified_at' => now(),
        'password' => 'x',
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
    $role = Role::create(['name' => 'Webmaster', 'custom' => ['level' => 0]]);
    DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $user->id]);

    return (int) $user->id;
}

function rtSetting(string $key, string $value): void
{
    Setting::create([
        'setting_key' => $key,
        'type' => 'text',
        'group_name' => explode('.', $key)[0],
        'name' => strtoupper($key),
        'value' => $value,
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);
}

function rtTranslate(string $name, string $text, bool $web = true): void
{
    Translate::create([
        'name' => $name,
        'group_name' => 'text',
        'modules' => ['Helpdesk'],
        'text' => ['vi' => $text, 'en' => $text . ' (en)'],
        'for_web' => $web,
        'status' => 'active',
        'is_used' => true,
        'used_in' => [],
    ]);
}

/**
 * All rows of a table except the given natural keys, as plain arrays ordered by id.
 *
 * @param list<string> $except
 *
 * @return list<array<string, mixed>>
 */
function rtRows(string $table, string $keyColumn, array $except): array
{
    return DB::table($table)->whereNotIn($keyColumn, $except)->orderBy('id')->get()
        ->map(static fn ($row): array => (array) $row)->all();
}

it('exports state A, re-applies it on state B and leaves every other row untouched', function (): void {
    $userId = rtWebmaster();

    // Shared rows that exist on both sides.
    rtSetting('helpdesk.portal_name', 'Help');
    rtSetting('basic.site_name', 'Site');
    rtTranslate('text.helpdesk.welcome', 'Chao mung');
    rtTranslate('text.admin_only', 'Quan tri', web: false);

    // State A: a changed helpdesk setting and a new web translation key.
    rtSetting('helpdesk.reply_signature', 'New signature');
    rtTranslate('text.helpdesk.new_key', 'Khoa moi');

    Event::fake([AuditLogEvent::class]);
    $export = app(ConfigPackageExporter::class)->export(['settings', 'translations'], ['translation_scope' => 'web'], $userId);
    expect($export->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED);
    $package = $export->payload();
    $stateA = [
        'setting' => Setting::query()->where('setting_key', 'helpdesk.reply_signature')->firstOrFail()->value,
        'translation' => Translate::query()->where('name', 'text.helpdesk.new_key')->firstOrFail()->only(['group_name', 'modules', 'text', 'for_web', 'for_adm', 'for_app', 'status']),
    ];

    // State B: the key is missing and the setting has its old value; one more local row the package does not carry.
    Translate::query()->where('name', 'text.helpdesk.new_key')->forceDelete();
    Setting::query()->where('setting_key', 'helpdesk.reply_signature')->firstOrFail()->update(['value' => 'Old signature']);
    rtSetting('helpdesk.local_only', 'Local');
    rtTranslate('text.local_only', 'Chi o day');

    $otherSettings = rtRows('settings', 'setting_key', ['helpdesk.reply_signature']);
    $otherTranslates = rtRows('translates', 'name', ['text.helpdesk.new_key']);
    $settingCount = DB::table('settings')->count();
    $translateCount = DB::table('translates')->count();

    // Dry run: exactly one create (translations) and one update (settings) for the carried keys.
    $dryRun = app(ConfigPackageDiffer::class)->dryRun($package, $userId);
    $settings = $dryRun->plan['sections']['settings'];
    $translations = $dryRun->plan['sections']['translations'];

    expect($dryRun->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED)
        ->and($settings['counts']['update'])->toBe(1)
        ->and($settings['counts']['create'])->toBe(0)
        ->and(collect($settings['items'])->where('action', 'update')->pluck('key')->all())->toBe(['helpdesk.reply_signature'])
        ->and($translations['counts']['create'])->toBe(1)
        ->and($translations['counts']['update'])->toBe(0)
        ->and(collect($translations['items'])->where('action', 'create')->pluck('key')->all())->toBe(['text.helpdesk.new_key']);

    // Apply with a backup gate that succeeds; Artisan syncs that would write repository files are faked.
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->once()->andReturn((new Backup())->forceFill(['uuid' => 'rt-backup']));
    app()->instance(BackupGate::class, $gate);
    app()->forgetInstance(ConfigPackageApplier::class);
    Artisan::spy();

    $run = app(ConfigPackageApplier::class)->apply($dryRun, [], $userId);

    expect($run->status)->toBe(ConfigPackageRun::STATUS_SUCCEEDED)
        ->and($run->backup_ref)->toBe('rt-backup')
        ->and($run->summary['result']['succeeded'])->toBe(['settings', 'translations'])
        ->and(Setting::query()->where('setting_key', 'helpdesk.reply_signature')->firstOrFail()->value)->toBe($stateA['setting'])
        ->and(Translate::query()->where('name', 'text.helpdesk.new_key')->firstOrFail()->only(['group_name', 'modules', 'text', 'for_web', 'for_adm', 'for_app', 'status']))->toBe($stateA['translation'])
        // Every other row is untouched and nothing was deleted.
        ->and(rtRows('settings', 'setting_key', ['helpdesk.reply_signature']))->toEqual($otherSettings)
        ->and(rtRows('translates', 'name', ['text.helpdesk.new_key']))->toEqual($otherTranslates)
        ->and(DB::table('settings')->count())->toBe($settingCount)
        ->and(DB::table('translates')->count())->toBe($translateCount + 1)
        ->and(Translate::onlyTrashed()->count())->toBe(0);

    Artisan::shouldHaveReceived('call')->with('cms:translation-generate');
    Artisan::shouldHaveReceived('call')->with('cms:setting-initialize --updateEnv=false');
    Artisan::shouldNotHaveReceived('call', ['cms:translation-sync']);
});
