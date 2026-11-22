<?php

declare(strict_types=1);

namespace Cms\Content\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admin submitted a crawled article to be saved as a post (`posts/save-crawl-data`). The project or module that
 * owns the crawl and translation pipeline listens and does the work; Content only checks for duplicates.
 *
 * @api Content public API; signature changes follow the module notes.
 */
final class PostCrawlDataSubmitted
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $data The submitted request payload.
     */
    public function __construct(public readonly array $data)
    {
    }
}
