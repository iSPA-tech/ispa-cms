<?php

use Cms\Core\Services\PublishedData\PublishedDataProfileService;

test('published-data profile service exposes expected defaults', function () {
    $service = new PublishedDataProfileService();

    $newsProfile = $service->getProfile(PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE);
    $entityProfile = $service->getProfile(PublishedDataProfileService::ENTITY_DIRECTORY_VERIFIED_PROFILE);

    expect($newsProfile->slug)->toBe('news_article_editorial')
        ->and($newsProfile->name)->toBe('News Article Editorial')
        ->and($newsProfile->duplicateThreshold)->toBe(0.94)
        ->and($newsProfile->relatedClusterThreshold)->toBe(0.82)
        ->and($newsProfile->strictVerificationRequired)->toBeFalse()
        ->and($newsProfile->allowPublishedFirst)->toBeTrue()
        ->and($newsProfile->requiresContentQualityCheck)->toBeTrue()
        ->and($newsProfile->requiresCopyrightQualityCheck)->toBeTrue()
        ->and($newsProfile->requiresSourceQualityCheck)->toBeTrue();

    expect($entityProfile->slug)->toBe('entity_directory_verified')
        ->and($entityProfile->name)->toBe('Entity Directory Verified')
        ->and($entityProfile->duplicateThreshold)->toBe(0.94)
        ->and($entityProfile->relatedClusterThreshold)->toBe(0.82)
        ->and($entityProfile->strictVerificationRequired)->toBeTrue()
        ->and($entityProfile->allowPublishedFirst)->toBeFalse()
        ->and($entityProfile->requiresContentQualityCheck)->toBeTrue()
        ->and($entityProfile->requiresCopyrightQualityCheck)->toBeFalse()
        ->and($entityProfile->requiresSourceQualityCheck)->toBeTrue()
        ->and($entityProfile->qualityChecks)->toContain('contact_or_geo_verification')
        ->and($entityProfile->requiredEvidence)->toContain('verified_contact_or_coordinate');
});

test('published-data profile service throws for unknown profile slug', function () {
    $service = new PublishedDataProfileService();
    expect(fn () => $service->getProfile('unknown_profile'))->toThrow(\InvalidArgumentException::class);
});

test('published-data profile service resolves candidate types to profile gates', function () {
    $service = new PublishedDataProfileService();

    expect($service->profileForCandidateType('news')->slug)
        ->toBe(PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE)
        ->and($service->profileForCandidateType('article')->slug)
        ->toBe(PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE)
        ->and($service->profileForCandidateType('authority')->slug)
        ->toBe(PublishedDataProfileService::ENTITY_DIRECTORY_VERIFIED_PROFILE)
        ->and($service->profileForCandidateType('unknown')->slug)
        ->toBe(PublishedDataProfileService::ENTITY_DIRECTORY_VERIFIED_PROFILE);
});
