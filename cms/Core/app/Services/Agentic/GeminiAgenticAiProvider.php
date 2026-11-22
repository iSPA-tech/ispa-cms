<?php

namespace Cms\Core\Services\Agentic;

use GuzzleHttp\Client;

final class GeminiAgenticAiProvider extends OpenAiCompatibleHttpAgenticAiProvider
{
    public function __construct(?Client $client = null)
    {
        parent::__construct($client ?? new Client([
            'base_uri' => rtrim((string) config('core.ai_structured_extraction.providers.gemini.base_uri'), '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . (string) config('core.ai_structured_extraction.providers.gemini.api_key'),
                'Content-Type' => 'application/json',
            ],
        ]), 'gemini');
    }
}
