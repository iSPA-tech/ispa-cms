<?php

declare(strict_types=1);

namespace Cms\Widget\Preview;

/**
 * The complete signed preview session record (plan Chunk 2 Task 19, DoD
 * 1104; docs/architecture/adr-widget-preview-session.md decision 4):
 * "project, layout, locale, Template Context, Target Context, canonical
 * public route name/parameters, release baseline, and payload fingerprint.
 * Minting actor ID is audit metadata only."
 *
 * Encrypted as ONE opaque blob at rest
 * (`Crypt::encryptString(json_encode($this->toArray()))`) -- nothing in
 * this record is stored in Redis as plaintext, including the
 * context-binding fields, per the ADR's own "Alternatives considered" #4
 * ("Skip encryption of the cached overlay because the cache store itself
 * is trusted infrastructure -- rejected").
 *
 * `overlayPlacements` carries the SAME normalized row shape
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService} produces
 * internally (slot_key, scope_type, scope_key, target_type, target_id,
 * scope_identity, widget_instance_version_id, position, enabled, rules) --
 * every `widget_instance_version_id` is a real persisted
 * {@see \Cms\Widget\Models\WidgetInstanceVersion} row id, never an
 * ephemeral/unsaved value (fixture `overlay_constraints`:
 * "instance_version_references: persisted_only", "ephemeral_instance_values:
 * forbidden").
 *
 * This class is installed as request-scoped container state by
 * {@see \Cms\Widget\Http\Middleware\ResolveWidgetPreviewSession} for the
 * duration of exactly one request and removed in `finally`.
 */
final readonly class WidgetPreviewContext
{
    /**
     * @param list<array<string, mixed>> $overlayPlacements
     * @param array<string, mixed> $canonicalRouteParameters
     * @param list<int> $manifestIds distinct WidgetArtifactManifest ids referenced by the overlay, re-gated at consume time.
     */
    public function __construct(
        public string $projectName,
        public string $layoutUuid,
        public string $locale,
        public string $templateContext,
        public string $targetType,
        public string $targetId,
        public string $canonicalRouteName,
        public array $canonicalRouteParameters,
        public ?int $releaseBaseline,
        public array $overlayPlacements,
        public array $manifestIds,
        public string $fingerprint,
        public ?int $mintedBy,
        public string $mintedAt,
        public ?string $viewport,
        public ?string $editorNonce = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_name' => $this->projectName,
            'layout_uuid' => $this->layoutUuid,
            'locale' => $this->locale,
            'template_context' => $this->templateContext,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'canonical_route_name' => $this->canonicalRouteName,
            'canonical_route_parameters' => $this->canonicalRouteParameters,
            'release_baseline' => $this->releaseBaseline,
            'overlay_placements' => $this->overlayPlacements,
            'manifest_ids' => $this->manifestIds,
            'fingerprint' => $this->fingerprint,
            'minted_by' => $this->mintedBy,
            'minted_at' => $this->mintedAt,
            'viewport' => $this->viewport,
            'editor_nonce' => $this->editorNonce,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            projectName: (string) ($data['project_name'] ?? ''),
            layoutUuid: (string) ($data['layout_uuid'] ?? ''),
            locale: (string) ($data['locale'] ?? ''),
            templateContext: (string) ($data['template_context'] ?? ''),
            targetType: (string) ($data['target_type'] ?? ''),
            targetId: (string) ($data['target_id'] ?? ''),
            canonicalRouteName: (string) ($data['canonical_route_name'] ?? ''),
            canonicalRouteParameters: is_array($data['canonical_route_parameters'] ?? null) ? $data['canonical_route_parameters'] : [],
            releaseBaseline: isset($data['release_baseline']) ? (int) $data['release_baseline'] : null,
            overlayPlacements: is_array($data['overlay_placements'] ?? null) ? $data['overlay_placements'] : [],
            manifestIds: is_array($data['manifest_ids'] ?? null) ? array_map('intval', $data['manifest_ids']) : [],
            fingerprint: (string) ($data['fingerprint'] ?? ''),
            mintedBy: isset($data['minted_by']) ? (int) $data['minted_by'] : null,
            mintedAt: (string) ($data['minted_at'] ?? ''),
            viewport: isset($data['viewport']) ? (string) $data['viewport'] : null,
            editorNonce: isset($data['editor_nonce']) && $data['editor_nonce'] !== '' ? (string) $data['editor_nonce'] : null,
        );
    }
}
