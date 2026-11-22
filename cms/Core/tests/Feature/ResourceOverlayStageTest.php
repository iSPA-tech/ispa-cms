<?php

use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Pipeline\CustomFieldsStage;
use Cms\Core\Admin\Resources\Pipeline\DefinitionStage;
use Cms\Core\Admin\Resources\Pipeline\OverlayStage;
use Cms\Core\Admin\Resources\Pipeline\SchemaContext;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Cms\Core\Models\ResourceOverlay;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Resource used by the overlay stage tests. */
class OverlayTestResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'overlaytest/items';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'overlaytest.items.view';
    }

    /** Opt in to custom fields. */
    public function supportsCustomFields(): bool
    {
        return true;
    }

    /** Resource fields. */
    public function fields(): array
    {
        return [
            Field::text('name')->required()->max(191),
            Field::text('code'),
            Field::status('status', ['active', 'off'])->filterable(),
            Field::datetime('created_at'),
        ];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()->columns(['name', 'code', 'status', 'created_at'])->filters(['status'])
            ->endpoint('overlaytest.index');
    }

    /** Form view. */
    public function form(): ?FormView
    {
        return FormView::create(['name', 'code'])->endpoint('overlaytest.store')
            ->update(['name', 'code'])->updateEndpoint('overlaytest.update');
    }

    /** Detail view. */
    public function detail(): ?DetailView
    {
        return DetailView::make()->endpoint('overlaytest.show')->sections(['name', 'code', 'status']);
    }
}

/** Build the schema without the overlay stage, then with it, for the given locale. */
function overlayBuild(string $locale = 'vi'): array
{
    $user = tap(new AuthUser, fn ($u) => $u->id = 1);
    $context = new SchemaContext(new OverlayTestResource, $user, $locale);
    $schema = [];
    foreach ([new DefinitionStage, new CustomFieldsStage, new OverlayStage] as $stage) {
        $schema = $stage->apply($schema, $context);
    }

    return $schema;
}

/** Store an overlay row for the test resource. */
function overlayStore(array $data): void
{
    ResourceOverlay::create(['resource_key' => 'overlaytest/items'] + $data);
}

beforeEach(function () {
    $p = 'api/v2/admin/';
    Route::get($p.'overlaytest', fn () => null)->name('overlaytest.index');
    Route::post($p.'overlaytest', fn () => null)->name('overlaytest.store');
    Route::get($p.'overlaytest/{uuid}', fn () => null)->name('overlaytest.show');
    Route::put($p.'overlaytest/{uuid}', fn () => null)->name('overlaytest.update');
    app('router')->getRoutes()->refreshNameLookups();
    app(ResourceRegistry::class)->register('overlaytest/items', OverlayTestResource::class);
});

it('leaves the schema identical when the resource has no overlay row', function () {
    $user = tap(new AuthUser, fn ($u) => $u->id = 1);
    $without = (new SchemaBuilder([new DefinitionStage, new CustomFieldsStage]))->build(new OverlayTestResource, $user);

    expect(overlayBuild())->toBe($without)
        ->and((new SchemaBuilder)->build(new OverlayTestResource, $user))->toBe($without);
});

it('reads the overlay with a single query', function () {
    overlayStore(['hidden' => ['code']]);
    $user = tap(new AuthUser, fn ($u) => $u->id = 1);
    $context = new SchemaContext(new OverlayTestResource, $user, 'vi');
    $schema = (new DefinitionStage)->apply([], $context);

    DB::enableQueryLog();
    (new OverlayStage)->apply($schema, $context);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBe(1);
});

it('hides listed fields from list, filters, update form and detail', function () {
    overlayStore(['hidden' => ['code', 'status', 'created_at']]);

    $schema = overlayBuild();

    expect(array_column($schema['list']['columns'], 'field'))->toBe(['name'])
        ->and($schema['list']['filters'])->toBe([])
        ->and(array_column($schema['form']['create']['fields'], 'field'))->toBe(['name'])
        ->and(array_column($schema['form']['update']['fields'], 'field'))->toBe(['name'])
        ->and(array_column($schema['detail']['sections'], 'field'))->toBe(['name']);
});

it('never hides a required field from the forms', function () {
    overlayStore(['hidden' => ['name']]);

    $schema = overlayBuild();

    expect(array_column($schema['form']['create']['fields'], 'field'))->toContain('name')
        ->and(array_column($schema['form']['update']['fields'], 'field'))->toContain('name')
        ->and(array_column($schema['list']['columns'], 'field'))->not->toContain('name')
        ->and(array_column($schema['detail']['sections'], 'field'))->not->toContain('name');
});

it('moves ordered fields first and keeps the rest in their original order', function () {
    overlayStore(['order' => ['created_at', 'nope', 'code']]);

    $schema = overlayBuild();

    expect(array_column($schema['list']['columns'], 'field'))->toBe(['created_at', 'code', 'name', 'status'])
        ->and(array_column($schema['form']['create']['fields'], 'field'))->toBe(['code', 'name'])
        ->and(array_column($schema['detail']['sections'], 'field'))->toBe(['code', 'name', 'status']);
});

it('overrides labels for the locale and wins over definition and custom field labels', function () {
    app(CustomFieldService::class)->create('overlaytest/items', [
        'key' => 'industry', 'type' => 'text', 'labels' => ['vi' => 'Ngành', 'en' => 'Industry'], 'show_in_list' => true,
    ], null);
    overlayStore(['labels' => [
        'code' => ['vi' => 'Mã nội bộ', 'en' => 'Internal code'],
        'custom_fields.industry' => ['en' => 'Sector'],
        'status' => 'broken',
    ]]);

    $vi = overlayBuild('vi');
    $en = overlayBuild('en');
    $col = fn (array $s, string $f) => collect($s['list']['columns'])->firstWhere('field', $f);
    $form = fn (array $s, string $f) => collect($s['form']['create']['fields'])->firstWhere('field', $f);

    expect($col($vi, 'code')['label_text']['vi'])->toBe('Mã nội bộ')
        ->and($col($vi, 'code')['label'])->toBe('code')
        ->and($form($vi, 'code')['label_text']['vi'])->toBe('Mã nội bộ')
        ->and($col($en, 'code')['label_text']['en'])->toBe('Internal code')
        ->and($col($en, 'custom_fields.industry')['label_text']['en'])->toBe('Sector')
        ->and($col($vi, 'custom_fields.industry')['label_text']['vi'])->toBe('Sector')
        ->and($col($vi, 'status'))->not->toHaveKey('label_text');
});

it('ignores unknown names and malformed values', function () {
    $baseline = overlayBuild();
    overlayStore(['hidden' => 'oops', 'order' => ['ghost'], 'labels' => ['ghost' => ['vi' => 'x']]]);

    expect(overlayBuild())->toBe($baseline);
});
