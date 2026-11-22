<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget;

use JsonException;

/**
 * Immutable value object for one legacy Widget artifact generation manifest.
 *
 * Encodes the complete active-generation map required by the artifact-store
 * ADR (docs/architecture/adr-widget-artifact-store.md, decision 1/5): every
 * project name is mapped to the generation that physically owns its rendered
 * files (its own id when freshly rebuilt, or an older retained generation id
 * when the project set was carried forward unchanged) plus a project-set hash
 * and a per-file size/sha256 fingerprint used for publish-time verification.
 *
 * @package Cms\Core\Services\Widget
 */
final readonly class LegacyWidgetArtifactGenerationManifest
{
    /**
     * @param string $generationId
     * @param string $createdAt ISO-8601 UTC timestamp.
     * @param array<string, array{
     *     generation_id: string,
     *     set_hash: string,
     *     files: array<string, array{size: int, sha256: string}>
     * }> $projects Keyed by project_name.
     */
    public function __construct(
        public string $generationId,
        public string $createdAt,
        public array $projects,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['generation_id'] ?? ''),
            (string) ($data['created_at'] ?? ''),
            (array) ($data['projects'] ?? []),
        );
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        return self::fromArray((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'created_at' => $this->createdAt,
            'projects' => $this->projects,
        ];
    }

    /**
     * Canonical, deterministically-ordered JSON used both for on-disk
     * persistence and for the byte-identity comparison that keeps a
     * no-op retry from creating a duplicate generation.
     *
     * @throws JsonException
     */
    public function toCanonicalJson(): string
    {
        $payload = $this->toArray();
        ksort($payload['projects']);

        foreach ($payload['projects'] as &$project) {
            if (isset($project['files']) && is_array($project['files'])) {
                ksort($project['files']);
            }
        }
        unset($project);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Canonical JSON of only the project map, ignoring generation_id/created_at,
     * so two manifests with identical project content but different generation
     * identity/timestamp compare equal (the no-duplicate-retry check).
     *
     * @throws JsonException
     */
    public function canonicalProjectsJson(): string
    {
        $projects = $this->projects;
        ksort($projects);
        foreach ($projects as &$project) {
            if (isset($project['files']) && is_array($project['files'])) {
                ksort($project['files']);
            }
        }
        unset($project);

        return json_encode($projects, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function projectEntry(string $projectName): ?array
    {
        return $this->projects[$projectName] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function projectNames(): array
    {
        return array_keys($this->projects);
    }
}
