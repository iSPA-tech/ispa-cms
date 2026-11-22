<?php

namespace Cms\Core\Tests\Feature\Bulk;

use Illuminate\Contracts\Auth\Authenticatable;
use Cms\Core\Tests\TestCase;
use Cms\Core\Services\Bulk\BulkResult;
use Cms\Core\Services\Bulk\BulkRegistry;
use Cms\Core\Services\Bulk\BulkActionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\ApiAdminVerify;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Middleware\ApiAdminVerifyMiddleware;

class BulkControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $registry = app(BulkRegistry::class);
        $registry->register('testing::items.publish', new AllowBulkHandler());
        $registry->register('testing::items.reject', new DenyBulkHandler());
        $registry->register('testing::items.preview', new PreviewBulkHandler());
    }

    public function test_returns_not_found_when_handler_missing(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/items/missing', [
            'ids' => [1],
        ]);

        $response->assertStatus(404);
        $this->assertSame('error', $response->json('status'));
    }

    public function test_validation_errors_when_ids_missing(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/items/publish', []);

        $response->assertStatus(422);
        $this->assertSame('error', $response->json('status'));
    }

    public function test_validation_errors_when_ids_exceed_limit(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/items/publish', [
            'ids' => range(1, 21),
        ]);

        $response->assertStatus(422);
        $this->assertSame('error', $response->json('status'));
    }

    public function test_authorization_failure_returns_403(): void
    {
        $response = $this->postJson('/api/v2/admin/testing/bulk/items/reject', [
            'ids' => [1, 2],
        ]);

        $response->assertStatus(403);
        $this->assertSame('error', $response->json('status'));
    }

    public function test_dry_run_returns_preview_data_and_does_not_mutate_db(): void
    {
        Schema::dropIfExists('bulk_items');
        Schema::create('bulk_items', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('draft');
        });

        DB::table('bulk_items')->insert([
            ['id' => 1, 'status' => 'draft'],
            ['id' => 2, 'status' => 'draft'],
        ]);

        PreviewBulkHandler::$lastDryRun = false;

        $response = $this->postJson('/api/v2/admin/testing/bulk/items/preview', [
            'ids' => [1, 2, 3],
            'dry_run' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame('success', $response->json('status'));
        $this->assertTrue($response->json('data.preview'));
        $this->assertSame([1, 2, 3], $response->json('data.requested_ids'));
        $this->assertSame([1, 2], $response->json('data.eligible_ids'));
        $this->assertSame(2, $response->json('data.affected_count'));
        $this->assertTrue(PreviewBulkHandler::$lastDryRun);
        $this->assertSame('draft', DB::table('bulk_items')->where('id', 1)->value('status'));
        $this->assertSame('draft', DB::table('bulk_items')->where('id', 2)->value('status'));

        Schema::dropIfExists('bulk_items');
    }

    public function test_rate_limiting_triggers_after_threshold(): void
    {
        $this->withMiddleware();
        $this->withoutMiddleware([
            Authenticate::class,
            ApiAdminVerify::class,
            ApiAdminVerifyMiddleware::class,
            HttpsProtocol::class,
            Localization::class,
            ApiLocalization::class,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/v2/admin/testing/bulk/items/publish', [
                'ids' => [1],
            ]);

            $response->assertStatus(200);
        }

        $response = $this->postJson('/api/v2/admin/testing/bulk/items/publish', [
            'ids' => [1],
        ]);

        $response->assertStatus(429);
        $this->assertSame('error', $response->json('status'));
    }
}

class AllowBulkHandler implements BulkActionHandler
{
    public function rules(): array
    {
        return [];
    }

    public function authorize(?Authenticatable $user, array $payload): void
    {
    }

    public function handle(array $payload, bool $dryRun): BulkResult|array
    {
        return new BulkResult(true, count($payload['ids']), $payload['ids']);
    }
}

class DenyBulkHandler implements BulkActionHandler
{
    public function rules(): array
    {
        return [];
    }

    public function authorize(?Authenticatable $user, array $payload): void
    {
        throw new AuthorizationException('Forbidden');
    }

    public function handle(array $payload, bool $dryRun): BulkResult|array
    {
        return new BulkResult(false, 0, $payload['ids']);
    }
}

class PreviewBulkHandler implements BulkActionHandler
{
    public static bool $lastDryRun = false;

    public function rules(): array
    {
        return [];
    }

    public function authorize(?Authenticatable $user, array $payload): void
    {
    }

    public function handle(array $payload, bool $dryRun): BulkResult|array
    {
        self::$lastDryRun = $dryRun;
        $eligible = DB::table('bulk_items')->whereIn('id', $payload['ids'])->pluck('id')->all();

        if (!$dryRun) {
            DB::table('bulk_items')->whereIn('id', $eligible)->update(['status' => 'published']);
        }

        return [
            'ids' => $eligible,
            'eligible_ids' => $eligible,
            'affected' => count($eligible),
            'affected_count' => count($eligible),
        ];
    }
}
