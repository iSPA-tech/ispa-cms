<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use Cms\Core\Traits\Models\StatusScope;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Scheduler
 * -- About the Scheduler Entity
 *
 * @package App\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property string status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 */
class Scheduler extends Model
{
    use HasFactory, SoftDeletes, StatusScope;

    /** @var string */
    public $table = Tables::SCHEDULER;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [];
}
