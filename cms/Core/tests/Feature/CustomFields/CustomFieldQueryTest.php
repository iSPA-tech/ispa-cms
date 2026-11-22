<?php

use Cms\Core\Admin\CustomFields\CustomFieldQuery;
use Cms\Core\Admin\CustomFields\CustomFieldRules;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\CustomFields\CustomFieldValueStore;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Services\MetaAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Test record model with its own table so Core depends on no module. */
class CfqRecord extends Model
{
    /** @var string */
    protected $table = 'cfq_records';

    /** @var array<int, string> */
    protected $guarded = [];
}

/** Resource that opts in to custom fields. */
class CfqResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cfqtest/records';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cfqtest.records.view';
    }

    /** No fields. */
    public function fields(): array
    {
        return [];
    }

    /** Opt in. */
    public function supportsCustomFields(): bool
    {
        return true;
    }
}

beforeEach(function () {
    app(ResourceRegistry::class)->register('cfqtest/records', CfqResource::class);
    Schema::create('cfq_records', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamps();
    });
    $this->service = new CustomFieldService(app(ResourceRegistry::class), new CustomFieldRules());
    $this->store = new CustomFieldValueStore(new MetaAssignmentService(), $this->service);
    $this->query = new CustomFieldQuery($this->service);
});

/** Define one field on the test resource. */
function cfqField(string $key, string $type, array $extra = []): MetaAttribute
{
    return test()->service->create('cfqtest/records', array_merge([
        'key' => $key, 'type' => $type, 'labels' => ['vi' => $key, 'en' => $key],
    ], $extra), null);
}

/** Select options. */
function cfqOptions(): array
{
    return array_map(fn ($v) => ['value' => $v, 'labels' => ['vi' => $v, 'en' => $v]], ['red', 'green', 'blue']);
}

/** Create a record titled $title holding the given custom values. */
function cfqRecord(string $title, array $values = []): CfqRecord
{
    $record = CfqRecord::create(['title' => $title]);
    test()->store->save($record, 'cfqtest/records', $values, null);

    return $record;
}

/** Titles of the records the filters leave, sorted. */
function cfqFiltered(array $filters): array
{
    $titles = test()->query->applyFilters(CfqRecord::query(), 'cfqtest/records', $filters)->pluck('title')->all();
    sort($titles);

    return $titles;
}

/** Titles of the records a search finds, sorted. */
function cfqSearched(string $text): array
{
    $titles = test()->query->applySearch(CfqRecord::query(), 'cfqtest/records', $text)->pluck('title')->all();
    sort($titles);

    return $titles;
}

it('filters by select and boolean equality', function () {
    cfqField('color', 'select', ['options' => cfqOptions()]);
    cfqField('active', 'boolean');
    cfqRecord('a', ['color' => 'red', 'active' => true]);
    cfqRecord('b', ['color' => 'green', 'active' => false]);
    cfqRecord('c', []);

    expect(cfqFiltered(['color' => 'red']))->toBe(['a'])
        ->and(cfqFiltered(['active' => 'true']))->toBe(['a'])
        ->and(cfqFiltered(['active' => '0']))->toBe(['b'])
        ->and(cfqFiltered(['color' => 'red', 'active' => 'false']))->toBe([]);
});

it('filters a multiselect with in semantics', function () {
    cfqField('tags', 'multiselect', ['options' => cfqOptions()]);
    cfqRecord('a', ['tags' => ['red', 'green']]);
    cfqRecord('b', ['tags' => ['blue']]);
    cfqRecord('c', []);

    expect(cfqFiltered(['tags' => ['red', 'blue']]))->toBe(['a', 'b'])
        ->and(cfqFiltered(['tags' => ['green']]))->toBe(['a'])
        ->and(cfqFiltered(['tags' => 'blue']))->toBe(['b']);
});

it('filters integers and dates by from and to', function () {
    cfqField('seats', 'integer');
    cfqField('since', 'date');
    cfqRecord('a', ['seats' => 5, 'since' => '2026-01-10']);
    cfqRecord('b', ['seats' => 50, 'since' => '2026-03-10']);
    cfqRecord('c', ['seats' => 500, 'since' => '2026-06-10']);

    expect(cfqFiltered(['seats' => ['from' => 10]]))->toBe(['b', 'c'])
        ->and(cfqFiltered(['seats' => ['to' => 50]]))->toBe(['a', 'b'])
        ->and(cfqFiltered(['seats' => ['from' => 6, 'to' => 100]]))->toBe(['b'])
        ->and(cfqFiltered(['seats' => 5]))->toBe(['a'])
        ->and(cfqFiltered(['since' => ['from' => '2026-02-01']]))->toBe(['b', 'c'])
        ->and(cfqFiltered(['since' => ['to' => '2026-03-10']]))->toBe(['a', 'b'])
        ->and(cfqFiltered(['since' => ['from' => '2026-02-01', 'to' => '2026-04-01']]))->toBe(['b'])
        ->and(cfqFiltered(['seats' => ['from' => 'many'], 'since' => ['from' => 'soon']]))->toBe(['a', 'b', 'c']);
});

it('ignores unknown keys, archived fields and text fields when filtering', function () {
    $color = cfqField('color', 'select', ['options' => cfqOptions()]);
    cfqField('name', 'text');
    cfqRecord('a', ['color' => 'red', 'name' => 'x']);
    cfqRecord('b', ['color' => 'green']);

    expect(cfqFiltered(['nope' => 'red']))->toBe(['a', 'b'])
        ->and(cfqFiltered(['name' => 'x']))->toBe(['a', 'b']);

    $color->forceFill(['archived_at' => now()])->save();
    expect(cfqFiltered(['color' => 'red']))->toBe(['a', 'b']);
});

it('searches text, select and textarea values of searchable fields only', function () {
    cfqField('name', 'text', ['is_searchable' => true]);
    cfqField('color', 'select', ['options' => cfqOptions(), 'is_searchable' => true]);
    cfqField('bio', 'textarea', ['is_searchable' => true]);
    cfqField('secret', 'text');
    $gone = cfqField('old', 'text', ['is_searchable' => true]);
    cfqRecord('a', ['name' => 'Acme Retail']);
    cfqRecord('b', ['color' => 'green']);
    cfqRecord('c', ['bio' => "Sells ACME\nwidgets"]);
    cfqRecord('d', ['secret' => 'acme hidden']);
    cfqRecord('e', ['old' => 'acme archived']);
    $gone->forceFill(['archived_at' => now()])->save();

    expect(cfqSearched('acme'))->toBe(['a', 'c'])
        ->and(cfqSearched('GREE'))->toBe(['b'])
        ->and(cfqSearched('   '))->toBe([]);
});

it('does not search a textarea field that is not searchable', function () {
    cfqField('bio', 'textarea');
    cfqRecord('a', ['bio' => 'needle']);

    expect(cfqSearched('needle'))->toBe([]);
});

it('treats like wildcards in the search text literally', function () {
    cfqField('name', 'text', ['is_searchable' => true]);
    cfqRecord('pct', ['name' => '100% sure']);
    cfqRecord('und', ['name' => 'a_b']);
    cfqRecord('bsl', ['name' => 'path\\x']);
    cfqRecord('bang', ['name' => 'wow!']);
    cfqRecord('plain', ['name' => 'abc sure']);

    expect(cfqSearched('%'))->toBe(['pct'])
        ->and(cfqSearched('_'))->toBe(['und'])
        ->and(cfqSearched('a_b'))->toBe(['und'])
        ->and(cfqSearched('\\'))->toBe(['bsl'])
        ->and(cfqSearched('!'))->toBe(['bang'])
        ->and(cfqSearched('%sure'))->toBe([])
        ->and($this->query->likePattern('50%_!'))->toBe('%50!%!_!!%');
});

it('joins into an existing OR group', function () {
    cfqField('name', 'text', ['is_searchable' => true]);
    cfqRecord('Alpha', []);
    cfqRecord('b', ['name' => 'alpha co']);
    cfqRecord('c', ['name' => 'zzz']);

    $titles = CfqRecord::query()->where(function ($group) {
        $group->where('title', 'like', '%alpha%');
        $this->query->orSearch($group, 'cfqtest/records', 'alpha');
    })->pluck('title')->all();
    sort($titles);

    expect($titles)->toBe(['Alpha', 'b']);
});

it('binds hostile keys and values instead of concatenating them', function () {
    cfqField('color', 'select', ['options' => cfqOptions()]);
    cfqField('name', 'text', ['is_searchable' => true]);
    cfqRecord('a', ['color' => 'red', 'name' => 'x']);
    $evil = "x' OR 1=1 --";

    $builder = $this->query->applyFilters(CfqRecord::query(), 'cfqtest/records', [
        "color'; DROP TABLE cfq_records; --" => 'red', 'color' => $evil, "1) OR (1=1" => ['from' => $evil],
    ]);
    $this->query->applySearch($builder, 'cfqtest/records', $evil);

    expect($builder->toSql())->not->toContain('DROP')->not->toContain('OR 1=1')->not->toContain($evil)
        ->and($builder->get())->toHaveCount(0)
        ->and(Schema::hasTable('cfq_records'))->toBeTrue()
        ->and(cfqFiltered(["color' OR '1'='1" => 'red']))->toBe(['a'])
        ->and(cfqSearched("%' OR 1=1 --"))->toBe([]);
});

it('reports the sqlite plan of a select filter', function () {
    cfqField('color', 'select', ['options' => cfqOptions()]);
    cfqRecord('a', ['color' => 'red']);

    $builder = $this->query->applyFilters(CfqRecord::query(), 'cfqtest/records', ['color' => 'red']);
    $plan = DB::select('explain query plan '.$builder->toSql(), $builder->getBindings());
    // Informational only (no assertion on the plan itself); the plan is echoed so the report can quote it.
    fwrite(STDERR, "\nEXPLAIN select filter: ".json_encode(array_map(fn ($row) => $row->detail, $plan))."\n");

    expect($plan)->not->toBeEmpty();
});
