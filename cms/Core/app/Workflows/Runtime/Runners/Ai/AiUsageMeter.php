<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use Cms\Core\Services\Ai\Usage\AiUsageLedger;
use Cms\Core\Services\Ai\Usage\AiUsageTokens;

/**
 * Builds the `meta.usage` block (`{task_key, provider, model, tokens_in, tokens_out, cost_usd, calls}`) that AI
 * workflow steps report through StepRunResult::withUsage(), from real provider responses.
 *
 * Cost rule: the provider-reported `usage.cost_usd` when present, else the AiUsageLedger price estimate for the model
 * that answered. When neither is known (unpriced model) `cost_usd` is null, so the executor counts the step as an
 * unknown spend and a cost cap stops the next ai_spend step: unknown spend is never treated as free. A call the budget
 * refused (never sent) costs 0.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
final class AiUsageMeter
{
    private ?array $usage = null;

    public function __construct(private readonly string $taskKey)
    {
    }

    /** Usage of a step that made no AI call: known, zero. */
    public static function none(string $taskKey): array
    {
        return ['task_key' => $taskKey, 'provider' => null, 'model' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'calls' => 0];
    }

    /**
     * Add one AiTaskRuntime::run() result.
     *
     * @param array<string, mixed> $run
     */
    public function addRun(array $run): void
    {
        $response = is_array($run['response'] ?? null) ? $run['response'] : [];
        $this->addResponse(
            (string) ($run['provider'] ?? 'legacy'),
            isset($run['model']) ? (string) $run['model'] : null,
            $response,
            ($run['refused_reason'] ?? null) !== null,
        );
    }

    /**
     * Add one raw provider response.
     *
     * @param array<string, mixed> $response
     */
    public function addResponse(string $provider, ?string $model, array $response, bool $refused = false): void
    {
        $tokens = AiUsageTokens::fromResponse($response);
        $cost = $refused ? 0.0 : self::cost((string) $model, $response, $tokens);

        $current = $this->usage ?? self::none($this->taskKey);
        $this->usage = [
            'task_key' => $this->taskKey,
            'provider' => $provider,
            'model' => $model ?? $current['model'],
            'tokens_in' => $current['tokens_in'] + $tokens->input + $tokens->cached,
            'tokens_out' => $current['tokens_out'] + $tokens->output,
            // Once one call has an unknown cost the step's total is unknown.
            'cost_usd' => $current['cost_usd'] === null || $cost === null ? null : round($current['cost_usd'] + $cost, 6),
            'calls' => $current['calls'] + ($refused ? 0 : 1),
        ];
    }

    /** True when at least one call was added. */
    public function hasCalls(): bool
    {
        return $this->usage !== null;
    }

    /** The summed usage, or the zero block when no call was made. */
    public function usage(): array
    {
        return $this->usage ?? self::none($this->taskKey);
    }

    /** Return the usage so far and start again from nothing. */
    public function take(): ?array
    {
        $usage = $this->usage;
        $this->usage = null;

        return $usage;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function cost(string $model, array $response, AiUsageTokens $tokens): ?float
    {
        $reported = $response['usage']['cost_usd'] ?? null;
        if (is_numeric($reported) && (float) $reported >= 0) {
            return round((float) $reported, 6);
        }
        if ($response === []) {
            return 0.0; // failed call with no usage block: the ledger records it as free as well
        }

        $ledger = app(AiUsageLedger::class);
        if ($model === '' || $ledger->price($model) === null) {
            return null;
        }

        return $ledger->estimateCostUsd($model, $tokens);
    }
}
