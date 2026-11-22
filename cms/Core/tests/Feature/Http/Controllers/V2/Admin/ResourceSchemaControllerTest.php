<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Services\DataScope\DataScope;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Tests\Fixtures\DataScope\ScopeFixtureRecord;
use Cms\Core\Tests\Fixtures\Resources\OrganizationsFixtureResource;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for the resource schema endpoint.
 */
class ResourceSchemaControllerTest extends TestCase
{
    private const URL = 'https://localhost/api/v2/admin/resources/%s/schema';

    /** Register fixtures under an enabled and a disabled module key and sign in. */
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->integer('type')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });
        $p = 'api/v2/admin/';
        Route::get($p . 'helpdesk/organizations', fn () => null)->name('helpdesk.organizations.index');
        Route::post($p . 'helpdesk/organizations', fn () => null)->name('helpdesk.organizations.store');
        Route::get($p . 'helpdesk/organizations/{uuid}', fn () => null)->name('helpdesk.organizations.show');
        Route::post($p . 'helpdesk/organizations/{uuid}/status', fn () => null)->name('helpdesk.organizations.status');
        Route::post($p . 'helpdesk/organizations/{uuid}/invite', fn () => null)->name('helpdesk.organizations.invite');
        app('router')->getRoutes()->refreshNameLookups();
        $registry = app(ResourceRegistry::class);
        $registry->register('core/schema-fixture', OrganizationsFixtureResource::class);
        $registry->register('gamecore/schema-fixture', OrganizationsFixtureResource::class);
    }

    /**
     * Create and sign in a non-webmaster admin; the view ability comes from the given permission keys.
     *
     * @param  array<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        $user = User::query()->create(['name' => 'User', 'email' => 'u' . uniqid() . '@example.test', 'password' => 'secret', 'type' => UserType::ADMIN]);
        $actor = new class($permissions) extends User {
            /** @param array<string> $keys */
            public function __construct(private array $keys = [])
            {
                parent::__construct();
            }

            public function permissionKeys(): array
            {
                return $this->keys;
            }

            public function loadMissing($relations)
            {
                return $this;
            }

            public function isWebmaster(): bool
            {
                return false;
            }
        };
        $actor->setRawAttributes($user->getAttributes(), true);
        $actor->exists = true;
        Sanctum::actingAs($actor);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(sprintf(self::URL, 'core/schema-fixture'))->assertStatus(401);
    }

    public function test_unknown_resource_returns_404(): void
    {
        $this->signIn(['helpdesk.organizations.view']);
        $this->getJson(sprintf(self::URL, 'core/nope'))->assertStatus(404);
    }

    public function test_disabled_module_returns_404(): void
    {
        $this->signIn(['helpdesk.organizations.view']);
        $this->getJson(sprintf(self::URL, 'gamecore/schema-fixture'))->assertStatus(404);
    }

    public function test_user_without_ability_gets_403(): void
    {
        $this->signIn([]);
        $this->getJson(sprintf(self::URL, 'core/schema-fixture'))->assertStatus(403);
    }

    public function test_returns_schema_with_cache_headers(): void
    {
        $this->signIn(['helpdesk.organizations.view']);
        $response = $this->getJson(sprintf(self::URL, 'core/schema-fixture'));

        $response->assertOk()
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.resource', 'helpdesk/organizations');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', $response->headers->get('ETag'));
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-cache', $cache);
    }

    public function test_matching_etag_returns_304(): void
    {
        $this->signIn(['helpdesk.organizations.view']);
        $etag = $this->getJson(sprintf(self::URL, 'core/schema-fixture'))->headers->get('ETag');

        $response = $this->getJson(sprintf(self::URL, 'core/schema-fixture'), ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame($etag, $response->headers->get('ETag'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('', $response->getContent());
    }

    /** Register a resource linked to the scoped resource `fixture/records` (hides `owner_user_id` unless `fixture.secret`). */
    private function registerScopedFixture(): void
    {
        $scoped = new ScopedResourceRegistry();
        $scoped->register(
            ScopedResource::make('fixture/records', ScopeFixtureRecord::class, 'fixture.view')
                ->hideUnless('owner_user_id', 'fixture.secret')
        );
        app()->instance(ScopedResourceRegistry::class, $scoped);
        app()->forgetInstance(DataScope::class);
        app(ResourceRegistry::class)->register('core/scoped-fixture', ScopedVisibilityControllerResource::class);
    }

    /** @return array<int, string> column field names of a schema response */
    private function columns(\Illuminate\Testing\TestResponse $response): array
    {
        return array_column($response->json('data.list.columns'), 'field');
    }

    public function test_schema_hides_a_field_the_data_scope_hides_and_etags_differ(): void
    {
        $this->registerScopedFixture();

        $this->signIn(['fixture.view']);
        $plain = $this->getJson(sprintf(self::URL, 'core/scoped-fixture'));
        $plain->assertOk();

        $this->signIn(['fixture.view', 'fixture.secret']);
        $holder = $this->getJson(sprintf(self::URL, 'core/scoped-fixture'));
        $holder->assertOk();

        $this->assertSame(['title'], $this->columns($plain));
        $this->assertSame(['title', 'owner_user_id'], $this->columns($holder));
        $this->assertNotSame($plain->headers->get('ETag'), $holder->headers->get('ETag'));
    }
}

/**
 * Resource linked to the scoped resource `fixture/records` for the schema endpoint tests.
 */
class ScopedVisibilityControllerResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'fixture/records';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'fixture.view';
    }

    /** Data scope key whose field visibility applies. */
    public function scopedResource(): ?string
    {
        return 'fixture/records';
    }

    /** Resource fields. */
    public function fields(): array
    {
        return [Field::text('title'), Field::text('owner_user_id')];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()->columns(['title', 'owner_user_id'])->endpoint('helpdesk.organizations.index');
    }
}
