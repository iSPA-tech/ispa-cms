<?php

namespace App\Policies;

use App\Models\Place;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

if (class_exists(\Cms\GeoPlaces\Policies\PlacePolicy::class)) {
    /**
     * Backward-compatible alias for the GeoPlaces-owned Place policy.
     */
    class PlacePolicy extends \Cms\GeoPlaces\Policies\PlacePolicy
    {
    }
} else {
    /**
     * Fallback permission rules when GeoPlaces module is unavailable.
     *
     * This preserves existing place policy behavior with the legacy app model type.
     */
    class PlacePolicy
    {
        use HandlesAuthorization;

        /**
         * Grant full access in local environment.
         *
         * @param mixed $user
         * @param string $ability
         *
         * @return bool|null
         */
        public function before($user, $ability)
        {
            if (app()->environment('local')) {
                return true;
            }
        }

        /**
         * Determine whether user can list places.
         *
         * @param User $user
         *
         * @return bool
         */
        public function viewAny(User $user): bool
        {
            return $user->hasRole('place.viewAny');
        }

        /**
         * Determine whether user can view a place.
         *
         * @param User $user
         * @param Place $model
         *
         * @return bool
         */
        public function view(User $user, Place $model): bool
        {
            return $user->hasRole('place.view') || $user->id === $model->user_id;
        }

        /**
         * Determine whether user can create places.
         *
         * @param User $user
         *
         * @return bool
         */
        public function create(User $user): bool
        {
            return $user->hasRole('place.create');
        }

        /**
         * Determine whether user can edit a place.
         *
         * @param User $user
         * @param Place $model
         *
         * @return bool
         */
        public function edit(User $user, Place $model): bool
        {
            return $user->hasRole('place.edit') || $user->id === $model->user_id;
        }

        /**
         * Determine whether user can update a place.
         *
         * @param User $user
         * @param Place $model
         *
         * @return bool
         */
        public function update(User $user, Place $model): bool
        {
            return $user->hasRole('place.update') || $user->id === $model->user_id;
        }

        /**
         * Determine whether user can delete a place.
         *
         * @param User $user
         * @param Place $model
         *
         * @return bool
         */
        public function delete(User $user, Place $model): bool
        {
            return $user->hasRole('place.delete') || $user->id === $model->user_id;
        }

        /**
         * Determine whether user can restore a place.
         *
         * @param User $user
         * @param Place $model
         *
         * @return bool
         */
        public function restore(User $user, Place $model): bool
        {
            return $user->hasRole('place.restore') || $user->id === $model->user_id;
        }

        /**
         * Determine whether user can permanently delete a place.
         *
         * @param User $user
         * @param Place $model
         *
         * @return bool
         */
        public function forceDelete(User $user, Place $model): bool
        {
            return $user->hasRole('place.forceDelete') || $user->id === $model->user_id;
        }
    }
}
