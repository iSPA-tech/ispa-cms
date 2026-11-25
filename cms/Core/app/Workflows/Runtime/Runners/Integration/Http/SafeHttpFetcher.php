<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The one way workflow nodes reach an outside URL (http_request, rss_read, sitemap_read, media_import).
 *
 * Every hop (the first URL and each redirect) is checked before any connection:
 * 1. scheme http/https only, no user:password@, no control characters or backslashes;
 * 2. host on the site allowlist (HttpHostAllowlist), port 80/443 unless the entry names the port;
 * 3. the host resolves (DnsResolver) and EVERY address is public (IpRangeGuard: no private, loopback, link-local,
 *    metadata, reserved, IPv6 ULA/link-local); the connection is pinned to the checked address (CURLOPT_RESOLVE) so a
 *    second DNS answer cannot swap in a private address. The pin only works with the curl handler, so the request is
 *    always sent through Guzzle's CurlHandler and never with `stream => true` (that selects the PHP stream handler,
 *    which ignores every curl option and resolves the name again).
 * Redirects are followed by hand (Guzzle redirects off), at most 3, each re-checked; credentials and cookies are dropped
 * when a redirect leaves the original origin (another host, another port, or https to http). A `credential_hosts`
 * option (the hosts a `$bind:` credential may go to) refuses a hop that would carry the credential anywhere else.
 * The body is aborted as soon as it passes `max_bytes` (curl progress callback; Content-Length is checked first).
 * `timeout` is a wall-clock limit per attempt (curl total timeout, not an idle timeout), and the whole call, all
 * redirects and retries included, ends after `timeout` x (retries + 1) seconds. Connection failures and
 * 429/502/503/504 answers are retried up to `retries` times.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
class SafeHttpFetcher
{
    public const MAX_REDIRECTS = 3;

    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const RETRY_STATUSES = [429, 502, 503, 504];

    private const FORBIDDEN_HEADERS = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer', 'expect',
        'keep-alive', 'proxy-authorization', 'proxy-connection',
    ];

    private const CROSS_HOST_DROP = ['authorization', 'cookie', 'proxy-authorization'];

    public function __construct(
        private readonly HttpHostAllowlist $allowlist,
        private readonly DnsResolver $dns,
    ) {
    }

    public static function settings(string $key, mixed $default = null): mixed
    {
        return config('core.workflow_nodes.fetch.settings.' . $key, $default);
    }

    public static function hardMaxBytes(): int
    {
        return max(1024, (int) self::settings('hard_max_bytes', 10 * 1024 * 1024));
    }

    /**
     * @param array{
     *     method?: string, headers?: array<string, mixed>, query?: array<string, mixed>, body?: mixed,
     *     body_type?: string, content_type?: string, timeout?: int, max_bytes?: int, retries?: int,
     *     retry_delay_ms?: int, follow_redirects?: bool, basic_auth?: array{0: string, 1: string}|null,
     *     sensitive_headers?: list<string>, credential_hosts?: list<string>|null
     * } $options
     *
     * @throws FetchRefused
     */
    public function fetch(string $url, array $options = []): FetchResponse
    {
        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        if (!in_array($method, self::METHODS, true)) {
            throw new FetchRefused('method', "method {$method} is not allowed.");
        }

        $maxBytes = max(1, min(self::hardMaxBytes(), (int) ($options['max_bytes'] ?? self::settings('default_max_bytes', 2 * 1024 * 1024))));
        $timeout = max(1, min((int) self::settings('max_timeout_seconds', 60), (int) ($options['timeout'] ?? self::settings('default_timeout_seconds', 20))));
        $retries = max(0, min(3, (int) ($options['retries'] ?? 0)));
        $retryDelayMs = max(0, min(5000, (int) ($options['retry_delay_ms'] ?? 500)));
        $follow = (bool) ($options['follow_redirects'] ?? true);
        $headers = $this->cleanHeaders((array) ($options['headers'] ?? []));
        $sensitive = array_map('strtolower', array_merge(self::CROSS_HOST_DROP, (array) ($options['sensitive_headers'] ?? [])));
        $basicAuth = $options['basic_auth'] ?? null;
        $bodyType = (string) ($options['body_type'] ?? 'none');
        $body = $options['body'] ?? null;
        $credentialHosts = isset($options['credential_hosts']) ? array_values((array) $options['credential_hosts']) : null;
        $deadline = microtime(true) + $timeout * ($retries + 1);

        $url = $this->withQuery($url, (array) ($options['query'] ?? []));
        $firstOrigin = null;
        $redirects = 0;
        $attemptsTotal = 0;

        while (true) {
            $target = $this->check($url);
            // Same origin = same scheme, host and port: a redirect to http, to another port or to another host loses
            // the credentials and cookies for the rest of the call.
            $origin = $target['scheme'] . '://' . $target['host'] . ':' . $target['port'];
            $firstOrigin ??= $origin;
            if ($origin !== $firstOrigin) {
                $headers = array_filter($headers, static fn (string $name): bool => !in_array(strtolower($name), $sensitive, true), ARRAY_FILTER_USE_KEY);
                $basicAuth = null;
                $credentialHosts = null;
            }
            if ($credentialHosts !== null && !HttpHostAllowlist::matchesAny($credentialHosts, $target['host'], $target['port'])) {
                throw new FetchRefused('credential_host', "the credential may not be sent to {$target['host']}.");
            }

            [$psr, $attempts] = $this->sendWithRetry($method, $target, $headers, $basicAuth, $bodyType, $body, $options, $timeout, $retries, $retryDelayMs, $deadline);
            $attemptsTotal += $attempts;
            $status = $psr->getStatusCode();

            if ($follow && in_array($status, [301, 302, 303, 307, 308], true) && $psr->hasHeader('Location')) {
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new FetchRefused('too_many_redirects', 'more than ' . self::MAX_REDIRECTS . ' redirects.');
                }
                $url = $this->resolveLocation($url, $psr->getHeaderLine('Location'));
                if ($status === 303 || (in_array($status, [301, 302], true) && !in_array($method, ['GET', 'HEAD'], true))) {
                    $method = 'GET';
                    $bodyType = 'none';
                    $body = null;
                }
                continue;
            }

            return new FetchResponse(
                status: $status,
                headers: $this->responseHeaders($psr),
                body: $method === 'HEAD' ? '' : $this->readBody($psr, $maxBytes),
                url: $url,
                redirects: $redirects,
                attempts: $attemptsTotal,
            );
        }
    }

    /**
     * Validate a URL hop and resolve its host. Public so callers can check a URL without fetching it.
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string}
     *
     * @throws FetchRefused
     */
    public function check(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new FetchRefused('url', 'the URL is empty or not valid.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FetchRefused('url', 'the URL is not valid.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new FetchRefused('scheme', 'only http and https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchRefused('url', 'URLs with a user name or password are not allowed.');
        }

        $host = HttpHostAllowlist::normalizeHost($parts['host']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($host === '') {
            throw new FetchRefused('url', 'the URL has no host.');
        }
        if (!$this->allowlist->allows($host, $port)) {
            if ($this->allowlist->knowsHost($host)) {
                throw new FetchRefused('port_not_allowed', "port {$port} of {$host} is not on the allowed hosts list.");
            }
            throw new FetchRefused('host_not_allowed', "{$host} is not on the allowed hosts list.");
        }

        $addresses = $this->dns->resolve($host);
        if ($addresses === []) {
            throw new FetchRefused('dns_failed', "{$host} could not be resolved.");
        }
        foreach ($addresses as $address) {
            if (!$this->addressAllowed($address)) {
                throw new FetchRefused('blocked_address', "{$host} points to a private or reserved address.");
            }
        }
        $ip = $this->preferredAddress($addresses);

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ip];
    }

    /**
     * @param array{url: string, scheme: string, host: string, port: int, ip: string} $target
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array{0: ResponseInterface, 1: int}
     */
    private function sendWithRetry(string $method, array $target, array $headers, ?array $basicAuth, string $bodyType, mixed $body, array $options, int $timeout, int $retries, int $retryDelayMs, float $deadline): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $left = $deadline - microtime(true);
            if ($left <= 0.05) {
                throw new FetchRefused('connection', 'the call took longer than its time limit.');
            }
            $limit = new \stdClass();
            $limit->exceeded = false;
            try {
                $response = $this->send($method, $target, $headers, $basicAuth, $bodyType, $body, $options, min((float) $timeout, $left), $limit);
                if ($attempt <= $retries && in_array($response->status(), self::RETRY_STATUSES, true)) {
                    $this->pause($retryDelayMs);
                    continue;
                }

                return [$response->toPsrResponse(), $attempt];
            } catch (ConnectionException $e) {
                if ($limit->exceeded) {
                    throw new FetchRefused('too_large', 'the response is larger than ' . $this->hardMaxBytesFor($options) . ' bytes.');
                }
                if ($attempt <= $retries) {
                    $this->pause($retryDelayMs);
                    continue;
                }
                throw new FetchRefused('connection', 'the connection failed: ' . mb_substr($e->getMessage(), 0, 200));
            } catch (FetchRefused $e) {
                throw $e;
            } catch (\Illuminate\Http\Client\RequestException $e) {
                if ($limit->exceeded) {
                    throw new FetchRefused('too_large', 'the response is larger than ' . $this->hardMaxBytesFor($options) . ' bytes.');
                }
                throw new FetchRefused('connection', 'the request failed: ' . mb_substr($e->getMessage(), 0, 200));
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                if ($limit->exceeded) {
                    throw new FetchRefused('too_large', 'the response is larger than ' . $this->hardMaxBytesFor($options) . ' bytes.');
                }
                if ($attempt <= $retries) {
                    $this->pause($retryDelayMs);
                    continue;
                }
                throw new FetchRefused(str_contains($e->getMessage(), 'too large') ? 'too_large' : 'connection', 'the request failed: ' . mb_substr($e->getMessage(), 0, 200));
            }
        }
    }

    /**
     * @param array{url: string, scheme: string, host: string, port: int, ip: string} $target
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @param \stdClass $limit `exceeded` turns true when the body passed max_bytes and the transfer was aborted
     */
    private function send(string $method, array $target, array $headers, ?array $basicAuth, string $bodyType, mixed $body, array $options, float $timeout, \stdClass $limit): Response
    {
        $resolveIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $maxBytes = $this->hardMaxBytesFor($options);
        $curl = [
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolveIp],
            // Abort the transfer as soon as the body passes max_bytes (a non-zero return stops curl), also when the
            // server sends no Content-Length.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($handle, int $downloadTotal, int $downloaded) use ($maxBytes, $limit): int {
                if ($downloaded > $maxBytes || $downloadTotal > $maxBytes) {
                    $limit->exceeded = true;

                    return 1;
                }

                return 0;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        /** @var PendingRequest $request */
        $request = Http::withOptions([
            'allow_redirects' => false,
            // Never through an environment proxy (HTTP_PROXY / HTTPS_PROXY): the request must go to the checked IP.
            // An empty proxy string makes curl ignore the proxy environment variables.
            'proxy' => ['http' => '', 'https' => '', 'no' => []],
            'protocols' => ['http', 'https'],
            'curl' => $curl,
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes, $limit): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                    $limit->exceeded = true;
                    throw new RuntimeException('response too large');
                }
            },
        ])
            ->connectTimeout(min(10.0, $timeout))
            ->timeout($timeout)
            ->withHeaders($headers + ['User-Agent' => (string) self::settings('user_agent', 'iSPA-CMS-Workflow/1.0')]);
        // The pin (CURLOPT_RESOLVE), the protocol limit and the progress abort exist only in curl: never let Guzzle
        // pick another handler. Http::fake() still answers first (its stub sits above the handler).
        $request->setHandler(new CurlHandler());

        if (is_array($basicAuth) && count($basicAuth) === 2) {
            $request = $request->withBasicAuth((string) $basicAuth[0], (string) $basicAuth[1]);
        }

        $url = $target['url'];

        return match ($bodyType) {
            'json' => $request->asJson()->send($method, $url, ['json' => $body]),
            'form' => $request->asForm()->send($method, $url, ['form_params' => is_array($body) ? $body : []]),
            'raw' => $request->withBody(is_string($body) ? $body : (string) json_encode($body), (string) ($options['content_type'] ?? 'text/plain'))->send($method, $url),
            default => $request->send($method, $url),
        };
    }

    /** @param array<string, mixed> $options */
    private function hardMaxBytesFor(array $options): int
    {
        return max(1, min(self::hardMaxBytes(), (int) ($options['max_bytes'] ?? self::settings('default_max_bytes', 2 * 1024 * 1024))));
    }

    private function readBody(ResponseInterface $psr, int $maxBytes): string
    {
        $stream = $psr->getBody();
        if ($stream->isSeekable() && $stream->tell() !== 0) {
            $stream->rewind();
        }
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                $stream->close();
                throw new FetchRefused('too_large', "the response is larger than {$maxBytes} bytes.");
            }
        }

        return $body;
    }

    /** @return array<string, string> */
    private function responseHeaders(ResponseInterface $psr): array
    {
        $out = [];
        foreach ($psr->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);
            if ($lower === 'set-cookie') {
                continue;
            }
            $out[$lower] = mb_substr(implode(', ', $values), 0, 2000);
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     *
     * @throws FetchRefused
     */
    private function cleanHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || $value === null) {
                continue;
            }
            if (!preg_match("/^[A-Za-z0-9!#$%&'*+.^_`|~-]{1,100}$/", $name)) {
                throw new FetchRefused('url', "header name {$name} is not valid.");
            }
            $lower = strtolower($name);
            if (in_array($lower, self::FORBIDDEN_HEADERS, true) || str_starts_with($lower, 'proxy-')) {
                throw new FetchRefused('url', "header {$name} cannot be set.");
            }
            $value = is_scalar($value) ? (string) $value : (string) json_encode($value);
            if (preg_match('/[\r\n\0]/', $value)) {
                throw new FetchRefused('url', "header {$name} has a line break.");
            }
            $out[$name] = mb_substr($value, 0, 8000);
        }

        return $out;
    }

    /** @param array<string, mixed> $query */
    private function withQuery(string $url, array $query): string
    {
        $query = array_filter($query, static fn ($v): bool => $v !== null);
        if ($query === []) {
            return $url;
        }
        $fragmentless = explode('#', $url, 2)[0];

        return $fragmentless . (str_contains($fragmentless, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws FetchRefused
     */
    private function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location)) {
            throw new FetchRefused('redirect_invalid', 'the redirect target is not valid.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';

        return $origin . $dir . $location;
    }

    /**
     * Whether the connection may go to this address (IpRangeGuard). Tests that talk to a local listener override it;
     * production code never does.
     */
    protected function addressAllowed(string $ip): bool
    {
        return IpRangeGuard::isPublic($ip);
    }

    /** @param list<string> $addresses */
    private function preferredAddress(array $addresses): string
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    private function pause(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
