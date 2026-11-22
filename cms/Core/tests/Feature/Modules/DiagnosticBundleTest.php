<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Support\DiagnosticBundleBuilder;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Security\LogRedactor;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/*
 * the site builds a diagnostic bundle for one module, masks it on the site, shows it for preview and sends
 * only the lines the Webmaster kept. The bundle is always rebuilt on the server; text from the browser never reaches
 * the hub except the subject and the description, which are masked too.
 */

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir() . '/diag-bundle-' . bin2hex(random_bytes(6));
    $this->releaseDir = $this->tmp . '/release';
    $this->runtimeDir = $this->tmp . '/runtime';
    $this->logDir = $this->tmp . '/logs';
    foreach ([$this->releaseDir, $this->runtimeDir, $this->logDir] as $dir) {
        mkdir($dir, 0755, true);
    }
    RuntimeModules::setPath($this->runtimeDir);

    // Fake secrets, built at run time so no real-looking key sits in the repository.
    $this->appKey = 'base64:' . base64_encode('k' . bin2hex(random_bytes(12)));
    $this->dbPassword = 'Db-Pw-' . bin2hex(random_bytes(5));
    $this->mailPassword = 'mail/pw+' . bin2hex(random_bytes(4));
    $this->secret = 'test-secret-' . bin2hex(random_bytes(8));
    $this->bearer = '12|' . bin2hex(random_bytes(16));
    $this->ctxToken = bin2hex(random_bytes(10));
    $this->envFile = $this->tmp . '/.env';
    file_put_contents($this->envFile, implode("\n", [
        'APP_KEY=' . $this->appKey,
        'DB_PASSWORD="' . $this->dbPassword . '"',
        'MAIL_PASSWORD=' . $this->mailPassword,
        'SHORT=abc12',
        'APP_ENV=production',
        'DB_CONNECTION=sqlite',
        'QUEUE_CONNECTION=sync',
        'FILESYSTEM_DISK=local',
        'APP_DEBUG=true',
        'EMPTY=',
        '',
    ]));

    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);

    $this->hub = ['status' => 201, 'data' => ['ticket' => '0193a2b4-5c6d-7e8f-9a0b-1c2d3e4f5a6b'], 'requests' => []];
    $GLOBALS['db_hub'] = &$this->hub;
    $GLOBALS['db_secret'] = $this->secret;
    Http::fake(function (ClientRequest $sent) {
        $hub = &$GLOBALS['db_hub'];
        $hub['requests'][] = ['method' => $sent->method(), 'path' => (string) parse_url($sent->url(), PHP_URL_PATH), 'body' => $sent->body()];
        $body = json_encode($hub['data']);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, $hub['status'], [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($GLOBALS['db_secret'], $hub['status'], $nonce, $body)]);
    });

    $this->app->instance(ModuleCatalog::class, new ModuleCatalog(null, $this->releaseDir));
    $this->app->bind(DiagnosticBundleBuilder::class, fn ($app) => new DiagnosticBundleBuilder(
        $app->make(LogRedactor::class),
        $app->make(ModuleCatalog::class),
        logDirectory: $this->logDir,
        envFile: $this->envFile,
    ));
});

afterEach(function () {
    RuntimeModules::resetPath();
    dbRemoveTree($this->tmp);
});

/**
 * Remove a temp tree.
 */
function dbRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            dbRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/**
 * Sign in as a Webmaster (level-0 role) or a plain admin.
 */
function dbAdmin(bool $webmaster = true): User
{
    $user = User::query()->create(['name' => 'Webmaster', 'email' => uniqid('db') . '@private.test', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster ' . uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }
    Sanctum::actingAs($user->fresh());

    return $user->fresh();
}

/**
 * Mark module Probe as installed on the site (a release folder with its module.json).
 */
function dbInstall(string $releaseDir, string $name = 'Probe'): void
{
    mkdir($releaseDir . '/' . $name, 0755, true);
    file_put_contents($releaseDir . '/' . $name . '/module.json', json_encode(['name' => $name, 'version' => '2026.3.0']));
}

/**
 * One Laravel log entry with a stack trace through the given namespace.
 */
function dbLogEntry(string $time, string $level, string $message, string $module = 'Probe'): string
{
    return '[' . $time . '] production.' . $level . ': ' . $message . ' {"exception":"[object] (RuntimeException(code: 0): '
        . $message . ' at ' . base_path('cms/' . $module . '/app/Services/Thing.php') . ':42)' . "\n"
        . '[stacktrace]' . "\n"
        . '#0 ' . base_path('cms/' . $module . '/app/Http/Controllers/ThingController.php') . '(17): Cms\\' . $module . '\\Services\\Thing->run()' . "\n"
        . '#1 {main}' . "\n" . '"}' . "\n";
}

const DB_BASE = '/api/v2/admin/utilities/modules';

test('secrets, personal data and IP addresses in a log entry come out masked', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $message = 'Hub call failed key=' . $this->appKey . ' db=' . $this->dbPassword . ' mail=' . $this->mailPassword
        . ' auth="Authorization: Bearer ' . $this->bearer . '" user=jane.doe@example.com phone=+84 912 345 678'
        . ' alt=0912345678 from 203.0.113.77 v6 2001:db8::8a2e:370:7334 at 10:15:30 secret=' . $this->secret
        . ' url=https://api.example.test/cb?access_token=tok-' . $this->ctxToken . '&page=2 ctx={"password":"pw-' . $this->ctxToken . '","prompt_tokens":12}';
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', $message));

    $response = $this->getJson(DB_BASE . '/Probe/support/preview')->assertOk();
    $raw = $response->getContent();

    foreach ([$this->appKey, $this->dbPassword, $this->mailPassword, $this->bearer, $this->secret, $this->ctxToken, 'jane.doe@example.com', '912 345 678', '0912345678', '203.0.113.77', '2001:db8::8a2e:370:7334'] as $plain) {
        expect(str_contains($raw, $plain))->toBeFalse('the preview leaked a masked value');
    }
    // JSON escaping must not let a value through either ("/" becomes "\/").
    expect(str_contains($raw, json_encode($this->mailPassword)))->toBeFalse();

    $log = collect($response->json('data.lines'))->where('section', 'log')->values();
    expect($log)->toHaveCount(1)
        ->and($log[0]['text'])->toContain('Hub call failed')
        ->and($log[0]['text'])->toContain(LogRedactor::MASK)
        ->and($log[0]['text'])->toContain('Authorization: ' . LogRedactor::MASK)
        // Times are not IPv6 addresses; absolute paths are cut to the application root.
        ->and($log[0]['text'])->toContain('10:15:30')
        ->and($log[0]['text'])->toContain('"prompt_tokens":12')
        ->and($log[0]['text'])->toContain('page=2')
        ->and($log[0]['text'])->toContain('cms/Probe/app/Services/Thing.php')
        ->and($log[0]['text'])->not->toContain(base_path());

    // Nothing leaves the site from a preview.
    expect($this->hub['requests'])->toBe([]);
});

test('a module with no errors gives an environment-only bundle and preview sends nothing', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    // Errors of another module and notices of this one are not part of the bundle.
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 09:00:00', 'ERROR', 'Other broke', 'Other')
        . '[2026-11-14 09:01:00] production.INFO: Cms\\Probe\\Services\\Thing started' . "\n");

    $response = $this->getJson(DB_BASE . '/Probe/support/preview')->assertOk();

    $lines = collect($response->json('data.lines'));
    expect($lines->pluck('section')->unique()->values()->all())->toBe(['environment'])
        ->and($lines->pluck('key')->all())->toContain('module_version', 'module_source', 'core_version', 'php_version', 'database', 'enabled_modules')
        ->and($lines->firstWhere('key', 'module_version')['text'])->toBe('2026.3.0')
        ->and($lines->firstWhere('key', 'php_version')['text'])->toBe(PHP_VERSION)
        ->and($response->json('data.module'))->toBe('Probe')
        ->and($response->json('data.counts'))->toBe(['environment' => $lines->count(), 'log' => 0, 'failed_job' => 0]);
    foreach ($lines as $line) {
        expect($line['id'])->toMatch('/^[a-f0-9]{16}$/');
    }
    expect($this->hub['requests'])->toBe([]);
});

test('core_version is the installed iSPA CMS package version and core_module_version the Core module.json version', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $site = $this->tmp . '/site';
    mkdir($site . '/cms/Core', 0755, true);
    file_put_contents($site . '/cms/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.16.9']));
    $build = fn () => $this->app->bind(DiagnosticBundleBuilder::class, fn ($app) => new DiagnosticBundleBuilder(
        $app->make(LogRedactor::class),
        $app->make(ModuleCatalog::class),
        logDirectory: $this->logDir,
        envFile: $this->envFile,
        basePath: $site,
    ));
    $build();
    $line = fn (string $key): ?string => collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->firstWhere('key', $key)['text'] ?? null;

    // No core-manifest.json (a site deployed by iSPA): the configured version, as before.
    config(['ispa-cms.app_version' => '2026.3.0']);
    expect($line('core_version'))->toBe('2026.3.0')
        ->and($line('core_module_version'))->toBe('2026.16.9');

    file_put_contents($site . '/core-manifest.json', json_encode(['format' => 1, 'version' => '2026.12.0', 'php_min' => '8.3', 'checked' => (object) [], 'replaced' => []]));
    expect($line('core_version'))->toBe('2026.12.0')
        ->and($line('core_module_version'))->toBe('2026.16.9');
});

test('failed jobs of the module are listed without their payload body', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $payload = json_encode([
        'displayName' => 'Cms\\Probe\\Jobs\\SyncThing',
        'data' => ['commandName' => 'Cms\\Probe\\Jobs\\SyncThing', 'command' => 'O:24:"Cms\\Probe\\Jobs\\SyncThing":1:{s:5:"email";s:16:"payload@body.dev";}'],
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => '0193a2b4-0000-7000-8000-000000000001', 'connection' => 'database', 'queue' => 'default',
        'payload' => $payload, 'exception' => 'RuntimeException: token ' . $this->dbPassword . ' rejected', 'failed_at' => now(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => '0193a2b4-0000-7000-8000-000000000002', 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'Cms\\Other\\Jobs\\X', 'data' => ['commandName' => 'Cms\\Other\\Jobs\\X']]),
        'exception' => 'Other failure', 'failed_at' => now(),
    ]);

    $response = $this->getJson(DB_BASE . '/Probe/support/preview')->assertOk();
    $raw = $response->getContent();

    $jobs = collect($response->json('data.lines'))->where('section', 'failed_job')->values();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['text'])->toContain('Cms\\Probe\\Jobs\\SyncThing')
        ->and($jobs[0]['text'])->toContain('rejected')
        ->and(str_contains($raw, 'payload@body.dev'))->toBeFalse()
        ->and(str_contains($raw, 'O:24:'))->toBeFalse()
        ->and(str_contains($raw, $this->dbPassword))->toBeFalse()
        ->and(str_contains($raw, 'Other failure'))->toBeFalse();
});

test('send rebuilds the bundle, keeps only the chosen lines and ignores text sent by the browser', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    file_put_contents($this->logDir . '/laravel-2026-11-14.log',
        dbLogEntry('2026-11-14 10:00:00', 'ERROR', 'First failure') . dbLogEntry('2026-11-14 11:00:00', 'CRITICAL', 'Second failure'));

    $lines = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'));
    $first = $lines->first(fn (array $l): bool => str_contains($l['text'], 'First failure'));
    $second = $lines->first(fn (array $l): bool => str_contains($l['text'], 'Second failure'));
    $php = $lines->firstWhere('key', 'php_version');
    expect($first)->not->toBeNull()->and($second)->not->toBeNull();

    $this->postJson(DB_BASE . '/Probe/support', [
        'subject' => 'Thing breaks',
        'description' => 'It fails when I save. Call me at jane.doe@example.com',
        'line_ids' => [$second['id'], $php['id'], 'ffffffffffffffff'],
        // Everything below is not part of the contract and must not reach the hub.
        'lines' => [['id' => $first['id'], 'text' => 'INJECTED-LINE']],
        'bundle' => 'INJECTED-BUNDLE',
        'extra' => 'INJECTED-EXTRA',
    ])->assertCreated()
        ->assertJsonPath('data.sent', true)
        ->assertJsonPath('data.lines', 2)
        ->assertJsonPath('data.ticket', '0193a2b4-5c6d-7e8f-9a0b-1c2d3e4f5a6b');

    expect($this->hub['requests'])->toHaveCount(1);
    $sent = $this->hub['requests'][0];
    expect($sent['method'])->toBe('POST')->and($sent['path'])->toBe('/api/v2/public/platform/support/Probe');
    $payload = json_decode($sent['body'], true);
    expect(array_keys($payload))->toBe(['subject', 'description', 'bundle'])
        ->and(array_keys($payload['bundle']))->toBe(['module', 'generated_at', 'lines'])
        ->and($payload['bundle']['lines'])->toBe([
            ['section' => 'environment', 'key' => 'php_version', 'text' => PHP_VERSION],
            ['section' => 'log', 'key' => null, 'text' => $second['text']],
        ])
        ->and($payload['description'])->not->toContain('jane.doe@example.com');
    foreach (['INJECTED', 'First failure', $this->appKey, $this->secret] as $absent) {
        expect(str_contains($sent['body'], $absent))->toBeFalse();
    }
});

test('send with no kept lines sends only the subject and the description', function () {
    dbAdmin();
    dbInstall($this->releaseDir);

    $this->postJson(DB_BASE . '/Probe/support', ['subject' => 'Question', 'description' => 'How do I start?', 'line_ids' => []])
        ->assertCreated()
        ->assertJsonPath('data.lines', 0);

    $payload = json_decode($this->hub['requests'][0]['body'], true);
    expect($payload['bundle']['lines'])->toBe([]);
});

test('only a Webmaster may preview or send, only for an installed module, with a validated request', function () {
    dbAdmin(false); // user id 1 is always a Webmaster
    dbAdmin(false);
    dbInstall($this->releaseDir);
    $this->getJson(DB_BASE . '/Probe/support/preview')->assertForbidden();
    $this->postJson(DB_BASE . '/Probe/support', ['subject' => 'x', 'description' => 'y', 'line_ids' => []])->assertForbidden();

    dbAdmin();
    $this->getJson(DB_BASE . '/Missing/support/preview')->assertNotFound()->assertJsonPath('error_code', 'MODULE_SUPPORT_NOT_INSTALLED');
    $this->postJson(DB_BASE . '/Missing/support', ['subject' => 'x', 'description' => 'y', 'line_ids' => []])->assertNotFound();
    $this->postJson(DB_BASE . '/Probe/support', ['subject' => '', 'description' => 'y', 'line_ids' => []])->assertUnprocessable();
    $this->postJson(DB_BASE . '/Probe/support', ['subject' => 'x', 'description' => 'y', 'line_ids' => ['not-an-id']])->assertUnprocessable();
    expect($this->hub['requests'])->toBe([]);
});

test('hub failures map to fixed codes and never pass the hub text on', function () {
    dbAdmin();
    dbInstall($this->releaseDir);

    $this->hub['status'] = 404;
    $this->hub['data'] = ['message' => 'hub-internal-detail'];
    $this->postJson(DB_BASE . '/Probe/support', ['subject' => 'x', 'description' => 'y', 'line_ids' => []])
        ->assertNotFound()
        ->assertJsonPath('error_code', 'MODULE_SUPPORT_NOT_FOUND');

    $this->hub['status'] = 500;
    $response = $this->postJson(DB_BASE . '/Probe/support', ['subject' => 'x', 'description' => 'y', 'line_ids' => []])
        ->assertStatus(502)
        ->assertJsonPath('error_code', 'MODULE_SUPPORT_UNAVAILABLE');
    expect($response->getContent())->not->toContain('hub-internal-detail');
});

test('an ended module licence on the hub maps to a plain renew message', function () {
    dbAdmin();
    dbInstall($this->releaseDir);

    // The hub's support intake answers 403 MODULE_RENEWAL_REQUIRED only when the module's grant has ended.
    $this->hub['status'] = 403;
    $this->hub['data'] = ['message' => 'hub-internal-detail', 'error_code' => 'MODULE_RENEWAL_REQUIRED', 'expired_at' => '2026-10-01T00:00:00+00:00'];
    $response = $this->postJson(DB_BASE . '/Probe/support', ['subject' => 'x', 'description' => 'y', 'line_ids' => []])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'MODULE_SUPPORT_RENEWAL_REQUIRED');
    expect($response->json('message'))->toContain('renew')->toContain('keeps working')
        ->and($response->getContent())->not->toContain('hub-internal-detail');
});

test('the log reader keeps the newest 50 matching entries and reads only the tail of the newest three files', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    // Oldest file: its entries are beyond the newest three files and must not appear.
    file_put_contents($this->logDir . '/laravel-2026-11-10.log', dbLogEntry('2026-11-10 08:00:00', 'ERROR', 'Ancient failure'));
    touch($this->logDir . '/laravel-2026-11-10.log', time() - 4000);
    foreach (['11', '12', '13'] as $i => $day) {
        $file = $this->logDir . '/laravel-2026-11-' . $day . '.log';
        $text = '';
        for ($n = 0; $n < 30; $n++) {
            $text .= dbLogEntry(sprintf('2026-11-%s 10:%02d:00', $day, $n), 'ERROR', 'Failure ' . $day . '-' . $n);
        }
        file_put_contents($file, $text);
        touch($file, time() - 3000 + $i * 1000);
    }

    $log = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->where('section', 'log')->values();

    expect($log)->toHaveCount(DiagnosticBundleBuilder::MAX_LOG_ENTRIES)
        ->and($log[0]['text'])->toContain('Failure 13-29')
        ->and($log->last()['text'])->toContain('Failure 12-10')
        ->and($log->pluck('text')->implode("\n"))->not->toContain('Ancient failure');
});

test('LogRedactor masks IP addresses and known values without touching times or method calls', function () {
    $redactor = new LogRedactor();

    expect($redactor->redactIps('from 203.0.113.77, ::1 and fe80::1%eth0 at 10:15:30 on 2026-11-14'))
        ->toBe('from [IP], [IP] and [IP] at 10:15:30 on 2026-11-14')
        ->and($redactor->redactIps('Cms\\Probe\\Thing::run() version 2026.3.0'))->toBe('Cms\\Probe\\Thing::run() version 2026.3.0')
        ->and($redactor->redactKnownValues('a=s3cr3t-value b=s3cr3t c={"u":"x\/y\/zzz"}', ['s3cr3t', 's3cr3t-value', 'x/y/zzz', '']))
        ->toBe('a=' . LogRedactor::MASK . ' b=' . LogRedactor::MASK . ' c={"u":"' . LogRedactor::MASK . '"}')
        ->and($redactor->redactString('Authorization: Bearer abcdefgh12345678'))->toBe('Authorization: Bearer ' . LogRedactor::MASK)
        ->and($redactor->redactString('Basic authentication failed'))->toBe('Basic authentication failed')
        ->and($redactor->redactKeyValuePairs('?api_key=abc123&page=2 {"password":"p\\"w","user":"an","prompt_tokens":5} token: xyz789'))
        ->toBe('?api_key=' . LogRedactor::MASK . '&page=2 {"password":"' . LogRedactor::MASK . '","user":"an","prompt_tokens":5} token: ' . LogRedactor::MASK);
});

test('only the last 2 MB of a log file are read', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $filler = '';
    $line = '[2026-11-14 09:00:00] production.INFO: ' . str_repeat('x', 200) . "\n";
    while (strlen($filler) <= DiagnosticBundleBuilder::MAX_TAIL_BYTES) {
        $filler .= $line;
    }
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 08:00:00', 'ERROR', 'Too old to read')
        . $filler . dbLogEntry('2026-11-14 10:00:00', 'ERROR', 'Recent failure'));

    $log = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->where('section', 'log')->values();

    expect($log)->toHaveCount(1)->and($log[0]['text'])->toContain('Recent failure');
});

test('harmless well-known .env values stay readable while secrets are still masked', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $message = 'Boot failed on sqlite with sync queue, local disk, debug true in production for key=' . $this->appKey . ' db=' . $this->dbPassword;
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', $message));

    $raw = $this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->getContent();
    $text = collect(json_decode($raw, true)['data']['lines'])->where('section', 'log')->first()['text'];

    expect($text)->toContain('Boot failed on sqlite with sync queue, local disk, debug true in production for')
        ->and(str_contains($raw, $this->appKey))->toBeFalse()
        ->and(str_contains($raw, $this->dbPassword))->toBeFalse()
        ->and($text)->toContain(LogRedactor::MASK);
});

test('a value of a secret-named key stays masked even when the word is harmless elsewhere', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    file_put_contents($this->envFile, "LICENSE_TOKEN=production\nAPP_SECRET=sqlite\nAPP_ENV=local\n");
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', 'Call failed with credential production and store sqlite'));

    $text = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->where('section', 'log')->first()['text'];

    expect($text)->toContain('Call failed with credential ' . LogRedactor::MASK . ' and store ' . LogRedactor::MASK);
});

test('a real secret of six or more characters is masked even when it looks like a harmless word', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    file_put_contents($this->envFile, "DB_PASSWORD=redis-pw1\nCACHE_DRIVER=file\n");
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', 'Cache file driver used password redis-pw1'));

    $text = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->where('section', 'log')->first()['text'];

    expect($text)->toContain('Cache file driver used password ' . LogRedactor::MASK)
        ->and(str_contains($text, 'redis-pw1'))->toBeFalse();
});

/*
 * (1): `.env` values are masked by key name and value shape, not by length alone. Ordinary settings (names,
 * hosts, database names, paths, time zones, URLs without credentials) stay readable; every value of a secret-named key
 * and every secret-shaped value (mixed letters and digits, symbols, long numbers, random-case words, URLs with
 * credentials, a query or a token-like path segment) is still masked, including the token part of such a URL alone.
 */
test('ordinary .env settings stay readable while secret-named and secret-shaped values are masked', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $token = 'Zq' . bin2hex(random_bytes(10)) . 'Xy';
    $hookToken = 'Hk' . bin2hex(random_bytes(12)) . 'Tz';
    $urlPassword = 'Up' . bin2hex(random_bytes(6));
    $randomCase = 'aBcDeFgHiJkLmNoP';
    $longNumber = '-1003652488266';
    $plainPassword = 'correcthorse';
    file_put_contents($this->envFile, implode("\n", [
        'APP_NAME="Probe Site"',
        'APP_URL=https://probe.example.test',
        'DB_HOST=localhost',
        'DB_DATABASE=probe_cms',
        'DB_USERNAME=probe_user',
        'MAIL_HOST=smtp.example.test',
        'LOG_CHANNEL=daily',
        'APP_TIMEZONE=Asia/Ho_Chi_Minh',
        'CACHE_STORE=database',
        'SESSION_DRIVER=database',
        'FILESYSTEM_DISK=public',
        'DB_CHARSET=utf8mb4',
        'UPLOAD_ROOT=/var/www/probe/shared',
        'DB_PORT=3306',
        'CUSTOM_REFERENCE=' . $token,
        'DISCORD_WEBHOOK_URL=https://discord.example.test/api/webhooks/123456789012345678/' . $hookToken,
        'DATABASE_URL=mysql://probe:' . $urlPassword . '@db.example.test/probe_cms',
        'WIDGET_STYLE=' . $randomCase,
        'TELEGRAM_CHAT_ID=' . $longNumber,
        'MAIL_PASSWORD=' . $plainPassword,
        '',
    ]));
    $message = 'Boot of Probe Site failed: https://probe.example.test via localhost db probe_cms as probe_user, mail smtp.example.test,'
        . ' channel daily, zone Asia/Ho_Chi_Minh, cache database, disk public, charset utf8mb4, root /var/www/probe/shared, port 3306;'
        . ' ref ' . $token . ' hook ' . $hookToken . ' url-pw ' . $urlPassword . ' style ' . $randomCase . ' chat ' . $longNumber
        . ' mail-pw ' . $plainPassword;
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', $message));

    $raw = $this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->getContent();
    $text = collect(json_decode($raw, true)['data']['lines'])->where('section', 'log')->first()['text'];

    expect($text)->toContain('Boot of Probe Site failed: https://probe.example.test via localhost db probe_cms as probe_user, mail smtp.example.test,')
        ->and($text)->toContain('channel daily, zone Asia/Ho_Chi_Minh, cache database, disk public, charset utf8mb4, root /var/www/probe/shared, port 3306;');
    foreach ([$token, $hookToken, $urlPassword, $randomCase, $longNumber, $plainPassword] as $secret) {
        expect(str_contains($raw, $secret))->toBeFalse('a secret .env value leaked: ' . substr($secret, 0, 3));
    }
});

test('the whole secret URL value is masked, not only its token part', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    $hookToken = 'Hk' . bin2hex(random_bytes(12)) . 'Tz';
    $hook = 'https://discord.example.test/api/webhooks/123456789012345678/' . $hookToken;
    file_put_contents($this->envFile, 'DISCORD_WEBHOOK_URL=' . $hook . "\nAPP_URL=https://probe.example.test/shop\n");
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', 'Post to ' . $hook . ' from https://probe.example.test/shop failed'));

    $text = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->where('section', 'log')->first()['text'];

    expect($text)->toContain('Post to ' . LogRedactor::MASK . ' from https://probe.example.test/shop failed')
        ->and(str_contains($text, '123456789012345678'))->toBeFalse();
});

test('an all-digit secret is masked too (numeric array keys used to drop it)', function () {
    dbAdmin();
    dbInstall($this->releaseDir);
    file_put_contents($this->envFile, "DB_PASSWORD=73915582\n");
    file_put_contents($this->logDir . '/laravel-2026-11-14.log', dbLogEntry('2026-11-14 10:15:30', 'ERROR', 'Login with 73915582 refused'));

    $text = collect($this->getJson(DB_BASE . '/Probe/support/preview')->assertOk()->json('data.lines'))->where('section', 'log')->first()['text'];

    expect($text)->toContain('Login with ' . LogRedactor::MASK . ' refused')
        ->and(str_contains($text, '73915582'))->toBeFalse();
});
