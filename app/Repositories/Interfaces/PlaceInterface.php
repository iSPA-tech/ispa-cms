<?php

namespace App\Repositories\Interfaces;

if (interface_exists(\Cms\GeoPlaces\Repositories\Interfaces\PlaceInterface::class)) {
    /**
     * Backward-compatible alias to the GeoPlaces-owned repository contract.
     */
    interface PlaceInterface extends \Cms\GeoPlaces\Repositories\Interfaces\PlaceInterface
    {
    }
} else {
    /**
     * Fallback repository contract when GeoPlaces module is unavailable.
     */
    interface PlaceInterface extends \Cms\Core\Repositories\Interfaces\RepositoryInterface
    {
    }
}
