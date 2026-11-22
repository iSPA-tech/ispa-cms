<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Cms\Core\Models\Workflows\WorkflowStep;

/**
 * Immutable value object representing a workflow step's definition and configuration.
 *
 * Encapsulates the step type, code, name, and configuration needed by step runners,
 * and provides a factory method to hydrate from an Eloquent WorkflowStep model.
 *
 * @see WorkflowStep
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StepDefinition
{
    /**
     * Initialize a workflow step definition with its type, code, name, and configuration.
     *
     * @param int|null    $id         The step database ID.
     * @param int|null    $projectId  The owning workflow project ID.
     * @param string      $stepType   The step type identifier.
     * @param string|null $stepCode   The step code (unique within project).
     * @param string|null $stepKey    The step key alias (compatibility).
     * @param string|null $stepName   The human-readable step name.
     * @param array       $stepConfig The step configuration array.
     * @param int|null    $order      The positional order of the step.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $projectId,
        public readonly string $stepType,
        public readonly ?string $stepCode,
        public readonly ?string $stepKey,
        public readonly ?string $stepName,
        public readonly array $stepConfig = [],
        public readonly ?int $order = null,
    ) {
    }

    /**
     * Build a StepDefinition from an Eloquent WorkflowStep model.
     *
     * @param  WorkflowStep $step The Eloquent workflow step model.
     * @return self A new StepDefinition instance.
     */
    public static function fromModel(WorkflowStep $step): self
    {
        $config = $step->step_config;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: $step->id,
            projectId: $step->project_id,
            stepType: (string) $step->step_type,
            stepCode: $step->step_code,
            stepKey: $step->step_code, // alias for compatibility
            stepName: $step->name,
            stepConfig: $config ?? [],
            order: $step->position_x ?? null,
        );
    }
}
