<?php

declare(strict_types=1);

use Cms\Core\Contracts\StarterContentImporter;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Illuminate\Support\Facades\File;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    $this->spRoot = sys_get_temp_dir() . '/starter-pack-lint-' . uniqid('', true);
    $this->spModules = $this->spRoot . '/modules';
    $this->spPacks = $this->spRoot . '/packs';
    File::ensureDirectoryExists($this->spModules);
    File::ensureDirectoryExists($this->spPacks);

    $importer = new class implements StarterContentImporter {
        public function id(): string { return 'sample'; }
        public function export(array $options): array { return []; }
        public function module(): string { return 'Core'; }
        public function validate(array $data): array { return []; }
        public function plan(array $data): array { return []; }
        public function import(array $data, array $options = []): array { return []; }
    };
    $registry = new StarterContentRegistry();
    $registry->register($importer);
    $this->app->instance(StarterContentRegistry::class, $registry);

    spModule($this->spModules, 'Core', ['name' => 'Core', 'version' => '1.0.0']);
    spModule($this->spModules, 'Helpdesk', [
        'name' => 'Helpdesk', 'version' => '1.0.0', 'requires' => ['Core'],
        'permissions' => [['key' => 'helpdesk.agent', 'label' => 'x', 'scopes' => ['all']]],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->spRoot);
});

/** Write a module.json into the temp modules folder. */
function spModule(string $root, string $module, array $manifest): void
{
    File::ensureDirectoryExists("{$root}/{$module}");
    File::put("{$root}/{$module}/module.json", json_encode($manifest));
}

/** Write a pack; $override replaces pack.json fields, $files adds or replaces files (relative path => content). */
function spPack(string $packs, string $key, array $override = [], array $files = []): void
{
    $manifest = array_replace([
        'pack_format' => 1, 'key' => $key, 'version' => '1.0.0',
        'name' => ['vi' => 'Gói', 'en' => 'Pack'], 'description' => ['vi' => 'Mô tả', 'en' => 'Description'],
        'modules' => ['Core' => '*', 'Helpdesk' => '*'],
        'config' => 'config.json', 'roles' => 'roles.json', 'workflows' => null,
        'content' => [['module' => 'Core', 'importer' => 'sample', 'file' => 'content/sample.json']],
        'post_apply' => [],
    ], $override);
    $defaults = [
        'config.json' => json_encode([
            'format' => PackageFormat::FORMAT, 'format_version' => PackageFormat::VERSION, 'created_at' => '2026-11-11T00:00:00+00:00',
            'sections' => [], 'checksum' => PackageFormat::checksum([]),
        ]),
        'roles.json' => json_encode([['name' => 'Agent', 'permissions' => ['helpdesk|agent']]]),
        'content/sample.json' => '{"items": []}',
    ];
    foreach (array_replace($defaults, $files, ['pack.json' => json_encode($manifest)]) as $path => $content) {
        File::ensureDirectoryExists(dirname("{$packs}/{$key}/{$path}"));
        File::put("{$packs}/{$key}/{$path}", $content);
    }
}

/** Run the command against the temp tree. */
function spLint(object $test, array $args = []): \Illuminate\Testing\PendingCommand
{
    return $test->artisan('cms:starter-pack-lint', ['--path' => $test->spPacks, '--modules-path' => $test->spModules] + $args);
}

it('reports no packs and exits 0 when the folder is empty', function (): void {
    spLint($this)->expectsOutputToContain('no packs')->assertExitCode(0);
});

it('passes a valid pack', function (): void {
    spPack($this->spPacks, 'good');

    spLint($this)->expectsOutputToContain('1 packs, 0 errors, 0 warnings')->assertExitCode(0);
});

it('fails on a loader error', function (): void {
    spPack($this->spPacks, 'bad', ['version' => '1.0']);

    spLint($this)->expectsOutputToContain('field "version"')->assertExitCode(1);
});

it('fails when a module has no module.json', function (): void {
    spPack($this->spPacks, 'p', ['modules' => ['Core' => '*', 'Ghost' => '*']]);

    spLint($this)->expectsOutputToContain('module "Ghost" has no cms/Ghost/module.json')->assertExitCode(1);
});

it('fails when a module requires something outside the pack', function (): void {
    spPack($this->spPacks, 'p', ['modules' => ['Core' => '*', 'Helpdesk' => '*', 'Alpha' => '*']]);
    spModule($this->spModules, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0', 'requires' => ['Beta']]);
    spModule($this->spModules, 'Beta', ['name' => 'Beta', 'version' => '1.0.0']);

    spLint($this)->expectsOutputToContain('module "Alpha" requires "Beta"')->assertExitCode(1);
});

it('warns about a module without version but still exits 0', function (): void {
    spModule($this->spModules, 'Core', ['name' => 'Core']);
    spPack($this->spPacks, 'p');

    spLint($this)->expectsOutputToContain('module "Core" has no version')
        ->expectsOutputToContain('0 errors, 1 warnings')->assertExitCode(0);
});

it('fails when the configuration package is invalid', function (): void {
    spPack($this->spPacks, 'p', [], ['config.json' => '[1, 2]']);

    spLint($this)->expectsOutputToContain('config.json:')->assertExitCode(1);
});

it('fails on an unknown role permission', function (): void {
    spPack($this->spPacks, 'p', [], ['roles.json' => json_encode([['name' => 'Agent', 'permissions' => ['helpdesk|agent', 'helpdesk|nope']]])]);

    spLint($this)->expectsOutputToContain('role permission "helpdesk|nope"')->assertExitCode(1);
});

it('fails on an unregistered importer', function (): void {
    spPack($this->spPacks, 'p', ['content' => [['module' => 'Core', 'importer' => 'other', 'file' => 'content/sample.json']]]);

    spLint($this)->expectsOutputToContain('importer "other"')->assertExitCode(1);
});

it('fails on a secret or a personal email and prints file:line:rule', function (): void {
    spPack($this->spPacks, 'p', [], ['content/sample.json' => "{\n\"owner\": \"jane@gmail.com\",\n\"x\": \"example@example.test\"\n}"]);

    spLint($this)->expectsOutputToContain('content/sample.json:2:email')->assertExitCode(1);
});

it('lists every error, not only the first, and can lint one pack by key', function (): void {
    spPack($this->spPacks, 'p', ['modules' => ['Core' => '*', 'Ghost' => '*'], 'content' => [['module' => 'Core', 'importer' => 'other', 'file' => 'content/sample.json']]]);
    spPack($this->spPacks, 'q');

    spLint($this, ['key' => 'p'])
        ->expectsOutputToContain('module "Ghost"')
        ->expectsOutputToContain('importer "other"')
        ->expectsOutputToContain('1 packs, 2 errors, 0 warnings')
        ->assertExitCode(1);
});
