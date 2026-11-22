<?php

declare(strict_types=1);

namespace Cms\Content\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A post became published (its status changed to published in an update). Modules and projects listen to run their
 * own follow-up work, for example a content pipeline that fills in a missing thumbnail.
 *
 * @api Content public API; signature changes follow the module notes.
 */
final class PostPublished
{
    use Dispatchable;

    public function __construct(public readonly int $postId)
    {
    }
}
