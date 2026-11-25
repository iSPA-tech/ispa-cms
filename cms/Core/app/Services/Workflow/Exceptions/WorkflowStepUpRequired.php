<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

use RuntimeException;

/**
 * A sensitive workflow change (guardrails B3) needs a fresh step-up grant of purpose `workflow_publish`, sent in the
 * `X-Step-Up-Grant` header like every step-up route. The admin answers 409 `step_up_required`.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowStepUpRequired extends RuntimeException
{
    public const CODE = 'step_up_required';

    public function __construct(public readonly string $purpose = 'workflow_publish')
    {
        parent::__construct('Confirm it is you before publishing this change.');
    }
}
