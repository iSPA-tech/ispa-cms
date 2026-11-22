<?php

namespace Cms\Core\Services\Agentic;

/**
 * Keeps a Chat Completions payload valid for the OpenAI model it targets.
 *
 * GPT-5.6, GPT-6, the original GPT-5 trio and the o-series accept only the default `temperature` / `top_p`
 * (any other value is a 400), and every GPT-5.x / GPT-6 / o-series model rejects `max_tokens` in favour of
 * `max_completion_tokens`. Older models (GPT-4.x, GPT-5.4) keep receiving the payload unchanged.
 */
final class OpenAiModelParameters
{
    /** Sampling parameters reasoning-only models accept only at their default. */
    public const SAMPLING_PARAMS = ['temperature', 'top_p'];

    /**
     * Normalize a payload for its `model`.
     *
     * @param array<string, mixed> $payload Chat Completions payload.
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        $model = (string) ($payload['model'] ?? '');

        if (self::rejectsSamplingParams($model)) {
            foreach (self::SAMPLING_PARAMS as $param) {
                unset($payload[$param]);
            }
        }

        if (self::usesMaxCompletionTokens($model) && array_key_exists('max_tokens', $payload)) {
            $payload['max_completion_tokens'] ??= $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        if (isset($payload['reasoning_effort']) && trim((string) $payload['reasoning_effort']) === '') {
            unset($payload['reasoning_effort']);
        }

        return $payload;
    }

    /**
     * Whether the model accepts only the default sampling parameters.
     *
     * @param string $model OpenAI model id.
     * @return bool
     */
    public static function rejectsSamplingParams(string $model): bool
    {
        $model = strtolower(trim($model));

        return preg_match('/^(gpt-5\.6-|gpt-6|o\d)/', $model) === 1
            || preg_match('/^gpt-5(-mini|-nano|-pro)?(-\d{4}-\d{2}-\d{2})?$/', $model) === 1;
    }

    /**
     * Whether the model takes `max_completion_tokens` instead of `max_tokens`.
     *
     * @param string $model OpenAI model id.
     * @return bool
     */
    public static function usesMaxCompletionTokens(string $model): bool
    {
        return preg_match('/^(gpt-5|gpt-6|o\d)/', strtolower(trim($model))) === 1;
    }
}
