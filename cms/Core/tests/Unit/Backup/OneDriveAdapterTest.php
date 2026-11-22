<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationRegistry;
use Cms\Core\Services\Backup\Destinations\OneDriveAdapter;
use Cms\Core\Services\Backup\OAuth\MicrosoftOAuthProvider;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToWriteFile;

/*
 * OneDrive backup destination (hand-written Flysystem adapter on the Http client, Microsoft Graph app folder).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const ODA_TOKEN_URL = MicrosoftOAuthProvider::TOKEN_URL;
const ODA_GRAPH = 'https://graph.microsoft.com/v1.0/me/drive/special/approot';
const ODA_SESSION = 'https://upload.example.test/onedrive/session/abc';

/**
 * Create a connected OneDrive destination with generated fake credentials.
 */
function odaDestination(): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'onedrive ' . bin2hex(random_bytes(3)),
        'driver' => 'onedrive',
        'config' => ['client_id' => 'cid-' . bin2hex(random_bytes(4))],
        'credentials' => ['client_secret' => 'cs-' . bin2hex(random_bytes(6)), 'refresh_token' => 'rt-' . bin2hex(random_bytes(6))],
    ]);
}

/**
 * Build an adapter with a small chunk size.
 */
function odaAdapter(BackupDestination $destination, int $chunk = 4): OneDriveAdapter
{
    return new OneDriveAdapter($destination, new OAuthConnector(), $chunk);
}

/**
 * Open a memory stream holding the contents.
 *
 * @return resource
 */
function odaStream(string $contents)
{
    $h = fopen('php://memory', 'w+b');
    fwrite($h, $contents);
    rewind($h);

    return $h;
}

/**
 * Fake the token endpoint with one token per entry.
 *
 * @param array<int, string> $tokens
 */
function odaTokens(array $tokens = ['at-1']): mixed
{
    $seq = Http::sequence();
    foreach ($tokens as $t) {
        $seq->push(['access_token' => $t, 'expires_in' => 3600]);
    }

    return $seq;
}

/**
 * Fake the upload session creation plus the session URL with queued PUT responses.
 *
 * @param array<int, HttpRequest> $log
 * @param array<int, mixed> $putResponses
 */
function odaFakeUpload(array &$log, array $putResponses): void
{
    $put = $putResponses;
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        ODA_GRAPH . '*' => function (HttpRequest $r) use (&$log) {
            $log[] = $r;

            return Http::response(['uploadUrl' => ODA_SESSION], 200);
        },
        ODA_SESSION => function (HttpRequest $r) use (&$log, &$put) {
            $log[] = $r;

            return array_shift($put) ?? Http::response(['id' => 'x'], 201);
        },
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

it('uses a default chunk size that Graph accepts', function (): void {
    expect(OneDriveAdapter::DEFAULT_CHUNK % 327680)->toBe(0)
        ->and(OneDriveAdapter::DEFAULT_CHUNK)->toBe(10485760)
        ->and(OneDriveAdapter::CHUNK_UNIT)->toBe(327680);
});

it('creates an upload session under the app folder and sends exact chunk ranges', function (): void {
    $log = [];
    odaFakeUpload($log, [Http::response('', 202), Http::response('', 202), Http::response(['id' => 'f'], 201)]);
    odaAdapter(odaDestination())->writeStream('app/site/2026-11-06/a b.sql.gz', odaStream('0123456789'), new Config());

    $init = $log[0];
    expect($init->method())->toBe('POST')
        ->and($init->url())->toBe(ODA_GRAPH . ':/app/site/2026-11-06/a%20b.sql.gz:/createUploadSession')
        ->and($init->data()['item']['@microsoft.graph.conflictBehavior'])->toBe('replace');

    $puts = array_values(array_filter($log, fn (HttpRequest $r) => $r->method() === 'PUT'));
    expect(array_map(fn ($r) => $r->header('Content-Range')[0], $puts))->toBe(['bytes 0-3/10', 'bytes 4-7/10', 'bytes 8-9/10'])
        ->and(array_map(fn ($r) => $r->body(), $puts))->toBe(['0123', '4567', '89']);
});

it('never sends the Authorization header to the upload url', function (): void {
    $log = [];
    odaFakeUpload($log, [Http::response('', 202), Http::response(['id' => 'f'], 201)]);
    odaAdapter(odaDestination())->write('a.txt', 'abcdef', new Config());

    $init = $log[0];
    $puts = array_values(array_filter($log, fn (HttpRequest $r) => $r->method() === 'PUT'));
    expect($init->header('Authorization')[0])->toBe('Bearer at-1')
        ->and(count($puts))->toBe(2);
    foreach ($puts as $put) {
        expect($put->hasHeader('Authorization'))->toBeFalse();
    }
});

it('retries one failed chunk once', function (): void {
    $log = [];
    odaFakeUpload($log, [Http::response('', 500), Http::response('', 202), Http::response(['id' => 'f'], 201)]);
    odaAdapter(odaDestination())->write('a.txt', 'abcdef', new Config());

    $puts = array_values(array_filter($log, fn (HttpRequest $r) => $r->method() === 'PUT'));
    expect(array_map(fn ($r) => $r->header('Content-Range')[0], $puts))->toBe(['bytes 0-3/6', 'bytes 0-3/6', 'bytes 4-5/6']);
});

it('retries a chunk after a connection error and gives up after the second failure', function (): void {
    $put = 0;
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        ODA_GRAPH . '*' => Http::response(['uploadUrl' => ODA_SESSION]),
        ODA_SESSION => function () use (&$put) {
            $put++;
            if ($put === 1) {
                throw new ConnectionException('boom');
            }

            return Http::response('', 503);
        },
    ]);

    try {
        odaAdapter(odaDestination())->write('a.txt', 'abcdef', new Config());
        $this->fail('expected exception');
    } catch (UnableToWriteFile $e) {
        expect($e->getMessage())->toContain('BACKUP_ONEDRIVE_HTTP_503');
    }
    expect($put)->toBe(2);
});

it('fails cleanly when the upload session is gone', function (): void {
    $log = [];
    odaFakeUpload($log, [Http::response('', 404)]);

    try {
        odaAdapter(odaDestination())->write('a.txt', 'abcdef', new Config());
        $this->fail('expected exception');
    } catch (UnableToWriteFile $e) {
        expect($e->getMessage())->toContain('BACKUP_ONEDRIVE_HTTP_404');
    }
});

it('reports metadata and not-found', function (): void {
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        ODA_GRAPH . ':/d/a.txt' => Http::response(['name' => 'a.txt', 'size' => 42, 'lastModifiedDateTime' => '2026-11-06T10:00:00Z']),
        ODA_GRAPH . ':/d/zzz.txt' => Http::response('', 404),
    ]);
    $adapter = odaAdapter(odaDestination());

    expect($adapter->fileExists('d/a.txt'))->toBeTrue()
        ->and($adapter->fileExists('d/zzz.txt'))->toBeFalse()
        ->and($adapter->fileSize('d/a.txt')->fileSize())->toBe(42)
        ->and($adapter->lastModified('d/a.txt')->lastModified())->toBe(strtotime('2026-11-06T10:00:00Z'));
});

it('lists one folder following nextLink paging', function (): void {
    $next = 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/d:/children?$skiptoken=p2';
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        $next => Http::response(['value' => [['name' => 'b.txt', 'size' => 2]]]),
        ODA_GRAPH . ':/d:/children' => Http::response([
            'value' => [['name' => 'a.txt', 'size' => 1, 'lastModifiedDateTime' => '2026-11-06T10:00:00Z'], ['name' => 'sub', 'folder' => ['childCount' => 1]]],
            '@odata.nextLink' => $next,
        ]),
    ]);

    $items = iterator_to_array(odaAdapter(odaDestination())->listContents('d', false), false);
    expect(array_map(fn ($i) => $i->path(), $items))->toBe(['d/a.txt', 'd/sub', 'd/b.txt'])
        ->and($items[1])->toBeInstanceOf(DirectoryAttributes::class)
        ->and($items[0]->fileSize())->toBe(1);
});

it('lists the app folder root through the children endpoint', function (): void {
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        ODA_GRAPH . '/children' => Http::response(['value' => [['name' => 'root.txt', 'size' => 3]]]),
    ]);

    $items = iterator_to_array(odaAdapter(odaDestination())->listContents('', false), false);
    expect(array_map(fn ($i) => $i->path(), $items))->toBe(['root.txt']);
});

it('deletes by path and tolerates a 404', function (): void {
    $log = [];
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        ODA_GRAPH . ':/d/a.txt' => function (HttpRequest $r) use (&$log) {
            $log[] = $r;

            return Http::response('', 204);
        },
        ODA_GRAPH . ':/d/gone.txt' => Http::response('', 404),
    ]);
    $adapter = odaAdapter(odaDestination());
    $adapter->delete('d/a.txt');
    $adapter->delete('d/gone.txt');

    expect($log[0]->method())->toBe('DELETE');
});

it('reads a file through the content endpoint', function (): void {
    Http::fake([
        ODA_TOKEN_URL => odaTokens(),
        ODA_GRAPH . ':/d/a.txt:/content' => fn () => Http::response('hello world', 200),
    ]);
    $adapter = odaAdapter(odaDestination());

    expect($adapter->read('d/a.txt'))->toBe('hello world');
    $h = $adapter->readStream('d/a.txt');
    expect(stream_get_contents($h))->toBe('hello world');
    fclose($h);
});

it('refreshes the token once on a 401 and then succeeds', function (): void {
    $auth = [];
    Http::fake([
        ODA_TOKEN_URL => Http::sequence()->push(['access_token' => 'at-old', 'expires_in' => 3600])->push(['access_token' => 'at-new', 'expires_in' => 3600]),
        ODA_GRAPH . '*' => function (HttpRequest $r) use (&$auth) {
            $auth[] = $r->header('Authorization')[0];

            return count($auth) === 1 ? Http::response('', 401) : Http::response('', 404);
        },
    ]);

    expect(odaAdapter(odaDestination())->fileExists('a.txt'))->toBeFalse()
        ->and($auth)->toBe(['Bearer at-old', 'Bearer at-new'])
        ->and(count(Http::recorded(fn (HttpRequest $r) => $r->url() === ODA_TOKEN_URL)))->toBe(2);
});

it('fails with BACKUP_OAUTH_RECONNECT after a second 401 without leaking tokens', function (): void {
    $destination = odaDestination();
    Http::fake([
        ODA_TOKEN_URL => Http::sequence()->push(['access_token' => 'at-LEAK-1', 'expires_in' => 3600])->push(['access_token' => 'at-LEAK-2', 'expires_in' => 3600]),
        ODA_GRAPH . '*' => Http::response('', 401),
    ]);

    try {
        odaAdapter($destination)->fileExists('a.txt');
        $this->fail('expected exception');
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('BACKUP_OAUTH_RECONNECT')
            ->and($e->getMessage())->not->toContain('at-LEAK');
    }
    expect($destination->fresh()->last_error_code)->toBe('BACKUP_OAUTH_RECONNECT');
});

it('requires a reconnect when the refresh token is rejected with invalid_grant', function (): void {
    $destination = odaDestination();
    Http::fake([
        ODA_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    try {
        odaAdapter($destination)->write('a.txt', 'abc', new Config());
        $this->fail('expected exception');
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('BACKUP_OAUTH_RECONNECT');
    }
    expect($destination->fresh()->last_error_code)->toBe('BACKUP_OAUTH_RECONNECT');
    Http::assertNotSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://graph.microsoft.com'));
});

it('refuses unsupported operations', function (): void {
    expect(fn () => odaAdapter(odaDestination())->copy('a', 'b', new Config()))->toThrow(UnableToCopyFile::class);
});

it('registers the onedrive driver', function (): void {
    $registry = new DestinationRegistry();
    $destination = odaDestination();

    expect($registry->has('onedrive'))->toBeTrue()
        ->and($registry->get('onedrive')->validate([], []))->toBe(['BACKUP_OAUTH_CLIENT_MISSING'])
        ->and($registry->get('onedrive')->diskConfig($destination))->toBe(['driver' => 'onedrive', 'destination_uuid' => (string) $destination->uuid, 'throw' => true]);
});
