<?php

namespace App\Policies;

use App\Models\Scheduler;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SchedulerPolicy
{
    use HandlesAuthorization;

    /**
     * Allow local development access
     *
     * @param Closure $user
     *
     * @return bool|void
     *
     * @author malayvuong
     * @since 1.0.0
     **/
    public function before($user, $ability)
    {
        if (app()->environment('local')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @param User $user
     *
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('scheduler.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user
     * @param Scheduler $model
     *
     * @return bool
     */
    public function view(User $user, Scheduler $model): bool
    {
        return $user->hasRole('scheduler.view') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user
     *
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasRole('scheduler.create');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user
     * @param Scheduler $model
     *
     * @return bool
     */
    public function update(User $user, Scheduler $model): bool
    {
        return $user->hasRole('scheduler.update') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user
     * @param Scheduler $model
     *
     * @return bool
     */
    public function delete(User $user, Scheduler $model): bool
    {
        return $user->hasRole('scheduler.delete') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param User $user
     * @param Scheduler $model
     *
     * @return bool
     */
    public function restore(User $user, Scheduler $model): bool
    {
        return $user->hasRole('scheduler.restore') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param User $user
     * @param Scheduler $model
     *
     * @return bool
     */
    public function forceDelete(User $user, Scheduler $model): bool
    {
        return $user->hasRole('scheduler.forceDelete') OR $user->id === $model->users_id;
    }
}
