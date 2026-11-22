<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * DB-backed runtime registry for AI provider and per-task model selection.
 *
 * Reads plain Core settings rows so values are admin-editable without a
 * deploy. Resolution precedence stays with the caller:
 *   explicit call-site option  >  task setting  >  provider setting  >
 *   env/config default (unchanged fallback — empty registry is a no-op).
 *
 * Setting keys:
 *   core.ai_runtime.tasks.{task_key}.provider|model|temperature|reasoning_effort
 *   core.ai_runtime.tasks.{task_key}.fallback_models   (comma-separated, max 3)
 *   core.ai_runtime.providers.{provider}.model|api_key|base_uri
 *   core.ai_runtime.budget.monthly_usd|kill_switch|warning_percent
 *   core.ai_runtime.pricing.{model}.input_per_1m_usd|cached_input_per_1m_usd|output_per_1m_usd
 */
final class AiRuntimeConfigResolver
{
    public const TASK_KEY_PREFIX = 'core.ai_runtime.tasks.';

    public const PROVIDER_KEY_PREFIX = 'core.ai_runtime.providers.';

    public const BUDGET_KEY_PREFIX = 'core.ai_runtime.budget.';

    public const PRICING_KEY_PREFIX = 'core.ai_runtime.pricing.';

    public const SPIKE_KEY_PREFIX = 'core.ai_runtime.spike.';

    public const TRACE_SAMPLE_KEY = 'core.ai_runtime.trace.sample_percent';

    public const DEFAULT_PROVIDER_KEY = 'core.ai_runtime.default_provider';

    /** @var array<string, string> */
    private array $settingCache = [];

    private ?bool $tableReady = null;

    /**
     * @return array{provider: ?string, model: ?string, temperature: ?float, reasoning_effort: ?string, fallback_models: list<string>}
     */
    public function taskOverrides(?string $taskKey): array
    {
        $taskKey = $this->normalizeKey((string) $taskKey);
        if ($taskKey === '') {
            return ['provider' => null, 'model' => null, 'temperature' => null, 'reasoning_effort' => null, 'fallback_models' => []];
        }

        $prefix = self::TASK_KEY_PREFIX . $taskKey . '.';
        $provider = $this->setting($prefix . 'provider');
        $model = $this->setting($prefix . 'model');
        $temperature = $this->setting($prefix . 'temperature');
        $reasoningEffort = strtolower($this->setting($prefix . 'reasoning_effort'));

        return [
            'provider' => $provider !== '' ? $provider : null,
            'model' => $model !== '' ? $model : null,
            'temperature' => is_numeric($temperature) ? (float) $temperature : null,
            'reasoning_effort' => in_array($reasoningEffort, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh'], true) ? $reasoningEffort : null,
            'fallback_models' => self::parseModelList($this->setting($prefix . 'fallback_models')),
        ];
    }

    /**
     * Monthly LLM budget settings, always read fresh from the settings table.
     *
     * This resolver is a singleton that lives across queue jobs, so the cached
     * values are dropped first: a kill switch or cap saved in the admin must stop
     * long-running workers without a restart.
     *
     * @return array{monthly_usd: float, kill_switch: bool, warning_percent: int}
     */
    public function budget(): array
    {
        foreach (['monthly_usd', 'warning_percent', 'kill_switch'] as $field) {
            unset($this->settingCache[self::BUDGET_KEY_PREFIX . $field]);
        }

        $monthly = $this->setting(self::BUDGET_KEY_PREFIX . 'monthly_usd');
        $percent = $this->setting(self::BUDGET_KEY_PREFIX . 'warning_percent');

        return [
            'monthly_usd' => is_numeric($monthly) ? max(0.0, (float) $monthly) : 0.0,
            'kill_switch' => in_array(strtolower($this->setting(self::BUDGET_KEY_PREFIX . 'kill_switch')), ['1', 'true', 'yes', 'on'], true),
            'warning_percent' => max(1, min(100, is_numeric($percent) ? (int) $percent : (int) config('core.ai_usage.warning_percent', 80))),
        ];
    }

    /**
     * Daily cost spike alert settings, read fresh (settings override config `core.ai_usage.spike`).
     *
     * @return array{enabled: bool, multiplier: float, min_usd: float, baseline_days: int}
     */
    public function spike(): array
    {
        $prefix = self::SPIKE_KEY_PREFIX;
        foreach (['enabled', 'multiplier', 'min_usd'] as $field) {
            unset($this->settingCache[$prefix . $field]);
        }

        $enabled = strtolower($this->setting($prefix . 'enabled'));
        $multiplier = $this->setting($prefix . 'multiplier');
        $minUsd = $this->setting($prefix . 'min_usd');

        return [
            'enabled' => $enabled === ''
                ? (bool) config('core.ai_usage.spike.enabled', true)
                : in_array($enabled, ['1', 'true', 'yes', 'on'], true),
            'multiplier' => max(1.0, is_numeric($multiplier) ? (float) $multiplier : (float) config('core.ai_usage.spike.multiplier', 3.0)),
            'min_usd' => max(0.0, is_numeric($minUsd) ? (float) $minUsd : (float) config('core.ai_usage.spike.min_usd', 2.0)),
            'baseline_days' => max(1, (int) config('core.ai_usage.spike.baseline_days', 7)),
        ];
    }

    /**
     * Share of successful AI calls written to the call log (0 disables), read fresh.
     *
     * @return int 0..100; the setting overrides config `core.ai_usage.trace.sample_percent`.
     */
    public function traceSamplePercent(): int
    {
        unset($this->settingCache[self::TRACE_SAMPLE_KEY]);
        $stored = $this->setting(self::TRACE_SAMPLE_KEY);
        $value = is_numeric($stored) ? (int) $stored : (int) config('core.ai_usage.trace.sample_percent', 100);

        return max(0, min(100, $value));
    }

    /**
     * Parse a comma-separated model list: trimmed, non-empty, unique, max 3.
     *
     * @return list<string>
     */
    public static function parseModelList(string $value): array
    {
        $models = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $m): bool => $m !== '')));

        return array_slice($models, 0, 3);
    }

    /**
     * Registry-level default provider, sitting between task settings and the
     * env/config default in the resolution chain.
     */
    public function defaultProvider(): ?string
    {
        $stored = $this->providerConfigKey($this->setting(self::DEFAULT_PROVIDER_KEY));

        return $stored !== '' ? $stored : null;
    }

    /**
     * One-stop resolution for AI flows that call providers directly
     * (outside AiStructuredExtractionService). Admin task settings win over
     * the caller's own fallbacks, so every flow is manageable from the
     * admin; an unconfigured registry returns the caller's legacy values.
     *
     * @return array{provider: string, model: string, temperature: ?float}
     */
    public function runtimeFor(
        string $taskKey,
        ?string $fallbackProvider = null,
        ?string $fallbackModel = null,
        ?float $fallbackTemperature = null,
    ): array {
        $task = $this->taskOverrides($taskKey);

        $provider = $this->providerConfigKey((string) ($task['provider']
            ?? $fallbackProvider
            ?? $this->defaultProvider()
            ?? config('core.ai_structured_extraction.provider', 'agentic_ai')));
        if ($provider !== '') {
            $this->applyProviderRuntimeOverrides($provider);
        }

        $model = (string) ($task['model']
            ?? $fallbackModel
            ?? ($provider !== ''
                ? config("core.ai_structured_extraction.providers.{$provider}.model")
                : null)
            ?? config('core.ai_structured_extraction.model', 'gpt-5.6-luna'));

        return [
            'provider' => $provider !== '' ? $provider : 'agentic_ai',
            'model' => $model,
            'temperature' => $task['temperature'] ?? $fallbackTemperature,
        ];
    }

    /**
     * Push stored provider credentials and model into the runtime config so
     * the existing provider factory and default-model lookup pick them up.
     */
    public function applyProviderRuntimeOverrides(?string $driver): void
    {
        $providerKey = $this->providerConfigKey((string) $driver);
        if ($providerKey === '') {
            return;
        }

        $prefix = self::PROVIDER_KEY_PREFIX . $providerKey . '.';
        foreach (['model', 'api_key', 'base_uri'] as $field) {
            $value = $this->setting($prefix . $field);
            if ($value !== '') {
                config()->set(
                    "core.ai_structured_extraction.providers.{$providerKey}.{$field}",
                    $value,
                );
            }
        }
    }

    /**
     * Map driver aliases onto the canonical provider config key, mirroring
     * AiStructuredExtractionService::defaultModelForProvider().
     */
    public function providerConfigKey(string $driver): string
    {
        $driver = strtolower(trim($driver));
        if ($driver === '' || in_array($driver, ['agentic_ai', 'default'], true)) {
            return '';
        }

        return match ($driver) {
            'google', 'google_gemini' => 'gemini',
            'xai', 'x.ai' => 'grok',
            'claude' => 'anthropic',
            default => $driver,
        };
    }

    public function flushCache(): void
    {
        $this->settingCache = [];
        $this->tableReady = null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    private function setting(string $key): string
    {
        if (array_key_exists($key, $this->settingCache)) {
            return $this->settingCache[$key];
        }

        $value = '';
        if ($this->settingsTableReady()) {
            try {
                // Read through the model so EncryptedSettingValue decrypts
                // secret rows (api keys) transparently.
                $stored = Setting::query()->where('setting_key', $key)->first()?->value;
                $value = is_scalar($stored) ? trim((string) $stored) : '';
            } catch (Throwable) {
                $value = '';
            }
        }

        return $this->settingCache[$key] = $value;
    }

    private function settingsTableReady(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }

        try {
            return $this->tableReady = Schema::hasTable(CoreTableConstant::SETTINGS);
        } catch (Throwable) {
            return $this->tableReady = false;
        }
    }
}
