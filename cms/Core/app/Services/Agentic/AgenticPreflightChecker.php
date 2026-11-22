<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use InvalidArgumentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Agents\Tools\AgentToolRegistry;

/**
 * Validates infrastructure readiness before agentic sync and grant operations.
 *
 * Checks for required database tables and validates that configured
 * bootstrap services are discoverable. Provides both inspection (returns
 * results) and assertion (throws on failure) variants for sync and
 * grant readiness checks.
 *
 * @see AgentToolProvisioner
 * @see AgentToolRegistry
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticPreflightChecker
{
    /**
     * Initialize the preflight checker with config and tool registry dependencies.
     *
     * @param ConfigRepository  $config   Configuration repository for bootstrap service lists.
     * @param AgentToolRegistry $registry Tool registry for validating service discoverability.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected ConfigRepository $config,
        protected AgentToolRegistry $registry,
    ) {
    }

    /**
     * Inspect whether the system is ready for tool sync operations.
     *
     * @param array<int, string|array<string, mixed>> $services    Service classes to validate.
     * @param string|null                              $environment Target environment.
     * @return array{missing_tables: array<int, string>, invalid_services: array<int, string>, resolved_services: array<int, string>}
     */
    public function inspectSyncReadiness(array $services = [], ?string $environment = null): array
    {
        $resolvedServices = $services !== []
            ? $this->normalizeServices($services)
            : $this->normalizeServices((array) $this->config->get('core.agentic.bootstrap_services', []));

        $invalidServices = [];
        $resolvedClassNames = [];

        foreach ($resolvedServices as $service) {
            $className = $service['class'];
            $resolvedClassNames[] = $className;

            if (! class_exists($className)) {
                $invalidServices[] = "Tool service class does not exist: {$className}";
                continue;
            }

            try {
                $this->registry->discoverService(
                    $className,
                    $service['module_scope'] ?? null,
                    $environment ?? $this->defaultEnvironment()
                );
            } catch (\Throwable $e) {
                $invalidServices[] = $e->getMessage();
            }
        }

        return [
            'missing_tables' => $this->missingTables([
                Tables::AGENT_TOOL_DEFINITION,
                Tables::AGENT_TOOL_BINDING,
            ]),
            'invalid_services' => array_values(array_unique($invalidServices)),
            'resolved_services' => array_values(array_unique($resolvedClassNames)),
        ];
    }

    /**
     * Inspect whether the system is ready for tool grant operations.
     *
     * @return array{missing_tables: array<int, string>}
     */
    public function inspectGrantReadiness(): array
    {
        return [
            'missing_tables' => $this->missingTables([
                Tables::AGENT_PROFILE,
                Tables::AGENT_TOOL_DEFINITION,
                Tables::AGENT_PROFILE_TOOL,
            ]),
        ];
    }

    /**
     * Assert that the system is ready for tool sync operations; throws on failure.
     *
     * @param array<int, string|array<string, mixed>> $services    Service classes to validate.
     * @param string|null                              $environment Target environment.
     * @return void
     * @throws \InvalidArgumentException If missing tables or invalid services are found.
     */
    public function assertSyncReady(array $services = [], ?string $environment = null): void
    {
        $inspection = $this->inspectSyncReadiness($services, $environment);

        if ($inspection['missing_tables'] !== []) {
            throw new InvalidArgumentException(
                'Agentic sync preflight failed. Missing tables: ' . implode(', ', $inspection['missing_tables'])
            );
        }

        if ($inspection['invalid_services'] !== []) {
            throw new InvalidArgumentException(
                'Agentic sync preflight failed. Invalid services: ' . implode(' | ', $inspection['invalid_services'])
            );
        }
    }

    /**
     * Assert that the system is ready for tool grant operations; throws on failure.
     *
     * @return void
     * @throws \InvalidArgumentException If required tables are missing.
     */
    public function assertGrantReady(): void
    {
        $inspection = $this->inspectGrantReadiness();

        if ($inspection['missing_tables'] !== []) {
            throw new InvalidArgumentException(
                'Agentic grant preflight failed. Missing tables: ' . implode(', ', $inspection['missing_tables'])
            );
        }
    }

    protected function defaultEnvironment(): string
    {
        return (string) $this->config->get('core.agentic.discovery.default_environment', app()->environment());
    }

    /**
     * @param array<int, string|array<string, mixed>> $services
     * @return array<int, array{class: string, module_scope: string|null}>
     */
    protected function normalizeServices(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (is_string($service) && $service !== '') {
                $normalized[] = [
                    'class' => $service,
                    'module_scope' => null,
                ];
                continue;
            }

            if (! is_array($service)) {
                continue;
            }

            $className = Arr::get($service, 'class');
            if (! is_string($className) || $className === '') {
                continue;
            }

            $moduleScope = Arr::get($service, 'module_scope');

            $normalized[] = [
                'class' => $className,
                'module_scope' => is_string($moduleScope) && $moduleScope !== '' ? $moduleScope : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $tables
     * @return array<int, string>
     */
    protected function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table) => ! Schema::hasTable($table)));
    }
}
