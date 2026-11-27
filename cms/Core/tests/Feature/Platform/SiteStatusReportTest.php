<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Platform\SiteStatusReport;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
});

/**
 * Configure a platform domain, secret and hub URL for the signed client.
 */
function platformReportConfigure(string $secret): void
{
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);
}

test('build returns exactly the allowlisted top-level keys in order', function () {
    expect(array_keys((new SiteStatusReport())->build()))->toBe([
        'schema', 'domain', 'app_version', 'release', 'revision', 'deployed_at', 'php_version', 'laravel_version',
        'activator', 'modules', 'pending_migrations', 'health', 'backups', 'runtime_collisions',
    ])->and((new SiteStatusReport())->build()['schema'])->toBe(1);
});

test('every module row says whether it comes from the release or the runtime folder', function () {
    $modules = (new SiteStatusReport())->build()['modules'];

    expect($modules)->not->toBeEmpty();
    foreach ($modules as $row) {
        expect(array_keys($row))->toBe(['name', 'enabled', 'version', 'source', 'revoked'])
            ->and($row['source'])->toBeIn(['release', 'runtime', null]);
    }
    $core = collect($modules)->firstWhere('name', 'Core');
    expect($core['source'])->toBe('release');
});

test('no configured secret, env value or path appears in the report', function () {
    $secrets = [
        'cfg-domain-secret-' . Str::random(12),
        'cfg-app-key-' . Str::random(12),
        'cfg-db-pass-' . Str::random(12),
        'cfg-mail-pass-' . Str::random(12),
        'cfg-service-key-' . Str::random(12),
        'env-secret-' . Str::random(12),
    ];
    config([
        'ispa-cms.cms_domain_secret' => $secrets[0],
        'app.key' => $secrets[1],
        'database.connections.mysql.password' => $secrets[2],
        'database.connections.sqlite.password' => $secrets[2],
        'mail.mailers.smtp.password' => $secrets[3],
        'services.stripe.key' => $secrets[4],
        'services.openai.key' => $secrets[4],
    ]);
    putenv('PLATFORM_REPORT_FAKE_SECRET=' . $secrets[5]);
    $_ENV['PLATFORM_REPORT_FAKE_SECRET'] = $secrets[5];

    try {
        $json = json_encode((new SiteStatusReport())->build());
    } finally {
        putenv('PLATFORM_REPORT_FAKE_SECRET');
        unset($_ENV['PLATFORM_REPORT_FAKE_SECRET']);
    }

    foreach ($secrets as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect($json)->not->toContain(str_replace('/', '\/', base_path()))
        ->and($json)->not->toContain(base_path());
});

test('pending migrations count grows with a new migration file and names carry no path', function () {
    $before = (new SiteStatusReport())->build()['pending_migrations'];

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platform-report-' . Str::random(8);
    mkdir($dir);
    $name = '0001_01_01_000000_platform_report_probe';
    file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.php', "<?php\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {};\n");

    try {
        app('migrator')->path($dir);
        $after = (new SiteStatusReport())->build()['pending_migrations'];
    } finally {
        @unlink($dir . DIRECTORY_SEPARATOR . $name . '.php');
        @rmdir($dir);
    }

    expect($after['count'])->toBe($before['count'] + 1)
        ->and($after['names'])->toContain($name)
        ->and(json_encode($after['names']))->not->toContain(DIRECTORY_SEPARATOR . 'platform-report-');
});

test('the push is signed and posts the report to the hub reports endpoint', function () {
    $secret = Str::random(40);
    platformReportConfigure($secret);
    Http::fake(function (ClientRequest $sent) use ($secret) {
        $body = '{"ok":true}';
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0] ?? '';

        return Http::response($body, 200, [
            'Content-Type' => 'application/json',
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body),
        ]);
    });

    expect(Artisan::call('cms:platform-report'))->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $r) use ($secret) {
        $data = json_decode($r->body(), true);

        return $r->url() === 'https://hub.test/api/v2/public/platform/reports'
            && $r->method() === 'POST'
            && $r->hasHeader(PlatformSignature::HEADER_DOMAIN, 'site.example.test')
            && $r->hasHeader(PlatformSignature::HEADER_TIMESTAMP)
            && $r->hasHeader(PlatformSignature::HEADER_NONCE)
            && $r->hasHeader(PlatformSignature::HEADER_SIGNATURE)
            && ($data['schema'] ?? null) === 1
            && ! str_contains($r->body(), $secret);
    });
});

test('dry-run prints the JSON report and sends nothing', function () {
    platformReportConfigure(Str::random(40));

    expect(Artisan::call('cms:platform-report', ['--dry-run' => true]))->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['schema'])->toBe(1)->and($decoded['domain'])->toBe('site.example.test');
    Http::assertNothingSent();
});

test('an unconfigured site succeeds without sending anything', function () {
    config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '']);

    expect(Artisan::call('cms:platform-report'))->toBe(0);
    Http::assertNothingSent();
});

test('app_version is the core manifest version after an admin upgrade, VERSION without a manifest', function () {
    // A temp tree root, so a real core-manifest.json (a release install has one) is never read or overwritten.
    $base = sys_get_temp_dir() . '/site-status-manifest-' . bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $path = $base . '/core-manifest.json';
    expect(file_exists($path))->toBeFalse();
    config(['ispa-cms.core_manifest_base' => $base, 'ispa-cms.app_version' => '2026.11.05']);
    $report = new SiteStatusReport();

    expect($report->build()['app_version'])->toBe('2026.11.05')
        ->and($report->payload(SiteStatusReport::LEVEL_BASIC)['app_version'])->toBe('2026.11.05');

    // An admin Core upgrade on a CI-deployed site rewrites the manifest but never VERSION (deploy-owned).
    file_put_contents($path, json_encode([
        'format' => 1, 'version' => '2026.11.07', 'php_min' => '8.2', 'origin' => 'deploy',
        'upgraded_from' => '2026.11.05', 'checked' => (object) [], 'replaced' => [],
    ]));
    try {
        expect($report->build()['app_version'])->toBe('2026.11.07')
            ->and($report->payload(SiteStatusReport::LEVEL_BASIC)['app_version'])->toBe('2026.11.07');

        file_put_contents($path, '{broken');
        expect($report->build()['app_version'])->toBe('2026.11.05');
    } finally {
        @unlink($path);
        @rmdir($base);
    }
});
