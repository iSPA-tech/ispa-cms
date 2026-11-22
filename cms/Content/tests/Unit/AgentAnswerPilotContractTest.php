<?php

declare(strict_types=1);

uses(Tests\TestCase::class);

/*
 * Pilot-wide contracts that sit outside any one service: the public API snapshot and version
 * of the Content module, and the shipped (fail-closed) configuration defaults.
 */

it('records the new Post scope and repository search in the Content public API snapshot', function (): void {
    $snapshot = json_decode(
        (string) file_get_contents(base_path('cms/Content/tests/Contract/content-api.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($snapshot['Cms\\Content\\Models\\Post']['methods'] ?? [])->toHaveKey('scopePublicAt')
        ->and($snapshot['Cms\\Content\\Interfaces\\PostInterface']['methods'] ?? [])->toHaveKey('searchAgentEligible');
});

it('bumps the Content module version past the release that predates the pilot', function (): void {
    $manifest = json_decode(
        (string) file_get_contents(base_path('cms/Content/module.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(version_compare((string) ($manifest['version'] ?? ''), '2026.11.4', '>'))->toBeTrue();
});

it('ships the content answer config file closed by default', function (): void {
    expect(is_file(base_path('cms/Content/config/agent_answers.php')))->toBeTrue()
        ->and(config('content.agent_answers.approved_posts'))->toBe([])
        ->and(config('content.agent_answers.max_corpus_chars'))->toBeInt()->toBeGreaterThan(0);
});

it('ships the Core answer gate disabled with no project or provider allowed', function (): void {
    expect(config('core.agent_answers.enabled'))->toBeFalse()
        ->and(config('core.agent_answers.allowed_projects'))->toBe([])
        ->and(config('core.agent_answers.allowed_providers'))->toBeArray();
});
