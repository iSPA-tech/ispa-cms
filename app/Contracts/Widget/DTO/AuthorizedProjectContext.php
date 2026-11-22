<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Server-derived, authorization-carrying project context.
 *
 * Frozen by docs/architecture/adr-widget-project-authorization.md (decision 1):
 * MVP freezes every Widget API to the server-derived active project
 * (`config('ispa-cms.folder_name')`); no caller-supplied `project_name` is
 * ever honored. This DTO is the single value threaded through
 * `WidgetTargetContextResolver::search()`/`resolve()` (and later project-
 * scoped authorization checks) so those signatures never take a raw string
 * project name directly.
 *
 * Disclosed as an addition to the Task 7 file list: the plan's frozen
 * resolver signatures reference `AuthorizedProjectContext` without listing
 * it explicitly among the DTO files, so it is created here as a minimal
 * root DTO per the task's deviation-recording instruction.
 */
final readonly class AuthorizedProjectContext
{
    public function __construct(
        public string $projectName,
    ) {
    }
}
