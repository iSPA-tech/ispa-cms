<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationRunner;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `http_request`: call an outside URL on the site's allowed hosts list through SafeHttpFetcher.
 *
 * Config: `method` (GET|HEAD|POST|PUT|PATCH|DELETE), `url`, `headers{}`, `query{}` (templates), `body_type`
 * (none|json|form|raw), `body`, `content_type` (raw), `auth` (none|header|basic) with `credential`
 * ($bind:<http_header|http_basic>), `timeout`, `max_bytes`, `retries` (0-3), `retry_delay_ms`, `follow_redirects`,
 * `response` (auto|json|text), `storeAs`.
 * Result: {status, ok, url, headers, body, bytes, redirects, tainted: true}. A 2xx answer leaves by `success`; any
 * other answer or a refused/failed call fails the step (the `error` output when it is wired, on_error = route), with
 * the result still stored under `storeAs`. Credentials are redacted from the result and logs (IntegrationRunner).
 * A credential goes only to the hosts saved on it (`allowed_hosts`); a credential without them is refused (fail closed,
 * also for credentials saved before the field existed), and a redirect to another origin drops it (SafeHttpFetcher).
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
class HttpRequestRunner extends IntegrationRunner
{
    private const KEPT_HEADERS = ['content-type', 'content-length', 'last-modified', 'etag', 'location', 'cache-control', 'date', 'retry-after'];

    protected function type(): string
    {
        return 'http_request';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $method = strtoupper((string) ($config['method'] ?? 'GET'));
        $url = $this->renderText($config['url'] ?? '', $context);
        $headers = $this->renderMap($config['headers'] ?? [], $context);
        $query = $this->renderMap($config['query'] ?? [], $context);
        $bodyType = (string) ($config['body_type'] ?? 'none');
        if (!in_array($bodyType, ['none', 'json', 'form', 'raw'], true)) {
            throw new IntegrationFailure('body_type must be none, json, form or raw.');
        }
        $body = $bodyType === 'none' ? null : $this->render($config['body'] ?? null, $context);
        if ($bodyType === 'json' && is_string($body)) {
            $decoded = json_decode($body, true);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
        }

        $options = [
            'method' => $method,
            'headers' => $headers,
            'query' => $query,
            'body_type' => $bodyType,
            'body' => $body,
            'content_type' => (string) ($config['content_type'] ?? 'text/plain'),
            'timeout' => $config['timeout'] ?? null,
            'max_bytes' => $config['max_bytes'] ?? null,
            'retries' => $config['retries'] ?? 0,
            'retry_delay_ms' => $config['retry_delay_ms'] ?? null,
            'follow_redirects' => filter_var($config['follow_redirects'] ?? true, FILTER_VALIDATE_BOOL),
        ];
        $options = array_filter($options, static fn ($v): bool => $v !== null);

        $auth = (string) ($config['auth'] ?? 'none');
        if ($auth === 'header') {
            $credential = $this->bind($config['credential'] ?? null, 'http_header');
            $name = (string) ($credential['header'] ?? '');
            $options['headers'][$name] = (string) ($credential['value'] ?? '');
            $options['sensitive_headers'] = [$name];
            $options['credential_hosts'] = $this->credentialHosts($config, $credential);
        } elseif ($auth === 'basic') {
            $credential = $this->bind($config['credential'] ?? null, 'http_basic');
            $options['basic_auth'] = [(string) ($credential['username'] ?? ''), (string) ($credential['password'] ?? '')];
            $options['credential_hosts'] = $this->credentialHosts($config, $credential);
        } elseif ($auth !== 'none' && $auth !== '') {
            throw new IntegrationFailure('auth must be none, header or basic.');
        }

        $response = app(SafeHttpFetcher::class)->fetch($url, $options);

        $result = [
            'status' => $response->status,
            'ok' => $response->successful(),
            'url' => $response->url,
            'headers' => array_intersect_key($response->headers, array_flip(self::KEPT_HEADERS)),
            'body' => $this->decodeBody($response, (string) ($config['response'] ?? 'auto')),
            'bytes' => strlen($response->body),
            'redirects' => $response->redirects,
            'tainted' => true,
        ];
        $this->store($config, $context, $result);

        $logs = [[
            'action' => 'http_request',
            'method' => $method,
            'host' => $response->host(),
            'status' => $response->status,
            'bytes' => strlen($response->body),
            'redirects' => $response->redirects,
            'attempts' => $response->attempts,
        ]];

        if (!$response->successful()) {
            return StepRunResult::failed("http_request: the server answered HTTP {$response->status}.", $logs, ['status' => $response->status]);
        }

        return StepRunResult::success($result, $logs)->withOutput('success');
    }

    /**
     * The hosts the bound credential may go to.
     *
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $credential
     * @return list<string>
     */
    private function credentialHosts(array $config, array $credential): array
    {
        $hosts = WorkflowCredentialStore::allowedHosts($credential);
        if ($hosts === []) {
            $name = (string) WorkflowCredentialStore::bindingName($config['credential'] ?? null);
            throw new IntegrationFailure("credential {$name} has no allowed hosts: add the hosts it may be sent to on the workflow credentials screen.");
        }

        return $hosts;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderMap(mixed $map, WorkflowContext $context): array
    {
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $rendered = $this->render($value, $context);
            $out[$key] = is_array($rendered) ? json_encode($rendered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $rendered;
        }

        return $out;
    }

    private function decodeBody(FetchResponse $response, string $mode): mixed
    {
        $wantsJson = $mode === 'json' || ($mode !== 'text' && str_contains($response->contentType(), 'json'));
        if ($wantsJson && $response->body !== '') {
            $decoded = json_decode($response->body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            if ($mode === 'json') {
                throw new IntegrationFailure('the answer is not valid JSON.');
            }
        }

        return $response->body;
    }
}
