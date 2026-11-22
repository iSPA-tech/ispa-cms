<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use App\Services\Dashboard\DashboardSummaryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract test for GET /api/v2/admin/my-tasks and its dashboard integration.
 */
final class MyTasksEndpointTest extends TestCase
{
    /**
     * Prepare schema, middleware and a test provider.
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(DashboardSummaryService::class, 'availabilityMemo'))->setValue(null, []);
        Schema::create('comments', static function (Blueprint $t): void {
            $t->id();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tracking_daily', static function (Blueprint $t): void {
            $t->id();
            foreach (['views', 'visitors', 'scans', 'download', 'sessions'] as $c) {
                $t->integer($c)->default(0);
            }
            $t->date('date')->nullable();
            $t->timestamps();
        });
        Schema::create('users', static function (Blueprint $t): void {
            $t->id();
            $t->integer('type')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('comments')->insert([['status' => null, 'created_at' => now()]]);
        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');

        app(AttentionRegistry::class)->register(
            'test_tasks',
            static fn ($u): bool => $u->id === 91,
            static fn (): int => 2,
            static fn (): array => [['title' => 'Do it', 'route' => 'x']],
            ['route' => 'tasks', 'tone' => 'warn']
        );
    }

    /**
     * Authenticate as the given user id and GET a path.
     */
    private function asUserGet(int $id, string $path): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs((new User())->forceFill(['id' => $id, 'type' => UserType::WEBMASTER]));

        return $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')->getJson($path);
    }

    /**
     * Allowed user sees the provider with items.
     */
    public function test_allowed_user_gets_items(): void
    {
        $r = $this->asUserGet(91, '/api/v2/admin/my-tasks')->assertOk();
        $r->assertJsonPath('data.total', 2);
        $r->assertJsonPath('data.items.0.key', 'test_tasks');
        $r->assertJsonPath('data.items.0.items.0.title', 'Do it');
    }

    /**
     * Disallowed user gets an empty list.
     */
    public function test_disallowed_user_gets_empty(): void
    {
        $r = $this->asUserGet(92, '/api/v2/admin/my-tasks')->assertOk();
        $r->assertJsonPath('data.total', 0);
        $r->assertJsonPath('data.items', []);
    }

    /**
     * Summary appends per-user provider entries after the cached inline items without caching them.
     */
    public function test_summary_appends_provider_per_user(): void
    {
        $a = $this->asUserGet(91, '/api/v2/admin/dashboard/summary')->assertOk()->json('data.attention');
        $keysA = array_column($a, 'key');
        self::assertSame('test_tasks', end($keysA));
        self::assertArrayNotHasKey('items', end($a));
        // The Content rows are per-user providers too; user 91 holds no post.comment permission row.
        self::assertNotContains('comments_pending', $keysA);

        $b = $this->asUserGet(92, '/api/v2/admin/dashboard/summary')->assertOk()->json('data.attention');
        self::assertNotContains('test_tasks', array_column($b, 'key'));
        self::assertSame(count($a) - 1, count($b));
    }

    /**
     * Groups come back most urgent first (danger, warning, info); an unknown tone counts as warning; ties keep order.
     */
    public function test_groups_are_ordered_by_tone(): void
    {
        $allow = static fn ($u): bool => $u->id === 91;
        $registry = app(AttentionRegistry::class);
        $registry->register('t_info', $allow, static fn (): int => 1, null, ['route' => 'a', 'tone' => 'info']);
        $registry->register('t_danger', $allow, static fn (): int => 1, null, ['route' => 'b', 'tone' => 'danger']);
        $registry->register('t_none', $allow, static fn (): int => 1, null, ['route' => 'c']);

        $keys = array_column($this->asUserGet(91, '/api/v2/admin/my-tasks')->assertOk()->json('data.items'), 'key');

        self::assertSame(['t_danger', 'test_tasks', 't_none', 't_info'], $keys);
    }
}
