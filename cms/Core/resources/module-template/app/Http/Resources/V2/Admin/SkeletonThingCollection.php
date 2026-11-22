<?php

declare(strict_types=1);

namespace Cms\Skeleton\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/** A page of things with the pagination numbers the admin table reads (Core CommentCollection shape). */
final class SkeletonThingCollection extends ResourceCollection
{
    public $collects = SkeletonThingResource::class;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'total' => $this->resource->total(),
            'count' => $this->resource->count(),
            'perPage' => $this->resource->perPage(),
            'currentPage' => $this->resource->currentPage(),
            'totalPages' => $this->resource->lastPage(),
        ];
    }
}
