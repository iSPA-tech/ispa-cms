<?php

namespace Cms\Core\Tests\Feature\Models;

use App\Models\Category as AppCategory;
use Cms\Core\Models\Category;
use Cms\Core\Tests\Support\AppendedRelationSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract for the ResolvesAppendedRelations global scope, as Category uses it.
 *
 * `slug` and `image` are appended attributes that fall back to their own query
 * when the relation behind them is not loaded, and a category is serialized
 * nearly everywhere in the public API. The scope eager loads both up front.
 *
 * The cases that matter most are the ones pinning *why* it eager loads rather
 * than projects: a global scope also reaches queries consumed as a subquery or
 * an aggregate, where an extra select column is a hard error.
 *
 * @author iSPA Team
 * @since  8.1.0 (2026-08-03)
 */
class AppendedRelationsScopeTest extends TestCase
{
    private const SCOPE = Category::APPENDED_RELATIONS_SCOPE;

    protected function setUp(): void
    {
        parent::setUp();

        AppendedRelationSchema::ensure();

        // Every site has the languages table. Without it each lang() call during serialization re-checks the schema,
        // which this test would count as per-row queries.
        if (! Schema::hasTable('languages')) {
            Schema::create('languages', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->string('path');
                $table->string('path_alias')->nullable();
                $table->string('flag')->nullable();
                $table->string('currency')->nullable();
                $table->boolean('default_web')->default(false);
                $table->boolean('for_web')->default(true);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table): void {
                $table->id();
                $table->char('uid', 10)->nullable();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->unsignedBigInteger('language_id')->nullable();
                $table->unsignedTinyInteger('priority')->default(0);
                $table->unsignedTinyInteger('type')->default(0);
                $table->boolean('featured')->default(false);
                $table->string('status')->nullable();
                $table->text('config')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        lang(); // resolve the current language once, as a request does, so no count below includes it

        DB::table('categories')->delete();
        DB::table('slugs')->where('target_type', 'like', '%Category')->delete();
        DB::table('upload_target_pivot')->where('target_type', 'like', '%Category')->delete();
        DB::table('uploads')->delete();
    }

    /** T1 — nothing internal may reach a payload. */
    public function test_projection_aliases_are_hidden_from_serialized_output(): void
    {
        $this->makeCategory(1, 'Thị trường');

        $payload = Category::query()->findOrFail(1)->toArray();

        $this->assertArrayNotHasKey('slug_value', $payload);
        $this->assertArrayNotHasKey('children_exists', $payload);
        $this->assertArrayHasKey('slug', $payload);
        $this->assertArrayHasKey('has_children', $payload);
    }

    /** T2 — neither the scope nor the projections may change an answer. */
    public function test_scoped_accessors_match_the_lazy_fallback(): void
    {
        $this->makeCategory(1, 'Parent');
        $this->makeCategory(2, 'Child', parentId: 1);
        $this->addSlug(1, 'parent-slug');

        $read = static fn (Category $category): array => [
            'slug' => $category->slug,
            'has_children' => $category->has_children,
        ];

        $lazy = Category::query()
            ->withoutGlobalScope(self::SCOPE)
            ->orderBy('id')
            ->get()
            ->map($read)
            ->all();

        $scoped = Category::query()->orderBy('id')->get()->map($read)->all();

        $this->assertSame($lazy, $scoped);
        $this->assertTrue($scoped[0]['has_children']);
        $this->assertFalse($scoped[1]['has_children']);
        $this->assertSame('parent-slug', $scoped[0]['slug']);
        $this->assertSame('', $scoped[1]['slug']);
    }

    /** T3 — the whole point: query count must not grow with row count. */
    public function test_query_count_is_constant_across_row_counts(): void
    {
        foreach (range(1, 4) as $id) {
            $this->makeCategory($id, 'Category ' . $id);
            $this->addSlug($id, 'category-' . $id);
        }

        $small = $this->countQueriesSerializing(2);

        foreach (range(5, 12) as $id) {
            $this->makeCategory($id, 'Category ' . $id);
            $this->addSlug($id, 'category-' . $id);
        }

        $large = $this->countQueriesSerializing(12);

        $this->assertSame($small, $large, 'serializing more categories must not cost more queries');
    }

    /**
     * T4 — the projection and the relation must answer identically.
     *
     * `withSlug()` mirrors `latestSlug`, a MorphOne pinned to getMorphClass().
     * A row stored under a subclass name matches neither, and the two paths
     * have to agree on that: a slug that appears only when the listing happens
     * to project is worse than one that never appears.
     */
    public function test_projection_and_relation_agree_on_a_foreign_morph_type(): void
    {
        $this->makeCategory(1, 'Legacy');
        $this->addSlug(1, 'legacy-slug', AppCategory::class);

        $projected = Category::query()
            ->withoutGlobalScope(self::SCOPE)
            ->withSlug()
            ->findOrFail(1)
            ->slug;

        $lazy = Category::query()
            ->withoutGlobalScope(self::SCOPE)
            ->findOrFail(1)
            ->slug;

        $this->assertSame($lazy, $projected);
        $this->assertSame('', $projected);
    }

    /** T5 — soft-deleted children must not count as children. */
    public function test_soft_deleted_children_are_not_counted(): void
    {
        $this->makeCategory(1, 'Parent');
        $this->makeCategory(2, 'Child', parentId: 1);

        $this->assertTrue(Category::query()->findOrFail(1)->has_children);

        DB::table('categories')->where('id', 2)->update(['deleted_at' => now()]);

        $this->assertFalse(Category::query()->findOrFail(1)->has_children);
    }

    /** T6 — a caller's own projection must survive. */
    public function test_caller_projection_is_preserved(): void
    {
        $this->makeCategory(1, 'Projected');
        $this->addSlug(1, 'projected');

        $row = Category::query()->select('id', 'name')->findOrFail(1);

        $this->assertSame('Projected', $row->name);
        $this->assertSame('projected', $row->slug);
        $this->assertNull($row->getAttributes()['status'] ?? null);
    }

    /**
     * T7 — the scope must leave a one-column subquery one column wide.
     *
     * This is why it eager loads instead of projecting. A select-adding global
     * scope turns `select parent_id` into `select parent_id, (…) as slug_value`,
     * and the database rejects a two-column scalar subquery outright. A
     * production cache-version query hit exactly this.
     */
    public function test_scoped_model_still_works_as_a_scalar_subquery(): void
    {
        $this->makeCategory(1, 'Parent');
        $this->makeCategory(2, 'Child', parentId: 1);

        $subquery = Category::query()->whereNotNull('parent_id')->select('parent_id');

        $this->assertSame(
            1,
            (int) DB::table('categories')->whereIn('id', $subquery)->count(),
        );
    }

    /** T8 — an aggregate must not drag the eager loads along. */
    public function test_aggregates_run_without_the_scope_relations(): void
    {
        $this->makeCategory(1, 'Parent');
        $this->addSlug(1, 'parent-slug');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(1, Category::query()->count());

        $loaded = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'slugs') || str_contains($sql, 'uploads'),
        ));

        $this->assertSame([], $loaded, 'count() must not eager load: ' . implode(' | ', $loaded));
    }

    /**
     * Serialize the first $limit categories and report how many queries it took.
     */
    private function countQueriesSerializing(int $limit): int
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        //  `has_children` is opt-in — the scope cannot project it safely — so a
        //  listing that serializes categories asks for it by name.
        Category::query()->withChildrenExists()->orderBy('id')->limit($limit)->get()->toArray();

        // The listener stays registered for the rest of the test, but each call
        // reads its own counter, so a later call is unaffected by this one.
        return $count;
    }

    private function makeCategory(int $id, string $name, ?int $parentId = null): void
    {
        DB::table('categories')->insert([
            'id' => $id,
            'uid' => 'cat' . str_pad((string) $id, 7, '0', STR_PAD_LEFT),
            'name' => $name,
            'parent_id' => $parentId,
            'language_id' => 1,
            'status' => 'status_published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addSlug(int $categoryId, string $slug, ?string $targetType = null): void
    {
        DB::table('slugs')->insert([
            'slug' => $slug,
            'target_type' => $targetType ?? Category::class,
            'target_id' => $categoryId,
            'language_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
