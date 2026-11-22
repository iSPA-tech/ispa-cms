<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin comment list sends its page size as `pageSize` (the "10 / page" changer); older callers sent `limit`.
 */
final class CommentListPageSizeTest extends TestCase
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
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('contacts', static function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('enumerates', static function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->string('enum_key')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // Each row reports the banned words of the comment settings (none saved here).
        Schema::create('settings', static function (Blueprint $t): void {
            $t->id();
            $t->string('setting_key')->nullable()->unique();
            $t->string('type')->default('text');
            $t->longText('value')->nullable();
            $t->text('input_setting')->nullable();
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
        Gate::before(static fn (): bool => true);
        Sanctum::actingAs(new User(['id' => 91, 'type' => UserType::WEBMASTER]));

        foreach (range(1, 25) as $n) {
            DB::table('comments')->insert(['name' => "Visitor {$n}", 'content' => 'Hello', 'status' => 'status_pending']);
        }
    }

    public function test_the_list_uses_the_page_size_the_admin_sends(): void
    {
        $this->list('pageSize=20')
            ->assertJsonPath('data.perPage', 20)
            ->assertJsonPath('data.count', 20);
    }

    public function test_the_older_limit_parameter_still_works(): void
    {
        $this->list('limit=5')->assertJsonPath('data.perPage', 5);
    }

    public function test_the_page_size_defaults_to_ten_and_is_capped_at_one_hundred(): void
    {
        $this->list('')->assertJsonPath('data.perPage', 10);
        $this->list('pageSize=100000')->assertJsonPath('data.perPage', 100);
    }

    private function list(string $query): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->getJson('/api/v2/admin/comment?status=all' . ($query !== '' ? '&' . $query : ''))
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
