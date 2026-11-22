<?php

namespace Cms\Core\Services\Automation;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ModuleAutomationSettingsService
{
    private const MASK = '********';

    private const FREQUENCY_PRESETS = [
        'disabled' => ['label' => 'Disabled', 'expression' => ''],
        'every_5_minutes' => ['label' => 'Every 5 minutes', 'expression' => '*/5 * * * *'],
        'every_10_minutes' => ['label' => 'Every 10 minutes', 'expression' => '*/10 * * * *'],
        'every_15_minutes' => ['label' => 'Every 15 minutes', 'expression' => '*/15 * * * *'],
        'every_30_minutes' => ['label' => 'Every 30 minutes', 'expression' => '*/30 * * * *'],
        'hourly' => ['label' => 'Hourly', 'expression' => '0 * * * *'],
        'every_6_hours' => ['label' => 'Every 6 hours', 'expression' => '0 */6 * * *'],
        'daily' => ['label' => 'Daily', 'expression' => '0 3 * * *'],
    ];

    private const AI_PROVIDER_OPTIONS = [
        'agentic_ai' => 'Default agentic AI',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'deepseek' => 'DeepSeek',
        'grok' => 'Grok',
        'anthropic' => 'Anthropic',
    ];

    private const AI_OPTIONAL_PROVIDER_OPTIONS = [
        'disabled' => 'Disabled',
        'agentic_ai' => 'Default agentic AI',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'deepseek' => 'DeepSeek',
        'grok' => 'Grok',
        'anthropic' => 'Anthropic',
    ];

    private const GEOCODING_PROVIDER_OPTIONS = [
        'disabled' => 'Disabled',
        'nominatim' => 'Nominatim',
    ];

    private const WEATHER_PROVIDER_OPTIONS = [
        'auto' => 'Auto',
        'open_meteo' => 'Open-Meteo',
        'weatherkit' => 'WeatherKit',
        'disabled' => 'Disabled',
    ];

    private const API_KEY_PROVIDERS = [
        'openai' => 'OpenAI API key',
        'gemini' => 'Google Gemini API key',
        'deepseek' => 'DeepSeek API key',
        'grok' => 'Grok API key',
        'anthropic' => 'Anthropic API key',
    ];

    /**
     * @return array<string, mixed>
     */
    public function show(string $module): array
    {
        $module = $this->normalizeModule($module);
        $definition = $this->definition($module);
        $schedules = [];
        $schedulePresets = [];

        foreach ($definition['schedules'] as $step => $schedule) {
            $expression = $this->scheduleExpression($module, $step);
            $preset = $this->presetForExpression($expression, (string) $schedule['default_preset']);
            $schedulePresets[$step] = $preset;
            $schedules[$step] = [
                'label' => $schedule['label'],
                'preset' => $preset,
                'expression' => $expression,
            ];
        }

        return [
            'module' => $module,
            'label' => $definition['label'],
            'settings_table_ready' => $this->settingsTableReady(),
            'enabled' => $this->enabled($module),
            'pipeline_mode' => $this->pipelineMode($module),
            'pipeline_modes' => $definition['pipeline_modes'],
            'frequency_options' => $this->frequencyOptions(),
            'schedule_presets' => $schedulePresets,
            'schedules' => $schedules,
            'providers' => $this->providers($module),
            'provider_options' => $this->providerOptionsForModule($module),
            'models' => $this->models($module),
            'api_keys' => $this->apiKeySummaries($module),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(string $module, array $payload): array
    {
        if (! $this->settingsTableReady()) {
            throw new RuntimeException('settings_table_missing');
        }

        $module = $this->normalizeModule($module);
        $definition = $this->definition($module);

        if (array_key_exists('enabled', $payload)) {
            $this->saveSetting($this->settingKey($module, 'enabled'), (bool) $payload['enabled'], 'boolean', $module, 'Automation enabled');
        }

        if (isset($payload['pipeline_mode']) && in_array($payload['pipeline_mode'], $definition['pipeline_modes'], true)) {
            $this->saveSetting($this->settingKey($module, 'pipeline_mode'), (string) $payload['pipeline_mode'], 'select', $module, 'Pipeline mode');
        }

        foreach ((array) ($payload['schedule_presets'] ?? []) as $step => $preset) {
            if (! isset($definition['schedules'][$step], self::FREQUENCY_PRESETS[$preset])) {
                continue;
            }

            $this->saveSetting(
                $this->settingKey($module, 'schedules.' . $step),
                self::FREQUENCY_PRESETS[$preset]['expression'],
                'select',
                $module,
                (string) $definition['schedules'][$step]['label'],
            );
        }

        foreach ((array) ($payload['providers'] ?? []) as $type => $provider) {
            if (! isset($definition['providers'][$type])) {
                continue;
            }

            $allowed = array_keys($this->optionsForProviderDefinition($definition['providers'][$type]));
            if (! in_array($provider, $allowed, true)) {
                continue;
            }

            $this->saveSetting(
                $this->settingKey($module, 'providers.' . $type),
                (string) $provider,
                'select',
                $module,
                (string) $definition['providers'][$type]['label'],
            );
        }

        foreach ((array) ($payload['models'] ?? []) as $type => $model) {
            if (! isset($definition['models'][$type]) || ! is_scalar($model)) {
                continue;
            }

            $model = trim((string) $model);
            if ($model === '') {
                continue;
            }

            $this->saveSetting(
                $this->settingKey($module, 'models.' . $type),
                $model,
                'text',
                $module,
                (string) $definition['models'][$type]['label'],
            );
        }

        foreach ((array) ($payload['api_keys'] ?? []) as $provider => $value) {
            if (! array_key_exists($provider, self::API_KEY_PROVIDERS) || ! is_scalar($value)) {
                continue;
            }

            $secret = trim((string) $value);
            if ($secret === '' || $secret === self::MASK) {
                continue;
            }

            $this->saveSetting(
                $this->settingKey($module, 'api_keys.' . $provider),
                $secret,
                'password',
                $module,
                self::API_KEY_PROVIDERS[$provider],
                secret: true,
            );
        }

        $this->forgetRuntimeCaches();
        $this->applyProviderRuntimeConfig($module);

        return $this->show($module);
    }

    public function enabled(string $module): bool
    {
        $module = $this->normalizeModule($module);
        $default = (bool) config($this->definition($module)['config_root'] . '.ingestion.enabled', false);

        return $this->booleanSetting($this->settingKey($module, 'enabled'), $default);
    }

    public function pipelineMode(string $module): string
    {
        $module = $this->normalizeModule($module);
        $definition = $this->definition($module);
        $mode = $this->stringSetting(
            $this->settingKey($module, 'pipeline_mode'),
            (string) config($definition['config_root'] . '.ingestion.pipeline_mode', $definition['default_pipeline_mode'])
        );

        return in_array($mode, $definition['pipeline_modes'], true)
            ? $mode
            : (string) $definition['default_pipeline_mode'];
    }

    public function scheduleExpression(string $module, string $step): string
    {
        $module = $this->normalizeModule($module);
        $schedule = $this->definition($module)['schedules'][$step] ?? null;
        if (! is_array($schedule)) {
            return '';
        }

        $default = (string) config((string) $schedule['config'], $schedule['default_expression']);

        return trim($this->stringSetting($this->settingKey($module, 'schedules.' . $step), $default));
    }

    public function provider(string $module, string $type, ?string $fallback = null): string
    {
        $module = $this->normalizeModule($module);
        $definition = $this->definition($module)['providers'][$type] ?? null;
        if (! is_array($definition)) {
            return $fallback ?: 'agentic_ai';
        }

        $default = config((string) $definition['default_config']);
        if (($default === null || $default === '') && isset($definition['fallback_config'])) {
            $default = config((string) $definition['fallback_config']);
        }

        $provider = $this->stringSetting($this->settingKey($module, 'providers.' . $type), (string) ($fallback ?: $default ?: 'agentic_ai'));
        $allowed = array_keys($this->optionsForProviderDefinition($definition));

        return in_array($provider, $allowed, true) ? $provider : (string) ($fallback ?: 'agentic_ai');
    }

    public function providerForReporterModule(?string $reporterModule, string $type, ?string $fallback = null): string
    {
        $module = $this->normalizeReporterModule($reporterModule);

        return $module === null ? (string) ($fallback ?: 'agentic_ai') : $this->provider($module, $type, $fallback);
    }

    public function model(string $module, string $type, ?string $fallback = null): string
    {
        $module = $this->normalizeModule($module);
        $definition = $this->definition($module)['models'][$type] ?? null;
        if (! is_array($definition)) {
            return $fallback ?: 'gpt-5.6-luna';
        }

        $default = config((string) ($definition['default_config'] ?? ''), $definition['default'] ?? null);

        return $this->stringSetting(
            $this->settingKey($module, 'models.' . $type),
            (string) ($fallback ?: $default ?: 'gpt-5.6-luna'),
        );
    }

    public function hasProviderOverride(string $module, string $type): bool
    {
        $module = $this->normalizeModule($module);

        return $this->hasStoredValue($this->settingKey($module, 'providers.' . $type));
    }

    public function applyProviderRuntimeConfig(?string $module = null): void
    {
        $normalized = $module === null ? null : $this->normalizeReporterModule($module);
        $modules = $normalized === null ? array_keys($this->catalog()) : [$normalized];

        foreach ($modules as $moduleName) {
            foreach (array_keys(self::API_KEY_PROVIDERS) as $provider) {
                $key = $this->stringSetting($this->settingKey($moduleName, 'api_keys.' . $provider), '');
                if ($key === '') {
                    continue;
                }

                $configPath = $this->providerApiKeyConfigPath($provider);
                if ($configPath !== null) {
                    config()->set($configPath, $key);
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function pipelineModes(string $module): array
    {
        return $this->definition($this->normalizeModule($module))['pipeline_modes'];
    }

    /**
     * @return array<int, string>
     */
    public function scheduleSteps(string $module): array
    {
        return array_keys($this->definition($this->normalizeModule($module))['schedules']);
    }

    /**
     * @return array<int, string>
     */
    public function frequencyPresetKeys(): array
    {
        return array_keys(self::FREQUENCY_PRESETS);
    }

    /**
     * @return array<int, string>
     */
    public function providerTypes(string $module): array
    {
        return array_keys($this->definition($this->normalizeModule($module))['providers']);
    }

    /**
     * @return array<int, string>
     */
    public function modelTypes(string $module): array
    {
        return array_keys($this->definition($this->normalizeModule($module))['models'] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function providerOptionKeys(string $module, string $type): array
    {
        $definition = $this->definition($this->normalizeModule($module))['providers'][$type] ?? null;

        return is_array($definition) ? array_keys($this->optionsForProviderDefinition($definition)) : [];
    }

    /**
     * @return array<int, string>
     */
    public function apiKeyProviderKeys(): array
    {
        return array_keys(self::API_KEY_PROVIDERS);
    }

    public function settingsTableReady(): bool
    {
        try {
            return Schema::hasTable(CoreTableConstant::SETTINGS);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function providers(string $module): array
    {
        $providers = [];
        foreach (array_keys($this->definition($module)['providers']) as $type) {
            $providers[$type] = $this->provider($module, $type);
        }

        return $providers;
    }

    /**
     * @return array<string, array{label: string, value: string}>
     */
    private function models(string $module): array
    {
        $models = [];
        foreach (($this->definition($module)['models'] ?? []) as $type => $definition) {
            $models[$type] = [
                'label' => (string) $definition['label'],
                'value' => $this->model($module, $type),
            ];
        }

        return $models;
    }

    /**
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function providerOptionsForModule(string $module): array
    {
        $options = [];
        foreach ($this->definition($module)['providers'] as $type => $definition) {
            $options[$type] = $this->optionsToList($this->optionsForProviderDefinition($definition));
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function optionsForProviderDefinition(array $definition): array
    {
        return match ((string) $definition['options']) {
            'ai_optional' => self::AI_OPTIONAL_PROVIDER_OPTIONS,
            'geocoding' => self::GEOCODING_PROVIDER_OPTIONS,
            'weather' => self::WEATHER_PROVIDER_OPTIONS,
            default => self::AI_PROVIDER_OPTIONS,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function frequencyOptions(): array
    {
        return $this->optionsToList(array_map(
            static fn (array $option): string => $option['label'],
            self::FREQUENCY_PRESETS
        ));
    }

    /**
     * @param array<string, string> $options
     * @return array<int, array{value: string, label: string}>
     */
    private function optionsToList(array $options): array
    {
        return collect($options)
            ->map(static fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{label: string, has_value: bool, value: null}>
     */
    private function apiKeySummaries(string $module): array
    {
        $keys = [];
        foreach (self::API_KEY_PROVIDERS as $provider => $label) {
            $keys[$provider] = [
                'label' => $label,
                'has_value' => $this->hasStoredValue($this->settingKey($module, 'api_keys.' . $provider)),
                'value' => null,
            ];
        }

        return $keys;
    }

    private function presetForExpression(string $expression, string $defaultPreset): string
    {
        foreach (self::FREQUENCY_PRESETS as $preset => $option) {
            if ($option['expression'] === $expression) {
                return $preset;
            }
        }

        return array_key_exists($defaultPreset, self::FREQUENCY_PRESETS) ? $defaultPreset : 'disabled';
    }

    /**
     * The catalog the modules registered under the `automation-modules` config key (module key => definition).
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        return (array) config('automation-modules', []);
    }

    /**
     * The catalog key of a module name in any spelling (StudlyCase, kebab-case, snake_case), or null when none matches.
     */
    private function normalizeReporterModule(?string $module): ?string
    {
        if ($module === null || $module === '') {
            return null;
        }
        $key = Str::snake(str_replace('-', '_', $module));

        return array_key_exists($key, $this->catalog()) ? $key : null;
    }

    private function normalizeModule(string $module): string
    {
        $normalized = $this->normalizeReporterModule($module) ?? $module;
        if (! array_key_exists($normalized, $this->catalog())) {
            throw new RuntimeException("Unsupported automation module [{$module}].");
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $module): array
    {
        return $this->catalog()[$module];
    }

    private function settingKey(string $module, string $path): string
    {
        return $module . '.automation.' . $path;
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        $value = $this->settingValue($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function stringSetting(string $key, string $default): string
    {
        $value = $this->settingValue($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function settingValue(string $key, mixed $default): mixed
    {
        if (! $this->settingsTableReady()) {
            return $default;
        }

        try {
            $setting = Setting::query()->where('setting_key', $key)->first();
        } catch (Throwable) {
            return $default;
        }

        $value = $setting?->value;

        return $value === null || $value === '' ? $default : $value;
    }

    private function hasStoredValue(string $key): bool
    {
        if (! $this->settingsTableReady()) {
            return false;
        }

        try {
            $value = DB::table(CoreTableConstant::SETTINGS)->where('setting_key', $key)->value('value');
        } catch (Throwable) {
            return false;
        }

        return is_string($value) && trim($value) !== '';
    }

    private function saveSetting(
        string $key,
        mixed $value,
        string $type,
        string $module,
        string $label,
        bool $secret = false,
    ): void {
        $setting = Setting::withTrashed()->firstOrNew(['setting_key' => $key]);
        if (method_exists($setting, 'trashed') && $setting->trashed()) {
            $setting->restore();
        }

        $setting->fill([
            'setting_key' => $key,
            'type' => $type,
            'group_name' => $module . '.automation',
            'name' => mb_substr(str_replace($module . '.automation.', '', $key), 0, 50),
            'input_setting' => [
                'title' => $label,
                'description' => null,
                'isPublic' => ! $secret,
                'isEnv' => false,
            ],
            'value' => $value,
        ]);
        $setting->save();
    }

    private function forgetRuntimeCaches(): void
    {
        try {
            app(CMSSettingRuntime::class)->forget();
        } catch (Throwable) {
            //
        }
    }

    private function providerApiKeyConfigPath(string $provider): ?string
    {
        return match ($provider) {
            'openai' => 'core.ai_structured_extraction.providers.openai.api_key',
            'gemini' => 'core.ai_structured_extraction.providers.gemini.api_key',
            'deepseek' => 'core.ai_structured_extraction.providers.deepseek.api_key',
            'grok' => 'core.ai_structured_extraction.providers.grok.api_key',
            'anthropic' => 'core.ai_structured_extraction.providers.anthropic.api_key',
            default => null,
        };
    }
}
