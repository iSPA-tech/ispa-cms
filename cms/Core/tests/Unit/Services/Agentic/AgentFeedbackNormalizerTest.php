<?php

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Services\Agentic\AgentFeedbackNormalizer;

test('captureRejectionFeedback returns null for empty note', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    expect($normalizer->captureRejectionFeedback($approval, ''))->toBeNull();
    expect($normalizer->captureRejectionFeedback($approval, null))->toBeNull();
    expect($normalizer->captureRejectionFeedback($approval, '   '))->toBeNull();
});

test('classify detects forbidden source by prefix', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'classify');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $result = $method->invoke($normalizer, 'source: do not use Wikipedia', $approval);
    expect($result['memory_type'])->toBe('forbidden_source');
    expect($result['confidence_score'])->toBe(0.92);
});

test('classify detects reviewer preference by prefix', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'classify');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $result = $method->invoke($normalizer, 'style: keep it professional', $approval);
    expect($result['memory_type'])->toBe('reviewer_preference');
    expect($result['confidence_score'])->toBe(0.9);
});

test('classify detects field correction by prefix', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'classify');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $result = $method->invoke($normalizer, 'field: price should be in VND', $approval);
    expect($result['memory_type'])->toBe('field_correction');
    expect($result['confidence_score'])->toBe(0.88);
});

test('classify detects forbidden source by keyword', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'classify');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $result = $method->invoke($normalizer, 'this looks like hallucinated content', $approval);
    expect($result['memory_type'])->toBe('forbidden_source');
    expect($result['confidence_score'])->toBe(0.74);
});

test('classify detects reviewer preference by keyword', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'classify');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $result = $method->invoke($normalizer, 'too promotional tone', $approval);
    expect($result['memory_type'])->toBe('reviewer_preference');
    expect($result['confidence_score'])->toBe(0.7);
});

test('classify falls back to general rejection', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'classify');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();
    $approval->artifact = null;

    $result = $method->invoke($normalizer, 'I just do not like it', $approval);
    expect($result['memory_type'])->toBe('general_rejection');
    expect($result['confidence_score'])->toBe(0.55);
});

test('expiresAt returns different TTLs per memory type', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'expiresAt');

    $fieldExpiry = $method->invoke($normalizer, 'field_correction');
    $sourceExpiry = $method->invoke($normalizer, 'forbidden_source');
    $prefExpiry = $method->invoke($normalizer, 'reviewer_preference');
    $generalExpiry = $method->invoke($normalizer, 'general_rejection');

    // field_correction = 90 days, forbidden_source = 60, preference = 45, general = 30
    expect($fieldExpiry->gt($sourceExpiry))->toBeTrue();
    expect($sourceExpiry->gt($prefExpiry))->toBeTrue();
    expect($prefExpiry->gt($generalExpiry))->toBeTrue();
});

test('buildContextKey includes module scope and artifact type', function () {
    $normalizer = new AgentFeedbackNormalizer();
    $method = new \ReflectionMethod($normalizer, 'buildContextKey');

    $approval = Mockery::mock(AgentApproval::class)->makePartial();

    $artifact = Mockery::mock(\Cms\Core\Models\Agents\AgentArtifact::class)->makePartial();
    $artifact->artifact_type = 'content_post_seo_patch';
    $approval->artifact = $artifact;

    $run = Mockery::mock(\Cms\Core\Models\Agents\AgentRun::class)->makePartial();
    $run->module_scope = 'content';
    $approval->run = $run;

    $result = $method->invoke($normalizer, $approval, 'forbidden_source');

    expect($result)->toBe('approval_feedback.content.content_post_seo_patch.forbidden_source');
});
