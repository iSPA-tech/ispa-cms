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
 * Immutable value object representing the event that triggered a workflow execution.
 *
 * Stores the event name, the timestamp when the trigger occurred, and arbitrary
 * metadata associated with the triggering event.
 *
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowTrigger
{
    protected string $event;
    protected CarbonImmutable $at;
    protected array $meta;

    /**
     * Initialize the trigger with event name, timestamp, and metadata.
     *
     * @param string      $event The event name that triggered the workflow.
     * @param string|null $at    ISO-8601 timestamp of the trigger (defaults to now).
     * @param array       $meta  Arbitrary metadata associated with the trigger.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(string $event = '', ?string $at = null, array $meta = [])
    {
        $this->event = $event;
        $this->at = $at ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        $this->meta = $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'at' => $this->at->toIso8601String(),
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the trigger event name.
     *
     * @return string The event name that triggered the workflow.
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Get the timestamp when the trigger occurred.
     *
     * @return CarbonImmutable The trigger timestamp.
     */
    public function getAt(): CarbonImmutable
    {
        return $this->at;
    }

    /**
     * Get the trigger metadata.
     *
     * @return array<string, mixed> The arbitrary metadata associated with the trigger.
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
