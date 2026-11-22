<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable render/provenance identity plus audited eligibility attestation
 * and independent emergency runtime control (plan Chunk 2 Task 13a;
 * architecture.md "widget_artifact_manifests"; ADR
 * adr-widget-artifact-store.md decision 10).
 *
 * IMMUTABILITY DISCIPLINE: canonical provenance fields (manifest_hash,
 * artifact_blob_id, generator/renderer identity, applicability,
 * fingerprints, provider_contracts, trust policy/lane) never mutate once
 * set -- $fillable intentionally separates them from the attestation/control
 * fields a later approval/block transition legitimately updates. Real
 * enforcement that canonical fields cannot be re-mass-assigned after
 * creation is a service-layer responsibility (Task 15b), not this model.
 */
final class WidgetArtifactManifest extends Model
{
    protected $table = WidgetTableConstant::ARTIFACT_MANIFEST;

    protected $fillable = [
        // Canonical provenance -- set once at creation, never mutated again.
        'project_name',
        'manifest_hash',
        'artifact_blob_id',
        'generator_version',
        'renderer_key',
        'renderer_version',
        'applicability',
        'executable_surface_fingerprint_hash',
        'canonical_schema_hash',
        'provider_contracts',
        'trust_policy_version',
        'trust_lane',
        // Monotonic eligibility attestation -- mutated by approve/revoke transitions.
        'eligibility_state',
        'attestation_version',
        'approved_by',
        'approved_at',
        'revoked_by',
        'revoked_at',
        'eligibility_reason',
        // Independent emergency runtime control -- mutated by block/clear transitions.
        'control_version',
        'blocked',
        'blocked_by',
        'blocked_at',
        'cleared_by',
        'cleared_at',
        'control_reason',
    ];

    protected $casts = [
        'applicability' => 'array',
        'provider_contracts' => 'array',
        'attestation_version' => 'integer',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
        'control_version' => 'integer',
        'blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function blob(): BelongsTo
    {
        return $this->belongsTo(WidgetArtifactBlob::class, 'artifact_blob_id');
    }

    public function instanceVersions(): HasMany
    {
        return $this->hasMany(WidgetInstanceVersion::class, 'artifact_manifest_id');
    }
}
