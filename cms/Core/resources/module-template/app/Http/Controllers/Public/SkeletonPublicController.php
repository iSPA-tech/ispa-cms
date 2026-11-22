<?php

declare(strict_types=1);

namespace Cms\Skeleton\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Cms\Skeleton\Enums\SkeletonThingStatus;
use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Contracts\View\View;

/** Public list and detail of published things, inside the active project's layout. */
final class SkeletonPublicController extends Controller
{
    public function index(): View
    {
        $skeletonThings = SkeletonThing::query()
            ->where('status', SkeletonThingStatus::Published)
            ->latest('id')
            ->paginate(12);

        return view('skeleton::public.index', ['layout' => $this->layout(), 'skeletonThings' => $skeletonThings]);
    }

    public function show(string $uuid): View
    {
        $skeletonThing = SkeletonThing::query()
            ->where('uuid', $uuid)
            ->where('status', SkeletonThingStatus::Published)
            ->firstOrFail();

        return view('skeleton::public.show', ['layout' => $this->layout(), 'skeletonThing' => $skeletonThing]);
    }

    /** The project layout (`<folder>::layout.index`); config skeleton.public.layout replaces it. */
    private function layout(): string
    {
        return (string) (config('skeleton.public.layout') ?: config('ispa-cms.folder') . 'layout.index');
    }
}
