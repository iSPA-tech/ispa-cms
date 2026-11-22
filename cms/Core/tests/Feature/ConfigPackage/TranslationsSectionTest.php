<?php

declare(strict_types=1);

use Cms\Core\Models\Translate;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    Schema::dropIfExists('translates');
    Schema::create('translates', function (Blueprint $table) {
        $table->id();
        $table->text('modules')->nullable();
        $table->char('group_name', 20)->nullable();
        $table->char('name', 100)->nullable()->unique();
        $table->text('text')->nullable();
        $table->boolean('for_web')->default(false);
        $table->boolean('for_adm')->default(false);
        $table->boolean('for_app')->default(false);
        $table->char('status', 50)->nullable();
        $table->boolean('is_used')->default(false);
        $table->timestamp('last_scanned_at')->nullable();
        $table->json('used_in')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

function cpTr(string $name, array $text, array $overrides = []): Translate
{
    return Translate::create(array_merge([
        'name' => $name,
        'group_name' => 'text',
        'modules' => ['Core'],
        'text' => $text,
        'for_web' => true,
        'for_adm' => false,
        'for_app' => false,
        'status' => 'active',
        'is_used' => true,
        'used_in' => ['resources/views/a.blade.php'],
        'last_scanned_at' => '2026-01-01 10:00:00',
    ], $overrides));
}

function cpTrSection(array $scope = []): TranslationsSection
{
    return new TranslationsSection(...$scope);
}

it('exports web rows by default and honours adm, app and all scopes', function (): void {
    cpTr('text.web', ['vi' => 'Web', 'en' => 'Web']);
    cpTr('text.adm', ['vi' => 'Adm'], ['for_web' => false, 'for_adm' => true]);
    cpTr('text.app', ['vi' => 'App'], ['for_web' => false, 'for_app' => true]);

    $names = fn (array $o) => array_column(cpTrSection($o)->export(new ExportContext())->rows, 'name');

    expect($names([]))->toBe(['text.web'])
        ->and($names(['scope' => 'adm']))->toBe(['text.adm'])
        ->and($names(['scope' => 'app']))->toBe(['text.app'])
        ->and($names(['scope' => 'all']))->toBe(['text.adm', 'text.app', 'text.web']);
});

it('filters export by modules and skips soft-deleted rows', function (): void {
    cpTr('text.core', ['vi' => 'C'], ['modules' => ['Core']]);
    cpTr('text.edu', ['vi' => 'E'], ['modules' => ['Education', 'Content']]);
    cpTr('text.gone', ['vi' => 'G'])->delete();

    $rows = cpTrSection(['modules' => ['Content']])->export(new ExportContext())->rows;

    expect(array_column($rows, 'name'))->toBe(['text.edu']);
    expect(array_column(cpTrSection()->export(new ExportContext())->rows, 'name'))->toBe(['text.core', 'text.edu']);
});

it('exports carried fields only with the stored text object', function (): void {
    cpTr('text.web', ['vi' => 'Xin chao', 'en' => 'Hello']);

    $row = cpTrSection()->export(new ExportContext())->rows[0];

    expect($row)->toBe([
        'name' => 'text.web',
        'group_name' => 'text',
        'modules' => ['Core'],
        'text' => ['vi' => 'Xin chao', 'en' => 'Hello'],
        'for_web' => true,
        'for_adm' => false,
        'for_app' => false,
        'status' => 'active',
    ]);
    expect(DB::table('translates')->value('text'))->toBe('{"vi":"Xin chao","en":"Hello"}');
});

it('diffs create, update and unchanged by name', function (): void {
    cpTr('text.same', ['vi' => 'A']);
    cpTr('text.diff', ['vi' => 'Old']);
    $base = ['group_name' => 'text', 'modules' => ['Core'], 'for_web' => true, 'for_adm' => false, 'for_app' => false, 'status' => 'active'];

    $diff = cpTrSection()->diff([
        $base + ['name' => 'text.same', 'text' => ['vi' => 'A']],
        $base + ['name' => 'text.diff', 'text' => ['vi' => 'New']],
        $base + ['name' => 'text.new', 'text' => ['vi' => 'N']],
    ], new DiffContext());

    expect($diff->item('text.same')['action'])->toBe('unchanged')
        ->and($diff->item('text.diff'))->toMatchArray(['action' => 'update'])
        ->and($diff->item('text.diff')['before']['text'])->toBe(['vi' => 'Old'])
        ->and($diff->item('text.diff')['after']['text'])->toBe(['vi' => 'New'])
        ->and($diff->item('text.new')['action'])->toBe('create')
        ->and($diff->counts())->toMatchArray(['create' => 1, 'update' => 1, 'unchanged' => 1, 'blocked' => 0]);
});

it('blocks long, empty or invalid rows', function (): void {
    $long = str_repeat('a', 101);

    $diff = cpTrSection()->diff([
        ['name' => $long, 'text' => ['vi' => 'x']],
        ['name' => '', 'text' => ['vi' => 'x']],
        ['name' => 'text.empty', 'text' => []],
        ['name' => 'text.str', 'text' => 'plain'],
    ], new DiffContext());

    expect($diff->counts()['blocked'])->toBe(4)
        ->and($diff->item($long)['reason'])->toContain('100');

    $result = cpTrSection()->apply([['name' => $long, 'text' => ['vi' => 'x']]], new ApplyContext());
    expect($result->blocked)->toBe(1)->and(Translate::withTrashed()->count())->toBe(0);
});

it('diffs a soft-deleted row as update noting the restore', function (): void {
    cpTr('text.old', ['vi' => 'A'])->delete();

    $diff = cpTrSection()->diff([['name' => 'text.old', 'text' => ['vi' => 'A'], 'for_web' => true]], new DiffContext());

    expect($diff->item('text.old')['action'])->toBe('update')
        ->and($diff->item('text.old')['reason'])->toContain('restored');
});

it('applies a new web key, new wording, and restores a soft-deleted row without touching scan data', function (): void {
    cpTr('text.exists', ['vi' => 'Old']);
    cpTr('text.trashed', ['vi' => 'Old'])->delete();
    $base = ['group_name' => 'text', 'modules' => ['Core'], 'for_web' => true, 'for_adm' => false, 'for_app' => false, 'status' => 'active'];

    $result = cpTrSection()->apply([
        $base + ['name' => 'text.brand_new', 'text' => ['vi' => 'Moi', 'en' => 'New']],
        $base + ['name' => 'text.exists', 'text' => ['vi' => 'Moi']],
        $base + ['name' => 'text.trashed', 'text' => ['vi' => 'Lai']],
    ], new ApplyContext());

    expect($result->blocked)->toBe(0)->and($result->updated)->toBe(3);

    $new = Translate::where('name', 'text.brand_new')->first();
    expect($new->text)->toBe(['vi' => 'Moi', 'en' => 'New'])->and($new->for_web)->toBeTrue()->and($new->is_used)->toBeFalse();

    $exists = Translate::where('name', 'text.exists')->first();
    expect($exists->text)->toBe(['vi' => 'Moi'])
        ->and($exists->is_used)->toBeTrue()
        ->and($exists->used_in)->toBe(['resources/views/a.blade.php'])
        ->and($exists->last_scanned_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 10:00:00');

    $trashed = Translate::where('name', 'text.trashed')->first();
    expect($trashed)->not->toBeNull()->and($trashed->deleted_at)->toBeNull()->and($trashed->text)->toBe(['vi' => 'Lai']);
    expect(Translate::withTrashed()->count())->toBe(3);
});

it('skips deselected items and counts unchanged', function (): void {
    cpTr('text.a', ['vi' => 'A']);
    $base = ['group_name' => 'text', 'modules' => ['Core'], 'for_web' => true, 'for_adm' => false, 'for_app' => false, 'status' => 'active'];

    $result = cpTrSection()->apply([
        $base + ['name' => 'text.a', 'text' => ['vi' => 'A']],
        $base + ['name' => 'text.b', 'text' => ['vi' => 'B']],
    ], new ApplyContext(['text.b' => false]));

    expect($result->unchanged)->toBe(1)->and($result->skipped)->toBe(1)->and(Translate::where('name', 'text.b')->exists())->toBeFalse();
});

it('afterApply generates language packs and never syncs or exports', function (): void {
    Artisan::spy();

    cpTrSection()->afterApply(new ApplyContext());

    Artisan::shouldHaveReceived('call')->once()->with('cms:translation-generate');
    Artisan::shouldNotHaveReceived('call', ['cms:translation-sync']);
    Artisan::shouldNotHaveReceived('call', ['cms:export-db translates']);
});

it('round-trips carried keys and leaves other rows untouched', function (): void {
    cpTr('text.a', ['vi' => 'A', 'en' => 'a']);
    cpTr('text.b', ['vi' => 'B'], ['modules' => ['Core', 'Content']]);
    $package = cpTrSection()->export(new ExportContext())->rows;

    Translate::where('name', 'text.a')->first()->update(['text' => ['vi' => 'changed']]);
    Translate::where('name', 'text.b')->first()->delete();
    cpTr('text.other', ['vi' => 'Other'], ['for_web' => false, 'for_adm' => true]);

    cpTrSection()->apply($package, new ApplyContext());

    expect(cpTrSection()->export(new ExportContext())->rows)->toBe($package)
        ->and(Translate::where('name', 'text.other')->first()->text)->toBe(['vi' => 'Other']);
});
