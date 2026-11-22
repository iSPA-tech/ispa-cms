<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Http\Request;
use Cms\Core\Http\Controllers\V2\Admin\TranslateController;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Interfaces\TranslateInterface;
use Cms\Core\Models\Translate;

test('TranslateController::fill builds name from group and key', function () {
    $req = Request::create('/', 'POST', [
        'group_name' => 'validation',
        'name' => 'email',
        'text' => ['en' => 'Email'],
        'for_web' => true,
        'for_adm' => false,
        'for_app' => true,
        'status' => 'published',
    ]);
    $this->app->instance('request', $req);

    $translateRepo = $this->createMock(TranslateInterface::class);
    $translateRepo->method('model')->willReturn(new Translate());
    $langRepo = $this->createMock(LanguageInterface::class);

    $controller = new TranslateController($translateRepo, $langRepo);

    $ref = new ReflectionClass($controller);
    $method = $ref->getMethod('fill');
    $method->setAccessible(true);
    $model = $method->invoke($controller, null);

    expect($model)->toBeInstanceOf(Translate::class);
    expect($model->name)->toBe('validation.email');
    expect($model->group_name)->toBe('validation');
    expect($model->for_web)->toBeTrue();
    expect($model->for_adm)->toBeFalse();
    expect($model->for_app)->toBeTrue();
    expect($model->text)->toBe(['en' => 'Email']);
    expect($model->status)->toBe('published');
});
