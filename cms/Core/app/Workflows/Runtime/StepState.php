<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Carbon\CarbonInterface;

/**
 * Mutable value object representing the runtime state of a single workflow step.
 *
 * Tracks the step code, execution status, result payload, metadata, and timing
 * information throughout the workflow execution lifecycle.
 *
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StepState
{
    /**
     * Initialize a step state with its code, status, result, metadata, and timing.
     *
     * @param string               $code       The step code identifier.
     * @param string               $status     The execution status (default: 'pending').
     * @param mixed                $result     The step result payload.
     * @param array                $meta       Metadata associated with the step execution.
     * @param CarbonInterface|null $startedAt  When the step execution started.
     * @param CarbonInterface|null $finishedAt When the step execution finished.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected string $code,
        protected string $status = 'pending',
        protected mixed $result = null,
        protected array $meta = [],
        protected ?CarbonInterface $startedAt = null,
        protected ?CarbonInterface $finishedAt = null,
    ) {
    }

    /**
     * Get step code.
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get current status.
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set status.
     * @param  string $status
     * @return void
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * Get result payload.
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Set result payload.
     * @param  mixed $result
     * @return void
     */
    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    /**
     * Get meta data.
     * @return array
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * Set meta data.
     * @param  array $meta
     * @return void
     */
    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    /**
     * Serialize the step state to an array representation.
     *
     * @return array<string, mixed> The step state as an associative array.
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status,
            'result' => $this->result,
            'meta' => $this->meta,
            'started_at' => $this->startedAt?->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
        ];
    }
}
