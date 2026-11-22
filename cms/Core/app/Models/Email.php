<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use App\Models\Contact;
use Cms\Core\Traits\HasUuid;
use Cms\Core\Traits\ModelBoot;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Observers\EmailObserver;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Email
 * About the Email Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string uuid
 * @property string subject
 * @property string name
 * @property string email
 * @property string from_name
 * @property string from_email
 * @property string reply_to
 * @property string cc
 * @property string bcc
 * @property string content
 * @property string raw_data
 * @property int contact_id
 * @property int user_id
 * @property int reply_id
 * @property string last_read
 * @property string type inbox, sent, report, system...
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \App\Models\Contact $contact  Contact associated with this email
 */
class Email extends Model
{
    use HasFactory, SoftDeletes;
    use HasUuid, ModelBoot, SerializeDateTrait;

    /** @var string */
    public $table = CoreTableConstant::EMAILS;

    /** @var string */
    private static string $observer = EmailObserver::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid', 'subject', 'name', 'email', 'from_name', 'from_email', 'reply_to', 'cc', 'bcc',
        'content', 'raw_data', 'contact_id', 'user_id', 'reply_id', 'last_read', 'type',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'raw_data' => 'json',
        'contact_id' => 'integer',
        'user_id' => 'integer',
        'reply_id' => 'integer',
    ];

    /**
     * Contact relationship
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-10, 11:1
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
