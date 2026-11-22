<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Presenters;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;

/**
 * Transforms a WorkflowInstance into a structured array for admin API responses.
 *
 * Computes derived fields such as duration, human-readable status labels, failed step
 * details, and debug flags extracted from error messages.
 *
 * @see WorkflowInstance
 * @package Cms\Core\Workflows\Presenters
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowInstancePresenter
{
    /**
     * Convert a workflow instance into an admin-facing response array.
     *
     * @param  WorkflowInstance $instance The workflow instance to present.
     * @return array<string, mixed> The structured admin response data.
     */
    public function toAdminArray(WorkflowInstance $instance): array
    {
        $durationMs = null;
        if ($instance->started_at && $instance->finished_at) {
            $durationMs = $instance->finished_at->diffInMilliseconds($instance->started_at);
        }

        $failedStep = WorkflowInstanceStep::query()
            ->where('instance_id', $instance->id)
            ->whereIn('status', ['failed', 'apply_failed', 'approval_stale'])
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $instance->id,
            'status' => $instance->status,
            'status_label' => $this->statusLabel($instance->status),
            'failed_step_id' => $failedStep?->step_id,
            'failed_step_name' => $failedStep?->step?->name,
            'short_error_message' => $failedStep?->error_message,
            'duration_ms' => $durationMs,
            'duration_human' => $durationMs ? round($durationMs / 1000, 2) . 's' : null,
            'debug_flags' => $this->debugFlags($failedStep?->error_message),
        ];
    }

    protected function statusLabel(?string $status): string
    {
        return match ($status) {
            'completed' => 'Success',
            'failed' => 'Failed',
            'running' => 'Running',
            'cancelling' => 'Cancelling',
            'awaiting_approval' => 'Awaiting approval',
            'awaiting_apply' => 'Awaiting apply',
            'applied' => 'Applied',
            'approval_rejected' => 'Approval rejected',
            'apply_failed' => 'Apply failed',
            'approval_stale' => 'Approval stale',
            'cancelled' => 'Cancelled',
            default => ucfirst($status ?? 'Unknown'),
        };
    }

    /**
     * Extract debug flags from error messages.
     *
     * @return array<int,string>
     */
    protected function debugFlags(?string $error): array
    {
        if (!$error) {
            return [];
        }

        $flags = [];
        $lower = strtolower($error);

        if (str_contains($lower, 'retry')) {
            $flags[] = 'retry_exhausted';
        }

        if (str_contains($lower, 'timeout')) {
            $flags[] = 'timeout';
        }

        if (str_contains($lower, 'unknown step')) {
            $flags[] = 'unknown_step';
        }

        return $flags;
    }
}
