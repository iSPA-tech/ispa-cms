<?php

declare(strict_types=1);

use App\Constants\CacheConstants;
use Cms\Core\Models\Language;
use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    if (!Schema::hasTable('settings')) {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('type', 20)->default('text');
            $table->string('group_name', 50)->nullable();
            $table->string('name', 50)->nullable();
            $table->longText('value')->nullable();
            $table->unsignedBigInteger('enumerate_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->text('input_setting')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } else {
        DB::table('settings')->truncate();
    }

    if (!Schema::hasTable('languages')) {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('currency')->nullable();
            $table->string('path')->unique();
            $table->string('path_alias')->nullable();
            $table->string('code')->nullable();
            $table->string('flag')->nullable();
            $table->string('time_zone')->nullable();
            $table->boolean('default_web')->default(false);
            $table->boolean('default_adm')->default(false);
            $table->boolean('default_app')->default(false);
            $table->boolean('for_web')->default(false);
            $table->boolean('for_adm')->default(false);
            $table->boolean('for_app')->default(false);
            $table->string('status')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    } else {
        DB::table('languages')->truncate();
    }
});

function cpSetting(string $key, mixed $value, array $overrides = []): Setting
{
    return Setting::create(array_merge([
        'setting_key' => $key,
        'type' => 'text',
        'group_name' => 'general',
        'name' => strtoupper($key),
        'value' => $value,
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ], $overrides));
}

function cpRaw(string $key): ?string
{
    return DB::table('settings')->where('setting_key', $key)->value('value');
}

function cpSection(): SettingsSection
{
    return new class extends SettingsSection {
        public int $cacheClears = 0;

        protected function clearSettingsCache(): void
        {
            ++$this->cacheClears;
        }
    };
}

it('skips secret, env, non-public and media rows on export and lists secret keys', function (): void {
    cpSetting('general.title', 'Hello');
    cpSetting('general.smtp_pass', 'pw', ['type' => 'password', 'input_setting' => ['isEnv' => false, 'isPublic' => false]]);
    cpSetting('general.env_value', 'e', ['input_setting' => ['isEnv' => true, 'isPublic' => true]]);
    cpSetting('general.private', 'p', ['input_setting' => ['isEnv' => false, 'isPublic' => false]]);
    cpSetting('general.logo', '12', ['type' => 'image']);
    cpSetting('other.title', 'Other');

    $export = (new SettingsSection())->export(new ExportContext(['general.*']));

    expect(array_column($export->rows, 'key'))->toBe(['general.title'])
        ->and($export->skipped)->toBe(['env' => 1, 'media' => 1, 'non_public' => 1, 'secret' => 1])
        ->and($export->requiredSecrets)->toEqualCanonicalizing(['general.smtp_pass', 'general.env_value', 'general.private'])
        ->and(json_encode($export->toArray()))->not->toContain('"pw"')
        ->and($export->rows[0])->not->toHaveKey('id');
});

it('exports a language-specific row with its language path', function (): void {
    Event::fake();
    $vi = Language::forceCreate(['name' => 'Vietnamese', 'path' => 'vi', 'code' => 'vi']);
    cpSetting('general.title_vi', 'Xin chao', ['language_id' => $vi->id]);

    $export = (new SettingsSection())->export(new ExportContext(['general.*']));

    expect($export->rows[0]['language'])->toBe('vi')
        ->and($export->rows[0]['value'])->toBe('Xin chao');
});

it('diffs missing rows as blocked, changed as update and equal as unchanged', function (): void {
    cpSetting('general.a', 'one');
    cpSetting('general.b', 'two');

    $diff = (new SettingsSection())->diff([
        ['key' => 'general.a', 'language' => null, 'value' => 'ONE'],
        ['key' => 'general.b', 'language' => null, 'value' => 'two'],
        ['key' => 'general.gone', 'language' => null, 'value' => 'x'],
    ], new DiffContext());

    expect($diff->item('general.a'))->toMatchArray(['action' => 'update', 'before' => 'one', 'after' => 'ONE'])
        ->and($diff->item('general.b')['action'])->toBe('unchanged')
        ->and($diff->item('general.gone'))->toMatchArray([
            'action' => 'blocked',
            'reason' => 'row does not exist on this site; enable the module or run seeders',
        ])
        ->and($diff->counts())->toMatchArray(['update' => 1, 'unchanged' => 1, 'blocked' => 1, 'create' => 0]);
});

it('applies updates through Eloquent and never touches forged private rows', function (): void {
    cpSetting('general.a', 'one');
    cpSetting('general.skip', 'keep');
    cpSetting('general.private', 'secret-value', ['input_setting' => ['isEnv' => false, 'isPublic' => false]]);
    cpSetting('general.logo', '7', ['type' => 'image']);
    $privateBefore = cpRaw('general.private');

    $rows = [
        ['key' => 'general.a', 'language' => null, 'value' => 'two'],
        ['key' => 'general.skip', 'language' => null, 'value' => 'changed'],
        ['key' => 'general.private', 'language' => null, 'value' => 'forged'],
        ['key' => 'general.logo', 'language' => null, 'value' => '999'],
    ];

    $result = (new SettingsSection())->apply($rows, new ApplyContext(['general.skip' => false]));

    expect($result->toArray())->toMatchArray(['updated' => 1, 'unchanged' => 0, 'skipped' => 2, 'blocked' => 1])
        ->and(Setting::where('setting_key', 'general.a')->first()->value)->toBe('two')
        ->and(Setting::where('setting_key', 'general.skip')->first()->value)->toBe('keep')
        ->and(cpRaw('general.private'))->toBe($privateBefore)
        ->and(cpRaw('general.logo'))->toBe('7')
        ->and(json_encode($result->toArray()))->not->toContain('forged');
});

it('syncs settings after apply without exporting the database', function (): void {
    Artisan::spy();
    $runtime = Mockery::mock(CMSSettingRuntime::class);
    $runtime->shouldReceive('forget')->once();
    app()->instance(CMSSettingRuntime::class, $runtime);

    $section = cpSection();
    $section->afterApply(new ApplyContext());

    Artisan::shouldHaveReceived('call')->once()->with('cms:setting-initialize --updateEnv=false');
    Artisan::shouldNotHaveReceived('call', ['cms:export-db settings']);
    expect($section->cacheClears)->toBe(1)
        ->and(CacheConstants::CACHE_SETTINGS)->toBe('cache:static:settings');
});

it('round-trips an export after the database drifted', function (): void {
    cpSetting('general.a', 'A1');
    cpSetting('general.b', 'B1');
    cpSetting('other.c', 'C1');

    $section = new SettingsSection();
    $export = $section->export(new ExportContext(['general.*']));

    Setting::where('setting_key', 'general.a')->first()->fill(['value' => 'A2'])->save();
    Setting::where('setting_key', 'other.c')->first()->fill(['value' => 'C2'])->save();

    $result = $section->apply($export->rows, new ApplyContext());

    expect($result->updated)->toBe(1)
        ->and($result->unchanged)->toBe(1)
        ->and(Setting::where('setting_key', 'general.a')->first()->value)->toBe('A1')
        ->and(Setting::where('setting_key', 'general.b')->first()->value)->toBe('B1')
        ->and(Setting::where('setting_key', 'other.c')->first()->value)->toBe('C2');
});

it('carries the human setting name as a label on diff and apply items, null when the row is missing', function (): void {
    cpSetting('general.a', 'one', ['name' => 'Site name']);
    cpSetting('general.nameless', 'x', ['name' => null]);

    $rows = [
        ['key' => 'general.a', 'language' => null, 'value' => 'two'],
        ['key' => 'general.nameless', 'language' => null, 'value' => 'y'],
        ['key' => 'general.gone', 'language' => null, 'value' => 'x'],
    ];

    $diff = (new SettingsSection())->diff($rows, new DiffContext());

    expect($diff->item('general.a')['label'])->toBe('Site name')
        ->and($diff->item('general.nameless')['label'])->toBeNull()
        ->and($diff->item('general.gone')['label'])->toBeNull();

    $items = collect((new SettingsSection())->apply($rows, new ApplyContext([]))->items)->keyBy('key');

    expect($items['general.a']['label'])->toBe('Site name')
        ->and($items['general.gone']['label'])->toBeNull();
});

it('reads an unchanged setting as unchanged when the model hands back a number and the package a string', function (): void {
    cpSetting('general.flag', '0', ['type' => 'number']);
    cpSetting('general.on', '1', ['type' => 'boolean']);
    cpSetting('general.off', '0', ['type' => 'switch']);

    $diff = (new SettingsSection())->diff([
        ['key' => 'general.flag', 'language' => null, 'value' => '0'],
        ['key' => 'general.on', 'language' => null, 'value' => '1'],
        ['key' => 'general.off', 'language' => null, 'value' => '0'],
    ], new DiffContext());

    expect($diff->counts()['update'])->toBe(0)->and($diff->counts()['unchanged'])->toBe(3);

    $changed = (new SettingsSection())->diff([['key' => 'general.flag', 'language' => null, 'value' => '1']], new DiffContext());

    expect($changed->counts()['update'])->toBe(1);
});
