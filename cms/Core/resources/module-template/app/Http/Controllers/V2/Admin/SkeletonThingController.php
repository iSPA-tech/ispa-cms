<?php

declare(strict_types=1);

namespace Cms\Skeleton\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
// @mode full
use Cms\Skeleton\Data\SkeletonThingData;
// @endmode
use Cms\Skeleton\Http\Requests\V2\Admin\SkeletonThingRequest;
use Cms\Skeleton\Http\Resources\V2\Admin\SkeletonThingCollection;
use Cms\Skeleton\Http\Resources\V2\Admin\SkeletonThingResource;
use Cms\Skeleton\Models\SkeletonThing;
// @mode normal,full
use Cms\Skeleton\Services\SkeletonThingService;
// @endmode
use Illuminate\Http\Request;

/**
 * Admin CRUD of things, UUID-first: /api/v2/admin/skeleton/things. Every action checks SkeletonThingPolicy; a user
 * without viewAny is refused before a uuid is looked up, so the API does not reveal which uuids exist.
 */
final class SkeletonThingController extends Controller
{
    // @mode normal,full
    public function __construct(private readonly SkeletonThingService $service)
    {
    }

    // @endmode
    public function index(Request $request): mixed
    {
        if (! user()?->can('viewAny', SkeletonThing::class)) {
            return $this->forbidden();
        }
        $perPage = min(max((int) $request->input('pageSize', 20), 1), 100);
        $search = trim((string) $request->input('search', ''));
        // @mode lite
        $page = SkeletonThing::query()
            ->when($search !== '', static fn ($query) => $query->nameContains($search))
            ->latest('id')
            ->paginate($perPage);
        // @endmode
        // @mode normal,full
        $page = $this->service->paginate(['search' => $search], $perPage);
        // @endmode

        return ires()->data(new SkeletonThingCollection($page));
    }

    public function show(string $uuid): mixed
    {
        if (! user()?->can('viewAny', SkeletonThing::class)) {
            return $this->forbidden();
        }
        $skeletonThing = SkeletonThing::query()->where('uuid', $uuid)->firstOrFail();
        if (! user()?->can('view', $skeletonThing)) {
            return $this->forbidden();
        }

        return ires()->data(new SkeletonThingResource($skeletonThing));
    }

    public function store(SkeletonThingRequest $request): mixed
    {
        if (! user()?->can('create', SkeletonThing::class)) {
            return $this->forbidden();
        }
        // @mode lite
        $skeletonThing = SkeletonThing::query()->create($request->validated());
        // @endmode
        // @mode normal
        $skeletonThing = $this->service->create($request->validated());
        // @endmode
        // @mode full
        $skeletonThing = $this->service->create(SkeletonThingData::fromArray($request->validated())->toArray());
        // @endmode

        return ires()->message(__('mgs.ok.create'))->data(new SkeletonThingResource($skeletonThing));
    }

    public function update(SkeletonThingRequest $request, string $uuid): mixed
    {
        if (! user()?->can('viewAny', SkeletonThing::class)) {
            return $this->forbidden();
        }
        $skeletonThing = SkeletonThing::query()->where('uuid', $uuid)->firstOrFail();
        if (! user()?->can('update', $skeletonThing)) {
            return $this->forbidden();
        }
        // @mode lite
        $skeletonThing->fill($request->validated())->save();
        // @endmode
        // @mode normal
        $skeletonThing = $this->service->update($skeletonThing, $request->validated());
        // @endmode
        // @mode full
        $skeletonThing = $this->service->update($skeletonThing, SkeletonThingData::fromArray($request->validated())->toArray());
        // @endmode

        return ires()->message(__('mgs.ok.update'))->data(new SkeletonThingResource($skeletonThing));
    }

    public function destroy(string $uuid): mixed
    {
        if (! user()?->can('viewAny', SkeletonThing::class)) {
            return $this->forbidden();
        }
        $skeletonThing = SkeletonThing::query()->where('uuid', $uuid)->firstOrFail();
        if (! user()?->can('delete', $skeletonThing)) {
            return $this->forbidden();
        }
        // @mode lite
        $skeletonThing->delete();
        // @endmode
        // @mode normal,full
        $this->service->delete($skeletonThing);
        // @endmode

        return ires()->message(__('mgs.ok.delete'));
    }

    private function forbidden(): mixed
    {
        return ires()->forbidden(__('mgs.no.permission'));
    }
}
