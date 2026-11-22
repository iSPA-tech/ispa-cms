<?php

declare(strict_types=1);

namespace Cms\Widget\Policies;

use Cms\Core\Policies\AbstractModelPolicy;
use Cms\Widget\Models\WidgetLayout;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * Authorization policy for Site Layout draft/release lifecycle management
 * (plan Chunk 2 Task 14; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * frozen permission inventory:
 * widget_layout.viewAny/view/update/preview/publish/rollback).
 *
 * Extends AbstractModelPolicy with permission prefix "widget_layout" for the
 * standard viewAny/view/update abilities, and adds the three lifecycle
 * abilities that have no AbstractModelPolicy default: preview, publish,
 * rollback. These three are permission-only (no ownership fallback) --
 * Layouts are project-scoped shared resources, not user-owned.
 *
 * Deliberately no "create"/"delete"/"restore"/"forceDelete" override:
 * Layouts are bootstrapped via the by-key PUT route
 * (PUT /layouts/by-key/{layout_key}), not a dedicated create permission, and
 * no delete/restore/force-delete route exists in the frozen 30-route
 * contract. AbstractModelPolicy's inherited create()/delete()/restore()/
 * forceDelete() still exist but always deny in practice because
 * "widget_layout.create"/"delete"/"restore"/"forceDelete" are never seeded
 * (WidgetPermissionTableSeeder) -- fail-closed by omission, not by an
 * explicit override.
 *
 * Ownership fallback is inert BY DESIGN: widget_layouts has neither
 * `user_id` nor any creator column at all, so AbstractModelPolicy::owns()
 * can never match and every inherited ability is permission-only in
 * practice. Adding a `user_id` column to widget_layouts later would
 * silently FAIL OPEN on the inherited view/update/delete/restore/forceDelete
 * fallbacks; revisit this policy before any such migration.
 *
 * @see \Cms\Core\Policies\AbstractModelPolicy
 * @see \Cms\Widget\Models\WidgetLayout
 */
final class WidgetLayoutPolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    protected function prefix(): string
    {
        return 'widget_layout';
    }

    /**
     * Determine whether the user can open a preview session for the layout draft.
     */
    public function preview(UserContract $user, WidgetLayout $layout): bool
    {
        return $this->checkPermission($user, $this->perm('preview'));
    }

    /**
     * Determine whether the user can publish the layout's current draft.
     */
    public function publish(UserContract $user, WidgetLayout $layout): bool
    {
        return $this->checkPermission($user, $this->perm('publish'));
    }

    /**
     * Determine whether the user can roll the layout back to a prior release.
     */
    public function rollback(UserContract $user, WidgetLayout $layout): bool
    {
        return $this->checkPermission($user, $this->perm('rollback'));
    }
}
