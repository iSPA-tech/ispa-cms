<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->lintRoot = sys_get_temp_dir().'/manifest-lint-'.uniqid('', true);
    File::ensureDirectoryExists($this->lintRoot);
});

afterEach(function () {
    File::deleteDirectory($this->lintRoot);
});

/**
 * Write a manifest for a module into the temp tree.
 */
function lintManifest(string $root, string $module, array $manifest, array $migrations = []): void
{
    File::ensureDirectoryExists("{$root}/{$module}/database/migrations");
    File::put("{$root}/{$module}/module.json", json_encode($manifest));
    foreach ($migrations as $file) {
        File::put("{$root}/{$module}/database/migrations/{$file}", '<?php');
    }
}

it('fails on an unknown top-level field', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0', 'bogus' => 1]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("unknown top-level field 'bogus'")
        ->expectsOutputToContain('1 modules, 1 errors, 0 warnings')
        ->assertExitCode(1);
});

it('fails on bad semver in version and admin.min_shell', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0', 'admin' => ['min_shell' => 'x']]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("'version' is not valid semver")
        ->expectsOutputToContain("'admin.min_shell' is not valid semver")
        ->assertExitCode(1);
});

it('fails when requires names a missing module', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0', 'requires' => ['Ghost' => '^1.0']]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("requires unknown module 'Ghost'")
        ->assertExitCode(1);
});

it('accepts requires in list form with lower-case names and in object form', function () {
    lintManifest($this->lintRoot, 'Core', ['name' => 'Core', 'version' => '1.0.0']);
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0', 'requires' => ['core']]);
    lintManifest($this->lintRoot, 'Beta', ['name' => 'Beta', 'version' => '1.0.0', 'requires' => ['Core' => '^1.0']]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('3 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('checks migrations.irreversible against the migrations folder', function () {
    lintManifest($this->lintRoot, 'Alpha', [
        'name' => 'Alpha', 'version' => '1.0.0',
        'migrations' => ['irreversible' => ['2026_01_01_000000_missing']],
    ]);
    lintManifest($this->lintRoot, 'Beta', [
        'name' => 'Beta', 'version' => '1.0.0',
        'migrations' => ['irreversible' => ['2026_01_01_000000_present', '2026_01_01_000001_other.php']],
    ], ['2026_01_01_000000_present.php', '2026_01_01_000001_other.php']);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('names a missing file: 2026_01_01_000000_missing')
        ->expectsOutputToContain('2 modules, 1 errors, 0 warnings')
        ->assertExitCode(1);
});

it('fails on a duplicate permission key across modules', function () {
    $perm = [['key' => 'posts.view', 'label' => 'View posts']];
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0', 'permissions' => $perm]);
    lintManifest($this->lintRoot, 'Beta', ['name' => 'Beta', 'version' => '1.0.0', 'permissions' => $perm]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("duplicate permission key 'posts.view'")
        ->assertExitCode(1);
});

it('only warns when version is missing', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha']);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("missing 'version'")
        ->expectsOutputToContain('1 modules, 0 errors, 1 warnings')
        ->assertExitCode(0);
});

it('fails on invalid JSON', function () {
    File::ensureDirectoryExists($this->lintRoot.'/Alpha');
    File::put($this->lintRoot.'/Alpha/module.json', '{nope');

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('not valid JSON')
        ->assertExitCode(1);
});

/**
 * Manifest with one navigation item pointing at the given resource key.
 */
function lintNavigationManifest(string $resource): array
{
    return [
        'name' => 'Alpha', 'version' => '1.0.0',
        'admin' => ['navigation' => [['name' => 'posts', 'text' => 'Posts', 'icon' => 'x', 'rule' => 'a', 'resource' => $resource]]],
    ];
}

it('fails when a navigation resource is not registered', function () {
    $this->app->instance(\Cms\Core\Admin\Resources\ResourceRegistry::class, new \Cms\Core\Admin\Resources\ResourceRegistry($this->app));
    lintManifest($this->lintRoot, 'Alpha', lintNavigationManifest('alpha/posts'));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("admin.navigation references unknown resource 'alpha/posts'")
        ->expectsOutputToContain('1 modules, 1 errors, 0 warnings')
        ->assertExitCode(1);
});

it('passes a navigation resource that is registered', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'alpha/posts';
        }

        public function ability(): string
        {
            return 'alpha.view';
        }

        public function fields(): array
        {
            return [];
        }
    };

    $registry = new \Cms\Core\Admin\Resources\ResourceRegistry($this->app);
    $registry->register('alpha/posts', $resource::class);
    $this->app->instance(\Cms\Core\Admin\Resources\ResourceRegistry::class, $registry);
    lintManifest($this->lintRoot, 'Alpha', lintNavigationManifest('alpha/posts'));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('passes a full valid v2 manifest', function () {
    lintManifest($this->lintRoot, 'Core', ['name' => 'Core', 'version' => '1.0.0']);
    lintManifest($this->lintRoot, 'Alpha', [
        'name' => 'Alpha', 'alias' => 'alpha', 'description' => 'd', 'keywords' => [], 'priority' => 0,
        'providers' => [], 'files' => [], 'authors' => [], 'active' => 1, 'order' => 0,
        'version' => '2.1.0', 'requires' => ['Core' => '^1.0'],
        'admin' => ['navigation' => [], 'custom_code' => false, 'min_shell' => '1.0.0'],
        'migrations' => ['irreversible' => ['2026_01_01_000000_x']],
        'permissions' => [['key' => 'alpha.view', 'label' => 'View', 'scopes' => ['own']]],
        'scope_dimensions' => [['name' => 'site', 'model' => 'App\\Site', 'hierarchy' => 'path', 'label' => 'Site']],
        'config_export' => ['settings' => ['a', 'alpha.*'], 'tables' => ['email_templates']],
    ], ['2026_01_01_000000_x.php']);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('2 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

/**
 * Bind a fresh DimensionRegistry and a module repository fake in which the given modules are enabled.
 *
 * @param array<int, string> $enabledModules Module names reported as enabled.
 * @param array<string, string> $registered Dimension name => owning module registered in the registry.
 */
function lintScopeRuntime(array $enabledModules, array $registered = []): void
{
    $repository = Mockery::mock(\Nwidart\Modules\Contracts\RepositoryInterface::class);
    $repository->shouldReceive('find')->andReturnUsing(function (string $name) use ($enabledModules) {
        foreach ($enabledModules as $enabled) {
            if (strcasecmp($enabled, $name) === 0) {
                $module = Mockery::mock(\Nwidart\Modules\Module::class);
                $module->shouldReceive('isEnabled')->andReturn(true);

                return $module;
            }
        }

        return null;
    });
    app()->instance(\Nwidart\Modules\Contracts\RepositoryInterface::class, $repository);

    $registry = new \Cms\Core\Services\DataScope\DimensionRegistry($repository);
    foreach ($registered as $name => $module) {
        $registry->register($name, $module, static fn () => null, 'label.'.$name);
    }
    app()->instance(\Cms\Core\Services\DataScope\DimensionRegistry::class, $registry);
}

/**
 * Manifest declaring the given dimensions and one permission with the given scopes.
 */
function lintScopeManifest(string $name, array $scopes = [], array $dimensions = [], array $extra = []): array
{
    $manifest = ['name' => $name, 'version' => '1.0.0'] + $extra;
    if ($scopes !== []) {
        $manifest['permissions'] = [['key' => strtolower($name).'.view', 'label' => 'View', 'scopes' => $scopes]];
    }
    if ($dimensions !== []) {
        $manifest['scope_dimensions'] = $dimensions;
    }

    return $manifest;
}

function lintDimension(string $name, string $hierarchy = 'flat'): array
{
    return ['name' => $name, 'model' => 'App\\Thing', 'hierarchy' => $hierarchy, 'label' => 'Thing'];
}

it('fails on a permission scope that is neither all, own nor a known dimension', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['all', 'own', 'galaxy']));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("permission 'alpha.view' scope 'galaxy' is not all, own or a known scope dimension")
        ->assertExitCode(1);
});

it('accepts scopes naming an own-module or a required-module dimension', function () {
    lintScopeRuntime([]);
    lintManifest($this->lintRoot, 'Core', lintScopeManifest('Core', [], [lintDimension('org_unit', 'closure')]));
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['all', 'org_unit'], [], ['requires' => ['core']]));
    lintManifest($this->lintRoot, 'Beta', lintScopeManifest('Beta', ['site', 'own'], [lintDimension('site')]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('3 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('rejects a dimension of a module that is not required', function () {
    lintScopeRuntime([]);
    lintManifest($this->lintRoot, 'Core', lintScopeManifest('Core', [], [lintDimension('org_unit')]));
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['org_unit']));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("scope 'org_unit' is not all, own or a known scope dimension")
        ->assertExitCode(1);
});

it('fails when scopes is not a list of strings', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0',
        'permissions' => [['key' => 'alpha.view', 'label' => 'V', 'scopes' => ['own', 5]]]]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("permission 'alpha.view' scopes must be a list of strings")
        ->assertExitCode(1);
});

it('fails on a bad hierarchy and a non-list scope_dimensions', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [lintDimension('site', 'tree')]));
    lintManifest($this->lintRoot, 'Beta', ['name' => 'Beta', 'version' => '1.0.0', 'scope_dimensions' => ['site' => []]]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("scope dimension 'site' hierarchy must be closure, path or flat")
        ->expectsOutputToContain("'scope_dimensions' must be a list")
        ->assertExitCode(1);
});

it('accepts every allowed hierarchy', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [
        lintDimension('dim_a', 'closure'), lintDimension('dim_b', 'path'), lintDimension('dim_c', 'flat'),
    ]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('fails on a bad dimension name and a duplicate name across modules', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [lintDimension('Bad-Name'), lintDimension('site')]));
    lintManifest($this->lintRoot, 'Beta', lintScopeManifest('Beta', [], [lintDimension('site')]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("scope dimension name 'Bad-Name' is invalid")
        ->expectsOutputToContain("duplicate scope dimension 'site' (also declared by Alpha)")
        ->assertExitCode(1);
});

it('fails when a declared dimension is not registered in the DimensionRegistry', function () {
    lintScopeRuntime(['Alpha']);
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [lintDimension('site')]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("scope dimension 'site' is declared but not registered in DimensionRegistry")
        ->assertExitCode(1);
});

it('fails when a registered dimension is not declared by its enabled module', function () {
    lintScopeRuntime(['Alpha'], ['site' => 'Alpha']);
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha'));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("dimension 'site' is registered by Alpha but not declared in scope_dimensions")
        ->assertExitCode(1);
});

it('passes when declared and registered dimensions agree and ignores unscanned modules', function () {
    lintScopeRuntime(['Alpha', 'Other'], ['site' => 'Alpha', 'elsewhere' => 'Other']);
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [lintDimension('site')]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('skips the runtime check for modules that are not enabled', function () {
    lintScopeRuntime([], []);
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [lintDimension('site')]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

/**
 * Write a PHP file with the given body into a fixture module (relative path inside the module directory).
 */
function lintModuleFile(string $root, string $module, string $relative, string $body): void
{
    File::ensureDirectoryExists(dirname("{$root}/{$module}/{$relative}"));
    File::put("{$root}/{$module}/{$relative}", "<?php\n\nclass Fixture\n{\n    public function run()\n    {\n        {$body}\n    }\n}\n");
}

it('fails when a scoped module calls withoutGlobalScopes() without arguments', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['own']));
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Services/Thing.php', 'return Post::query()->withoutGlobalScopes()->get();');

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('Alpha: app/Services/Thing.php:7 must not drop the data scope')
        ->expectsOutputToContain('1 modules, 1 errors, 0 warnings')
        ->assertExitCode(1);
});

it('fails when a scoped module calls withoutGlobalScope with the data_scope id string', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', [], [lintDimension('site')]));
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Models/Thing.php', "return Post::query()->withoutGlobalScope('data_scope')->get();");
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Models/Other.php', 'return Post::query()->withoutGlobalScope("data_scope")->get();');

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('Alpha: app/Models/Thing.php:7 must not drop the data scope')
        ->expectsOutputToContain('Alpha: app/Models/Other.php:7 must not drop the data scope')
        ->assertExitCode(1);
});

it('fails when a scoped module calls withoutGlobalScope with DataScope::SCOPE_ID', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['own']));
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Thing.php', 'return Post::query()->withoutGlobalScope(\\Cms\\Core\\Services\\DataScope\\DataScope::SCOPE_ID)->get();');
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Deep/Nested/Other.php', 'return Post::query()->withoutGlobalScopes([DataScope::SCOPE_ID, Foo::BAR])->get();');

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('Alpha: app/Thing.php:7 must not drop the data scope')
        ->expectsOutputToContain('Alpha: app/Deep/Nested/Other.php:7 must not drop the data scope')
        ->assertExitCode(1);
});

it('does not scan tests or other directories outside app', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['own']));
    lintModuleFile($this->lintRoot, 'Alpha', 'tests/Feature/ThingTest.php', 'return Post::query()->withoutGlobalScopes()->get();');
    lintModuleFile($this->lintRoot, 'Alpha', 'database/seeders/Seeder.php', "return Post::query()->withoutGlobalScope('data_scope')->get();");

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('ignores dropped scopes in modules that declare no data scope', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha'));
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Thing.php', 'return Post::query()->withoutGlobalScopes()->get();');

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('allows dropping scopes other than the data scope in a scoped module', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['own']));
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Thing.php', "Post::query()->withoutGlobalScope(Post::OTHER_SCOPE)->get();\n        Post::query()->withoutGlobalScope('other')->get();\n        Post::query()->withoutGlobalScopes([SoftDeletingScope::class])->get();\n        // ->withoutGlobalScopes() in a comment");

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('fails on an empty withoutGlobalScopes array in a scoped module', function () {
    lintManifest($this->lintRoot, 'Alpha', lintScopeManifest('Alpha', ['own']));
    lintModuleFile($this->lintRoot, 'Alpha', 'app/Thing.php', 'return Post::query()->withoutGlobalScopes([])->get();');

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('Alpha: app/Thing.php:7 must not drop the data scope')
        ->assertExitCode(1);
});

it('passes a config_export with valid patterns and a registered table', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0',
        'config_export' => ['settings' => ['alpha.*', 'beta.exact_key'], 'tables' => ['email_templates']]]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')
        ->assertExitCode(0);
});

it('fails on a config_export table that is not registered', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0',
        'config_export' => ['settings' => [], 'tables' => ['ghost_table']]]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("config_export.tables names unregistered table 'ghost_table'")
        ->assertExitCode(1);
});

it('fails on malformed config_export settings patterns', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0',
        'config_export' => ['settings' => ['*', 'a.*.b', 'has space.*', '']]]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("config_export.settings pattern '*' is malformed")
        ->expectsOutputToContain("config_export.settings pattern 'a.*.b' is malformed")
        ->expectsOutputToContain("config_export.settings pattern 'has space.*' is malformed")
        ->expectsOutputToContain("config_export.settings pattern '' is malformed")
        ->assertExitCode(1);
});

it('fails on a badly shaped config_export', function () {
    lintManifest($this->lintRoot, 'Alpha', ['name' => 'Alpha', 'version' => '1.0.0', 'config_export' => ['a']]);
    lintManifest($this->lintRoot, 'Beta', ['name' => 'Beta', 'version' => '1.0.0',
        'config_export' => ['settings' => 'x', 'tables' => [5], 'extra' => []]]);

    $this->artisan('cms:manifest-lint', ['--path' => $this->lintRoot])
        ->expectsOutputToContain("'config_export' must be an object")
        ->expectsOutputToContain('config_export.settings must be a list of strings')
        ->expectsOutputToContain('config_export.tables must be a list of strings')
        ->expectsOutputToContain("config_export has unknown field 'extra'")
        ->assertExitCode(1);
});
