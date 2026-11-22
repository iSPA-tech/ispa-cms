<?php

namespace App\Models;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;

class SpamEmailBlock extends Model
{
    public $table = Tables::SPAM_EMAIL_BLOCKS;

    protected $fillable = [
        'email_hash',
    ];

    protected $hidden = [
        'email_hash',
    ];
}
