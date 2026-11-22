<?php

declare(strict_types=1);

namespace Cms\Skeleton\Tests\Feature;

use App\Http\Middleware\HttpsProtocol;
use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Public list and detail show published things only. */
final class SkeletonPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HttpsProtocol::class);
        // A plain layout: the project layout needs the built theme assets.
        config(['skeleton.public.layout' => 'errors.layout']);
    }

    public function test_published_skeleton_things_are_listed_and_shown(): void
    {
        $published = SkeletonThing::query()->create(['name' => 'Visible', 'status' => 'published']);
        SkeletonThing::query()->create(['name' => 'Hidden', 'status' => 'draft']);

        $this->get('/skeleton')->assertOk()->assertSee('Visible')->assertDontSee('Hidden');
        $this->get('/skeleton/' . $published->uuid)->assertOk()->assertSee('Visible');
    }

    public function test_a_draft_is_not_found(): void
    {
        $draft = SkeletonThing::query()->create(['name' => 'Hidden', 'status' => 'draft']);

        $this->get('/skeleton/' . $draft->uuid)->assertNotFound();
    }
}
