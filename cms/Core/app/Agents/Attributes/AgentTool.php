<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Attributes;

use Attribute;

/**
 * PHP attribute for marking methods as agent-callable tools.
 *
 * This attribute annotates controller or service methods that should be
 * discoverable by the CMS agent runtime. It carries metadata such as
 * tool name, module scope, safety level, and capability class so the
 * agent orchestrator can enforce access control and side-effect policies.
 *
 * @package Cms\Core\Agents\Attributes
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AgentTool
{
    /**
     * Create a new AgentTool attribute instance.
     *
     * @param  string|null $name             Human-readable tool name override
     * @param  string|null $family           Logical grouping or family the tool belongs to
     * @param  string|null $moduleScope      CMS module this tool is scoped to (e.g. 'core', 'content')
     * @param  string      $contractSteward  Module responsible for the tool contract
     * @param  string      $sideEffectClass  Side-effect classification ('read_only', 'write', etc.)
     * @param  string      $safetyLevel      Safety rating ('safe', 'moderate', 'dangerous')
     * @param  bool        $citationRequired Whether the tool output must include citations
     * @param  bool        $approvalRequired Whether human approval is needed before execution
     * @param  string|null $capabilityClass  Capability tier for permission gating
     * @param  int|null    $timeoutSeconds   Maximum execution time in seconds
     * @param  string|null $description      Free-text description shown to the agent
     * @param  string|null $ability          Gate ability the run's delegating user must hold for the tool to run
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct(
        public ?string $name = null,
        public ?string $family = null,
        public ?string $moduleScope = null,
        public string $contractSteward = 'core',
        public string $sideEffectClass = 'read_only',
        public string $safetyLevel = 'safe',
        public bool $citationRequired = false,
        public bool $approvalRequired = false,
        public ?string $capabilityClass = 'class_0_read_only',
        public ?int $timeoutSeconds = null,
        public ?string $description = null,
        public ?string $ability = null,
    ) {
    }
}
