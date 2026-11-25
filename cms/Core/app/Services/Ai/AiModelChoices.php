<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai;

/**
 * Provider + model pairs a workflow author or a reviewer may pick for one AI call (
 * "chạy lại node AI với option khác").
 *
 * A provider is listed only when it has an API key (stored on the AI Runtime page or in env), the same test as the AI
 * Runtime providers tab. Its models: the provider's own stored/env model, the models (and fallback models) of the task
 * rows that use it, and the priced models (`core.ai_usage.pricing` plus pricing settings) of its family; a priced model
 * whose family cannot be told is offered under every keyed provider. Nothing here ever carries an API key or base URI.
 *
 * `value` is `"<provider>:<model>"`; AiTaskRuntime::run() refuses an override that is not in this list.
 *
 * @package Cms\Core\Services\Ai
 * @since   2026-11-24
 */
class AiModelChoices
{
    /** Model-name prefixes per provider, to place a priced model under its provider. */
    private const FAMILIES = [
        'openai' => '/^(gpt-|o\d|chatgpt-)/i',
        'anthropic' => '/^claude-/i',
        'deepseek' => '/^deepseek-/i',
        'gemini' => '/^gemini-/i',
        'grok' => '/^grok-/i',
    ];

    /** @var list<array{value: string, provider: string, provider_label: string, model: string, price_known: bool}>|null */
    private ?array $cache = null;

    public function __construct(private readonly AiRuntimeSettingsService $settings)
    {
    }

    /**
     * @return list<array{value: string, provider: string, provider_label: string, model: string, price_known: bool}>
     */
    public function selectable(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $keyed = [];
        foreach ($this->settings->providerRows() as $row) {
            if (($row['has_api_key'] ?? false) === true || ($row['env_has_api_key'] ?? false) === true) {
                $keyed[(string) $row['key']] = $row;
            }
        }
        if ($keyed === []) {
            return $this->cache = [];
        }

        $models = [];
        $add = static function (string $provider, mixed $model) use (&$models, $keyed): void {
            $model = is_string($model) ? trim($model) : '';
            if ($model !== '' && isset($keyed[$provider]) && mb_strlen($model) <= 100 && !str_contains($model, ':')) {
                $models[$provider][$model] = true;
            }
        };

        foreach ($keyed as $provider => $row) {
            $add($provider, $row['model'] ?? null);
            $add($provider, $row['env_model'] ?? null);
        }
        foreach ($this->settings->taskRows() as $task) {
            $provider = (string) ($task['provider'] ?? '');
            $add($provider, $task['model'] ?? null);
            foreach (AiRuntimeConfigResolver::parseModelList((string) ($task['fallback_models'] ?? '')) as $fallback) {
                $add($provider, $fallback);
            }
        }

        $priced = array_keys($this->settings->pricing());
        foreach ($priced as $model) {
            $model = (string) $model;
            // Embedding models cannot answer a prompt.
            if (str_contains($model, 'embedding')) {
                continue;
            }
            $family = $this->familyOf($model);
            foreach (array_keys($keyed) as $provider) {
                if ($family === null || $family === $provider) {
                    $add($provider, $model);
                }
            }
        }

        $pricedSet = array_flip(array_map('strval', $priced));
        $out = [];
        ksort($models);
        foreach ($models as $provider => $names) {
            $names = array_keys($names);
            sort($names);
            foreach ($names as $model) {
                $out[] = [
                    'value' => $provider . ':' . $model,
                    'provider' => $provider,
                    'provider_label' => (string) ($keyed[$provider]['label'] ?? $provider),
                    'model' => $model,
                    'price_known' => isset($pricedSet[$model]),
                ];
            }
        }

        return $this->cache = $out;
    }

    public function isSelectable(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && in_array($value, array_column($this->selectable(), 'value'), true);
    }

    /**
     * Split `"provider:model"` (no check against the list).
     *
     * @return array{provider: string, model: string}|null
     */
    public function parse(string $value): ?array
    {
        $value = trim($value);
        $colon = strpos($value, ':');
        if ($colon === false) {
            return null;
        }
        $provider = strtolower(trim(substr($value, 0, $colon)));
        $model = trim(substr($value, $colon + 1));

        return $provider !== '' && $model !== '' ? ['provider' => $provider, 'model' => $model] : null;
    }

    /**
     * Rewrite-form choices for a `type: model` revision option: `{value, label}`, labels plain text.
     *
     * @return list<array{value: string, label: string}>
     */
    public function revisionChoices(): array
    {
        return array_map(
            static fn (array $choice): array => ['value' => $choice['value'], 'label' => $choice['provider_label'] . ' · ' . $choice['model']],
            $this->selectable(),
        );
    }

    private function familyOf(string $model): ?string
    {
        foreach (self::FAMILIES as $provider => $pattern) {
            if (preg_match($pattern, $model) === 1) {
                return $provider;
            }
        }

        return null;
    }
}
