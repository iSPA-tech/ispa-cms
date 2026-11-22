<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models\Agents;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Versioned output artifact produced by an agent run.
 *
 * Represents a discrete piece of work output (code diff, document, file, etc.)
 * generated during an agent execution. Supports hierarchical artifact trees
 * via parent/child relationships and tracks storage, versioning, and checksums.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $run_id                  Foreign key to the owning agent run
 * @property int|null    $artifact_group_id       Logical grouping identifier for related artifacts
 * @property int|null    $parent_artifact_id      Self-referencing parent for hierarchical artifacts
 * @property string      $artifact_type           Type classifier (e.g. code_diff, document, file)
 * @property string|null $subject_type            Polymorphic subject model class
 * @property int|null    $subject_id              Polymorphic subject model ID
 * @property string|null $source_snapshot_ref     Reference to the source state snapshot
 * @property array|null  $original_data_snapshot  Original data before agent modifications
 * @property array|null  $payload                 The artifact content payload
 * @property string|null $storage_driver          Storage backend identifier (local, s3, etc.)
 * @property string|null $storage_ref             Storage path or reference key
 * @property int|null    $version                 Artifact version number
 * @property string|null $render_hint             UI rendering hint for display
 * @property string|null $apply_contract_version  Contract version for applying this artifact
 * @property string|null $checksum                Content integrity checksum
 * @property int|null    $size_bytes              Artifact size in bytes
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentRun           $run             The agent run that produced this artifact
 * @property-read AgentArtifact|null $parentArtifact  Parent artifact in a hierarchy
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentArtifact[]  $childArtifacts  Child artifacts
 * @property-read \Illuminate\Database\Eloquent\Collection|AgentApproval[]  $approvals       Human review approvals
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentArtifact extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_ARTIFACT;

    protected $fillable = [
        'run_id',
        'artifact_group_id',
        'parent_artifact_id',
        'artifact_type',
        'subject_type',
        'subject_id',
        'source_snapshot_ref',
        'original_data_snapshot',
        'payload',
        'storage_driver',
        'storage_ref',
        'version',
        'render_hint',
        'apply_contract_version',
        'checksum',
        'size_bytes',
    ];

    protected $casts = [
        'artifact_group_id' => 'integer',
        'parent_artifact_id' => 'integer',
        'original_data_snapshot' => 'array',
        'payload' => 'array',
        'version' => 'integer',
        'size_bytes' => 'integer',
    ];

    /**
     * Get the agent run that produced this artifact.
     * @return BelongsTo
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /**
     * Get the parent artifact in a hierarchical artifact tree.
     * @return BelongsTo
     */
    public function parentArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_artifact_id');
    }

    /**
     * Get all child artifacts of this artifact.
     * @return HasMany
     */
    public function childArtifacts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_artifact_id');
    }

    /**
     * Get all human review approvals for this artifact.
     * @return HasMany
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(AgentApproval::class, 'artifact_id');
    }
}
