<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

/**
 * Contract for agentic embedding providers in the agent runtime system.
 *
 * Abstracts the vector-embedding service (e.g. OpenAI Embeddings, local models)
 * so that retrieval-augmented generation (RAG) and semantic search components
 * can generate embeddings without coupling to a specific vendor.
 *
 * Implementors: vendor-specific embedding providers registered via the service container.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface AgenticEmbeddingProviderInterface
{
    /**
     * Generate a vector embedding for the given text input.
     *
     * @param  string      $input The text to embed.
     * @param  string|null $model Optional model override (provider-specific identifier).
     * @return array<int, float> The embedding vector as a float array.
     */
    public function createEmbedding(string $input, ?string $model = null): array;
}
