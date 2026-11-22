<?php

declare(strict_types=1);

namespace Cms\Content\Database\Seeders;

use Cms\Core\Database\Seeders\AiRuntimeTaskSeederBase;

/** AI runtime task keys owned by Content. */
final class ContentAiRuntimeTaskSeeder extends AiRuntimeTaskSeederBase
{
    protected function taskCatalog(): array
    {
        return [
            'content_post_translate_title_desc' => [
                'provider' => $this->agenticDriver(),
                // chatgpt-4o-latest was shut down on 2026-02-17.
                'model' => 'gpt-5.6-luna',
            ],
            // Answer pilot: listed blank on purpose; an operator picks an allowed provider and model.
            'content_agent_answer' => [
                'register' => true,
                'provider' => null,
                'model' => null,
            ],
        ];
    }
}
