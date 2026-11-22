<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\DTO;

/**
 * Truthful outcome of a post-commit legacy Widget definition sync attempt.
 *
 * Frozen shape (Gate 0 Task 2 plan, "Frozen Contracts / Mutation and sync
 * status"). A database-committed Widget/Theme/Input mutation never reports
 * HTTP 503 as though it failed: the caller always has a committed resource,
 * and this result only ever says whether the legacy export/artifact
 * publication that follows it is `ready` (completed) or `pending` (failed,
 * retry-safe, retained under an incident/correlation identity for operators).
 *
 * @package Cms\Core\Services\Widget\DTO
 */
final readonly class WidgetDefinitionSyncResult
{
    public const STATUS_READY = 'ready';
    public const STATUS_PENDING = 'pending';

    /**
     * @param string $status ready|pending
     * @param string|null $incidentId Correlation id for operator retry when pending; null when ready.
     * @param string|null $errorCode Machine-readable failure classification when pending; null when ready.
     */
    public function __construct(
        public string $status,
        public ?string $incidentId,
        public ?string $errorCode,
    ) {
    }

    public static function ready(): self
    {
        return new self(self::STATUS_READY, null, null);
    }

    public static function pending(string $incidentId, ?string $errorCode = null): self
    {
        return new self(self::STATUS_PENDING, $incidentId, $errorCode);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Additive fields merged into an otherwise-unchanged legacy API envelope.
     *
     * @return array{sync_status: string, sync_incident_id: string|null, sync_error_code: string|null}
     */
    public function toArray(): array
    {
        return [
            'sync_status' => $this->status,
            'sync_incident_id' => $this->incidentId,
            'sync_error_code' => $this->errorCode,
        ];
    }
}
