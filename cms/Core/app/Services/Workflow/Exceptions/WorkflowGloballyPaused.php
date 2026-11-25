<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

/**
 * Raised by WorkflowProjectRunService::start() while a Webmaster (or the server config) has paused all workflows
 * (guardrails B7, WorkflowGlobalPause).
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowGloballyPaused extends WorkflowProjectInactive
{
    public const CODE = 'workflows_paused';

    public function __construct(string $message = 'All workflows are paused on this site, so this one can not run now.')
    {
        parent::__construct($message);
    }
}
