<?php

declare(strict_types=1);

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Illuminate\Database\Seeder;

/**
 * Base for per-module AI runtime task seeders.
 *
 * Each module seeds ONLY its own task keys (module ownership rule) so a
 * deployment without a module never receives that module's registry rows.
 * Idempotent and never overwrites: keys are written only when absent, so
 * admin edits survive every re-seed.
 */
abstract class AiRuntimeTaskSeederBase extends Seeder
{
    /**
     * Task keys owned by the module with their current effective values,
     * snapshotted from the same config/env chain the call site uses:
     * [task_key => ['provider' => mixed, 'model' => mixed, 'register' => bool]]
     *
     * `register => true` with a blank provider and model lists the task in the AI runtime admin with no
     * provider or model (one empty `.provider` row), so an operator must choose both explicitly.
     *
     * @return array<string, array{provider: mixed, model: mixed, register?: bool}>
     */
    abstract protected function taskCatalog(): array;

    public function run(): void
    {
        foreach ($this->taskCatalog() as $task => $values) {
            $provider = $this->normalizeProvider($values['provider'] ?? null);
            $model = trim((string) ($values['model'] ?? ''));

            if ($provider === '' && $model === '' && ($values['register'] ?? false) === true) {
                $this->seedIfMissing(AiRuntimeConfigResolver::TASK_KEY_PREFIX . $task . '.provider', '');

                continue;
            }

            if ($provider !== '') {
                $this->seedIfMissing(
                    AiRuntimeConfigResolver::TASK_KEY_PREFIX . $task . '.provider',
                    $provider,
                );
            }
            if ($model !== '') {
                $this->seedIfMissing(
                    AiRuntimeConfigResolver::TASK_KEY_PREFIX . $task . '.model',
                    $model,
                );
            }
        }
    }

    protected function agenticDriver(): mixed
    {
        return config('core.agentic.ai_provider.driver');
    }

    protected function extractionProvider(): mixed
    {
        return config('core.ai_structured_extraction.provider');
    }

    protected function normalizeProvider(mixed $provider): string
    {
        $key = (new AiRuntimeConfigResolver())->providerConfigKey(trim((string) $provider));

        return array_key_exists($key, AiRuntimeSettingsService::DRIVERS) ? $key : '';
    }

    protected function seedIfMissing(string $key, string $value): void
    {
        if (Setting::withTrashed()->where('setting_key', $key)->exists()) {
            return;
        }

        Setting::query()->create([
            'setting_key' => $key,
            'type' => 'text',
            'group_name' => 'core.ai_runtime',
            'name' => mb_substr($key, -50),
            'input_setting' => [
                'title' => $key,
                'description' => 'AI runtime registry (seeded)',
                'isPublic' => true,
                'isEnv' => false,
            ],
            'value' => $value,
        ]);
    }
}
