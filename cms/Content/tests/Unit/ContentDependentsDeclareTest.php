<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;

/**
 * every module that imports `Cms\Content\` declares the Content range it needs (spec section 4.3), so the
 * install preflight refuses a Content upgrade a dependent excludes, and a dependent upgrade that needs a newer Content.
 *
 * "Imports" means non-comment PHP code that names a `Cms\Content\` symbol (a `use`, an inline fully qualified name or a
 * class-name string), except a name that is only the argument of `class_exists()` (a guarded optional integration).
 * Core is skipped: it always ships with the release, and Content depends on it, not the other way round.
 */

/**
 * Module folder names (under cms/) that import Content outside class_exists guards.
 *
 * @return list<string>
 */
function contentDependentsImporting(): array
{
    $importers = [];
    foreach (glob(base_path('cms/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $module = basename($dir);
        if (in_array($module, ['Content', 'Core'], true)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($path, '/tests/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'Cms\\Content\\') && !str_contains($source, 'Cms\\\\Content\\\\')) {
                continue;
            }
            if (contentDependentsFileImports($source)) {
                $importers[] = $module;
                break;
            }
        }
    }
    sort($importers);

    return $importers;
}

/**
 * True when the PHP source names a Content symbol in code (not a comment) outside a class_exists() argument.
 */
function contentDependentsFileImports(string $source): bool
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn ($token) => !is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
    ));
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        $isName = in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
        $isString = $token[0] === T_CONSTANT_ENCAPSED_STRING;
        if (!$isName && !$isString) {
            continue;
        }
        $text = str_replace('\\\\', '\\', ltrim($token[1], "\\'\""));
        if (!str_starts_with($text, 'Cms\\Content\\')) {
            continue;
        }
        $guarded = isset($tokens[$index - 1], $tokens[$index - 2])
            && $tokens[$index - 1] === '('
            && is_array($tokens[$index - 2])
            && strtolower(ltrim((string) $tokens[$index - 2][1], '\\')) === 'class_exists';
        if (!$guarded) {
            return true;
        }
    }

    return false;
}

/**
 * The `requires` of a module.json as an object map (list form means "any version").
 *
 * @return array<string, string>
 */
function contentDependentsRequires(string $module): array
{
    $json = json_decode((string) file_get_contents(base_path("cms/{$module}/module.json")), true);

    return ModuleVersionConstraint::parseRequires($json['requires'] ?? []);
}

test('the guard reader skips comments and class_exists arguments', function () {
    expect(contentDependentsFileImports("<?php\nuse Cms\\Content\\Models\\Post;\n"))->toBeTrue()
        ->and(contentDependentsFileImports("<?php\n\$x = app(\\Cms\\Content\\Services\\X::class);\n"))->toBeTrue()
        ->and(contentDependentsFileImports("<?php\n\$x = 'Cms\\\\Content\\\\Models\\\\Post';\n"))->toBeTrue()
        ->and(contentDependentsFileImports("<?php\n// use Cms\\Content\\Models\\Post;\n/** @see \\Cms\\Content\\Models\\Post */\n"))->toBeFalse()
        ->and(contentDependentsFileImports("<?php\nif (class_exists('Cms\\\\Content\\\\Models\\\\Post')) {}\n"))->toBeFalse()
        ->and(contentDependentsFileImports("<?php\nif (class_exists(\\Cms\\Content\\Models\\Post::class)) {}\n"))->toBeFalse();
});

test('every module that imports Content declares requires.content with a range', function () {
    $missing = [];
    foreach (contentDependentsImporting() as $module) {
        $constraint = contentDependentsRequires($module)['Content'] ?? null;
        if ($constraint === null || $constraint === '' || $constraint === '*') {
            $missing[] = $module;
        }
    }

    expect($missing)->toBe([], 'Modules importing Cms\\Content\\ without a requires.content range: ' . implode(', ', $missing));
});

test('every declared Content range accepts the current Content version and is bounded', function () {
    $content = (string) json_decode((string) file_get_contents(base_path('cms/Content/module.json')), true)['version'];
    $bad = [];
    foreach (glob(base_path('cms/*/module.json')) ?: [] as $file) {
        $module = basename(dirname($file));
        $constraint = contentDependentsRequires($module)['Content'] ?? null;
        if ($constraint === null) {
            continue;
        }
        if (!ModuleVersionConstraint::satisfies($content, $constraint) || !str_contains($constraint, '<')) {
            $bad[] = "{$module}: {$constraint}";
        }
    }

    expect($bad)->toBe([], 'Content ranges that exclude the current Content ' . $content . ' or have no upper bound: ' . implode('; ', $bad));
});

test('the plan lists Widget and ClassOnline too (runtime users of Content) and they declare it', function () {
    foreach (['Widget', 'ClassOnline', 'DigitalLibrary'] as $module) {
        if (! is_file(base_path("cms/{$module}/module.json"))) {
            continue; // not in this tree: the open-source release ships only Core, Content and Widget
        }
        expect(contentDependentsRequires($module))->toHaveKey('Content');
    }
});
