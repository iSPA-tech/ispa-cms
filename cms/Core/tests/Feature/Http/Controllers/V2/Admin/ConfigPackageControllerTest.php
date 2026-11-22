<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Translate;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * configuration package admin API (Webmaster only, run scoped to its creator, secrets never answered).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const CPC_BASE = 'https://localhost/api/v2/admin/config-packages';

/** Marker planted in a secret setting; it must never appear in any response. */
const CPC_SECRET = 'PLANTED-CPC-SECRET-7f3a9c';

/**
 * Test adapter delegating to a real adapter, except afterApply (the real ones call Artisan and Redis).
 */
final class CpcQuietSection implements SectionAdapter
{
    /**
     * @param SectionAdapter $inner Real adapter doing the work.
     */
    public function __construct(private readonly SectionAdapter $inner)
    {
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
        return $this->inner->apply($rows, $c);
    }

    /** @inheritDoc */
    public function afterApply(ApplyContext $c): void
    {
    }
}

/**
 * Create an admin user (type ADMIN) with a role of the given level (0 = Webmaster).
 */
function cpcUser(string $name, ?int $roleLevel): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => UserType::ADMIN,
        'level' => 0,
        'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' ' . Str::random(4), 'custom' => ['level' => $roleLevel]]));
    }

    return $user;
}

/**
 * Valid package around the given sections.
 *
 * @param array<string, mixed> $sections
 *
 * @return array<string, mixed>
 */
function cpcPackage(array $sections): array
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

/**
 * Upload file holding the JSON of a package.
 *
 * @param array<string, mixed> $package
 */
function cpcFile(array $package, string $name = 'package.json'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Run row created directly (for scoping and kind tests).
 */
function cpcRun(User $owner, string $kind, string $status = ConfigPackageRun::STATUS_SUCCEEDED): ConfigPackageRun
{
    $run = new ConfigPackageRun([
        'kind' => $kind,
        'status' => $status,
        'sections' => ['settings'],
        'created_by' => $owner->id,
        'summary' => ['sections' => ['settings' => 1]],
        'plan' => ['sections' => ['settings' => ['status' => 'ok', 'counts' => [], 'total' => 0, 'items' => []]]],
    ]);
    $package = cpcPackage(['settings' => [['key' => 'basic.site_name', 'language' => null, 'value' => 'Payload-Only-Value']]]);
    $run->setPayload($package);
    $run->package_checksum = $package['checksum'];
    $run->save();

    return $run;
}

/**
 * Every config package route with a sample path.
 *
 * @return list<array{0: string, 1: string}>
 */
function cpcRoutes(string $uuid): array
{
    return [
        ['GET', CPC_BASE . '/sections'],
        ['POST', CPC_BASE . '/export'],
        ['GET', CPC_BASE . '/runs'],
        ['GET', CPC_BASE . '/runs/' . $uuid],
        ['GET', CPC_BASE . '/' . $uuid . '/download'],
        ['POST', CPC_BASE . '/upload'],
        ['POST', CPC_BASE . '/' . $uuid . '/apply'],
    ];
}

beforeEach(function (): void {
    // User id 1 is always a Webmaster (User::isWebmaster), so take it with a filler account first.
    cpcUser('filler', null);
    $this->webmaster = cpcUser('webmaster', 0);
    $this->otherWebmaster = cpcUser('webmaster2', 0);
    $this->manager = cpcUser('manager', 50);
    expect($this->webmaster->fresh()->isWebmaster())->toBeTrue()
        ->and($this->manager->fresh()->isWebmaster())->toBeFalse();

    Setting::create([
        'setting_key' => 'basic.site_name',
        'type' => 'text',
        'group_name' => 'basic',
        'name' => 'Site name',
        'value' => 'Old Name',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);
    Setting::create([
        'setting_key' => 'basic.mailer_password',
        'type' => 'password',
        'group_name' => 'basic',
        'name' => 'Mailer password',
        'value' => CPC_SECRET,
        'input_setting' => ['isEnv' => false, 'isPublic' => false],
    ]);
    Translate::create([
        'name' => 'text.cpc_hello',
        'group_name' => 'text',
        'modules' => ['Core'],
        'text' => ['vi' => 'Xin chao'],
        'for_web' => true,
        'status' => 'active',
        'is_used' => true,
        'used_in' => [],
    ]);

    $this->asWebmaster = fn () => Sanctum::actingAs($this->webmaster->fresh(), ['*']);
});

test('every route requires authentication', function (): void {
    foreach (cpcRoutes((string) Str::uuid()) as [$method, $url]) {
        $this->json($method, $url)->assertStatus(401);
    }
});

test('an admin who is not a Webmaster gets 403 on every route, also in the local environment', function (): void {
    Queue::fake();
    $run = cpcRun($this->manager, ConfigPackageRun::KIND_EXPORT);

    foreach (['testing', 'local'] as $environment) {
        $this->app['env'] = $environment;
        expect(app()->environment($environment))->toBeTrue();
        Sanctum::actingAs($this->manager->fresh(), ['*']);

        foreach (cpcRoutes((string) $run->uuid) as [$method, $url]) {
            $this->json($method, $url, ['sections' => ['settings']])
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'WEBMASTER_ONLY');
        }
    }

    expect(ConfigPackageRun::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('sections lists the available sections with key patterns and counts', function (): void {
    ($this->asWebmaster)();

    $response = $this->getJson(CPC_BASE . '/sections')->assertOk();
    $sections = collect($response->json('data.sections'))->keyBy('key');

    expect($sections->keys()->all())->toBe(PackageFormat::SECTIONS)
        ->and($sections['settings']['patterns'])->toContain('basic.*')
        // The secret setting is not counted as exportable.
        ->and($sections['settings']['count'])->toBe(1)
        ->and($sections['translations']['count'])->toBe(1)
        ->and($response->json('data.translation_scopes'))->toBe(['web', 'adm', 'app', 'all'])
        ->and($response->getContent())->not->toContain(CPC_SECRET);
});

test('export then download returns a valid package with download headers and no secret', function (): void {
    ($this->asWebmaster)();

    $export = $this->postJson(CPC_BASE . '/export', ['sections' => ['settings', 'translations'], 'translation_scope' => 'web'])
        ->assertStatus(202);
    $runUuid = (string) $export->json('data.run');
    expect($runUuid)->not->toBe('')
        ->and($export->json('data.task'))->toBeString()
        ->and($export->json('data.status'))->toBe(ConfigPackageRun::STATUS_SUCCEEDED);

    $download = $this->get(CPC_BASE . '/' . $runUuid . '/download');
    $download->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and((string) $download->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and((string) $download->headers->get('Content-Disposition'))->toContain('.json');

    $body = $download->streamedContent();
    $package = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    expect((new PackageValidator())->validate($package)->isValid())->toBeTrue()
        ->and(collect($package['sections']['settings'])->pluck('key')->all())->toBe(['basic.site_name'])
        ->and(collect($package['sections']['translations'])->pluck('name')->all())->toContain('text.cpc_hello')
        ->and($body)->not->toContain(CPC_SECRET)
        ->and($export->getContent())->not->toContain(CPC_SECRET);

    $show = $this->getJson(CPC_BASE . '/runs/' . $runUuid)->assertOk()
        ->assertJsonPath('data.run.kind', 'export')
        ->assertJsonMissingPath('data.run.payload');
    expect($show->getContent())->not->toContain(CPC_SECRET);
});

test('export rejects an unknown section with 422 and creates no run', function (): void {
    ($this->asWebmaster)();

    $this->postJson(CPC_BASE . '/export', ['sections' => ['settings', 'users']])->assertStatus(422);
    $this->postJson(CPC_BASE . '/export', ['sections' => []])->assertStatus(422);
    expect(ConfigPackageRun::query()->count())->toBe(0);
});

test('download of a dry run, an apply run, an unfinished export or another user\'s export is 404', function (): void {
    ($this->asWebmaster)();

    $dryRun = cpcRun($this->webmaster, ConfigPackageRun::KIND_DRY_RUN);
    $apply = cpcRun($this->webmaster, ConfigPackageRun::KIND_APPLY);
    $queued = cpcRun($this->webmaster, ConfigPackageRun::KIND_EXPORT, ConfigPackageRun::STATUS_QUEUED);
    $foreign = cpcRun($this->otherWebmaster, ConfigPackageRun::KIND_EXPORT);
    $own = cpcRun($this->webmaster, ConfigPackageRun::KIND_EXPORT);

    foreach ([$dryRun, $apply, $queued, $foreign] as $run) {
        $this->getJson(CPC_BASE . '/' . $run->uuid . '/download')->assertStatus(404);
    }
    $this->get(CPC_BASE . '/' . $own->uuid . '/download')->assertOk();
    $this->getJson(CPC_BASE . '/' . Str::uuid() . '/download')->assertStatus(404);
});

test('another user\'s run is 404 on show and apply and missing from the history', function (): void {
    ($this->asWebmaster)();
    $foreign = cpcRun($this->otherWebmaster, ConfigPackageRun::KIND_DRY_RUN);
    $own = cpcRun($this->webmaster, ConfigPackageRun::KIND_EXPORT);

    $this->getJson(CPC_BASE . '/runs/' . $foreign->uuid)->assertStatus(404);
    $this->postJson(CPC_BASE . '/' . $foreign->uuid . '/apply', ['choices' => []])->assertStatus(404);

    $runs = $this->getJson(CPC_BASE . '/runs')->assertOk();
    expect(collect($runs->json('data.data'))->pluck('uuid')->all())->toBe([(string) $own->uuid])
        ->and($runs->json('data.meta.total'))->toBe(1);
    expect(ConfigPackageRun::query()->where('kind', 'apply')->count())->toBe(0);
});

test('upload rejects a non-JSON file, an oversize file and invalid JSON without creating a run', function (): void {
    ($this->asWebmaster)();

    $this->post(CPC_BASE . '/upload', ['file' => UploadedFile::fake()->createWithContent('package.txt', 'plain text')], ['Accept' => 'application/json'])
        ->assertStatus(422);
    $this->post(CPC_BASE . '/upload', ['file' => UploadedFile::fake()->image('package.png')], ['Accept' => 'application/json'])
        ->assertStatus(422);
    $this->post(CPC_BASE . '/upload', ['file' => UploadedFile::fake()->create('package.json', 20481, 'application/json')], ['Accept' => 'application/json'])
        ->assertStatus(422);
    $this->post(CPC_BASE . '/upload', [], ['Accept' => 'application/json'])->assertStatus(422);
    $this->post(CPC_BASE . '/upload', ['file' => UploadedFile::fake()->createWithContent('package.json', '{"format": "ispa-config-package",')], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'CONFIG_PACKAGE_INVALID_JSON');

    expect(ConfigPackageRun::query()->count())->toBe(0);
});

test('upload of a package that fails validation answers 422 with the validator messages and no run', function (): void {
    ($this->asWebmaster)();

    $package = cpcPackage(['settings' => [['key' => 'basic.site_name', 'language' => null, 'value' => 'New Name']]]);
    $package['checksum'] = 'sha256:' . str_repeat('0', 64);
    $package['format_version'] = 99;
    $expected = (new PackageValidator())->validate($package)->errors();
    expect($expected)->not->toBe([]);

    $response = $this->post(CPC_BASE . '/upload', ['file' => cpcFile($package)], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'CONFIG_PACKAGE_INVALID')
        ->assertJsonPath('data.errors', $expected)
        ->assertJsonPath('data.issues.0.params', fn ($p) => is_array($p))
        ->assertJsonPath('data.issues.0.message', $expected[0]);

    expect(str_starts_with((string) $response->json('data.issues.0.key'), 'text.config_package.validation.'))->toBeTrue();

    // A package carrying a secret-looking key is refused the same way.
    $secret = cpcPackage(['settings' => [['key' => 'basic.site_name', 'language' => null, 'value' => ['smtp_password' => CPC_SECRET]]]]);
    $secretResponse = $this->post(CPC_BASE . '/upload', ['file' => cpcFile($secret)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(ConfigPackageRun::query()->count())->toBe(0)
        ->and($response->getContent() . $secretResponse->getContent())->not->toContain(CPC_SECRET);
});

test('a package carrying a planted secret is refused with no run and no stored payload', function (): void {
    ($this->asWebmaster)();

    $secret = cpcPackage(['settings' => [['key' => 'basic.site_name', 'language' => null, 'value' => ['smtp_password' => CPC_SECRET]]]]);
    $response = $this->post(CPC_BASE . '/upload', ['file' => cpcFile($secret)], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'CONFIG_PACKAGE_INVALID');

    // Nothing is stored, so there is no payload to prune and the value is in no row or response.
    expect(ConfigPackageRun::query()->count())->toBe(0)
        ->and(ConfigPackageRun::query()->whereNotNull('payload')->count())->toBe(0)
        ->and($response->getContent())->not->toContain(CPC_SECRET)
        ->and(json_encode(DB::table((new ConfigPackageRun())->getTable())->get()))->not->toContain(CPC_SECRET);
});

test('a package with a row aimed at a secret setting of this site is refused and nothing is stored', function (): void {
    ($this->asWebmaster)();
    Setting::create([
        'setting_key' => 'email.MAIL_PASSWORD', 'type' => 'password', 'group_name' => 'email', 'name' => 'MAIL_PASSWORD',
        'value' => 'old', 'input_setting' => ['isEnv' => true, 'isPublic' => true],
    ]);

    $package = cpcPackage(['settings' => [['key' => 'email.MAIL_PASSWORD', 'language' => null, 'value' => CPC_SECRET]]]);
    $response = $this->post(CPC_BASE . '/upload', ['file' => cpcFile($package)], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('data.issues.0.key', 'text.config_package.validation.secret_blocked');

    expect(ConfigPackageRun::query()->count())->toBe(0)
        ->and($response->getContent())->not->toContain(CPC_SECRET);
});

test('upload of a valid package starts a dry run whose plan is paged by section and never shows the payload', function (): void {
    ($this->asWebmaster)();

    $package = cpcPackage([
        'settings' => [['key' => 'basic.site_name', 'language' => null, 'value' => 'New Name']],
        'translations' => [
            ['name' => 'text.cpc_hello', 'group_name' => 'text', 'modules' => ['Core'], 'text' => ['vi' => 'Xin chao'], 'for_web' => true, 'for_adm' => false, 'for_app' => false, 'status' => 'active'],
            ['name' => 'text.cpc_added', 'group_name' => 'text', 'modules' => ['Core'], 'text' => ['vi' => 'Moi'], 'for_web' => true, 'for_adm' => false, 'for_app' => false, 'status' => 'active'],
        ],
    ]);

    $upload = $this->post(CPC_BASE . '/upload', ['file' => cpcFile($package)], ['Accept' => 'application/json'])
        ->assertStatus(202);
    $runUuid = (string) $upload->json('data.run');
    $run = ConfigPackageRun::query()->where('uuid', $runUuid)->firstOrFail();
    expect($run->kind)->toBe('dry_run')
        ->and($run->status)->toBe('succeeded')
        ->and($run->created_by)->toBe($this->webmaster->id)
        ->and($upload->json('data.task'))->toBe($run->background_task_uuid);

    $show = $this->getJson(CPC_BASE . '/runs/' . $runUuid . '?section=translations&per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.run.status', 'succeeded')
        ->assertJsonPath('data.plan.section', 'translations')
        ->assertJsonPath('data.plan.page', 2)
        ->assertJsonPath('data.plan.per_page', 1)
        ->assertJsonPath('data.plan.item_total', 2)
        ->assertJsonPath('data.plan.last_page', 2)
        ->assertJsonMissingPath('data.run.payload')
        ->assertJsonMissingPath('data.payload');
    expect($show->json('data.plan.items'))->toHaveCount(1)
        ->and(array_keys($show->json('data.plan.sections')))->toBe(['settings', 'translations'])
        ->and($show->json('data.plan.sections.settings'))->not->toHaveKey('items');

    $settings = $this->getJson(CPC_BASE . '/runs/' . $runUuid)->assertOk();
    expect($settings->json('data.plan.section'))->toBe('settings')
        ->and($settings->json('data.plan.items.0.action'))->toBe('update');

    $rawPayload = (string) $run->getAttributes()['payload'];
    expect($rawPayload)->not->toBe('');
    foreach ([$upload, $show, $settings, $this->getJson(CPC_BASE . '/runs')->assertOk()] as $response) {
        expect($response->getContent())->not->toContain(substr($rawPayload, 0, 40))
            ->and($response->getContent())->not->toContain('"payload"')
            ->and($response->getContent())->not->toContain(CPC_SECRET);
    }
    expect($this->getJson(CPC_BASE . '/runs')->json('data.data.0'))->not->toHaveKey('plan');
});

test('apply of the caller\'s dry run starts an apply run with the backup gate', function (): void {
    $adapters = [new CpcQuietSection(new SettingsSection()), new CpcQuietSection(new TranslationsSection('all'))];
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->once()->andReturn((new Backup())->forceFill(['uuid' => 'cpc-backup-1']));
    app()->instance(ConfigPackageDiffer::class, new ConfigPackageDiffer($adapters));
    app()->instance(ConfigPackageApplier::class, new ConfigPackageApplier($gate, $adapters, new ConfigPackageDiffer($adapters)));
    ($this->asWebmaster)();

    $package = cpcPackage(['settings' => [['key' => 'basic.site_name', 'language' => null, 'value' => 'New Name']]]);
    $dryRunUuid = (string) $this->post(CPC_BASE . '/upload', ['file' => cpcFile($package)], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('data.run');

    $apply = $this->postJson(CPC_BASE . '/' . $dryRunUuid . '/apply', ['choices' => []])->assertStatus(202);
    $applyRun = ConfigPackageRun::query()->where('uuid', (string) $apply->json('data.run'))->firstOrFail();

    expect($applyRun->kind)->toBe('apply')
        ->and($applyRun->status)->toBe('succeeded')
        ->and($applyRun->parentRun?->uuid)->toBe($dryRunUuid)
        ->and($apply->json('data.task'))->toBe($applyRun->background_task_uuid)
        ->and(Setting::query()->where('setting_key', 'basic.site_name')->firstOrFail()->value)->toBe('New Name')
        ->and($apply->getContent())->not->toContain(CPC_SECRET);

    // A second apply of the same dry run is refused because the site changed since the dry run.
    $this->postJson(CPC_BASE . '/' . $dryRunUuid . '/apply', ['choices' => []])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'CONFIG_PACKAGE_APPLY_REFUSED');

    // An export run cannot be applied.
    $export = cpcRun($this->webmaster, ConfigPackageRun::KIND_EXPORT);
    $this->postJson(CPC_BASE . '/' . $export->uuid . '/apply', ['choices' => []])->assertStatus(404);

    $history = $this->getJson(CPC_BASE . '/runs?kind=apply')->assertOk();
    expect(collect($history->json('data.data'))->pluck('uuid')->all())->toBe([(string) $applyRun->uuid])
        ->and($history->json('data.data.0.parent_uuid'))->toBe($dryRunUuid);
});

test('apply refuses invalid choices input with 422', function (): void {
    ($this->asWebmaster)();
    $dryRun = cpcRun($this->webmaster, ConfigPackageRun::KIND_DRY_RUN);

    $this->postJson(CPC_BASE . '/' . $dryRun->uuid . '/apply', ['choices' => 'all'])->assertStatus(422);
    expect(ConfigPackageRun::query()->where('kind', 'apply')->count())->toBe(0);
});
