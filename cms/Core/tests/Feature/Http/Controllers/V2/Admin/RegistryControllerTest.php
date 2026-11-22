<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Tests\Fixtures\Resources\OrganizationsFixtureResource;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Nwidart\Modules\Facades\Module;

/**
 * Feature tests for the admin registry endpoint.
 */
class RegistryControllerTest extends TestCase
{
    private const URL = 'https://localhost/api/v2/admin/registry';

    /** Create the users table and register a fixture resource. */
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
        Schema::create('translates', function (Blueprint $table) {
            $table->id();
            $table->string('group_name')->nullable();
            $table->string('name')->nullable();
            $table->boolean('for_adm')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        $registry = app(ResourceRegistry::class);
        if (!$registry->has('helpdesk/organizations')) {
            $registry->register('helpdesk/organizations', OrganizationsFixtureResource::class);
        }
    }

    /**
     * Sign in a non-webmaster admin holding the given permission keys.
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

    /**
     * Build a fake module object exposing the nwidart methods the controller uses.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function fakeModule(string $name, bool $enabled, array $manifest): object
    {
        return new class($name, $enabled, $manifest) {
            /** @param array<string, mixed> $manifest */
            public function __construct(private string $name, private bool $enabled, private array $manifest)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function get(string $key, $default = null)
            {
                return $this->manifest[$key] ?? $default;
            }
        };
    }

    /** @param array<int, object> $modules */
    private function fakeModules(array $modules): void
    {
        Module::shouldReceive('all')->andReturn($modules);
    }

    /** @return array<string, mixed> */
    private function entry(array $override = []): array
    {
        return array_merge([
            'name' => 'helpdesk-organizations',
            'text' => 'nav.a.helpdesk-organizations',
            'icon' => 'domain',
            'rule' => 'helpdesk.agent',
            'resource' => 'helpdesk/organizations',
        ], $override);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_returns_shape_for_enabled_module(): void
    {
        $this->fakeModules([$this->fakeModule('Helpdesk', true, [
            'alias' => 'helpdesk',
            'version' => '1.4.0',
            'admin' => ['navigation' => [$this->entry(['secret' => 'x'])]],
        ])]);
        $this->signIn(['helpdesk.agent']);

        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'schema_version' => 1,
            'modules' => [[
                'name' => 'Helpdesk',
                'key' => 'helpdesk',
                'label' => 'Helpdesk',
                'version' => '1.4.0',
                'navigation' => [$this->entry()],
                'summary' => ['tasks' => [], 'tiles' => []],
            ]],
        ]);
    }

    public function test_inactive_module_and_modules_without_navigation_are_omitted(): void
    {
        $this->fakeModules([
            $this->fakeModule('Helpdesk', false, ['admin' => ['navigation' => [$this->entry()]]]),
            $this->fakeModule('Plain', true, []),
        ]);
        $this->signIn(['helpdesk.agent']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('modules', []);
    }

    public function test_entries_are_filtered_by_permission_rule(): void
    {
        $this->fakeModules([$this->fakeModule('Helpdesk', true, [
            'admin' => ['navigation' => [
                $this->entry(),
                $this->entry(['name' => 'open', 'rule' => null, 'resource' => null]),
            ]],
        ])]);

        $this->signIn(['helpdesk.agent']);
        $with = $this->getJson(self::URL)->assertOk();
        $this->assertSame(['helpdesk-organizations', 'open'], array_column($with->json('modules.0.navigation'), 'name'));
        $this->assertSame('helpdesk', $with->json('modules.0.key'));
        $this->assertNull($with->json('modules.0.version'));

        $this->signIn([]);
        $without = $this->getJson(self::URL)->assertOk();
        $this->assertSame(['open'], array_column($without->json('modules.0.navigation'), 'name'));
    }

    public function test_unknown_resource_is_dropped_and_logged(): void
    {
        Log::spy();
        $this->fakeModules([$this->fakeModule('Helpdesk', true, [
            'admin' => ['navigation' => [$this->entry(['resource' => 'helpdesk/ghost'])]],
        ])]);
        $this->signIn(['helpdesk.agent']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('modules', []);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_label_is_the_nav_key_when_the_admin_translation_exists(): void
    {
        \DB::table('translates')->insert(['group_name' => 'nav', 'name' => 'nav.a.sample-directory', 'for_adm' => true]);
        \DB::table('translates')->insert(['group_name' => 'nav', 'name' => 'nav.a.helpdesk', 'for_adm' => false]);
        $this->fakeModules([
            $this->fakeModule('SampleDirectory', true, ['displayName' => 'Sample', 'admin' => ['navigation' => [$this->entry()]]]),
            $this->fakeModule('Helpdesk', true, ['displayName' => 'Help desk', 'admin' => ['navigation' => [$this->entry()]]]),
        ]);
        $this->signIn(['helpdesk.agent']);

        $response = $this->getJson(self::URL)->assertOk();
        $this->assertSame('nav.a.sample-directory', $response->json('modules.0.label'));
        // A row that is not an admin row does not count.
        $this->assertSame('Help desk', $response->json('modules.1.label'));
    }

    public function test_label_is_the_manifest_display_name_when_the_key_is_absent(): void
    {
        $this->fakeModules([$this->fakeModule('Helpdesk', true, [
            'displayName' => 'Help desk',
            'admin' => ['navigation' => [$this->entry()]],
        ])]);
        $this->signIn(['helpdesk.agent']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('modules.0.label', 'Help desk');
    }

    public function test_label_is_the_module_name_when_nothing_else_exists(): void
    {
        $this->fakeModules([$this->fakeModule('Helpdesk', true, ['admin' => ['navigation' => [$this->entry()]]])]);
        $this->signIn(['helpdesk.agent']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('modules.0.label', 'Helpdesk');
    }
}
