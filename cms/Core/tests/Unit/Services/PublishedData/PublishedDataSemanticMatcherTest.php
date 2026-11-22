<?php

use Cms\Core\Services\PublishedData\PublishedDataProfileService;
use Cms\Core\Services\PublishedData\PublishedDataSemanticMatcher;

test('published-data semantic matcher classifies scores by profile thresholds', function () {
    $matcher = new PublishedDataSemanticMatcher();

    expect($matcher->classifyScore(0.95, PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE))
        ->toBe('duplicate')
        ->and($matcher->classifyScore(0.83, PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE))
        ->toBe('related_cluster')
        ->and($matcher->classifyScore(0.5, PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE))
        ->toBe('new_candidate');
});

test('published-data semantic matcher keeps quality thresholds consistent across profiles', function () {
    $matcher = new PublishedDataSemanticMatcher();

    expect($matcher->classifyScore(0.95, PublishedDataProfileService::ENTITY_DIRECTORY_VERIFIED_PROFILE))
        ->toBe('duplicate')
        ->and($matcher->classifyScore(0.83, PublishedDataProfileService::ENTITY_DIRECTORY_VERIFIED_PROFILE))
        ->toBe('related_cluster')
        ->and($matcher->classifyScore(0.5, PublishedDataProfileService::ENTITY_DIRECTORY_VERIFIED_PROFILE))
        ->toBe('new_candidate');
});

test('published-data semantic matcher computes cosine similarity and vector classification', function () {
    $matcher = new PublishedDataSemanticMatcher();
    $left = [1, 0, 2];
    $right = [1, 0, 2];
    $bad = [0, 0, 0];

    expect($matcher->classifyVectors($left, $right, PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE))
        ->toBe('duplicate')
        ->and($matcher->matchVectors($left, $right, PublishedDataProfileService::NEWS_ARTICLE_EDITORIAL_PROFILE))
        ->toBe(['score' => 1.0, 'classification' => 'duplicate'])
        ->and($matcher->sanitizeVector([1, 'bad', 2.0, INF, -3]))->toBe([1.0, 2.0, -3.0])
        ->and($matcher->cosineSimilarity($left, $bad))->toBe(0.0);
});
