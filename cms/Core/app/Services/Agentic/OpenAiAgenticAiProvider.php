<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Clients\OpenAIClient;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;

/**
 * OpenAI-backed implementation of AgenticAiProviderInterface.
 *
 * Delegates AI completion requests to the application's OpenAIClient.
 * Used as the primary AI provider for agent tool execution when
 * OpenAI is configured.
 *
 * @see AgenticAiProviderInterface
 * @see OpenAIClient
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class OpenAiAgenticAiProvider implements AgenticAiProviderInterface
{
    /**
     * Initialize the provider with the OpenAI client.
     *
     * @param OpenAIClient $client The OpenAI API client for completion requests.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(protected OpenAIClient $client)
    {
    }

    /**
     * Create an AI completion via the OpenAI client.
     *
     * @param array<string, mixed> $payload The completion request payload.
     * @return array<string, mixed> The OpenAI completion response.
     */
    public function createCompletion(array $payload = []): array
    {
        return $this->client->createCompletion($payload);
    }

    /**
     * Extract the content string from an OpenAI completion response.
     *
     * @param array<string, mixed> $response The completion response.
     * @return string The extracted content.
     */
    public function getContent(array $response = []): string
    {
        return $this->client->getContent($response);
    }
}
