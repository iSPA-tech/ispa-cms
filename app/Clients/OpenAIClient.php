<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Clients;

use Cms\Core\Services\Agentic\OpenAiModelParameters;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class OpenAIClient
{
    private const REQUEST_CONTROL_KEYS = [
        'request_timeout_seconds',
        'request_connect_timeout_seconds',
        'request_max_attempts',
    ];

    private const DEFAULT_MAX_ATTEMPTS = 3;

    private const DEFAULT_BACKOFF_MS = 750;

    private const TRANSIENT_STATUS_CODES = [408, 409, 425, 429, 500, 502, 503, 504];

    private Client $client;

    private int $maxAttempts;

    private int $backoffMs;

    /**
     * Create the OpenAI HTTP client wrapper.
     *
     * The optional client/backoff arguments exist so tests can inject a Guzzle
     * mock client without changing production bindings. Production keeps using
     * the default OpenAI endpoint and retries transient upstream failures.
     *
     * @param Client|null $client Optional preconfigured Guzzle client.
     * @param int|null $maxAttempts Maximum attempts for transient upstream errors.
     * @param int|null $backoffMs Base backoff in milliseconds between attempts.
     */
    public function __construct(?Client $client = null, ?int $maxAttempts = null, ?int $backoffMs = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 120,         // 2 min per request (prevents hang forever)
            'connect_timeout' => 10,  // 10s to establish connection
            'headers' => [
                'Authorization' => 'Bearer ' . (string) config('services.openai.key'),
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->maxAttempts = max(1, $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS);
        $this->backoffMs = max(0, $backoffMs ?? self::DEFAULT_BACKOFF_MS);
    }

    /**
     * Create a completion
     *
     * @param array $body
     *
     * @return array
     * @throws GuzzleException
     * @since 7.160.1175 - 2024-09-18, 19:49 ICT
     * @author malayvuong
     */
    public function createCompletion(array $body = []): array
    {
        $requestControls = $this->extractRequestControls($body);
        // GPT-5.x / o-series reject non-default temperature and max_tokens; keep older models unchanged.
        $body = OpenAiModelParameters::normalize($this->stripRequestControls($body));

        try {
            $response = $this->postWithTransientRetry('chat/completions', [
                'json' => $body,
                ...$requestControls['http_options'],
            ], $body, $requestControls['max_attempts']);

            // Check for response status code
            if ($response->getStatusCode() !== 200) {
                Log::error('Non-200 response from OpenAI', [
                    'status_code' => $response->getStatusCode(),
                    'request' => $this->summarizeCompletionPayload($body),
                ]);
                return [];
            }

            $responseContent = $response->getBody()->getContents();

            return json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            Log::error('OpenAI completion request failed.', [
                'error_type' => $e::class,
                'status_code' => $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null,
                'request' => $this->summarizeCompletionPayload($body),
            ]);
            return [];
        }
    }

    /**
     * Create an embedding vector.
     *
     * @return array<int, float>
     */
    public function createEmbedding(string $input, ?string $model = null): array
    {
        return $this->createEmbeddingResponse($input, $model)['data'][0]['embedding'] ?? [];
    }

    /**
     * Create an embedding and return the full decoded response (including usage).
     *
     * @return array<string, mixed> Decoded response, or an empty array on failure.
     */
    public function createEmbeddingResponse(string $input, ?string $model = null): array
    {
        try {
            $response = $this->postWithTransientRetry('embeddings', [
                'json' => [
                    'model' => $model ?: 'text-embedding-3-small',
                    'input' => $input,
                ],
            ], ['model' => $model ?: 'text-embedding-3-small', 'input_length' => mb_strlen($input)]);

            if ($response->getStatusCode() !== 200) {
                Log::error('Non-200 response from OpenAI embeddings', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getBody()->getContents(),
                ]);

                return [];
            }

            $responseContent = $response->getBody()->getContents();
            $decoded = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Exception $e) {
            Log::error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'input_length' => mb_strlen($input),
                'model' => $model ?: 'text-embedding-3-small',
            ]);

            return [];
        }
    }

    /**
     * POST to OpenAI with bounded retries for transient upstream failures.
     *
     * OpenAI can occasionally return 429/5xx responses during load. Guzzle
     * raises those as RequestException before the caller sees a response, so
     * retrying here protects all agentic call sites consistently.
     *
     * @param string $uri OpenAI API path.
     * @param array<string, mixed> $options Guzzle request options.
     * @param array<string, mixed> $logContext Safe request context for logging.
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws GuzzleException
     */
    private function postWithTransientRetry(
        string $uri,
        array $options,
        array $logContext,
        ?int $maxAttempts = null,
    ): ResponseInterface
    {
        $attempt = 0;
        $maxAttempts = max(1, $maxAttempts ?? $this->maxAttempts);

        while (true) {
            $attempt++;

            try {
                return $this->client->post($uri, $options);
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode();
                if (!$this->shouldRetry($statusCode, $attempt, $maxAttempts)) {
                    throw $e;
                }

                $this->logTransientRetry($e, $uri, $attempt, $maxAttempts, $statusCode, $logContext);
                $this->sleepBeforeRetry($attempt);
            } catch (ConnectException $e) {
                if (!$this->shouldRetry(null, $attempt, $maxAttempts)) {
                    throw $e;
                }

                $this->logTransientRetry($e, $uri, $attempt, $maxAttempts, null, $logContext);
                $this->sleepBeforeRetry($attempt);
            }
        }
    }

    /**
     * Determine if an upstream failure should be retried.
     *
     * @param int|null $statusCode HTTP status code if available.
     * @param int $attempt Current attempt number, starting at 1.
     * @param int $maxAttempts Maximum attempts allowed for this request.
     *
     * @return bool True when another attempt is allowed and useful.
     */
    private function shouldRetry(?int $statusCode, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        return $statusCode === null || in_array($statusCode, self::TRANSIENT_STATUS_CODES, true);
    }

    /**
     * Log retryable upstream failures without dumping large prompt content.
     *
     * @param Exception $exception The transient exception from Guzzle.
     * @param string $uri OpenAI API path.
     * @param int $attempt Current attempt number.
     * @param int $maxAttempts Maximum attempts allowed for this request.
     * @param int|null $statusCode HTTP status code if available.
     * @param array<string, mixed> $logContext Safe request context.
     */
    private function logTransientRetry(
        Exception $exception,
        string $uri,
        int $attempt,
        int $maxAttempts,
        ?int $statusCode,
        array $logContext,
    ): void {
        Log::warning('Transient OpenAI request failure; retrying.', [
            'uri' => $uri,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'status_code' => $statusCode,
            'error_type' => $exception::class,
            'request' => $this->summarizeCompletionPayload($logContext),
        ]);
    }

    /**
     * Sleep with linear backoff between transient retries.
     *
     * @param int $attempt Current attempt number.
     */
    private function sleepBeforeRetry(int $attempt): void
    {
        if ($this->backoffMs <= 0) {
            return;
        }

        usleep($this->backoffMs * $attempt * 1000);
    }

    /**
     * Summarize an OpenAI request body for logs without exposing prompt text.
     *
     * @param array<string, mixed> $body Raw completion or embedding payload.
     *
     * @return array<string, mixed> Safe log metadata.
     */
    private function summarizeCompletionPayload(array $body): array
    {
        $messages = $body['messages'] ?? [];

        return [
            'model' => $body['model'] ?? null,
            'message_count' => is_array($messages) ? count($messages) : 0,
            'body_bytes' => $this->estimatePayloadBytes($body),
            'input_length' => $body['input_length'] ?? null,
        ];
    }

    /**
     * Estimate request size without serializing or copying nested file data.
     */
    private function estimatePayloadBytes(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        if (is_scalar($value)) {
            return strlen((string) $value);
        }

        if (!is_array($value)) {
            return 0;
        }

        $bytes = 2;
        foreach ($value as $key => $item) {
            $bytes += strlen((string) $key) + 2;
            $bytes += $this->estimatePayloadBytes($item);
        }

        return $bytes;
    }

    /**
     * Extract internal HTTP controls from a completion payload.
     *
     * @param array<string, mixed> $body Raw completion payload.
     *
     * @return array{http_options: array<string, float>, max_attempts: int|null}
     */
    private function extractRequestControls(array $body): array
    {
        $httpOptions = [];
        $timeout = $this->positiveFloat($body['request_timeout_seconds'] ?? null);
        $connectTimeout = $this->positiveFloat($body['request_connect_timeout_seconds'] ?? null);

        if ($timeout !== null) {
            $httpOptions['timeout'] = $timeout;
        }

        if ($connectTimeout !== null) {
            $httpOptions['connect_timeout'] = $connectTimeout;
        }

        return [
            'http_options' => $httpOptions,
            'max_attempts' => $this->positiveInt($body['request_max_attempts'] ?? null),
        ];
    }

    /**
     * Remove internal request-control fields before sending JSON to OpenAI.
     *
     * @param array<string, mixed> $body Raw completion payload.
     *
     * @return array<string, mixed> OpenAI-safe payload.
     */
    private function stripRequestControls(array $body): array
    {
        foreach (self::REQUEST_CONTROL_KEYS as $key) {
            unset($body[$key]);
        }

        return $body;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Get only content from result
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-21, 14:57 ICT
     */
    public function getContent(array $responseFromOpenAI = []): string
    {
        $choices = collect($responseFromOpenAI['choices'] ?? []);

        if ($choices->isEmpty()) {
            return '{}';
        }

        //  Get the first item with index = 0
        $firstItem = $choices->where('index', 0)->first();

        return $firstItem['message']['content'] ?? '{}';
    }
}
