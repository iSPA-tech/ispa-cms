<?php

namespace App\Models;

use App\Constants\Tables;
use Cms\Core\Models\AbstractBaseModel;
use Cms\GeoPlaces\Models\Place as GeoPlace;

/**
 * Backward-compatible alias for the GeoPlaces-owned Place model.
 *
 * Legacy code and stored morph references still use `App\Models\Place`.
 * When the GeoPlaces model is available, this file stays a direct alias.
 */
if (class_exists(GeoPlace::class)) {
    class_alias(GeoPlace::class, __NAMESPACE__ . '\\Place');
} else {
    /**
     * Fallback compatibility shim.
     *
     * In very limited bootstrap windows where module classes are not yet
     * autoloaded, this shim keeps legacy app references valid and avoids
     * hard failures while module boot can be recovered.
     */
    class Place extends AbstractBaseModel
    {
        public $table = Tables::PLACES;
    }
}
