<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Carbon\CarbonImmutable;

/**
 * Represents the outcome of executing a single workflow step.
 *
 * Carries the status (success, failed, skipped, paused, cancelled, rescheduled), result data, execution logs,
 * error details, timing information, and an optional next-edge hint for graph traversal.
 *
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StepRunResult
{
    /**
     * Initialize a step run result with status, data, logs, error details, and timing.
     *
     * @param string               $status       The outcome status (success, failed, skipped, paused, cancelled).
     * @param mixed                $resultData   The result payload.
     * @param array                $logs         Execution log entries.
     * @param array|null           $error        Error details array with 'message' key.
     * @param string|null          $nextEdgeHint Hint for the next graph edge to follow.
     * @param CarbonImmutable|null $startedAt    When the step execution started.
     * @param CarbonImmutable|null $finishedAt   When the step execution finished.
     * @param bool                 $paused       Whether the step is paused (e.g., awaiting approval).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public string $status,
        public mixed $resultData = null,
        public array $logs = [],
        public ?array $error = null,
        public ?string $nextEdgeHint = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $finishedAt = null,
        public bool $paused = false,
        public ?int $delaySeconds = null,
        public array $meta = [],
    ) {
    }

    /**
     * Name the output handle the run continues from (`true`, `false`, `case:<key>`, `default`,
     * `approved`, `rejected`, `expired`, `no_proposal`, ...). The executor matches it against the
     * outgoing edges' `source_handle`. Same slot as the legacy next-edge hint.
     *
     * @param  string|null $handle Output handle name, or null for the plain output.
     * @return self
     */
    public function withOutput(?string $handle): self
    {
        $this->nextEdgeHint = is_string($handle) && trim($handle) !== '' ? trim($handle) : null;

        return $this;
    }

    /**
     * The output handle this result routes by (null = plain output).
     *
     * @return string|null
     */
    public function output(): ?string
    {
        return $this->nextEdgeHint;
    }

    /**
     * Attach what the step spent: `{task_key, provider, model, tokens_in, tokens_out, cost_usd}`.
     * The executor sums it onto the workflow instance.
     *
     * @param  array<string, mixed> $usage
     * @return self
     */
    public function withUsage(array $usage): self
    {
        $this->meta['usage'] = $usage;

        return $this;
    }

    /**
     * Reported usage, or null when the runner reported none.
     *
     * @return array<string, mixed>|null
     */
    public function usage(): ?array
    {
        $usage = $this->meta['usage'] ?? null;

        return is_array($usage) && $usage !== [] ? $usage : null;
    }

    /**
     * Create a rescheduled step result: the step succeeded and the run must continue
     * from the next step after the given delay, in a new queued execution.
     *
     * @param  int   $delaySeconds Seconds to wait before the run continues.
     * @param  mixed $resultData   The result payload.
     * @param  array $logs         Execution log entries.
     * @return self
     */
    public static function rescheduled(int $delaySeconds, mixed $resultData = null, array $logs = []): self
    {
        $now = CarbonImmutable::now();

        return new self(
            status: 'rescheduled',
            resultData: $resultData,
            logs: $logs,
            error: null,
            nextEdgeHint: null,
            startedAt: $now,
            finishedAt: $now,
            paused: false,
            delaySeconds: max(0, $delaySeconds),
        );
    }

    /**
     * Create a successful step result.
     *
     * @param  mixed       $resultData   The result payload.
     * @param  array       $logs         Execution log entries.
     * @param  string|null $nextEdgeHint Optional hint for the next graph edge to follow.
     * @return self
     */
    public static function success(mixed $resultData = null, array $logs = [], ?string $nextEdgeHint = null): self
    {
        $now = CarbonImmutable::now();

        return new self(
            status: 'success',
            resultData: $resultData,
            logs: $logs,
            error: null,
            nextEdgeHint: $nextEdgeHint,
            startedAt: $now,
            finishedAt: $now,
            paused: false,
        );
    }

    /**
     * Create a failed step result.
     *
     * @param  string $message   The error message describing the failure.
     * @param  array  $logs      Execution log entries.
     * @param  array  $errorMeta Additional error metadata.
     * @return self
     */
    public static function failed(string $message, array $logs = [], array $errorMeta = []): self
    {
        $now = CarbonImmutable::now();

        return new self(
            status: 'failed',
            resultData: null,
            logs: $logs,
            error: array_merge(['message' => $message], $errorMeta),
            nextEdgeHint: null,
            startedAt: $now,
            finishedAt: $now,
            paused: false,
        );
    }

    /**
     * Create a skipped step result.
     *
     * @param  string $reason The reason the step was skipped.
     * @param  array  $logs   Execution log entries.
     * @return self
     */
    public static function skipped(string $reason, array $logs = []): self
    {
        $now = CarbonImmutable::now();

        return new self(
            status: 'skipped',
            resultData: null,
            logs: $logs,
            error: ['message' => $reason],
            nextEdgeHint: null,
            startedAt: $now,
            finishedAt: $now,
            paused: false,
        );
    }

    /**
     * Create a paused step result (e.g., awaiting approval).
     *
     * @param  string     $status     The paused status label.
     * @param  mixed      $resultData The result payload accumulated so far.
     * @param  array      $logs       Execution log entries.
     * @param  array|null $error      Optional error details.
     * @return self
     */
    public static function paused(string $status, mixed $resultData = null, array $logs = [], ?array $error = null): self
    {
        $now = CarbonImmutable::now();

        return new self(
            status: $status,
            resultData: $resultData,
            logs: $logs,
            error: $error,
            nextEdgeHint: null,
            startedAt: $now,
            finishedAt: $now,
            paused: true,
        );
    }

    /**
     * Create a cancelled step result.
     *
     * @param  string $message    The cancellation message.
     * @param  mixed  $resultData The result payload accumulated so far.
     * @param  array  $logs       Execution log entries.
     * @return self
     */
    public static function cancelled(string $message = 'Cancelled', mixed $resultData = null, array $logs = []): self
    {
        $now = CarbonImmutable::now();

        return new self(
            status: 'cancelled',
            resultData: $resultData,
            logs: $logs,
            error: ['message' => $message],
            nextEdgeHint: null,
            startedAt: $now,
            finishedAt: $now,
            paused: false,
        );
    }
}
