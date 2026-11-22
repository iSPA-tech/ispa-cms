<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Services\Comment\CommentBannedWords;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Banned words of the comment settings: saved in the settings table, reported on every admin comment row.
 */
final class CommentBannedWordsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('comments', static function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->string('subject')->nullable();
            $t->text('content')->nullable();
            $t->string('target_type')->nullable();
            $t->unsignedBigInteger('target_id')->nullable();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('last_view')->nullable();
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
        if (! Schema::hasTable(CoreTableConstant::SETTINGS)) {
            Schema::create(CoreTableConstant::SETTINGS, static function (Blueprint $t): void {
                $t->id();
                $t->string('setting_key')->nullable()->unique();
                $t->string('type')->default('text');
                $t->string('group_name')->nullable();
                $t->string('name')->nullable();
                $t->longText('value')->nullable();
                $t->unsignedBigInteger('enumerate_id')->nullable();
                $t->unsignedBigInteger('language_id')->nullable();
                $t->text('input_setting')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
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
        DB::table('enumerates')->insert(['name' => 'Chờ duyệt', 'enum_key' => 'status_pending']);
        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        Event::fake([AuditLogEvent::class]);
        Gate::before(static fn (): bool => true);
        Sanctum::actingAs(new User(['id' => 91, 'type' => UserType::WEBMASTER]));
    }

    public function test_the_list_starts_empty_and_a_save_keeps_a_clean_list(): void
    {
        $this->api('getJson', '/api/v2/admin/comment-settings')->assertJsonPath('data.banned_words', []);

        $this->api('putJson', '/api/v2/admin/comment-settings', ['banned_words' => [' Spam ', '', 'spam', 'lừa đảo']])
            ->assertJsonPath('data.banned_words', ['Spam', 'lừa đảo']);

        $this->api('getJson', '/api/v2/admin/comment-settings')->assertJsonPath('data.banned_words', ['Spam', 'lừa đảo']);
        $row = DB::table(CoreTableConstant::SETTINGS)->where('setting_key', CommentBannedWords::SETTING_KEY)->first();
        self::assertSame('comment', $row->group_name);
        self::assertSame("Spam\nlừa đảo", $row->value);
    }

    public function test_saving_needs_a_list(): void
    {
        $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->putJson('/api/v2/admin/comment-settings', ['banned_words' => 'spam'])
            ->assertStatus(422);
    }

    public function test_list_and_detail_rows_carry_the_words_they_contain(): void
    {
        $this->api('putJson', '/api/v2/admin/comment-settings', ['banned_words' => ['lừa đảo', 'casino']]);
        $flagged = DB::table('comments')->insertGetId(['name' => 'Khách', 'content' => 'Trang này lua dao', 'status' => 'status_pending']);
        DB::table('comments')->insert(['name' => 'Minh', 'content' => 'Bài hay', 'status' => 'status_pending']);

        $rows = collect($this->api('getJson', '/api/v2/admin/comment?status=all')->json('data.data'))->keyBy('id');
        self::assertSame(['lừa đảo'], $rows[$flagged]['banned_words']);
        self::assertSame([], $rows->firstWhere('name', 'Minh')['banned_words']);

        $this->api('getJson', "/api/v2/admin/comment/{$flagged}")->assertJsonPath('data.banned_words', ['lừa đảo']);
    }

    public function test_an_admin_edits_the_text_without_resending_the_status(): void
    {
        $id = DB::table('comments')->insertGetId(['name' => 'Khách', 'content' => 'Trang này lua dao', 'status' => 'rejected']);

        $this->api('putJson', "/api/v2/admin/comment/{$id}", ['name' => 'Khách', 'content' => 'Trang này hay']);

        $row = DB::table('comments')->where('id', $id)->first();
        self::assertSame('Trang này hay', $row->content);
        self::assertSame('rejected', $row->status);
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->{$method}($uri, $data)
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
