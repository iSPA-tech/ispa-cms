<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Support;

/**
 * Immutable DTO representing the authorization result for a tool invocation.
 *
 * Produced by the ToolPolicyResolver to indicate whether a tool call is allowed,
 * optionally denied with a reason, or allowed but requiring human approval.
 * Consumed by the AgentRunner to gate tool execution.
 *
 * @package Cms\Core\Agents\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class ToolAuthorization
{
    /**
     * Create a new ToolAuthorization instance.
     *
     * @param bool        $allowed          Whether the tool invocation is authorized.
     * @param string|null $reason           The denial reason (null when allowed).
     * @param bool        $requiresApproval Whether human approval is needed before execution.
     */
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public bool $requiresApproval = false,
    ) {
    }

    /**
     * Create an authorization that allows the tool invocation.
     *
     * @param  bool $requiresApproval Whether human approval is still required.
     * @return self An allowed authorization.
     */
    public static function allow(bool $requiresApproval = false): self
    {
        return new self(true, null, $requiresApproval);
    }

    /**
     * Create an authorization that denies the tool invocation.
     *
     * @param  string $reason The reason for denial.
     * @return self A denied authorization.
     */
    public static function deny(string $reason): self
    {
        return new self(false, $reason, false);
    }
}
