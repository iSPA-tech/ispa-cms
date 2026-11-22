<?php
/**********************************************************************************************************************
 * Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                       *
 **********************************************************************************************************************/

namespace Cms\Core\Models;

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared canonical numeric range used by polymorphic meta_data assignments.
 *
 * Unit belongs to MetaAttribute. Relation distinguishes a continuous interval
 * from discrete alternatives such as a two-seat or four-seat configuration.
 */
class MetaValueRange extends Model
{
    use HasFactory;

    public const RELATION_BETWEEN = 'between';
    public const RELATION_ALTERNATIVE = 'alternative';

    /** @var string */
    public $table = CoreTableConstant::META_VALUES_RANGE;

    /** @var array<int, string> */
    protected $fillable = [
        'minimum',
        'maximum',
        'relation',
        'value_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'minimum' => 'float',
        'maximum' => 'float',
    ];
}
