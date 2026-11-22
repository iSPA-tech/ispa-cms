<?php

namespace Cms\Core\Tests\Feature;

use Cms\Core\Tests\Support\AppendedRelationSchema;
use App\Enums\StatusEnum;
use Cms\Core\Models\Category;
use Cms\Core\Repositories\CategoryRepository;
use Cms\Core\Services\CategoryConfigQuery;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CategoryConfigQueryTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $createdUids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureCategoriesTable();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('categories') && $this->createdUids !== []) {
            Category::query()
                ->withTrashed()
                ->whereIn('uid', $this->createdUids)
                ->forceDelete();
        }

        parent::tearDown();
    }

    public function test_text_backed_category_config_query_matches_value_like_in_and_bool(): void
    {
        $matchId = $this->createCategory([
            'showApi' => true,
            'apiList' => ['mobile_customer_guard'],
            'last_category_id' => 987654,
            'sample_directory' => [
                'taxonomy' => 'authority',
                'key' => 'guard_police',
            ],
        ]);
        $this->createCategory([
            'showApi' => false,
            'apiList' => ['other_client'],
            'last_category_id' => 111,
            'sample_directory' => [
                'taxonomy' => 'authority',
                'key' => 'guard_other',
            ],
        ]);

        $ids = Category::query()
            ->tap(static fn ($query) => CategoryConfigQuery::whereConfigBool($query, 'showApi', true))
            ->tap(static fn ($query) => CategoryConfigQuery::whereConfigLike($query, 'apiList', '%mobile_customer_guard%'))
            ->tap(static fn ($query) => CategoryConfigQuery::whereConfig($query, 'last_category_id', 987654))
            ->tap(static fn ($query) => CategoryConfigQuery::whereConfigIn($query, 'sample_directory.key', ['guard_police']))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertContains($matchId, $ids);
    }

    public function test_category_repository_routes_config_where_arrays_through_driver_guard(): void
    {
        $client = 'mobile_customer_guard_' . Str::lower(Str::random(6));
        $matchId = $this->createCategory([
            'showApi' => true,
            'apiList' => [$client],
        ]);
        $this->createCategory([
            'showApi' => true,
            'apiList' => ['other_client'],
        ]);

        $items = (new CategoryRepository())->get([
            'select' => ['id'],
            'where' => [
                'config->showApi' => true,
                ['config->apiList', 'like', '%' . $client . '%'],
            ],
        ]);

        $this->assertContains(
            $matchId,
            $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
    }

    public function test_category_config_query_compiles_separate_postgresql_and_mysql_sql(): void
    {
        //  This asserts the exact bindings the JSON config predicate compiles
        //  to, so the appended-relations global scope is switched off: its
        //  slug/children projections contribute bindings of their own and are
        //  not what this test is about.
        $pgsql = Category::on('pgsql')->withoutGlobalScope(Category::APPENDED_RELATIONS_SCOPE);
        CategoryConfigQuery::whereConfig($pgsql, 'sample_directory.taxonomy', 'authority');

        $mysql = Category::on('mysql')->withoutGlobalScope(Category::APPENDED_RELATIONS_SCOPE);
        CategoryConfigQuery::whereConfig($mysql, 'sample_directory.taxonomy', 'authority');

        $this->assertStringContainsString('NULLIF("config"::text, \'\')::jsonb #>>', $pgsql->toSql());
        $this->assertSame(['authority'], $pgsql->getBindings());
        $this->assertStringContainsString('JSON_UNQUOTE(JSON_EXTRACT(`config`, ?))', $mysql->toSql());
        $this->assertSame(['$.sample_directory.taxonomy', 'authority'], $mysql->getBindings());
    }

    private function createCategory(array $config): int
    {
        $uid = strtoupper(substr('T' . Str::random(9), 0, 10));
        $this->createdUids[] = $uid;

        return (int) DB::table('categories')->insertGetId([
            'uid' => $uid,
            'uuid' => (string) Str::uuid(),
            'name' => 'Guarded Config ' . $uid,
            'priority' => 1,
            'type' => 1,
            'featured' => false,
            'status' => StatusEnum::PUBLISHED->value,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function ensureCategoriesTable(): void
    {
        //  The Category global scope reads slugs + uploads on every query.
        AppendedRelationSchema::ensure();

        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->char('uid', 10)->nullable()->unique();
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('content')->nullable();
            $table->text('hashtag')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->unsignedTinyInteger('type')->default(0);
            $table->boolean('featured')->default(false);
            $table->char('status', 50)->nullable();
            $table->text('config')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
