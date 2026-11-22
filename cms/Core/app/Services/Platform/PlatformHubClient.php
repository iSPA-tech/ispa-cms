<?php

namespace Cms\Core\Services\Platform;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Site-side client for the platform hub. Every request carries an HMAC
 * signature keyed by the domain secret; the secret itself is never sent.
 * Every answer must carry a valid hub response signature.
 */
final class PlatformHubClient
{
    private readonly string $domain;

    private readonly string $secret;

    private readonly string $baseUrl;

    /**
     * @param  string|null  $domain  Registered site domain; defaults to HubCredentials (`.env` first, then the encrypted database row).
     * @param  string|null  $secret  Domain secret; defaults to HubCredentials (`.env` first, then the encrypted database row).
     * @param  string|null  $baseUrl  Hub base URL; defaults to config ispa-cms.platform_hub_url.
     * @param  int  $connectTimeout  Connect timeout in seconds.
     * @param  int  $timeout  Total request timeout in seconds.
     *
     * @throws PlatformHubException When not configured or the URL is not secure.
     */
    public function __construct(
        ?string $domain = null,
        #[\SensitiveParameter] ?string $secret = null,
        ?string $baseUrl = null,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
    ) {
        if ($domain === null || $secret === null) {
            $credentials = app(HubCredentials::class);
            $domain ??= $credentials->domain();
            $secret ??= $credentials->secret();
        }
        $this->domain = trim($domain);
        $this->secret = $secret;
        if ($this->domain === '' || $this->secret === '') {
            throw new PlatformHubException(PlatformHubException::NOT_CONFIGURED, 'Platform hub domain or secret is not configured.');
        }

        $this->baseUrl = rtrim((string) ($baseUrl ?? config('ispa-cms.platform_hub_url', 'https://ispa.tech')), '/');
        self::assertSecureUrl($this->baseUrl);
    }

    /**
     * Send a signed GET request and return the verified, decoded JSON answer.
     *
     * @param  array<array-key, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws PlatformHubException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, '');
    }

    /**
     * Send a signed GET request and return the verified raw body (an image, for example) with its content type.
     *
     * The response signature covers the exact bytes, so a body the hub did not sign is refused like a JSON answer.
     *
     * @param  array<array-key, mixed>  $query
     * @return array{body: string, content_type: ?string}
     *
     * @throws PlatformHubException
     */
    public function getBinary(string $path, array $query = []): array
    {
        [$response, $nonce] = $this->dispatch('GET', $path, $query, '');
        $this->verifiedBody($response, $nonce);

        $type = $response->header('Content-Type');

        return ['body' => $response->body(), 'content_type' => $type !== '' ? $type : null];
    }

    /**
     * Send a signed POST request with a JSON body and return the verified, decoded JSON answer.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PlatformHubException
     */
    public function post(string $path, array $payload = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->send('POST', $path, [], $body);
    }

    /**
     * Send a signed PUT request with a JSON body and return the verified, decoded JSON answer.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PlatformHubException
     */
    public function put(string $path, array $payload = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->send('PUT', $path, [], $body);
    }

    /**
     * Sign, send, and verify one request.
     *
     * @param  array<array-key, mixed>  $query
     * @return array<array-key, mixed>
     */
    private function send(string $method, string $path, array $query, string $body): array
    {
        [$response, $nonce] = $this->dispatch($method, $path, $query, $body);

        return $this->verifiedPayload($response, $nonce);
    }

    /**
     * Sign and send one request; the answer is not checked yet.
     *
     * @param  array<array-key, mixed>  $query
     * @return array{0: Response, 1: string} The response and the request nonce its signature is bound to.
     */
    private function dispatch(string $method, string $path, array $query, string $body): array
    {
        $url = $this->baseUrl.PlatformSignature::normalizePath($path);
        $sortedQuery = PlatformSignature::canonicalQuery($query);
        if ($sortedQuery !== '') {
            $url .= '?'.$sortedQuery;
        }

        $timestamp = Carbon::now()->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $signature = PlatformSignature::request(
            $this->secret,
            $method,
            (string) parse_url($url, PHP_URL_PATH),
            $sortedQuery,
            $timestamp,
            $nonce,
            $body,
        );

        $pending = Http::withoutRedirecting()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->accept('application/json')
            ->withHeaders([
                PlatformSignature::HEADER_DOMAIN => $this->domain,
                PlatformSignature::HEADER_TIMESTAMP => (string) $timestamp,
                PlatformSignature::HEADER_NONCE => $nonce,
                PlatformSignature::HEADER_SIGNATURE => $signature,
            ]);
        if ($method !== 'GET') {
            $pending = $pending->withBody($body, 'application/json');
        }

        try {
            $response = $pending->send($method, $url);
        } catch (ConnectionException $e) {
            throw new PlatformHubException(PlatformHubException::TRANSPORT, 'Platform hub could not be reached.');
        }

        return [$response, $nonce];
    }

    /**
     * Check status and response signature, then decode the JSON body.
     *
     * @return array<array-key, mixed>
     */
    private function verifiedPayload(Response $response, string $nonce): array
    {
        $body = $this->verifiedBody($response, $nonce);
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new PlatformHubException(PlatformHubException::BAD_RESPONSE, 'Platform hub response is not a JSON object.', $response->status());
        }

        return $decoded;
    }

    /**
     * Check status and response signature and return the raw body.
     */
    private function verifiedBody(Response $response, string $nonce): string
    {
        $status = $response->status();
        if ($status >= 300 && $status < 400) {
            throw new PlatformHubException(PlatformHubException::REDIRECT, 'Platform hub answered with a redirect; it was not followed.', $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new PlatformHubException(PlatformHubException::HTTP_STATUS, 'Platform hub answered with HTTP '.$status.'.', $status);
        }

        $body = $response->body();
        $provided = (string) $response->header(PlatformSignature::HEADER_RESPONSE_SIGNATURE);
        $expected = PlatformSignature::response($this->secret, $status, $nonce, $body);
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new PlatformHubException(PlatformHubException::BAD_SIGNATURE, 'Platform hub response signature is missing or invalid.', $status);
        }

        return $body;
    }

    /**
     * Require https, except plain http to localhost/127.0.0.1 in local or testing. Also used for the unsigned one-tap
     * connect calls (HubOneTapConnect), which must meet the same rule.
     *
     * @throws PlatformHubException
     */
    public static function assertSecureUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme === 'https' && $host !== '') {
            return;
        }
        if ($scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1'], true)
            && in_array(config('app.env'), ['local', 'testing'], true)) {
            return;
        }

        throw new PlatformHubException(PlatformHubException::INSECURE_URL, 'Platform hub URL must use https.');
    }
}
