<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

use Cms\Core\Agents\Support\AgentToolDefinitionData;

/**
 * Formats AgentToolDefinitionData DTOs into OpenAI function-calling schema.
 *
 * Converts the internal tool definition format into the JSON structure required
 * by OpenAI's chat completions API with function calling (tools parameter).
 *
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0
 */
class ToolDefinitionFormatter
{
    /**
     * Format a list of tool definitions into OpenAI function-calling tools array.
     *
     * @param  array<string, AgentToolDefinitionData> $definitions Tool definitions keyed by tool name.
     * @param  array<int, string> $allowedToolNames Only include these tools (empty = include all).
     * @return array<int, array<string, mixed>> OpenAI tools array.
     */
    public function format(array $definitions, array $allowedToolNames = []): array
    {
        $tools = [];

        foreach ($definitions as $definition) {
            if ($allowedToolNames !== [] && !in_array($definition->toolName, $allowedToolNames, true)) {
                continue;
            }

            $tools[] = $this->formatOne($definition);
        }

        return $tools;
    }

    /**
     * Format a single tool definition into OpenAI function schema.
     *
     * @param  AgentToolDefinitionData $definition The tool definition.
     * @return array<string, mixed> Single OpenAI tool entry.
     */
    public function formatOne(AgentToolDefinitionData $definition): array
    {
        $parameters = $definition->inputSchema;
        if ($parameters === []) {
            $parameters = ['type' => 'object', 'properties' => new \stdClass()];
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $definition->toolName,
                'description' => $definition->description ?? "Execute {$definition->toolName}",
                'parameters' => $parameters,
            ],
        ];
    }
}
