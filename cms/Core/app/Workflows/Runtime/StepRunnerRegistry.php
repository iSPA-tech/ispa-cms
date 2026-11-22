<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;

/**
 * Registry that maps workflow step types to their corresponding StepRunner implementations.
 *
 * Supports direct type registration, step-type aliases for backward compatibility,
 * and falls back to a configurable default runner for unrecognized step types.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StepRunnerRegistry
{
    /** @var array<string, StepRunner> */
    protected array $runners = [];

    /** @var array<string,string> */
    protected array $aliases = [];

    /**
     * Initialize the registry with a fallback runner and optional step-type aliases.
     *
     * @param StepRunner           $fallbackRunner The default runner for unregistered step types.
     * @param array<string,string> $aliases        Map of legacy step types to canonical types.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(protected StepRunner $fallbackRunner, array $aliases = [])
    {
        $this->aliases = $aliases;
    }

    /**
     * Register a runner for a specific step type.
     *
     * @param  string     $stepType The step type identifier.
     * @param  StepRunner $runner   The runner implementation to handle this step type.
     * @return void
     */
    public function register(string $stepType, StepRunner $runner): void
    {
        $this->runners[$stepType] = $runner;
    }

    /**
     * Check if a runner is registered for the given step type.
     *
     * @param  string $stepType The step type identifier.
     * @return bool True if a runner is registered for this step type.
     */
    public function has(string $stepType): bool
    {
        return isset($this->runners[$stepType]);
    }

    /**
     * Get the runner for a given step type, falling back to the default runner.
     *
     * @param  string $stepType The step type identifier.
     * @return StepRunner The runner for the step type, or the fallback runner.
     */
    public function get(string $stepType): StepRunner
    {
        if (isset($this->runners[$stepType])) {
            return $this->runners[$stepType];
        }

        $alias = $this->aliases[$stepType] ?? null;
        if ($alias && isset($this->runners[$alias])) {
            return $this->runners[$alias];
        }

        return $this->fallbackRunner;
    }

    /**
     * @return array<string, StepRunner>
     */
    public function all(): array
    {
        return $this->runners;
    }
}
