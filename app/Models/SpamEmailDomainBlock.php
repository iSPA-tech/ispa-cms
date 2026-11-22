<?php

namespace App\Models;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;

class SpamEmailDomainBlock extends Model
{
    public $table = Tables::SPAM_EMAIL_DOMAIN_BLOCKS;

    protected $fillable = [
        'domain_hash',
    ];

    protected $hidden = [
        'domain_hash',
    ];
}
