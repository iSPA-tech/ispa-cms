<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\UserType;
use App\Enums\StatusEnum;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Approving, deleting or restoring a comment drops the site's cached list of published comments for its post.
 */
final class CommentConfirmCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('comments', static function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->text('content')->nullable();
            $t->string('target_type')->nullable();
            $t->unsignedBigInteger('target_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // Comment eager-loads its main image; empty media tables keep that query valid.
        Schema::create('uploads', static function (Blueprint $t): void {
            $t->id();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('upload_target_pivot', static function (Blueprint $t): void {
            $t->unsignedBigInteger('upload_id');
            $t->unsignedBigInteger('target_id');
            $t->string('target_type');
            $t->string('category')->nullable();
            $t->boolean('main')->default(false);
            $t->integer('order')->default(0);
        });
        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        Event::fake([AuditLogEvent::class]);
        Gate::before(static fn (): bool => true);
        Sanctum::actingAs(new User(['id' => 91, 'type' => UserType::WEBMASTER]));
    }

    public function test_confirm_publishes_the_comment_and_clears_the_site_comment_cache(): void
    {
        $id = $this->comment(StatusEnum::PENDING->value);
        $siteKey = $this->cacheSiteList();

        $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->putJson("/api/v2/admin/comment/{$id}/confirm")
            ->assertOk();

        self::assertSame(StatusEnum::PUBLISHED->value, DB::table('comments')->where('id', $id)->value('status'));
        self::assertFalse(cache()->has($siteKey));
    }

    public function test_deleting_a_published_comment_clears_the_site_comment_cache(): void
    {
        $id = $this->comment(StatusEnum::PUBLISHED->value);
        $siteKey = $this->cacheSiteList();

        $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->deleteJson("/api/v2/admin/comment/{$id}")
            ->assertOk();

        self::assertNotNull(DB::table('comments')->where('id', $id)->value('deleted_at'));
        self::assertFalse(cache()->has($siteKey));
    }

    public function test_restoring_a_comment_clears_the_site_comment_cache(): void
    {
        $id = $this->comment(StatusEnum::PUBLISHED->value, deleted: true);
        $siteKey = $this->cacheSiteList();

        $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->putJson("/api/v2/admin/comment/{$id}/restore")
            ->assertOk();

        self::assertNull(DB::table('comments')->where('id', $id)->value('deleted_at'));
        self::assertFalse(cache()->has($siteKey));
    }

    /**
     * Insert a comment on post 162 as the public form stores it.
     */
    private function comment(string $status, bool $deleted = false): int
    {
        return DB::table('comments')->insertGetId([
            'name' => 'Visitor',
            'content' => 'Hello',
            'target_type' => Post::class,
            'target_id' => 162,
            'status' => $status,
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    /**
     * Cache a stale published list for post 162 under the key CommentForm / CommentRating read (ids are ints there).
     */
    private function cacheSiteList(): string
    {
        $siteKey = make_key(['target_id' => 162, 'target_type' => Post::class, 'reply_to' => null, 'status' => StatusEnum::PUBLISHED->value]);
        cache()->put($siteKey, 'stale published list', 3600);

        return $siteKey;
    }
}
