<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Models\Role;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * OAuth connection flow (authorization code + PKCE) for Google Drive and OneDrive backup destinations.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const BOAUTH_GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
const BOAUTH_MS_TOKEN = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
const BOAUTH_REFRESH = 'rt-PLAIN-REFRESH-TOKEN-9f2c41';
const BOAUTH_ACCESS = 'at-PLAIN-ACCESS-TOKEN-77ab0e';
const BOAUTH_SECRET = 'cs-PLAIN-CLIENT-SECRET-31d8';
const BOAUTH_CALLBACK = '/api/v2/admin/backups/oauth/callback';
// Requests go to the canonical test host over HTTPS, as the other admin feature tests do.
const BOAUTH_HOST = 'https://localhost';

/**
 * Create an admin user (type ADMIN, no Webmaster role).
 */
function boauthUser(string $name): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => UserType::ADMIN,
        'level' => 0,
        'status' => null,
    ]);
}

/**
 * Create a destination for the given driver with a fake client id and client secret.
 */
function boauthDestination(string $driver = 'gdrive'): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'cloud ' . $driver . ' ' . Str::random(6),
        'driver' => $driver,
        'config' => ['client_id' => 'client-id-' . $driver],
        'credentials' => ['client_secret' => BOAUTH_SECRET],
    ]);
}

/**
 * base64url without padding.
 */
function boauthB64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * Parse the query string of a URL.
 *
 * @return array<string, string>
 */
function boauthQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

beforeEach(function (): void {
    config(['app.url' => 'https://cms.example.test/']);
    Http::preventStrayRequests();

    // User id 1 is always a Webmaster (User::isWebmaster), so take it with a filler account first.
    boauthUser('filler');
    $this->webmaster = boauthUser('webmaster');
    $role = Role::query()->create(['name' => 'Webmaster ' . Str::random(4), 'custom' => ['level' => 0]]);
    $this->webmaster->roles()->attach($role);
    $this->webmasterRole = $role;
    $this->manager = boauthUser('manager');
    $this->manager->roles()->attach(Role::query()->create(['name' => 'Manager ' . Str::random(4), 'custom' => ['level' => 50]]));

    expect($this->webmaster->fresh()->isWebmaster())->toBeTrue()
        ->and($this->manager->fresh()->isWebmaster())->toBeFalse();

    $this->logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logs[] = $event->message . ' ' . json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $this->startFor = function (BackupDestination $destination): string {
        Sanctum::actingAs($this->webmaster->fresh(), ['*']);
        $response = $this->postJson(BOAUTH_HOST . '/api/v2/admin/backups/destinations/' . $destination->uuid . '/oauth/start');
        $response->assertOk();

        return (string) $response->json('data.authorize_url');
    };

    $this->assertNoSecretsLeaked = function (array $haystacks): void {
        $all = implode("\n", array_merge($haystacks, $this->logs));
        expect($all)->not->toContain(BOAUTH_REFRESH)
            ->and($all)->not->toContain(BOAUTH_ACCESS)
            ->and($all)->not->toContain(BOAUTH_SECRET);
    };
});

test('start requires authentication', function (): void {
    $destination = boauthDestination();

    $this->postJson(BOAUTH_HOST . '/api/v2/admin/backups/destinations/' . $destination->uuid . '/oauth/start')->assertStatus(401);
});

test('start is refused for an admin who is not a Webmaster', function (): void {
    $destination = boauthDestination();
    Sanctum::actingAs($this->manager->fresh(), ['*']);

    $this->postJson(BOAUTH_HOST . '/api/v2/admin/backups/destinations/' . $destination->uuid . '/oauth/start')
        ->assertStatus(403)
        ->assertJsonPath('error_code', 'BACKUP_OAUTH_WEBMASTER_ONLY');
});

test('start is refused for a destination that is not Google Drive or OneDrive', function (): void {
    $destination = BackupDestination::query()->create(['name' => 's3', 'driver' => 's3', 'config' => []]);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $this->postJson(BOAUTH_HOST . '/api/v2/admin/backups/destinations/' . $destination->uuid . '/oauth/start')
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BACKUP_OAUTH_DRIVER_UNSUPPORTED');
});

test('start is refused when the destination has no client id', function (): void {
    $destination = BackupDestination::query()->create(['name' => 'g', 'driver' => 'gdrive', 'config' => []]);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $this->postJson(BOAUTH_HOST . '/api/v2/admin/backups/destinations/' . $destination->uuid . '/oauth/start')
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BACKUP_OAUTH_CLIENT_MISSING');
});

test('start answers 404 for an unknown destination', function (): void {
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $this->postJson(BOAUTH_HOST . '/api/v2/admin/backups/destinations/' . Str::uuid() . '/oauth/start')
        ->assertStatus(404)
        ->assertJsonPath('error_code', 'BACKUP_DESTINATION_NOT_FOUND');
});

test('the Google authorize URL carries the exact scope, offline access, PKCE and state', function (): void {
    $url = ($this->startFor)(boauthDestination('gdrive'));
    $query = boauthQuery($url);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['scope'])->toBe('https://www.googleapis.com/auth/drive.file')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('client-id-gdrive')
        ->and($query['redirect_uri'])->toBe('https://cms.example.test' . BOAUTH_CALLBACK)
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['state'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($url)->not->toContain(BOAUTH_SECRET);

    $second = boauthQuery(($this->startFor)(boauthDestination('gdrive')));
    expect($second['state'])->not->toBe($query['state'])
        ->and($second['code_challenge'])->not->toBe($query['code_challenge']);
});

test('the Microsoft authorize URL uses the common tenant and the app folder scope', function (): void {
    $url = ($this->startFor)(boauthDestination('onedrive'));
    $query = boauthQuery($url);

    expect($url)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?')
        ->and($query['scope'])->toBe('Files.ReadWrite.AppFolder offline_access')
        ->and($query['response_type'])->toBe('code')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['state'])->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

test('a successful callback stores only the encrypted refresh token', function (): void {
    Http::fake([BOAUTH_GOOGLE_TOKEN => Http::response([
        'access_token' => BOAUTH_ACCESS, 'refresh_token' => BOAUTH_REFRESH, 'expires_in' => 3599,
        'scope' => 'https://www.googleapis.com/auth/drive.file', 'token_type' => 'Bearer',
    ])]);
    $destination = boauthDestination('gdrive');
    $destination->forceFill(['last_error_code' => 'BACKUP_OAUTH_RECONNECT'])->save();
    $query = boauthQuery(($this->startFor)($destination));

    $response = $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1');

    $response->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=ok');

    Http::assertSent(function (HttpRequest $request) use ($query): bool {
        $data = $request->data();

        return $request->url() === BOAUTH_GOOGLE_TOKEN
            && $data['grant_type'] === 'authorization_code'
            && $data['code'] === 'auth-code-1'
            && $data['client_id'] === 'client-id-gdrive'
            && $data['client_secret'] === BOAUTH_SECRET
            && $data['redirect_uri'] === 'https://cms.example.test' . BOAUTH_CALLBACK
            && boauthB64(hash('sha256', (string) $data['code_verifier'], true)) === $query['code_challenge'];
    });

    $raw = (string) DB::table(CoreTableConstant::BACKUP_DESTINATIONS)->where('id', $destination->id)->value('credentials');
    $row = json_encode(DB::table(CoreTableConstant::BACKUP_DESTINATIONS)->where('id', $destination->id)->first());
    $fresh = $destination->fresh();

    expect($raw)->not->toContain(BOAUTH_REFRESH)
        ->and($row)->not->toContain(BOAUTH_ACCESS)
        ->and($fresh->credentials)->toBe(['client_secret' => BOAUTH_SECRET, 'refresh_token' => BOAUTH_REFRESH])
        ->and($fresh->last_error_code)->toBeNull();

    ($this->assertNoSecretsLeaked)([(string) $response->getContent(), (string) $response->headers->get('Location')]);

    // The access token from the exchange is cached, so the drivers get it without another token call.
    expect(app(OAuthConnector::class)->accessToken($fresh))->toBe(BOAUTH_ACCESS);
    Http::assertSentCount(1);
});

test('a callback with an unknown state fails without calling the token endpoint', function (): void {
    Http::fake();
    boauthDestination('gdrive');

    $response = $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . boauthB64(random_bytes(32)) . '&code=auth-code-1');

    $response->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    Http::assertNothingSent();
});

test('a callback without a state fails without calling the token endpoint', function (): void {
    Http::fake();

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    Http::assertNothingSent();
});

test('a callback with an expired state fails without calling the token endpoint', function (): void {
    Http::fake();
    $query = boauthQuery(($this->startFor)(boauthDestination('gdrive')));

    $this->travel(11)->minutes();

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    Http::assertNothingSent();
});

test('a state works only once', function (): void {
    Http::fake([BOAUTH_GOOGLE_TOKEN => Http::response([
        'access_token' => BOAUTH_ACCESS, 'refresh_token' => BOAUTH_REFRESH, 'expires_in' => 3599,
    ])]);
    $query = boauthQuery(($this->startFor)(boauthDestination('gdrive')));

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=ok');
    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-2')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');

    Http::assertSentCount(1);
});

test('a state whose issuing user is no longer a Webmaster fails without calling the token endpoint', function (): void {
    Http::fake();
    $query = boauthQuery(($this->startFor)(boauthDestination('gdrive')));

    $this->webmaster->roles()->detach($this->webmasterRole->id);

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    Http::assertNothingSent();
});

test('a state whose issuing user was deleted fails without calling the token endpoint', function (): void {
    Http::fake();
    $query = boauthQuery(($this->startFor)(boauthDestination('gdrive')));

    $this->webmaster->delete();

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    Http::assertNothingSent();
});

test('a state whose destination changed driver fails without calling the token endpoint', function (): void {
    Http::fake();
    $destination = boauthDestination('gdrive');
    $query = boauthQuery(($this->startFor)($destination));

    $destination->forceFill(['driver' => 'onedrive'])->save();

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    Http::assertNothingSent();
});

test('a provider error on the callback fails, burns the state and is not echoed', function (): void {
    Http::fake();
    $query = boauthQuery(($this->startFor)(boauthDestination('gdrive')));

    $response = $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&error=access_denied&error_description=USER-SAID-NO');

    $response->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    expect((string) $response->headers->get('Location'))->not->toContain('USER-SAID-NO');
    Http::assertNothingSent();
});

test('a failed code exchange redirects without echoing the provider error or storing anything', function (): void {
    Http::fake([BOAUTH_GOOGLE_TOKEN => Http::response([
        'error' => 'invalid_request', 'error_description' => 'PROVIDER-DETAIL ' . BOAUTH_REFRESH,
    ], 400)]);
    $destination = boauthDestination('gdrive');
    $query = boauthQuery(($this->startFor)($destination));

    $response = $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1');

    $response->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');
    expect((string) $response->headers->get('Location'))->not->toContain('PROVIDER-DETAIL')
        ->and($destination->fresh()->credentials)->toBe(['client_secret' => BOAUTH_SECRET])
        ->and(implode("\n", $this->logs))->toContain('BACKUP_OAUTH_EXCHANGE_FAILED')
        ->and(implode("\n", $this->logs))->not->toContain('PROVIDER-DETAIL');
    ($this->assertNoSecretsLeaked)([(string) $response->getContent()]);
});

test('an exchange without a refresh token fails and stores nothing', function (): void {
    Http::fake([BOAUTH_MS_TOKEN => Http::response(['access_token' => BOAUTH_ACCESS, 'expires_in' => 3600])]);
    $destination = boauthDestination('onedrive');
    $query = boauthQuery(($this->startFor)($destination));

    $this->get(BOAUTH_HOST . BOAUTH_CALLBACK . '?state=' . $query['state'] . '&code=auth-code-1')
        ->assertRedirect('https://cms.example.test/iPanel/setting/system/backups?oauth=failed');

    expect($destination->fresh()->credentials)->toBe(['client_secret' => BOAUTH_SECRET]);
});

test('an access token is refreshed once and then served from the cache', function (): void {
    Http::fake([BOAUTH_MS_TOKEN => Http::response([
        'access_token' => BOAUTH_ACCESS, 'refresh_token' => 'rt-ROTATED-REFRESH-TOKEN', 'expires_in' => 3600,
    ])]);
    $destination = boauthDestination('onedrive');
    $destination->credentials = ['client_secret' => BOAUTH_SECRET, 'refresh_token' => BOAUTH_REFRESH];
    $destination->save();

    $connector = app(OAuthConnector::class);
    expect($connector->accessToken($destination))->toBe(BOAUTH_ACCESS)
        ->and($connector->accessToken($destination->fresh()))->toBe(BOAUTH_ACCESS);

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === BOAUTH_MS_TOKEN
        && $request->data()['grant_type'] === 'refresh_token'
        && $request->data()['refresh_token'] === BOAUTH_REFRESH
        && $request->data()['scope'] === 'Files.ReadWrite.AppFolder offline_access');

    $fresh = $destination->fresh();
    expect($fresh->credentials)->toBe(['client_secret' => BOAUTH_SECRET, 'refresh_token' => 'rt-ROTATED-REFRESH-TOKEN'])
        ->and(json_encode(DB::table(CoreTableConstant::BACKUP_DESTINATIONS)->where('id', $destination->id)->first()))
        ->not->toContain(BOAUTH_ACCESS);

    // After the cached token is dropped (for example on a 401), the next call refreshes again.
    $connector->forgetAccessToken($fresh);
    $connector->accessToken($fresh);
    Http::assertSentCount(2);
});

test('invalid_grant on refresh marks the destination for reconnection', function (): void {
    Http::fake([BOAUTH_GOOGLE_TOKEN => Http::response([
        'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.',
    ], 400)]);
    $destination = boauthDestination('gdrive');
    $destination->credentials = ['client_secret' => BOAUTH_SECRET, 'refresh_token' => BOAUTH_REFRESH];
    $destination->save();

    expect(fn () => app(OAuthConnector::class)->accessToken($destination))
        ->toThrow(RuntimeException::class, 'BACKUP_OAUTH_RECONNECT');

    expect($destination->fresh()->last_error_code)->toBe('BACKUP_OAUTH_RECONNECT');
    ($this->assertNoSecretsLeaked)([]);
});

test('a destination that was never connected asks for reconnection without calling the token endpoint', function (): void {
    Http::fake();
    $destination = boauthDestination('gdrive');

    expect(fn () => app(OAuthConnector::class)->accessToken($destination))
        ->toThrow(RuntimeException::class, 'BACKUP_OAUTH_RECONNECT');
    Http::assertNothingSent();
});
