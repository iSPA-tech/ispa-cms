<?php

namespace App\Repositories\Eloquent;

use App\Models\Place;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Repositories\Interfaces\PlaceInterface;

if (class_exists(\Cms\GeoPlaces\Repositories\Eloquent\PlaceRepository::class)) {
    /**
     * Backward-compatible alias for the GeoPlaces-owned Place repository.
     */
    class PlaceRepository extends \Cms\GeoPlaces\Repositories\Eloquent\PlaceRepository implements PlaceInterface
    {
    }
} else {
    /**
     * Fallback repository when GeoPlaces module is unavailable.
     */
    class PlaceRepository extends RepositoriesAbstract implements PlaceInterface
    {
        /**
         * Bind fallback model to keep container wiring bootable.
         */
        public function __construct()
        {
            $this->setModel(Place::class);
        }

        /**
         * Fallback implementation for non-GeoPlaces installs.
         *
         * @param string $slug
         * @param bool $freshCache
         *
         * @return mixed
         */
        public function getBySlug(string $slug, bool $freshCache = false): mixed
        {
            return $this->model()->newQuery()->where('slug', $slug)->first();
        }
    }
}
