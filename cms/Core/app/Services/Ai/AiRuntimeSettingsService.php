<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AiExtractionRun;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\Usage\AiUsageLedger;
use Illuminate\Support\Facades\DB;

/**
 * Admin read/write layer over the AI runtime registry settings.
 *
 * Providers tab: per-provider default model, base URI and API key
 * (write-only, stored encrypted via the Setting `password` type).
 * Tasks tab: per-task provider/model/temperature overrides, auto-seeded
 * from the distinct task keys already logged in ai_extraction_runs and
 * in the AI usage ledger (AiTaskRuntime flows).
 */
final class AiRuntimeSettingsService
{
    public const DRIVERS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic (Claude)',
        'deepseek' => 'DeepSeek',
        'gemini' => 'Google Gemini',
        'grok' => 'xAI Grok',
    ];

    /**
     * Official model-catalog documentation per provider, linked from the
     * admin page so editors can pick and copy exact model IDs.
     */
    public const DRIVER_DOCS = [
        'openai' => 'https://platform.openai.com/docs/models',
        'anthropic' => 'https://platform.claude.com/docs/en/about-claude/models/overview',
        'deepseek' => 'https://api-docs.deepseek.com/quick_start/pricing',
        'gemini' => 'https://ai.google.dev/gemini-api/docs/models',
        'grok' => 'https://docs.x.ai/docs/models',
    ];

    private const GROUP = 'core.ai_runtime';

    public function __construct(
        private readonly AiRuntimeConfigResolver $resolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $envDefault = $this->resolver->providerConfigKey(
            (string) config('core.ai_structured_extraction.provider', 'agentic_ai'),
        );
        $configuredDefault = $this->resolver->providerConfigKey(
            $this->storedValue(AiRuntimeConfigResolver::DEFAULT_PROVIDER_KEY),
        );
        $effectiveDefault = $configuredDefault !== '' ? $configuredDefault : $envDefault;

        return [
            'drivers' => collect(self::DRIVERS)
                ->map(static fn (string $label, string $key): array => [
                    'key' => $key,
                    'label' => $label,
                    'docs_url' => self::DRIVER_DOCS[$key] ?? null,
                ])
                ->values()
                ->all(),
            'default_provider' => $effectiveDefault !== '' ? $effectiveDefault : 'agentic_ai',
            'configured_default_provider' => $configuredDefault,
            'env_default_provider' => $envDefault !== '' ? $envDefault : 'agentic_ai',
            'global_default_model' => (string) config('core.ai_structured_extraction.model', 'gpt-5.6-luna'),
            'providers' => $this->providerRows(),
            'tasks' => $this->taskRows(),
            'budget' => $this->resolver->budget() + ['spike' => $this->resolver->spike()],
            'pricing' => $this->pricing(),
        ];
    }

    /**
     * Model prices (config plus settings overrides) keyed by model name.
     *
     * @return array<string, array{input_per_1m_usd: float, cached_input_per_1m_usd: ?float, output_per_1m_usd: ?float}>
     */
    public function pricing(): array
    {
        $models = array_keys((array) config('core.ai_usage.pricing', []));
        $prefix = AiRuntimeConfigResolver::PRICING_KEY_PREFIX;
        try {
            foreach (Setting::query()->where('setting_key', 'like', $prefix . '%')->pluck('setting_key') as $key) {
                $rest = substr((string) $key, strlen($prefix));
                $dot = strrpos($rest, '.');
                if ($dot !== false) {
                    $models[] = substr($rest, 0, $dot);
                }
            }
        } catch (\Throwable) {
            // Settings table missing: config prices only.
        }

        $ledger = app(AiUsageLedger::class);
        $out = [];
        foreach (array_unique(array_map('strval', $models)) as $model) {
            $price = $model !== '' ? $ledger->price($model) : null;
            if ($price !== null) {
                $out[$model] = $price;
            }
        }

        return $out;
    }

    /**
     * Registry-level system default provider. Empty clears the setting so
     * the env/config default applies again.
     */
    public function saveDefaultProvider(string $provider): void
    {
        $key = $this->resolver->providerConfigKey($provider);
        if ($key !== '' && ! array_key_exists($key, self::DRIVERS)) {
            return;
        }

        $this->putSetting(AiRuntimeConfigResolver::DEFAULT_PROVIDER_KEY, $key);
        $this->resolver->flushCache();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function saveProviders(array $rows): void
    {
        foreach ($rows as $row) {
            $key = $this->resolver->providerConfigKey((string) ($row['key'] ?? ''));
            if ($key === '' || ! array_key_exists($key, self::DRIVERS)) {
                continue;
            }

            $prefix = AiRuntimeConfigResolver::PROVIDER_KEY_PREFIX . $key . '.';
            $this->putSetting($prefix . 'model', trim((string) ($row['model'] ?? '')));
            $this->putSetting($prefix . 'base_uri', trim((string) ($row['base_uri'] ?? '')));

            if ((bool) ($row['clear_api_key'] ?? false)) {
                $this->putSetting($prefix . 'api_key', '');
            } elseif (trim((string) ($row['api_key'] ?? '')) !== '') {
                // Write-only: an empty field means "keep the stored key".
                $this->putSetting($prefix . 'api_key', trim((string) $row['api_key']), secret: true);
            }
        }

        $this->resolver->flushCache();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function saveTasks(array $rows): void
    {
        foreach ($rows as $row) {
            $task = strtolower(trim((string) ($row['task'] ?? '')));
            if ($task === '') {
                continue;
            }

            $provider = $this->resolver->providerConfigKey((string) ($row['provider'] ?? ''));
            if ($provider !== '' && ! array_key_exists($provider, self::DRIVERS)) {
                $provider = '';
            }
            $temperature = $row['temperature'] ?? null;

            $prefix = AiRuntimeConfigResolver::TASK_KEY_PREFIX . $task . '.';
            $this->putSetting($prefix . 'provider', $provider);
            $this->putSetting($prefix . 'model', trim((string) ($row['model'] ?? '')));
            $this->putSetting(
                $prefix . 'temperature',
                is_numeric($temperature) ? (string) (float) $temperature : '',
            );
            // GPT-5.x reasoning effort; empty leaves the provider default.
            $this->putSetting($prefix . 'reasoning_effort', strtolower(trim((string) ($row['reasoning_effort'] ?? ''))));
            $this->putSetting($prefix . 'fallback_models', implode(',', AiRuntimeConfigResolver::parseModelList((string) ($row['fallback_models'] ?? ''))));
        }

        $this->resolver->flushCache();
    }

    /**
     * Save the monthly LLM budget settings.
     *
     * @param array<string, mixed> $data monthly_usd, kill_switch, warning_percent, spike_enabled, spike_multiplier, spike_min_usd.
     */
    public function saveBudget(array $data): void
    {
        $prefix = AiRuntimeConfigResolver::BUDGET_KEY_PREFIX;
        if (array_key_exists('monthly_usd', $data)) {
            $monthly = $data['monthly_usd'];
            $this->putSetting($prefix . 'monthly_usd', is_numeric($monthly) && (float) $monthly > 0 ? (string) (float) $monthly : '');
        }
        if (array_key_exists('kill_switch', $data)) {
            $this->putSetting($prefix . 'kill_switch', filter_var($data['kill_switch'], FILTER_VALIDATE_BOOLEAN) ? '1' : '');
        }
        if (array_key_exists('warning_percent', $data)) {
            $percent = $data['warning_percent'];
            $this->putSetting($prefix . 'warning_percent', is_numeric($percent) ? (string) max(1, min(100, (int) $percent)) : '');
        }
        $spike = AiRuntimeConfigResolver::SPIKE_KEY_PREFIX;
        if (array_key_exists('spike_enabled', $data) && $data['spike_enabled'] !== null) {
            // Stored explicitly ('0' / '1'): an empty value would fall back to the config default (enabled).
            $this->putSetting($spike . 'enabled', filter_var($data['spike_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        if (array_key_exists('spike_multiplier', $data) && is_numeric($data['spike_multiplier'])) {
            $this->putSetting($spike . 'multiplier', (string) max(1.0, min(20.0, (float) $data['spike_multiplier'])));
        }
        if (array_key_exists('spike_min_usd', $data) && is_numeric($data['spike_min_usd'])) {
            $this->putSetting($spike . 'min_usd', (string) max(0.0, (float) $data['spike_min_usd']));
        }

        $this->resolver->flushCache();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providerRows(): array
    {
        $rows = [];

        foreach (self::DRIVERS as $key => $label) {
            $prefix = AiRuntimeConfigResolver::PROVIDER_KEY_PREFIX . $key . '.';
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'docs_url' => self::DRIVER_DOCS[$key] ?? null,
                'model' => $this->storedValue($prefix . 'model'),
                'base_uri' => $this->storedValue($prefix . 'base_uri'),
                'has_api_key' => $this->storedValue($prefix . 'api_key') !== '',
                'env_model' => (string) config("core.ai_structured_extraction.providers.{$key}.model", ''),
                'env_has_api_key' => trim((string) config("core.ai_structured_extraction.providers.{$key}.api_key", '')) !== '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function taskRows(): array
    {
        $logged = collect();
        try {
            $logged = AiExtractionRun::query()
                ->select('task')
                ->selectRaw('COUNT(*) as runs')
                ->selectRaw('MAX(created_at) as last_run_at')
                ->selectRaw('MAX(module) as module')
                ->whereNotNull('task')
                ->where('task', '!=', '')
                ->groupBy('task')
                ->orderByDesc(DB::raw('MAX(created_at)'))
                ->limit(200)
                ->get();
        } catch (\Throwable) {
            // Table missing (fresh install): matrix starts from settings only.
        }

        $context = ['catalog' => (new AiTaskCatalog())->all(), 'latest' => $this->latestLedgerModels()];
        $rows = [];
        $seen = [];

        foreach ($logged as $run) {
            $task = strtolower(trim((string) $run->task));
            if ($task === '' || isset($seen[$task])) {
                continue;
            }

            $seen[$task] = true;
            $rows[] = $this->taskRow($task, (int) $run->runs, $run->last_run_at, (string) $run->module, true, $context);
        }

        $configured = $this->configuredTaskKeys();

        // Tasks metered by the usage ledger (AiTaskRuntime flows of a client module or content pipeline
        // never write ai_extraction_runs). Tasks seen only with via=direct are listed read-only.
        try {
            $metered = DB::table(CoreTableConstant::AI_USAGE_DAILY)
                ->select('task_key')
                ->selectRaw('SUM(request_count) as runs')
                ->selectRaw('MAX(updated_at) as last_run_at')
                ->selectRaw("SUM(CASE WHEN via = 'direct' THEN 0 ELSE 1 END) as runtime_rows")
                ->where('task_key', '!=', 'unattributed')
                ->groupBy('task_key')
                ->orderByDesc(DB::raw('MAX(updated_at)'))
                ->limit(200)
                ->get();
        } catch (\Throwable) {
            $metered = collect();
        }
        foreach ($metered as $usage) {
            $task = strtolower(trim((string) $usage->task_key));
            if ($task !== '' && ! isset($seen[$task])) {
                $seen[$task] = true;
                $configurable = (int) $usage->runtime_rows > 0 || in_array($task, $configured, true);
                $rows[] = $this->taskRow($task, (int) $usage->runs, $usage->last_run_at, '', $configurable, $context);
            }
        }

        // Configured tasks that have no logged run yet must stay visible.
        foreach ($configured as $task) {
            if (! isset($seen[$task])) {
                $seen[$task] = true;
                $rows[] = $this->taskRow($task, 0, null, '', true, $context);
            }
        }

        return $rows;
    }

    /**
     * Provider/model of the most recent ledger row per task (any month, by updated_at).
     *
     * @return array<string, array{provider: string, model: string}>
     */
    private function latestLedgerModels(): array
    {
        try {
            $rows = DB::table(CoreTableConstant::AI_USAGE_DAILY)
                ->select('task_key', 'provider', 'model')
                ->selectRaw('MAX(updated_at) as last_at')
                ->groupBy('task_key', 'provider', 'model')
                ->orderByDesc('last_at')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $latest = [];
        foreach ($rows as $row) {
            $task = strtolower(trim((string) $row->task_key));
            if (! isset($latest[$task])) {
                $latest[$task] = ['provider' => (string) $row->provider, 'model' => (string) $row->model];
            }
        }

        return $latest;
    }

    /**
     * @param array{catalog: array<string, array{label: string, module: string}>, latest: array<string, array{provider: string, model: string}>} $context
     * @return array<string, mixed>
     */
    private function taskRow(string $task, int $runs, mixed $lastRunAt, string $module, bool $configurable, array $context): array
    {
        $overrides = $this->resolver->taskOverrides($task);
        $entry = $context['catalog'][$task] ?? null;
        $latest = $context['latest'][$task] ?? null;

        if ($overrides['provider'] !== null || $overrides['model'] !== null) {
            $provider = $overrides['provider'] ?? ($latest['provider'] ?? null);
            // Same order AiTaskRuntime applies at call time: admin provider model, then env/config model.
            $providerModel = $provider !== null
                ? $this->storedValue(AiRuntimeConfigResolver::PROVIDER_KEY_PREFIX . $this->resolver->providerConfigKey($provider) . '.model')
                : '';
            $model = $overrides['model']
                ?? ($providerModel !== '' ? $providerModel
                    : ($provider !== null ? (string) config("core.ai_structured_extraction.providers.{$provider}.model", '') : ''));
            $effective = ['provider' => $provider, 'model' => $model !== '' ? $model : null, 'source' => 'task'];
        } elseif ($latest !== null) {
            $effective = ['provider' => $latest['provider'], 'model' => $latest['model'], 'source' => 'observed'];
        } else {
            $effective = ['provider' => null, 'model' => null, 'source' => 'unknown'];
        }

        return [
            'task' => $task,
            'label' => $entry['label'] ?? ucfirst(str_replace('_', ' ', $task)),
            'module' => $module !== '' ? $module : ($entry['module'] ?? ''),
            'configurable' => $configurable,
            'effective' => $effective,
            'runs' => $runs,
            'last_run_at' => $lastRunAt ? (string) $lastRunAt : null,
            'provider' => $overrides['provider'] ?? '',
            'model' => $overrides['model'] ?? '',
            'temperature' => $overrides['temperature'],
            'reasoning_effort' => $overrides['reasoning_effort'] ?? '',
            'fallback_models' => implode(',', $overrides['fallback_models']),
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredTaskKeys(): array
    {
        try {
            $prefix = AiRuntimeConfigResolver::TASK_KEY_PREFIX;

            return Setting::query()
                ->where('setting_key', 'like', $prefix . '%')
                ->pluck('setting_key')
                ->map(static function (string $key) use ($prefix): string {
                    $rest = substr($key, strlen($prefix));
                    $dot = strrpos($rest, '.');

                    return strtolower($dot === false ? $rest : substr($rest, 0, $dot));
                })
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function storedValue(string $key): string
    {
        try {
            $value = Setting::query()->where('setting_key', $key)->first()?->value;

            return is_scalar($value) ? trim((string) $value) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function putSetting(string $key, string $value, bool $secret = false): void
    {
        if ($value === '') {
            Setting::query()->where('setting_key', $key)->forceDelete();

            return;
        }

        $setting = Setting::withTrashed()->firstOrNew(['setting_key' => $key]);
        if (method_exists($setting, 'trashed') && $setting->trashed()) {
            $setting->restore();
        }

        $setting->fill([
            'setting_key' => $key,
            'type' => $secret ? 'password' : 'text',
            'group_name' => self::GROUP,
            'name' => mb_substr(str_replace([self::GROUP, '..'], ['', '.'], $key), -50),
            'input_setting' => [
                'title' => $key,
                'description' => 'AI runtime registry',
                'isPublic' => ! $secret,
                'isEnv' => false,
            ],
            'value' => $value,
        ]);
        $setting->save();
    }
}
