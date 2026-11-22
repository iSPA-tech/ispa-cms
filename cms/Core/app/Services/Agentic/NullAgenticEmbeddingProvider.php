<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Cms\Core\Agents\Contracts\AgenticEmbeddingProviderInterface;

/**
 * No-op implementation of AgenticEmbeddingProviderInterface.
 *
 * Returns empty results for all embedding requests. Used as a
 * fallback when no real embedding provider is configured or during testing.
 *
 * @see AgenticEmbeddingProviderInterface
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class NullAgenticEmbeddingProvider implements AgenticEmbeddingProviderInterface
{
    /**
     * Return an empty embedding response.
     *
     * @param string      $input The input text (ignored).
     * @param string|null $model The model name (ignored).
     * @return array<string, mixed> Always returns an empty array.
     */
    public function createEmbedding(string $input, ?string $model = null): array
    {
        return [];
    }
}
