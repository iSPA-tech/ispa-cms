<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageAudit;
use Cms\Core\Services\Modules\Packages\PackageBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuildException;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Remove a temp tree (symlinks are unlinked, never followed).
 */
function pauRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        pauRemove($path . '/' . $entry);
    }
    rmdir($path);
}

/**
 * Create a temp root with one module under <root>/mods/<name>, a composer.lock and a shared admin package list.
 *
 * @param array<string, mixed>  $module module.json overrides (a null value removes the field)
 * @param array<string, string> $files  extra files: relative path => contents
 *
 * @return string the temp root
 */
function pauFixture(array $module = [], array $files = [], string $name = 'Demo'): string
{
    $root = sys_get_temp_dir() . '/pau-' . bin2hex(random_bytes(6));
    $dir = $root . '/mods/' . $name;
    mkdir($dir . '/app', 0775, true);
    $manifest = array_filter(array_merge([
        'name' => $name, 'alias' => strtolower($name), 'version' => '2026.03.0', 'providers' => [], 'files' => [],
    ], $module), static fn ($v) => $v !== null);
    file_put_contents($dir . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT));
    foreach (array_merge(['app/Thing.php' => "<?php\n// thing\n"], $files) as $relative => $contents) {
        @mkdir(dirname($dir . '/' . $relative), 0775, true);
        file_put_contents($dir . '/' . $relative, $contents);
    }
    file_put_contents($root . '/composer.lock', json_encode(['packages' => [['name' => 'vendor/present']], 'packages-dev' => []]));
    $shared = [];
    foreach (['vue' => 3, 'vue-i18n' => 11, 'vue-router' => 5, 'ant-design-vue' => 4, 'dayjs' => 1, 'axios' => 1] as $pkg => $major) {
        $shared[$pkg] = ['major' => $major, 'used_by' => []];
    }
    file_put_contents($root . '/shared-packages.json', json_encode(['format' => 1, 'packages' => $shared]));
    config(['core.module_packages.shared_admin_packages_file' => $root . '/shared-packages.json']);

    return $root;
}

/**
 * The refusal code of a callable, or null when it passed.
 */
function pauCode(callable $run): ?string
{
    try {
        $run();
    } catch (PackageBuildException $e) {
        return $e->errorCode;
    }

    return null;
}

/**
 * Every refusal of the package builder, as [module.json overrides, extra files, module name, setup, expected code].
 *
 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>, 2: string, 3: (callable(string): void)|null, 4: string}>
 */
function pauRefusals(): array
{
    $up = "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void\n    {\n        Illuminate\\Support\\Facades\\Schema::table('posts', function (\$t) { \$t->dropColumn('x'); });\n    }\n};\n";

    return [
        'invalid name' => [[], [], 'demo-x', null, PackageBuildException::NOT_FOUND],
        'Core' => [[], [], 'Core', null, PackageBuildException::FORBIDDEN_MODULE],
        'Game*' => [[], [], 'GameRun', null, PackageBuildException::FORBIDDEN_MODULE],
        'module.json not an object' => [[], [], 'Demo', static fn (string $root) => file_put_contents($root . '/mods/Demo/module.json', '[1]'), PackageBuildException::LINT],
        'name mismatch' => [['name' => 'Other'], [], 'Demo', null, PackageBuildException::LINT],
        'lint error' => [['bogus_field' => 1], [], 'Demo', null, PackageBuildException::LINT],
        'deploy only' => [['distribution' => 'deploy', 'distribution_reason' => 'needs the project theme'], [], 'Demo', null, PackageBuildException::DEPLOY_ONLY],
        'open requires premium' => [['edition' => 'open', 'requires' => ['other']], [], 'Demo', static function (string $root): void {
            mkdir($root . '/mods/Other');
            file_put_contents($root . '/mods/Other/module.json', json_encode(['name' => 'Other', 'version' => '2026.03.0', 'edition' => 'premium']));
        }, PackageBuildException::EDITION],
        'missing version' => [['version' => null], [], 'Demo', null, PackageBuildException::VERSION],
        'bad version' => [['version' => '1.0.0-beta'], [], 'Demo', null, PackageBuildException::VERSION],
        'vite config' => [[], ['vite.config.js' => 'export default {}'], 'Demo', null, PackageBuildException::VITE_CONFIG],
        'symlink' => [[], [], 'Demo', static fn (string $root) => symlink($root . '/composer.lock', $root . '/mods/Demo/app/link'), PackageBuildException::SYMLINK],
        'own frontend-build' => [[], ['frontend-build/manifest.json' => '{}'], 'Demo', null, PackageBuildException::FRONTEND_BUILD],
        'missing frontend entry' => [['frontend' => ['entries' => ['resources/js/missing.js'], 'styles' => 'none']], [], 'Demo', null, PackageBuildException::FRONTEND_BUILD],
        'too many files' => [[], ['app/a.php' => '<?php', 'app/b.php' => '<?php'], 'Demo', static fn () => config(['core.module_packages.max_files' => 2]), PackageBuildException::LIMITS],
        'file too large' => [[], ['app/big.txt' => str_repeat('x', 64)], 'Demo', static fn () => config(['core.module_packages.max_file_bytes' => 32]), PackageBuildException::LIMITS],
        'changelog required' => [[], [], 'Demo', static fn () => config(['core.module_packages.require_changelog' => true]), PackageBuildException::CHANGELOG],
        'destructive Content migration' => [['alias' => 'content'], ['database/migrations/2026_11_14_000001_drop_x.php' => $up], 'Content', null, PackageBuildException::DESTRUCTIVE_MIGRATION],
        'composer autoload files' => [[], ['composer.json' => json_encode(['autoload' => ['files' => ['helpers.php']]])], 'Demo', null, PackageBuildException::COMPOSER_AUTOLOAD],
        'composer foreign psr-4' => [[], ['composer.json' => json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]])], 'Demo', null, PackageBuildException::COMPOSER_AUTOLOAD],
        'composer unlocked package' => [[], ['composer.json' => json_encode(['require' => ['vendor/absent' => '^1.0']])], 'Demo', null, PackageBuildException::COMPOSER_REQUIRE],
        'relative import leaving the module' => [[], ['admin/escape.js' => "export { S } from '../../Core/admin/page-loaders'\n"], 'Demo', null, PackageBuildException::ADMIN_IMPORT],
        'unknown admin package' => [[], ['admin/bad.vue' => "<script>\nimport qs from 'qs'\n</script>"], 'Demo', null, PackageBuildException::ADMIN_IMPORT],
        'non-literal dynamic import' => [[], ['admin/dyn.js' => "const a = import(name)\n"], 'Demo', null, PackageBuildException::ADMIN_IMPORT],
        'shared list missing' => [[], ['admin/ok.js' => "import { ref } from 'vue'\n"], 'Demo', static fn (string $root) => unlink($root . '/shared-packages.json'), PackageBuildException::SHARED_PACKAGES],
    ];
}

it('refuses every builder refusal through PackageAudit, and the builder refuses it with the same code', function (array $module, array $files, string $name, ?callable $setup, string $code) {
    $root = pauFixture($module, $files, $name);
    try {
        if ($setup !== null) {
            $setup($root);
        }
        $lock = $root . '/composer.lock';
        expect(pauCode(fn () => (new PackageAudit())->source($name, $root . '/mods', $lock)))->toBe($code)
            ->and(pauCode(fn () => (new PackageBuilder())->check($name, $root . '/mods', $lock)))->toBe($code);
    } finally {
        pauRemove($root);
    }
})->with(pauRefusals());

it('returns the context the builder builds from, with the release-notes warning', function () {
    $root = pauFixture([], ['admin/module.js' => "import axios from 'axios'\nimport x from './x.js'\n", 'admin/x.js' => 'export default 1', 'tests/skip.php' => '<?php', '.DS_Store' => 'x']);
    try {
        $audit = new PackageAudit();
        $context = $audit->source('Demo', $root . '/mods', $root . '/composer.lock');

        expect($context['version'])->toBe('2026.03.0')
            ->and($context['dir'])->toBe($root . '/mods/Demo')
            ->and($context['paths'])->toBe(['admin/module.js', 'admin/x.js', 'app/Thing.php', 'module.json'])
            ->and($context['admin_packages'])->toBe(['axios' => 1])
            ->and($context['frontend'])->toBeNull()
            ->and($context['warnings'])->toHaveCount(1)
            ->and($audit->warnings())->toBe($context['warnings'])
            ->and($context['warnings'][0])->toContain('has no CHANGELOG.md');
    } finally {
        pauRemove($root);
    }
});

it('audits an extracted package: nothing skipped, a built frontend-build accepted only with declared entries', function () {
    $frontend = ['entries' => ['resources/js/app.js'], 'styles' => 'module'];
    $root = pauFixture(['frontend' => $frontend], [
        'resources/js/app.js' => "console.log('x')\n",
        'frontend-build/manifest.json' => '{}',
        'tests/Shipped.php' => '<?php',
    ]);
    try {
        $context = (new PackageAudit())->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/mods');
        expect($context['paths'])->toContain('frontend-build/manifest.json')->toContain('tests/Shipped.php')
            ->and($context['frontend'])->toBe(['entries' => ['resources/js/app.js'], 'styles' => 'module']);

        // The same tree as a source tree: the builder writes frontend-build itself, so a shipped one is refused.
        expect(pauCode(fn () => (new PackageAudit())->source('Demo', $root . '/mods', $root . '/composer.lock')))->toBe(PackageBuildException::FRONTEND_BUILD);
    } finally {
        pauRemove($root);
    }

    $root = pauFixture([], ['frontend-build/manifest.json' => '{}']);
    try {
        expect(pauCode(fn () => (new PackageAudit())->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/mods')))->toBe(PackageBuildException::FRONTEND_BUILD);
    } finally {
        pauRemove($root);
    }
});

it('refuses Core and Game* names in an extracted package and checks sizes and the unpacked total', function () {
    foreach (['Core', 'GameRun'] as $name) {
        $root = pauFixture([], [], $name);
        try {
            expect(pauCode(fn () => (new PackageAudit())->extracted($root . '/mods/' . $name, $root . '/composer.lock', $root . '/mods')))->toBe(PackageBuildException::FORBIDDEN_MODULE);
        } finally {
            pauRemove($root);
        }
    }

    $root = pauFixture([], ['tests/big.txt' => str_repeat('x', 64)]);
    try {
        config(['core.module_packages.max_file_bytes' => 32]);
        expect(pauCode(fn () => (new PackageAudit())->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/mods')))->toBe(PackageBuildException::LIMITS);
        config(['core.module_packages.max_file_bytes' => 1000, 'core.module_packages.max_unpacked_bytes' => 100]);
        expect(pauCode(fn () => (new PackageAudit())->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/mods')))->toBe(PackageBuildException::LIMITS);
    } finally {
        pauRemove($root);
    }
});

it('resolves required modules of an extracted package from the dependency folder', function () {
    $root = pauFixture(['requires' => ['helper']]);
    mkdir($root . '/deps/Helper', 0775, true);
    file_put_contents($root . '/deps/Helper/module.json', json_encode(['name' => 'Helper', 'alias' => 'helper', 'version' => '2026.03.0', 'providers' => [], 'files' => []]));
    try {
        $audit = new PackageAudit();
        expect(pauCode(fn () => $audit->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/mods')))->toBe(PackageBuildException::LINT)
            ->and($audit->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/deps')['version'])->toBe('2026.03.0');
    } finally {
        pauRemove($root);
    }
});

it('only reads the audited tree', function () {
    $root = pauFixture([], ['admin/module.js' => "import { ref } from 'vue'\n", 'app/Boom.php' => "<?php\nfile_put_contents(__DIR__ . '/../../../executed', 'x');\n"]);
    try {
        $snapshot = static function (string $dir): array {
            $out = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $file) {
                $out[substr($file->getPathname(), strlen($dir))] = $file->isFile() ? hash_file('sha256', $file->getPathname()) . '@' . $file->getMTime() : 'dir';
            }
            ksort($out);

            return $out;
        };
        $before = $snapshot($root);
        (new PackageAudit())->source('Demo', $root . '/mods', $root . '/composer.lock');
        (new PackageAudit())->extracted($root . '/mods/Demo', $root . '/composer.lock', $root . '/mods');
        expect($snapshot($root))->toBe($before)->and(is_file($root . '/executed'))->toBeFalse();
    } finally {
        pauRemove($root);
    }
});
