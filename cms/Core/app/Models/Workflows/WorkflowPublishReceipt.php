<?php

declare(strict_types=1);

namespace Cms\Core\Models\Workflows;

use Illuminate\Database\Eloquent\Model;

/**
 * One outward post made by a workflow node, keyed by the node's `receipt_key`, so a retried step reuses the first
 * post instead of posting again.
 *
 * @package Cms\Core\Models\Workflows
 * @since   2026-11-24
 */
class WorkflowPublishReceipt extends Model
{
    /** @var string */
    protected $table = 'workflow_publish_receipts';

    /** @var array<int, string> */
    protected $fillable = ['receipt_key', 'integration', 'credential_name', 'external_id', 'external_url', 'instance_id'];
}
