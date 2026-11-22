<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable replay protection for Instance create/update/clone,
 * update-and-repin, publish, rollback, and post-MVP Safe Import store (plan
 * Chunk 2 Task 13a; architecture.md "widget_idempotency_keys"; frozen
 * contract cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * "idempotency" section).
 *
 * Retains ONLY actor/project/action/resource scope, the caller-provided
 * key, canonical request hash, processing/committed state, safe HTTP
 * status, and committed resource type/ID/version identity -- never raw
 * request values, provider payloads, preview overlays, or serialized
 * response bodies (`$fillable` deliberately excludes them; see
 * WidgetSiteLayoutSchemaTest for the executable absence proof). `state` is
 * a plain string (processing|committed) -- no native DB enum.
 */
final class WidgetIdempotencyKey extends Model
{
    protected $table = WidgetTableConstant::IDEMPOTENCY_KEY;

    public const STATE_PROCESSING = 'processing';

    public const STATE_COMMITTED = 'committed';

    protected $fillable = [
        'project_name',
        'action',
        'resource_scope',
        'actor_id',
        'idempotency_key',
        'request_hash',
        'state',
        'http_status',
        'resource_type',
        'resource_id',
        'resource_version',
        'expires_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'expires_at' => 'datetime',
    ];
}
