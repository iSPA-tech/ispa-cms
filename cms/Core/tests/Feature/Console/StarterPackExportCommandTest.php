<?php

declare(strict_types=1);

use Cms\Core\Contracts\StarterContentImporter;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\StarterPacks\PackContentScanner;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Cms\Core\Services\StarterPacks\StarterPackLinter;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** In-memory importer: `$site` is the reference site content, `$target` receives an import. */
class SpeImporter implements StarterContentImporter
{
    /** @var array<string, mixed> */
    public static array $site = [];

    /** @var array<string, mixed> */
    public static array $target = [];

    public function id(): string { return 'sample.items'; }

    public function module(): string { return 'Sample'; }

    public function validate(array $data): array { return []; }

    public function plan(array $data): array { return ['items' => count($data['items'] ?? [])]; }

    public function import(array $data, array $options = []): array
    {
        self::$target = $data;

        return ['created' => ['items' => count($data['items'] ?? [])], 'skipped' => ['items' => 0]];
    }

    public function export(array $options): array { return self::$site; }
}

/** Importer that cannot export. */
class SpeSilentImporter extends SpeImporter
{
    public function id(): string { return 'sample.silent'; }

    public function export(array $options): array { return []; }
}

beforeEach(function (): void {
    $this->speRoot = sys_get_temp_dir() . '/starter-pack-export-' . uniqid('', true);
    $this->speModules = $this->speRoot . '/modules';
    $this->spePacks = $this->speRoot . '/packs';
    File::ensureDirectoryExists($this->speModules . '/Core');
    File::ensureDirectoryExists($this->speModules . '/Sample');
    File::ensureDirectoryExists($this->spePacks);
    File::put($this->speModules . '/Core/module.json', json_encode(['name' => 'Core', 'version' => '1.0.0', 'requires' => []]));
    File::put($this->speModules . '/Sample/module.json', json_encode([
        'name' => 'Sample', 'version' => '1.0.0', 'requires' => ['Core'],
        'config_export' => ['settings' => ['fx.*'], 'tables' => []],
    ]));

    SpeImporter::$site = ['items' => [['slug' => 'one', 'title' => 'One'], ['slug' => 'two', 'title' => 'Two']]];
    SpeImporter::$target = [];
    $registry = new StarterContentRegistry();
    $registry->register(new SpeImporter());
    $registry->register(new SpeSilentImporter());
    $this->app->instance(StarterContentRegistry::class, $registry);

    foreach ([
        ['fx.title', 'text', 'Welcome', ['isEnv' => false, 'isPublic' => true]],
        ['fx.lang', 'text', 'vi', ['isEnv' => false, 'isPublic' => true]],
        ['fx.api_key', 'password', 'sk-live-SECRETVALUE0123456789', ['isEnv' => false, 'isPublic' => true]],
        ['fx.private', 'text', 'PRIVATEVALUE', ['isEnv' => false, 'isPublic' => false]],
        ['fx.encrypted', 'text', 'eyJpdiI6ENCRYPTEDVALUE', ['isEnv' => true, 'isPublic' => true]],
        ['basic.title', 'text', 'NOT-DECLARED-BY-ANY-MODULE', ['isEnv' => false, 'isPublic' => true]],
    ] as [$key, $type, $value, $input]) {
        Setting::create(['setting_key' => $key, 'type' => $type, 'group_name' => 'basic', 'name' => strtoupper($key), 'value' => $value, 'input_setting' => $input]);
    }

    $permission = Permission::create(['group' => 'post', 'name' => 'view', 'title' => 'View posts']);
    Role::create(['name' => 'Editor', 'custom' => ['level' => 50, 'user_id' => 99]])->permissions()->attach($permission->id);
});

afterEach(function (): void {
    File::deleteDirectory($this->speRoot);
});

/** Run the export command against the temp tree. */
function speExport(object $test, array $args = []): \Illuminate\Testing\PendingCommand
{
    return $test->artisan('cms:starter-pack-export', ['key' => 'ref', '--out' => $test->spePacks . '/ref', '--modules-path' => $test->speModules] + $args);
}

/** Lint the exported folder the way cms:starter-pack-lint does. */
function speLint(object $test): array
{
    $linter = new StarterPackLinter(new StarterPackLoader($test->spePacks), new PackageValidator(), new PackContentScanner(), app(StarterContentRegistry::class), $test->speModules);

    return $linter->lint($test->spePacks . '/ref');
}

it('exports a pack that passes the linter and the content scanner unchanged', function (): void {
    speExport($this, ['--roles' => ['Editor']])->expectsOutputToContain('Pack "ref" written')->assertExitCode(0);

    expect(speLint($this))->toBe(['errors' => [], 'warnings' => []])
        ->and((new PackContentScanner())->scan($this->spePacks . '/ref'))->toBe([]);

    $manifest = (new StarterPackLoader($this->spePacks))->load($this->spePacks . '/ref');
    expect($manifest->modules)->toBe(['Core' => '*', 'Sample' => '*'])
        ->and($manifest->content)->toHaveCount(1)
        ->and($manifest->content[0]['importer'])->toBe('sample.items')
        ->and($manifest->content[0]['file'])->toBe('content/sample/items.json');
});

it('keeps encrypted, secret, non-public and undeclared settings out of the package', function (): void {
    speExport($this)->assertExitCode(0);

    $text = '';
    foreach (File::allFiles($this->spePacks . '/ref') as $file) {
        $text .= file_get_contents($file->getPathname());
    }
    $package = json_decode((string) file_get_contents($this->spePacks . '/ref/config.package.json'), true);
    $keys = array_column($package['sections']['settings'], 'key');

    expect($keys)->toBe(['fx.lang', 'fx.title'])
        ->and($package)->not->toHaveKey('source')
        ->and($package['required_secrets'])->toBe([])
        ->and($text)->not->toContain('SECRETVALUE')->not->toContain('PRIVATEVALUE')->not->toContain('ENCRYPTEDVALUE')
        ->and($text)->not->toContain('fx.api_key')->not->toContain('NOT-DECLARED');
});

it('exports roles with group|name permissions and never the user id', function (): void {
    speExport($this, ['--roles' => ['Editor']])->assertExitCode(0);

    $roles = json_decode((string) file_get_contents($this->spePacks . '/ref/roles.json'), true);

    expect($roles)->toBe([['name' => 'Editor', 'custom' => ['level' => 50], 'permissions' => ['post|view']]]);
});

it('fails for a role that does not exist and writes nothing', function (): void {
    speExport($this, ['--roles' => ['Ghost']])->expectsOutputToContain('"Ghost"')->assertExitCode(1);

    expect(is_dir($this->spePacks . '/ref'))->toBeFalse()
        ->and(glob($this->spePacks . '/.*', GLOB_NOSORT) ?: [])->each->toMatch('~/\.\.?$~');
});

it('round trips: the exported content imports back to the same counts', function (): void {
    speExport($this)->assertExitCode(0);
    $data = json_decode((string) file_get_contents($this->spePacks . '/ref/content/sample/items.json'), true);

    SpeImporter::$site = [];
    $result = (new SpeImporter())->import($data);

    expect($result['created'])->toBe(['items' => 2])
        ->and(SpeImporter::$target)->toBe(['items' => [['slug' => 'one', 'title' => 'One'], ['slug' => 'two', 'title' => 'Two']]]);
});

it('leaves an importer that cannot export out of the pack and says so', function (): void {
    speExport($this)->expectsOutputToContain('importer "sample.silent" cannot export')->assertExitCode(0);

    expect(array_column((new StarterPackLoader($this->spePacks))->load($this->spePacks . '/ref')->content, 'importer'))->toBe(['sample.items']);
});

it('does not overwrite an existing pack without --force', function (): void {
    speExport($this)->assertExitCode(0);
    File::put($this->spePacks . '/ref/notes.txt', 'edited by hand');

    speExport($this)->expectsOutputToContain('--force')->assertExitCode(1);
    expect(file_get_contents($this->spePacks . '/ref/notes.txt'))->toBe('edited by hand');

    speExport($this, ['--force' => true])->assertExitCode(0);
    expect(file_exists($this->spePacks . '/ref/notes.txt'))->toBeFalse()
        ->and(speLint($this)['errors'])->toBe([]);
});

it('keeps the existing pack when a forced export is refused', function (): void {
    speExport($this)->assertExitCode(0);
    $before = file_get_contents($this->spePacks . '/ref/pack.json');
    Setting::query()->where('setting_key', 'fx.title')->update(['value' => 'owner@real-company.vn']);

    speExport($this, ['--force' => true])->expectsOutputToContain(':email')->assertExitCode(1);

    expect(file_get_contents($this->spePacks . '/ref/pack.json'))->toBe($before);
});

it('refuses to export data that holds personal data and writes nothing', function (): void {
    Setting::query()->where('setting_key', 'fx.title')->update(['value' => 'owner@real-company.vn']);

    speExport($this)->expectsOutputToContain('config.package.json')->assertExitCode(1);

    expect(is_dir($this->spePacks . '/ref'))->toBeFalse();
});

it('rejects a pack folder whose name differs from the key', function (): void {
    $this->artisan('cms:starter-pack-export', ['key' => 'ref', '--out' => $this->spePacks . '/other', '--modules-path' => $this->speModules])
        ->expectsOutputToContain('must equal the pack key')->assertExitCode(1);
});
