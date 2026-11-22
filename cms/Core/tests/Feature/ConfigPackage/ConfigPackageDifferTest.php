<?php

declare(strict_types=1);

use Cms\Core\Console\Commands\ConfigImportCommand;
use Cms\Core\Jobs\DryRunConfigPackageJob;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Translate;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    foreach (['settings', 'translates', 'languages', 'roles', 'permission_role', 'menus', 'menu_items', 'workflow_projects', 'core_config_package_runs', 'core_background_tasks'] as $table) {
        Schema::dropIfExists($table);
    }

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

    Schema::create('translates', function (Blueprint $table) {
        $table->id();
        $table->text('modules')->nullable();
        $table->char('group_name', 20)->nullable();
        $table->char('name', 100)->nullable()->unique();
        $table->text('text')->nullable();
        $table->boolean('for_web')->default(false);
        $table->boolean('for_adm')->default(false);
        $table->boolean('for_app')->default(false);
        $table->char('status', 50)->nullable();
        $table->boolean('is_used')->default(false);
        $table->timestamp('last_scanned_at')->nullable();
        $table->json('used_in')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    // Tables of the other sections only need to exist so their row counts can be compared.
    foreach (['roles', 'permission_role', 'menus', 'menu_items', 'workflow_projects'] as $table) {
        Schema::create($table, function (Blueprint $t) {
            $t->id();
        });
    }

    Schema::create('core_config_package_runs', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->enum('kind', ['export', 'dry_run', 'apply']);
        $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
        $table->json('sections');
        $table->string('package_checksum', 80)->nullable();
        $table->longText('payload')->nullable();
        $table->json('summary')->nullable();
        $table->json('plan')->nullable();
        $table->unsignedBigInteger('parent_run_id')->nullable();
        $table->uuid('background_task_uuid')->nullable();
        $table->string('backup_ref')->nullable();
        $table->unsignedBigInteger('created_by')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

/**
 * Differ limited to the settings and translations adapters (the only tables this test seeds with data).
 */
function cdDiffer(): ConfigPackageDiffer
{
    return new ConfigPackageDiffer([new SettingsSection(), new TranslationsSection('all')]);
}

/**
 * Build a valid package (correct checksum) around the given sections.
 *
 * @param array<string, mixed> $sections
 * @param array<string, mixed> $extra
 *
 * @return array<string, mixed>
 */
function cdPackage(array $sections, array $extra = []): array
{
    return array_merge([
        'format' => PackageFormat::FORMAT,
        'format_version' => PackageFormat::VERSION,
        'created_at' => '2026-11-07T10:00:00+00:00',
        'source' => ['app_name' => 'Source'],
        'sections' => $sections,
        'required_secrets' => [],
        'skipped' => [],
        'checksum' => PackageFormat::checksum($sections),
    ], $extra);
}

function cdSetting(string $key, mixed $value, array $overrides = []): Setting
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

function cdTranslate(string $name, array $text): Translate
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
 * Row counts of every configuration table the dry run must not touch.
 *
 * @return array<string, int>
 */
function cdCounts(): array
{
    $counts = [];
    foreach (['settings', 'translates', 'roles', 'permission_role', 'menus', 'menu_items', 'workflow_projects'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

it('writes only the run record', function (): void {
    cdSetting('general.title', 'Old');
    cdTranslate('text.hello', ['vi' => 'Xin chao']);
    $package = cdPackage([
        'settings' => [['key' => 'general.title', 'language' => null, 'value' => 'New']],
        'translations' => [['name' => 'text.hello', 'group_name' => 'text', 'text' => ['vi' => 'Chao']], ['name' => 'text.new', 'group_name' => 'text', 'text' => ['vi' => 'Moi']]],
    ]);

    $before = cdCounts();
    $beforeSetting = DB::table('settings')->first();
    $run = cdDiffer()->dryRun($package, 1);

    expect(cdCounts())->toBe($before)
        ->and(DB::table('settings')->first())->toEqual($beforeSetting)
        ->and(ConfigPackageRun::query()->count())->toBe(1)
        ->and($run->kind)->toBe('dry_run')
        ->and($run->status)->toBe('succeeded')
        ->and($run->package_checksum)->toBe($package['checksum'])
        ->and($run->payload())->toBe($package)
        ->and($run->plan['sections']['settings']['counts']['update'])->toBe(1)
        ->and($run->plan['sections']['translations']['counts']['create'])->toBe(1)
        ->and($run->plan['sections']['translations']['counts']['update'])->toBe(1)
        ->and($run->summary['sections']['settings']['total'])->toBe(1);
});

it('fails an invalid or tampered package with the validator errors and no plan', function (): void {
    $package = cdPackage(['translations' => [['name' => 'text.a', 'text' => ['vi' => 'A']]]]);
    $package['sections']['translations'][0]['text']['vi'] = 'Edited after checksum';

    $run = cdDiffer()->dryRun($package, 1);

    expect($run->status)->toBe('failed')
        ->and($run->plan)->toBeNull()
        ->and($run->hasPayload())->toBeFalse()
        ->and($run->package_checksum)->toBeNull()
        ->and($run->summary['errors'])->toContain('checksum does not match the package sections.');

    $broken = cdDiffer()->dryRun(['format' => 'nope'], 1);
    expect($broken->status)->toBe('failed')
        ->and($broken->summary['errors'])->not->toBe([]);
});

it('refuses a package with a newer format_version', function (): void {
    $package = cdPackage(['translations' => []], ['format_version' => PackageFormat::VERSION + 1]);

    $run = cdDiffer()->dryRun($package, 1);

    expect($run->status)->toBe('failed')
        ->and($run->plan)->toBeNull()
        ->and(implode(' ', $run->summary['errors']))->toContain('Unsupported format_version');
});

it('never puts secret values into the plan, summary or failure record', function (): void {
    // Secret target setting: raw value is ciphertext, the plain text must never show up.
    cdSetting('mail.smtp_pass', 'Planted-Password-1', ['type' => 'password', 'input_setting' => ['isEnv' => false, 'isPublic' => false]]);
    // Non-secret target whose stored value nests a secret-looking key and a ciphertext string.
    cdSetting('general.blob', ['label' => 'old', 'api_key' => 'Planted-Nested-2', 'note' => 'eyJpdiI6Planted-Cipher-3']);
    cdTranslate('text.x', ['vi' => 'x']);

    $package = cdPackage(
        [
            'settings' => [
                ['key' => 'general.blob', 'language' => null, 'value' => ['label' => 'new']],
            ],
        ],
        ['required_secrets' => ['mail.smtp_pass', 'mail.other_pass']],
    );

    $run = cdDiffer()->dryRun($package, 1);
    $stored = json_encode([$run->plan, $run->summary]);

    expect($run->status)->toBe('succeeded');
    foreach (['Planted-Password-1', 'Planted-Nested-2', 'Planted-Cipher-3'] as $needle) {
        expect($stored)->not->toContain($needle);
    }
    expect($run->plan['sections']['settings']['items'][0]['before'])->toMatchArray(['label' => 'old', 'api_key' => '[redacted]']);

    // A package that carries a secret is refused and its value does not leak into the failure record either.
    $bad = cdPackage(['settings' => [['key' => 'general.x', 'language' => null, 'value' => ['smtp_password' => 'Planted-Package-4']]]]);
    $failed = cdDiffer()->dryRun($bad, 1);

    expect($failed->status)->toBe('failed')
        ->and($failed->plan)->toBeNull()
        ->and(json_encode($failed->summary))->not->toContain('Planted-Package-4')
        ->and($failed->hasPayload())->toBeFalse();
});

it('fails a package with a row aimed at a secret setting and keeps no payload', function (): void {
    cdSetting('mail.smtp_pass', 'old', ['type' => 'password', 'input_setting' => ['isEnv' => false, 'isPublic' => false]]);

    $run = cdDiffer()->dryRun(cdPackage(['settings' => [['key' => 'mail.smtp_pass', 'language' => null, 'value' => 'Planted-Row-9']]]), 1);

    expect($run->status)->toBe('failed')
        ->and($run->hasPayload())->toBeFalse()
        ->and($run->plan)->toBeNull()
        ->and(json_encode($run->summary))->not->toContain('Planted-Row-9')
        ->and(json_encode($run->summary['issues']))->toContain('secret_blocked');
});

it('caps items per section at 500 and reports how many more', function (): void {
    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = ['name' => sprintf('text.k%04d', $i), 'group_name' => 'text', 'text' => ['vi' => 'v' . $i]];
    }

    $run = cdDiffer()->dryRun(cdPackage(['translations' => $rows]), 1);
    $section = $run->plan['sections']['translations'];

    expect($run->status)->toBe('succeeded')
        ->and($section['items'])->toHaveCount(500)
        ->and($section['total'])->toBe(501)
        ->and($section['more'])->toBe(1)
        ->and($section['more_text'])->toBe('and 1 more')
        ->and($section['counts']['create'])->toBe(501);
});

it('gives the same fingerprint for the same data and a different one after a target change', function (): void {
    cdSetting('general.title', 'Old');
    $package = cdPackage(['settings' => [['key' => 'general.title', 'language' => null, 'value' => 'New']]]);

    $first = cdDiffer()->dryRun($package, 1)->plan['base_fingerprint'];
    $second = cdDiffer()->dryRun($package, 1)->plan['base_fingerprint'];

    Setting::query()->where('setting_key', 'general.title')->firstOrFail()->update(['value' => 'Changed here']);
    $third = cdDiffer()->dryRun($package, 1)->plan['base_fingerprint'];

    expect($first)->toBe($second)->and($first)->toStartWith('sha256:')->and($third)->not->toBe($first);
});

it('splits required secrets into already set and missing here', function (): void {
    cdSetting('mail.smtp_pass', 'has-value', ['type' => 'password', 'input_setting' => ['isEnv' => false, 'isPublic' => false]]);
    cdSetting('mail.empty_pass', '', ['type' => 'password', 'input_setting' => ['isEnv' => false, 'isPublic' => false]]);

    $package = cdPackage(['translations' => []], ['required_secrets' => ['mail.smtp_pass', 'mail.empty_pass', 'mail.absent_pass']]);
    $run = cdDiffer()->dryRun($package, 1);

    expect($run->plan['required_secrets'])->toBe([
        'already_set_here' => ['mail.smtp_pass'],
        'missing_here' => ['mail.absent_pass', 'mail.empty_pass'],
        // Labels come from the settings table (`name`); a key with no row has none and clients fall back to the key.
        'labels' => ['mail.empty_pass' => 'MAIL.EMPTY_PASS', 'mail.smtp_pass' => 'MAIL.SMTP_PASS'],
    ])->and(json_encode($run->plan))->not->toContain('has-value');
});

it('warns about unknown sections and lists blocked items', function (): void {
    $package = cdPackage([
        'settings' => [['key' => 'general.gone', 'language' => null, 'value' => 'x']],
        'roles' => [['name' => 'Editor']],
    ]);

    $run = cdDiffer()->dryRun($package, 1);

    expect($run->plan['sections']['roles']['status'])->toBe('skipped')
        ->and($run->plan['sections']['settings']['counts']['blocked'])->toBe(1)
        ->and($run->plan['warnings'])->not->toBe([]);
});

it('fills an existing queued run through the job and records a background task', function (): void {
    Schema::dropIfExists('core_background_tasks');
    Schema::create('core_background_tasks', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('type', 100);
        $table->string('module', 64);
        $table->string('status', 20)->default('queued');
        $table->unsignedTinyInteger('progress')->default(0);
        $table->string('message', 500)->nullable();
        $table->json('message_params')->nullable();
        $table->json('result')->nullable();
        $table->unsignedBigInteger('started_by')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });

    $package = cdPackage(['translations' => [['name' => 'text.job', 'group_name' => 'text', 'text' => ['vi' => 'J']]]]);
    $run = new ConfigPackageRun(['kind' => 'dry_run', 'status' => 'queued', 'sections' => ['translations'], 'created_by' => 1]);
    $run->setPayload($package);
    $run->save();

    (new DryRunConfigPackageJob((string) $run->uuid, 1))->handle(cdDiffer(), app(\Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder::class));

    $run->refresh();
    expect($run->status)->toBe('succeeded')
        ->and($run->plan['sections']['translations']['counts']['create'])->toBe(1)
        ->and($run->background_task_uuid)->not->toBeNull();
});

it('runs cms:config-import with --dry-run and refuses without it', function (): void {
    Artisan::registerCommand(app(ConfigImportCommand::class));
    cdSetting('general.title', 'Old');
    $package = cdPackage(
        ['settings' => [['key' => 'general.title', 'language' => null, 'value' => 'New']]],
        ['required_secrets' => ['mail.absent_pass']],
    );
    $file = tempnam(sys_get_temp_dir(), 'cfgpkg');
    file_put_contents($file, json_encode($package));

    try {
        $code = Artisan::call('cms:config-import', ['file' => $file, '--dry-run' => true]);
        $output = Artisan::output();
        $run = ConfigPackageRun::query()->firstOrFail();

        expect($code)->toBe(0)
            ->and($output)->toContain((string) $run->uuid)
            ->and($output)->toContain('settings: changes')
            ->and($output)->toContain('mail.absent_pass');

        $before = ConfigPackageRun::query()->count();
        $code = Artisan::call('cms:config-import', ['file' => $file]);

        expect($code)->not->toBe(0)
            ->and(Artisan::output())->toContain('--dry-run')
            ->and(ConfigPackageRun::query()->count())->toBe($before);

        file_put_contents($file, '{not json');
        expect(Artisan::call('cms:config-import', ['file' => $file, '--dry-run' => true]))->not->toBe(0);
    } finally {
        @unlink($file);
    }
});
