<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Cms\Core\Models\Agents\AgentArtifact;

/**
 * Builds structured before/after diff payloads for agent approval review.
 *
 * Transforms an AgentArtifact into a human-readable diff representation
 * based on the artifact type, supporting content patches, SEO patches,
 * product catalog normalization, custom flags, education evaluations,
 * and metadata review resolutions. Falls back to a generic JSON diff
 * for unrecognized artifact types.
 *
 * @see AgentArtifact
 * @see AgentApprovalService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentApprovalDiffBuilder
{
    /**
     * Diff builders contributed by modules for their own artifact types, keyed by artifact type.
     *
     * @var array<string, callable(AgentArtifact): array<string, mixed>>
     */
    private array $builders = [];

    /**
     * Register the diff builder of an artifact type a module owns (a later registration replaces an earlier one).
     * Types nobody registers keep the built-in diffs or the generic JSON fallback.
     *
     * @param callable(AgentArtifact): array<string, mixed> $builder
     */
    public function register(string $artifactType, callable $builder): void
    {
        $this->builders[$artifactType] = $builder;
    }

    /**
     * Build a structured diff payload for the given artifact.
     *
     * @param AgentArtifact $artifact The artifact to build a diff for.
     * @return array<string, mixed> The structured diff payload.
     */
    public function build(AgentArtifact $artifact): array
    {
        $registered = $this->builders[(string) $artifact->artifact_type] ?? null;
        if ($registered !== null) {
            return $registered($artifact);
        }

        return match ($artifact->artifact_type) {
            'content_post_description_patch' => $this->buildContentDescriptionPatch($artifact),
            'content_post_seo_patch' => $this->buildContentSeoPatch($artifact),
            'product_catalog_normalization_patch' => $this->buildProductCatalogNormalizationPatch($artifact),
            'product_custom_flags_patch' => $this->buildProductCustomFlagsPatch($artifact),
            'product_content_patch' => $this->buildProductContentPatch($artifact),
            'product_description_patch' => $this->buildProductDescriptionPatch($artifact),
            'product_seo_patch' => $this->buildProductSeoPatch($artifact),
            'education_ai_evaluation_patch' => $this->buildEducationAiEvaluationPatch($artifact),
            default => $this->buildJsonFallback($artifact),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContentDescriptionPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'text_diff',
            'field' => 'description',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'before' => data_get($artifact->original_data_snapshot, 'description'),
            'after' => data_get($artifact->payload, 'proposed_change.description'),
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildJsonFallback(AgentArtifact $artifact): array
    {
        return [
            'type' => 'json_patch',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'before' => $artifact->original_data_snapshot,
            'after' => data_get($artifact->payload, 'proposed_change', $artifact->payload),
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContentSeoPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'json_patch',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'field' => 'seo',
            'before' => [
                'language_id' => data_get($artifact->original_data_snapshot, 'seo.language_id'),
                'title' => data_get($artifact->original_data_snapshot, 'seo.title'),
                'description' => data_get($artifact->original_data_snapshot, 'seo.description'),
                'hashtag' => data_get($artifact->original_data_snapshot, 'seo.hashtag'),
            ],
            'after' => [
                'language_id' => data_get($artifact->payload, 'proposed_change.language_id'),
                'title' => data_get($artifact->payload, 'proposed_change.title'),
                'description' => data_get($artifact->payload, 'proposed_change.description'),
                'hashtag' => data_get($artifact->payload, 'proposed_change.hashtag'),
            ],
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProductSeoPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'json_patch',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'field' => 'seo',
            'before' => [
                'language_id' => data_get($artifact->original_data_snapshot, 'seo.language_id'),
                'title' => data_get($artifact->original_data_snapshot, 'seo.title'),
                'description' => data_get($artifact->original_data_snapshot, 'seo.description'),
                'hashtag' => data_get($artifact->original_data_snapshot, 'seo.hashtag'),
            ],
            'after' => [
                'language_id' => data_get($artifact->payload, 'proposed_change.language_id'),
                'title' => data_get($artifact->payload, 'proposed_change.title'),
                'description' => data_get($artifact->payload, 'proposed_change.description'),
                'hashtag' => data_get($artifact->payload, 'proposed_change.hashtag'),
            ],
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProductCatalogNormalizationPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'product_catalog_patch',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'field_groups' => ['description', 'seo'],
            'issue_keys' => data_get($artifact->payload, 'proposed_change.issue_keys', []),
            'before' => [
                'description' => data_get($artifact->original_data_snapshot, 'description'),
                'seo' => [
                    'language_id' => data_get($artifact->original_data_snapshot, 'seo.language_id'),
                    'title' => data_get($artifact->original_data_snapshot, 'seo.title'),
                    'description' => data_get($artifact->original_data_snapshot, 'seo.description'),
                    'hashtag' => data_get($artifact->original_data_snapshot, 'seo.hashtag'),
                ],
            ],
            'after' => [
                'description' => data_get($artifact->payload, 'proposed_change.description'),
                'seo' => [
                    'language_id' => data_get($artifact->payload, 'proposed_change.seo.language_id'),
                    'title' => data_get($artifact->payload, 'proposed_change.seo.title'),
                    'description' => data_get($artifact->payload, 'proposed_change.seo.description'),
                    'hashtag' => data_get($artifact->payload, 'proposed_change.seo.hashtag'),
                ],
            ],
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProductDescriptionPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'text_diff',
            'field' => 'description',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'before' => data_get($artifact->original_data_snapshot, 'description'),
            'after' => data_get($artifact->payload, 'proposed_change.description'),
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProductContentPatch(AgentArtifact $artifact): array
    {
        $before = (array) data_get($artifact->original_data_snapshot, 'content', []);
        $after = (array) data_get($artifact->payload, 'proposed_change.content', []);

        return [
            'type' => 'content_patch',
            'field' => 'content',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'issue_keys' => data_get($artifact->payload, 'proposed_change.issue_keys', []),
            'before' => $before,
            'after' => $after,
            'before_preview' => $this->previewContentBlocks($before),
            'after_preview' => $this->previewContentBlocks($after),
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProductCustomFlagsPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'product_flags_patch',
            'field' => 'custom_flags',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'changed_keys' => data_get($artifact->payload, 'proposed_change.changed_keys', []),
            'before' => $this->normalizeProductFlags(data_get($artifact->original_data_snapshot, 'custom_flags', [])),
            'after' => $this->normalizeProductFlags(data_get($artifact->payload, 'proposed_change.custom_flags', [])),
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildEducationAiEvaluationPatch(AgentArtifact $artifact): array
    {
        return [
            'type' => 'scorecard_change',
            'subject' => [
                'type' => $artifact->subject_type,
                'id' => $artifact->subject_id,
            ],
            'summary' => data_get($artifact->payload, 'summary'),
            'before' => [
                'score' => data_get($artifact->original_data_snapshot, 'score'),
                'feedback_text' => data_get($artifact->original_data_snapshot, 'feedback_text'),
                'status' => data_get($artifact->original_data_snapshot, 'status'),
            ],
            'after' => [
                'score' => data_get($artifact->payload, 'proposed_change.score'),
                'feedback_text' => data_get($artifact->payload, 'proposed_change.feedback_text'),
                'status' => data_get($artifact->payload, 'proposed_change.status'),
            ],
            'score' => [
                'before' => data_get($artifact->original_data_snapshot, 'score'),
                'after' => data_get($artifact->payload, 'proposed_change.score'),
            ],
            'status_transition' => [
                'from' => data_get($artifact->original_data_snapshot, 'status'),
                'to' => data_get($artifact->payload, 'proposed_change.status'),
            ],
            'evidence' => data_get($artifact->payload, 'evidence', []),
            'citations' => data_get($artifact->payload, 'citations', []),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    protected function previewContentBlocks(array $blocks): string
    {
        $segments = array_values(array_filter(array_map(function (array $block): ?string {
            $value = $block['value'] ?? null;

            return is_string($value) ? trim(strip_tags($value)) : null;
        }, $blocks)));

        return implode("\n\n", $segments);
    }

    /**
     * @param mixed $flags
     * @return array<string, bool>
     */
    protected function normalizeProductFlags(mixed $flags): array
    {
        $flags = is_array($flags) ? $flags : [];

        return [
            'newProduct' => (bool) ($flags['newProduct'] ?? false),
            'selling' => (bool) ($flags['selling'] ?? false),
            'goodPrice' => (bool) ($flags['goodPrice'] ?? false),
            'favourite' => (bool) ($flags['favourite'] ?? false),
            'featured' => (bool) ($flags['featured'] ?? false),
        ];
    }
}
