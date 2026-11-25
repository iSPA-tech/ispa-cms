<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Security;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The user a workflow run acts for: the delegate stored on its instance when the instance was created.
 *
 * Read from the database by the executor-owned `vars.__runtime.instance_id`, never from anything a step can write, so
 * a run without an instance (or an instance nobody delegated) has no delegate and the model steps refuse.
 *
 * @package Cms\Core\Workflows\Security
 * @since   2026-11-25
 */
class WorkflowRunDelegate
{
    /**
     * The delegate of the run that owns $context, or null.
     */
    public function for(WorkflowContext $context): ?Authenticatable
    {
        $instanceId = $context->getVarPath(WorkflowContext::RUNTIME_KEY . '.instance_id');
        if (!is_int($instanceId) && !(is_string($instanceId) && ctype_digit($instanceId))) {
            return null;
        }

        try {
            $user = WorkflowInstance::query()->find((int) $instanceId)?->delegatedBy;
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? $user : null;
    }
}
