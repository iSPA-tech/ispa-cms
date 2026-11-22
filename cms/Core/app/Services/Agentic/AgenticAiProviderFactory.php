<?php

namespace Cms\Core\Services\Agentic;

use App\Clients\OpenAIClient;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Container\Container;

final class AgenticAiProviderFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly AgenticAiProviderInterface $defaultProvider,
    ) {}

    public function make(?string $driver = null): AgenticAiProviderInterface
    {
        $driver = strtolower(trim((string) ($driver ?: config('core.ai_structured_extraction.provider', 'agentic_ai'))));

        return match ($driver) {
            'agentic_ai', 'default' => $this->defaultProvider,
            'openai' => $this->openAiProvider(),
            'gemini', 'google_gemini', 'google' => new GeminiAgenticAiProvider(),
            'deepseek' => new DeepSeekAgenticAiProvider(),
            'grok', 'xai', 'x.ai' => new GrokAgenticAiProvider(),
            'anthropic', 'claude' => new AnthropicAgenticAiProvider(),
            default => new NullAgenticAiProvider(),
        };
    }

    private function openAiProvider(): AgenticAiProviderInterface
    {
        $apiKey = trim((string) config('core.ai_structured_extraction.providers.openai.api_key', ''));
        if ($apiKey === '') {
            return new OpenAiAgenticAiProvider($this->container->make(OpenAIClient::class));
        }

        return new OpenAiCompatibleHttpAgenticAiProvider(new Client([
            'base_uri' => rtrim((string) config('core.ai_structured_extraction.providers.openai.base_uri', 'https://api.openai.com/v1/'), '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
        ]), 'openai');
    }
}
