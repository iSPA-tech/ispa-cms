<?php

use Cms\Core\Models\Language;
use Cms\Core\Models\Translate;
use Cms\Core\Repositories\LanguageRepository;
use Cms\Core\Repositories\TranslateRepository;

test('LanguageRepository model and table', function () {
    $repo = new LanguageRepository();
    expect($repo->getModelClass())->toBe(Language::class);
    expect($repo->table())->toBe('languages');
});

test('TranslateRepository model and table', function () {
    $repo = new TranslateRepository();
    expect($repo->getModelClass())->toBe(Translate::class);
    expect($repo->table())->toBe('translates');
});
