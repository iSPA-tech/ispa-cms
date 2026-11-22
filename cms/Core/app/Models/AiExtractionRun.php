<?php

namespace Cms\Core\Models;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AiExtractionRun extends Model
{
    use HasUuid;

    protected $table = CoreTableConstant::AI_EXTRACTION_RUNS;

    protected $fillable = [
        'module',
        'task',
        'schema_name',
        'schema_version',
        'provider',
        'model',
        'input_hash',
        'schema_hash',
        'instruction_hash',
        'request_hash',
        'input_bytes',
        'prompt_bytes',
        'context_payload',
        'schema_payload',
        'request_payload',
        'response_payload',
        'parsed_payload',
        'usage_payload',
        'confidence',
        'status',
        'error_message',
    ];

    protected $casts = [
        'context_payload' => 'array',
        'schema_payload' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'parsed_payload' => 'array',
        'usage_payload' => 'array',
        'confidence' => 'float',
    ];
}
