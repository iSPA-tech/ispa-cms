<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;

/**
 * No-op implementation of AgenticAiProviderInterface.
 *
 * Returns empty results for all AI completion requests. Used as a
 * fallback when no real AI provider is configured or during testing.
 *
 * @see AgenticAiProviderInterface
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class NullAgenticAiProvider implements AgenticAiProviderInterface
{
    /**
     * Return an empty completion response.
     *
     * @param array<string, mixed> $payload The completion request payload (ignored).
     * @return array<string, mixed> Always returns an empty array.
     */
    public function createCompletion(array $payload = []): array
    {
        return [];
    }

    /**
     * Return empty JSON content from a completion response.
     *
     * @param array<string, mixed> $response The completion response (ignored).
     * @return string Always returns '{}';
     */
    public function getContent(array $response = []): string
    {
        return '{}';
    }
}
