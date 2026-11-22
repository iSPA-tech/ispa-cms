<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models\Workflows;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class WorkflowProject
 * -- About the WorkflowProject Entity
 *
 * @package Cms\Core\Models\Workflows
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property string name
 * @property string slug
 * @property string trigger_event
 * @property string target_entity_type
 * @property boolean is_active
 * @property array tags
 * @property string description
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\Workflows\WorkflowStep[] $steps  Workflow steps in this project
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\Workflows\WorkflowConnection[] $connections  Workflow connections in this project
 */
class WorkflowProject extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = Tables::WORKFLOW_PROJECT;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
        'trigger_event',
        'target_entity_type',
        'is_active',
        'tags',
        'description',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
        'tags' => 'array',
    ];

    /**
     * Get the workflow steps for this project.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'project_id');
    }

    /**
     * Get the workflow connections for this project.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function connections(): HasMany
    {
        return $this->hasMany(WorkflowConnection::class, 'project_id');
    }
}
