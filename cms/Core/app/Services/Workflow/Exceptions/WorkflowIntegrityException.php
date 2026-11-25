<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

/**
 * A workflow version failed its integrity check: the stored graph does not match its content hash, the seal does not
 * match, or the version row is missing or belongs to another project. The run is refused and the project paused
 * (see WorkflowIntegrity::violated()).
 *
 * Extends WorkflowProjectInactive so start paths that refuse an inactive project refuse this too.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowIntegrityException extends WorkflowProjectInactive
{
    public const CODE = 'integrity_mismatch';

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $versionId,
        public readonly string $reason,
    ) {
        parent::__construct('The workflow version failed its integrity check, so it can not run.');
    }
}
