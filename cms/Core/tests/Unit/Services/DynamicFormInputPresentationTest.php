<?php

use Cms\Core\Models\Field;
use Cms\Core\Models\FieldItem;
use Cms\Core\Services\Forms\DynamicFormInput;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

uses(\Cms\Core\Tests\TestCase::class);

test('upload size follows the form setting and errors use the visible field name', function () {
    $item = new FieldItem([
        'field_name' => 'diplomaFile', 'name' => 'Bằng tốt nghiệp', 'type' => 'file',
        'status' => true, 'custom' => ['required' => true, 'allowFileType' => 'pdf', 'maxFileSizeMb' => 1],
    ]);
    $form = (new Field(['form_id' => 'test']))->setRelation('items', new Collection([$item]));
    $request = Request::create('/', 'POST', [], [], ['diplomaFile' => UploadedFile::fake()->create('degree.pdf', 1100, 'application/pdf')]);

    try {
        app(DynamicFormInput::class)->validate($request, $form);
        $this->fail('An upload larger than the configured limit was accepted.');
    } catch (ValidationException $error) {
        expect($error->errors()['diplomaFile'][0])->toContain('Bằng tốt nghiệp')->toContain('1 MB');
    }
});

test('preview uses the reviewed interface and escapes administrator-entered guidance', function () {
    session()->put('language-locale-' . config('app.locale'), (object) ['id' => 1, 'path' => 'vi']);
    session()->put('language-locales', []);
    $item = new FieldItem([
        'field_name' => 'sample', 'name' => 'Tên gọi', 'type' => 'text', 'status' => true,
        'description' => '<script>alert(1)</script>', 'custom' => ['placeholder' => 'Ví dụ: Toàn', 'section' => 'Thông tin thí sinh'],
    ]);
    $form = (new Field(['form_id' => 'preview', 'status' => 'status_published']))->setRelation('items', new Collection([$item]));
    $form->id = 1;
    $html = view('components.form-generate-reviewed', [
        'fields' => $form, 'form' => 'preview', 'actionUrl' => '', 'submissionToken' => null, 'previewOnly' => true,
    ])->render();
    expect($html)->toContain('Ví dụ: Toàn')->toContain('Thông tin thí sinh')
        ->toContain('.reviewed-form{font-size:14px')->toContain('min-height:40px')
        ->toContain('font-size:16px;min-height:48px')
        ->toContain('Không có hồ sơ nào được gửi')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>')->not->toContain('@click="confirm"')
        ->not->toContain('px-4 py-3');
    expect($html)->not->toContain('Không bắt buộc');
});

test('reviewed fields render responsive columns and full-row textareas without changing field order', function () {
    session()->put('language-locale-' . config('app.locale'), (object) ['id' => 1, 'path' => 'vi']);
    session()->put('language-locales', []);
    $form = new Field(['form_id' => 'layout', 'status' => 'status_published', 'custom' => ['layout' => ['columns' => ['mobile' => 1, 'tablet' => 2, 'desktop' => 3]]]]);
    $form->id = 1;
    $form->setRelation('items', new Collection([
        new FieldItem(['name' => 'First item', 'field_name' => 'first', 'type' => 'text', 'status' => true, 'custom' => ['layout' => ['span' => ['desktop' => 2]]]]),
        new FieldItem(['name' => 'Long notes', 'field_name' => 'notes', 'type' => 'textarea', 'status' => true]),
    ]));
    $html = view('components.form-generate-reviewed', ['fields' => $form, 'form' => 'preview', 'actionUrl' => '', 'submissionToken' => null, 'previewOnly' => true])->render();
    expect($html)->toContain('--form-cols-mobile:1;--form-cols-tablet:2;--form-cols-desktop:3')
        ->toContain('--form-span-mobile:1;--form-span-tablet:1;--form-span-desktop:2')
        ->toContain('--form-span-mobile:1;--form-span-tablet:2;--form-span-desktop:3')
        ->toContain('form-layout-grid')->not->toContain('grid-auto-flow:dense');
    expect(strpos($html, 'name="first"'))->toBeLessThan(strpos($html, 'name="notes"'));
});

test('legacy contact rendering uses the same layout metadata without changing submit flow', function () {
    // routes/web.php registers ajax.* only when the template folder is not
    // `Default`; the legacy form posts to it, so stub the name on Default builds.
    if (! \Illuminate\Support\Facades\Route::has('ajax.contact-request')) {
        \Illuminate\Support\Facades\Route::post('ajax/contact-request', fn () => null)->name('ajax.contact-request');
        app('router')->getRoutes()->refreshNameLookups();
    }
    session()->put('language-locale-' . config('app.locale'), (object) ['id' => 1, 'path' => 'vi']);
    session()->put('language-locales', []);
    $form = new Field(['form_id' => 'contact-layout', 'custom' => ['layout' => ['columns' => ['desktop' => 2]]]]);
    $form->id = 1;
    $form->setRelation('items', new Collection([
        new FieldItem(['field_name' => 'name', 'name' => 'Name', 'name_trans_key' => 'Name', 'type' => 'text', 'custom' => ['required' => true]]),
        new FieldItem(['field_name' => 'content', 'name' => 'Notes', 'name_trans_key' => 'Notes', 'type' => 'textarea']),
    ]));
    $html = view('components.form-generate', ['fields' => $form, 'form' => 'contact', 'actionUrl' => null, 'submissionToken' => null])->render();
    expect($html)->toContain('form-layout-grid--legacy')->toContain('--form-cols-desktop:2')
        ->toContain('--form-span-desktop:2')->toContain('@submit.prevent="submitForm"')->toContain('name="content"');
});
