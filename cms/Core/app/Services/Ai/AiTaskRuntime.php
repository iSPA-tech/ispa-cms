<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai;

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Services\Agentic\AgenticAiProviderFactory;
use Cms\Core\Services\Ai\Usage\AiGateway;

/**
 * Registry-aware completion runner for AI flows that call providers
 * directly (chat completions outside AiStructuredExtractionService).
 *
 * Behavior contract:
 * - No admin task row  -> byte-for-byte legacy behavior: the caller's own
 *   provider instance and payload model are used untouched.
 * - Admin task row     -> its provider/model/temperature win over whatever
 *   the call site hardcoded, so every AI flow is manageable from the
 *   AI Runtime admin page.
 *
 * The system default provider deliberately does NOT apply here — direct
 * flows only follow their own task setting, so flipping the global default
 * cannot change dozens of legacy pipelines at once.
 *
 * When an AiGateway is present every call is metered in the usage ledger, checked
 * against the monthly budget (a refused call returns an empty response with
 * `refused_reason` set and never reaches the provider) and retried on the task's
 * opt-in fallback models. `model` is the model actually used; `fallback_used` tells
 * whether a fallback model answered.
 */
final class AiTaskRuntime
{
    public function __construct(
        private readonly AiRuntimeConfigResolver $resolver,
        private readonly ?AgenticAiProviderFactory $factory = null,
        private readonly ?AgenticAiProviderInterface $defaultProvider = null,
        private readonly ?AiGateway $gateway = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{response: array<string, mixed>, content: string, model: ?string, provider: string, fallback_used: bool, refused_reason: ?string}
     */
    public function run(
        string $taskKey,
        array $payload,
        ?AgenticAiProviderInterface $legacyProvider = null,
    ): array {
        $task = $this->resolver->taskOverrides($taskKey);

        if ($task['model'] !== null) {
            $payload['model'] = $task['model'];
        }
        if ($task['temperature'] !== null) {
            $payload['temperature'] = $task['temperature'];
        }
        if (($task['reasoning_effort'] ?? null) !== null) {
            $payload['reasoning_effort'] = $task['reasoning_effort'];
        }

        $providerKey = 'legacy';
        $provider = $legacyProvider ?? $this->defaultProvider;
        if ($task['provider'] !== null && $this->factory !== null) {
            $this->resolver->applyProviderRuntimeOverrides($task['provider']);
            $provider = $this->factory->make($task['provider']);
            $providerKey = $task['provider'];

            if ($task['model'] === null) {
                $providerModel = config("core.ai_structured_extraction.providers.{$task['provider']}.model");
                if (is_string($providerModel) && trim($providerModel) !== '') {
                    $payload['model'] = $providerModel;
                }
            }
        }

        if ($provider === null) {
            throw new \RuntimeException('No AI provider available for task ' . $taskKey);
        }

        $model = isset($payload['model']) ? (string) $payload['model'] : null;
        $fallbackUsed = false;
        $refusedReason = null;
        if ($this->gateway !== null) {
            $result = $this->gateway->complete(
                $provider,
                $task['provider'] ?? AiGateway::providerNameOf($provider),
                $taskKey,
                $payload,
                $task['fallback_models'],
            );
            $response = $result->response;
            $model = $result->model;
            $fallbackUsed = $result->fallbackUsed;
            $refusedReason = $result->refusedReason;
        } else {
            $response = $provider->createCompletion($payload);
        }

        return [
            'response' => $response,
            'content' => $provider->getContent($response),
            'model' => $model,
            'provider' => $providerKey,
            'fallback_used' => $fallbackUsed,
            'refused_reason' => $refusedReason,
        ];
    }
}
