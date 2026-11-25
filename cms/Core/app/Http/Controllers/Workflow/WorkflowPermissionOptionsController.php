<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Services\Permissions\PermissionCatalog;

/**
 * Permission picker of the workflow step form: `GET api/v2/admin/workflows/permission-options`.
 *
 * Fields such as approval `reviewer_permission` and notify `permission` store a permission key. Needs
 * `workflow_project.create` or `workflow_project.update`; returns `[{key, label, module, module_label}]` ordered by
 * module (PermissionCatalog::options()).
 *
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026-11-24
 */
class WorkflowPermissionOptionsController extends Controller
{
    public function index(PermissionCatalog $catalog)
    {
        $user = user();
        if (!$user?->can('workflow_project.update') && !$user?->can('workflow_project.create')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        return ires()->data($catalog->options());
    }
}
