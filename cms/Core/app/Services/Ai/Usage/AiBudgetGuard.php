<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Monthly budget guard over the LLM usage ledger.
 *
 * Deliberate difference from the GeoPlaces GoogleBudgetGuard: an LLM call's cost is only
 * known after the call, so this is check-then-record, not reserve-then-call. The cap can be
 * overshot by at most the calls already in flight. No row locking is used. The guard fails
 * open: metering problems must never take AI features down.
 */
final class AiBudgetGuard
{
    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly AiRuntimeConfigResolver $resolver,
    ) {
    }

    /**
     * Decide whether an AI call may proceed.
     *
     * @return AiBudgetDecision Refused on kill switch or exhausted cap; allowed otherwise (and on any error).
     */
    public function check(): AiBudgetDecision
    {
        $month = $this->ledger->currentMonth();
        try {
            $budget = $this->resolver->budget();
            $cap = $budget['monthly_usd'];
            if ($budget['kill_switch']) {
                return new AiBudgetDecision(false, AiBudgetDecision::REASON_KILL_SWITCH, $month, 0.0, $cap);
            }
            if ($cap > 0) {
                $spent = $this->ledger->monthSpentUsd($month);
                if ($spent >= $cap) {
                    return new AiBudgetDecision(false, AiBudgetDecision::REASON_BUDGET_EXCEEDED, $month, $spent, $cap);
                }

                return new AiBudgetDecision(true, null, $month, $spent, $cap);
            }
        } catch (Throwable $e) {
            Log::warning('AI budget check failed, allowing the call', ['error' => $e::class]);
        }

        return new AiBudgetDecision(true, null, $month, 0.0, 0.0);
    }

    /**
     * Raise the once-per-month warning and exceeded alerts after a recorded call.
     */
    public function alertIfThresholdCrossed(): void
    {
        try {
            $budget = $this->resolver->budget();
            $cap = $budget['monthly_usd'];
            if ($cap <= 0) {
                return;
            }

            $month = $this->ledger->currentMonth();
            $spent = $this->ledger->monthSpentUsd($month);
            $percent = round($spent / $cap * 100, 1);
            $message = sprintf('AI monthly budget %s: spent %.2f USD of %.2f USD cap (%.1f%%)', $month, $spent, $cap, $percent);

            if ($spent >= $cap && Cache::add("core:ai_budget_alert:{$month}:exceeded", true, now()->addDays(40))) {
                CMSLogger::error(['message' => $message . ' - cap reached, AI calls are refused']);
            }
            if ($spent >= $cap * $budget['warning_percent'] / 100
                && Cache::add("core:ai_budget_alert:{$month}:warning", true, now()->addDays(40))) {
                CMSLogger::warning(['message' => $message . ' - warning threshold crossed']);
            }
        } catch (Throwable $e) {
            Log::warning('AI budget alert failed', ['error' => $e::class]);
        }
    }
}
