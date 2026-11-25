<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

/**
 * The workflow project has no published version (or a run has no version), so it can not run (fail closed).
 *
 * Extends WorkflowProjectInactive so every caller that already refuses an inactive project refuses this too.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowProjectNotPublished extends WorkflowProjectInactive
{
    public const CODE = 'project_not_published';

    public function __construct(string $message = 'The workflow has no published version, so it can not run. Publish it first.')
    {
        parent::__construct($message);
    }
}
