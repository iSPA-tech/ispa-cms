<?php

namespace Cms\Core\Tests\Feature\Models;

use App\Models\Slug;
use Cms\Core\Traits\Models\GetSlugTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract for the inline slug projection.
 *
 * `slug` is an accessor over the `latestSlug` relation, so serializing a listing
 * used to cost one query per row. `withSlug()` selects the slug inside the
 * listing's own query; these cases pin the parts that are easy to get wrong.
 *
 * @author iSPA Team
 * @since  8.1.0 (2026-08-02)
 */
class GetSlugTraitTest extends TestCase
{
    /** @var array<string, class-string> morph map as it was before this test */
    private array $originalMorphMap = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMorphMap = Relation::morphMap();

        if (! Schema::hasTable('slug_trait_subjects')) {
            Schema::create('slug_trait_subjects', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('slugs')) {
            Schema::create('slugs', function (Blueprint $table): void {
                $table->id();
                $table->string('slug')->nullable();
                $table->string('category')->nullable();
                $table->string('target_type')->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->unsignedBigInteger('language_id')->nullable();
                $table->timestamps();
            });
        }

        DB::table('slug_trait_subjects')->delete();
        DB::table('slugs')->where('target_type', 'like', '%SlugTraitSubject%')->delete();
        DB::table('slugs')->where('target_type', 'slug_trait_subject')->delete();
    }

    protected function tearDown(): void
    {
        // The morph map is static global state; leaving a fixture registered
        // would leak into every later suite sharing this process.
        Relation::morphMap($this->originalMorphMap, merge: false);

        parent::tearDown();
    }

    /** T1 — the projection alias must never reach a payload. */
    public function test_projection_alias_is_hidden_from_serialized_output(): void
    {
        $subject = $this->makeSubject('Peugeot 308');
        $this->addSlug($subject, '308-sw');

        $loaded = SlugTraitSubject::query()->withSlug()->firstOrFail();

        $this->assertSame('308-sw', $loaded->slug);
        $this->assertArrayNotHasKey('slug_value', $loaded->toArray());
        $this->assertStringNotContainsString('slug_value', $loaded->toJson());
    }

    /** T2 — a caller's own projection survives the scope. */
    public function test_scope_does_not_replace_a_caller_projection(): void
    {
        $columns = SlugTraitSubject::query()->select('id')->withSlug()->getQuery()->columns;

        $this->assertSame('id', $columns[0]);
        $this->assertCount(2, $columns, 'withSlug() must add one column, not rewrite the projection.');
    }

    /** T3 — renames leave history rows; the newest one wins, as latestOfMany did. */
    public function test_highest_id_slug_wins_when_a_target_has_several(): void
    {
        $subject = $this->makeSubject('Renamed');
        $this->addSlug($subject, 'first-slug');
        $this->addSlug($subject, 'second-slug');
        $this->addSlug($subject, 'third-slug');

        $viaScope = SlugTraitSubject::query()->withSlug()->findOrFail($subject->id);
        $viaRelation = SlugTraitSubject::query()->findOrFail($subject->id);

        $this->assertSame('third-slug', $viaScope->slug);
        $this->assertSame($viaRelation->slug, $viaScope->slug, 'The scope must pick what the relation picks.');
    }

    /** T4 — a target with no slug row reads as an empty string, not null. */
    public function test_missing_slug_row_reads_as_empty_string(): void
    {
        $subject = $this->makeSubject('No slug');

        $viaScope = SlugTraitSubject::query()->withSlug()->findOrFail($subject->id);

        $this->assertSame('', $viaScope->slug);
        $this->assertSame('', SlugTraitSubject::query()->findOrFail($subject->id)->slug);
    }

    /** T5 — the whole point: query count stops growing with the row count. */
    public function test_query_count_is_constant_with_the_scope_and_grows_without_it(): void
    {
        foreach (range(1, 10) as $index) {
            $this->addSlug($this->makeSubject('Model ' . $index), 'model-' . $index);
        }

        $withScope = $this->countQueries(function (): void {
            SlugTraitSubject::query()->withSlug()->get()->each(fn ($row) => $row->slug);
        });
        $withoutScope = $this->countQueries(function (): void {
            SlugTraitSubject::query()->get()->each(fn ($row) => $row->slug);
        });

        $this->assertSame(1, $withScope, 'withSlug() must resolve every slug in the listing query.');
        $this->assertGreaterThan($withScope, $withoutScope, 'The lazy fallback must stay intact.');

        // And it stays at one when the listing grows.
        foreach (range(11, 30) as $index) {
            $this->addSlug($this->makeSubject('Model ' . $index), 'model-' . $index);
        }

        $this->assertSame($withScope, $this->countQueries(function (): void {
            SlugTraitSubject::query()->withSlug()->get()->each(fn ($row) => $row->slug);
        }));
    }

    /** T8 — target_type must be the morph alias, not the class name. */
    public function test_scope_binds_the_morph_alias_for_a_mapped_model(): void
    {
        Relation::morphMap(['slug_trait_subject' => SlugTraitSubject::class], merge: true);

        $subject = $this->makeSubject('Mapped');
        DB::table('slugs')->insert([
            'slug' => 'mapped-slug',
            'target_type' => 'slug_trait_subject',
            'target_id' => $subject->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bindings = SlugTraitSubject::query()->withSlug()->getQuery()->getBindings();

        $this->assertContains('slug_trait_subject', $bindings);
        $this->assertNotContains(SlugTraitSubject::class, $bindings);
        $this->assertSame('mapped-slug', SlugTraitSubject::query()->withSlug()->findOrFail($subject->id)->slug);
    }

    private function makeSubject(string $name): SlugTraitSubject
    {
        $id = DB::table('slug_trait_subjects')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SlugTraitSubject::query()->findOrFail($id);
    }

    private function addSlug(SlugTraitSubject $subject, string $slug): void
    {
        DB::table('slugs')->insert([
            'slug' => $slug,
            'target_type' => $subject->getMorphClass(),
            'target_id' => $subject->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function countQueries(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $work();

        return $count;
    }
}

/**
 * Fixture model. The trait's only morph-mapped consumer would have to be a real
 * model, and none of the ten production models using it is in the map, so the
 * mapping case needs a subject of its own.
 */
class SlugTraitSubject extends Model
{
    use GetSlugTrait;

    protected $table = 'slug_trait_subjects';

    protected $guarded = [];

    protected $appends = ['slug'];

    public function getCurrentLanguageId(): ?int
    {
        return null;
    }
}
