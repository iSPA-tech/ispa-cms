<?php

declare(strict_types=1);

use Cms\Core\Models\Language;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigTableRegistry;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\TablesSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    Event::fake();

    foreach (['email_templates', 'languages'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('languages', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('currency')->nullable();
        $table->string('path')->unique();
        $table->string('path_alias')->nullable();
        $table->string('code')->nullable();
        $table->string('flag')->nullable();
        $table->string('time_zone')->nullable();
        $table->boolean('default_web')->default(false);
        $table->boolean('default_adm')->default(false);
        $table->boolean('default_app')->default(false);
        $table->boolean('for_web')->default(false);
        $table->boolean('for_adm')->default(false);
        $table->boolean('for_app')->default(false);
        $table->string('status')->nullable();
        $table->boolean('is_locked')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('email_templates', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('key_name', 100)->nullable()->unique();
        $table->longText('content')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $this->vi = Language::forceCreate(['name' => 'Vietnamese', 'path' => 'vi', 'code' => 'vi']);
    $this->en = Language::forceCreate(['name' => 'English', 'path' => 'en', 'path_alias' => 'english', 'code' => 'en']);
});

/**
 * Section over a registry that registers email_templates exactly like Core does.
 */
function tsSection(): TablesSection
{
    $tables = new ConfigTableRegistry();
    $tables->register('email_templates', ['key_name'], ['user_id'], ['language_id']);
    $export = new ConfigExportRegistry(static fn (): array => [], $tables);

    return new TablesSection($export, $tables);
}

function tsTemplate(string $key, string $content, ?int $languageId = null, ?int $userId = 7): void
{
    DB::table('email_templates')->insert([
        'name' => strtoupper($key), 'key_name' => $key, 'content' => $content,
        'language_id' => $languageId, 'user_id' => $userId,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @return array<string, mixed>
 */
function tsRow(string $key): array
{
    return (array) DB::table('email_templates')->where('key_name', $key)->first();
}

it('exports email templates with the language path and without ids, users or timestamps', function (): void {
    tsTemplate('welcome', 'Hi', $this->vi->id);
    tsTemplate('goodbye', 'Bye', $this->en->id);
    tsTemplate('plain', 'Plain', null);

    $export = tsSection()->export(new ExportContext());
    $byKey = [];
    foreach ($export->rows as $row) {
        $byKey[$row['key']['key_name']] = $row;
    }

    expect($export->rows)->toHaveCount(3)
        ->and($byKey['welcome'])->toMatchArray(['table' => 'email_templates', 'language' => 'vi'])
        ->and($byKey['goodbye']['language'])->toBe('english')
        ->and($byKey['plain']['language'])->toBeNull()
        ->and($byKey['welcome']['values'])->toMatchArray(['name' => 'WELCOME', 'content' => 'Hi']);

    $json = json_encode($export->toArray());
    foreach (['user_id', 'language_id', '"id"', 'created_at', 'updated_at', 'deleted_at'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

it('round-trips email templates: state A restored, untouched rows untouched, created rows get the importing user', function (): void {
    tsTemplate('welcome', 'Hi', $this->vi->id);
    tsTemplate('goodbye', 'Bye', null);
    $package = tsSection()->export(new ExportContext())->rows;

    // Change the DB: edit one, delete one, add an unrelated one.
    DB::table('email_templates')->where('key_name', 'welcome')->update(['content' => 'CHANGED', 'language_id' => $this->en->id]);
    DB::table('email_templates')->where('key_name', 'goodbye')->delete();
    tsTemplate('local_only', 'Local', null, 9);

    $diff = tsSection()->diff($package, new DiffContext(5));
    expect($diff->item('email_templates|welcome')['action'])->toBe('update')
        ->and($diff->item('email_templates|goodbye')['action'])->toBe('create');

    $result = tsSection()->apply($package, new ApplyContext([], 5));

    expect($result->updated)->toBe(2)->and($result->blocked)->toBe(0);

    $welcome = tsRow('welcome');
    $goodbye = tsRow('goodbye');
    expect($welcome['content'])->toBe('Hi')
        ->and($welcome['language_id'])->toBe($this->vi->id)
        ->and($welcome['user_id'])->toBe(7)                // existing row keeps its owner
        ->and($goodbye['content'])->toBe('Bye')
        ->and($goodbye['user_id'])->toBe(5)                // created row belongs to the importing user
        ->and($goodbye['created_at'])->not->toBeNull()
        ->and(tsRow('local_only')['content'])->toBe('Local') // untouched
        ->and(tsRow('local_only')['user_id'])->toBe(9)
        ->and(tsSection()->diff($package, new DiffContext())->counts())->toMatchArray(['unchanged' => 2, 'update' => 0, 'create' => 0]);
});

it('creates with a null user when the context carries no user and never deletes', function (): void {
    tsTemplate('keep', 'Keep', null);
    $package = [['table' => 'email_templates', 'key' => ['key_name' => 'fresh'], 'language' => null, 'values' => ['name' => 'F', 'content' => 'x']]];

    $result = tsSection()->apply($package, new ApplyContext());

    expect($result->updated)->toBe(1)
        ->and(tsRow('fresh')['user_id'])->toBeNull()
        ->and(DB::table('email_templates')->where('key_name', 'keep')->exists())->toBeTrue();
});

it('honors deselected items', function (): void {
    $package = [['table' => 'email_templates', 'key' => ['key_name' => 'fresh'], 'language' => null, 'values' => ['name' => 'F']]];

    $result = tsSection()->apply($package, new ApplyContext(['email_templates|fresh' => false]));

    expect($result->skipped)->toBe(1)->and(DB::table('email_templates')->count())->toBe(0);
});

it('blocks unregistered tables, unknown languages, forged columns, secret-looking values and empty keys', function (): void {
    $base = ['table' => 'email_templates', 'key' => ['key_name' => 'x'], 'language' => null, 'values' => ['name' => 'X']];
    $rows = [
        ['table' => 'users', 'key' => ['email' => 'a@b.c'], 'language' => null, 'values' => ['name' => 'A']],
        array_merge($base, ['key' => ['key_name' => 'lang'], 'language' => 'xx']),
        array_merge($base, ['key' => ['key_name' => 'forged'], 'values' => ['name' => 'X', 'user_id' => 1, 'id' => 99]]),
        array_merge($base, ['key' => ['key_name' => 'secret'], 'values' => ['name' => 'X', 'api_token' => 'abc']]),
        array_merge($base, ['key' => []]),
    ];

    $diff = tsSection()->diff($rows, new DiffContext());
    $result = tsSection()->apply($rows, new ApplyContext([], 1));

    expect($diff->counts()['blocked'])->toBe(5)
        ->and($result->blocked)->toBe(5)
        ->and(DB::table('email_templates')->count())->toBe(0);
});

it('does not export rows with secret-looking content keys and lists them as required secrets', function (): void {
    $tables = new ConfigTableRegistry();
    $tables->register('email_templates', ['key_name'], ['user_id'], ['language_id']);
    $export = new ConfigExportRegistry(static fn (): array => [], $tables);
    tsTemplate('ciphered', 'eyJpdiI6SECRETCIPHERTEXT');

    $result = (new TablesSection($export, $tables))->export(new ExportContext());

    expect($result->rows)->toBe([])
        ->and($result->skipped)->toBe(['secret' => 1])
        ->and($result->requiredSecrets)->toBe(['email_templates|ciphered']);
});

it('ignores a registered table that no enabled module or Core declares', function (): void {
    $tables = new ConfigTableRegistry();
    $tables->register('email_templates', ['key_name'], ['user_id'], ['language_id']);
    $tables->register('hidden_table', ['code']);
    $section = new TablesSection(new ConfigExportRegistry(static fn (): array => [], $tables), $tables);

    $diff = $section->diff([['table' => 'hidden_table', 'key' => ['code' => 'a'], 'language' => null, 'values' => []]], new DiffContext());

    expect($diff->items[0]['action'])->toBe('blocked');
});
