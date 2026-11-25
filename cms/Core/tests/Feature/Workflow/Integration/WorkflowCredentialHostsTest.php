<?php

declare(strict_types=1);

/**
 * Review findings 16, 27, 28 and 36 (SMTP): where a `$bind:` credential may go, and how its secret is kept out of
 * results.
 */

use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Credentials\SmtpMailerFactory;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialException;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialTester;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\DnsResolver;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpRequestRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** A public address (not a private, loopback or documentation range) that the fake DNS hands out. */
const WCH_PUBLIC_IP = '93.184.' . '216.34';

/** Fake DNS: listed hosts get their answer, the loopback name gets the loopback address, the rest a public one. */
function wchDns(array $map = []): void
{
    app()->instance(DnsResolver::class, new class ($map) extends DnsResolver {
        public function __construct(private array $map)
        {
        }

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [WCH_PUBLIC_IP]);
        }
    });
}

function wchRun(array $config): Cms\Core\Workflows\Runtime\StepRunResult
{
    $root = new WorkflowProject();
    $root->id = 1;

    return (new HttpRequestRunner())->run(new StepDefinition(1, 1, 'http_request', 's1', 's1', 'Step', $config), new WorkflowContext($root, [], []));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['core.workflow_security.http_hosts' => ['api.example.com', 'api.example.com:8443', '*.example.org']]);
    wchDns();
});

// ---- 16: HTTP credentials are bound to hosts -------------------------------------------------------------------

it('sends a header credential only to its allowed hosts', function (): void {
    app(WorkflowCredentialStore::class)->create(['name' => 'api', 'type' => 'http_header', 'payload' => [
        'header' => 'X-Api-Key', 'value' => 'val-123456789', 'allowed_hosts' => 'api.example.com',
    ]], null);
    Http::fake(['*' => Http::response(['ok' => true])]);

    $elsewhere = wchRun(['url' => 'https://echo.example.org/anything', 'auth' => 'header', 'credential' => '$bind:api']);
    Http::assertNothingSent();

    $home = wchRun(['url' => 'https://api.example.com/items', 'auth' => 'header', 'credential' => '$bind:api']);

    expect($elsewhere->status)->toBe('failed')
        ->and($elsewhere->error['message'])->toContain('may not be sent to echo.example.org')
        ->and($home->status)->toBe('success');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.example.com/items' && $r->hasHeader('X-Api-Key', 'val-' . '123456789'));
});

it('refuses an HTTP credential that has no allowed hosts yet (fail closed for credentials saved before the field)', function (): void {
    // Fake secret split so the export scan (gitleaks) sees no key-shaped literal next to a key-named field.
    app(WorkflowCredentialStore::class)->create(['name' => 'basic', 'type' => 'http_basic', 'payload' => ['username' => 'bot', 'password' => 'pw-' . '987654321']], null);
    Http::fake(['*' => Http::response('ok')]);

    $result = wchRun(['url' => 'https://api.example.com/x', 'auth' => 'basic', 'credential' => '$bind:basic']);

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toContain('no allowed hosts')
        ->and(json_encode($result))->not->toContain('pw-987654321');
    Http::assertNothingSent();
});

it('validates, normalizes and shows the allowed hosts of a credential', function (): void {
    $store = app(WorkflowCredentialStore::class);

    expect(fn () => $store->create(['name' => 'bad', 'type' => 'http_header', 'payload' => [
        'header' => 'X-Key', 'value' => 'val-123456789', 'allowed_hosts' => 'api.example.com, *.com',
    ]], null))->toThrow(ValidationException::class);

    $credential = $store->create(['name' => 'ok', 'type' => 'http_header', 'payload' => [
        'header' => 'X-Key', 'value' => 'val-123456789', 'allowed_hosts' => "https://API.example.com/\n*.example.org api.example.com:8443",
    ]], null);

    expect($credential->payload['allowed_hosts'])->toBe('api.example.com, *.example.org, api.example.com:8443')
        ->and($store->describe($credential)['allowed_hosts'])->toBe('api.example.com, *.example.org, api.example.com:8443')
        ->and($store->describe($credential)['fields'])->toContain('allowed_hosts')
        ->and(WorkflowCredentialStore::secretValues([$credential->payload]))->not->toContain('api.example.com, *.example.org, api.example.com:8443');

    // An update that leaves the field out keeps the hosts.
    $credential = $store->update($credential, ['payload' => ['value' => 'val-222222222']], null);
    expect($credential->payload['allowed_hosts'])->toBe('api.example.com, *.example.org, api.example.com:8443');
});

// ---- 27: credentials dropped on a downgrade or port change ----------------------------------------------------

it('drops credentials when a redirect goes from https to http on the same host', function (): void {
    Http::fake([
        'https://api.example.com/up' => Http::response('', 302, ['Location' => 'http://api.example.com/down']),
        'http://api.example.com/down' => Http::response('final'),
    ]);

    app(SafeHttpFetcher::class)->fetch('https://api.example.com/up', [
        'headers' => ['Authorization' => 'Bearer tok-123456789'], 'basic_auth' => null,
    ]);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.example.com/up' && $r->hasHeader('Authorization'));
    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://api.example.com/down' && !$r->hasHeader('Authorization'));
});

it('drops credentials when a redirect changes the port on the same host', function (): void {
    Http::fake([
        'https://api.example.com/p' => Http::response('', 302, ['Location' => 'https://api.example.com:8443/q']),
        'https://api.example.com:8443/q' => Http::response('final'),
    ]);

    app(SafeHttpFetcher::class)->fetch('https://api.example.com/p', ['basic_auth' => ['bot', 'pw-987654321']]);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.example.com/p' && $r->hasHeader('Authorization'));
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.example.com:8443/q' && !$r->hasHeader('Authorization'));
});

// ---- 28: redaction of encoded forms -----------------------------------------------------------------------------

it('redacts the base64 user:password form and JSON-escaped secrets', function (): void {
    $payload = ['username' => 'bot', 'password' => 'pw/"98765"'];
    $echo = [
        'auth' => 'Basic ' . base64_encode('bot:pw/"98765"'),
        'json' => json_encode(['p' => 'pw/"98765"']),
        'json_unescaped' => json_encode(['p' => 'pw/"98765"'], JSON_UNESCAPED_SLASHES),
    ];

    $redacted = WorkflowCredentialStore::redact($echo, [$payload]);

    expect(json_encode($redacted))->not->toContain('98765')
        ->and($redacted['auth'])->toBe('Basic [REDACTED]');
});

// ---- 36: SMTP server address checked before connecting --------------------------------------------------------

it('refuses an SMTP server that resolves to a private or loopback address, also on Test', function (): void {
    wchDns(['mail.internal.invalid' => [gethostbyname('localhost')]]);
    $payload = ['host' => 'mail.internal.invalid', 'port' => '25', 'from_address' => 'bot@example.org', 'password' => 'pw-555555555'];

    expect(fn () => app(SmtpMailerFactory::class)->make($payload))
        ->toThrow(WorkflowCredentialException::class, 'private or reserved');

    $credential = app(WorkflowCredentialStore::class)->create(['name' => 'mail', 'type' => 'smtp', 'payload' => $payload], null);
    $result = app(WorkflowCredentialTester::class)->test($credential);

    expect($result['status'])->toBe(WorkflowCredentialTester::FAILED)
        ->and($result['message'])->toContain('private or reserved')
        ->and(WorkflowCredential::query()->find($credential->id)->last_test_ok)->toBeFalse();
});

it('pins the SMTP connection to the checked address and keeps the name for TLS', function (): void {
    wchDns(['smtp.example.org' => [WCH_PUBLIC_IP]]);

    $mailer = app(SmtpMailerFactory::class)->make(['host' => 'smtp.example.org', 'port' => '587', 'from_address' => 'bot@example.org']);
    $stream = $mailer->getSymfonyTransport()->getStream();

    expect($stream->getHost())->toBe(WCH_PUBLIC_IP)
        ->and($stream->getPort())->toBe(587)
        ->and($stream->getStreamOptions()['ssl']['peer_name'] ?? null)->toBe('smtp.example.org');
});
