<?php

use Cms\Core\Admin\CustomFields\CustomFieldRules;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\CustomFields\CustomFieldValueStore;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaData;
use Cms\Core\Services\MetaAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Test record model with its own table so Core depends on no module. */
class CfvRecord extends Model
{
    /** @var string */
    protected $table = 'cfv_records';

    /** @var array<int, string> */
    protected $guarded = [];

    /** Assigned meta rows. */
    public function meta(): MorphMany
    {
        return $this->morphMany(MetaData::class, 'target');
    }
}

/** Resource that opts in to custom fields. */
class CfvResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cfvtest/records';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cfvtest.records.view';
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
    app(ResourceRegistry::class)->register('cfvtest/records', CfvResource::class);
    Schema::create('cfv_records', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamps();
    });
    $this->service = new CustomFieldService(app(ResourceRegistry::class), new CustomFieldRules());
    $this->store = new CustomFieldValueStore(new MetaAssignmentService(), $this->service);
});

/** Define one field on the test resource. */
function cfvField(string $key, string $type, array $extra = []): MetaAttribute
{
    return test()->service->create('cfvtest/records', array_merge([
        'key' => $key,
        'type' => $type,
        'labels' => ['vi' => $key, 'en' => $key],
    ], $extra), null);
}

/** Options for a select field. */
function cfvOptions(): array
{
    return array_map(fn ($v) => ['value' => $v, 'labels' => ['vi' => $v, 'en' => $v]], ['red', 'green', 'blue']);
}

/** Create a record. */
function cfvRecord(): CfvRecord
{
    return CfvRecord::create(['title' => 't']);
}

/** Pooled value row count of the string table. */
function cfvStrings(): int
{
    return DB::table(CoreTableConstant::META_VALUES_STRING)->count();
}

it('round trips every field type', function () {
    foreach (['name' => 'text', 'bio' => 'textarea', 'seats' => 'integer', 'active' => 'boolean', 'since' => 'date'] as $key => $type) {
        cfvField($key, $type);
    }
    cfvField('color', 'select', ['options' => cfvOptions()]);
    cfvField('tags', 'multiselect', ['options' => cfvOptions()]);
    $record = cfvRecord();

    $ignored = $this->store->save($record, 'cfvtest/records', [
        'name' => '  Acme   Corp ', 'bio' => "Long\ntext", 'seats' => '42', 'active' => false,
        'since' => '2026-03-05', 'color' => 'green', 'tags' => ['red', 'blue'],
    ], null);

    expect($ignored)->toBe([])
        ->and($this->store->valuesFor($record, 'cfvtest/records'))->toBe([
            'name' => 'Acme Corp', 'bio' => "Long\ntext", 'seats' => 42, 'active' => false,
            'since' => '2026-03-05', 'color' => 'green', 'tags' => ['red', 'blue'],
        ]);
});

it('returns null for fields without a value', function () {
    cfvField('name', 'text');
    cfvField('tags', 'multiselect', ['options' => cfvOptions()]);

    expect($this->store->valuesFor(cfvRecord(), 'cfvtest/records'))->toBe(['name' => null, 'tags' => null]);
});

it('reads 25 records with exactly two queries', function () {
    cfvField('name', 'text');
    cfvField('seats', 'integer');
    cfvField('tags', 'multiselect', ['options' => cfvOptions()]);
    $records = collect(range(1, 25))->map(fn ($i) => cfvRecord());
    foreach ($records as $i => $record) {
        $this->store->save($record, 'cfvtest/records', ['name' => "n{$i}", 'seats' => $i, 'tags' => ['red', 'green']], null);
    }
    $this->store->valuesFor($records->first(), 'cfvtest/records'); // warm the memoized definitions

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $this->store->valuesForMany($records, 'cfvtest/records');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($result)->toHaveCount(25)
        ->and($result[$records[3]->id])->toBe(['name' => 'n3', 'seats' => 3, 'tags' => ['red', 'green']]);
});

it('clearing a value removes the assignment but keeps the pooled value row', function () {
    cfvField('name', 'text');
    $record = cfvRecord();
    $this->store->save($record, 'cfvtest/records', ['name' => 'Acme'], null);
    $pool = cfvStrings();

    foreach ([null, ''] as $empty) {
        $this->store->save($record, 'cfvtest/records', ['name' => 'Acme'], null);
        $this->store->save($record, 'cfvtest/records', ['name' => $empty], null);
        expect($record->meta()->count())->toBe(0)
            ->and(cfvStrings())->toBe($pool)
            ->and($this->store->valuesFor($record, 'cfvtest/records'))->toBe(['name' => null]);
    }
});

it('adds and removes multiselect values', function () {
    cfvField('tags', 'multiselect', ['options' => cfvOptions()]);
    $record = cfvRecord();
    $pool = null;

    $this->store->save($record, 'cfvtest/records', ['tags' => ['red', 'green']], null);
    $pool = cfvStrings();
    $this->store->save($record, 'cfvtest/records', ['tags' => ['green', 'blue']], null);
    expect($this->store->valuesFor($record, 'cfvtest/records')['tags'])->toEqualCanonicalizing(['green', 'blue'])
        ->and($record->meta()->count())->toBe(2)
        ->and(cfvStrings())->toBe($pool + 1);

    $this->store->save($record, 'cfvtest/records', ['tags' => []], null);
    expect($record->meta()->count())->toBe(0)
        ->and(cfvStrings())->toBe($pool + 1);
});

it('creates no second assignment row when the same value is saved twice', function () {
    cfvField('name', 'text');
    cfvField('tags', 'multiselect', ['options' => cfvOptions()]);
    $record = cfvRecord();

    foreach ([1, 2] as $_) {
        $this->store->save($record, 'cfvtest/records', ['name' => 'Acme', 'tags' => ['red']], null);
    }

    expect($record->meta()->count())->toBe(2);
});

it('shares one pooled value row between records and edits stay separate', function () {
    cfvField('name', 'text');
    [$a, $b] = [cfvRecord(), cfvRecord()];
    $this->store->save($a, 'cfvtest/records', ['name' => 'Shared'], null);
    $this->store->save($b, 'cfvtest/records', ['name' => 'Shared'], null);
    expect(cfvStrings())->toBe(1);

    $this->store->save($a, 'cfvtest/records', ['name' => 'Changed'], null);

    expect($this->store->valuesFor($a, 'cfvtest/records')['name'])->toBe('Changed')
        ->and($this->store->valuesFor($b, 'cfvtest/records')['name'])->toBe('Shared')
        ->and(cfvStrings())->toBe(2);
});

it('returns unknown and archived keys as ignored', function () {
    cfvField('name', 'text');
    $old = cfvField('legacy', 'text');
    $old->update(['archived_at' => now()]);
    $record = cfvRecord();

    $ignored = $this->store->save($record, 'cfvtest/records', ['name' => 'x', 'legacy' => 'y', 'ghost' => 'z'], null);

    expect($ignored)->toBe(['legacy', 'ghost'])
        ->and($record->meta()->count())->toBe(1)
        ->and($this->store->valuesFor($record, 'cfvtest/records'))->toBe(['name' => 'x']);
});

it('purges every assignment of a field and keeps value rows', function () {
    $field = cfvField('name', 'text');
    cfvField('city', 'text');
    foreach ([cfvRecord(), cfvRecord(), cfvRecord()] as $record) {
        $this->store->save($record, 'cfvtest/records', ['name' => 'Acme', 'city' => 'Hue'], null);
    }
    $pool = cfvStrings();

    expect($this->store->purge($field))->toBe(3)
        ->and(MetaData::query()->count())->toBe(3)
        ->and(cfvStrings())->toBe($pool);
});
