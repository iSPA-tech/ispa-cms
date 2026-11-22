<?php

use Cms\Core\Admin\CustomFields\CustomFieldException;
use Cms\Core\Admin\CustomFields\CustomFieldRules;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaValueString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Illuminate\Support\Facades\DB;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Test resource that opts in to custom fields. */
class CflcResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cflctest/records';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cflctest.records.view';
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
    app(ResourceRegistry::class)->register('cflctest/records', CflcResource::class);
    $this->gateLog = new ArrayObject();
    $this->service = new CustomFieldService(app(ResourceRegistry::class), new CustomFieldRules(), cflcGate($this->gateLog));
});

/**
 * Backup gate stand-in: records how many value rows were still in the database when the backup was taken, then returns
 * a stored backup record (or throws when $fail is set).
 *
 * @param ArrayObject<int, array<string, mixed>> $log
 */
function cflcGate(ArrayObject $log, bool $fail = false): BackupGate
{
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturnUsing(function (string $reason, ?int $userId) use ($log, $fail): Backup {
        $log[] = ['reason' => $reason, 'user' => $userId, 'rows' => DB::table(CoreTableConstant::META_DATA)->count()];
        if ($fail) {
            throw new BackupRequiredException('BACKUP_ALL_DESTINATIONS_FAILED');
        }

        return cflcBackup();
    });

    return $gate;
}

/** A persisted backup with one stored copy; $stored = false gives a copy that never uploaded. */
function cflcBackup(bool $stored = true, string $status = Backup::STATUS_SUCCEEDED): Backup
{
    $dest = BackupDestination::query()->firstOrCreate(['name' => 'cflc-local'], ['driver' => 'local', 'config' => ['disk' => 'local']]);
    $backup = Backup::query()->create([
        'reason' => Backup::REASON_PRE_IMPORT, 'status' => $status, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz',
    ]);
    BackupCopy::query()->create([
        'backup_id' => $backup->id, 'destination_id' => $dest->id, 'path' => 'cflc/backup.sqlite.gz',
        'status' => $stored ? BackupCopy::STATUS_STORED : BackupCopy::STATUS_FAILED,
    ]);

    return $backup;
}

/** Purge a field the way the API does: backup first, then delete. */
function cflcPurge(MetaAttribute $field, string $typed): int
{
    return test()->service->purge($field, $typed, test()->service->backupBeforePurge($field, $typed, 7));
}

/** Define a field on the test resource. */
function cflcField(string $key = 'industry', string $type = 'text', array $extra = []): MetaAttribute
{
    return test()->service->create('cflctest/records', array_merge([
        'key' => $key, 'type' => $type, 'labels' => ['vi' => $key, 'en' => $key],
    ], $extra), null);
}

/** Insert an assignment row for a record with a pooled string value. */
function cflcAssign(MetaAttribute $field, int $recordId, string $value): void
{
    $row = MetaValueString::query()->firstOrCreate(['value' => $value], ['label' => $value]);
    DB::table(CoreTableConstant::META_DATA)->insert([
        'meta_attribute_id' => $field->id, 'target_type' => 'cflc_record', 'target_id' => $recordId,
        'value_type' => MetaValueString::class, 'value_id' => $row->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Assignment rows of a field. */
function cflcRows(MetaAttribute $field): int
{
    return DB::table(CoreTableConstant::META_DATA)->where('meta_attribute_id', $field->id)->count();
}

it('archives a field, keeps its values and hides it unless asked', function () {
    $field = cflcField();
    cflcAssign($field, 1, 'a');

    $archived = $this->service->archive($field);

    expect($archived->archived_at)->not->toBeNull()
        ->and($archived->is_enabled)->toBeFalse()
        ->and(cflcRows($field))->toBe(1)
        ->and($this->service->fieldsFor('cflctest/records'))->toBeEmpty()
        ->and($this->service->fieldsFor('cflctest/records', true))->toHaveCount(1);

    $again = $this->service->archive($archived);
    expect($again->archived_at->equalTo($archived->archived_at))->toBeTrue();
});

it('restores an archived field with its values', function () {
    $field = cflcField();
    cflcAssign($field, 1, 'a');
    $this->service->archive($field);

    $restored = $this->service->restore($field->fresh());

    expect($restored->archived_at)->toBeNull()
        ->and($restored->is_enabled)->toBeTrue()
        ->and($this->service->fieldsFor('cflctest/records'))->toHaveCount(1)
        ->and(cflcRows($field))->toBe(1);
});

it('refuses a wrong confirm key and deletes nothing', function (string $typed) {
    $field = cflcField();
    cflcAssign($field, 1, 'a');

    try {
        cflcPurge($field, $typed);
        test()->fail('Expected a confirm mismatch.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::CONFIRM_MISMATCH);
    }

    expect(cflcRows($field))->toBe(1)->and(MetaAttribute::find($field->id))->not->toBeNull();
})->with(['wrong' => ['other'], 'case differs' => ['Industry'], 'padded' => [' industry'], 'empty' => ['']]);

it('purges assignments with the right key and soft-deletes the field', function () {
    $field = cflcField();
    $other = cflcField('region');
    cflcAssign($field, 1, 'a');
    cflcAssign($field, 2, 'b');
    cflcAssign($other, 1, 'c');

    $removed = cflcPurge($field, 'industry');

    expect($removed)->toBe(2)
        ->and(cflcRows($field))->toBe(0)
        ->and(cflcRows($other))->toBe(1)
        ->and(MetaAttribute::find($field->id))->toBeNull()
        ->and(MetaAttribute::withTrashed()->find($field->id)->trashed())->toBeTrue()
        ->and($this->service->fieldsFor('cflctest/records', true)->pluck('name')->all())->toBe(['cf.cflctest.records.region']);
});

it('keeps a purged key reserved and refuses to restore it', function () {
    $field = cflcField();
    cflcPurge($field, 'industry');

    try {
        cflcField();
        test()->fail('Expected a duplicate key.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::DUPLICATE_KEY);
    }
    try {
        $this->service->restore(MetaAttribute::withTrashed()->find($field->id));
        test()->fail('Expected a purged field.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::FIELD_PURGED);
    }
});

it('only operates on custom fields', function () {
    $plain = MetaAttribute::create(['name' => 'plain.attr', 'label' => 'Plain', 'type' => 'string', 'input_type' => 'text', 'is_custom' => false]);

    foreach ([
        fn () => $this->service->archive($plain),
        fn () => $this->service->restore($plain),
        fn () => $this->service->backupBeforePurge($plain, 'attr', null),
    ] as $call) {
        try {
            $call();
            test()->fail('Expected a refusal.');
        } catch (CustomFieldException $e) {
            expect($e->reason())->toBe(CustomFieldException::NOT_CUSTOM_FIELD);
        }
    }
});

it('counts distinct records, not value rows', function () {
    $field = cflcField('tags', 'multiselect', ['options' => [
        ['value' => 'a', 'labels' => ['vi' => 'a', 'en' => 'a']], ['value' => 'b', 'labels' => ['vi' => 'b', 'en' => 'b']],
    ]]);

    expect($this->service->usage($field))->toBe(0);
    cflcAssign($field, 1, 'a');
    cflcAssign($field, 1, 'b');
    expect($this->service->usage($field))->toBe(1);
    cflcAssign($field, 2, 'a');
    expect($this->service->usage($field))->toBe(2);
});

it('locks the type while records use the field and frees it at zero', function () {
    $field = cflcField();
    cflcAssign($field, 1, 'a');

    try {
        $this->service->update($field, ['type' => 'textarea']);
        test()->fail('Expected a type lock.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::TYPE_LOCKED);
    }

    DB::table(CoreTableConstant::META_DATA)->where('meta_attribute_id', $field->id)->delete();
    expect($this->service->update($field, ['type' => 'textarea'])->input_type)->toBe('textarea');
});

it('refuses removing an option that records use', function () {
    $field = cflcField('tier', 'select', ['options' => [
        ['value' => 'gold', 'labels' => ['vi' => 'V', 'en' => 'Gold']], ['value' => 'silver', 'labels' => ['vi' => 'B', 'en' => 'Silver']],
    ]]);
    cflcAssign($field, 1, 'silver');
    $onlyGold = [['value' => 'gold', 'labels' => ['vi' => 'V', 'en' => 'Gold']]];

    try {
        $this->service->update($field, ['options' => $onlyGold]);
        test()->fail('Expected an option in use.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::OPTION_IN_USE);
    }
    expect($this->service->update($field, ['options' => [
        ['value' => 'gold', 'labels' => ['vi' => 'V', 'en' => 'Gold']], ['value' => 'silver', 'labels' => ['vi' => 'B', 'en' => 'Silver']],
    ]])->rules['options'])->toHaveCount(2);
});

it('takes the backup before any value is deleted and the backup sees every row', function () {
    $field = cflcField();
    cflcAssign($field, 1, 'a');
    cflcAssign($field, 2, 'b');

    $backup = $this->service->backupBeforePurge($field, 'industry', 7);

    expect($this->gateLog->getArrayCopy())->toBe([['reason' => Backup::REASON_PRE_PURGE, 'user' => 7, 'rows' => 2]])
        ->and(cflcRows($field))->toBe(2);

    expect($this->service->purge($field, 'industry', $backup))->toBe(2)
        ->and(cflcRows($field))->toBe(0);
});

it('refuses to back up or purge on a wrong key without taking a backup', function () {
    $field = cflcField();
    cflcAssign($field, 1, 'a');

    try {
        $this->service->backupBeforePurge($field, 'nope', 7);
        test()->fail('Expected a confirm mismatch.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::CONFIRM_MISMATCH);
    }
    expect($this->gateLog->getArrayCopy())->toBe([])->and(cflcRows($field))->toBe(1);
});

it('deletes nothing when the backup cannot be taken', function () {
    $service = new CustomFieldService(app(ResourceRegistry::class), new CustomFieldRules(), cflcGate(new ArrayObject(), true));
    $field = cflcField();
    cflcAssign($field, 1, 'a');

    try {
        $service->backupBeforePurge($field, 'industry', 7);
        test()->fail('Expected a backup refusal.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::BACKUP_REQUIRED);
    }
    expect(cflcRows($field))->toBe(1)->and(MetaAttribute::find($field->id))->not->toBeNull();
});

it('refuses a purge whose backup record is missing, failed or has no stored copy', function (string $case) {
    $field = cflcField();
    cflcAssign($field, 1, 'a');
    $backup = match ($case) {
        'unsaved' => new Backup(),
        'failed' => cflcBackup(true, Backup::STATUS_FAILED),
        'no stored copy' => cflcBackup(false),
    };

    try {
        $this->service->purge($field, 'industry', $backup);
        test()->fail('Expected a backup refusal.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::BACKUP_REQUIRED);
    }
    expect(cflcRows($field))->toBe(1)->and(MetaAttribute::find($field->id))->not->toBeNull();
})->with(['unsaved', 'failed', 'no stored copy']);
