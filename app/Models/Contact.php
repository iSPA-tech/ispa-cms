<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use DateTimeInterface;
use App\Constants\Tables;
use Cms\Core\Traits\ModelBoot;
use App\Observers\ContactObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Contact
 * About the Contact Entity
 *
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string name
 * @property string email
 * @property string mobile
 * @property string address
 * @property string organization
 * @property string url
 * @property string source
 * @property string type
 * @property string birthday
 * @property int gender
 * @property int user_id
 * @property bool is_subscribe
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 */
class Contact extends Model
{
    use HasFactory, SoftDeletes;
    use ModelBoot;

    /** @var string */
    public $table = Tables::CONTACTS;

    /** @var string */
    private static string $observer = ContactObserver::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'mobile', 'address', 'organization', 'url', 'source',
        'type', 'birthday', 'gender', 'user_id', 'is_subscribe',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'gender' => 'integer',
        'user_id' => 'integer',
        'is_subscribe' => 'boolean',
        'birthday' => 'datetime',
    ];
}
