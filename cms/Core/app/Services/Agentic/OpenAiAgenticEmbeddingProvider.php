<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Clients\OpenAIClient;
use Cms\Core\Agents\Contracts\AgenticEmbeddingProviderInterface;
use Cms\Core\Services\Ai\Usage\AiGateway;

/**
 * OpenAI-backed implementation of AgenticEmbeddingProviderInterface.
 *
 * Delegates text embedding requests to the application's OpenAIClient.
 * Used for generating vector embeddings when OpenAI is configured.
 *
 * @see AgenticEmbeddingProviderInterface
 * @see OpenAIClient
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class OpenAiAgenticEmbeddingProvider implements AgenticEmbeddingProviderInterface
{
    /**
     * Initialize the provider with the OpenAI client.
     *
     * @param OpenAIClient $client The OpenAI API client for embedding requests.
     * @param AiGateway|null $gateway Optional usage meter; null keeps the unmetered path.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(protected OpenAIClient $client, protected ?AiGateway $gateway = null)
    {
    }

    /**
     * Create a text embedding via the OpenAI client.
     *
     * @param string      $input The input text to embed.
     * @param string|null $model Optional model name override.
     * @return array<string, mixed> The embedding response.
     */
    public function createEmbedding(string $input, ?string $model = null): array
    {
        if ($this->gateway === null) {
            return $this->client->createEmbedding($input, $model);
        }

        $model = $model ?: 'text-embedding-3-small';
        $result = $this->gateway->meter(
            'openai',
            $model,
            'embeddings',
            fn (): array => $this->client->createEmbeddingResponse($input, $model),
        );

        return $result->response['data'][0]['embedding'] ?? [];
    }
}
