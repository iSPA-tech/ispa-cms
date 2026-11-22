<?php

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Guard against real secrets in tracked example and seed files.
 *
 * The open-source export scan (tools/open-source-export/scan.sh) found a hub secret, an app key and a master password
 * in `.env.example` and Stripe and Google keys in seed data. Those files are copied into every new install, so they
 * carry placeholders only. Failure messages name the key or file, never the value.
 */

/**
 * Value of a key in the tracked `.env.example` ('' when the key is empty, null when it is missing).
 */
function trackedEnvExampleValue(string $key): ?string
{
    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, $key.'=')) {
            return trim(substr($line, strlen($key) + 1), " \t\"'");
        }
    }

    return null;
}

it('keeps the secrets in .env.example empty', function (string $key) {
    $value = trackedEnvExampleValue($key);

    expect($value !== null)->toBeTrue("{$key} is missing from .env.example")
        ->and($value === '')->toBeTrue("{$key} in .env.example must be empty");
})->with(['APP_KEY', 'CMS_DOMAIN_SECRET', 'MASTER_PASSWORD', 'MAIL_PASSWORD']);

// Release rehearsal 2026-11-19: a fresh install copies .env.example to .env, so it must not name the maintainer's mail
// host, mail addresses, database or database user, and must not send mail anywhere until the site owner sets a mailer.
it('ships neutral mail and database defaults in .env.example', function () {
    expect(trackedEnvExampleValue('MAIL_MAILER'))->toBe('log')
        ->and(trackedEnvExampleValue('MAIL_HOST'))->toBe('127.0.0.1')
        ->and(trackedEnvExampleValue('MAIL_FROM_ADDRESS'))->toBe('hello@example.com')
        ->and(trackedEnvExampleValue('MAIL_REPLY'))->toBe('hello@example.com')
        ->and(trackedEnvExampleValue('DB_DATABASE'))->toBe('ispa_cms')
        ->and(trackedEnvExampleValue('DB_USERNAME'))->toBe('root');
    expect((string) file_get_contents(base_path('.env.example')))->not->toContain('privateemail.com')
        ->and((string) file_get_contents(base_path('.env.example')))->not->toContain('team@ispa.io');
    // 2026-11-19 review: the maintainer's Postgres host (a private address), database and user, and an IndexNow key.
    expect(trackedEnvExampleValue('PTG_HOST'))->toBe('127.0.0.1')
        ->and(trackedEnvExampleValue('PTG_DATABASE'))->toBe('ispa_cms')
        ->and(trackedEnvExampleValue('PTG_USERNAME'))->toBe('postgres')
        ->and(trackedEnvExampleValue('INDEX_NOW_KEY'))->toBe('');
});

// A key set but empty (`KEY=`) gives env() an empty string, so the default in config never applies. Every empty key in
// .env.example must therefore have no literal non-empty default in the app's or the bundled modules' config.
it('has no empty key in .env.example that hides a config default', function () {
    $empty = [];
    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=\s*(""|\'\')?\s*$/', $line, $m) === 1) {
            $empty[$m[1]] = true;
        }
    }
    $hidden = [];
    $files = array_merge(
        glob(base_path('config/*.php')) ?: [],
        ...array_map(static fn (string $module): array => glob(base_path("cms/{$module}/config/*.php")) ?: [], ['Core', 'Content', 'Widget']),
    );
    foreach ($files as $file) {
        preg_match_all('/env\(\s*\'([A-Z][A-Z0-9_]*)\'\s*,\s*(\'[^\']+\'|"[^"]+"|true|false|-?\d+(?:\.\d+)?)\s*\)/', (string) file_get_contents($file), $calls, PREG_SET_ORDER);
        foreach ($calls as [, $key, $default]) {
            if (isset($empty[$key])) {
                $hidden[] = "{$key} (default {$default} in " . basename($file) . ')';
            }
        }
    }

    expect(array_values(array_unique($hidden)))->toBe([]);
});

it('ships no Stripe or Google API key in seeded settings', function (string $relative) {
    $path = base_path($relative);
    if (! is_file($path)) {
        $this->markTestSkipped("{$relative} is not part of this tree");
    }

    $text = (string) file_get_contents($path);

    expect(preg_match('/\b[spr]k_(?:test|live)_[A-Za-z0-9]{16,}/', $text))->toBe(0, "{$relative} holds a Stripe key")
        ->and(preg_match('/AIza[0-9A-Za-z_\-]{30,}/', $text))->toBe(0, "{$relative} holds a Google API key");
})->with(['cms/Core/database/seeders/exported/settings.php', 'storage/app/upgradable/settings.php']);

/**
 * Rows of a settings seed file as [setting_key => value].
 *
 * @return array<string, mixed>
 */
function trackedSeedSettings(string $relative): array
{
    $rows = require base_path($relative);

    return collect(is_array($rows) ? $rows : [])->mapWithKeys(
        static fn ($row): array => [(string) ($row['setting_key'] ?? '') => $row['value'] ?? null]
    )->all();
}

it('keeps secret-named values empty in the settings seeds', function (string $relative) {
    // the export scan found a mail password, a webhook secret and a bot token in a settings seed.
    foreach (trackedSeedSettings($relative) as $key => $value) {
        if (preg_match('/(password|passwd|secret|token|api_key|apikey|private_key|chat_id)$/i', $key) === 1) {
            expect($value === null || $value === '')->toBeTrue("{$relative}: {$key} must be empty");
        }
    }
})->with(['cms/Core/database/seeders/exported/settings.php']);

it('ships no phone number or outside social link in the settings seeds', function (string $relative) {
    // a new site starts with empty contact details instead of a client's phone or channel.
    foreach (trackedSeedSettings($relative) as $key => $value) {
        $text = is_string($value) ? $value : '';
        expect(preg_match('/(?<![\d.])(?:\+?84[\s.\-]?|0)(?:[35789]\d|2\d{2})(?:[\s.\-]?\d){7}(?![\d.])/', $text))
            ->toBe(0, "{$relative}: {$key} holds a phone number");
        if (str_starts_with($key, 'social.url.') && $text !== '') {
            expect(str_contains(strtolower($text), 'ispa'))
                ->toBeTrue("{$relative}: {$key} must be empty or iSPA's own page");
        }
    }
})->with(['cms/Core/database/seeders/exported/settings.php']);
