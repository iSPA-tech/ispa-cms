<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

/**
 * Contract for agentic AI completion providers in the agent runtime system.
 *
 * Abstracts the underlying LLM/AI service (e.g. OpenAI, Anthropic) so the
 * reasoning engine and agent runner can request completions and extract
 * content without coupling to a specific vendor SDK.
 *
 * Implementors: vendor-specific providers registered via the service container.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface AgenticAiProviderInterface
{
    /**
     * Send a completion request to the AI provider.
     *
     * @param  array<string, mixed> $payload Provider-specific request payload (messages, model, temperature, etc.).
     * @return array<string, mixed> The raw provider response as an associative array.
     */
    public function createCompletion(array $payload = []): array;

    /**
     * Extract the textual content from a provider response.
     *
     * @param  array<string, mixed> $response The raw provider response returned by {@see createCompletion()}.
     * @return string The extracted content string.
     */
    public function getContent(array $response = []): string;
}
