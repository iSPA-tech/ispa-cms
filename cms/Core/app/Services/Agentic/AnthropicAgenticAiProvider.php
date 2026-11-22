<?php

namespace Cms\Core\Services\Agentic;

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Anthropic Messages API provider that accepts the OpenAI-shaped payloads existing callers already send.
 *
 * Request side: `model`, `messages` (system/developer/user/assistant, string or part-array content),
 * `response_format` (json_schema / json_object), `max_tokens` / `max_completion_tokens`, `reasoning_effort` and
 * `temperature` are translated; every other OpenAI-only parameter is dropped because the request is rebuilt from a
 * whitelist. Models of other vendors are mapped to a Claude model (see {@see self::resolveModel()}).
 *
 * Response side (the contract callers may rely on): the raw Messages API body (`content` blocks, `stop_reason`,
 * `usage`, `model`) plus an OpenAI-compatible `choices[0]` (`message.content` = {@see self::getContent()} text,
 * `message.refusal` on a refusal, `finish_reason` = stop|length|content_filter|tool_calls). A transport failure
 * returns `[]` after logging, exactly like the other providers; `getContent()` then yields `'{}'` like OpenAIClient.
 */
final class AnthropicAgenticAiProvider implements AgenticAiProviderInterface
{
    /** Tool used to return the result when the schema is too large for structured outputs. */
    private const RESULT_TOOL = 'submit_extraction';

    /** Anthropic structured outputs limit on parameters with type arrays or `anyOf`. */
    private const MAX_UNION_PARAMETERS = 16;

    private const DEFAULT_MAX_TOKENS = 16000;

    private const DEFAULT_MODEL = 'claude-sonnet-5-5';

    /** Values accepted by `output_config.effort`. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const IMAGE_MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const REQUEST_CONTROL_KEYS = [
        'request_timeout_seconds',
        'request_connect_timeout_seconds',
        'request_max_attempts',
    ];

    public function __construct(private readonly ?Client $client = null)
    {
    }

    public function createCompletion(array $payload = []): array
    {
        $httpOptions = $this->httpOptions($payload);

        try {
            $request = $this->toAnthropicPayload($this->stripRequestControls($payload));
        } catch (InvalidArgumentException $exception) {
            Log::error('Anthropic provider could not translate the request', ['error' => $exception->getMessage()]);

            return [];
        }

        $summary = [
            'model' => $request['model'] ?? null,
            'message_count' => count((array) ($request['messages'] ?? [])),
        ];
        $maxAttempts = $this->maxAttempts();
        $client = $this->client();

        for ($attempt = 1; ; $attempt++) {
            $status = null;
            $response = null;
            $error = null;

            try {
                $response = $client->post('v1/messages', [
                    'json' => $request,
                    'headers' => $this->headers(),
                    ...$httpOptions,
                ]);
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $this->withChatCompletionShape($decoded);
                    }
                }
            } catch (Throwable $exception) {
                $error = $exception;
                if ($exception instanceof RequestException) {
                    $response = $exception->getResponse();
                    $status = $response?->getStatusCode();
                }
            }

            $retryable = $error instanceof ConnectException || ($status !== null && self::isRetryableStatus($status));
            if ($retryable && $attempt < $maxAttempts) {
                $this->pauseBeforeRetry($attempt, $response);

                continue;
            }

            Log::error($error === null ? 'Non-2xx Anthropic provider response' : 'Anthropic provider completion failed', [
                ...$summary,
                'status_code' => $status,
                'attempts' => $attempt,
                'error' => $error !== null ? mb_substr($error->getMessage(), 0, 500) : null,
            ]);

            return [];
        }
    }

    public function getContent(array $response = []): string
    {
        $content = $response['content'] ?? [];
        $text = is_array($content) ? self::resultText($content) : '';

        // Same contract as OpenAIClient::getContent(): never an empty string, '{}' when there is no answer.
        return $text !== '' ? $text : '{}';
    }

    /**
     * Why a decoded Anthropic response cannot be used as an answer, or null when it can (or is not Anthropic-shaped).
     *
     * Callers use this to treat a refusal, a truncated answer or an answer without text as a failure.
     *
     * @param array<string, mixed> $response Decoded provider response.
     * @return string|null `refusal`, `max_tokens`, `empty`, or null.
     */
    public static function failureReason(array $response): ?string
    {
        $stopReason = $response['stop_reason'] ?? null;
        $blocks = $response['content'] ?? null;
        if (! is_string($stopReason) && ! is_array($blocks)) {
            return null;
        }

        if ($stopReason === 'refusal') {
            return 'refusal';
        }

        if (in_array($stopReason, ['max_tokens', 'model_context_window_exceeded'], true)) {
            return 'max_tokens';
        }

        return is_array($blocks) && self::resultText($blocks) !== '' ? null : 'empty';
    }

    /**
     * Resolve the Claude model for a requested model name.
     *
     * Claude ids pass through untouched. Any other name goes through the ordered
     * `core.ai_structured_extraction.providers.anthropic.model_map` (regex key when it starts with `/`, otherwise a
     * case-insensitive prefix); with no match, or no requested model, the provider default model is used.
     *
     * @param string $requested Model sent by the caller.
     * @return string Claude model id.
     */
    public static function resolveModel(string $requested): string
    {
        $requested = trim($requested);
        $default = trim((string) config('core.ai_structured_extraction.providers.anthropic.model', ''));
        $default = $default !== '' ? $default : self::DEFAULT_MODEL;

        if ($requested === '') {
            return $default;
        }

        if (str_starts_with(strtolower($requested), 'claude-')) {
            return $requested;
        }

        foreach ((array) config('core.ai_structured_extraction.providers.anthropic.model_map', []) as $pattern => $claude) {
            $pattern = (string) $pattern;
            $claude = trim((string) $claude);
            if ($pattern === '' || $claude === '') {
                continue;
            }

            $matches = $pattern[0] === '/'
                ? @preg_match($pattern, $requested) === 1
                : str_starts_with(strtolower($requested), strtolower($pattern));
            if ($matches) {
                return $claude;
            }
        }

        return $default;
    }

    /**
     * Map a caller / task / config reasoning effort onto `output_config.effort`.
     *
     * `none` / `minimal` / `low` become `low` (the lowest Claude level); `medium`, `high`, `xhigh` and `max` pass through.
     *
     * @param mixed $value Effort name.
     * @return string|null Allowed effort, or null when the value is empty or unknown.
     */
    public static function normalizeEffort(mixed $value): ?string
    {
        $effort = is_string($value) ? strtolower(trim($value)) : '';

        return match (true) {
            in_array($effort, ['none', 'minimal', 'low'], true) => 'low',
            in_array($effort, self::EFFORTS, true) => $effort,
            default => null,
        };
    }

    /**
     * Normalize a configured base URI: single trailing slash, never a trailing `/v1` (the path is added per request).
     *
     * @param string $configured Configured base URI (env or admin DB value).
     * @return string
     */
    public static function normalizeBaseUri(string $configured): string
    {
        $base = rtrim(trim($configured) !== '' ? trim($configured) : 'https://api.anthropic.com', '/');

        return (preg_replace('#/v1$#i', '', $base) ?? $base) . '/';
    }

    /**
     * Count parameters declared with a type array or `anyOf` (the structured outputs union limit).
     *
     * @param array<mixed> $schema JSON Schema.
     * @return int
     */
    public static function unionParameterCount(array $schema): int
    {
        $count = (isset($schema['type']) && is_array($schema['type'])) || isset($schema['anyOf']) ? 1 : 0;
        foreach ($schema as $value) {
            if (is_array($value)) {
                $count += self::unionParameterCount($value);
            }
        }

        return $count;
    }

    /**
     * Prepare a JSON Schema for Anthropic structured outputs, as the official SDKs do before sending.
     *
     * Drops constraints structured outputs reject (numeric bounds, string lengths, array size limits beyond `minItems`
     * 0/1; callers already validate the decoded result) and adds `additionalProperties: false` to every object schema
     * that does not declare it. `required` is never touched.
     *
     * @param array<mixed> $schema JSON Schema (or a sub-schema).
     * @return array<mixed>
     */
    public static function structuredOutputSchema(array $schema): array
    {
        $unsupported = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'maxItems', 'uniqueItems'];
        $clean = [];
        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, $unsupported, true)) {
                continue;
            }
            if ($key === 'minItems' && is_int($value) && $value > 1) {
                continue;
            }
            if (in_array($key, ['properties', '$defs', 'definitions'], true) && is_array($value)) {
                // Keys here are property / definition names, never keywords: keep them all, clean each sub-schema.
                $clean[$key] = array_map(static fn (mixed $sub): mixed => is_array($sub) ? self::structuredOutputSchema($sub) : $sub, $value);
                continue;
            }
            $clean[$key] = is_array($value) ? self::structuredOutputSchema($value) : $value;
        }

        if (self::isObjectSchema($clean) && ! array_key_exists('additionalProperties', $clean)) {
            $clean['additionalProperties'] = false;
        }

        return $clean;
    }

    /**
     * Whether the model still accepts sampling parameters (`temperature`).
     *
     * Claude Opus 4.7 and later (Opus 4.8, the 5.x family, Fable and Mythos) reject them with a 400, so the
     * extraction default `temperature: 0` must not be sent there. Older models keep receiving it.
     *
     * @param string $model Anthropic model id.
     * @return bool
     */
    public static function acceptsSamplingParams(string $model): bool
    {
        $model = strtolower(trim($model));

        return preg_match('/^claude-(fable|mythos)-/', $model) !== 1
            && preg_match('/^claude-(opus|sonnet|haiku)-5/', $model) !== 1
            && preg_match('/^claude-opus-4-[78]/', $model) !== 1;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When a content part cannot be expressed as an Anthropic block.
     */
    private function toAnthropicPayload(array $payload): array
    {
        $requestedModel = trim((string) ($payload['model'] ?? ''));
        $model = self::resolveModel($requestedModel);
        if ($model !== $requestedModel) {
            Log::debug('Anthropic provider mapped the requested model', ['requested' => $requestedModel, 'model' => $model]);
        }

        $schema = data_get($payload, 'response_format.json_schema.schema');
        $schema = is_array($schema) ? self::structuredOutputSchema($schema) : null;
        $jsonObject = $schema === null && data_get($payload, 'response_format.type') === 'json_object';

        [$systemParts, $messages] = $this->translateMessages($payload['messages'] ?? []);

        // Assistant prefill is a 400 on current models; the JSON instructions below replace its usual purpose.
        if (! self::acceptsSamplingParams($model)) {
            while ($messages !== [] && $messages[array_key_last($messages)]['role'] === 'assistant') {
                array_pop($messages);
                Log::warning('Anthropic provider dropped a trailing assistant message (prefill is not supported)', ['model' => $model]);
            }
        }

        if ($schema !== null) {
            array_unshift($systemParts, 'Return JSON only.');
        } elseif ($jsonObject) {
            $systemParts[] = 'Return only a valid JSON object.';
        }

        $request = [
            'model' => $model,
            'max_tokens' => $this->maxTokens($payload),
            'messages' => $messages,
        ];

        if (isset($payload['temperature']) && self::acceptsSamplingParams($model)) {
            $request['temperature'] = (float) $payload['temperature'];
        }

        if ($schema !== null) {
            if (self::unionParameterCount($schema) <= self::MAX_UNION_PARAMETERS && ! self::hasOpenObject($schema)) {
                $request['output_config'] = [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $schema,
                    ],
                ];
            } else {
                // Structured outputs refuse schemas with more than 16 nullable / union parameters and every object that
                // is not closed. A non-strict tool takes any JSON Schema and its `input` always arrives as a parsed JSON
                // object, so ask for the result through it (forced tool_choice is rejected by current models, hence
                // `auto` plus the instruction).
                $request['tools'] = [[
                    'name' => self::RESULT_TOOL,
                    'description' => 'Submit the extracted data. Call this exactly once with the complete result.',
                    'input_schema' => $schema,
                ]];
                $request['tool_choice'] = ['type' => 'auto'];
                $systemParts[] = 'Call the ' . self::RESULT_TOOL . ' tool exactly once with the complete result; do not answer in text.';
            }
        }

        $system = trim(implode("\n\n", $systemParts));
        if ($system !== '') {
            $request = ['model' => $request['model'], 'max_tokens' => $request['max_tokens'], 'system' => $system] + $request;
        }

        $effort = self::normalizeEffort($payload['reasoning_effort'] ?? null)
            ?? self::normalizeEffort(config('core.ai_structured_extraction.providers.anthropic.effort', ''));
        if ($effort !== null) {
            $request['output_config']['effort'] = $effort;
        }

        return $request;
    }

    private function maxTokens(array $payload): int
    {
        $tokens = (int) ($payload['max_tokens'] ?? $payload['max_completion_tokens'] ?? 0);
        if ($tokens <= 0) {
            $tokens = (int) config('core.ai_structured_extraction.providers.anthropic.max_tokens', self::DEFAULT_MAX_TOKENS);
        }

        $floor = (int) config('core.ai_structured_extraction.providers.anthropic.min_max_tokens', 0);

        return max($tokens > 0 ? $tokens : self::DEFAULT_MAX_TOKENS, $floor);
    }

    /**
     * Split OpenAI-style messages into Anthropic `system` parts and `messages`.
     *
     * @return array{0: list<string>, 1: list<array{role: string, content: string|list<array<string, mixed>>}>}
     */
    private function translateMessages(mixed $messages): array
    {
        $system = [];
        $translated = [];

        foreach ((array) $messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = strtolower((string) ($message['role'] ?? 'user'));
            $content = $message['content'] ?? '';

            if ($role === 'system' || $role === 'developer') {
                $text = trim($this->flattenText($content));
                if ($text !== '') {
                    $system[] = $text;
                }
                continue;
            }

            $blocks = $this->translateContent($content);
            if ($blocks === '' || $blocks === []) {
                continue;
            }

            $translated[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $blocks];
        }

        return [$system, $translated];
    }

    private function flattenText(mixed $content): string
    {
        if (! is_array($content)) {
            return (string) $content;
        }

        $parts = array_is_list($content) ? $content : [$content];

        return implode("\n", array_filter(array_map(
            static fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part,
            $parts,
        ), static fn (string $text): bool => $text !== ''));
    }

    /**
     * @return string|list<array<string, mixed>> Plain string for string content, Anthropic blocks for part arrays.
     *
     * @throws InvalidArgumentException
     */
    private function translateContent(mixed $content): string|array
    {
        if ($content === null) {
            return '';
        }

        if (! is_array($content)) {
            $text = (string) $content;

            return trim($text) === '' ? '' : $text;
        }

        $blocks = [];
        foreach (array_is_list($content) ? $content : [$content] as $part) {
            $block = $this->translatePart(is_string($part) ? ['type' => 'text', 'text' => $part] : $part);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null Null for a part that carries nothing (an empty text).
     *
     * @throws InvalidArgumentException
     */
    private function translatePart(mixed $part): ?array
    {
        if (! is_array($part)) {
            return null;
        }

        $type = strtolower((string) ($part['type'] ?? ''));

        return match (true) {
            in_array($type, ['text', 'input_text', 'output_text'], true) => $this->textBlock((string) ($part['text'] ?? '')),
            in_array($type, ['image_url', 'input_image'], true) => $this->imageBlock($part),
            in_array($type, ['file', 'input_file'], true) => $this->fileBlock($part),
            // Parts that already are Anthropic blocks.
            in_array($type, ['image', 'document', 'tool_use', 'tool_result', 'thinking', 'redacted_thinking'], true) => $part,
            $type === '' && is_string($part['text'] ?? null) => $this->textBlock($part['text']),
            default => throw new InvalidArgumentException('Unsupported content part type for Anthropic: ' . ($type !== '' ? $type : 'unknown')),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function textBlock(string $text): ?array
    {
        return trim($text) === '' ? null : ['type' => 'text', 'text' => $text];
    }

    /**
     * @param array<string, mixed> $part OpenAI `image_url` part.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function imageBlock(array $part): array
    {
        $image = $part['image_url'] ?? $part['url'] ?? '';
        $url = trim((string) (is_array($image) ? ($image['url'] ?? '') : $image));

        if (preg_match('#^https?://#i', $url) === 1) {
            return ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
        }

        [$mediaType, $data] = $this->parseInlineData($url, '');
        if (! in_array($mediaType, self::IMAGE_MEDIA_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported image media type for Anthropic: ' . $mediaType);
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $data]];
    }

    /**
     * Turn an OpenAI `file` part (inline data URI) into a PDF `document` block or an `image` block.
     *
     * @param array<string, mixed> $part OpenAI `file` part.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function fileBlock(array $part): array
    {
        $file = is_array($part['file'] ?? null) ? $part['file'] : $part;
        $fileData = trim((string) ($file['file_data'] ?? ''));
        if ($fileData === '') {
            throw new InvalidArgumentException('A file part without inline file_data cannot be sent to Anthropic.');
        }

        [$mediaType, $data] = $this->parseInlineData($fileData, (string) ($file['filename'] ?? ''));

        if ($mediaType === 'application/pdf') {
            return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $data]];
        }

        if (in_array($mediaType, self::IMAGE_MEDIA_TYPES, true)) {
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $data]];
        }

        throw new InvalidArgumentException('Unsupported file media type for Anthropic: ' . $mediaType);
    }

    /**
     * Split a data URI (or bare base64, typed by the filename extension) into media type and base64 data.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    private function parseInlineData(string $value, string $filename): array
    {
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma === false) {
                throw new InvalidArgumentException('Malformed data URI.');
            }

            $header = substr($value, 5, $comma - 5);
            $body = substr($value, $comma + 1);
            $mediaType = strtolower(trim(explode(';', $header)[0]));
            if (! str_contains(strtolower($header), ';base64')) {
                $body = base64_encode(rawurldecode($body));
            }
        } else {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mediaType = match ($extension) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'application/pdf',
            };
            $body = $value;
        }

        return [$mediaType === 'image/jpg' ? 'image/jpeg' : $mediaType, $body];
    }

    /**
     * Add an OpenAI-compatible `choices[0]` next to the raw Messages API body.
     *
     * @param array<string, mixed> $response Decoded Messages API body.
     * @return array<string, mixed>
     */
    private function withChatCompletionShape(array $response): array
    {
        if (isset($response['choices'])) {
            return $response;
        }

        $stopReason = (string) ($response['stop_reason'] ?? '');
        $text = is_array($response['content'] ?? null) ? self::resultText($response['content']) : '';
        $message = ['role' => 'assistant', 'content' => $text];
        if ($stopReason === 'refusal') {
            $message['refusal'] = $text !== '' ? $text : 'refusal';
        }

        $response['choices'] = [[
            'index' => 0,
            'message' => $message,
            'finish_reason' => match ($stopReason) {
                'max_tokens', 'model_context_window_exceeded' => 'length',
                'refusal' => 'content_filter',
                'tool_use' => 'tool_calls',
                default => 'stop',
            },
        ]];

        return $response;
    }

    /**
     * The answer of a content block list: the result tool input as JSON, else the text blocks.
     *
     * @param array<int|string, mixed> $content Content blocks.
     */
    private static function resultText(array $content): string
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::RESULT_TOOL && is_array($block['input'] ?? null)) {
                return (string) json_encode($block['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $text = collect($content)
            ->map(static fn (mixed $block): string => is_array($block) ? (string) ($block['text'] ?? '') : '')
            ->implode('');

        // A JSON answer (the model skipped the tool) may come wrapped in a Markdown code fence.
        if (preg_match('/^\s*```(?:json)?\s*(.*?)\s*```\s*$/s', $text, $matches) === 1 && preg_match('/^[\[{]/', $matches[1]) === 1) {
            return $matches[1];
        }

        return $text;
    }

    /**
     * @param array<mixed> $schema
     */
    private static function isObjectSchema(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return $type === 'object'
            || (is_array($type) && in_array('object', $type, true))
            || ($type === null && is_array($schema['properties'] ?? null));
    }

    /**
     * Whether any object schema is open (`additionalProperties` other than false); structured outputs reject those.
     *
     * @param array<mixed> $schema JSON Schema after {@see self::structuredOutputSchema()}.
     */
    private static function hasOpenObject(array $schema): bool
    {
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            return true;
        }

        foreach ($schema as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (in_array($key, ['properties', '$defs', 'definitions'], true)) {
                foreach ($value as $sub) {
                    if (is_array($sub) && self::hasOpenObject($sub)) {
                        return true;
                    }
                }
                continue;
            }

            if (self::hasOpenObject($value)) {
                return true;
            }
        }

        return false;
    }

    private static function isRetryableStatus(int $status): bool
    {
        // 429 rate limited, 5xx server errors and 529 overloaded.
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function maxAttempts(): int
    {
        return max(1, min(6, (int) config('core.ai_structured_extraction.providers.anthropic.max_attempts', 3)));
    }

    /**
     * Sleep before the next attempt: `retry-after` when the API sent one, exponential backoff otherwise, capped.
     */
    private function pauseBeforeRetry(int $attempt, ?ResponseInterface $response): void
    {
        $capMs = max(0, (int) config('core.ai_structured_extraction.providers.anthropic.retry_max_wait_seconds', 30)) * 1000;
        $retryAfter = $response !== null ? trim($response->getHeaderLine('retry-after')) : '';

        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            $delayMs = (int) round(max(0.0, (float) $retryAfter) * 1000);
        } elseif ($retryAfter !== '' && ($at = strtotime($retryAfter)) !== false) {
            $delayMs = max(0, ($at - time()) * 1000);
        } else {
            $baseMs = max(0, (int) config('core.ai_structured_extraction.providers.anthropic.retry_base_delay_ms', 1000));
            $delayMs = $baseMs * (2 ** ($attempt - 1));
        }

        $delayMs = min($capMs, $delayMs);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function client(): Client
    {
        return $this->client ?? new Client([
            'base_uri' => self::normalizeBaseUri((string) config('core.ai_structured_extraction.providers.anthropic.base_uri')),
            // Built from the Laravel HTTP factory so Http::fake() intercepts it in tests; a plain Guzzle stack otherwise.
            'handler' => Http::buildHandlerStack(),
            'headers' => $this->headers(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'x-api-key' => (string) config('core.ai_structured_extraction.providers.anthropic.api_key'),
            'anthropic-version' => (string) config('core.ai_structured_extraction.providers.anthropic.version'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Transport timeouts: the configured read timeout is a floor under a caller's own (Claude answers slower).
     *
     * @return array<string, mixed>
     */
    private function httpOptions(array $payload): array
    {
        $timeout = max(1.0, (float) config('core.ai_structured_extraction.providers.anthropic.timeout_seconds', 180));
        if (isset($payload['request_timeout_seconds']) && is_numeric($payload['request_timeout_seconds'])) {
            $timeout = max($timeout, (float) $payload['request_timeout_seconds']);
        }

        $connect = max(1.0, (float) config('core.ai_structured_extraction.providers.anthropic.connect_timeout_seconds', 10));
        if (isset($payload['request_connect_timeout_seconds']) && is_numeric($payload['request_connect_timeout_seconds'])) {
            $connect = max(1.0, (float) $payload['request_connect_timeout_seconds']);
        }

        return ['timeout' => $timeout, 'connect_timeout' => $connect];
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
}
