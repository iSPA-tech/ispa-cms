<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Services\Agentic\AnthropicAgenticAiProvider;
use Cms\Core\Services\Agentic\NullAgenticAiProvider;
use Cms\Core\Services\Agentic\OpenAiAgenticAiProvider;
use Cms\Core\Services\Agentic\OpenAiCompatibleHttpAgenticAiProvider;
use Throwable;

/**
 * Metered completion entry point: budget check, usage ledger and opt-in model fallback.
 *
 * A provider failure is an empty response; the next fallback model is then tried on the
 * same provider. An Anthropic response that was refused, truncated (`max_tokens`) or carries no
 * text counts as a failure too (the last such response is still returned to the caller, so it
 * can report the stop reason). Provider exceptions are recorded as failed and rethrown unchanged.
 */
final class AiGateway
{
    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly AiBudgetGuard $guard,
        private readonly AiCallLog $callLog,
    ) {
    }

    /**
     * Run a completion through the budget guard, ledger and fallback chain.
     *
     * @param AgenticAiProviderInterface $provider Provider to call.
     * @param string $providerName Provider name for the ledger.
     * @param string $taskKey Task key for the ledger.
     * @param array<string, mixed> $payload Completion payload.
     * @param list<string> $fallbackModels Models tried in order after the payload model fails.
     */
    public function complete(
        AgenticAiProviderInterface $provider,
        string $providerName,
        string $taskKey,
        array $payload,
        array $fallbackModels = [],
    ): AiGatewayResult {
        $primary = isset($payload['model']) && is_string($payload['model']) && $payload['model'] !== '' ? $payload['model'] : null;
        $models = [$primary];
        if ($primary !== null) {
            foreach ($fallbackModels as $model) {
                if (! in_array($model, $models, true)) {
                    $models[] = $model;
                }
            }
        }

        $response = [];
        $attempts = 0;
        $last = $primary;
        $tried = [];
        foreach ($models as $index => $model) {
            // A fallback that resolves to the Claude model already tried would only repeat the same call.
            $resolved = $this->actualModel($provider, $model, []);
            if ($index > 0 && in_array($resolved, $tried, true)) {
                continue;
            }
            $tried[] = $resolved;

            $last = $model;
            $decision = $this->guard->check();
            if (! $decision->allowed) {
                $this->ledger->recordRefused($providerName, (string) $model, $taskKey);
                $this->callLog->recordRefused($providerName, (string) $model, $taskKey, AiUsageLedger::VIA_RUNTIME, $attempts + 1, $index > 0, (string) $decision->reason);

                return new AiGatewayResult([], $model, $providerName, false, $decision->reason, $attempts);
            }

            if ($model !== null) {
                $payload['model'] = $model;
            }
            $started = hrtime(true);
            $attempts++;
            try {
                $response = $provider->createCompletion($payload);
            } catch (Throwable $e) {
                $latency = $this->elapsedMs($started);
                $this->ledger->record($providerName, $resolved, $taskKey, [], true, $index > 0, $latency);
                $this->callLog->record($providerName, $resolved, $taskKey, AiUsageLedger::VIA_RUNTIME, $attempts, $index > 0, true, $latency, [], $payload);
                throw $e;
            }

            $failed = $response === [] || AnthropicAgenticAiProvider::failureReason($response) !== null;
            $resolved = $this->actualModel($provider, $model, $response);
            $latency = $this->elapsedMs($started);
            $this->ledger->record($providerName, $resolved, $taskKey, $response, $failed, $index > 0, $latency);
            $this->callLog->record($providerName, $resolved, $taskKey, AiUsageLedger::VIA_RUNTIME, $attempts, $index > 0, $failed, $latency, $response, $payload);
            $this->guard->alertIfThresholdCrossed();

            if (! $failed) {
                return new AiGatewayResult($response, $provider instanceof AnthropicAgenticAiProvider ? $resolved : $model, $providerName, $index > 0, null, $attempts);
            }
        }

        return new AiGatewayResult($response, $last, $providerName, false, null, $attempts);
    }

    /**
     * Meter a call that does its own HTTP/SDK request outside AgenticAiProviderInterface.
     *
     * Budget check first (refused: closure not called). The closure returns the decoded
     * response array (`[]` = failure). A throwing closure is recorded as failed and rethrown.
     * No model fallback is applied.
     *
     * @param string $providerName Provider name for the ledger.
     * @param string $model Model sent by the call site.
     * @param string $taskKey Task key for the ledger.
     * @param \Closure():array<string, mixed> $send Performs the request and returns the decoded response.
     * @param array<string, mixed>|null $hashPayload Optional request payload whose prompt parts feed the call log's prompt hash.
     */
    public function meter(string $providerName, string $model, string $taskKey, \Closure $send, ?array $hashPayload = null): AiGatewayResult
    {
        $decision = $this->guard->check();
        if (! $decision->allowed) {
            $this->ledger->recordRefused($providerName, $model, $taskKey, AiUsageLedger::VIA_DIRECT);
            $this->callLog->recordRefused($providerName, $model, $taskKey, AiUsageLedger::VIA_DIRECT, 1, false, (string) $decision->reason);

            return new AiGatewayResult([], $model, $providerName, false, $decision->reason, 0);
        }

        $started = hrtime(true);
        try {
            $response = $send();
        } catch (Throwable $e) {
            $latency = $this->elapsedMs($started);
            $this->ledger->record($providerName, $model, $taskKey, [], true, false, $latency, AiUsageLedger::VIA_DIRECT);
            $this->callLog->record($providerName, $model, $taskKey, AiUsageLedger::VIA_DIRECT, 1, false, true, $latency, [], $hashPayload);
            throw $e;
        }

        $latency = $this->elapsedMs($started);
        $this->ledger->record($providerName, $model, $taskKey, $response, $response === [], false, $latency, AiUsageLedger::VIA_DIRECT);
        $this->callLog->record($providerName, $model, $taskKey, AiUsageLedger::VIA_DIRECT, 1, false, $response === [], $latency, $response, $hashPayload);
        $this->guard->alertIfThresholdCrossed();

        return new AiGatewayResult($response, $model, $providerName, false, null, 1);
    }

    /**
     * Ledger provider name of a provider instance.
     *
     * @param AgenticAiProviderInterface $provider Provider instance.
     */
    public static function providerNameOf(AgenticAiProviderInterface $provider): string
    {
        return match (true) {
            $provider instanceof OpenAiCompatibleHttpAgenticAiProvider => $provider->providerName(),
            $provider instanceof AnthropicAgenticAiProvider => 'anthropic',
            $provider instanceof OpenAiAgenticAiProvider => 'openai',
            $provider instanceof NullAgenticAiProvider => 'null',
            default => strtolower(class_basename($provider)),
        };
    }

    /**
     * Model that actually answers: the Claude model an Anthropic provider maps the requested one to
     * (the response's own `model` once known), the requested model for every other provider.
     *
     * @param array<string, mixed> $response Provider response, `[]` before the call.
     */
    private function actualModel(AgenticAiProviderInterface $provider, ?string $model, array $response): string
    {
        if (! $provider instanceof AnthropicAgenticAiProvider) {
            return (string) $model;
        }

        $answered = $response['model'] ?? null;

        return is_string($answered) && $answered !== '' ? $answered : AnthropicAgenticAiProvider::resolveModel((string) $model);
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
