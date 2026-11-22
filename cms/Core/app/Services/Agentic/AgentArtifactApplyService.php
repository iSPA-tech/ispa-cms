<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use InvalidArgumentException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;

/**
 * Dispatches artifact apply operations to the appropriate applier class.
 *
 * Resolves the applier implementation from the config-driven artifact type
 * map (core.agentic.artifact_appliers), instantiates it via the container,
 * and delegates the apply call. Throws if no applier is configured or
 * the resolved class lacks an apply() method.
 *
 * @see AgentApprovalService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentArtifactApplyService
{
    /**
     * Initialize the apply service with the container and config for applier resolution.
     *
     * @param Container        $container The application container for resolving applier classes.
     * @param ConfigRepository $config    Configuration repository for the artifact type-to-applier map.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected Container $container,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Apply the artifact changes using the configured applier.
     *
     * @param AgentArtifact $artifact The artifact containing proposed changes.
     * @param AgentApproval $approval The associated approval record.
     * @return array<string, mixed> The result from the applier's apply method.
     *
     * @throws InvalidArgumentException When no applier is configured for the artifact type or the applier lacks an apply() method.
     */
    public function apply(AgentArtifact $artifact, AgentApproval $approval): array
    {
        $className = $this->resolveApplierClass($artifact->artifact_type);
        $applier = $this->container->make($className);

        if (!$applier instanceof ArtifactApplierInterface) {
            throw new InvalidArgumentException(
                "Artifact applier [{$className}] must implement ArtifactApplierInterface."
            );
        }

        return $applier->apply($artifact, $approval);
    }

    protected function resolveApplierClass(string $artifactType): string
    {
        $map = (array) $this->config->get('core.agentic.artifact_appliers', []);
        $className = $map[$artifactType] ?? null;

        if (!is_string($className) || $className === '') {
            throw new InvalidArgumentException("No artifact applier configured for [{$artifactType}].");
        }

        return $className;
    }
}
