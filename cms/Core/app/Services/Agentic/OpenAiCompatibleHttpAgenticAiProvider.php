<?php

namespace Cms\Core\Services\Agentic;

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiCompatibleHttpAgenticAiProvider implements AgenticAiProviderInterface
{
    private const REQUEST_CONTROL_KEYS = [
        'request_timeout_seconds',
        'request_connect_timeout_seconds',
        'request_max_attempts',
    ];

    public function __construct(
        protected Client $client,
        protected string $providerName,
    ) {}

    /**
     * Provider name this instance talks to (e.g. deepseek, grok).
     */
    public function providerName(): string
    {
        return $this->providerName;
    }

    public function createCompletion(array $payload = []): array
    {
        $httpOptions = $this->httpOptions($payload);
        $maxAttempts = max(1, min(5, (int) ($payload['request_max_attempts'] ?? 1)));
        $payload = $this->openAiParameters($this->normalizePayload($this->stripRequestControls($payload)));
        $samplingRetried = false;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->client->post('chat/completions', [
                    'json' => $payload,
                    ...$httpOptions,
                ]);

                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    Log::error('Non-2xx AI provider response', [
                        'provider' => $this->providerName,
                        'status_code' => $response->getStatusCode(),
                        'request' => $this->safePayloadSummary($payload),
                    ]);

                    return [];
                }

                return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $status = $exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null;
                $errorCodes = $exception instanceof RequestException
                    ? $this->providerErrorCodes((string) $exception->getResponse()?->getBody())
                    : null;

                // A model that only accepts the default sampling value rejects it with 400 + `param`; resend once
                // without it (400s are not billed) instead of failing every request of that task.
                $rejectedParam = $errorCodes['param'] ?? null;
                if ($status === 400 && ! $samplingRetried && in_array($rejectedParam, OpenAiModelParameters::SAMPLING_PARAMS, true) && array_key_exists($rejectedParam, $payload)) {
                    unset($payload[$rejectedParam]);
                    $samplingRetried = true;
                    $attempt--;
                    continue;
                }

                $retryable = $exception instanceof ConnectException || $status === 429 || ($status !== null && $status >= 500);
                if ($retryable && $attempt < $maxAttempts) {
                    usleep(500_000 * $attempt);
                    continue;
                }

                Log::error('AI provider completion failed', [
                    'provider' => $this->providerName,
                    'error_type' => $exception::class,
                    'status_code' => $status,
                    'provider_error' => $errorCodes,
                    'attempts' => $attempt,
                    'request' => $this->safePayloadSummary($payload),
                ]);

                return [];
            }
        }
    }

    /**
     * Keep the request valid for the provider and model it targets (see OpenAiModelParameters).
     *
     * `reasoning_effort` is an OpenAI parameter and is removed for the other OpenAI-compatible providers.
     *
     * @param array<string, mixed> $payload Chat completions payload.
     * @return array<string, mixed>
     */
    private function openAiParameters(array $payload): array
    {
        if ($this->providerName !== 'openai') {
            unset($payload['reasoning_effort']);

            return $payload;
        }

        return OpenAiModelParameters::normalize($payload);
    }

    public function getContent(array $response = []): string
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return collect($content)
                ->map(static fn (mixed $block): string => is_array($block) ? (string) ($block['text'] ?? '') : '')
                ->implode('');
        }

        return '';
    }

    /**
     * Machine-readable error codes from an OpenAI-style error body (`error.type`, `error.code`, `error.param`).
     *
     * Free text (`error.message`) and anything else in the body is never logged: it can echo request content.
     * Only short identifier-like values are kept, so a broken schema shows up as `param: response_format`.
     *
     * @param string $body Raw response body.
     * @return array<string, string>|null
     */
    private function providerErrorCodes(string $body): ?array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (! is_array($error)) {
            return null;
        }

        $codes = [];
        foreach (['type', 'code', 'param'] as $field) {
            $value = $error[$field] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.\-\[\]]{1,64}$/', $value) === 1) {
                $codes[$field] = $value;
            }
        }

        return $codes !== [] ? $codes : null;
    }

    protected function normalizePayload(array $payload): array
    {
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(array $payload): array
    {
        $options = [];

        if (isset($payload['request_timeout_seconds']) && is_numeric($payload['request_timeout_seconds'])) {
            $options['timeout'] = max(1.0, (float) $payload['request_timeout_seconds']);
        }

        if (isset($payload['request_connect_timeout_seconds']) && is_numeric($payload['request_connect_timeout_seconds'])) {
            $options['connect_timeout'] = max(1.0, (float) $payload['request_connect_timeout_seconds']);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function stripRequestControls(array $payload): array
    {
        foreach (self::REQUEST_CONTROL_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function safePayloadSummary(array $payload): array
    {
        return [
            'model' => $payload['model'] ?? null,
            'message_count' => count((array) ($payload['messages'] ?? [])),
            'response_format_type' => data_get($payload, 'response_format.type'),
        ];
    }
}
