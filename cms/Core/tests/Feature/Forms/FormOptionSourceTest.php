<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Models\Field;
use Cms\Core\Models\FieldItem;
use Cms\Core\Services\Forms\DynamicFormInput;
use Cms\Core\Services\Forms\FormOptionSourceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Cms\Core\Services\Forms\FormOptionResolver;

uses(\Cms\Core\Tests\TestCase::class);

class FormOptionTestEntry extends Model
{
    use SoftDeletes;
    protected $table = 'form_option_test_entries';
    protected $guarded = [];
    public $timestamps = false;

    public static function publicOptionsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where('project_key', config('ispa-cms.folder_name'))
            ->where('status', 'status_published');
    }
}

beforeEach(function () {
    $this->withoutMiddleware([ApiLocalization::class, HttpsProtocol::class, Localization::class, ThrottleRequests::class]);
    config(['ispa-cms.folder_name' => 'ExampleSchool']);
    Schema::create('form_option_test_entries', function (Blueprint $table) {
        $table->id(); $table->string('uuid')->unique(); $table->string('name');
        $table->string('status'); $table->string('project_key'); $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('secret')->nullable(); $table->softDeletes();
    });
    $this->registry = app(FormOptionSourceRegistry::class);
    $this->registry->register('test.entries', [
        'module' => 'Core', 'public' => true, 'model' => FormOptionTestEntry::class,
        'value' => 'uuid', 'label' => 'name', 'title' => ['vi' => 'Danh sách thử', 'en' => 'Test list'],
        'group' => ['vi' => 'Dữ liệu chung', 'en' => 'Common data'],
        'query' => [FormOptionTestEntry::class, 'publicOptionsQuery'],
        'filters' => ['parent' => ['title' => ['vi' => 'Mục phía trên', 'en' => 'Parent'], 'type' => 'reference', 'column' => 'parent_id', 'source' => 'test.entries']],
    ]);
    FormOptionTestEntry::insert([
        ['id' => 1, 'uuid' => 'parent-a', 'name' => 'Nhóm A', 'status' => 'status_published', 'project_key' => 'ExampleSchool', 'parent_id' => null, 'secret' => 'private'],
        ['id' => 2, 'uuid' => 'child-a', 'name' => 'Mục A', 'status' => 'status_published', 'project_key' => 'ExampleSchool', 'parent_id' => 1, 'secret' => 'private'],
        ['id' => 3, 'uuid' => 'other-project', 'name' => 'Private other project', 'status' => 'status_published', 'project_key' => 'Other', 'parent_id' => null, 'secret' => 'private'],
        ['id' => 4, 'uuid' => 'draft-a', 'name' => 'Private draft', 'status' => 'status_draft', 'project_key' => 'ExampleSchool', 'parent_id' => 1, 'secret' => 'private'],
    ]);
});

afterEach(function () { Schema::dropIfExists('form_option_test_entries'); });

function sourceTestForm(array $items): Field
{
    return (new Field(['form_id' => 'source-test', 'custom' => ['project' => 'ExampleSchool']]))
        ->setRelation('items', new \Illuminate\Database\Eloquent\Collection(array_map(fn ($item) => new FieldItem($item), $items)));
}

test('public lookup returns only scoped public labels and stable values', function () {
    $this->getJson('/api/v2/public/form-option-sources/test.entries/options')
        ->assertOk()->assertJsonPath('data.options.0.value', 'child-a')
        ->assertJsonPath('data.options.0.label', 'Mục A')
        ->assertJsonMissing(['value' => 'other-project'])->assertJsonMissing(['value' => 'draft-a'])
        ->assertDontSee('private')->assertDontSee('form_option_test_entries')->assertDontSee(FormOptionTestEntry::class);
});

test('lookup resolves reference labels without allowing scope or column overrides', function () {
    $response = $this->getJson('/api/v2/public/form-option-sources/test.entries/options?filters[parent]=parent-a');
    $response->assertOk()->assertJsonCount(1, 'data.options')->assertJsonPath('data.options.0.value', 'child-a');
    $this->getJson('/api/v2/public/form-option-sources/test.entries/options?filters[parent]=other-project')->assertUnprocessable();
    $this->getJson('/api/v2/public/form-option-sources/test.entries/options?filters[project_key]=Other')->assertUnprocessable();
    $this->getJson('/api/v2/public/form-option-sources/App.Models.User/options')->assertUnprocessable();
});

test('selected values are checked outside the first page and snapshot their label only', function () {
    foreach (range(10, 70) as $id) FormOptionTestEntry::create([
        'id' => $id, 'uuid' => 'entry-' . $id, 'name' => 'Entry ' . $id,
        'status' => 'status_published', 'project_key' => 'ExampleSchool',
    ]);
    $form = sourceTestForm([['name' => 'Lựa chọn', 'field_name' => 'choice', 'type' => 'select', 'status' => true,
        'custom' => ['required' => true, 'optionsSource' => ['source' => 'test.entries', 'filters' => [], 'dependsOn' => []]], 'options' => []]]);
    $result = app(DynamicFormInput::class)->validate(Request::create('/', 'POST', ['choice' => 'entry-70']), $form);
    expect($result['answers']['choice'])->toBe('entry-70')
        ->and($result['snapshot']['items'][0]['options'])->toBe([['value' => 'entry-70', 'label' => 'Entry 70']]);
    FormOptionTestEntry::where('uuid', 'entry-70')->update(['name' => 'Renamed later']);
    expect($result['snapshot']['items'][0]['options'][0]['label'])->toBe('Entry 70');
    expect(fn () => app(DynamicFormInput::class)->validate(Request::create('/', 'POST', ['choice' => 'other-project']), $form))
        ->toThrow(ValidationException::class);
});

test('dependent options require the selected parent and reject choices from another branch', function () {
    $form = sourceTestForm([
        ['name' => 'Nhóm', 'field_name' => 'group', 'type' => 'select', 'status' => true, 'custom' => ['required' => true], 'options' => [['value' => 'parent-a', 'label' => 'Nhóm A']]],
        ['name' => 'Mục', 'field_name' => 'choice', 'type' => 'select', 'status' => true, 'custom' => ['required' => true, 'optionsSource' => ['source' => 'test.entries', 'filters' => [], 'dependsOn' => ['parent' => 'group']]], 'options' => []],
    ]);
    $input = app(DynamicFormInput::class);
    expect($input->validate(Request::create('/', 'POST', ['group' => 'parent-a', 'choice' => 'child-a']), $form)['answers']['choice'])->toBe('child-a');
    expect(fn () => $input->validate(Request::create('/', 'POST', ['group' => 'parent-a', 'choice' => 'parent-a']), $form))->toThrow(ValidationException::class);
    expect(fn () => $input->validate(Request::create('/', 'POST', ['choice' => 'child-a']), $form))->toThrow(ValidationException::class);
});

test('content source uses the canonical publication status and hides future posts', function () {
    Schema::create('posts', function (Blueprint $table) {
        $table->id(); $table->string('uuid'); $table->string('name'); $table->string('status');
        $table->timestamp('published_at')->nullable(); $table->softDeletes();
    });
    try {
        DB::table('posts')->insert([
            ['uuid' => 'visible', 'name' => 'Public post', 'status' => 'status_published', 'published_at' => now()->subDay()],
            ['uuid' => 'future', 'name' => 'Scheduled post', 'status' => 'status_published', 'published_at' => now()->addDay()],
            ['uuid' => 'draft', 'name' => 'Private draft', 'status' => 'status_draft', 'published_at' => null],
        ]);
        $this->getJson('/api/v2/public/form-option-sources/content.posts/options')
            ->assertOk()->assertJsonCount(1, 'data.options')->assertJsonPath('data.options.0.value', 'visible');
    } finally { Schema::dropIfExists('posts'); }
});

test('settings reject foreign projects, missing sources, and invalid parent bindings', function () {
    $resolver = app(FormOptionResolver::class);
    $item = ['name' => 'Mục', 'field_name' => 'choice', 'type' => 'select', 'status' => true,
        'custom' => ['optionsSource' => ['source' => 'test.entries', 'filters' => [], 'dependsOn' => ['parent' => 'choice']]], 'options' => []];
    expect(fn () => $resolver->validateSettings(sourceTestForm([$item])))->toThrow(ValidationException::class);
    $item['custom']['optionsSource']['dependsOn'] = ['parent' => null];
    expect(fn () => $resolver->validateSettings(sourceTestForm([$item])))->toThrow(ValidationException::class);
    $item['custom']['optionsSource']['dependsOn'] = [];
    $form = sourceTestForm([$item]);
    $form->custom = ['project' => 'Other'];
    expect(fn () => $resolver->validateSettings($form))->toThrow(ValidationException::class);
    $item['custom']['optionsSource']['source'] = '';
    expect(fn () => $resolver->validateSettings(sourceTestForm([$item])))->toThrow(ValidationException::class);
    $this->registry->register('test.private', ['public' => false, 'model' => FormOptionTestEntry::class, 'value' => 'uuid', 'label' => 'name']);
    $this->getJson('/api/v2/public/form-option-sources/test.private/options')->assertUnprocessable();
});
