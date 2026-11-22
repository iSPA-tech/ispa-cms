<?php

declare(strict_types=1);

namespace Cms\Skeleton\Tests\Feature;

use App\Models\User;
use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Admin API of things: CRUD by uuid for a user with the permissions, 403 without them. */
final class SkeletonThingAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v2/admin/skeleton/things';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public function test_an_admin_creates_reads_updates_and_deletes_skeleton_things(): void
    {
        $this->actingAsAdmin();

        $uuid = $this->postJson(self::BASE, ['name' => 'First'])
            ->assertOk()
            ->assertJsonPath('data.name', 'First')
            ->json('data.uuid');

        $this->assertStringContainsString($uuid, (string) $this->getJson(self::BASE . '?search=Fir')->assertOk()->getContent());
        $this->getJson(self::BASE . '/' . $uuid)->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->putJson(self::BASE . '/' . $uuid, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->deleteJson(self::BASE . '/' . $uuid)->assertOk();

        $this->assertSoftDeleted('skeleton_things', ['uuid' => $uuid]);
        $this->getJson(self::BASE . '/' . $uuid)->assertNotFound();
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE, ['name' => ''])->assertUnprocessable();
    }

    public function test_a_numeric_id_is_not_accepted(): void
    {
        $this->actingAsAdmin();
        $skeletonThing = SkeletonThing::query()->create(['name' => 'Kept']);

        $this->getJson(self::BASE . '/' . $skeletonThing->id)->assertNotFound();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $unknown = '00000000-0000-4000-8000-000000000000';

        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, ['name' => 'Nope'])->assertForbidden();
        // 403, not 404: the API does not tell whether a uuid exists.
        $this->getJson(self::BASE . '/' . $unknown)->assertForbidden();
        $this->deleteJson(self::BASE . '/' . $unknown)->assertForbidden();
    }

    public function test_search_matches_percent_and_underscore_literally(): void
    {
        $this->actingAsAdmin();
        foreach (['100% pure', '100 pure'] as $name) {
            SkeletonThing::query()->create(['name' => $name]);
        }

        $this->getJson(self::BASE . '?search=' . rawurlencode('100%'))->assertOk()->assertJsonPath('data.total', 1);
    }

    private function actingAsAdmin(): void
    {
        // AbstractModelPolicy lets every check pass in the local environment.
        $this->app['env'] = 'local';
        Sanctum::actingAs(User::factory()->create());
    }
}
