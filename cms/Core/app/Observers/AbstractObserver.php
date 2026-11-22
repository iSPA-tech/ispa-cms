<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base observer providing automatic audit logging for Eloquent models.
 *
 * Automatically fires AuditLogEvent for created, deleted, restored, and forceDeleted
 * lifecycle events. Child observers only need to implement modelClass() and can
 * override individual event methods to add custom side-effect logic.
 *
 * Listens to: created, deleted, restored, forceDeleted
 * Side effects: AuditLogEvent dispatch (currently commented out pending wiring)
 *
 * @see \Illuminate\Database\Eloquent\Model
 * @package Cms\Core\Observers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
abstract class AbstractObserver
{
    /**
     * Get the fully qualified class name of the observed model.
     */
    abstract protected function modelClass(): string;

    /**
     * Get the authenticated user ID for audit attribution.
     */
    protected function userId(): ?int
    {
        return auth()->id();
    }

    /**
     * Fire AuditLog event if Core module is active.
     *
     * @param string $action  The audit action name (create, delete, restore, forceDelete)
     * @param Model  $model   The model instance being observed
     * @param array  $payload Additional data to include in the audit entry
     * @param string $icon    Font Awesome icon class for the audit UI
     * @param string $status  Severity level (success, info, primary, danger)
     */
    protected function audit(string $action, Model $model, array $payload = [], string $icon = '', string $status = 'info'): void
    {
//        if (function_exists('module') && module()->has('Core') && module('Core')->isEnabled()) {
//            event(new AuditLogEvent(
//                $action,
//                $payload,
//                $this->modelClass(),
//                $model->getKey(),
//                $icon,
//                $status,
//                $this->userId()
//            ));
//        }
    }

    /**
     * Handle the model "created" event. Logs a creation audit entry.
     * @param  Model $model
     * @return void
     */
    public function created(Model $model): void
    {
        $this->audit('create', $model, $model->toArray(), 'fa fa-plus-circle', 'success');
    }

    /**
     * Handle the model "deleted" event. Logs a soft-delete audit entry.
     * @param  Model $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        $this->audit('delete', $model, [], 'far fa-trash-alt', 'danger');
    }

    /**
     * Handle the model "restored" event. Logs a restore audit entry.
     * @param  Model $model
     * @return void
     */
    public function restored(Model $model): void
    {
        $this->audit('restore', $model, [], 'far fa-trash-undo-alt', 'info');
    }

    /**
     * Handle the model "forceDeleted" event. Logs a permanent deletion audit entry.
     * @param  Model $model
     * @return void
     */
    public function forceDeleted(Model $model): void
    {
        $this->audit('forceDelete', $model, $model->toArray(), 'fa fa-minus-circle', 'danger');
    }
}
