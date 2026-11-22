<?php

namespace App\Observers;

use App\Helpers\AppHelpers;
use Cms\Ecommerce\Models\Order;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\WorkflowExecutor;

class OrderObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     * @since 1.0.0
     */

    /**
     * @param Order $model
     *
     * @return Order
     */
    private function updateDateTime(Order $model): Order
    {
        //  Generate date time by timezone

        return $model;
    }

    /**
     * Handle the Order "creating" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function creating(Order $model): void
    {
        $this->updateDateTime($model);
    }

    /**
     * Handle the Order "created" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function created(Order $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));

        $workflows = WorkflowProject::query()
            ->where('is_active', true)
            ->where('trigger_event', 'order.created')
            ->where('target_entity_type', Order::class)
            ->with(['steps', 'connections.fromStep', 'connections.toStep'])
            ->get();

        foreach ($workflows as $workflow) {
            (new WorkflowExecutor($workflow, $model))->run();
        }
    }

    /**
     * Handle the Order "updating" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function updating(Order $model): void
    {
        $this->updateDateTime($model);
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Order "updated" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function updated(Order $model): void
    {
        //
    }

    /**
     * Handle the Order "deleting" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function deleting(Order $model): void
    {

    }

    /**
     * Handle the Order "deleted" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function deleted(Order $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Order "restoring" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function restoring(Order $model): void
    {

    }

    /**
     * Handle the Order "restored" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function restored(Order $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Order "force deleted" event.
     *
     * @param Order $model
     *
     * @return void
     */
    public function forceDeleted(Order $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
