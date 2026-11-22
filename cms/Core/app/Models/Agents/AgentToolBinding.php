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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Runtime binding that connects a tool definition to a concrete implementation.
 *
 * Maps abstract tool definitions to specific implementation classes or services,
 * scoped by module, environment, tenant, and policy. Supports rollout controls
 * and priority-based resolution when multiple bindings exist.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $tool_definition_id  Foreign key to the tool definition
 * @property string|null $module_scope        CMS module scope for this binding
 * @property string|null $environment         Target environment (production, staging, dev)
 * @property int|null    $tenant_id           Multi-tenant scope identifier
 * @property string|null $policy_scope        Policy scope for authorization checks
 * @property array|null  $rollout_scope       Gradual rollout configuration
 * @property string      $binding_type        Binding type (class, service, closure)
 * @property string      $binding_target      Fully qualified class name or service identifier
 * @property array|null  $binding_config      Additional binding configuration
 * @property bool        $enabled             Whether this binding is currently active
 * @property int         $priority            Resolution priority (higher = preferred)
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read AgentToolDefinition $definition The abstract tool definition this binding implements
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models\Agents
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentToolBinding extends Model
{
    use HasFactory;

    protected $table = Tables::AGENT_TOOL_BINDING;

    protected $fillable = [
        'tool_definition_id',
        'module_scope',
        'environment',
        'tenant_id',
        'policy_scope',
        'rollout_scope',
        'binding_type',
        'binding_target',
        'binding_config',
        'enabled',
        'priority',
    ];

    protected $casts = [
        'rollout_scope' => 'array',
        'binding_config' => 'array',
        'enabled' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Get the abstract tool definition this binding implements.
     * @return BelongsTo
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(AgentToolDefinition::class, 'tool_definition_id');
    }
}
