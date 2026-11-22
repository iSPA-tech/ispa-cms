<?php

namespace Cms\Core\Services\Ai;

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AiExtractionRun;
use Cms\Core\Services\Agentic\AgenticAiProviderFactory;
use Cms\Core\Services\Automation\ModuleAutomationSettingsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class AiStructuredExtractionService
{
    public function __construct(
        private readonly AgenticAiProviderInterface $provider,
        private readonly JsonSchemaShapeValidator $validator,
        private readonly ?AgenticAiProviderFactory $providerFactory = null,
        private readonly ?AiRuntimeConfigResolver $runtimeConfig = null,
        private readonly ?\Cms\Core\Services\Ai\Usage\AiGateway $gateway = null,
    ) {
    }

    /**
     * @param array<string, mixed> $schema JSON Schema, or a definition containing a `schema` key.
     * @param array<string, mixed> $context Safe metadata for the extraction request.
     * @param array<string, mixed> $options Runtime options: schema_name, schema_version, model, module, task, cache_enabled, strict.
     */
    public function extract(
        string $input,
        array $schema,
        string $instructions = '',
        array $context = [],
        array $options = [],
    ): AiStructuredExtractionResult {
        return $this->runExtraction(
            inputHashSource: $input,
            schema: $schema,
            instructions: $instructions,
            context: $context,
            options: $options,
            messageBuilder: function (array &$safeContext) use ($input, $instructions): array {
                $safeInput = $this->limitInput($input, $safeContext);

                return $this->buildMessages($safeInput, $instructions, $safeContext);
            },
        );
    }

    /**
     * Extract structured data from one ephemeral Base64 data URI attachment.
     *
     * `$sourceDescriptor` must be a small, non-sensitive stable label or fingerprint. It is used
     * for request identity and audit metadata; `$fileDataUri` is sent only to the provider.
     *
     * @param string $fileDataUri Ephemeral Base64 data URI sent only in the provider request.
     * @param string $filename Safe filename without path components.
     * @param string $sourceDescriptor Safe source label or fingerprint used for hashing and audit.
     * @param array<string, mixed> $schema JSON Schema, or a definition containing a `schema` key.
     * @param array<string, mixed> $context Safe metadata for the extraction request.
     * @param array<string, mixed> $options Runtime options, including safe input_bytes/prompt_bytes audit overrides.
     */
    public function extractFile(
        string $fileDataUri,
        string $filename,
        string $sourceDescriptor,
        array $schema,
        string $instructions = '',
        array $context = [],
        array $options = [],
    ): AiStructuredExtractionResult {
        $userPrompt = $this->buildFileUserPrompt($instructions, $context, $sourceDescriptor);
        $systemPrompt = (string) config('core.ai_structured_extraction.system_prompt');
        $inputBytes = array_key_exists('input_bytes', $options)
            ? $options['input_bytes']
            : strlen($sourceDescriptor);
        $promptBytes = array_key_exists('prompt_bytes', $options)
            ? $options['prompt_bytes']
            : strlen($systemPrompt) + strlen($userPrompt);
        $options = [
            ...$options,
            'store' => false,
            'redact_provider_errors' => true,
            'input_bytes' => $inputBytes,
            'prompt_bytes' => $promptBytes,
            '_audit_source' => [
                'type' => 'file',
                'filename' => $filename,
                'descriptor' => $sourceDescriptor,
            ],
        ];

        return $this->runExtraction(
            inputHashSource: $sourceDescriptor,
            schema: $schema,
            instructions: $instructions,
            context: $context,
            options: $options,
            messageBuilder: fn (array &$safeContext): array => $this->buildFileMessages(
                $fileDataUri,
                $filename,
                $userPrompt,
            ),
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     * @param callable(array<string, mixed>&): array<int, array<string, mixed>> $messageBuilder
     */
    private function runExtraction(
        string $inputHashSource,
        array $schema,
        string $instructions,
        array $context,
        array $options,
        callable $messageBuilder,
    ): AiStructuredExtractionResult {
        $definition = $this->resolveSchemaDefinition($schema, $options);
        $schema = $definition['schema'];
        $schemaName = $definition['schema_name'];
        $schemaVersion = $definition['schema_version'];
        // Admin registry rules: a task configured in the admin beats the
        // call site's hardcoded option, so every AI flow stays manageable
        // from the admin without code changes. Unconfigured tasks keep the
        // call site's own choice, then registry default, then env.
        $taskOverrides = $this->runtimeConfig?->taskOverrides(
            (string) ($options['task'] ?? $context['task'] ?? ''),
        ) ?? ['provider' => null, 'model' => null, 'temperature' => null, 'reasoning_effort' => null, 'fallback_models' => []];
        $provider = (string) ($taskOverrides['provider']
            ?? $options['provider']
            ?? $this->runtimeConfig?->defaultProvider()
            ?? config('core.ai_structured_extraction.provider', 'agentic_ai'));
        $this->runtimeConfig?->applyProviderRuntimeOverrides($provider);
        $model = (string) ($taskOverrides['model']
            ?? $options['model']
            ?? $this->defaultModelForProvider($provider));
        if ($taskOverrides['temperature'] !== null) {
            $options['temperature'] = $taskOverrides['temperature'];
        }
        // Reasoning effort (OpenAI GPT-5.x): task setting, then call-site option, then the provider default.
        $reasoningEffort = trim((string) ($taskOverrides['reasoning_effort']
            ?? $options['reasoning_effort']
            ?? config('core.ai_structured_extraction.providers.' . strtolower(trim($provider)) . '.reasoning_effort', '')));
        $options['reasoning_effort'] = $reasoningEffort !== '' ? $reasoningEffort : null;
        $inputHash = hash('sha256', $inputHashSource);
        $schemaHash = hash('sha256', $this->stableJson($schema));
        $instructionHash = hash('sha256', $instructions);
        $requestHash = hash('sha256', implode('|', [
            $provider,
            $inputHash,
            $schemaHash,
            $instructionHash,
            $schemaName,
            $schemaVersion,
            $model,
            $this->stableJson($context),
            // Appended only when set, so request hashes (and cached runs) without an effort stay unchanged.
            ...($options['reasoning_effort'] !== null ? ['reasoning_effort:' . $options['reasoning_effort']] : []),
        ]));

        if (! (bool) config('core.ai_structured_extraction.enabled', true)) {
            return $this->failedResult('disabled', ['AI structured extraction is disabled.'], null, [
                'input_hash' => $inputHash,
                'request_hash' => $requestHash,
                'schema_name' => $schemaName,
                'schema_version' => $schemaVersion,
                'model' => $model,
            ]);
        }

        $cacheEnabled = (bool) ($options['cache_enabled'] ?? config('core.ai_structured_extraction.cache.enabled', true));
        if ($cacheEnabled && $this->canPersist()) {
            $cached = AiExtractionRun::query()
                ->where('request_hash', $requestHash)
                ->where('status', 'completed')
                ->first();

            if ($cached) {
                return $this->resultFromRun($cached, true);
            }
        }

        $messages = $messageBuilder($context);
        $payload = $this->buildPayload($model, $messages, $schema, $schemaName, $options);
        $run = $this->startRun(
            $requestHash,
            $inputHash,
            $schemaHash,
            $instructionHash,
            $schemaName,
            $schemaVersion,
            $provider,
            $model,
            $inputHashSource,
            $messages,
            $schema,
            $context,
            $options
        );

        try {
            app(ModuleAutomationSettingsService::class)->applyProviderRuntimeConfig((string) ($options['module'] ?? $context['module'] ?? ''));
            $activeProvider = $this->resolveProvider($provider);
            if ($this->gateway !== null) {
                $gatewayResult = $this->gateway->complete(
                    $activeProvider,
                    $provider !== '' ? $provider : \Cms\Core\Services\Ai\Usage\AiGateway::providerNameOf($activeProvider),
                    (string) ($options['task'] ?? $context['task'] ?? ''),
                    $payload,
                    $taskOverrides['fallback_models'],
                );
                $response = $gatewayResult->response;
            } else {
                $response = $activeProvider->createCompletion($payload);
            }
            $refusal = $this->extractRefusal($response);
        } catch (Throwable $exception) {
            $redactProviderErrors = (bool) ($options['redact_provider_errors'] ?? false);
            $errors = [$redactProviderErrors
                ? 'AI provider request failed.'
                : 'AI provider request failed: ' . $exception->getMessage()];
            $errorResponse = ['error' => ['type' => $exception::class]];
            if (! $redactProviderErrors) {
                $errorResponse['error']['message'] = $exception->getMessage();
            }
            $this->finishRun($run, 'failed', null, [
                ...$errorResponse,
            ], $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        if ($response === []) {
            // Providers log the failure and return an empty response; do not report it as invalid JSON.
            $errors = ['AI provider returned no response (request failed, see the provider log).'];
            $this->finishRun($run, 'failed', null, ['error' => ['type' => 'empty_provider_response']], $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        // Anthropic reports a refusal / truncation as `stop_reason` on an HTTP 200; map them like OpenAI's.
        $stopReason = is_string($response['stop_reason'] ?? null) ? $response['stop_reason'] : null;

        if ($refusal !== null || $stopReason === 'refusal') {
            $errors = ['AI provider refused extraction.'];
            $this->finishRun($run, 'failed', null, $response, $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        if (($response['choices'][0]['finish_reason'] ?? null) === 'length' || in_array($stopReason, ['max_tokens', 'model_context_window_exceeded'], true)) {
            $errors = ['AI provider response was truncated.'];
            $this->finishRun($run, 'failed', null, $response, $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        try {
            $content = $activeProvider->getContent($response);
        } catch (Throwable $exception) {
            $redactProviderErrors = (bool) ($options['redact_provider_errors'] ?? false);
            $errors = [$redactProviderErrors
                ? 'AI provider request failed.'
                : 'AI provider request failed: ' . $exception->getMessage()];
            $errorResponse = ['error' => ['type' => $exception::class]];
            if (! $redactProviderErrors) {
                $errorResponse['error']['message'] = $exception->getMessage();
            }
            $this->finishRun($run, 'failed', null, $errorResponse, $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        try {
            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors = ['AI response is not valid JSON: ' . $exception->getMessage()];
            $this->finishRun($run, 'failed', null, $response, $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        if (! is_array($parsed)) {
            $errors = ['AI response JSON must decode to an object or array.'];
            $this->finishRun($run, 'failed', null, $response, $errors, null);

            return $this->failedResult('failed', $errors, $run, compact('inputHash', 'requestHash', 'schemaName', 'schemaVersion', 'model'));
        }

        $errors = $this->validator->validate($schema, $parsed);
        $confidence = $this->extractConfidence($parsed);

        if ($errors !== []) {
            $this->finishRun($run, 'failed', $parsed, $response, $errors, $confidence);

            return new AiStructuredExtractionResult(
                ok: false,
                status: 'failed',
                data: $parsed,
                confidence: $confidence,
                errors: $errors,
                cached: false,
                runId: $run?->id,
                inputHash: $inputHash,
                requestHash: $requestHash,
                schemaName: $schemaName,
                schemaVersion: $schemaVersion,
                model: $model,
                metadata: ['validation_failed' => true],
            );
        }

        $this->finishRun($run, 'completed', $parsed, $response, [], $confidence);

        return new AiStructuredExtractionResult(
            ok: true,
            status: 'completed',
            data: $parsed,
            confidence: $confidence,
            errors: [],
            cached: false,
            runId: $run?->id,
            inputHash: $inputHash,
            requestHash: $requestHash,
            schemaName: $schemaName,
            schemaVersion: $schemaVersion,
            model: $model,
            metadata: ['usage' => $response['usage'] ?? null],
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function extractUsingConfiguredSchema(
        string $input,
        string $schemaKey,
        string $instructions = '',
        array $context = [],
        array $options = [],
    ): AiStructuredExtractionResult {
        $definition = config('core.ai_structured_extraction.schemas.' . $schemaKey);

        if (! is_array($definition)) {
            throw new InvalidArgumentException("AI structured extraction schema [{$schemaKey}] is not configured.");
        }

        return $this->extract($input, $definition, $instructions, $context, [
            'schema_name' => $schemaKey,
            ...$options,
        ]);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $options
     * @return array{schema: array<string, mixed>, schema_name: string, schema_version: string}
     */
    private function resolveSchemaDefinition(array $schema, array $options): array
    {
        $schemaPayload = is_array($schema['schema'] ?? null) ? $schema['schema'] : $schema;
        $schemaName = (string) ($options['schema_name'] ?? $schema['name'] ?? 'structured_extraction');
        $schemaVersion = (string) ($options['schema_version'] ?? $schema['version'] ?? 'v1');

        if ($schemaPayload === []) {
            throw new InvalidArgumentException('AI structured extraction schema cannot be empty.');
        }

        return [
            'schema' => $schemaPayload,
            'schema_name' => $schemaName,
            'schema_version' => $schemaVersion,
        ];
    }

    private function resolveProvider(string $provider): AgenticAiProviderInterface
    {
        if ($this->providerFactory === null || in_array(strtolower($provider), ['agentic_ai', 'default'], true)) {
            return $this->provider;
        }

        return $this->providerFactory->make($provider);
    }

    private function defaultModelForProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $providerKey = match ($provider) {
            'google', 'google_gemini' => 'gemini',
            'xai', 'x.ai' => 'grok',
            'claude' => 'anthropic',
            default => $provider,
        };
        $providerModel = config("core.ai_structured_extraction.providers.{$providerKey}.model");

        if (is_string($providerModel) && trim($providerModel) !== '') {
            return $providerModel;
        }

        return (string) config('core.ai_structured_extraction.model', 'gpt-5.6-luna');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function limitInput(string $input, array &$context): string
    {
        $maxChars = (int) config('core.ai_structured_extraction.max_input_chars', 60000);
        if ($maxChars <= 0 || mb_strlen($input) <= $maxChars) {
            return $input;
        }

        $context['_input_truncated'] = true;
        $context['_input_original_chars'] = mb_strlen($input);

        return mb_substr($input, 0, $maxChars);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $input, string $instructions, array $context): array
    {
        $systemPrompt = (string) config('core.ai_structured_extraction.system_prompt');
        $contextJson = $this->stableJson($context);
        $userPrompt = implode("\n\n", array_filter([
            $instructions !== '' ? "TASK INSTRUCTIONS:\n" . $instructions : null,
            $context !== [] ? "SAFE CONTEXT JSON:\n" . $contextJson : null,
            "SOURCE TEXT:\n" . $input,
        ]));

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildFileUserPrompt(string $instructions, array $context, string $sourceDescriptor): string
    {
        return implode("\n\n", array_filter([
            $instructions !== '' ? "TASK INSTRUCTIONS:\n" . $instructions : null,
            $context !== [] ? "SAFE CONTEXT JSON:\n" . $this->stableJson($context) : null,
            "ATTACHED SOURCE:\n" . $sourceDescriptor,
        ]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFileMessages(string $fileDataUri, string $filename, string $userPrompt): array
    {
        return [
            [
                'role' => 'system',
                'content' => (string) config('core.ai_structured_extraction.system_prompt'),
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'file',
                        'file' => [
                            'filename' => $filename,
                            'file_data' => $fileDataUri,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $userPrompt,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(string $model, array $messages, array $schema, string $schemaName, array $options): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $this->openAiSchemaName($schemaName),
                    'strict' => (bool) ($options['strict'] ?? config('core.ai_structured_extraction.strict', true)),
                    'schema' => $schema,
                ],
            ],
            'request_timeout_seconds' => (float) ($options['request_timeout_seconds'] ?? config('core.ai_structured_extraction.request.timeout_seconds', 60)),
            'request_connect_timeout_seconds' => (float) ($options['request_connect_timeout_seconds'] ?? config('core.ai_structured_extraction.request.connect_timeout_seconds', 10)),
            'request_max_attempts' => (int) ($options['request_max_attempts'] ?? config('core.ai_structured_extraction.request.max_attempts', 2)),
        ];

        if (array_key_exists('store', $options)) {
            $payload['store'] = (bool) $options['store'];
        }

        if (is_string($options['reasoning_effort'] ?? null) && $options['reasoning_effort'] !== '') {
            $payload['reasoning_effort'] = $options['reasoning_effort'];
        }

        $temperature = array_key_exists('temperature', $options)
            ? $options['temperature']
            : config('core.ai_structured_extraction.temperature', 0.0);
        if ($temperature !== null) {
            $payload['temperature'] = (float) $temperature;
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    private function startRun(
        string $requestHash,
        string $inputHash,
        string $schemaHash,
        string $instructionHash,
        string $schemaName,
        string $schemaVersion,
        string $provider,
        string $model,
        string $input,
        array $messages,
        array $schema,
        array $context,
        array $options,
    ): ?AiExtractionRun {
        if (! $this->canPersist()) {
            return null;
        }

        $inputBytes = array_key_exists('input_bytes', $options)
            ? max(0, (int) $options['input_bytes'])
            : strlen($input);
        $promptBytes = array_key_exists('prompt_bytes', $options)
            ? max(0, (int) $options['prompt_bytes'])
            : strlen($this->stableJson($messages));
        $requestPayload = [
            'message_count' => count($messages),
            'schema_name' => $schemaName,
            'schema_version' => $schemaVersion,
            'strict' => (bool) ($options['strict'] ?? config('core.ai_structured_extraction.strict', true)),
        ];
        if (is_array($options['_audit_source'] ?? null)) {
            $requestPayload['source'] = $options['_audit_source'];
        }

        $run = AiExtractionRun::query()->firstOrNew(['request_hash' => $requestHash]);
        $run->fill([
            'module' => $options['module'] ?? Arr::get($context, 'module'),
            'task' => $options['task'] ?? Arr::get($context, 'task'),
            'schema_name' => $schemaName,
            'schema_version' => $schemaVersion,
            'provider' => $provider,
            'model' => $model,
            'input_hash' => $inputHash,
            'schema_hash' => $schemaHash,
            'instruction_hash' => $instructionHash,
            'input_bytes' => $inputBytes,
            'prompt_bytes' => $promptBytes,
            'context_payload' => $context,
            'schema_payload' => $schema,
            'request_payload' => $requestPayload,
            'status' => 'running',
            'error_message' => null,
        ]);
        $run->save();

        return $run;
    }

    /**
     * @param array<string, mixed>|array<int, mixed>|null $parsed
     * @param array<string, mixed> $response
     * @param array<int, string> $errors
     */
    private function finishRun(
        ?AiExtractionRun $run,
        string $status,
        ?array $parsed,
        array $response,
        array $errors,
        ?float $confidence,
    ): void {
        if (! $run) {
            return;
        }

        $run->fill([
            'status' => $status,
            'response_payload' => $this->redactProviderResponse($response),
            'parsed_payload' => $parsed,
            'usage_payload' => $response['usage'] ?? null,
            'confidence' => $confidence,
            'error_message' => $errors === [] ? null : implode("\n", $errors),
        ]);
        $run->save();
    }

    private function resultFromRun(AiExtractionRun $run, bool $cached): AiStructuredExtractionResult
    {
        return new AiStructuredExtractionResult(
            ok: $run->status === 'completed',
            status: $run->status,
            data: $run->parsed_payload,
            confidence: $run->confidence,
            errors: $run->error_message ? explode("\n", $run->error_message) : [],
            cached: $cached,
            runId: $run->id,
            inputHash: $run->input_hash,
            requestHash: $run->request_hash,
            schemaName: $run->schema_name,
            schemaVersion: $run->schema_version,
            model: $run->model,
            metadata: ['usage' => $run->usage_payload],
        );
    }

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $base
     */
    private function failedResult(string $status, array $errors, ?AiExtractionRun $run, array $base): AiStructuredExtractionResult
    {
        return new AiStructuredExtractionResult(
            ok: false,
            status: $status,
            data: null,
            confidence: null,
            errors: $errors,
            cached: false,
            runId: $run?->id,
            inputHash: (string) ($base['input_hash'] ?? $base['inputHash'] ?? ''),
            requestHash: (string) ($base['request_hash'] ?? $base['requestHash'] ?? ''),
            schemaName: (string) ($base['schema_name'] ?? $base['schemaName'] ?? 'structured_extraction'),
            schemaVersion: (string) ($base['schema_version'] ?? $base['schemaVersion'] ?? 'v1'),
            model: (string) ($base['model'] ?? config('core.ai_structured_extraction.model', 'gpt-5.6-luna')),
        );
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $parsed
     */
    private function extractConfidence(array $parsed): ?float
    {
        $value = $parsed['confidence'] ?? $parsed['confidence_score'] ?? null;
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (float) $value;
        if ($confidence > 1) {
            $confidence /= 100;
        }

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractRefusal(array $response): ?string
    {
        $message = $response['choices'][0]['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $refusal = $message['refusal'] ?? null;

        return is_string($refusal) && $refusal !== '' ? $refusal : null;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function redactProviderResponse(array $response): array
    {
        // Anthropic body: keep the shape (block types, sizes), never the extracted text or tool input.
        if (isset($response['content']) && is_array($response['content'])) {
            $blocks = array_values(array_filter($response['content'], 'is_array'));
            $response['content'] = [
                'content_present' => true,
                'block_types' => array_map(static fn (array $block): string => (string) ($block['type'] ?? 'unknown'), $blocks),
                'content_bytes' => strlen((string) json_encode($response['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ];
        }

        if (isset($response['choices']) && is_array($response['choices'])) {
            $response['choices'] = array_map(static function (mixed $choice): mixed {
                if (! is_array($choice)) {
                    return $choice;
                }

                if (isset($choice['message']) && is_array($choice['message'])) {
                    if (array_key_exists('content', $choice['message'])) {
                        $content = $choice['message']['content'];
                        $encodedContent = is_array($content)
                            ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : null;
                        $choice['message']['content_present'] = true;
                        $choice['message']['content_type'] = get_debug_type($content);
                        $choice['message']['content_bytes'] = match (true) {
                            is_string($content) => strlen($content),
                            is_string($encodedContent) => strlen($encodedContent),
                            is_scalar($content) => strlen((string) $content),
                            default => 0,
                        };
                        unset($choice['message']['content']);
                    }

                    if (array_key_exists('refusal', $choice['message'])) {
                        $refusal = $choice['message']['refusal'];
                        $choice['message']['refusal_present'] = $refusal !== null && $refusal !== '';
                        if (is_string($refusal)) {
                            $choice['message']['refusal_bytes'] = strlen($refusal);
                        }
                        unset($choice['message']['refusal']);
                    }
                }

                return $choice;
            }, $response['choices']);
        }

        return $response;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $data
     */
    private function stableJson(array $data): string
    {
        $normalized = $this->sortRecursive($data);

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $value
     * @return array<string, mixed>|array<int, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }

    private function openAiSchemaName(string $schemaName): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $schemaName) ?: 'structured_extraction';
        $name = trim($name, '_-');

        if ($name === '' || is_numeric($name[0])) {
            $name = 'schema_' . $name;
        }

        return mb_substr($name, 0, 64);
    }

    private function canPersist(): bool
    {
        return Schema::hasTable(CoreTableConstant::AI_EXTRACTION_RUNS);
    }
}
