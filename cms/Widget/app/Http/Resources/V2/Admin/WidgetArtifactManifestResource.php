<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of one Artifact Manifest's safe eligibility/runtime
 * metadata (Chunk 1 Task 12 skeleton). This backs both the `GET
 * /catalog/manifests` approval inventory and the materialize/approval/
 * runtime-block responses. It exposes only safe pending/approved/revoked
 * metadata inside the authorized project — never the canonical manifest
 * JSON, artifact storage path/key, source HTML, or secret-value metadata
 * (architecture.md "widget_artifact_manifests"; checklist item 4).
 *
 * `eligibility_state` and emergency `control_version`/`blocked` are
 * deliberately separate fields: eligibility approval is a future-promotion
 * gate, emergency block is a different, independently-versioned runtime
 * control (D-039).
 *
 * The base skeleton stays model-agnostic (frozenFields()/toArray() project a
 * plain array via data_get, see {@see WidgetInstanceResource} for that shared
 * pattern's rationale). Task 15b adds the OPTIONAL {@see self::fromManifestRow()}
 * /{@see self::manifestRowFields()} named-constructor pair so the two
 * single-manifest response controllers and the catalog inventory share ONE
 * copy of the safe raw-column -> frozen-field projection; they accept a raw
 * array or an Eloquent row interchangeably and never force model coupling on
 * a caller that already holds a plain array.
 */
final class WidgetArtifactManifestResource extends JsonResource
{
    public function __construct(mixed $resource = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return list<string>
     */
    public static function frozenFields(): array
    {
        return [
            'manifest_hash',
            'project_name',
            'eligibility_state',
            'attestation_version',
            'policy_version',
            'trust_lane',
            'control_version',
            'blocked',
            'approved_by',
            'approved_at',
            'revoked_by',
            'revoked_at',
        ];
    }

    /**
     * The SINGLE source of the safe raw-manifest-row -> frozen-field
     * projection (the one place `trust_policy_version` is renamed to the
     * resource's frozen `policy_version` field). Accepts either a raw-column
     * array (the approval/materialize services' `safeRowSnapshot`, which uses
     * `trust_policy_version`) or a {@see \Cms\Widget\Models\WidgetArtifactManifest}
     * Eloquent row (the catalog inventory's paginator rows) -- `data_get`
     * reads both uniformly. Never projects any storage key, source bytes, or
     * Blob metadata; only the twelve safe frozen fields.
     *
     * @param array<string, mixed>|Model $row
     * @return array<string, mixed>
     */
    public static function manifestRowFields(array|Model $row): array
    {
        return [
            'manifest_hash' => data_get($row, 'manifest_hash'),
            'project_name' => data_get($row, 'project_name'),
            'eligibility_state' => data_get($row, 'eligibility_state'),
            'attestation_version' => data_get($row, 'attestation_version'),
            'policy_version' => data_get($row, 'trust_policy_version'),
            'trust_lane' => data_get($row, 'trust_lane'),
            'control_version' => data_get($row, 'control_version'),
            'blocked' => data_get($row, 'blocked'),
            'approved_by' => data_get($row, 'approved_by'),
            'approved_at' => data_get($row, 'approved_at'),
            'revoked_by' => data_get($row, 'revoked_by'),
            'revoked_at' => data_get($row, 'revoked_at'),
        ];
    }

    /**
     * Named constructor for a single-manifest response: wraps
     * {@see self::manifestRowFields()} so the approval and materialize
     * controllers stop hand-copying the identical twelve-field
     * `trust_policy_version` -> `policy_version` block. The catalog inventory
     * uses {@see self::manifestRowFields()} directly inside its paginator
     * `through()` because a collection wraps arrays, not resources.
     *
     * @param array<string, mixed>|Model $row
     */
    public static function fromManifestRow(array|Model $row): self
    {
        return new self(self::manifestRowFields($row));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $output = [];

        foreach (self::frozenFields() as $field) {
            $output[$field] = data_get($this->resource, $field);
        }

        return $output;
    }
}
