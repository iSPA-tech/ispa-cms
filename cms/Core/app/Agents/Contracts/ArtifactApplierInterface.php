<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;

/**
 * Contract for module-owned artifact appliers.
 *
 * Appliers write approved agent proposals to domain tables. Each module
 * owns its applier implementation and registers it in the
 * core.agentic.artifact_appliers config map.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0
 */
interface ArtifactApplierInterface
{
    /**
     * Apply an approved artifact's proposed changes to the domain.
     *
     * @param  AgentArtifact $artifact The artifact containing proposed changes.
     * @param  AgentApproval $approval The associated approval record.
     * @return array<string, mixed> The apply result summary.
     *
     * @throws ApprovalStaleException When the target entity changed since the proposal.
     * @throws \InvalidArgumentException When the artifact data is invalid.
     */
    public function apply(AgentArtifact $artifact, AgentApproval $approval): array;
}
