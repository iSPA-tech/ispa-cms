<?php
/**********************************************************************************************************************
 * Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                       *
 **********************************************************************************************************************/

namespace Cms\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every menu write once the server-side caches for the group have
 * been dropped. Projects listen to push the invalidation further out — e.g.
 * A project pings its separate frontend so the site re-renders immediately instead
 * of waiting out the route revalidate window.
 */
class PublicMenuInvalidated
{
    use Dispatchable;

    public function __construct(
        public readonly string $groupKey,
        public readonly int|string|null $languageId = null,
    ) {
    }
}
