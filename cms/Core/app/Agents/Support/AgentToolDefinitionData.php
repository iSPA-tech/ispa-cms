<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Support;

use Cms\Core\Agents\Attributes\AgentTool;

/**
 * Immutable DTO representing the full definition of an agent tool.
 *
 * Describes the tool's identity, input/output schemas, safety classification,
 * side-effect class, retry/idempotency/lock/compensation policies, and approval
 * requirements. Created during tool discovery and persisted to the database via
 * the tool registry.
 *
 * @package Cms\Core\Agents\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class AgentToolDefinitionData
{
    /**
     * Create a new AgentToolDefinitionData instance.
     *
     * @param string               $toolName            The canonical tool name.
     * @param string|null          $toolFamily          The tool family / grouping (e.g. module scope).
     * @param string|null          $contractSteward     The contract steward responsible for this tool.
     * @param string|null          $description         Human-readable description of the tool.
     * @param array<string, mixed> $inputSchema         JSON Schema for the tool's input parameters.
     * @param array<string, mixed> $outputSchema        JSON Schema for the tool's output.
     * @param string               $safetyLevel         Safety classification (e.g. "safe", "dangerous").
     * @param string               $sideEffectClass     Side-effect classification (e.g. "read_only", "write").
     * @param int|null             $timeoutSeconds      Maximum execution timeout in seconds.
     * @param array<string, mixed> $retryPolicy         Retry configuration for failed invocations.
     * @param array<string, mixed> $idempotencyStrategy Idempotency handling configuration.
     * @param array<string, mixed> $lockScope           Distributed lock scoping configuration.
     * @param array<string, mixed> $compensationPolicy  Compensation/rollback policy configuration.
     * @param bool                 $citationRequired    Whether output citations are required.
     * @param bool                 $approvalRequired    Whether human approval is required before execution.
     * @param string|null          $capabilityClass     The capability class this tool belongs to.
     * @param string               $status              The tool status (e.g. "active", "deprecated").
     * @param string|null          $ability             Gate ability the run's delegating user must hold (runtime only, never persisted).
     */
    public function __construct(
        public string $toolName,
        public ?string $toolFamily = null,
        public ?string $contractSteward = null,
        public ?string $description = null,
        public array $inputSchema = [],
        public array $outputSchema = [],
        public string $safetyLevel = 'safe',
        public string $sideEffectClass = 'read_only',
        public ?int $timeoutSeconds = null,
        public array $retryPolicy = [],
        public array $idempotencyStrategy = [],
        public array $lockScope = [],
        public array $compensationPolicy = [],
        public bool $citationRequired = false,
        public bool $approvalRequired = false,
        public ?string $capabilityClass = null,
        public string $status = 'active',
        public ?string $ability = null,
    ) {
    }

    /**
     * Create a definition from an AgentTool attribute discovered on a service method.
     *
     * @param  string    $toolName  The resolved tool name.
     * @param  AgentTool $attribute The AgentTool attribute instance from reflection.
     * @return self A new definition populated from the attribute metadata.
     */
    public static function fromAttribute(string $toolName, AgentTool $attribute): self
    {
        return new self(
            toolName: $toolName,
            toolFamily: $attribute->family,
            contractSteward: $attribute->contractSteward,
            description: $attribute->description,
            safetyLevel: $attribute->safetyLevel,
            sideEffectClass: $attribute->sideEffectClass,
            timeoutSeconds: $attribute->timeoutSeconds,
            citationRequired: $attribute->citationRequired,
            approvalRequired: $attribute->approvalRequired,
            capabilityClass: $attribute->capabilityClass,
            ability: $attribute->ability,
        );
    }

    /**
     * Convert the tool definition to a plain associative array.
     *
     * The `ability` field is deliberately omitted: this array is written to
     * agent_tool_definitions, which has no ability column.
     *
     * @return array<string, mixed> The definition as a serializable array.
     */
    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'tool_family' => $this->toolFamily,
            'contract_steward' => $this->contractSteward,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
            'output_schema' => $this->outputSchema,
            'safety_level' => $this->safetyLevel,
            'side_effect_class' => $this->sideEffectClass,
            'timeout_seconds' => $this->timeoutSeconds,
            'retry_policy' => $this->retryPolicy,
            'idempotency_strategy' => $this->idempotencyStrategy,
            'lock_scope' => $this->lockScope,
            'compensation_policy' => $this->compensationPolicy,
            'citation_required' => $this->citationRequired,
            'approval_required' => $this->approvalRequired,
            'capability_class' => $this->capabilityClass,
            'status' => $this->status,
        ];
    }
}
