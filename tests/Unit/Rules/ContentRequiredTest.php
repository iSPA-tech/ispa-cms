<?php

use App\Rules\ContentRequired;

it('rejects malformed content blocks without throwing', function (): void {
    $rule = new ContentRequired();

    expect($rule->passes('content', [null]))->toBeFalse()
        ->and($rule->passes('content', ['invalid']))->toBeFalse();
});

it('accepts content blocks with text or widget content', function (): void {
    $rule = new ContentRequired();

    expect($rule->passes('content', [
        ['type' => 'text', 'value' => '<p>Nội dung</p>'],
    ]))->toBeTrue()
        ->and($rule->passes('content', [
            ['type' => 'widget', 'value' => []],
        ]))->toBeTrue()
        ->and($rule->passes('content', [
            ['type' => 'data_chart', 'value' => ['title' => 'Chi phí sở hữu']],
        ]))->toBeTrue();
});
