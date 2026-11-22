<?php

declare(strict_types=1);

namespace Cms\Widget\Policies;

use Cms\Core\Policies\AbstractModelPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for Widget Instance management (plan Chunk 2 Task 14;
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json frozen
 * permission inventory: widget_instance.viewAny/view/create/update/delete).
 *
 * Extends AbstractModelPolicy with permission prefix "widget_instance",
 * providing standard CRUD authorization (viewAny, view, create, update,
 * delete) with no custom abilities -- the frozen Instance permission set is
 * exactly the AbstractModelPolicy default set.
 *
 * Ownership fallback is inert BY DESIGN: widget_instances stores its creator
 * in `created_by`, not `user_id`, so AbstractModelPolicy::owns() (which reads
 * ownershipKey() = 'user_id') can never match and view/update/delete are
 * permission-only in practice -- pinned by WidgetPermissionSeederTest's
 * creator-with-zero-permissions test. Adding a `user_id` column to
 * widget_instances later would silently FAIL OPEN here (grant the creator
 * view/update/delete without any permission); revisit this policy before any
 * such migration.
 *
 * @see \Cms\Core\Policies\AbstractModelPolicy
 * @see \Cms\Widget\Models\WidgetInstance
 */
final class WidgetInstancePolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    protected function prefix(): string
    {
        return 'widget_instance';
    }
}
