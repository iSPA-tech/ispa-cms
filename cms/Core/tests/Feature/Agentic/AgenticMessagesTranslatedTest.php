<?php

declare(strict_types=1);

uses(Cms\Core\Tests\TestCase::class);

/**
 * Guards that every `mgs.agentic.*` message key used by module code has an
 * English and a Vietnamese translation, so the admin never shows a raw key.
 */

/**
 * Collect the distinct `mgs.agentic.<key>` literals used under cms/{module}/app.
 *
 * @return array<int, string>
 */
function agenticMessageKeysInUse(): array
{
    $keys = [];
    $files = glob(base_path('cms') . '/*/app') ?: [];

    foreach ($files as $appDir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match_all('/mgs\.agentic\.[a-z0-9-]+/', (string) file_get_contents($file->getPathname()), $matches)) {
                foreach ($matches[0] as $key) {
                    $keys[$key] = $key;
                }
            }
        }
    }

    ksort($keys);

    return array_values($keys);
}

it('finds agentic message keys in module code', function () {
    expect(agenticMessageKeysInUse())->not->toBeEmpty();
});

it('translates every agentic message key in English and Vietnamese', function () {
    $missing = [];

    foreach (agenticMessageKeysInUse() as $key) {
        foreach (['en', 'vi'] as $locale) {
            $text = __($key, [], $locale);

            if (! is_string($text) || $text === $key) {
                $missing[] = "{$locale}: {$key}";
            }
        }
    }

    expect($missing)->toBe([]);
});
