<?php

declare(strict_types=1);

namespace Cms\Core\Models;

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored incoming webhook event (unique per provider and event id).
 *
 * The payload is kept for replay only; never log it.
 */
class WebhookReceipt extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $table = CoreTableConstant::WEBHOOK_RECEIPTS;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
