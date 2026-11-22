<?php

namespace Cms\Core\Tests\Feature\Bulk;

use App\Enums\StatusEnum;
use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Services\Bulk\BulkRegistry;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Services\Bulk\Handlers\GenericSoftDeleteHandler;
use Cms\Core\Services\Bulk\Handlers\GenericStatusPublishHandler;
use Cms\Core\Services\Bulk\Handlers\GenericStatusUnpublishHandler;

class DefaultBulkHandlersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        Schema::dropIfExists('bulk_samples');
        Schema::create('bulk_samples', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('bulk_samples')->insert([
            ['id' => 1, 'status' => StatusEnum::DRAFT->value, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'status' => StatusEnum::DRAFT->value, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $registry = app(BulkRegistry::class);
        $registry->register('testing::sample.publish', new GenericStatusPublishHandler(BulkSample::class));
        $registry->register('testing::sample.unpublish', new GenericStatusUnpublishHandler(BulkSample::class));
        $registry->register('testing::sample.delete', new GenericSoftDeleteHandler(BulkSample::class));

        $this->actingAs(new BulkTestUser());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bulk_samples');

        parent::tearDown();
    }

    public function test_publish_handler_updates_status(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/sample/publish', [
            'ids' => [1, 2],
        ]);

        $response->assertStatus(200);
        $this->assertSame(StatusEnum::PUBLISHED->value, DB::table('bulk_samples')->where('id', 1)->value('status'));
        $this->assertSame(StatusEnum::PUBLISHED->value, DB::table('bulk_samples')->where('id', 2)->value('status'));
    }

    public function test_unpublish_handler_updates_status(): void
    {
        DB::table('bulk_samples')->whereIn('id', [1, 2])
            ->update(['status' => StatusEnum::PUBLISHED->value]);

        $response = $this->postJson('/api/v2/admin/testing/bulk/sample/unpublish', [
            'ids' => [1, 2],
        ]);

        $response->assertStatus(200);
        $this->assertSame(StatusEnum::DRAFT->value, DB::table('bulk_samples')->where('id', 1)->value('status'));
        $this->assertSame(StatusEnum::DRAFT->value, DB::table('bulk_samples')->where('id', 2)->value('status'));
    }

    public function test_publish_dry_run_returns_preview(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/sample/publish', [
            'ids' => [1, 2],
            'dry_run' => true,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.preview'));
        $this->assertSame([1, 2], $response->json('data.requested_ids'));
        $this->assertSame([1, 2], $response->json('data.eligible_ids'));
        $this->assertSame(2, $response->json('data.affected_count'));
        $this->assertSame(StatusEnum::DRAFT->value, DB::table('bulk_samples')->where('id', 1)->value('status'));
    }

    public function test_delete_handler_soft_deletes_records(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/sample/delete', [
            'ids' => [1, 2],
        ]);

        $response->assertStatus(200);
        $this->assertNotNull(DB::table('bulk_samples')->where('id', 1)->value('deleted_at'));
        $this->assertNotNull(DB::table('bulk_samples')->where('id', 2)->value('deleted_at'));
    }

    public function test_publish_rolls_back_when_handler_throws(): void
    {
        $registry = app(BulkRegistry::class);
        $counter = 0;

        $registry->register('testing::sample.publish-fail', new GenericStatusPublishHandler(
            BulkSample::class,
            'update',
            StatusEnum::PUBLISHED->value,
            function () use (&$counter) {
                $counter++;

                if ($counter === 1) {
                    throw new \RuntimeException('fail');
                }
            }
        ));

        $response = $this->postJson('/api/v2/admin/testing/bulk/sample/publish-fail', [
            'ids' => [1, 2],
        ]);

        $response->assertStatus(400);
        $this->assertSame(StatusEnum::DRAFT->value, DB::table('bulk_samples')->where('id', 1)->value('status'));
        $this->assertSame(StatusEnum::DRAFT->value, DB::table('bulk_samples')->where('id', 2)->value('status'));
    }

    public function test_delete_rejects_models_without_soft_deletes(): void
    {
        Schema::dropIfExists('bulk_no_deletes');
        Schema::create('bulk_no_deletes', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        DB::table('bulk_no_deletes')->insert([
            ['id' => 1, 'status' => StatusEnum::DRAFT->value, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $registry = app(BulkRegistry::class);
        $registry->register('testing::sample.delete-missing', new GenericSoftDeleteHandler(BulkNoDelete::class));

        $response = $this->postJson('/api/v2/admin/testing/bulk/sample/delete-missing', [
            'ids' => [1],
        ]);

        $response->assertStatus(400);
        $this->assertSame(StatusEnum::DRAFT->value, DB::table('bulk_no_deletes')->where('id', 1)->value('status'));

        Schema::dropIfExists('bulk_no_deletes');
    }
}

class BulkSample extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'bulk_samples';
    protected $guarded = [];
}

class BulkNoDelete extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bulk_no_deletes';
    protected $guarded = [];
}

class BulkTestUser implements \Illuminate\Contracts\Auth\Authenticatable
{
    public function __construct(
        protected bool $allowed = true,
        protected int $id = 1
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function can($ability, $arguments = []): bool
    {
        return $this->allowed;
    }
}
