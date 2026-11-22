<?php

declare(strict_types=1);

use App\Services\Moderation\AutoModerationService;
use Tests\TestCase;

uses(TestCase::class);

/*
 * the open keyword list is neutral; a project adds its own words through `ispa-cms.moderation.extra_keywords`.
 */

beforeEach(function (): void {
    config()->set('openai.api_key', null);
});

test('the open list blocks its own words', function () {
    $result = (new AutoModerationService())->moderate(['name' => 'Xe', 'description' => 'đây là lừa đảo']);

    expect($result['status'])->toBe('moderation_rejected')->and($result['layer'])->toBe(2);
});

test('a plain app does not block a project word', function () {
    $result = (new AutoModerationService())->moderate(['name' => 'Xe', 'description' => 'bán trên chotot giá tốt']);

    expect($result['status'])->toBe('moderation_approved');
});

test('words a project registers are blocked, whatever their case', function () {
    config(['ispa-cms.moderation.extra_keywords' => ['ChoTot']]);

    $result = (new AutoModerationService())->moderate(['name' => 'Xe', 'description' => 'bán trên CHOTOT giá tốt']);

    expect($result['status'])->toBe('moderation_rejected')->and($result['layer'])->toBe(2);
});
