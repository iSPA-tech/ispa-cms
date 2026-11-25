<?php

declare(strict_types=1);

use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Illuminate\Support\Facades\Crypt;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Build a package around the given sections with a correct checksum.
 */
function configPackage(array $sections = [], array $overrides = []): array
{
    if ($sections === []) {
        $sections = [
            'settings' => [['key' => 'site.title', 'type' => 'text', 'group_name' => 'general', 'name' => 'Title', 'language' => null, 'value' => 'Hi']],
            'roles' => [['name' => 'Editor', 'custom' => true, 'permissions' => ['posts.view']]],
        ];
    }

    return array_merge([
        'format' => PackageFormat::FORMAT,
        'format_version' => PackageFormat::VERSION,
        'created_at' => '2026-11-07T10:00:00+07:00',
        'source' => ['app_name' => 'iSPA', 'host' => 'example.test', 'core_version' => '1.0.0', 'modules' => ['Core' => '1.0.0']],
        'sections' => $sections,
        'required_secrets' => ['mail.smtp_password'],
        'skipped' => ['settings' => ['secret' => 1]],
        'checksum' => PackageFormat::checksum($sections),
    ], $overrides);
}

function errorsText(array $package, ?PackageValidator $validator = null): string
{
    return implode("\n", ($validator ?? new PackageValidator())->validate($package)->errors());
}

it('accepts a valid minimal package', function (): void {
    $result = (new PackageValidator())->validate(configPackage());

    expect($result->errors())->toBe([])->and($result->isValid())->toBeTrue();
});

it('allows absent sections', function (): void {
    expect((new PackageValidator())->validate(configPackage(['menus' => []]))->isValid())->toBeTrue();

    $package = configPackage(overrides: ['sections' => [], 'checksum' => PackageFormat::checksum([])]);
    unset($package['source'], $package['required_secrets'], $package['skipped']);
    expect((new PackageValidator())->validate($package)->errors())->toBe([]);
});

it('rejects a wrong checksum', function (): void {
    $package = configPackage();
    $package['sections']['settings'][0]['value'] = 'tampered';

    expect((new PackageValidator())->validate($package)->isValid())->toBeFalse()
        ->and(errorsText($package))->toContain('checksum');
});

it('rejects an unsupported format version', function (): void {
    expect(errorsText(configPackage(overrides: ['format_version' => 2])))->toContain('format_version 2')
        ->and(errorsText(configPackage(overrides: ['format_version' => '1'])))->toContain('format_version');
});

it('rejects a wrong format string', function (): void {
    expect(errorsText(configPackage(overrides: ['format' => 'other'])))->toContain('format');
});

it('rejects unknown top-level keys and missing required keys', function (): void {
    expect(errorsText(configPackage(overrides: ['extra' => 1])))->toContain('extra');

    $package = configPackage();
    unset($package['created_at']);
    expect(errorsText($package))->toContain('created_at');
});

it('rejects numeric id and *_id keys inside sections', function (): void {
    $withId = configPackage(['roles' => [['name' => 'Editor', 'id' => 5]]]);
    $withMenuId = configPackage(['menus' => [['group_key' => 'main', 'name' => 'Main', 'items' => [['name' => 'a', 'menu_id' => '12']]]]]);
    $withUuidKey = configPackage(['roles' => [['name' => 'Editor', 'parent_id' => 'editor-key']]]);

    expect(errorsText($withId))->toContain('sections.roles.0.id')
        ->and(errorsText($withMenuId))->toContain('sections.menus.0.items.0.menu_id')
        ->and((new PackageValidator())->validate($withUuidKey)->isValid())->toBeTrue();
});

it('rejects numeric camelCase *Id keys inside workflows only', function (): void {
    $step = static fn (array $config): array => configPackage(['workflows' => [['slug' => 'w', 'steps' => [['step_code' => 'a', 'step_config' => $config]]]]]);

    expect(errorsText($step(['sourceRunId' => 42])))->toContain('sections.workflows.0.steps.0.step_config.sourceRunId')
        ->and(errorsText($step(['approvalID' => '7'])))->toContain('step_config.approvalID')
        ->and((new PackageValidator())->validate($step(['sourceRunId' => 'vars.run.uuid', 'valid' => 1, 'uuid' => 'x']))->errors())->toBe([]);

    // A site's own setting value keeps exporting: camelCase ids were never checked outside workflows.
    $setting = configPackage(['settings' => [['key' => 'theme.home', 'type' => 'json', 'group_name' => 'theme', 'name' => 'Home', 'language' => null, 'value' => ['pageId' => 12]]]]);
    expect((new PackageValidator())->validate($setting)->errors())->toBe([]);
});

it('rejects an oversize package', function (): void {
    $validator = new PackageValidator(maxBytes: 200);

    expect(errorsText(configPackage(), $validator))->toContain('size');
});

it('rejects sections with the wrong shape', function (): void {
    expect(errorsText(configPackage(['settings' => 'nope'])))->toContain('sections.settings')
        ->and(errorsText(configPackage(['settings' => ['a', 'b']])))->toContain('sections.settings.0')
        ->and(errorsText(configPackage(['tables' => [['a' => 1]]])))->toContain('sections.tables')
        ->and(errorsText(configPackage(['settings' => [['type' => 'text']]])))->toContain('sections.settings.0.key')
        ->and(errorsText(configPackage(['widgets' => []])))->toContain('widgets')
        ->and(errorsText(configPackage(overrides: ['sections' => ['a', 'b'], 'checksum' => PackageFormat::checksum(['a', 'b'])])))->toContain('sections')
        ->and(errorsText(configPackage(['workflows' => [['slug' => 'w', 'steps' => [['name' => 'x']], 'connections' => [['from' => 'a']]]]])))
        ->toContain('sections.workflows.0.steps.0.step_code')
        ->toContain('sections.workflows.0.connections.0.to');
});

it('rejects too many rows', function (): void {
    $validator = new PackageValidator(maxRows: 2);
    $rows = [['name' => 'a'], ['name' => 'b'], ['name' => 'c']];

    expect(errorsText(configPackage(['roles' => $rows]), $validator))->toContain('sections.roles')
        ->and(errorsText(configPackage(['tables' => ['t' => $rows]]), $validator))->toContain('sections.tables.t');
});

it('rejects ciphertext inside sections without echoing it', function (): void {
    $cipher = Crypt::encryptString('secret-value');
    $text = errorsText(configPackage(['settings' => [['key' => 'a', 'value' => $cipher]]]));

    expect($text)->toContain('sections.settings.0.value')->and($text)->not->toContain($cipher);
});

/**
 * Build a menu whose items nest the given number of levels.
 */
function nestedMenuSections(int $levels): array
{
    $item = ['name' => 'leaf'];
    for ($i = 1; $i < $levels; $i++) {
        $item = ['name' => 'level' . $i, 'items' => [$item]];
    }

    return ['menus' => [['group_key' => 'main', 'name' => 'Main', 'items' => [$item]]]];
}

it('accepts menu items nested up to the depth cap and rejects deeper trees', function (): void {
    $validator = new PackageValidator();

    expect($validator->validate(configPackage(nestedMenuSections(PackageValidator::MAX_MENU_DEPTH)))->isValid())->toBeTrue()
        ->and(errorsText(configPackage(nestedMenuSections(PackageValidator::MAX_MENU_DEPTH + 1))))->toContain('nests deeper than');
});

it('reports every issue as a translation key with params and the English message', function (): void {
    $package = configPackage();
    $package['format_version'] = 99;
    $package['checksum'] = 'sha256:' . str_repeat('0', 64);
    $package['sections']['settings'][0]['key'] = '';

    $result = (new PackageValidator())->validate($package);
    $issues = collect($result->issues());

    expect($issues)->not->toBeEmpty()
        ->and($issues->every(fn (array $i): bool => str_starts_with($i['key'], 'text.config_package.validation.') && is_array($i['params']) && is_string($i['message']) && $i['message'] !== ''))->toBeTrue()
        ->and($result->errors())->toBe($issues->pluck('message')->all())
        ->and($issues->firstWhere('key', 'text.config_package.validation.version_unsupported'))->toMatchArray(['params' => ['version' => 99, 'max' => PackageFormat::VERSION]])
        ->and($issues->firstWhere('key', 'text.config_package.validation.field_required')['params']['path'])->toBe('sections.settings.0.key');
});
