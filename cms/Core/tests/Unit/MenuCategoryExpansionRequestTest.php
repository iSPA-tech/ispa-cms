<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Http\Requests\Menu\StoreMenuRequest;
use Cms\Core\Http\Requests\Menu\UpdateMenuRequest;
use Illuminate\Support\Facades\Validator;

test('menu requests reject invalid category child levels recursively', function (string $requestClass) {
    $request = new $requestClass();
    $rules = $request->rules();
    $itemsRule = $rules['items'] ?? [];

    $validator = Validator::make([
        'items' => [[
            'custom' => ['isExpandChild' => true, 'expandChildLevel' => 1],
            'items' => [[
                'custom' => ['isExpandChild' => true, 'expandChildLevel' => -1],
            ]],
        ]],
    ], ['items' => $itemsRule]);

    expect($validator->fails())->toBeTrue();
})->with([
    'store' => [StoreMenuRequest::class],
    'update' => [UpdateMenuRequest::class],
]);

test('menu requests accept zero, positive, and missing legacy child levels', function (string $requestClass) {
    $request = new $requestClass();
    $rules = $request->rules();
    $itemsRule = $rules['items'] ?? [];

    foreach ([0, 3, null] as $level) {
        $custom = ['isExpandChild' => true];
        if ($level !== null) {
            $custom['expandChildLevel'] = $level;
        }

        $validator = Validator::make([
            'items' => [['custom' => $custom]],
        ], ['items' => $itemsRule]);

        expect($validator->passes())->toBeTrue();
    }
})->with([
    'store' => [StoreMenuRequest::class],
    'update' => [UpdateMenuRequest::class],
]);
