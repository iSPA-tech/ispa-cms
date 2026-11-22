<?php

declare(strict_types=1);

namespace Cms\Skeleton\Services;

// @mode full
use Cms\Skeleton\Events\SkeletonThingSaved;
// @endmode
use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Business rules of things; controllers, commands and jobs go through here. */
final class SkeletonThingService
{
    /** @param array{search?: string} $filters */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return SkeletonThing::query()
            ->when($search !== '', static fn ($query) => $query->nameContains($search))
            ->latest('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data validated attributes */
    public function create(array $data): SkeletonThing
    {
        $skeletonThing = SkeletonThing::query()->create($data);
        // @mode full
        SkeletonThingSaved::dispatch($skeletonThing);
        // @endmode

        return $skeletonThing;
    }

    /** @param array<string, mixed> $data validated attributes; only the keys sent change */
    public function update(SkeletonThing $skeletonThing, array $data): SkeletonThing
    {
        $skeletonThing->fill($data)->save();
        // @mode full
        SkeletonThingSaved::dispatch($skeletonThing);
        // @endmode

        return $skeletonThing;
    }

    /** Soft delete: the row stays in the trash. */
    public function delete(SkeletonThing $skeletonThing): void
    {
        $skeletonThing->delete();
    }
}
