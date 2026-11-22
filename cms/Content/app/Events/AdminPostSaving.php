<?php

declare(strict_types=1);

namespace Cms\Content\Events;

use Cms\Content\Models\Post;

/**
 * Dispatched before an admin post save.
 *
 * @api Content public API; signature changes follow the module notes.
 */
final class AdminPostSaving
{
    public function __construct(public readonly Post $post) {}
}
