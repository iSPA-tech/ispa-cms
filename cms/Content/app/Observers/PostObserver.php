<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Observers;

use Cms\Content\Models\Post;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Workflows\WorkflowProject;
use App\Traits\Observers\BeforeUpdateTrait;
use App\Traits\Observers\BeforeCreateTrait;
use Cms\Core\Services\Workflow\WorkflowExecutor;

/**
 * Audits Post lifecycle events, runs `post.updated` workflows and queues the guarded optional thumbnail job.
 */
class PostObserver
{
    use BeforeCreateTrait, BeforeUpdateTrait;

    /**
     * Handle the Post "created" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function created(Post $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Post "updated" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function updated(Post $model): void
    {
        logger('Post updated observer triggered for Post ID: ' . $model->id);
        
        // Announce the publication; modules and projects listen for their own follow-up work.
        if ($model->isDirty('status') && $model->status === \App\Enums\StatusEnum::PUBLISHED->value) {
            event(new \Cms\Content\Events\PostPublished((int) $model->id));
        }

        $workflows = WorkflowProject::query()
            ->where('is_active', true)
            ->where('trigger_event', 'post.updated')
            ->where('target_entity_type', Post::class)
            ->with(['steps', 'connections.fromStep', 'connections.toStep'])
            ->get();

        foreach ($workflows as $workflow) {
            (new WorkflowExecutor($workflow, $model))->run();
        }
    }

    /**
     * Handle the Post "deleting" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function deleting(Post $model): void
    {
    }

    /**
     * Handle the Post "deleted" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function deleted(Post $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Post "restoring" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function restoring(Post $model): void
    {
    }

    /**
     * Handle the Post "restored" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function restored(Post $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Post "force deleted" event.
     *
     * @param Post $model
     *
     * @return void
     */
    public function forceDeleted(Post $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
        //  Delete slug + seo
        $model->seo()->delete();
        $model->slugs()->delete();
        //  Unlink images
        $model->images()->detach();
        //  remove the category target
        $model->categories()->detach();
    }
}
