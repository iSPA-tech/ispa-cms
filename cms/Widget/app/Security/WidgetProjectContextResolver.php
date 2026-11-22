<?php

declare(strict_types=1);

namespace Cms\Widget\Security;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Resolves the server-owned authorized project context for every Widget
 * module API call (Chunk 1 Task 12; architecture.md "Authorization and
 * Project Isolation"; docs/architecture/adr-widget-project-authorization.md
 * decision 1).
 *
 * MVP freezes every Widget API to the server-derived active project
 * (`config('ispa-cms.folder_name')`, the same single-project-per-deployment
 * value already verified at config/ispa-cms.php:53). No caller-supplied
 * `project_name` is ever honored in single-project mode — this is the Task
 * 5a conservative freeze, not an open design choice. Reverting this freeze
 * requires new Task 5a-grade evidence that a real project-scoped RBAC model
 * exists (ADR "Multi-project topology re-proof condition"); this class does
 * not pre-approve that transition.
 *
 * Every module API follows the fixed order (architecture.md "Authorization
 * and Project Isolation"): authenticate -> authorize project scope ->
 * resolve project-owned layout/instance -> authorize target -> validate
 * applicability -> mutate. This resolver owns step 2 only; it never reads
 * request input beyond an optional caller-supplied `project_name` to
 * reject.
 */
final class WidgetProjectContextResolver
{
    /**
     * Resolve the server-owned authorized project context. Every Widget
     * module API call starts here; no request input feeds this method.
     */
    public function resolve(): AuthorizedProjectContext
    {
        return new AuthorizedProjectContext(
            projectName: (string) config('ispa-cms.folder_name'),
        );
    }

    /**
     * Reject a caller-supplied project selector that does not match the
     * server-derived active project. A null/absent selector is not a
     * mismatch — most Widget routes never accept a project_name field at
     * all (e.g. the materialize endpoint explicitly accepts "no project
     * selector" per checklist item 3); this method exists for the rare
     * caller-supplied field this contract explicitly rejects rather than
     * silently ignores.
     *
     * Throws Laravel's standard authorization exception (mapped to 403 by
     * the framework's exception handler) rather than returning a boolean,
     * so a future controller's single `try`/exception-handler path can
     * translate the failure into the frozen envelope via
     * {@see \Cms\Widget\Http\Support\WidgetApiErrorResponse} without a
     * bespoke Widget-only exception class in this static-contract slice.
     *
     * @throws AuthorizationException When $projectName does not match the resolved server-owned project.
     */
    public function assertProject(?string $projectName): AuthorizedProjectContext
    {
        $context = $this->resolve();

        if ($projectName !== null && $projectName !== $context->projectName) {
            throw new AuthorizationException(
                "Project '{$projectName}' does not match the authorized project."
            );
        }

        return $context;
    }
}
