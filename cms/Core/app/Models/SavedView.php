<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved filter state of an admin list, owned by a user and optionally shared.
 *
 * `list_key` identifies the list (see {@see \Cms\Core\SavedViews\SavedViewRegistry}); `state` holds plain filter
 * and search values checked by {@see \Cms\Core\SavedViews\SavedViewStateValidator}. Addressed by uuid only; the
 * numeric id and the owner id never leave the server.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $list_key
 * @property string $name
 * @property array<string, mixed> $state
 * @property bool $is_shared
 * @property int $position
 */
class SavedView extends Model
{
    use HasUuid;

    protected $table = CoreTableConstant::SAVED_VIEWS;

    protected $fillable = [
        'uuid',
        'user_id',
        'list_key',
        'name',
        'state',
        'is_shared',
        'position',
    ];

    protected $hidden = [
        'id',
        'user_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'state' => 'array',
        'is_shared' => 'boolean',
        'position' => 'integer',
    ];

    protected $attributes = [
        'is_shared' => false,
        'position' => 0,
    ];

    /**
     * Route key name: saved views are addressed by uuid.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The user who owns the view.
     *
     * @return BelongsTo<User, SavedView>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
