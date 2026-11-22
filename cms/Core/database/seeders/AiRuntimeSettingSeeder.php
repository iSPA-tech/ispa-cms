<?php

declare(strict_types=1);

namespace Cms\Core\Database\Seeders;

use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;

/**
 * Core-owned AI runtime registry rows only: per-provider env model
 * snapshots plus Core's own AI task flows (news compliance).
 *
 * Module task keys live in each module's own AiRuntimeTaskSeeder —
 * see DatabaseSeeder for the guarded per-module calls.
 */
final class AiRuntimeSettingSeeder extends AiRuntimeTaskSeederBase
{
    public function run(): void
    {
        foreach (array_keys(AiRuntimeSettingsService::DRIVERS) as $provider) {
            $envModel = trim((string) config("core.ai_structured_extraction.providers.{$provider}.model", ''));
            if ($envModel !== '') {
                $this->seedIfMissing(
                    AiRuntimeConfigResolver::PROVIDER_KEY_PREFIX . $provider . '.model',
                    $envModel,
                );
            }
        }

        parent::run();
    }

    protected function taskCatalog(): array
    {
        $provider = static fn (string $stage): mixed => config("core.news_compliance.ai.stages.{$stage}.provider")
            ?: config('core.news_compliance.ai.provider');
        $model = static fn (string $stage): mixed => config("core.news_compliance.ai.stages.{$stage}.model")
            ?: config('core.news_compliance.ai.model');

        return [
            'copyright_safe_news_fact_extraction' => ['provider' => $provider('fact_sheet'), 'model' => $model('fact_sheet')],
            'copyright_safe_news_drafting' => ['provider' => $provider('draft'), 'model' => $model('draft')],
            'copyright_safe_news_legal_editorial_review' => ['provider' => $provider('review'), 'model' => $model('review')],
        ];
    }
}
